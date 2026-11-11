<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\AirTicketDetail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\PassengerProfile;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingPassengerController extends Controller
{
    public function searchProfiles(Request $request, Booking $booking): JsonResponse
    {
        abort_unless($request->user()->hasPermission('passengers.manage'),403);

        $q = trim((string)$request->query('q',''));

        $priorIds = BookingPassenger::query()
            ->whereNotNull('passenger_profile_id')
            ->whereHas('booking', fn($x) => $x
                ->where('company_id',$booking->company_id)
                ->where('customer_party_id',$booking->customer_party_id))
            ->pluck('passenger_profile_id')
            ->unique()
            ->take(100)
            ->values()
            ->all();

        $profiles = PassengerProfile::query()
            ->where('company_id',$booking->company_id)
            ->where('is_active',true)
            ->when($q !== '', function($query) use ($q): void {
                $like = '%'.$q.'%';
                $normalized = $this->normalizeDocument($q);

                $query->where(function($x) use ($like,$normalized): void {
                    $x->where('first_name','like',$like)
                      ->orWhere('middle_name','like',$like)
                      ->orWhere('last_name','like',$like)
                      ->orWhere('passport_no','like',$like)
                      ->orWhere('national_id','like',$like)
                      ->orWhere('mobile','like',$like)
                      ->orWhere('email','like',$like);

                    if ($normalized) {
                        $x->orWhere('passport_no_normalized','like','%'.$normalized.'%')
                          ->orWhere('national_id_normalized','like','%'.$normalized.'%');
                    }
                });
            })
            ->orderByDesc('last_used_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(60)
            ->get();

        // Same-customer travellers first without making the customer/profile relationship permanent.
        $priorSet = array_fill_keys(array_map('intval',$priorIds),true);
        $profiles = $profiles->sortByDesc(fn($p) => isset($priorSet[(int)$p->id]) ? 1 : 0)->values()->take(30);

        $already = BookingPassenger::query()
            ->where('booking_id',$booking->id)
            ->where('is_active',true)
            ->whereNotNull('passenger_profile_id')
            ->pluck('passenger_profile_id')
            ->map(fn($v)=>(int)$v)
            ->all();
        $alreadySet = array_fill_keys($already,true);

        return response()->json([
            'reference_date'=>$this->referenceDate($booking)->format('Y-m-d'),
            'profiles'=>$profiles->map(function(PassengerProfile $p) use($booking,$priorSet,$alreadySet): array {
                return [
                    'id'=>$p->id,
                    'name'=>$p->fullName(),
                    'title'=>$p->title,
                    'first_name'=>$p->first_name,
                    'last_name'=>$p->last_name,
                    'date_of_birth'=>$p->date_of_birth?->format('Y-m-d'),
                    'passport_no'=>$p->passport_no,
                    'passport_expiry'=>$p->passport_expiry?->format('Y-m-d'),
                    'nationality'=>$p->nationality_country_code,
                    'mobile'=>$p->mobile,
                    'calculated_pax_type'=>$this->resolvePaxType($booking,$p->date_of_birth?->format('Y-m-d'),$p->pax_type_hint),
                    'pax_type'=>$this->resolvePaxType($booking,$p->date_of_birth?->format('Y-m-d'),$p->pax_type_hint),
                    'previous_for_customer'=>isset($priorSet[(int)$p->id]),
                    'already_in_booking'=>isset($alreadySet[(int)$p->id]),
                    'last_used_at'=>$p->last_used_at?->format('d M Y'),
                ];
            })->values(),
        ]);
    }

    public function attachProfiles(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->isEditable(),422);

        $data = $request->validate([
            'profile_ids'=>['required','array','min:1'],
            'profile_ids.*'=>['integer'],
            'make_first_lead'=>['nullable','boolean'],
        ]);

        $profiles = PassengerProfile::query()
            ->where('company_id',$booking->company_id)
            ->where('is_active',true)
            ->whereIn('id',array_values(array_unique(array_map('intval',$data['profile_ids']))))
            ->get();

        if ($profiles->count() !== count(array_unique($data['profile_ids']))) {
            throw ValidationException::withMessages(['profile_ids'=>'One or more selected saved passengers are unavailable.']);
        }

        $added = 0;
        $restored = 0;
        $skipped = 0;

        DB::transaction(function() use ($request,$booking,$profiles,$data,&$added,&$restored,&$skipped): void {
            $makeLead = $request->boolean('make_first_lead');
            $leadUsed = BookingPassenger::query()->where('booking_id',$booking->id)->where('is_active',true)->where('is_lead',true)->exists();

            foreach ($profiles as $profile) {
                $existing = BookingPassenger::query()
                    ->where('booking_id',$booking->id)
                    ->where('passenger_profile_id',$profile->id)
                    ->first();

                if ($existing && $existing->is_active) {
                    $skipped++;
                    continue;
                }

                $snapshot = $this->snapshotFromProfile($booking,$profile);

                if ($existing) {
                    $old = $existing->toArray();
                    $existing->update(array_merge($snapshot,[
                        'is_active'=>true,
                        'is_lead'=>$makeLead && !$leadUsed,
                        'updated_by'=>$request->user()->id,
                    ]));
                    if ($existing->is_lead) $leadUsed = true;
                    $restored++;
                    AuditService::log($request,'booking.passenger.reactivated_from_master',$existing,$old,$existing->fresh()->toArray());
                } else {
                    $next = ((int)BookingPassenger::query()->where('booking_id',$booking->id)->max('passenger_no')) + 1;
                    $passenger = BookingPassenger::query()->create(array_merge($snapshot,[
                        'booking_id'=>$booking->id,
                        'passenger_profile_id'=>$profile->id,
                        'passenger_no'=>$next,
                        'is_lead'=>$makeLead && !$leadUsed,
                        'is_active'=>true,
                        'created_by'=>$request->user()->id,
                        'updated_by'=>$request->user()->id,
                    ]));
                    if ($passenger->is_lead) $leadUsed = true;
                    $added++;
                    AuditService::log($request,'booking.passenger.added_from_master',$passenger,[],array_merge($passenger->toArray(),['profile_id'=>$profile->id]));
                }

                $profile->update([
                    'last_used_at'=>now(),
                    'updated_by'=>$request->user()->id,
                ]);
            }
        });

        return back()->with('status',
            ($added+$restored).' saved passenger(s) added to booking'.
            ($skipped ? '; '.$skipped.' already existed and were skipped.' : '.')
        );
    }

    public function store(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->isEditable(),422);

        $data = $this->validatedNewProfile($request,$booking);
        $passportNorm = $this->normalizeDocument($data['passport_no'] ?? null);
        $nationalNorm = $this->normalizeDocument($data['national_id'] ?? null);

        $duplicate = PassengerProfile::query()
            ->where('company_id',$booking->company_id)
            ->where(function($q) use($passportNorm,$nationalNorm): void {
                if ($passportNorm) $q->orWhere('passport_no_normalized',$passportNorm);
                if ($nationalNorm) $q->orWhere('national_id_normalized',$nationalNorm);
            })
            ->when(!$passportNorm && !$nationalNorm, fn($q)=>$q->whereRaw('1=0'))
            ->first();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'passport_no'=>'This passenger already exists in Saved Passengers as '.$duplicate->fullName().'. Use Search Saved Passengers instead of creating a duplicate.'
            ]);
        }

        $passenger = DB::transaction(function() use($request,$booking,$data,$passportNorm,$nationalNorm) {
            $profile = PassengerProfile::query()->create([
                'company_id'=>$booking->company_id,
                'title'=>$data['title'] ?? null,
                'first_name'=>$data['first_name'],
                'middle_name'=>$data['middle_name'] ?? null,
                'last_name'=>$data['last_name'],
                'gender'=>$data['gender'] ?? null,
                'date_of_birth'=>$data['date_of_birth'] ?? null,
                'nationality_country_code'=>isset($data['nationality_country_code']) ? strtoupper((string)$data['nationality_country_code']) : null,
                'passport_no'=>$data['passport_no'] ?? null,
                'passport_no_normalized'=>$passportNorm,
                'passport_expiry'=>$data['passport_expiry'] ?? null,
                'national_id'=>$data['national_id'] ?? null,
                'national_id_normalized'=>$nationalNorm,
                'email'=>$data['email'] ?? null,
                'mobile'=>$data['mobile'] ?? null,
                'pax_type_hint'=>$data['pax_type'] ?? null,
                'is_active'=>true,
                'notes'=>$data['notes'] ?? null,
                'last_used_at'=>now(),
                'created_by'=>$request->user()->id,
                'updated_by'=>$request->user()->id,
            ]);

            if ($request->boolean('is_lead')) {
                BookingPassenger::query()->where('booking_id',$booking->id)->update(['is_lead'=>false]);
            }

            $next = ((int)BookingPassenger::query()->where('booking_id',$booking->id)->max('passenger_no')) + 1;

            $passenger = BookingPassenger::query()->create(array_merge(
                $this->snapshotFromProfile($booking,$profile),
                [
                    'booking_id'=>$booking->id,
                    'passenger_profile_id'=>$profile->id,
                    'passenger_no'=>$next,
                    'is_lead'=>$request->boolean('is_lead'),
                    'is_active'=>true,
                    'created_by'=>$request->user()->id,
                    'updated_by'=>$request->user()->id,
                ]
            ));

            AuditService::log($request,'passenger.profile.created',$profile,[],$profile->toArray());
            AuditService::log($request,'booking.passenger.created',$passenger,[],$passenger->toArray());

            return $passenger;
        });

        return back()->with('status','Passenger '.$passenger->fullName().' saved to Passenger Master and added to this booking.');
    }

    public function update(Request $request, Booking $booking, BookingPassenger $passenger): RedirectResponse
    {
        abort_unless($booking->isEditable() && (int)$passenger->booking_id===(int)$booking->id,422);

        $data=$request->validate([
            'pax_type'=>['required','in:ADULT,CHILD,INFANT'],
            'title'=>['nullable','in:MR,MRS,MS,MISS,MSTR'],
            'first_name'=>['required','string','max:100'],
            'middle_name'=>['nullable','string','max:100'],
            'last_name'=>['required','string','max:100'],
            'gender'=>['nullable','in:MALE,FEMALE'],
            'date_of_birth'=>['nullable','date','before_or_equal:today'],
            'nationality_country_code'=>['nullable','string','size:2'],
            'passport_no'=>['nullable','string','max:80',Rule::unique('booking_passengers','passport_no')->ignore($passenger->id)->where(fn($q)=>$q->where('booking_id',$booking->id))],
            'passport_expiry'=>['nullable','date'],
            'national_id'=>['nullable','string','max:80'],
            'email'=>['nullable','email','max:190'],
            'mobile'=>['nullable','string','max:60'],
            'notes'=>['nullable','string','max:2000'],
            'is_lead'=>['nullable','boolean'],
        ]);

        if (!empty($data['date_of_birth'])) {
            $calculated=$this->resolvePaxType($booking,$data['date_of_birth'],$data['pax_type']);
            $data['calculated_pax_type']=$calculated;
            $data['pax_type']=$this->allowedEffectivePaxType($calculated,$data['pax_type']);
        } else {
            $data['calculated_pax_type']=$data['pax_type'];
        }

        $old=$passenger->toArray();

        DB::transaction(function() use($request,$booking,$passenger,$data): void {
            if($request->boolean('is_lead')) {
                BookingPassenger::query()
                    ->where('booking_id',$booking->id)
                    ->where('id','!=',$passenger->id)
                    ->update(['is_lead'=>false]);
            }

            $passenger->update(array_merge($data,[
                'nationality_country_code'=>isset($data['nationality_country_code']) ? strtoupper((string)$data['nationality_country_code']) : null,
                'is_lead'=>$request->boolean('is_lead'),
                'updated_by'=>$request->user()->id,
            ]));
        });

        AuditService::log($request,'booking.passenger.updated',$passenger,$old,$passenger->fresh()->toArray());

        return back()->with('status','Booking passenger snapshot updated. Historical master data was not overwritten.');
    }

    public function updateFareType(Request $request, Booking $booking, BookingPassenger $passenger): RedirectResponse
    {
        abort_unless(
            $booking->isEditable()
            && (int)$passenger->booking_id===(int)$booking->id
            && $passenger->is_active,
            422
        );

        $data=$request->validate([
            'pax_type'=>['required','in:CHILD,ADULT'],
        ]);

        $calculated=$passenger->calculated_pax_type;

        if (!$calculated) {
            $calculated=$this->resolvePaxType(
                $booking,
                $passenger->date_of_birth?->format('Y-m-d'),
                $passenger->pax_type
            );
        }

        if ($calculated!=='CHILD') {
            throw ValidationException::withMessages([
                'pax_type'=>'Manual fare-type override is allowed only when the system age classification is CHILD.'
            ]);
        }

        $effective=$this->allowedEffectivePaxType($calculated,$data['pax_type']);

        // Never silently move an already-saved air ticket from CHILD pricing
        // to ADULT pricing (or back). Staff must adjust before ticket rows exist.
        if (
            $effective!==$passenger->pax_type
            && AirTicketDetail::query()
                ->where('booking_passenger_id',$passenger->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'pax_type'=>'This passenger already has a saved Air Ticket. Change the fare type before saving ticket rows, or use the controlled ticket-edit/reissue workflow.'
            ]);
        }

        $old=$passenger->toArray();

        $passenger->update([
            'calculated_pax_type'=>$calculated,
            'pax_type'=>$effective,
            'updated_by'=>$request->user()->id,
        ]);

        AuditService::log(
            $request,
            $effective===$calculated
                ? 'booking.passenger.fare_type_override_removed'
                : 'booking.passenger.fare_type_overridden',
            $passenger,
            $old,
            array_merge($passenger->fresh()->toArray(),[
                'system_age_type'=>$calculated,
                'effective_fare_type'=>$effective,
            ])
        );

        return back()->with(
            'status',
            $effective===$calculated
                ? $passenger->fullName().' restored to CHILD fare type.'
                : $passenger->fullName().' remains age-classified as CHILD but will use ADULT fare/pricing for this booking.'
        );
    }

    public function deactivate(Request $request, Booking $booking, BookingPassenger $passenger): RedirectResponse
    {
        abort_unless($booking->isEditable() && (int)$passenger->booking_id===(int)$booking->id,422);

        $old=$passenger->toArray();
        $passenger->update([
            'is_active'=>false,
            'is_lead'=>false,
            'updated_by'=>$request->user()->id
        ]);
        $passenger->services()->detach();

        AuditService::log($request,'booking.passenger.deactivated',$passenger,$old,$passenger->fresh()->toArray());

        return back()->with('status','Passenger removed from active booking without deleting history or the saved Passenger Master profile.');
    }

    private function validatedNewProfile(Request $request, Booking $booking): array
    {
        return $request->validate([
            'pax_type'=>['required_without:date_of_birth','nullable','in:ADULT,CHILD,INFANT'],
            'title'=>['nullable','in:MR,MRS,MS,MISS,MSTR'],
            'first_name'=>['required','string','max:100'],
            'middle_name'=>['nullable','string','max:100'],
            'last_name'=>['required','string','max:100'],
            'gender'=>['nullable','in:MALE,FEMALE'],
            'date_of_birth'=>['nullable','date','before_or_equal:today'],
            'nationality_country_code'=>['nullable','string','size:2'],
            'passport_no'=>[
                'nullable','string','max:80',
                Rule::unique('booking_passengers','passport_no')->where(fn($q)=>$q->where('booking_id',$booking->id))
            ],
            'passport_expiry'=>['nullable','date'],
            'national_id'=>['nullable','string','max:80'],
            'email'=>['nullable','email','max:190'],
            'mobile'=>['nullable','string','max:60'],
            'notes'=>['nullable','string','max:2000'],
            'is_lead'=>['nullable','boolean'],
        ]);
    }

    private function snapshotFromProfile(Booking $booking, PassengerProfile $profile): array
    {
        $calculated=$this->resolvePaxType(
            $booking,
            $profile->date_of_birth?->format('Y-m-d'),
            $profile->pax_type_hint
        );

        return [
            'calculated_pax_type'=>$calculated,
            'pax_type'=>$calculated,
            'title'=>$profile->title,
            'first_name'=>$profile->first_name,
            'middle_name'=>$profile->middle_name,
            'last_name'=>$profile->last_name,
            'gender'=>$profile->gender,
            'date_of_birth'=>$profile->date_of_birth?->format('Y-m-d'),
            'nationality_country_code'=>$profile->nationality_country_code,
            'passport_no'=>$profile->passport_no,
            'passport_expiry'=>$profile->passport_expiry?->format('Y-m-d'),
            'national_id'=>$profile->national_id,
            'email'=>$profile->email,
            'mobile'=>$profile->mobile,
            'notes'=>$profile->notes,
        ];
    }

    private function resolvePaxType(Booking $booking, ?string $dob, ?string $hint): string
    {
        if (!$dob) {
            return in_array($hint,['ADULT','CHILD','INFANT'],true) ? $hint : 'ADULT';
        }

        $birth = Carbon::parse($dob)->startOfDay();
        $reference = $this->referenceDate($booking);

        if ($birth->greaterThan($reference)) {
            throw ValidationException::withMessages(['date_of_birth'=>'Date of Birth cannot be after the booking travel reference date.']);
        }

        $age = $birth->diffInYears($reference);

        if ($age < 2) return 'INFANT';
        if ($age < 12) return 'CHILD';
        return 'ADULT';
    }

    private function allowedEffectivePaxType(string $calculated, string $requested): string
    {
        if ($calculated==='CHILD') {
            if (!in_array($requested,['CHILD','ADULT'],true)) {
                throw ValidationException::withMessages([
                    'pax_type'=>'A CHILD passenger may use only CHILD or ADULT fare type.'
                ]);
            }
            return $requested;
        }

        // INFANT and ADULT classifications are not manually overridable.
        return $calculated;
    }

    private function referenceDate(Booking $booking): Carbon
    {
        return Carbon::parse(
            $booking->travel_start_date?->format('Y-m-d')
            ?: $booking->booking_date?->format('Y-m-d')
            ?: now()->format('Y-m-d')
        )->startOfDay();
    }

    private function normalizeDocument(?string $value): ?string
    {
        $value = strtoupper(trim((string)$value));
        $value = preg_replace('/[^A-Z0-9]/','',$value) ?: '';
        return $value !== '' ? $value : null;
    }
}
