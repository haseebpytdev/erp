<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class NativeHotelMasterAuthority
{
    public function tables(): array
    {
        return ['city' => $this->discover(['cities','travel_cities','city_master','city_masters','travel_city_master'], ['name','city_name','title']), 'hotel' => $this->discover(['hotels','travel_hotels','hotel_master','hotel_masters','travel_hotel_master'], ['name','hotel_name','title','property_name'])];
    }

    private function discover(array $candidates, array $nameFields): ?string
    {
        foreach ($candidates as $table) {
            try { if (! Schema::hasTable($table)) continue; $columns = Schema::getColumnListing($table); if (in_array('id', $columns, true) && array_intersect($columns, $nameFields)) return $table; } catch (\Throwable) {}
        }
        return null;
    }

    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!$lines || count($lines) < 2) throw ValidationException::withMessages(['csv' => 'CSV must include a header and at least one data row.']);
        $header = array_map(fn($v) => strtolower(trim((string)$v)), str_getcsv((string)array_shift($lines)));
        if ($header !== ['city','hotel name'] && $header !== ['city','hotel_name']) throw ValidationException::withMessages(['csv' => 'CSV headers must be exactly City,Hotel Name.']);
        if (count($lines) > 1001) throw ValidationException::withMessages(['csv' => 'A maximum of 1000 data rows is allowed per import.']);
        $rows=[]; foreach ($lines as $i=>$line) { if (trim($line)==='') continue; $v=str_getcsv($line); if(count($v)!==2) {$rows[]=['row'=>$i+2,'city'=>'','hotel_name'=>'','status'=>'INVALID','reason'=>'Malformed CSV row.']; continue;} $rows[]=['row'=>$i+2,'city'=>trim($v[0]),'hotel_name'=>trim($v[1])]; }
        return $rows;
    }

    public function preview(array $rows): array
    {
        $tables=$this->tables(); if(!$tables['hotel']) throw ValidationException::withMessages(['hotel'=>'Native Hotel Master could not be resolved.']);
        $cities=$this->cityRows($tables['city']); $hotels=$this->hotelRows($tables['hotel']); $out=[]; $seen=[];
        foreach($rows as $row){$city=$this->cityMatch($row['city']??'', $cities); $name=trim((string)($row['hotel_name']??'')); if(!$city||$name===''){$row['status']='INVALID';$row['reason']=$city?'Hotel Name is required.':'City could not be resolved safely.';}else{$key=$this->key($city['id'],$name);$existing=$hotels[$key]??null;$alias=$this->alias($name);if(isset($seen[$key])){$row['status']='EXISTING';$row['reason']='Duplicate within this CSV batch.';}elseif($existing){$row['status']='EXISTING';$row['reason']='Same city and hotel already exists.';}elseif($alias && (($hotels[$this->key($city['id'],$alias)]??null)||isset($seen[$this->key($city['id'],$alias)]))){$row['status']='ALIAS_MATCH';$row['reason']='Known hotel alias matches existing property.';}else{$row['status']='NEW';$row['reason']='Ready to import.';$seen[$key]=true;}$row['resolved_city']=$city['name'];$row['city_id']=$city['id'];$row['generated_code']=$this->nextCode($tables['hotel'],$hotels);}$out[]=$row;}
        return ['rows'=>$out,'summary'=>['total'=>count($out),'new'=>count(array_filter($out,fn($r)=>$r['status']==='NEW')),'existing'=>count(array_filter($out,fn($r)=>$r['status']==='EXISTING')),'alias_matches'=>count(array_filter($out,fn($r)=>$r['status']==='ALIAS_MATCH')),'invalid'=>count(array_filter($out,fn($r)=>$r['status']==='INVALID')),'cities_resolved'=>count(array_filter($out,fn($r)=>!empty($r['city_id'])) )]];
    }

    public function import(array $rows): array
    {
        $preview=$this->preview($rows); $tables=$this->tables(); $columns=Schema::getColumnListing($tables['hotel']); $cityColumns=$tables['city']?Schema::getColumnListing($tables['city']):[]; $created=0;
        DB::transaction(function() use (&$created,$preview,$tables,$columns,$cityColumns){$hotels=$this->hotelRows($tables['hotel']);$usedCodes=[];foreach($hotels as $h){$usedCodes[]=(string)($h['code']??$h['hotel_code']??$h['property_code']??'');}foreach($preview['rows'] as $r){if(($r['status']??'')!=='NEW')continue;$key=$this->key((int)$r['city_id'],(string)$r['hotel_name']);if(isset($hotels[$key]))continue;$code=$this->nextUniqueCode($usedCodes);$row=[];$this->put($row,$columns,['name','hotel_name','title','property_name'],$r['hotel_name']);$this->put($row,$columns,['city_id','travel_city_id'],$r['city_id']);$this->put($row,$columns,['city_iata','iata','iata_code','city_code'],$this->cityCode($r['resolved_city']));$this->put($row,$columns,['country','country_code','country_iso'],'SA');$this->put($row,$columns,['code','hotel_code','property_code'],$code);$this->put($row,$columns,['is_active','active'],1);$row=array_intersect_key($row,array_flip($columns));if(!$row)throw ValidationException::withMessages(['hotel'=>'Native Hotel fields could not be resolved.']);DB::table($tables['hotel'])->insert($row);$hotels[$key]=['code'=>$code];$usedCodes[]=$code;$created++;}});
        return ['import_complete'=>true,'rows_submitted'=>count($rows),'rows_created'=>$created,'rows_skipped_existing'=>$preview['summary']['existing'],'rows_skipped_alias'=>$preview['summary']['alias_matches'],'rows_invalid'=>$preview['summary']['invalid'],'city_rows_created'=>0,'hotel_rows_created'=>$created];
    }
    private function put(array &$row,array $columns,array $keys,mixed $value):void{foreach($keys as $k)if(in_array($k,$columns,true)){$row[$k]=$value;return;}}
    private function cityRows(?string $table):array{if(!$table)return [];return DB::table($table)->get()->map(fn($r)=>(array)$r)->map(fn($r)=>['id'=>(int)($r['id']??0),'name'=>(string)($r['name']??$r['city_name']??$r['title']??'')])->filter(fn($r)=>$r['id']>0&&$r['name']!=='')->values()->all();}
    private function hotelRows(?string $table):array{if(!$table)return []; $cols=Schema::getColumnListing($table);$city=in_array('city_id',$cols,true)?'city_id':(in_array('travel_city_id',$cols,true)?'travel_city_id':null);$name=array_values(array_intersect($cols,['name','hotel_name','title','property_name']))[0]??null;if(!$city||!$name)return []; $out=[];foreach(DB::table($table)->get() as $r){$a=(array)$r;$out[$this->key((int)$a[$city],(string)$a[$name])]=$a;}return $out;}
    private function cityMatch(string $input,array $cities):?array{$n=$this->norm($input);$aliases=['makkah'=>['makkah','mecca','makkah al mukarramah'],'madinah'=>['madinah','madina','medina','al madinah']];foreach($cities as $c)if($this->norm($c['name'])===$n)return $c;foreach($aliases as $canonical=>$vals)if(in_array($n,$vals,true))foreach($cities as $c)if(in_array($this->norm($c['name']),$vals,true))return $c;return null;}
    private function norm(string $v):string{return strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ',preg_replace('/\s+/',' ', $v))??''));}
    private function key(int $city,string $name):string{return $city.'|'.$this->norm($name);}
    private function alias(string $name):?string{$m=['badar al masa'=>'Al Massa Bader Hotel','badar al massa'=>'Al Massa Bader Hotel','bader al massa'=>'Al Massa Bader Hotel','al massa bader'=>'Al Massa Bader Hotel','mather al jewar'=>'Mather Al Jiwar','mather al jawar'=>'Mather Al Jiwar','mather al jiwar'=>'Mather Al Jiwar','al kiswah tower'=>'Al Kiswah Towers Hotel','al kiswah towers'=>'Al Kiswah Towers Hotel','voco'=>'voco Makkah','voco makkah'=>'voco Makkah','diyar safa'=>'Diyar Al Safa','safa tower'=>'Diyar Al Safa','saja al madinah'=>'Saja by Warwick Madinah Hotel','golden luxury'=>'Rua Luxury'];return $m[$this->norm($name)]??null;}
    private function cityCode(string $city):string{return $this->norm($city)==='madinah'?'MED':'JED';}
    private function nextCode(string $table,array $hotels):string{$max=0;foreach($hotels as $r){$c=(string)($r['code']??$r['hotel_code']??$r['property_code']??'');if(preg_match('/HTL-(\d+)/i',$c,$m))$max=max($max,(int)$m[1]);}return 'HTL-'.str_pad((string)($max+1),4,'0',STR_PAD_LEFT);}
    private function nextUniqueCode(array $used): string { $max=0; foreach($used as $c) if(preg_match('/HTL-(\d+)/i',(string)$c,$m)) $max=max($max,(int)$m[1]); return 'HTL-'.str_pad((string)($max+1),4,'0',STR_PAD_LEFT); }
}
