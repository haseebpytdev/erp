<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Schema;

/** Read-only authority for the native Hotel operational stay store. */
final class GeneralBookingHotelNativeStoreResolver
{
    private const CANDIDATES = ['booking_hotel_stays','booking_hotels','hotel_stays','booking_hotel_details','booking_accommodations','hotel_booking_details'];

    public function resolve(): ?array
    {
        foreach (self::CANDIDATES as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table);
            if (! in_array('id', $columns, true)) continue;
            if (! $this->hasAny($columns, ['hotel_id','hotel_name','name','hotel_name_snapshot'])) continue;
            if (! $this->hasAny($columns, ['check_in','check_in_date','checkin_date']) || ! $this->hasAny($columns, ['check_out','check_out_date','checkout_date'])) continue;
            if (in_array('booking_id', $columns, true)) return ['table'=>$table,'ownership_mode'=>'direct_booking','booking_column'=>'booking_id','service_link_column'=>in_array('booking_service_id',$columns,true)?'booking_service_id':null,'id_column'=>'id','one_row_per_service'=>$this->uniqueServiceLink($table)];
            $link = $this->confirmedServiceForeignKey($table, $columns);
            if ($link) return ['table'=>$table,'ownership_mode'=>'service_link','booking_column'=>null,'service_link_column'=>$link,'id_column'=>'id','one_row_per_service'=>$this->uniqueServiceLink($table,$link)];
        }
        return null;
    }

    public function table(): ?string { return $this->resolve()['table'] ?? null; }

    private function hasAny(array $columns,array $names): bool { return (bool) array_intersect($names,$columns); }
    private function confirmedServiceForeignKey(string $table,array $columns): ?string
    { try { foreach ((array) Schema::getForeignKeys($table) as $fk) { $foreign=(string)($fk['foreign_table']??$fk['foreign_table_name']??''); if(strtolower($foreign)!=='booking_services')continue; foreach((array)($fk['columns']??$fk['local_columns']??[]) as $column)if(in_array($column,$columns,true))return$column; } } catch(\Throwable){} return null; }
    private function uniqueServiceLink(string $table,string $link='booking_service_id'): bool
    { try { foreach((array)Schema::getIndexes($table) as $index){$columns=(array)($index['columns']??[]);if(!empty($index['unique'])&&count($columns)===1&&$columns[0]===$link)return true;} } catch(\Throwable){} return false; }
}
