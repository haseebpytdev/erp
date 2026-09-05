<?php

namespace App\Services\Operations;

/**
 * One commercial-completeness authority for product saves, Booking Review and
 * approval. Operational issuance (tickets/Visa status) intentionally lives in
 * BookingTravelReadinessResolver instead.
 */
final class BookingCommercialCompletenessResolver
{
    /** @return array{items:array<string,array{label:string,complete:bool,reasons:list<string>}>,passed_count:int,applicable_count:int,complete:bool,reasons:list<string>} */
    public function resolve(array $selected, array $air, array $hotel, array $transport, array $visa): array
    {
        $items=[];$passengers=(array)($air['passengers']??$visa['passengers']??[]);
        $items['passengers']=$this->item('Passengers',$passengers?[]:['Add at least one booking passenger.']);
        if(in_array('air',$selected,true))$items['air']=$this->item('Air',$this->airReasons($air));
        if(in_array('hotel',$selected,true))$items['hotel']=$this->item('Hotel',$this->hotelReasons((array)($hotel['stays']??[]),$hotel));
        if(in_array('transport',$selected,true))$items['transport']=$this->item('Transport',$this->transportReasons((array)($transport['transports']??[]),$transport));
        if(in_array('visa',$selected,true))$items['visa']=$this->item('Visa Commercial',$this->visaReasons((array)($visa['visa_rows']??[]),$visa));
        $passed=count(array_filter($items,fn(array $item):bool=>$item['complete']));
        $reasons=[];foreach($items as $item)foreach($item['reasons'] as $reason)$reasons[]=$item['label'].': '.$reason;
        return ['items'=>$items,'passed_count'=>$passed,'applicable_count'=>count($items),'complete'=>$passed===count($items),'reasons'=>$reasons];
    }

    /** @return list<string> */
    public function airVendorErrors(array $common,array $commercials): array
    {
        $cost=0.0;foreach($commercials as $row)$cost+=max(0,(float)($row['cost_price']??$row['supplier_total']??$row['vendor_cost']??0));
        if($cost>0.00001&&$this->vendorMissing($common,['supplier_id','vendor_id'],['supplier_name','vendor_name']))return ['Select Vendor / Supplier before saving Air commercial data.'];
        return [];
    }

    /** @return list<string> */
    public function hotelVendorErrors(array $stays): array
    {
        $errors=[];foreach(array_values($stays) as $index=>$row){$cost=max((float)($row['vendor_total']??0),(float)($row['cost_rate']??0));if($cost>0.00001&&$this->vendorMissing((array)$row,['vendor_id','supplier_id'],['vendor_name','supplier_name']))$errors[]='Hotel '.($index+1).': Vendor is required because a vendor cost has been entered.';}return $errors;
    }

    /** @return list<string> */
    public function transportVendorErrors(array $rows): array
    {
        $errors=[];foreach(array_values($rows) as $index=>$row){$row=(array)$row;$cost=max((float)($row['cost_amount']??0),(float)($row['cost_rate']??0));if($cost<=0.00001)continue;$company=$this->text($row,['company_name','transport_company_name']);$vendorMissing=$this->vendorMissing($row,['vendor_id','supplier_id'],['vendor_name','supplier_name']);if($company===''||$vendorMissing)$errors[]='Transport '.($index+1).': Transport Company with a resolved Vendor / Supplier is required because vendor cost has been entered.';}return $errors;
    }

    /** @return list<string> */
    public function visaVendorErrors(array $rows): array
    {
        $errors=[];foreach(array_values($rows) as $index=>$row){$row=(array)$row;$cost=max((float)($row['vendor_cost_pkr']??0),(float)($row['cost_rate']??0));if($cost<=0.00001)continue;$missing=[];if((int)($row['visa_rate_card_id']??$row['visa_rate_id']??0)<=0)$missing[]='Visa Rate';if($this->text($row,['saudi_company_name','saudi_company_name_snapshot'])===''&&(int)($row['saudi_company_id']??0)<=0)$missing[]='Saudi Company';if($this->text($row,['pakistani_iata_name','pakistani_iata_name_snapshot'])===''&&(int)($row['pakistani_iata_id']??0)<=0)$missing[]='Pakistani IATA';if($this->vendorMissing($row,['vendor_id','supplier_id'],['vendor_name','supplier_name']))$missing[]='ERP Vendor Account';if($missing)$errors[]='Visa passenger '.($index+1).': missing '.implode(', ',$missing).' in the Saudi Company → Pakistani IATA → ERP Vendor Account chain.';}return $errors;
    }

    private function airReasons(array $air): array
    {
        $reasons=[];$rows=(array)($air['itinerary']??[]);if(!$rows||!$this->rowsHave($rows,[['from'],['to'],['departure_at'],['flight_number','airline_code','airline']]))$reasons[]='Saved Air service / itinerary is incomplete.';
        $summary=(array)($air['summary']??[]);if((float)($summary['customer_total']??0)<=0)$reasons[]='Customer Sale is missing.';if((float)($summary['supplier_total']??$summary['vendor_total']??0)<=0)$reasons[]='Vendor Cost is missing.';
        $commercials=(array)($air['fare_commercials']??[]);if(!$commercials&&isset($summary['supplier_total']))$commercials=[['supplier_total'=>$summary['supplier_total']]];
        return array_values(array_unique(array_merge($reasons,$this->airVendorErrors((array)($air['common']??[]),$commercials))));
    }
    private function hotelReasons(array $rows,array $hotel):array{$r=[];if(!$rows||!$this->rowsHave($rows,[['hotel_name'],['city'],['check_in'],['check_out']]))$r[]='Saved Hotel stay data is incomplete.';$s=(array)($hotel['summary']??[]);if((float)($s['customer_total']??0)<=0)$r[]='Customer Sale is missing.';if((float)($s['vendor_total']??0)<=0)$r[]='Vendor Cost is missing.';return array_merge($r,$this->hotelVendorErrors($rows));}
    private function transportReasons(array $rows,array $transport):array{$r=[];if(!$rows||!$this->rowsHave($rows,[['company_name'],['route_name'],['vehicle_type']]))$r[]='Transport Company, Route or Vehicle is incomplete.';$s=(array)($transport['summary']??[]);if((float)($s['customer_total']??0)<=0)$r[]='Customer Sale is missing.';if((float)($s['vendor_total']??0)<=0)$r[]='Vendor Cost is missing.';return array_merge($r,$this->transportVendorErrors($rows));}
    private function visaReasons(array $rows,array $visa):array{$r=[];if(!$rows)$r[]='No Visa passenger rows are saved.';$s=(array)($visa['summary']??[]);if((float)($s['customer_total']??0)<=0)$r[]='Customer Sale is missing.';if((float)($s['vendor_total']??0)<=0)$r[]='Vendor Cost is missing.';return array_merge($r,$this->visaVendorErrors($rows));}
    private function item(string $label,array $reasons):array{return ['label'=>$label,'complete'=>$reasons===[],'reasons'=>array_values($reasons)];}
    private function vendorMissing(array $row,array $ids,array $names):bool{foreach($ids as $k)if((int)($row[$k]??0)>0)return false;return $this->text($row,$names)==='';}
    private function rowsHave(array $rows,array $groups):bool{foreach($rows as $row)foreach($groups as $keys)if($this->text((array)$row,$keys)==='')return false;return true;}
    private function text(array $row,array $keys):string{foreach($keys as $key){$v=trim((string)($row[$key]??''));if($v!=='')return $v;}return '';}
}
