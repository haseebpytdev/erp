<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\EtCvVoucherRevision;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Services\Organization\CompanyProfileSnapshotService;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Operations\EtCvOperationalCorrectionService;
use App\Services\Operations\CompletePackageOperationalResolver;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use App\Support\QrCodeSvg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\Response;

final class EtCvVoucherController extends Controller
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions,
        private readonly UnifiedGroupPackageDataSource $masters,
        private readonly EtCvOperationalCorrectionService $operationalCorrections,
    ) {}

    public function manage(Request $request, int $booking): View
    {
        $this->authorizeManage($request);
        $preview = $this->preview($request, $booking);
        $bookingRow = (array) DB::table('bookings')->where('id', $booking)->first();
        $draft = DB::table('et_cv_voucher_drafts')->where('booking_id', $booking)->first();
        $revisions = EtCvVoucherRevision::query()->where('booking_id', $booking)->orderByDesc('revision_no')->get();
        $latestIssued = $revisions->firstWhere('status', 'issued');
        if ($latestIssued && is_string($latestIssued->snapshot_json ?? null)) {
            $issuedSnapshot = json_decode($latestIssued->snapshot_json, true);
            if (is_array($issuedSnapshot) && $issuedSnapshot !== []) {
                $preview = $issuedSnapshot;
            }
        }
        return view('operations.bookings.et-cv-voucher-manage', [
            'bookingId' => $booking,
            'voucherNumber' => (string) ($latestIssued?->voucher_number ?: ($preview['voucherNumber'] ?? ('ET-CV-'.now()->year.'-'.$booking))),
            'preview' => $preview,
            'fields' => $this->editableFields($preview),
            'draft' => $draft ? json_decode((string) $draft->fields_json, true) : [],
            'draftReason' => $draft?->reason ?? '',
            'revisions' => $revisions,
            'layoutMeta' => [
                'layout' => 'layouts.et-cv-standalone',
                'content_section' => 'content',
                'title_section' => 'title',
            ],
            'masterOptions' => $this->masterOptions($bookingRow),
            'costLocks' => $this->operationalCorrections->lockSummary($booking),
            'operationalRows' => $this->operationalCorrections->rows($booking),
        ]);
    }

    public function operationalCorrection(Request $request, int $booking): Response
    {
        $data = $request->validate([
            'correction_reason' => ['required', 'string', 'max:2000'],
            'hotel' => ['nullable', 'array'],
            'transport' => ['nullable', 'array'],
            'hotel.*.source_key' => ['required', 'string', 'max:190'],
            'hotel.*.source_id' => ['required', 'integer', 'min:1'],
            'hotel.*.display_index' => ['nullable', 'integer', 'min:0'],
            'hotel.*.hotel_id' => ['nullable', 'integer', 'min:1'],
            'hotel.*.vendor_id' => ['nullable', 'integer', 'min:1'],
            'hotel.*.cost_amount' => ['required', 'numeric', 'min:0'],
            'hotel.*.cost_rate' => ['nullable', 'numeric', 'min:0'],
            'hotel.*.currency_code' => ['nullable', 'string', 'size:3'],
            'hotel.*.fx_rate' => ['nullable', 'numeric', 'gt:0'],
            'transport.*.source_key' => ['required', 'string', 'max:190'],
            'transport.*.source_id' => ['required', 'integer', 'min:1'],
            'transport.*.display_index' => ['nullable', 'integer', 'min:0'],
            'transport.*.source_table' => ['required', 'in:booking_transport_segments,transport_booking_details,booking_transport_details'],
            'transport.*.vendor_id' => ['nullable', 'integer', 'min:1'],
            'transport.*.transport_company_id' => ['nullable', 'integer', 'min:1'],
            'transport.*.cost_amount' => ['required', 'numeric', 'min:0'],
            'transport.*.cost_foreign' => ['nullable', 'numeric', 'min:0'],
            'transport.*.currency_code' => ['nullable', 'string', 'size:3'],
            'transport.*.fx_rate' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $this->operationalCorrections->apply($request, $booking, $data, (string) $data['correction_reason']);
        return redirect()->route('bookings.et-cv-voucher.manage', ['booking' => $booking])
            ->with('success', 'Native Hotel/Transport vendor-cost correction saved. ET-CV publication still requires explicit reissue.');
    }

    public function saveDraft(Request $request, int $booking): Response
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'fields' => ['required', 'array'],
            'fields.*' => ['nullable', 'string', 'max:1000'],
        ]);
        \Log::info('C91.35 draft diagnostic', ['booking'=>$booking, 'user'=>$request->user()?->id, 'fields'=>$data['fields'] ?? null, 'reason'=>$data['reason'] ?? null]);
        DB::table('et_cv_voucher_drafts')->updateOrInsert(
            ['booking_id' => $booking],
            [
                'fields_json' => json_encode($data['fields'], JSON_THROW_ON_ERROR),
                'reason' => trim($data['reason']),
                'updated_by' => $request->user()?->id,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        return back()->with('success', 'ET-CV draft saved. The issued voucher was not changed.');
    }

    public function reissue(Request $request, int $booking): Response
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'confirm' => ['accepted'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ]);
        $result = DB::transaction(function () use ($request, $booking, $data): array {
            $draft = DB::table('et_cv_voucher_drafts')->where('booking_id', $booking)->lockForUpdate()->first();
            $latest = DB::table('et_cv_voucher_revisions')->where('booking_id', $booking)->orderByDesc('revision_no')->lockForUpdate()->first();
            $currentRevision = (int) ($latest->revision_no ?? 0);
            if ($data['expected_revision'] !== null && (int) $data['expected_revision'] !== $currentRevision) {
                abort(409, 'The voucher changed. Reload before reissuing.');
            }
            abort_unless($draft, 422, 'Save a draft before reissuing.');
            $preview = $this->preview($request, $booking);
            $fields = json_decode((string) $draft->fields_json, true) ?: [];
            $snapshot = $this->applyFields($preview, $fields);
            $next = $currentRevision + 1;
            // Reissues keep the issued ET-CV identifier stable. A fresh
            // preview may choose an accommodation-only ET-AV prefix, but it
            // must never rename an already-issued Client Voucher.
            $issuedVoucherNumber = trim((string) ($latest->voucher_number ?? ''));
            if ($issuedVoucherNumber !== '') {
                $snapshot['voucherNumber'] = $issuedVoucherNumber;
            }
            $token = bin2hex(random_bytes(24));
            $snapshot['publicVoucherUrl'] = url('/voucher/'.$token);
            try {
                $snapshot['voucherQrImage'] = QrCodeSvg::dataUri($snapshot['publicVoucherUrl']);
            } catch (\Throwable) {
                $snapshot['voucherQrImage'] = '';
            }
            $oldToken = (string) DB::table('bookings')->where('id', $booking)->value('public_voucher_token');
            DB::table('et_cv_voucher_tokens')->where('booking_id', $booking)->update(['status'=>'superseded','updated_at'=>now()]);
            if ($oldToken !== '') {
                DB::table('et_cv_voucher_tokens')->updateOrInsert(['token' => $oldToken], ['booking_id'=>$booking,'status'=>'superseded','updated_at'=>now(),'created_at'=>now()]);
            }
            DB::table('et_cv_voucher_revisions')->where('booking_id', $booking)->update(['status'=>'superseded','updated_at'=>now()]);
            $revisionId = DB::table('et_cv_voucher_revisions')->insertGetId([
                'booking_id'=>$booking,
                'revision_no'=>$next,
                'voucher_number'=>$issuedVoucherNumber !== '' ? $issuedVoucherNumber : (string) ($preview['voucherNumber'] ?? ('ET-CV-'.now()->year.'-'.$booking)),
                'public_token'=>$token,
                'snapshot_json'=>json_encode($snapshot, JSON_THROW_ON_ERROR),
                'reason'=>trim($data['reason']),
                'issued_by'=>$request->user()?->id,
                'issued_at'=>now(),
                'status'=>'issued',
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
            DB::table('et_cv_voucher_tokens')->insert(['token'=>$token,'booking_id'=>$booking,'revision_id'=>$revisionId,'status'=>'current','created_at'=>now(),'updated_at'=>now()]);
            DB::table('et_cv_voucher_drafts')->where('booking_id', $booking)->delete();
            return ['revision'=>$next];
        });
        return redirect()->route('bookings.et-cv-voucher.manage', ['booking'=>$booking])->with('success', 'ET-CV voucher reissued as revision '.$result['revision'].'.');
    }

    public function history(Request $request, int $booking): View
    {
        $this->authorizeManage($request);
        return view('operations.bookings.et-cv-voucher-history', ['bookingId'=>$booking,'revisions'=>EtCvVoucherRevision::where('booking_id',$booking)->orderByDesc('revision_no')->get(),'layoutMeta'=>app(NativeErpLayoutResolver::class)->resolve()]);
    }

    public function revision(Request $request, int $booking, int $revision): View
    {
        $this->authorizeManage($request);
        $row = DB::table('et_cv_voucher_revisions')->where('booking_id',$booking)->where('revision_no',$revision)->first();
        abort_unless($row, 404);
        $snapshot = json_decode((string) $row->snapshot_json, true) ?: [];
        $snapshot = $this->normalizeSnapshotDates($snapshot);
        return view('operations.bookings.general-client-voucher-v113142', $snapshot + ['publicMode'=>false,'historicalRevision'=>$revision]);
    }

    public function publicRevision(Request $request, string $token): View
    {
        $row = DB::table('et_cv_voucher_tokens')->where('token', $token)->first();
        abort_unless($row && $row->status === 'current' && $row->revision_id, 404);
        $revision = DB::table('et_cv_voucher_revisions')->where('id', $row->revision_id)->where('status','issued')->first();
        abort_unless($revision, 404);
        $data = json_decode((string) $revision->snapshot_json, true) ?: [];
        $data = $this->normalizeSnapshotDates($data);
        return view('operations.bookings.general-client-voucher-v113142', $data + ['publicMode'=>true]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user() && ($this->permissions->isSuperAdmin($request->user()) || $this->permissions->hasCapability($request->user(), ['service details','voucher'], ['manage','edit','reissue','release'])), 403);
    }

    private function preview(Request $request, int $booking): array
    {
        $request->attributes->set('et_cv_amendment_snapshot', true);
        $view = app(GeneralBookingVoucherPreviewController::class)->show($request, $booking, app(CompanyProfileSnapshotService::class), app(\App\Services\Operations\ClientVoucherFooterResolver::class), app(\App\Services\Operations\ClientVoucherPassengerVisaMap::class), app(\App\Services\Operations\UnifiedGroupPackageDataSource::class), app(CompletePackageOperationalResolver::class));
        return $view->getData();
    }

    private function editableFields(array $data): array
    {
        $paths = ['specialInstructions'];
        foreach (['hotelStays'=>['hotel_name','vendor_name','check_in','check_out','room_type','room_quantity','room_qty','guest_allocation'], 'airSegments'=>['airline','flight_number','from','to','departure_at','arrival_at','sector','cabin','booking_class','baggage'], 'airTickets'=>['ticket_number','booking_class','baggage'], 'transportRows'=>['company_name','route_name','pickup','dropoff','pickup_location','dropoff_location','driver_name','vehicle_type','plate_number','travel_date'], 'visaRows'=>['vendor_name','visa_number','passport_number','passport_no','issue_date','expiry_date','status']] as $root=>$keys) {
            foreach ((array) ($data[$root] ?? []) as $i=>$row) foreach ($keys as $key) $paths[] = $root.'.'.$i.'.'.$key;
        }
        return array_values(array_unique($paths));
    }

    private function applyFields(array $data, array $fields): array
    {
        foreach ($fields as $path=>$value) {
            if (! is_string($path) || $path === '') continue;
            data_set($data, $path, trim((string) $value));
        }
        return $data;
    }

    private function normalizeSnapshotDates(array $snapshot): array
    {
        if (isset($snapshot['generatedAt']) && is_string($snapshot['generatedAt'])) {
            $snapshot['generatedAt'] = Carbon::parse($snapshot['generatedAt']);
        }
        return $snapshot;
    }

    /** Read-only master options; these values are display overrides only. */
    private function masterOptions(array $booking): array
    {
        $vendors = $this->masters->vendors()->map(fn (array $row): array => [
            'name' => trim((string) ($row['name'] ?? '')),
            'id' => (int) ($row['id'] ?? 0),
        ])->filter(fn (array $row): bool => $row['name'] !== '')->values()->all();
        $vendors = $this->permittedVendors($booking, $vendors);
        return [
            'hotels' => $this->masters->hotels()->map(fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'label' => trim((string) ($row['name'] ?? '')).(trim((string) ($row['city'] ?? '')) !== '' ? ' · '.trim((string) $row['city']) : ''),
                'value' => trim((string) ($row['name'] ?? '')),
            ])->filter(fn (array $row): bool => $row['value'] !== '')->values()->all(),
            'vendors' => $vendors,
            'airlines' => $this->masters->airlines()->map(fn (array $row): array => [
                'label' => trim((string) ($row['name'] ?? '')).(trim((string) ($row['code'] ?? '')) !== '' ? ' ('.trim((string) $row['code']).')' : ''),
                'value' => trim((string) ($row['name'] ?? '')),
            ])->filter(fn (array $row): bool => $row['value'] !== '')->values()->all(),
            'transportCompanies' => $vendors,
            'vehicleTypes' => $this->masters->transportVehicles()->map(fn (array $row): array => [
                'label' => trim((string) ($row['name'] ?? '')),
                'value' => trim((string) ($row['name'] ?? '')),
            ])->filter(fn (array $row): bool => $row['value'] !== '')->unique('value')->values()->all(),
            'visaVendors' => $vendors,
        ];
    }

    private function permittedVendors(array $booking, array $vendors): array
    {
        if (! Schema::hasTable('parties')) return $vendors;
        $columns = Schema::getColumnListing('parties');
        $companyId = (int) ($booking['company_id'] ?? 0);
        $branchId = (int) ($booking['branch_id'] ?? 0);
        $query = DB::table('parties');
        if ($companyId > 0 && in_array('company_id', $columns, true)) $query->where('company_id', $companyId);
        if ($branchId > 0 && in_array('branch_id', $columns, true)) $query->where(function ($q) use ($branchId): void { $q->where('branch_id', $branchId)->orWhereNull('branch_id'); });
        $allowed = $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($allowed === []) return $vendors;
        return array_values(array_filter($vendors, fn (array $row): bool => in_array((int) ($row['id'] ?? 0), $allowed, true)));
    }
}
