<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingService;
use App\Models\AirTicketDetail;
use App\Models\ProductService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingServiceController extends Controller
{
    public function store(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->isEditable(),422);
        [$data,$product,$passengerIds]=$this->validated($request,$booking);

        $service=DB::transaction(function()use($request,$booking,$data,$product,$passengerIds){
            $service=BookingService::query()->create(array_merge($data,[
                'booking_id'=>$booking->id,
                'line_total'=>$this->lineTotal($data,$product),
                'pricing_basis_snapshot'=>$product->pricing_basis,
                'passenger_link_mode_snapshot'=>$product->passenger_link_mode,
                'status'=>'DRAFT',
                'created_by'=>$request->user()->id,
                'updated_by'=>$request->user()->id,
            ]));
            $service->passengers()->sync($passengerIds);
            AuditService::log($request,'booking.service.created',$service,[],$service->toArray());
            return $service;
        });

        $category=strtoupper((string)$service->product?->category);
        return back()->with(
            'status',
            $category==='HOTEL'
                ? 'Hotel Stay created. Booking passengers were linked automatically.'
                : (($service->product?->name ?: 'Booking').' service added. Passenger links and quantity have been validated.')
        );
    }

    public function update(Request $request, Booking $booking, BookingService $service): RedirectResponse
    {
        abort_unless($booking->isEditable()&&(int)$service->booking_id===(int)$booking->id,422);
        [$data,$product,$passengerIds]=$this->validated($request,$booking);
        $old=array_merge($service->toArray(),['passenger_ids'=>$service->passengers()->pluck('booking_passengers.id')->all()]);

        DB::transaction(function()use($request,$service,$data,$product,$passengerIds):void{
            $category=strtoupper((string)$product->category);
            $isAir=$category==='AIR_TICKET';
            $isHotel=$category==='HOTEL';
            if($isAir){
                $ticketPassengerIds=AirTicketDetail::query()->where('booking_service_id',$service->id)->pluck('booking_passenger_id')->unique()->map(fn($v)=>(int)$v)->all();
                foreach($ticketPassengerIds as $pid){
                    if(!in_array($pid,$passengerIds,true))throw ValidationException::withMessages(['passenger_ids'=>'A passenger with an existing ticket row cannot be unlinked. Edit/correct the ticket workflow first.']);
                }
                $ticketCount=AirTicketDetail::query()->where('booking_service_id',$service->id)->count();
                $saleTotal=(float)AirTicketDetail::query()->where('booking_service_id',$service->id)->sum('selling_total');
                $data['quantity']=max(1,$ticketCount ?: count($passengerIds));
                $data['unit_price']=$ticketCount?round($saleTotal/$ticketCount,2):0;
                $data['line_total']=round($saleTotal,2);
            }elseif($isHotel){
                // Hotel service totals are owned by the Smart Hotel Booking Workspace.
                // Generic service editing may change links/description only until Hotel details exist.
                $data['service_from']=$service->service_from;
                $data['service_to']=$service->service_to;
                $data['quantity']=$service->quantity;
                $data['unit_price']=$service->unit_price;
                $data['line_total']=$service->line_total;
                $data['pricing_basis_snapshot']=$service->pricing_basis_snapshot;
            }else{
                $data['line_total']=$this->lineTotal($data,$product);
            }
            $service->update(array_merge($data,[
                'pricing_basis_snapshot'=>$isHotel ? $service->pricing_basis_snapshot : $product->pricing_basis,
                'passenger_link_mode_snapshot'=>$product->passenger_link_mode,
                'status'=>'DRAFT',
                'updated_by'=>$request->user()->id,
            ]));
            $service->passengers()->sync($passengerIds);
        });

        $fresh=$service->fresh()->load('passengers');
        AuditService::log($request,'booking.service.updated',$service,$old,array_merge($fresh->toArray(),['passenger_ids'=>$fresh->passengers->pluck('id')->all()]));
        return back()->with('status','Booking service updated. Reconfirm the booking after all service lines are ready.');
    }

    public function cancel(Request $request, Booking $booking, BookingService $service): RedirectResponse
    {
        abort_unless($booking->isEditable()&&(int)$service->booking_id===(int)$booking->id,422);
        $old=$service->toArray();
        $service->update(['status'=>'CANCELLED','updated_by'=>$request->user()->id]);
        AuditService::log($request,'booking.service.cancelled',$service,$old,$service->fresh()->toArray());
        return back()->with('status','Service cancelled without deleting history.');
    }

    /** @return array{0:array,1:ProductService,2:array} */
    private function validated(Request $request, Booking $booking): array
    {
        $productId=(int)$request->input('product_service_id');
        $product=ProductService::query()
            ->where('company_id',$booking->company_id)
            ->whereKey($productId)
            ->where('is_active',true)
            ->firstOrFail();
        $category=strtoupper((string)$product->category);
        $isAir=$category==='AIR_TICKET';
        $isHotel=$category==='HOTEL';
        $isStructural=$isAir||$isHotel;

        $rules=[
            'product_service_id'=>['required','integer','exists:products_services,id'],
            'description'=>[$isStructural?'nullable':'required','string','max:500'],
            'notes'=>['nullable','string','max:2000'],
            'passenger_ids'=>['nullable','array'],
            'passenger_ids.*'=>['integer'],
        ];
        if(!$isStructural){
            $rules += [
                'service_from'=>['nullable','date'],
                'service_to'=>['nullable','date','after_or_equal:service_from'],
                'quantity'=>['nullable','numeric','gt:0','max:99999'],
                'unit_price'=>['required','numeric','min:0','max:999999999999.99'],
            ];
        }
        $data=$request->validate($rules);

        $ids=array_values(array_unique(array_map('intval',(array)($data['passenger_ids']??[]))));

        // Hotel stays inherit all active Booking Passengers automatically.
        // Staff enters passengers once at Booking level and never links them again per Hotel.
        if($isHotel){
            $ids=BookingPassenger::query()
                ->where('booking_id',$booking->id)
                ->where('is_active',true)
                ->pluck('id')
                ->map(fn($v)=>(int)$v)
                ->all();
        }elseif($product->passenger_link_mode==='NONE'){
            $ids=[];
        }

        if($ids){
            $count=BookingPassenger::query()->where('booking_id',$booking->id)->where('is_active',true)->whereIn('id',$ids)->count();
            if($count!==count($ids))throw ValidationException::withMessages(['passenger_ids'=>'One or more selected passengers do not belong to this active booking.']);
        }
        if(in_array($product->passenger_link_mode,['REQUIRED','MULTIPLE'],true)&&!$ids){
            throw ValidationException::withMessages(['passenger_ids'=>'This service requires at least one passenger to be linked.']);
        }

        if($isAir){
            // Air Ticket is a structural service container. Passenger-level ticket rows own dates, sale and purchase pricing.
            $data['description']=trim((string)($data['description']??'')) ?: $product->name;
            $data['service_from']=null;
            $data['service_to']=null;
            $data['quantity']=max(1,count($ids));
            $data['unit_price']=0;
        }elseif($isHotel){
            // Hotel is also structural. Dates, rooms, pricing basis, sale and supplier cost are owned by Hotel Workspace.
            $data['description']=trim((string)($data['description']??'')) ?: $product->name;
            $data['service_from']=null;
            $data['service_to']=null;
            $data['quantity']=1;
            $data['unit_price']=0;
        }elseif($product->pricing_basis==='PER_PERSON'){
            if(!$ids)throw ValidationException::withMessages(['passenger_ids'=>'PER_PERSON pricing requires at least one linked passenger.']);
            $data['quantity']=count($ids);
        }else{
            $data['quantity']=$data['quantity']??1;
        }

        unset($data['passenger_ids']);
        $data['currency_code']=$booking->currency_code;
        return [$data,$product,$ids];
    }

    private function lineTotal(array $data, ?ProductService $product=null): float
    {
        if($product && in_array(strtoupper((string)$product->category),['AIR_TICKET','HOTEL'],true))return 0.0;
        return round((float)$data['quantity']*(float)$data['unit_price'],2);
    }

}
