<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingTravelReadinessResolver;
use App\Services\Operations\GroupUmrahEditAuthority;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Organization\CompanyProfileSnapshotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

final class GeneralBookingReviewController extends Controller
{
    public function show(Request $request, int $booking, NativeErpLayoutResolver $layout, CompanyProfileSnapshotService $company, NativeSalesInvoiceInspector $invoices, BookingTravelReadinessResolver $readiness, GroupUmrahEditAuthority $authority): View
    {
        $row = $this->booking($booking);
        $snapshots = $this->snapshots($request, $booking);
        $selected = $this->selected($snapshots);
        $commercial = $this->commercial($row, $snapshots);
        $checklist = $this->checklist($snapshots, $selected);
        $travel = $readiness->resolve($row, $selected, ...array_values($snapshots));
        $invoice = $invoices->summary($booking);
        $passengers = (array) ($snapshots['air']['passengers'] ?? $snapshots['visa']['passengers'] ?? []);

        return view('operations.bookings.general-booking-review-v113160', [
            'layoutMeta' => $layout->resolve(), 'bookingId' => $booking, 'booking' => $row,
            'company' => $company->get($row), 'identity' => $this->identity($row, $booking),
            'passengerSummary' => $this->passengerSummary($passengers), 'airSummary' => $this->airSummary($snapshots['air']),
            'hotelSummary' => $this->hotelSummary($snapshots['hotel']), 'transportSummary' => $this->transportSummary($snapshots['transport']),
            'visaSummary' => $this->visaSummary($snapshots['visa']), 'commercial' => $commercial,
            'selected' => $selected, 'checklist' => $checklist, 'completion' => $this->completion($checklist),
            'approvalStatus' => $this->approvalStatus($row), 'travel' => $travel,
            'accounting' => $this->accounting($invoice), 'payment' => $this->payment($booking, $commercial['final_sale_total']),
            'specialInstructions' => $this->first($row, ['special_instructions','voucher_instructions','client_instructions','notes','remarks','description']),
            'specialField' => $this->column(['special_instructions','voucher_instructions','client_instructions']),
            'internalNotes' => $this->first($row, ['internal_notes','booking_internal_notes','staff_notes','private_notes']),
            'internalField' => $this->column(['internal_notes','booking_internal_notes','staff_notes','private_notes']),
            'canReopen' => $authority->canReopen($request->user()),
            'canApprove' => $authority->canReopen($request->user()),
        ]);
    }

    public function action(Request $request, int $booking, string $action, GroupUmrahEditAuthority $authority): RedirectResponse
    {
        $row = $this->booking($booking); $columns = Schema::getColumnListing('bookings');
        if ($action === 'ready') {
            $snapshots=$this->snapshots($request,$booking);$selected=$this->selected($snapshots);
            $state=app(BookingTravelReadinessResolver::class)->resolve($row,$selected,...array_values($snapshots));
            if(!$state['ready'])return back()->withErrors(['review'=>'Cannot mark Travel Ready: '.implode(' ',$state['blockers'])]);
            $travelField=$this->firstColumn($columns,['travel_status']);
            abort_unless($travelField,422,'The native travel_status field is unavailable.');
            $update=[$travelField=>'Ready'];if(in_array('updated_at',$columns,true))$update['updated_at']=now();DB::table('bookings')->where('id',$booking)->update($update);
            return back()->with('review_success','Booking marked Travel Ready after all readiness gates passed.');
        }
        $statusField = $this->firstColumn($columns, ['approval_status','workflow_status','booking_status','status']);
        abort_unless($statusField, 422, 'The native booking workflow status field is unavailable.');
        if ($action === 'submit') {
            $snapshots = $this->snapshots($request, $booking); $checklist = $this->checklist($snapshots, $this->selected($snapshots));
            $errors = array_values(array_filter(array_map(fn ($item) => $item['complete'] ? null : $item['message'], $checklist)));
            if ($errors) return back()->withErrors(['review' => 'Cannot send for approval: '.implode(' ', $errors)]);
            $this->setStatus($booking, $statusField, 'pending_approval', $columns, $request);
            return back()->with('review_success', 'Booking sent for approval.');
        }
        if ($action === 'approve') {
            abort_unless($authority->canReopen($request->user()), 403, 'Only an authorized approver may approve this booking.');
            abort_unless($this->approvalStatus($row) === 'Pending Approval', 422, 'Only a pending booking can be approved.');
            $this->setStatus($booking, $statusField, 'approved', $columns, $request);
            return back()->with('review_success', 'Booking approved. Commercial product editing is now locked.');
        }
        if ($action === 'reopen') {
            abort_unless($authority->canReopen($request->user()), 403, 'Only an Administrator may reopen an approved booking.');
            $this->setStatus($booking, $statusField, 'reopened', $columns, $request);
            return back()->with('review_success', 'Booking reopened for controlled editing.');
        }
        if ($action === 'notes') {
            $data = $request->validate(['special_instructions'=>['nullable','string','max:5000'],'internal_notes'=>['nullable','string','max:5000']]); $update=[];
            if ($field=$this->firstColumn($columns,['special_instructions','voucher_instructions','client_instructions'])) $update[$field]=trim((string)($data['special_instructions']??''));
            if ($field=$this->firstColumn($columns,['internal_notes','booking_internal_notes','staff_notes','private_notes'])) $update[$field]=trim((string)($data['internal_notes']??''));
            abort_unless($update, 422, 'No compatible native instruction or internal-note field exists.');
            if(in_array('updated_at',$columns,true))$update['updated_at']=now(); DB::table('bookings')->where('id',$booking)->update($update);
            return back()->with('review_success','Booking notes updated.');
        }
        abort(404);
    }

    private function booking(int $id): array { abort_unless(Schema::hasTable('bookings'),404); $row=DB::table('bookings')->where('id',$id)->first(); abort_unless($row,404); return (array)$row; }
    private function snapshots(Request $request,int $id): array { return [
        'air'=>$this->safe(fn()=>app(GeneralBookingAirProductController::class)->show($request,$id)->getData(true)),
        'hotel'=>$this->safe(fn()=>app(GeneralBookingHotelProductController::class)->show($request,$id)->getData(true)),
        'transport'=>$this->safe(fn()=>app(GeneralBookingTransportProductController::class)->show($request,$id)->getData(true)),
        'visa'=>$this->safe(fn()=>app(GeneralBookingVisaProductController::class)->show($request,$id)->getData(true)),
    ]; }
    private function safe(callable $fn): array { try{$v=$fn();return is_array($v)?$v:[];}catch(Throwable $e){report($e);return [];} }
    private function selected(array $s): array { $r=[]; if(($s['air']['itinerary']??[])||($s['air']['tickets']??[]))$r[]='air';if($s['hotel']['stays']??[])$r[]='hotel';if($s['transport']['transports']??[])$r[]='transport';if($s['visa']['visa_rows']??[])$r[]='visa';return $r; }
    private function checklist(array $s,array $selected): array {
        $pass=(array)($s['air']['passengers']??$s['visa']['passengers']??[]);$items=['passengers'=>['label'=>'Passengers','complete'=>count($pass)>0,'message'=>'Add at least one passenger.']];
        if(in_array('air',$selected,true)){ $rows=(array)($s['air']['itinerary']??[]);$ok=$rows&&$this->rowsHave($rows,[['from'],['to'],['departure_at'],['flight_number','airline_code','airline']])&&(float)($s['air']['summary']['customer_total']??0)>0&&(float)($s['air']['summary']['supplier_total']??0)>0;$items['air']=['label'=>'Air','complete'=>$ok,'message'=>'Complete the saved Air itinerary, customer fare and supplier cost.']; }
        if(in_array('hotel',$selected,true)){ $rows=(array)($s['hotel']['stays']??[]);$ok=$rows&&$this->rowsHave($rows,[['hotel_name'],['city'],['check_in'],['check_out']])&&(float)($s['hotel']['summary']['customer_total']??0)>0&&(float)($s['hotel']['summary']['vendor_total']??0)>0;$items['hotel']=['label'=>'Hotel','complete'=>$ok,'message'=>'Complete Hotel names, dates, cities, customer total and vendor cost.']; }
        if(in_array('transport',$selected,true)){ $rows=(array)($s['transport']['transports']??[]);$ok=$rows&&$this->rowsHave($rows,[['company_name'],['route_name'],['vehicle_type']])&&(float)($s['transport']['summary']['customer_total']??0)>0&&(float)($s['transport']['summary']['vendor_total']??0)>0;$items['transport']=['label'=>'Transport','complete'=>$ok,'message'=>'Complete Transport company, route, vehicle, customer total and vendor cost.']; }
        if(in_array('visa',$selected,true)){ $rows=(array)($s['visa']['visa_rows']??[]);$ok=$rows&&$this->rowsHave($rows,[['visa_rate_id','rate_card_id'],['sale_pkr'],['vendor_cost_pkr'],['saudi_company_name','saudi_company_name_snapshot'],['pakistani_iata_name','pakistani_iata_name_snapshot']]);$items['visa']=['label'=>'Visa Commercial','complete'=>$ok,'message'=>'Complete Visa rate, relationship, customer sale and vendor cost for every Visa passenger.']; }
        return $items;
    }
    private function completion(array $items): array{$total=count($items);$done=count(array_filter($items,fn($x)=>$x['complete']));return ['done'=>$done,'total'=>$total,'percent'=>$total?(int)round($done/$total*100):0];}
    private function passengerSummary(array $rows):array{$a=$c=$i=0;foreach($rows as $r){$t=strtoupper($this->first((array)$r,['fare_type','passenger_type','age_type']));if(str_contains($t,'INF'))$i++;elseif(str_contains($t,'CHD')||str_contains($t,'CHILD'))$c++;else$a++;}return ['total'=>count($rows),'adult'=>$a,'child'=>$c,'infant'=>$i];}
    private function airSummary(array $s):array{$rows=array_values((array)($s['itinerary']??[]));$fmt=fn($r)=>trim($this->first((array)$r,['from','origin','departure_airport'])).' → '.trim($this->first((array)$r,['to','destination','arrival_airport']));return ['count'=>count($rows),'routes'=>array_values(array_filter(array_map($fmt,array_slice($rows,0,3))))];}
    private function hotelSummary(array $s):array{$rows=array_values((array)($s['stays']??[]));return ['count'=>count($rows),'total_nights'=>array_sum(array_map(fn($r)=>max(0,(int)((array)$r)['nights']??0),$rows)),'rows'=>array_slice($rows,0,3)];}
    private function transportSummary(array $s):array{$rows=array_values((array)($s['transports']??[]));return ['count'=>count($rows),'rows'=>array_slice($rows,0,3)];}
    private function visaSummary(array $s):array{$rows=(array)($s['visa_rows']??[]);$issued=count(array_filter($rows,fn($r)=>strtoupper($this->first((array)$r,['status','visa_status']))==='ISSUED'));return ['total'=>count($rows),'issued'=>$issued,'pending'=>count($rows)-$issued];}
    private function commercial(array $b,array $s):array{$customer=$vendor=0;foreach($s as $x){$customer+=(float)($x['summary']['customer_total']??0);$vendor+=(float)($x['summary']['vendor_total']??$x['summary']['supplier_total']??0);} $discount=(float)($this->first($b,['discount_amount','discount_value','total_discount'])?:0);$final=$this->first($b,['final_sale_total','net_total','grand_total','booking_total']);$final=$final!==''?(float)$final:max(0,$customer-$discount);return ['gross_customer_total'=>round($customer,2),'vendor_cost_total'=>round($vendor,2),'discount_type'=>$this->first($b,['discount_type','discount_mode'])?:'Amount','discount_value'=>round($discount,2),'final_sale_total'=>round($final,2),'gross_margin'=>round($final-$vendor,2),'agent_commission'=>(float)($this->first($b,['agent_commission','agent_commission_amount'])?:0),'salesperson_commission'=>(float)($this->first($b,['salesperson_commission','sales_commission','salesperson_commission_amount'])?:0),'currency'=>strtoupper($this->first($b,['currency_code','currency','booking_currency'])?:'PKR')];}
    private function identity(array $b,int $id):array{return ['reference'=>$this->first($b,['booking_reference','booking_ref','booking_no','booking_number'])?:('Booking #'.$id),'voucher'=>$this->first($b,['travel_voucher_no','client_voucher_no','voucher_no','voucher_number'])?:'Generated on preview','booking_date'=>$this->first($b,['booking_date','date','created_at']),'travel_date'=>$this->first($b,['travel_date','departure_date','start_date']),'customer'=>$this->first($b,['customer_name','client_name','party_name'])?:'—','branch'=>$this->first($b,['branch_name','office_name'])?:'—','agent'=>$this->first($b,['agent_name','service_partner_name','partner_name'])?:'—','salesperson'=>$this->first($b,['salesperson_name','sales_person_name','created_by_name'])?:'—','package'=>$this->first($b,['package_name','package_reference','reference_name'])?:'—','manual'=>$this->first($b,['manual_voucher_no','manual_no','manual_number'])?:'—'];}
    private function approvalStatus(array $b):string{$s=str_replace(['-','_'],' ',strtolower($this->first($b,['approval_status','workflow_status','booking_status','status'])?:'draft'));return match($s){'pending','pending approval','submitted'=>'Pending Approval','approved','confirmed'=>'Approved','reopened','reopen','reapproval required'=>'Reopened',default=>'Draft'};}
    private function accounting(array $x):array{$latest=$x['latest']??null;if(!$latest)return ['label'=>'Not Created','detail'=>'No Sales Invoice is linked to this booking.'];$s=strtolower((string)($latest['status']??'draft'));return ['label'=>ucwords(str_replace('_',' ',$s)),'detail'=>'Sales Invoice '.(($latest['number']??'')?:'#'.($latest['id']??'')).'.'];}
    private function payment(int $booking,float $due):array{$paid=0.0;try{if(Schema::hasTable('cash_vouchers')){$c=Schema::getColumnListing('cash_vouchers');$bc=$this->firstColumn($c,['booking_id','travel_booking_id','source_booking_id']);$ac=$this->firstColumn($c,['allocated_amount','amount','total_amount','base_amount']);$sc=$this->firstColumn($c,['status','voucher_status']);if($bc&&$ac){$q=DB::table('cash_vouchers')->where($bc,$booking);if($sc)$q->whereIn($sc,['approved','posted']);if(in_array('direction',$c,true))$q->where('direction','in');if(in_array('party_type',$c,true))$q->where('party_type','customer');$paid=(float)$q->sum($ac);}}}catch(Throwable){}$label=$paid<=0?'Unpaid':($due>0&&$paid+0.01<$due?'Partially Paid':'Paid');return ['label'=>$label,'paid'=>round($paid,2),'due'=>round(max(0,$due-$paid),2)];}
    private function rowsHave(array $rows,array $groups):bool{foreach($rows as $r)foreach($groups as $g)if($this->first((array)$r,$g)==='')return false;return true;}
    private function first(array $row,array $keys):string{foreach($keys as $k){$v=trim((string)($row[$k]??''));if($v!=='')return $v;}return '';}
    private function firstColumn(array $columns,array $keys):?string{foreach($keys as $k)if(in_array($k,$columns,true))return $k;return null;}
    private function column(array $keys):?string{return Schema::hasTable('bookings')?$this->firstColumn(Schema::getColumnListing('bookings'),$keys):null;}
    private function setStatus(int $id,string $field,string $value,array $columns,Request $request):void{$u=[$field=>$value];if(in_array('updated_at',$columns,true))$u['updated_at']=now();foreach($value==='approved'?['approved_by'=>$request->user()?->id,'approved_at'=>now()]:[] as $k=>$v)if(in_array($k,$columns,true))$u[$k]=$v;DB::table('bookings')->where('id',$id)->update($u);}
}
