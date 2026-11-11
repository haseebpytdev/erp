<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\SalesInvoice;
use App\Services\AuditService;
use App\Services\Operations\BookingTravelReadinessResolver;
use App\Services\Operations\BookingCommercialCompletenessResolver;
use App\Services\Operations\BookingBillingEditLockResolver;
use App\Services\Operations\GeneralBookingCommercialSummaryResolver;
use App\Services\Operations\GroupUmrahEditAuthority;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Operations\NativeSalesInvoiceCreateCapability;
use App\Services\Operations\NativeBookingCustomerResolver;
use App\Services\Operations\CompletePackageOperationalResolver;
use App\Services\Sales\BaseSalesInvoiceConsistencyResolver;
use App\Services\Purchase\SupplierCostingDraftSynchronizer;
use App\Services\Organization\CompanyProfileSnapshotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

final class GeneralBookingReviewController extends Controller
{
    public function show(Request $request, int $booking, NativeErpLayoutResolver $layout, CompanyProfileSnapshotService $company, NativeSalesInvoiceInspector $invoices, NativeSalesInvoiceCreateCapability $invoiceCreateCapability, NativeBookingCustomerResolver $customerAuthority, BookingTravelReadinessResolver $readiness, BookingCommercialCompletenessResolver $commercialResolver, GeneralBookingCommercialSummaryResolver $commercialSummary, GroupUmrahEditAuthority $authority, BookingBillingEditLockResolver $billingLocks, BaseSalesInvoiceConsistencyResolver $invoiceConsistencyResolver, CompletePackageOperationalResolver $packageOperational): View
    {
        $row = $this->booking($booking);
        $snapshots = $this->snapshots($request, $booking);
        $package = $packageOperational->snapshot($booking);
        $selected = $this->selected($snapshots, $booking);
        if ($package['air']['itinerary'] || $package['hotel']['stays'] || $package['transport']['transports'] || $package['visa']['visa_rows'] || $package['other']) {
            $selected[] = 'air';
            if ($package['hotel']['stays']) $selected[] = 'hotel';
            if ($package['transport']['transports']) $selected[] = 'transport';
            if ($package['visa']['visa_rows']) $selected[] = 'visa';
            if ($package['other']) $selected[] = 'other';
            $selected = array_values(array_unique($selected));
        }
        $commercial = $commercialSummary->resolve($row, ...array_values($snapshots));
        $commercialState = $commercialResolver->resolve($selected, ...array_values($snapshots));
        $checklist = $commercialState['items'];
        $travel = $readiness->resolve($row, $selected, ...array_values($snapshots));
        $invoice = $invoices->summary($booking);
        $latestInvoice = $invoice['latest'] ?? null;
        $invoiceUrl = route('operations.bookings.sales-invoice.stable', ['booking'=>$booking]);
        if (is_array($latestInvoice) && (int) ($latestInvoice['id'] ?? 0) > 0) {
            try {
                $nativeUrl = $invoices->nativeInvoiceUrl((int) $latestInvoice['id']);
                if (is_string($nativeUrl) && trim($nativeUrl) !== '') {
                    $invoiceUrl = $nativeUrl;
                }
            } catch (Throwable) {
                // Keep the booking-scoped bridge as a safe compatibility fallback.
            }
        }
        $passengers = (array) ($snapshots['air']['passengers'] ?? $snapshots['visa']['passengers'] ?? []);
        $billingLock = $billingLocks->resolve($booking);
        $invoiceConsistency = null;
        if (is_array($latestInvoice) && (int) ($latestInvoice['id'] ?? 0) > 0) {
            try { $invoiceConsistency = $invoiceConsistencyResolver->resolve(SalesInvoice::query()->findOrFail((int) $latestInvoice['id'])); } catch (Throwable) { $invoiceConsistency = null; }
        }

        return view('operations.bookings.general-booking-review-v113160', [
            'layoutMeta' => $layout->resolve(), 'bookingId' => $booking, 'booking' => $row,
            'company' => $company->get($row), 'identity' => $this->identity($row, $booking, $customerAuthority->resolve($booking)),
            'passengerSummary' => $this->passengerSummary($passengers), 'airSummary' => $this->airSummary($snapshots['air']),
            'hotelSummary' => $this->hotelSummary($snapshots['hotel'], $booking), 'transportSummary' => $this->transportSummary($snapshots['transport']),
            'visaSummary' => $this->visaSummary($snapshots['visa']), 'commercial' => $commercial,
            'selected' => $selected, 'checklist' => $checklist, 'completion' => ['done'=>$commercialState['passed_count'],'total'=>$commercialState['applicable_count'],'percent'=>$commercialState['applicable_count']?(int)round($commercialState['passed_count']/$commercialState['applicable_count']*100):0],
            'approvalStatus' => $this->approvalStatus($row), 'travel' => $travel,
            'persistedTravelStatus' => $this->persistedTravelStatus($row),
            'accounting' => $this->accounting($invoice), 'payment' => $this->payment($booking, $commercial['final_sale_total']),
            'invoice' => $invoice['latest'] ?? null,
            'invoiceUrl' => $invoiceUrl,
            'invoiceCreateEnabled' => $invoiceCreateCapability->enabled(),
            'invoiceCreateDisabledMessage' => $invoiceCreateCapability->disabledMessage(),
            'specialInstructions' => $this->first($row, ['special_instructions','voucher_instructions','client_instructions','notes','remarks','description']),
            'specialField' => $this->column(['special_instructions','voucher_instructions','client_instructions','notes']),
            'internalNotes' => $this->first($row, ['internal_notes','booking_internal_notes','staff_notes','private_notes']),
            'internalField' => $this->column(['internal_notes','booking_internal_notes','staff_notes','private_notes']),
            'canReopen' => $authority->canReopen($request->user()),
             'canAmend' => $authority->canReopen($request->user()) && in_array($this->approvalStatus($row), ['Approved','Travel Ready'], true),
             'operationalAmendmentRows' => [
                 'hotel' => array_values((array) ($snapshots['hotel']['stays'] ?? [])),
                 'air' => array_values((array) ($snapshots['air']['itinerary'] ?? [])),
                 'transport' => array_values((array) ($snapshots['transport']['transports'] ?? [])),
                 'visa' => array_values((array) ($snapshots['visa']['visa_rows'] ?? [])),
             ],
            'billingLock' => $billingLock,
            'invoiceConsistency' => $invoiceConsistency,
            'canApprove' => $authority->canReopen($request->user()),
        ]);
    }

    public function action(Request $request, int $booking, string $action, GroupUmrahEditAuthority $authority, BookingBillingEditLockResolver $billingLocks, SupplierCostingDraftSynchronizer $supplierCostingDrafts): mixed
    {
        $row = $this->booking($booking); $columns = Schema::getColumnListing('bookings');
        if ($action === 'hotel-confirmation') {
            abort_unless($authority->canReopen($request->user()), 403, 'Only an authorized administrator may update Hotel confirmation/reference values.');
            abort_unless($this->approvalStatus($row) === 'Approved', 422, 'Hotel confirmation/reference updates are allowed only after approval.');
            abort_unless(Schema::hasTable('hotel_booking_details') && Schema::hasTable('booking_services'), 422, 'The native Hotel stay store is unavailable.');
            $data = $request->validate(['hotel_stay_id'=>['required','integer','min:1'],'confirmation_no'=>['nullable','string','max:120']]);
            $detailColumns = Schema::getColumnListing('hotel_booking_details');
            $confirmationColumn = $this->firstColumn($detailColumns, ['confirmation_number','confirmation_no','booking_reference','reference_no']);
            abort_unless($confirmationColumn, 422, 'The native Hotel confirmation/reference field is unavailable.');
            $detail = DB::table('hotel_booking_details as h')->join('booking_services as bs','bs.id','=','h.booking_service_id')->where('h.id',(int)$data['hotel_stay_id'])->where('bs.booking_id',$booking)->where(function($q):void{$q->whereNull('bs.status')->orWhereRaw("UPPER(bs.status) NOT IN ('CANCELLED','VOIDED','VOID')");})->select(['h.id','h.booking_service_id','h.'.$confirmationColumn.' as confirmation_value'])->first();
            abort_unless($detail,404,'The Hotel stay does not belong to this booking.');
            $newValue=trim((string)($data['confirmation_no']??'')); $oldValue=trim((string)($detail->confirmation_value??''));
            DB::transaction(function() use($booking,$detail,$detailColumns,$confirmationColumn,$newValue,$oldValue,$request):void{
                $update=[$confirmationColumn=>$newValue!==''?$newValue:null];
                if(in_array('updated_by',$detailColumns,true))$update['updated_by']=$request->user()?->id;
                if(in_array('updated_at',$detailColumns,true))$update['updated_at']=now();
                DB::table('hotel_booking_details')->where('id',(int)$detail->id)->update($update);
                $nativeDetail=(array)(DB::table('hotel_booking_details')->where('id',(int)$detail->id)->first()??(object)[]);
                $this->syncHotelConfirmationSnapshot((int)$detail->booking_service_id,$nativeDetail,$newValue);
                AuditService::log($request,'booking.hotel_confirmation.updated',Booking::query()->findOrFail($booking),['hotel_stay_id'=>(int)$detail->id,'confirmation_reference'=>$oldValue],['hotel_stay_id'=>(int)$detail->id,'confirmation_reference'=>$newValue]);
            });
            return back()->with('review_success','Hotel confirmation/reference updated without unlocking the booking.');
        }
        if ($action === 'ready') {
            abort_unless($authority->canReopen($request->user()), 403, 'Only an authorized administrator may mark a booking as Travel Ready.');
            abort_unless($this->approvalStatus($row) === 'Approved', 422, 'Only an approved booking can be marked as Travel Ready.');
            $snapshots=$this->snapshots($request,$booking);$selected=$this->selected($snapshots,$booking);
            $commercialState=app(BookingCommercialCompletenessResolver::class)->resolve($selected,...array_values($snapshots));
            if(!$commercialState['complete']) return $this->businessFailure($request,$booking,'Cannot mark this booking as Travel Ready.',$commercialState['reasons']);
            $state=app(BookingTravelReadinessResolver::class)->resolve($row,$selected,...array_values($snapshots));
            if(!$state['ready']) return $this->businessFailure($request,$booking,'Cannot mark this booking as Travel Ready.',$state['blockers']);
            $travelField=$this->firstColumn($columns,['travel_status']);
            if(!$travelField) return $this->businessFailure($request,$booking,'System upgrade required.',['The travel status migration has not been applied.']);
            $update=[$travelField=>'Ready'];
            foreach(['travel_ready_at'=>now(),'travel_ready_by'=>$request->user()?->id] as $field=>$value) if(in_array($field,$columns,true))$update[$field]=$value;
            if(in_array('updated_at',$columns,true))$update['updated_at']=now();DB::table('bookings')->where('id',$booking)->update($update);
            return redirect()->route('bookings.review.show',['booking'=>$booking])->with('review_success','Booking marked Travel Ready after all readiness gates passed.');
        }
        $statusField = $this->firstColumn($columns, ['approval_status','workflow_status','booking_status','status']);
        abort_unless($statusField, 422, 'The native booking workflow status field is unavailable.');
        if ($action === 'submit') {
            $billing = $billingLocks->resolve($booking);
            if ($this->billingBlocksWorkflow($billing)) {
                return back()->withErrors(['review' => $this->billingWorkflowMessage($billing, 'resubmitting the original booking for approval')]);
            }
            abort_unless(in_array(strtoupper(trim((string) ($row['status'] ?? ''))), ['DRAFT', 'PENDING_CONFIRMATION'], true), 422, 'Only a draft booking can be submitted.');
            $snapshots = $this->snapshots($request, $booking); $state=app(BookingCommercialCompletenessResolver::class)->resolve($this->selected($snapshots,$booking),...array_values($snapshots));
            if (!$state['complete']) return back()->withErrors(['review' => 'Cannot send for approval: '.implode(' ', $state['reasons'])]);
            $result = DB::transaction(function () use ($booking, $statusField, $columns, $request): array {
                $locked = DB::table('bookings')->where('id', $booking)->lockForUpdate()->first();
                abort_unless($locked, 404);
                abort_unless(in_array(strtoupper(trim((string) ($locked->status ?? ''))), ['DRAFT', 'PENDING_CONFIRMATION'], true), 422, 'Only a draft booking can be submitted.');
                $lockedSnapshots = $this->snapshots($request, $booking);
                $lockedState = app(BookingCommercialCompletenessResolver::class)->resolve($this->selected($lockedSnapshots, $booking), ...array_values($lockedSnapshots));
                if (! $lockedState['complete']) return ['complete' => false, 'reasons' => $lockedState['reasons']];
                $this->setStatus($booking, $statusField, 'pending_approval', $columns, $request);
                $this->syncGeneralNativeConfirmation($booking, 'pending', $statusField, $columns, $request);
                $submittedAt = now();
                $attribution = [];
                if (in_array('submitted_by', $columns, true)) $attribution['submitted_by'] = $request->user()?->id;
                if (in_array('submitted_at', $columns, true)) $attribution['submitted_at'] = $submittedAt;
                if ($attribution) DB::table('bookings')->where('id', $booking)->update($attribution);
                $auditable = Booking::query()->findOrFail($booking);
                AuditService::log($request, 'booking.submitted', $auditable,
                    ['status' => (string) ($locked->status ?? ''), 'travel_status' => (string) ($locked->travel_status ?? '')],
                    ['status' => 'pending', 'travel_status' => 'PendingTravel'] + $attribution);
                return ['complete' => true, 'reasons' => []];
            });
            if (! $result['complete']) return back()->withErrors(['review' => 'Cannot send for approval: '.implode(' ', $result['reasons'])]);
            return back()->with('review_success', 'Booking sent for approval.');
        }
        if ($action === 'approve') {
            abort_unless($authority->canReopen($request->user()), 403, 'Only an authorized approver may approve this booking.');
            abort_unless($this->approvalStatus($row) === 'Pending Approval', 422, 'Only a pending booking can be approved.');
            $billing = $billingLocks->resolve($booking);
            if ($this->billingBlocksWorkflow($billing)) {
                return back()->withErrors(['review' => $this->billingWorkflowMessage($billing, 'approving the corrected booking')]);
            }
            $snapshots = $this->snapshots($request, $booking);
            $selected = $this->selected($snapshots, $booking);
            $commercialState = app(BookingCommercialCompletenessResolver::class)->resolve($selected, ...array_values($snapshots));
            if (! $commercialState['complete']) {
                return $this->businessFailure($request, $booking, 'Cannot approve this booking until the selected products are commercially complete.', $commercialState['reasons']);
            }
            $result = DB::transaction(function () use ($booking, $statusField, $columns, $request): array {
                $locked = DB::table('bookings')->where('id', $booking)->lockForUpdate()->first();
                abort_unless($locked, 404);
                abort_unless($this->approvalStatus((array) $locked) === 'Pending Approval', 422, 'Only a pending booking can be approved.');
                $lockedSnapshots = $this->snapshots($request, $booking);
                $lockedSelected = $this->selected($lockedSnapshots, $booking);
                $lockedCommercial = app(BookingCommercialCompletenessResolver::class)->resolve($lockedSelected, ...array_values($lockedSnapshots));
                if (! $lockedCommercial['complete']) return ['ready' => false, 'blockers' => $lockedCommercial['reasons']];
                $prospective = (array) $locked;
                $prospective[$statusField] = 'APPROVED';
                $lockedTravel = app(BookingTravelReadinessResolver::class)->resolve($prospective, $lockedSelected, ...array_values($lockedSnapshots));
                if (! $lockedTravel['ready']) return ['ready' => false, 'blockers' => $lockedTravel['blockers']];
                $this->setStatus($booking, $statusField, 'approved', $columns, $request);
                $this->syncGeneralNativeConfirmation($booking, 'CONFIRMED', $statusField, $columns, $request);
                $travelField = $this->firstColumn($columns, ['travel_status']);
                $update = $travelField ? [$travelField => 'Ready'] : [];
                foreach (['travel_ready_at'=>now(),'travel_ready_by'=>$request->user()?->id] as $field=>$value) if (in_array($field, $columns, true)) $update[$field] = $value;
                if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
                if ($update) DB::table('bookings')->where('id', $booking)->update($update);
                $auditable = Booking::query()->findOrFail($booking);
                AuditService::log($request, 'booking.approved', $auditable,
                    ['status' => 'pending', 'travel_status' => 'PendingTravel'],
                    ['status' => 'CONFIRMED', 'travel_status' => 'Ready', 'confirmed_by' => $request->user()?->id]);
                return ['ready' => true, 'blockers' => []];
            });
            if (! $result['ready']) return $this->businessFailure($request, $booking, 'Cannot approve this booking until all selected products are commercially and operationally ready.', $result['blockers']);
            $costingResult = $supplierCostingDrafts->syncBooking($booking, $request->user(), 'auto_create');
            $redirect = back()->with('review_success', 'Booking approved and marked Travel Ready after all selected product readiness gates passed. Commercial product editing is now locked.');
            if (($costingResult['ok'] ?? false) !== true || (int) ($costingResult['attention'] ?? 0) > 0) {
                $redirect->with('review_warning', 'Supplier Costing requires review. Open Supplier Costing to review the booking cost queue.');
            }
            return $redirect;
        }
        if ($action === 'reopen') {
            abort_unless($authority->canReopen($request->user()), 403, 'Only an authorized Administrator may reopen this booking.');
            $reason = trim((string) $request->validate(['reopen_reason'=>['required','string','min:5','max:1000']])['reopen_reason']);
            $currentApproval = $this->approvalStatus($row);
            abort_unless(in_array($currentApproval, ['Pending Approval','Approved'], true), 422, 'Only a Pending Approval or Approved booking can be reopened.');
            $billing = $billingLocks->resolve($booking);
            if ($billing['locked'] ?? false) {
                return redirect()->route('bookings.review.show', ['booking' => $booking])->withErrors(['review' => (string) ($billing['reason'] ?? 'This booking is protected by its billing state.')]);
            }
            DB::transaction(function () use ($booking, $statusField, $columns, $request, $currentApproval, $reason): void {
                $targetWorkflow = $currentApproval === 'Pending Approval' ? 'draft' : 'reopened';
                $this->setStatus($booking, $statusField, $targetWorkflow, $columns, $request);
                $this->syncGeneralNativeConfirmation($booking, $currentApproval === 'Pending Approval' ? 'draft' : 'reopened', $statusField, $columns, $request);
                if($travelField=$this->firstColumn($columns,['travel_status'])){
                    $travelUpdate=[$travelField=>'PendingTravel'];
                    if(in_array('updated_at',$columns,true))$travelUpdate['updated_at']=now();
                    DB::table('bookings')->where('id',$booking)->update($travelUpdate);
                }
                $auditable = Booking::query()->findOrFail($booking);
                AuditService::log($request, 'booking.reopened_for_correction', $auditable,
                    ['status' => $currentApproval, 'travel_status' => 'PendingTravel'],
                    ['status' => $targetWorkflow === 'draft' ? 'Draft' : 'Reopened', 'travel_status' => 'PendingTravel', 'reason' => $reason]);
            });
            return redirect()->route('bookings.review.show',['booking'=>$booking])->with('review_success', $currentApproval === 'Pending Approval'
                ? 'Booking reopened to Draft. Review the commercial data and submit it for approval again.'
                : 'Booking reopened for controlled editing.');
        }
        if ($action === 'notes') {
            if ($this->approvalStatus($row) === 'Approved') abort_unless($authority->canReopen($request->user()), 403, 'Only an authorized administrator may update instructions after approval.');
            $data = $request->validate(['special_instructions'=>['nullable','string','max:5000'],'internal_notes'=>['nullable','string','max:5000']]); $update=[];
            $specialField=$this->firstColumn($columns,['special_instructions','voucher_instructions','client_instructions','notes']);
            $internalField=$this->firstColumn($columns,['internal_notes','booking_internal_notes','staff_notes','private_notes']);
            $before=[];
            if($specialField){$before['special_instructions']=(string)($row[$specialField]??'');$update[$specialField]=trim((string)($data['special_instructions']??''));}
            if($internalField){$before['internal_notes']=(string)($row[$internalField]??'');$update[$internalField]=trim((string)($data['internal_notes']??''));}
            abort_unless($update, 422, 'No compatible native instruction or internal-note field exists.');
            if(in_array('updated_at',$columns,true))$update['updated_at']=now();
            DB::transaction(function() use($booking,$update,$before,$data,$request):void{DB::table('bookings')->where('id',$booking)->update($update);$after=[];if(array_key_exists('special_instructions',$before))$after['special_instructions']=trim((string)($data['special_instructions']??''));if(array_key_exists('internal_notes',$before))$after['internal_notes']=trim((string)($data['internal_notes']??''));AuditService::log($request,'booking.instructions.updated',Booking::query()->findOrFail($booking),$before,$after);});
            return back()->with('review_success','Booking notes updated.');
        }
        abort(404);
    }

    private function booking(int $id): array { abort_unless(Schema::hasTable('bookings'),404); $row=DB::table('bookings')->where('id',$id)->first(); abort_unless($row,404); return (array)$row; }
    private function billingBlocksWorkflow(array $billing): bool
    {
        return (bool) ($billing['locked'] ?? false)
            && in_array((string) ($billing['code'] ?? ''), ['draft_invoice','final_invoice','approved_supplement','supplement_invoice'], true);
    }

    private function billingWorkflowMessage(array $billing, string $action): string
    {
        if (($billing['code'] ?? '') === 'draft_invoice') {
            return 'This booking has an active Draft Sales Invoice. Cancel the Draft Sales Invoice before '.$action.'.';
        }
        return (string) ($billing['reason'] ?? 'This booking is protected by its billing state.');
    }
    private function snapshots(Request $request,int $id): array {
        $snapshots = [
            'air'=>$this->safe(fn()=>app(GeneralBookingAirProductController::class)->show($request,$id)->getData(true)),
            'hotel'=>$this->safe(fn()=>app(GeneralBookingHotelProductController::class)->show($request,$id)->getData(true)),
            'transport'=>$this->safe(fn()=>app(GeneralBookingTransportProductController::class)->show($request,$id)->getData(true)),
            'visa'=>$this->safe(fn()=>app(GeneralBookingVisaProductController::class)->show($request,$id)->getData(true)),
            'other'=>$this->otherSnapshot($id),
        ];
        $package = app(CompletePackageOperationalResolver::class)->snapshot($id);
        if (($package['air']['itinerary'] ?? []) !== []) $snapshots['air']['itinerary'] = array_values(array_merge((array) ($snapshots['air']['itinerary'] ?? []), $package['air']['itinerary']));
        if (($package['air']['tickets'] ?? []) !== []) $snapshots['air']['tickets'] = array_values(array_merge((array) ($snapshots['air']['tickets'] ?? []), $package['air']['tickets']));
        if (($package['air']['common'] ?? []) !== []) $snapshots['air']['common'] = array_merge((array) ($snapshots['air']['common'] ?? []), $package['air']['common']);
        if (($package['hotel']['stays'] ?? []) !== []) $snapshots['hotel']['stays'] = array_values(array_merge((array) ($snapshots['hotel']['stays'] ?? []), $package['hotel']['stays']));
        if (($package['transport']['transports'] ?? []) !== []) $snapshots['transport']['transports'] = array_values(array_merge((array) ($snapshots['transport']['transports'] ?? []), $package['transport']['transports']));
        if (($package['visa']['visa_rows'] ?? []) !== []) $snapshots['visa']['visa_rows'] = array_values(array_merge((array) ($snapshots['visa']['visa_rows'] ?? []), $package['visa']['visa_rows']));
        if (($package['other'] ?? []) !== []) $snapshots['other']['services'] = array_values(array_merge((array) ($snapshots['other']['services'] ?? []), $package['other']));
        return $snapshots;
    }
    private function otherSnapshot(int $id): array
    {
        if(!Schema::hasTable('booking_services')||!Schema::hasTable('products_services'))return ['services'=>[]];
        try{
            $rows=DB::table('booking_services as bs')->join('products_services as ps','ps.id','=','bs.product_service_id')->where('bs.booking_id',$id)->where(function($q):void{$q->whereNull('bs.status')->orWhereRaw("UPPER(bs.status) NOT IN ('CANCELLED','VOIDED','VOID')");})->where(function($q):void{$q->whereNull('ps.is_active')->orWhere('ps.is_active',true);})->get(['bs.id','bs.description','bs.quantity','bs.unit_price','bs.line_total','ps.category','ps.passenger_link_mode']);
            $services=[];
            foreach($rows as $row){$r=(array)$row;$r['passenger_count']=Schema::hasTable('booking_service_passengers')?(int)DB::table('booking_service_passengers')->where('booking_service_id',$r['id'])->count():0;$category=strtoupper(trim((string)($r['category']??'')));if($category==='OTHER')$services[]=$r;}
            return ['services'=>$services];
        }catch(Throwable){return ['services'=>[]];}
    }
    private function safe(callable $fn): array { try{$v=$fn();return is_array($v)?$v:[];}catch(Throwable $e){report($e);return [];} }
    private function selected(array $s, int $booking): array
    {
        $r=[];
        if(($s['air']['itinerary']??[])||($s['air']['tickets']??[]))$r[]='air';
        if($s['hotel']['stays']??[])$r[]='hotel';
        if(($s['transport']['transports']??[])||app(GeneralBookingTransportProductController::class)->activeServiceId($booking)!==null)$r[]='transport';
        if($s['visa']['visa_rows']??[])$r[]='visa';

        // A persisted active booking service is authoritative selection even
        // when its detail table is empty. The downstream resolvers must then
        // fail closed on the missing required detail instead of treating the
        // product as unselected.
        try {
            if (Schema::hasTable('booking_services') && Schema::hasTable('products_services')) {
                $services=DB::table('booking_services as bs')
                    ->join('products_services as ps','ps.id','=','bs.product_service_id')
                    ->where('bs.booking_id',$booking)
                    ->where(function($q):void{$q->whereNull('bs.status')->orWhereRaw("UPPER(bs.status) NOT IN ('CANCELLED','VOIDED','VOID')");})
                    ->where(function($q):void{$q->whereNull('ps.is_active')->orWhere('ps.is_active',true);})
                    ->get(['ps.category']);
                foreach($services as $service){
                    $category=strtolower(trim((string)($service->category??'')));
                    if(str_contains($category,'air')||str_contains($category,'flight'))$r[]='air';
                    elseif(str_contains($category,'hotel'))$r[]='hotel';
                    elseif(str_contains($category,'transport'))$r[]='transport';
                    elseif(str_contains($category,'visa'))$r[]='visa';
                    elseif(str_contains($category,'other'))$r[]='other';
                    else $r[]='unsupported';
                }
            }
        } catch (Throwable) {
            // Preserve the existing snapshot behavior if optional discovery
            // is unavailable; no request parameter can add a selection.
        }
        return array_values(array_unique($r));
    }
    private function passengerSummary(array $rows):array{$a=$c=$i=0;foreach($rows as $r){$t=strtoupper($this->first((array)$r,['fare_type','passenger_type','age_type']));if(str_contains($t,'INF'))$i++;elseif(str_contains($t,'CHD')||str_contains($t,'CHILD'))$c++;else$a++;}return ['total'=>count($rows),'adult'=>$a,'child'=>$c,'infant'=>$i];}
    private function airSummary(array $s):array{$rows=array_values((array)($s['itinerary']??[]));$fmt=fn($r)=>trim($this->first((array)$r,['from','origin','departure_airport'])).' → '.trim($this->first((array)$r,['to','destination','arrival_airport']));return ['count'=>count($rows),'routes'=>array_values(array_filter(array_map($fmt,array_slice($rows,0,3))))];}
    private function hotelSummary(array $s, int $booking):array
    {
        $rows = array_values((array) ($s['stays'] ?? []));
        $native = [];
        if ($rows && Schema::hasTable('hotel_booking_details') && Schema::hasTable('booking_services')) {
            $native = DB::table('hotel_booking_details as h')
                ->join('booking_services as bs', 'bs.id', '=', 'h.booking_service_id')
                ->where('bs.booking_id', $booking)
                ->orderBy('h.id')
                ->get(['h.id', 'h.booking_service_id'])
                ->map(fn ($row): array => ['id' => (int) $row->id, 'booking_service_id' => (int) $row->booking_service_id])
                ->all();
        }
        $used = [];
        foreach ($rows as $index => &$row) {
            $row = (array) $row;
            if ((int) ($row['id'] ?? 0) > 0) { $used[(int) $row['id']] = true; continue; }
            $serviceId = (int) ($row['booking_service_id'] ?? 0);
            $match = null;
            foreach ($native as $candidate) {
                if (($used[$candidate['id']] ?? false) || ($serviceId > 0 && $candidate['booking_service_id'] !== $serviceId)) continue;
                $match = $candidate; break;
            }
            if (!$match && isset($native[$index])) {
                foreach ($native as $candidate) if (!($used[$candidate['id']] ?? false)) { $match = $candidate; break; }
            }
            if ($match) { $row['id'] = $match['id']; $row['booking_service_id'] = $row['booking_service_id'] ?? $match['booking_service_id']; $used[$match['id']] = true; }
        }
        unset($row);
        return ['count'=>count($rows),'total_nights'=>array_sum(array_map(fn($r)=>max(0,(int)((array)$r)['nights']??0),$rows)),'rows'=>array_slice($rows,0,3)];
    }
    private function transportSummary(array $s):array{$rows=array_values((array)($s['transports']??[]));return ['count'=>count($rows),'rows'=>array_slice($rows,0,3)];}
    private function visaSummary(array $s):array{$rows=(array)($s['visa_rows']??[]);$issued=count(array_filter($rows,fn($r)=>strtoupper($this->first((array)$r,['status','visa_status']))==='ISSUED'));return ['total'=>count($rows),'issued'=>$issued,'pending'=>count($rows)-$issued];}
    private function identity(array $b,int $id,array $customer):array{return ['reference'=>$this->first($b,['booking_reference','booking_ref','booking_no','booking_number'])?:('Booking #'.$id),'voucher'=>$this->first($b,['travel_voucher_no','client_voucher_no','voucher_no','voucher_number'])?:'Generated on preview','booking_date'=>$this->first($b,['booking_date','date','created_at']),'travel_date'=>$this->first($b,['travel_date','departure_date','start_date']),'customer'=>trim((string)($customer['name']??''))?:'—','branch'=>$this->first($b,['branch_name','office_name'])?:'—','agent'=>$this->first($b,['agent_name','service_partner_name','partner_name'])?:'—','salesperson'=>$this->first($b,['salesperson_name','sales_person_name','created_by_name'])?:'—','package'=>$this->first($b,['package_name','package_reference','reference_name'])?:'—','manual'=>$this->first($b,['manual_voucher_no','manual_no','manual_number'])?:'—'];}
    private function approvalStatus(array $b):string{$s=str_replace(['-','_'],' ',strtolower($this->first($b,['approval_status','workflow_status','booking_status','status'])?:'draft'));return match($s){'pending','pending approval','submitted'=>'Pending Approval','approved','confirmed'=>'Approved','reopened','reopen','reapproval required'=>'Reopened',default=>'Draft'};}
    private function prospectiveApprovedRow(array $row): array
    {
        foreach (['approval_status','workflow_status','booking_status','status'] as $field) {
            if (array_key_exists($field, $row)) $row[$field] = 'CONFIRMED';
        }
        return $row;
    }
    private function accounting(array $x):array{$latest=$x['latest']??null;if(!$latest)return ['label'=>'Not Created','detail'=>'No Sales Invoice is linked to this booking.'];$s=strtolower((string)($latest['status']??'draft'));return ['label'=>ucwords(str_replace('_',' ',$s)),'detail'=>'Sales Invoice '.(($latest['number']??'')?:'#'.($latest['id']??'')).'.'];}
    private function payment(int $booking,float $due):array{$paid=0.0;try{if(Schema::hasTable('cash_vouchers')){$c=Schema::getColumnListing('cash_vouchers');$bc=$this->firstColumn($c,['booking_id','travel_booking_id','source_booking_id']);$ac=$this->firstColumn($c,['allocated_amount','amount','total_amount','base_amount']);$sc=$this->firstColumn($c,['status','voucher_status']);if($bc&&$ac){$q=DB::table('cash_vouchers')->where($bc,$booking);if($sc)$q->whereIn($sc,['approved','posted']);if(in_array('direction',$c,true))$q->where('direction','in');if(in_array('party_type',$c,true))$q->where('party_type','customer');$paid=(float)$q->sum($ac);}}}catch(Throwable){}$label=$paid<=0?'Unpaid':($due>0&&$paid+0.01<$due?'Partially Paid':'Paid');return ['label'=>$label,'paid'=>round($paid,2),'due'=>round(max(0,$due-$paid),2)];}
    private function first(array $row,array $keys):string{foreach($keys as $k){$v=trim((string)($row[$k]??''));if($v!=='')return $v;}return '';}
    private function firstColumn(array $columns,array $keys):?string{foreach($keys as $k)if(in_array($k,$columns,true))return $k;return null;}
    private function syncHotelConfirmationSnapshot(int $serviceId,array $nativeDetail,string $confirmation):void
    {
        if($serviceId<=0||!Schema::hasTable('booking_services'))return;
        $columns=Schema::getColumnListing('booking_services');$row=(array)(DB::table('booking_services')->where('id',$serviceId)->first()??(object)[]);
        foreach(array_intersect(['notes','remarks','internal_notes','description','details','other_details','comment','comments'],$columns) as $field){
            $raw=(string)($row[$field]??'');if($raw==='')continue;$payload=null;$mode='tag';$decoded=null;
            if(preg_match('/\[\[ETERP_HOTEL_STAYS:([A-Za-z0-9+\/=]+)\]\]/',$raw,$match)){$json=base64_decode((string)($match[1]??''),true);$payload=$json===false?null:json_decode($json,true);}
            else{$decoded=json_decode($raw,true);if(is_array($decoded)&&isset($decoded['et_erp_hotel_stays']['stays'])){$payload=$decoded['et_erp_hotel_stays'];$mode='json';}}
            if(!is_array($payload)||!is_array($payload['stays']??null))continue;$stays=array_values(array_filter($payload['stays'],'is_array'));$matchIndex=null;
            foreach($stays as $index=>$stay){$name=trim((string)($nativeDetail['hotel_name_snapshot']??$nativeDetail['hotel_name']??$nativeDetail['name']??''));$in=trim((string)($nativeDetail['check_in']??$nativeDetail['check_in_date']??''));$out=trim((string)($nativeDetail['check_out']??$nativeDetail['check_out_date']??''));$nameMatch=$name===''||strcasecmp($name,trim((string)($stay['hotel_name']??'')))===0;$inMatch=$in===''||$in===trim((string)($stay['check_in']??''));$outMatch=$out===''||$out===trim((string)($stay['check_out']??''));if($nameMatch&&$inMatch&&$outMatch){$matchIndex=$index;break;}}
            if($matchIndex===null&&count($stays)===1)$matchIndex=0;if($matchIndex===null)continue;$stays[$matchIndex]['confirmation_no']=$confirmation;$payload['stays']=$stays;
            if($mode==='json'){$decoded['et_erp_hotel_stays']=$payload;$value=json_encode($decoded,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}else{$encoded=base64_encode((string)json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$value=preg_replace('/\[\[ETERP_HOTEL_STAYS:[A-Za-z0-9+\/=]+\]\]/','[['.'ETERP_HOTEL_STAYS:'.$encoded.']]', $raw,1)??$raw;}
            $update=[$field=>$value];if(in_array('updated_at',$columns,true))$update['updated_at']=now();DB::table('booking_services')->where('id',$serviceId)->update($update);return;
        }
    }
    private function column(array $keys):?string{return Schema::hasTable('bookings')?$this->firstColumn(Schema::getColumnListing('bookings'),$keys):null;}
    private function setStatus(int $id,string $field,string $value,array $columns,Request $request):void{$u=[$field=>$value];if(in_array('updated_at',$columns,true))$u['updated_at']=now();foreach($value==='approved'?['approved_by'=>$request->user()?->id,'approved_at'=>now()]:[] as $k=>$v)if(in_array($k,$columns,true))$u[$k]=$v;DB::table('bookings')->where('id',$id)->update($u);}
    /** GENERAL workflow may use any visible alias; host accounting always reads bookings.status. */
    private function syncGeneralNativeConfirmation(int $id,string $nativeStatus,string $workflowField,array $columns,Request $request):void
    {
        if(!in_array('status',$columns,true)) throw new \RuntimeException('The native bookings.status column is unavailable.');
        $update=['status'=>$nativeStatus];
        $confirmed=strcasecmp($nativeStatus,'CONFIRMED')===0;
        foreach(['is_confirmed','confirmed'] as $field) if(in_array($field,$columns,true))$update[$field]=$confirmed?1:0;
        if(in_array('confirmed_at',$columns,true))$update['confirmed_at']=$confirmed?now():null;
        if($confirmed) foreach(['confirmed_by','confirmed_by_id','confirmed_user_id'] as $field) if(in_array($field,$columns,true))$update[$field]=$request->user()?->id;
        if(in_array('updated_at',$columns,true))$update['updated_at']=now();
        DB::table('bookings')->where('id',$id)->update($update);
        $stored=trim((string)DB::table('bookings')->where('id',$id)->value('status'));
        if(strcasecmp($stored,$nativeStatus)!==0) throw new \RuntimeException('Native booking status persistence verification failed.');
    }
    private function persistedTravelStatus(array $row):string
    {
        $value=trim((string)($row['travel_status']??''));
        return strcasecmp(str_replace(['_','-'],'',$value),'ready')===0?'Ready':'PendingTravel';
    }
    private function businessFailure(Request $request,int $booking,string $message,array $blockers): mixed
    {
        $blockers=array_values(array_filter(array_map(fn($v)=>trim((string)$v),$blockers)));
        if($request->expectsJson()||$request->ajax()) return response()->json(['message'=>$message,'error'=>'travel_ready_blocked','blockers'=>$blockers],422);
        return redirect()->route('bookings.review.show',['booking'=>$booking])->with('review_error',$message.' Complete the following first: '.implode(' ',array_map(fn($v)=>'• '.$v,$blockers)));
    }
}
