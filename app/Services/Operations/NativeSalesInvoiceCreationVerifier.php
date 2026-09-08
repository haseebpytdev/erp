<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class NativeSalesInvoiceCreationVerifier
{
    public function __construct(private readonly NativeSalesInvoiceInspector $invoices) {}

    public function verify(int $bookingId,int $customerId,float $expectedTotal,int $minimumLines):array
    {
        $summary=$this->invoices->summary($bookingId);
        if((int)$summary['count']!==1||(int)$summary['all_count']!==1) $this->fail('Native creation must leave exactly one Sales Invoice for the booking.');
        $invoice=(array)($summary['latest']??[]);
        $table=(string)($invoice['table']??'');$invoiceId=(int)($invoice['id']??0);
        if($table===''||$invoiceId<=0||!Schema::hasTable($table)) $this->fail('The native Sales Invoice could not be resolved after creation.');
        $columns=Schema::getColumnListing($table);$idColumn=$this->first($columns,['id','sales_invoice_id','invoice_id']);
        $row=$idColumn?DB::table($table)->where($idColumn,$invoiceId)->first():null;
        if(!$row)$this->fail('The created native Sales Invoice header could not be reloaded.');
        $data=(array)$row;
        $bookingColumn=$this->first($columns,['booking_id','travel_booking_id','source_booking_id']);
        if(!$bookingColumn||(int)($data[$bookingColumn]??0)!==$bookingId)$this->fail('The created Sales Invoice has an invalid booking link.');
        $customerColumn=$this->first($columns,['customer_id','party_id','client_id','customer_party_id','bill_to_party_id']);
        if(!$customerColumn||$customerId<=0||(int)($data[$customerColumn]??0)!==$customerId)$this->fail('The created Sales Invoice has an invalid customer link.');
        $statusColumn=$this->first($columns,['status','invoice_status','document_status']);
        if(!$statusColumn||strtolower(trim((string)($data[$statusColumn]??'')))!=='draft')$this->fail('The created Sales Invoice did not start in Draft status.');
        $numberColumn=$this->first($columns,['invoice_number','invoice_no','invoice_reference','reference_no','reference','number','document_no']);
        if(!$numberColumn||trim((string)($data[$numberColumn]??''))==='')$this->fail('The native Sales Invoice number was not assigned.');
        $headerAmountColumn=$this->first($columns,['grand_total','total_amount','net_total','total','amount','invoice_total']);
        if(!$headerAmountColumn||!$this->same((float)($data[$headerAmountColumn]??0),$expectedTotal))$this->fail('The Sales Invoice header total does not match the authoritative booking customer total.');
        [$lineCount,$lineTotal]=$this->lineSummary($table,$invoiceId);
        if($lineCount<max(1,$minimumLines))$this->fail('The native Sales Invoice did not create all required product lines.');
        if(!$this->same($lineTotal,$expectedTotal))$this->fail('The Sales Invoice line total does not match the authoritative booking customer total.');
        return ['invoice'=>$invoice,'line_count'=>$lineCount,'line_total'=>round($lineTotal,2),'expected_total'=>round($expectedTotal,2)];
    }

    private function lineSummary(string $headerTable,int $invoiceId):array
    {
        $tables=['sales_invoice_items','sales_invoice_lines','invoice_items','invoice_lines','sales_invoice_details'];
        try{foreach(Schema::getTables() as $meta){$name=is_array($meta)?(string)($meta['name']??$meta['table_name']??''):'';if($name!==''&&str_contains(strtolower($name),'invoice')&&(str_contains(strtolower($name),'line')||str_contains(strtolower($name),'item')||str_contains(strtolower($name),'detail')))$tables[]=$name;}}catch(\Throwable){}
        foreach(array_unique($tables) as $table){
            $lower=strtolower($table);if(str_contains($lower,'vendor')||str_contains($lower,'purchase'))continue;
            if(!Schema::hasTable($table)||$table===$headerTable)continue;$columns=Schema::getColumnListing($table);
            $foreign=$this->first($columns,['sales_invoice_id','invoice_id','header_id']);
            if(!$foreign)continue;
            $rows=DB::table($table)->where($foreign,$invoiceId)->get();
            if($rows->isEmpty())continue;
            $amount=$this->first($columns,['line_total','net_amount','total_amount','amount','total']);
            $quantity=$this->first($columns,['quantity','qty']);
            $rate=$this->first($columns,['unit_price','unit_rate','rate','price']);
            if(!$amount&&!($quantity&&$rate))continue;
            $total=0.0;
            foreach($rows as $row){$data=(array)$row;$total+=$amount?(float)($data[$amount]??0):(float)($data[$quantity]??0)*(float)($data[$rate]??0);}
            return [$rows->count(),$total];
        }
        return [0,0.0];
    }

    private function first(array $columns,array $candidates):?string{foreach($candidates as $candidate)if(in_array($candidate,$columns,true))return $candidate;return null;}
    private function same(float $left,float $right):bool{return abs(round($left,2)-round($right,2))<0.01;}
    private function fail(string $message):never{throw ValidationException::withMessages(['invoice'=>$message]);}
}
