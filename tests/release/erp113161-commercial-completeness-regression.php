<?php

require_once __DIR__.'/../../app/Services/Operations/BookingCommercialCompletenessResolver.php';
require_once __DIR__.'/../../app/Services/Operations/BookingTravelReadinessResolver.php';

use App\Services\Operations\BookingCommercialCompletenessResolver;
use App\Services\Operations\BookingTravelReadinessResolver;

$resolver=new BookingCommercialCompletenessResolver();$checks=0;
$assert=static function(bool $condition,string $message)use(&$checks):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}$checks++;};
$airBase=['passengers'=>[['id'=>1,'fare_type'=>'ADULT']],'itinerary'=>[['from'=>'LHE','to'=>'JED','departure_at'=>'2026-09-20','flight_number'=>'SV1']],'common'=>['supplier_id'=>0,'supplier_name'=>''],'fare_commercials'=>[['sale_price'=>100,'cost_price'=>80]],'summary'=>['customer_total'=>100,'supplier_total'=>80]];
$hotelBase=['stays'=>[['hotel_name'=>'Badar Al Masa','city'=>'Makkah','check_in'=>'2026-09-20','check_out'=>'2026-09-22','sale_rate'=>100,'cost_rate'=>80,'vendor_total'=>160,'vendor_id'=>0,'vendor_name'=>'']], 'summary'=>['customer_total'=>200,'vendor_total'=>160]];
$transportBase=['transports'=>[['company_name'=>'Transport Co','route_name'=>'JED → Makkah','vehicle_type'=>'Bus','sale_amount'=>100,'cost_amount'=>80,'cost_rate'=>80,'vendor_id'=>9,'vendor_name'=>'Transport Co']], 'summary'=>['customer_total'=>100,'vendor_total'=>80]];
$visaBase=['passengers'=>[['id'=>1]],'visa_rows'=>[['visa_rate_card_id'=>3,'saudi_company_id'=>4,'saudi_company_name'=>'Saudi Co','pakistani_iata_id'=>5,'pakistani_iata_name'=>'IATA','vendor_id'=>6,'vendor_name'=>'Vendor','sale_pkr'=>100,'vendor_cost_pkr'=>80,'status'=>'pending']], 'summary'=>['customer_total'=>100,'vendor_total'=>80]];

$assert($resolver->airVendorErrors($airBase['common'],$airBase['fare_commercials'])!==[],'Air cost with blank Vendor is blocked');
$airReady=$airBase;$airReady['common']=['supplier_id'=>7,'supplier_name'=>'Air Vendor'];
$assert($resolver->airVendorErrors($airReady['common'],$airReady['fare_commercials'])===[],'Air cost with Vendor is accepted');
$state=$resolver->resolve(['air'],$airBase,[],[],[]);$assert(!$state['items']['air']['complete'],'legacy Air cost without Vendor loads as incomplete');

$assert(count($resolver->hotelVendorErrors($hotelBase['stays']))===1,'Hotel cost with blank Vendor is blocked');
$two=$hotelBase['stays'];$two[]=$hotelBase['stays'][0];$assert(count($resolver->hotelVendorErrors($two))===2,'all missing Hotel row Vendors are returned together');
$hotelReady=$hotelBase;$hotelReady['stays'][0]['vendor_id']=8;$hotelReady['stays'][0]['vendor_name']='Hotel Vendor';$assert($resolver->hotelVendorErrors($hotelReady['stays'])===[],'Hotel cost with Vendor is accepted');

$transportBroken=$transportBase;$transportBroken['transports'][0]['vendor_id']=0;$transportBroken['transports'][0]['vendor_name']='';
$assert($resolver->transportVendorErrors($transportBroken['transports'])!==[],'Transport cost without resolved Vendor is incomplete');
$assert($resolver->transportVendorErrors($transportBase['transports'])===[],'Transport with Company and Vendor is complete');

$visaBroken=$visaBase;$visaBroken['visa_rows'][0]['vendor_id']=0;$visaBroken['visa_rows'][0]['vendor_name']='';
$assert($resolver->visaVendorErrors($visaBroken['visa_rows'])!==[],'Visa cost with broken Vendor chain is incomplete');
$assert($resolver->visaVendorErrors($visaBase['visa_rows'])===[],'valid Visa relationship chain is accepted');
$visaState=$resolver->resolve(['visa'],[],[],[],$visaBase);$assert($visaState['complete'],'Visa Pending remains commercially complete');

$three=$resolver->resolve(['air','hotel','transport','visa'],$airBase,$hotelBase,$transportBase,$visaBase);
$assert($three['passed_count']===3&&$three['applicable_count']===5&&!$three['complete'],'legacy missing Air/Hotel Vendors produce 3 of 5 incomplete');
$five=$resolver->resolve(['air','hotel','transport','visa'],$airReady,$hotelReady,$transportBase,$visaBase);
$assert($five['passed_count']===5&&$five['complete'],'all five commercial checks pass after Vendor correction');
$assert($three['reasons']!==[],'server approval gate receives actionable commercial reasons');
$assert($five['reasons']===[],'complete booking has no approval blockers');

$travel=new BookingTravelReadinessResolver();$travelState=$travel->resolve(['approval_status'=>'approved'],['air'],$airReady+['tickets'=>[['ticket_status'=>'PENDING','ticket_number'=>'']],'common'=>['pnr'=>'ABC','supplier_id'=>7]],[],[],[]);
$assert($travelState['status']==='PendingTravel','commercially complete Air with Pending ticket remains PendingTravel');
echo "ERP-11.3.161 commercial completeness regression checks passed: {$checks}\n";
