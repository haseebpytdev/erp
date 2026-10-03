<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only preflight for a future append-only native materialization.
 * C55 deliberately has no persistence authority; C56 will execute the
 * returned plan in one transaction after rechecking every authority.
 */
final class GeneralBookingAdditionalServiceMaterializationPlanner
{
    private const FOUNDATION = [
        'bookings', 'general_booking_billing_batches',
        'general_booking_billing_batch_items', 'general_booking_invoice_links',
    ];

    private const HOTEL_TABLES = [
        'booking_hotel_stays', 'booking_hotels', 'hotel_stays',
        'booking_hotel_details', 'booking_accommodations', 'hotel_booking_details',
    ];

    public function __construct(
        private readonly GeneralBookingAdditionalServiceSnapshotIntegrity $integrity,
        private readonly NativeProductServiceResolver $products,
    ) {}

    /** Return a deterministic, read-only append-only plan; never persists anything. */
    public function plan(int $bookingId, int $batchId): array
    {
        $base = [
            'ready' => false, 'code' => null, 'message' => null,
            'booking_id' => $bookingId, 'batch_id' => $batchId, 'batch_no' => null,
            'batch_status' => null, 'freeze_hash' => null, 'item_count' => 0,
            'materialized_count' => 0, 'pending_count' => 0, 'product_groups' => [],
            'items' => [], 'blockers' => [], 'atomic' => true,
            'materialization_state' => 'blocked', 'would_reset_travel_ready' => false,
        ];
        foreach (self::FOUNDATION as $table) if (! Schema::hasTable($table)) {
            return $this->blocked($base, 'schema_not_ready', 'Supplementary materialization foundation is unavailable.', ['missing_table:'.$table]);
        }
        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        if (! $booking) return $this->blocked($base, 'booking_missing', 'Booking was not found.', ['booking_missing']);
        $travelStatus = strtolower(trim((string) ($booking->travel_status ?? $booking->travel_state ?? '')));
        $base['would_reset_travel_ready'] = in_array(str_replace(['_', '-'], ' ', $travelStatus), ['ready', 'travel ready'], true);
        $batch = DB::table('general_booking_billing_batches')->where('id', $batchId)->where('booking_id', $bookingId)->first();
        if (! $batch) return $this->blocked($base, 'batch_missing', 'Supplementary batch was not found for this booking.', ['batch_missing']);
        $base['batch_no'] = (int) $batch->batch_no; $base['batch_status'] = strtolower((string) $batch->status); $base['freeze_hash'] = (string) $batch->source_snapshot_hash;
        if (strtolower((string) $batch->batch_type) !== 'supplementary') return $this->blocked($base, 'base_batch', 'Only supplementary batches can be materialized.', ['base_batch_not_plannable']);
        if ($base['batch_status'] !== 'approved') return $this->blocked($base, 'not_approved', 'Only an approved supplementary batch can be materialized.', ['only_approved_batch_plannable']);
        if (DB::table('general_booking_invoice_links')->where('batch_id', $batchId)->exists()) return $this->blocked($base, 'invoiced', 'An invoiced batch cannot be materialized again.', ['invoice_link_present']);
        try { $frozen = $this->integrity->build($bookingId, $batchId); } catch (\Throwable $e) {
            return $this->blocked($base, 'snapshot_integrity_failed', 'The persisted supplementary snapshot is invalid.', ['snapshot_integrity_failed']);
        }
        if (! hash_equals((string) $batch->source_snapshot_hash, (string) $frozen['hash'])) return $this->blocked($base, 'snapshot_integrity_failed', 'The submitted snapshot no longer matches persisted items.', ['snapshot_integrity_failed']);

        $rows = DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->orderBy('line_no')->orderBy('id')->get();
        if ($rows->isEmpty()) return $this->blocked($base, 'approved_batch_has_no_items', 'An approved supplementary batch has no items to materialize.', ['approved_batch_has_no_items']);
        $base['item_count'] = $rows->count(); $groups = []; $blockers = []; $linked = 0; $pending = 0;
        foreach ($rows as $row) {
            $item = $this->itemPlan($bookingId, $row, $groups, $blockers);
            if ($item['link_state'] === 'materialized') $linked++; else $pending++;
            $base['items'][] = $item;
        }
        $base['materialized_count'] = $linked; $base['pending_count'] = $pending; $base['product_groups'] = array_values($groups); $base['blockers'] = array_values(array_unique($blockers));
        if ($linked > 0 && $pending > 0) return $this->blocked($base, 'partial_batch_materialization', 'A batch cannot contain both materialized and unmaterialized items.', ['partial_batch_materialization']);
        if ($pending === 0 && $linked === $base['item_count'] && !$blockers) { $base['ready'] = true; $base['code'] = 'already_materialized'; $base['materialization_state'] = 'already_materialized'; return $base; }
        if ($blockers) return $this->blocked($base, 'materialization_blocked', 'One or more supplementary items cannot be safely appended.', $blockers);
        $base['ready'] = true; $base['code'] = 'ready'; $base['materialization_state'] = 'unmaterialized';
        return $base;
    }

    private function itemPlan(int $bookingId, object $row, array &$groups, array &$blockers): array
    {
        $snapshot = json_decode((string) $row->product_snapshot, true); $snapshot = is_array($snapshot) ? $snapshot : [];
        $product = strtolower((string) $row->product_type); $item = ['id'=>(int)$row->id, 'line_no'=>(int)$row->line_no, 'product_type'=>$product, 'link_state'=>'unmaterialized', 'service_strategy'=>'blocked', 'existing_booking_service_id'=>null, 'product_service_id'=>null, 'blockers'=>[]];
        $sourceTable = trim((string) ($row->source_table ?? '')); $sourceId = (int) ($row->source_id ?? 0); $serviceId = (int) ($row->booking_service_id ?? 0); $productServiceId = (int) ($row->product_service_id ?? 0);
        $linkValues = [$sourceTable !== '', $sourceId > 0, $serviceId > 0, $productServiceId > 0];
        if (count(array_unique($linkValues)) > 1 && ! ($sourceTable === '' && $sourceId === 0 && $serviceId === 0 && $productServiceId === 0)) return $this->itemBlocked($item, $blockers, 'partial_materialization_link');
        $master = $this->master($product, $item, $blockers);
        $expectedProductServiceId = (int) ($master['id'] ?? 0);
        $item['product_service_id'] = $productServiceId ?: null;
        if ($sourceTable !== '') {
            $allowed = $this->sourceTableFor($product, $bookingId);
            if (! $allowed || $sourceTable !== $allowed) return $this->itemBlocked($item, $blockers, 'unsupported_materialized_source');
            if (! Schema::hasTable($sourceTable) || ! $this->nativeSourceBelongs($sourceTable, $sourceId, $bookingId, $serviceId, $product, (int) ($snapshot['booking_passenger_id'] ?? 0))) return $this->itemBlocked($item, $blockers, 'foreign_or_missing_native_source');
            if ($productServiceId <= 0 || $productServiceId !== $expectedProductServiceId) return $this->itemBlocked($item, $blockers, 'product_service_link_conflict');
            if ($serviceId <= 0 || ! $this->serviceMatches($serviceId, $bookingId, $productServiceId)) return $this->itemBlocked($item, $blockers, 'booking_service_link_conflict');
            $item['link_state'] = 'materialized'; $item['service_strategy'] = 'existing_materialized_service'; return $item;
        }
        if ($serviceId > 0 || $productServiceId > 0) return $this->itemBlocked($item, $blockers, 'partial_materialization_link');
        if ($product === 'visa') $this->visaPlan($bookingId, $snapshot, $item, $blockers);
        if ($product === 'air') $this->airPlan($bookingId, $snapshot, $item, $groups, $blockers);
        if ($product === 'hotel') $this->hotelPlan($bookingId, $snapshot, $item, $blockers);
        if ($product === 'transport') $this->transportPlan($bookingId, $snapshot, $item, $blockers);
        if (!$item['blockers']) {
            if ($item['service_strategy'] === 'blocked') $item['service_strategy'] = $serviceId > 0 ? 'reuse_existing_service' : 'new_service';
            if ($serviceId > 0) $item['existing_booking_service_id'] = $serviceId;
        }
        return $item;
    }

    private function master(string $product, array &$item, array &$blockers): ?array
    { try { $m = match ($product) { 'air' => $this->products->findAir(), 'hotel' => $this->products->findHotel(), 'transport' => $this->products->findTransport(), 'visa' => $this->products->findVisa(), default => null }; if (!$m) $this->itemBlocked($item, $blockers, 'product_service_unresolved'); return $m; } catch (\Throwable) { $this->itemBlocked($item, $blockers, 'product_service_ambiguous'); return null; } }

    private function airPlan(int $bookingId, array $s, array &$item, array &$groups, array &$blockers): void
    { foreach (['booking_services','air_ticket_details','booking_itinerary_segments'] as $t) if (!Schema::hasTable($t)) $this->itemBlocked($item, $blockers, 'air_required_table_missing'); $p=(int)($s['booking_passenger_id']??0); if($p<=0)$this->itemBlocked($item,$blockers,'air_missing_passenger'); elseif(!$this->passengerBelongs($bookingId,$p))$this->itemBlocked($item,$blockers,'air_foreign_passenger'); if(trim((string)($s['from']??''))==='')$this->itemBlocked($item,$blockers,'air_missing_from'); if(trim((string)($s['to']??''))==='')$this->itemBlocked($item,$blockers,'air_missing_to'); if(trim((string)($s['departure_at']??''))==='')$this->itemBlocked($item,$blockers,'air_missing_departure'); if(trim((string)($s['airline_id']??''))===''&&trim((string)($s['airline_code']??''))===''&&trim((string)($s['airline_name']??''))==='')$this->itemBlocked($item,$blockers,'air_missing_airline'); $this->vendorCheck($s,$item,$blockers); $key=hash('sha256',json_encode([$s['vendor_id']??null,$s['pnr']??null,$s['airline_id']??null,$s['airline_code']??null,$s['airline_name']??null,$s['flight_number']??null,$s['from']??null,$s['to']??null,$s['departure_at']??null,$s['arrival_at']??null],JSON_UNESCAPED_SLASHES)); $groups[$key] ??= ['product_type'=>'air','group_key'=>$key,'service_strategy'=>'new_service','item_ids'=>[]]; $groups[$key]['item_ids'][]=$item['id']; }
    private function hotelPlan(int $bookingId, array $s, array &$item, array &$blockers): void
    { $table=$this->compatibleTable(self::HOTEL_TABLES,'hotel'); if(!$table)$this->itemBlocked($item,$blockers,'hotel_native_stay_store_unresolved'); $item['native_table']=$table; foreach(['city','hotel_name','room_type','board','check_in','check_out'] as $f)if(trim((string)($s[$f]??''))==='')$this->itemBlocked($item,$blockers,'hotel_required_snapshot_field'); $this->vendorCheck($s,$item,$blockers); $this->serviceStrategy($bookingId,(int)($item['product_service_id']??0),'hotel',$item,$blockers); }
    private function transportPlan(int $bookingId, array $s, array &$item, array &$blockers): void
    { $table=$this->compatibleTable(['booking_transport_segments','booking_transports','transport_booking_details','booking_transport_details'],'transport'); if(!$table)$this->itemBlocked($item,$blockers,'transport_native_store_unresolved'); $item['native_table']=$table; foreach(['from_location','to_location','vehicle_type'] as $f)if(trim((string)($s[$f]??''))==='')$this->itemBlocked($item,$blockers,'transport_required_snapshot_field'); $this->vendorCheck($s,$item,$blockers); $this->serviceStrategy($bookingId,(int)($item['product_service_id']??0),'transport',$item,$blockers); }
    private function visaPlan(int $bookingId, array $s, array &$item, array &$blockers): void
    { if(!Schema::hasTable('booking_visa_services')||!Schema::hasTable('booking_services')){$this->itemBlocked($item,$blockers,'visa_required_table_missing');return;} $p=(int)($s['booking_passenger_id']??0); if($p<=0)$this->itemBlocked($item,$blockers,'visa_missing_passenger'); elseif(!$this->passengerBelongs($bookingId,$p))$this->itemBlocked($item,$blockers,'visa_foreign_passenger'); elseif(DB::table('booking_visa_services')->where('booking_id',$bookingId)->where('booking_passenger_id',$p)->exists())$this->itemBlocked($item,$blockers,'visa_existing_passenger_conflict'); foreach(['country','visa_type'] as $f)if(trim((string)($s[$f]??''))==='')$this->itemBlocked($item,$blockers,'visa_required_snapshot_field'); $this->vendorCheck($s,$item,$blockers); $this->serviceStrategy($bookingId,(int)($item['product_service_id']??0),'visa',$item,$blockers); }
    private function nativeSourceBelongs(string $table,int $id,int $bookingId,int $serviceId,string $product,int $passengerId): bool { $row=DB::table($table)->where('id',$id)->first(); if(!$row)return false; $a=(array)$row; if(array_key_exists('booking_id',$a)&& (int)$a['booking_id']!==$bookingId)return false; if(array_key_exists('booking_service_id',$a)&&(!$serviceId|| (int)$a['booking_service_id']!==$serviceId||!$this->serviceBelongs((int)$a['booking_service_id'],$bookingId)))return false; if($product==='air'&&array_key_exists('booking_passenger_id',$a)&&(int)$a['booking_passenger_id']!==$passengerId)return false; if($product==='visa'&&array_key_exists('booking_passenger_id',$a)&&(int)$a['booking_passenger_id']!==$passengerId)return false; return array_key_exists('booking_id',$a)||array_key_exists('booking_service_id',$a); }
    private function serviceBelongs(int $id,int $bookingId): bool { return Schema::hasTable('booking_services')&&(int)(DB::table('booking_services')->where('id',$id)->value('booking_id')??0)===$bookingId; }
    private function serviceMatches(int $id,int $bookingId,int $productServiceId): bool { return Schema::hasTable('booking_services') && (int)(DB::table('booking_services')->where('id',$id)->where('booking_id',$bookingId)->value('product_service_id')??0)===$productServiceId; }
    private function serviceStrategy(int $bookingId,int $productServiceId,string $product,array &$item,array &$blockers): void { if(!Schema::hasTable('booking_services')){$this->itemBlocked($item,$blockers,$product.'_service_missing');return;} $rows=DB::table('booking_services')->where('booking_id',$bookingId)->where('product_service_id',$productServiceId)->get(); $active=$rows->filter(fn($r)=>!in_array(strtolower((string)($r->status??'')),['retired','inactive','deleted','removed','cancelled','canceled'],true))->values(); $ambiguousCode=match($product){'hotel'=>'hotel_service_ambiguous','transport'=>'transport_service_ambiguous','visa'=>'visa_service_ambiguous',default=>'service_ambiguous'}; if($active->count()>1){$this->itemBlocked($item,$blockers,$ambiguousCode);return;} if($active->count()===1){$item['existing_booking_service_id']=(int)$active[0]->id;$item['service_strategy']='reuse_existing_service';}else{$item['service_strategy']='new_service';} }
    private function sourceTableFor(string $product,int $bookingId): ?string { if($product==='visa')return Schema::hasTable('booking_visa_services')?'booking_visa_services':null; if($product==='hotel')return $this->compatibleTable(self::HOTEL_TABLES,'hotel'); if($product==='transport')return $this->compatibleTable(['booking_transport_segments','booking_transports','transport_booking_details','booking_transport_details'],'transport'); return $product==='air'&&Schema::hasTable('air_ticket_details')?'air_ticket_details':null; }
    private function compatibleTable(array $tables,string $product): ?string { foreach($tables as $t){if(!Schema::hasTable($t))continue;$c=Schema::getColumnListing($t);if(!in_array('booking_id',$c,true)&&!in_array('booking_service_id',$c,true))continue;if($product==='hotel'&&!array_intersect(['hotel_name','check_in','check_out','city'],$c))continue;if($product==='transport'&&!array_intersect(['from_location','to_location','vehicle_type','service_date'],$c))continue;return$t;}return null; }
    private function vendorCheck(array $snapshot,array &$item,array &$blockers): void { $cost=(float)($snapshot['supplier_cost_snapshot']??$snapshot['cost_price']??$snapshot['cost_rate']??0); if($cost<=0)return; $id=(int)($snapshot['vendor_id']??0); if($id<=0||!Schema::hasTable('vendors')||!DB::table('vendors')->where('id',$id)->exists())$this->itemBlocked($item,$blockers,'positive_cost_vendor_missing'); }
    private function passengerBelongs(int $bookingId,int $id): bool { foreach(['booking_passengers','booking_passenger_details','passengers'] as $t)if(Schema::hasTable($t)&&Schema::hasColumn($t,'booking_id'))return DB::table($t)->where('id',$id)->where('booking_id',$bookingId)->exists(); return false; }
    private function itemBlocked(array $item,array &$blockers,string $code): array { $item['link_state']='inconsistent'; $item['service_strategy']='blocked'; $item['blockers'][]=$code; $blockers[]=$code; return $item; }
    private function blocked(array $base,string $code,string $message,array $blockers): array { $base['code']=$code;$base['message']=$message;$base['blockers']=array_values(array_unique(array_merge($base['blockers'],$blockers)));$base['ready']=false;return$base; }
}
