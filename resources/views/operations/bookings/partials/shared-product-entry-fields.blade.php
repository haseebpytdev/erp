{{-- Shared product-entry fields. Persistence is selected by ProductWorkspaceContext. --}}
@php($v = $values ?? [])
@php($field = fn (string $name, string $label, string $type = 'text', bool $required = false) => '<label>'.e($label).'<input name="'.e($name).'" type="'.e($type).'" value="'.e((string)($v[$name] ?? '')).'"'.($required ? ' required' : '').'></label>')
<div class="et-grid et-grid-3" data-et-shared-product-fields="{{ $product }}">
  @if(in_array($product,['air','visa'],true))
    <label>Passenger<select name="booking_passenger_id" required><option value="">Select passenger</option>@foreach($passengers ?? [] as $p)<option value="{{ $p['id'] }}" @selected((string)($v['booking_passenger_id'] ?? '')===(string)$p['id'])>{{ $p['name'] }}</option>@endforeach</select></label>
  @endif
  <label>Vendor<select name="vendor_id"><option value="">No vendor</option>@foreach($vendors ?? [] as $vendor)<option value="{{ $vendor['id'] }}" @selected((string)($v['vendor_id'] ?? '')===(string)$vendor['id'])>{{ $vendor['name'] }}</option>@endforeach</select></label>
  @if($product==='air')
    <label>Airline master<select name="airline_id"><option value="">Manual / not selected</option>@foreach($airlines ?? [] as $airline)<option value="{{ $airline['id'] }}" @selected((string)($v['airline_id'] ?? '')===(string)$airline['id'])>{{ $airline['name'] }}{{ $airline['code'] ? ' · '.$airline['code'] : '' }}</option>@endforeach</select></label>
    {!! $field('airline_name','Airline') !!}{!! $field('from','From','text',true) !!}{!! $field('to','To','text',true) !!}{!! $field('departure_at','Departure','datetime-local',true) !!}{!! $field('arrival_at','Arrival','datetime-local') !!}{!! $field('flight_number','Flight') !!}{!! $field('pnr','PNR') !!}{!! $field('booking_class','Booking class') !!}{!! $field('baggage','Baggage') !!}{!! $field('sale_price','Sale','number',true) !!}{!! $field('cost_price','Cost','number') !!}
  @elseif($product==='hotel')
    {!! $field('city','City','text',true) !!}{!! $field('hotel_name','Hotel','text',true) !!}{!! $field('room_type','Room','text',true) !!}{!! $field('board','Board','text',true) !!}{!! $field('check_in','Check in','date',true) !!}{!! $field('check_out','Check out','date',true) !!}{!! $field('confirmation_no','Confirmation no') !!}{!! $field('sale_rate','Sale / night','number',true) !!}{!! $field('cost_rate','Cost / night','number') !!}
  @elseif($product==='transport')
    {!! $field('from_location','From','text',true) !!}{!! $field('to_location','To','text',true) !!}{!! $field('vehicle_type','Vehicle','text',true) !!}{!! $field('service_date','Service date','date') !!}{!! $field('company_name','Company') !!}{!! $field('driver_name','Driver') !!}{!! $field('contact_number','Contact') !!}{!! $field('plate_number','Plate') !!}{!! $field('brn_number','BRN') !!}{!! $field('sale_price','Sale','number',true) !!}{!! $field('cost_price','Cost','number') !!}
  @elseif($product==='visa')
    {!! $field('country','Country','text',true) !!}{!! $field('visa_type','Visa type','text',true) !!}{!! $field('provider_type','Provider type') !!}{!! $field('application_reference','Application reference') !!}{!! $field('sale_price','Sale','number',true) !!}{!! $field('cost_price','Cost','number') !!}
  @endif
</div>
