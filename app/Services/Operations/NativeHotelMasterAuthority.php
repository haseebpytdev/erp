<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class NativeHotelMasterAuthority
{
    public function tables(): array
    {
        return ['city' => $this->discover(['cities','travel_cities','city_master','city_masters','travel_city_master'], ['name','city_name','title'], 'city', ['id','city_id']), 'hotel' => $this->discover(['hotels','travel_hotels','hotel_master','hotel_masters','travel_hotel_master'], ['name','hotel_name','title','property_name'], 'hotel', ['id','hotel_id'])];
    }

    private function discover(array $candidates, array $nameFields, string $needle, array $idFields): ?string
    {
        foreach (array_unique($candidates) as $table) {
            try { if (! Schema::hasTable($table)) continue; $columns = Schema::getColumnListing($table); if (array_intersect($idFields, $columns) && array_intersect($columns, $nameFields)) return $table; } catch (\Throwable) {}
        }
        try { foreach ((array) Schema::getTables() as $entry) { $table=is_string($entry)?$entry:(string)($entry['name']??$entry['table_name']??''); if ($table===''||str_starts_with(strtolower($table),'booking'.'_')||!str_contains(strtolower($table),$needle)) continue; $columns=Schema::getColumnListing($table); if (array_intersect($columns,$nameFields) && array_intersect($columns,$idFields)) return $table; } } catch (\Throwable) {}
        return null;
    }

    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!$lines || count($lines) < 2) throw ValidationException::withMessages(['csv' => 'CSV must include a header and at least one data row.']);
        $header = array_map(fn($v) => strtolower(trim((string)$v)), str_getcsv((string)array_shift($lines)));
        if ($header !== ['city','hotel name'] && $header !== ['city','hotel_name']) throw ValidationException::withMessages(['csv' => 'CSV headers must be exactly City,Hotel Name.']);
        $lines=array_values(array_filter($lines,fn($line)=>trim((string)$line)!=='')); if (count($lines) > 1000) throw ValidationException::withMessages(['csv' => 'A maximum of 1000 data rows is allowed per import.']);
        $rows=[]; foreach ($lines as $i=>$line) { $v=str_getcsv($line); if(count($v)!==2) {$rows[]=['row'=>$i+2,'city'=>'','hotel_name'=>'','status'=>'INVALID','reason'=>'Malformed CSV row.']; continue;} $rows[]=['row'=>$i+2,'city'=>trim($v[0]),'hotel_name'=>trim($v[1])]; }
        return $rows;
    }

public function preview(array $rows, ?int $companyId = null): array
{
    $tables = $this->tables();

    if (! $tables['hotel']) {
        throw ValidationException::withMessages([
            'hotel' => 'Native Hotel Master could not be resolved.',
        ]);
    }

    $hotelColumns = Schema::getColumnListing(
        $tables['hotel']
    );

    $unresolved = $this->requiredUnresolvedColumns(
        $tables['hotel'],
        $this->deterministicFields($hotelColumns, $companyId)
    );

    $hotelUsesCityFk = $this->hotelUsesCityFk(
        $hotelColumns
    );

    $cities = $this->cityRows(
        $tables['city']
    );

    $hotels = $this->hotelRows(
        $tables['hotel']
    );

    if ($unresolved) {
        $invalidRows = array_map(
            fn ($row) => $row + [
                'status' => 'INVALID',
                'reason' => 'Required columns unresolved: '
                    .implode(', ', $unresolved),
                'required_unresolved_columns' => $unresolved,
            ],
            $rows
        );

        return [
            'rows' => $invalidRows,
            'summary' => [
                'total' => count($invalidRows),
                'new' => 0,
                'existing' => 0,
                'alias_matches' => 0,
                'invalid' => count($invalidRows),
                'required_unresolved_columns' => $unresolved,
            ],
        ];
    }

    $result = [];
    $seen = [];

    $nextCode = $this->nextCode(
        $tables['hotel'],
        $hotels
    );

    foreach ($rows as $row) {
        $city = $this->cityMatch(
            (string) ($row['city'] ?? ''),
            $cities
        );

        $hotelName = trim(
            (string) ($row['hotel_name'] ?? '')
        );

        if (! $city || $hotelName === '') {
            $row['status'] = 'INVALID';

            $row['reason'] = $city
                ? 'Hotel Name is required.'
                : 'City could not be resolved safely.';

            $result[] = $row;
            continue;
        }

        $row['resolved_city'] = (string) (
            $city['name'] ?? ''
        );

        $row['city_id'] = (int) (
            $city['id'] ?? 0
        );

        if (
            $hotelUsesCityFk
            && $row['city_id'] <= 0
        ) {
            $row['status'] = 'INVALID';

            $row['reason'] =
                'City could not be resolved to a native City ID.';

            $result[] = $row;
            continue;
        }

        $identity = $this->hotelIdentity(
            $hotelColumns,
            $city,
            $hotelName
        );

        $existing = $hotels[$identity] ?? null;

        $aliasName = $this->alias(
            $hotelName
        );

        $aliasIdentity = $aliasName
            ? $this->hotelIdentity(
                $hotelColumns,
                $city,
                $aliasName
            )
            : null;

        if (isset($seen[$identity])) {
            $row['status'] = 'EXISTING';

            $row['reason'] =
                'Duplicate within this CSV batch.';
        } elseif ($existing) {
            $row['status'] = 'EXISTING';

            $row['reason'] =
                'Same city and hotel already exists.';
        } elseif (
            $aliasIdentity
            && (
                isset($hotels[$aliasIdentity])
                || isset($seen[$aliasIdentity])
            )
        ) {
            $row['status'] = 'ALIAS_MATCH';

            $row['reason'] =
                'Known hotel alias matches existing property.';
        } else {
            $row['status'] = 'NEW';
            $row['reason'] = 'Ready to import.';
            $row['generated_code'] = $nextCode;

            $seen[$identity] = true;

            $hotels[$identity] = [
                'code' => $nextCode,
            ];

            $nextCode = $this->incrementCode(
                $nextCode
            );
        }

        if (empty($row['generated_code'])) {
            $row['generated_code'] = $this->nextCode(
                $tables['hotel'],
                $hotels
            );
        }

        $result[] = $row;
    }

    return [
        'rows' => $result,
        'summary' => [
            'total' => count($result),

            'new' => count(array_filter(
                $result,
                fn ($row) =>
                    ($row['status'] ?? '') === 'NEW'
            )),

            'existing' => count(array_filter(
                $result,
                fn ($row) =>
                    ($row['status'] ?? '') === 'EXISTING'
            )),

            'alias_matches' => count(array_filter(
                $result,
                fn ($row) =>
                    ($row['status'] ?? '') === 'ALIAS_MATCH'
            )),

            'invalid' => count(array_filter(
                $result,
                fn ($row) =>
                    ($row['status'] ?? '') === 'INVALID'
            )),

            'cities_resolved' => count(array_filter(
                $result,
                fn ($row) =>
                    ! empty($row['resolved_city'])
            )),
        ],
    ];
}

public function import(array $rows, ?int $companyId = null): array
{
    $preview = $this->preview($rows, $companyId);

    $tables = $this->tables();

    if (! $tables['hotel']) {
        throw ValidationException::withMessages([
            'hotel' => 'Native Hotel Master could not be resolved.',
        ]);
    }

    $columns = Schema::getColumnListing(
        $tables['hotel']
    );

    if (in_array('company_id', $columns, true) && (int) $companyId <= 0) {
        throw ValidationException::withMessages([
            'hotel' => 'Required company context could not be resolved safely.',
        ]);
    }

    $unresolved = $this->requiredUnresolvedColumns(
        $tables['hotel'],
        $this->deterministicFields($columns, $companyId)
    );

    if ($unresolved) {
        throw ValidationException::withMessages([
            'hotel' => 'Required columns unresolved: '
                .implode(', ', $unresolved),
        ]);
    }

    $hotelUsesCityFk = $this->hotelUsesCityFk(
        $columns
    );

    $created = 0;

    DB::transaction(function () use (
        &$created,
        $preview,
        $tables,
        $columns,
        $hotelUsesCityFk,
        $companyId
    ) {
        $hotels = $this->hotelRows(
            $tables['hotel']
        );

        $usedCodes = [];

        foreach ($hotels as $hotel) {
            $usedCodes[] = (string) (
                $hotel['code']
                ?? $hotel['hotel_code']
                ?? $hotel['property_code']
                ?? ''
            );
        }

        foreach ($preview['rows'] as $previewRow) {
            if (
                ($previewRow['status'] ?? '')
                !== 'NEW'
            ) {
                continue;
            }

            $resolvedCity = [
                'id' => (int) (
                    $previewRow['city_id'] ?? 0
                ),
                'name' => (string) (
                    $previewRow['resolved_city'] ?? ''
                ),
            ];

            if (
                $hotelUsesCityFk
                && $resolvedCity['id'] <= 0
            ) {
                throw ValidationException::withMessages([
                    'hotel' =>
                        'Hotel import requires a resolved native City ID.',
                ]);
            }

            $identity = $this->hotelIdentity(
                $columns,
                $resolvedCity,
                (string) $previewRow['hotel_name']
            );

            if (isset($hotels[$identity])) {
                continue;
            }

            $code = $this->nextUniqueCode(
                $usedCodes
            );

            $row = [];

            if (in_array('company_id', $columns, true)) {
                $row['company_id'] = (int) $companyId;
            }

            $this->put(
                $row,
                $columns,
                [
                    'name',
                    'hotel_name',
                    'title',
                    'property_name',
                ],
                $previewRow['hotel_name']
            );

            $this->put(
                $row,
                $columns,
                [
                    'city_id',
                    'travel_city_id',
                ],
                $resolvedCity['id']
            );

            $this->put(
                $row,
                $columns,
                [
                    'city',
                    'city_name',
                    'location',
                ],
                $resolvedCity['name']
            );

            $this->put(
                $row,
                $columns,
                [
                    'city_iata',
                    'iata',
                    'iata_code',
                    'city_code',
                ],
                $this->cityCode(
                    $resolvedCity['name']
                )
            );

            $this->put(
                $row,
                $columns,
                [
                    'country',
                    'country_code',
                    'country_iso',
                ],
                'SA'
            );

            $this->put(
                $row,
                $columns,
                [
                    'code',
                    'hotel_code',
                    'property_code',
                ],
                $code
            );

            $this->put(
                $row,
                $columns,
                [
                    'is_active',
                    'active',
                ],
                1
            );

            $now = now();

            if (
                in_array(
                    'created_at',
                    $columns,
                    true
                )
            ) {
                $row['created_at'] = $now;
            }

            if (
                in_array(
                    'updated_at',
                    $columns,
                    true
                )
            ) {
                $row['updated_at'] = $now;
            }

            $row = array_intersect_key(
                $row,
                array_flip($columns)
            );

            if (
                ! $row
                || ! $this->hasRequiredStorage(
                    $columns
                )
            ) {
                throw ValidationException::withMessages([
                    'hotel' =>
                        'Native Hotel fields could not be resolved safely.',
                ]);
            }

            DB::table(
                $tables['hotel']
            )->insert($row);

            $hotels[$identity] = [
                'code' => $code,
            ];

            $usedCodes[] = $code;
            $created++;
        }
    });

    return [
        'import_complete' => true,
        'rows_submitted' => count($rows),
        'rows_created' => $created,

        'rows_skipped_existing' =>
            $preview['summary']['existing'],

        'rows_skipped_alias' =>
            $preview['summary']['alias_matches'],

        'rows_invalid' =>
            $preview['summary']['invalid'],

        'city_rows_created' => 0,
        'hotel_rows_created' => $created,
    ];
}
    private function put(array &$row,array $columns,array $keys,mixed $value):void{foreach($keys as $k)if(in_array($k,$columns,true)){$row[$k]=$value;return;}}
    private function cityRows(?string $table):array{if(!$table)return [];return DB::table($table)->get()->map(fn($r)=>(array)$r)->map(fn($r)=>['id'=>(int)($r['id']??0),'name'=>(string)($r['name']??$r['city_name']??$r['title']??'')])->filter(fn($r)=>$r['id']>0&&$r['name']!=='')->values()->all();}
    private function hotelUsesCityFk(array $columns): bool { return in_array('city_id',$columns,true)||in_array('travel_city_id',$columns,true); }
    private function hotelIdentity(array $hotelColumns,array $city,string $hotelName): string { $cityId=$this->hotelUsesCityFk($hotelColumns)?(int)($city['id']??0):0; return $this->identityFromValues($cityId,(string)($city['name']??''),$hotelName); }
    private function hotelRows(?string $table): array
    {
        if (! $table) return [];
        $columns=Schema::getColumnListing($table); $cityFk=in_array('city_id',$columns,true)?'city_id':(in_array('travel_city_id',$columns,true)?'travel_city_id':null); $cityText=in_array('city',$columns,true)?'city':(in_array('city_name',$columns,true)?'city_name':(in_array('location',$columns,true)?'location':null)); $nameColumn=array_values(array_intersect($columns,['name','hotel_name','title','property_name']))[0]??null; if((!$cityFk&&!$cityText)||!$nameColumn)return [];
        $hotels=[]; foreach(DB::table($table)->get() as $record){$row=(array)$record;$cityId=$cityFk?(int)($row[$cityFk]??0):0;$cityName=$cityText?(string)($row[$cityText]??''):'';$identity=$this->identityFromValues($cityId,$cityName,(string)($row[$nameColumn]??''));$hotels[$identity]=$row;} return $hotels;
    }
    private function textKey(string $city,string $name):string{return $this->identityFromValues(0,$city,$name);}
    private function hasRequiredStorage(array $columns):bool{return (bool)array_intersect($columns,['name','hotel_name','title','property_name']) && (bool)array_intersect($columns,['city_id','travel_city_id','city','city_name','location']);}
    private function deterministicFields(array $columns, ?int $companyId): array { return in_array('company_id', $columns, true) && (int) $companyId > 0 ? ['company_id'] : []; }
    public function requiredUnresolvedColumns(string $table, array $deterministicFields = []):array{try{$columns=Schema::getColumns($table);$un=[];foreach($columns as $c){$name=(string)($c['name']??'');if(($c['nullable']??true)===false&&($c['default']??null)===null&&!($c['auto_increment']??false)&&!($c['generated']??false)&&!in_array($name,['created_at','updated_at','name','hotel_name','title','property_name','city_id','travel_city_id','city','city_name','location','code','hotel_code','property_code','city_iata','iata','iata_code','city_code','country','country_code','country_iso','is_active','active'],true)&&!in_array($name,$deterministicFields,true))$un[]=$name;}return $un;}catch(\Throwable){return ['__METADATA_UNAVAILABLE__'];}}
    private function cityMatch(string $input,array $cities):?array{$n=$this->norm($input);$aliases=['makkah'=>['makkah','mecca','makkah al mukarramah'],'madinah'=>['madinah','madina','medina','al madinah']];foreach($cities as $c)if($this->norm($c['name'])===$n)return $c;foreach($aliases as $canonical=>$vals)if(in_array($n,$vals,true)){foreach($cities as $c)if(in_array($this->norm($c['name']),$vals,true))return $c;return ['id'=>0,'name'=>ucfirst($canonical)];}return null;}
    private function norm(string $v):string{return strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ',preg_replace('/\s+/',' ', $v))??''));}
    public function canonicalHotelName(string $name):string{$n=$this->norm($name);$m=['badar al masa'=>'al massa bader hotel','badar al massa'=>'al massa bader hotel','bader al massa'=>'al massa bader hotel','al massa bader'=>'al massa bader hotel','al massa bader hotel'=>'al massa bader hotel','mather al jewar'=>'mather al jiwar','mather al jawar'=>'mather al jiwar','mather al jiwar'=>'mather al jiwar','al kiswah tower'=>'al kiswah towers hotel','al kiswah towers'=>'al kiswah towers hotel','al kiswah towers hotel'=>'al kiswah towers hotel','voco'=>'voco makkah','voco makkah'=>'voco makkah','diyar safa'=>'diyar al safa','safa tower'=>'diyar al safa','diyar al safa'=>'diyar al safa','saja al madinah'=>'saja by warwick madinah hotel','saja by warwick madinah hotel'=>'saja by warwick madinah hotel','golden luxury'=>'rua luxury','rua luxury'=>'rua luxury'];return $m[$n]??$n;}
    private function canonicalCityFamily(string $city):string{$n=$this->norm($city);if(in_array($n,['makkah','mecca','makkah al mukarramah'],true))return 'makkah';if(in_array($n,['madinah','madina','medina','al madinah'],true))return 'madinah';return $n;}
    private function identityFromValues(int $cityId,string $cityName,string $hotelName):string{return $cityId>0?'FK:'.$cityId.'|'.$this->canonicalHotelName($hotelName):'TEXT:'.$this->canonicalCityFamily($cityName).'|'.$this->canonicalHotelName($hotelName);}
    private function identity(array $city,string $name):string{return $this->identityFromValues((int)($city['id']??0),(string)($city['name']??''),$name);}
    private function key(int $city,string $name):string{return $this->identityFromValues($city,'',$name);}
    private function alias(string $name):?string{$m=['badar al masa'=>'Al Massa Bader Hotel','badar al massa'=>'Al Massa Bader Hotel','bader al massa'=>'Al Massa Bader Hotel','al massa bader'=>'Al Massa Bader Hotel','mather al jewar'=>'Mather Al Jiwar','mather al jawar'=>'Mather Al Jiwar','mather al jiwar'=>'Mather Al Jiwar','al kiswah tower'=>'Al Kiswah Towers Hotel','al kiswah towers'=>'Al Kiswah Towers Hotel','voco'=>'voco Makkah','voco makkah'=>'voco Makkah','diyar safa'=>'Diyar Al Safa','safa tower'=>'Diyar Al Safa','saja al madinah'=>'Saja by Warwick Madinah Hotel','golden luxury'=>'Rua Luxury'];return $m[$this->norm($name)]??null;}
    private function cityCode(string $city):string{return $this->canonicalCityFamily($city)==='madinah'?'MED':'JED';}
    private function nextCode(string $table,array $hotels):string{$max=0;foreach($hotels as $r){$c=(string)($r['code']??$r['hotel_code']??$r['property_code']??'');if(preg_match('/HTL-(\d+)/i',$c,$m))$max=max($max,(int)$m[1]);}return 'HTL-'.str_pad((string)($max+1),4,'0',STR_PAD_LEFT);}
    private function nextUniqueCode(array $used): string { $max=0; foreach($used as $c) if(preg_match('/HTL-(\d+)/i',(string)$c,$m)) $max=max($max,(int)$m[1]); return 'HTL-'.str_pad((string)($max+1),4,'0',STR_PAD_LEFT); }
    private function incrementCode(string $code):string{if(!preg_match('/HTL-(\d+)/i',$code,$m))return 'HTL-0002';return 'HTL-'.str_pad((string)((int)$m[1]+1),4,'0',STR_PAD_LEFT);} private function decrementCode(string $code):string{if(!preg_match('/HTL-(\d+)/i',$code,$m))return 'HTL-0001';return 'HTL-'.str_pad((string)max(1,(int)$m[1]-1),4,'0',STR_PAD_LEFT);}
}
