<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Read-only, schema-aware Product/Service Master authority. */
final class NativeProductServiceResolver
{
    public function air(): array { return $this->resolve('Air', ['AIR','AIRTICKET','AIR_TICKET'], ['AIR_TICKET'], ['AIR TICKET','AIR TICKETS','FLIGHT TICKET']); }
    public function hotel(): array { return $this->resolve('Hotel', ['HOTEL'], ['HOTEL','ACCOMMODATION'], ['HOTEL','HOTELS','ACCOMMODATION']); }
    public function transport(): array { return $this->resolve('Transport', ['TRANSPORT'], ['TRANSPORT','TRANSFER'], ['TRANSPORT','TRANSPORTATION','TRANSFER']); }

    private function resolve(string $label, array $codes, array $categories, array $names): array
    {
        $tables = $this->tables(); $matches = [];
        foreach ($tables as $table) foreach (DB::table($table)->get() as $row) {
            $a = (array) $row; if (isset($a['active']) && ! $a['active']) continue; if (isset($a['is_active']) && ! $a['is_active']) continue;
            $code = strtoupper(trim((string) ($a['code'] ?? $a['product_code'] ?? $a['service_code'] ?? '')));
            $category = strtoupper(trim((string) ($a['category'] ?? $a['type'] ?? '')));
            $name = strtoupper(trim((string) ($a['name'] ?? $a['title'] ?? $a['service_name'] ?? '')));
            $score = in_array($code, $codes, true) ? 3 : (in_array($category, $categories, true) ? 2 : (in_array($name, $names, true) ? 1 : 0));
            if ($score) $matches[] = compact('table','a','code','category','name','score');
        }
        if (! $matches) throw ValidationException::withMessages(['product' => "No active native {$label} Product/Service master matched."]);
        $best = max(array_column($matches, 'score')); $matches = array_values(array_filter($matches, fn (array $m): bool => $m['score'] === $best));
        if (count($matches) !== 1) throw ValidationException::withMessages(['product' => "Multiple active native {$label} Product/Service masters matched; no Product identity was guessed."]);
        $m = $matches[0]; $r = $m['a'];
        return ['id'=>(int) ($r['id'] ?? $r['product_service_id'] ?? 0), 'table'=>$m['table'], 'row'=>$r, 'code'=>$m['code'], 'name'=>$m['name'], 'category'=>$m['category'], 'pricing_basis'=>strtoupper((string) ($r['pricing_basis'] ?? $r['pricing_basis_code'] ?? '')), 'passenger_link_mode'=>strtoupper((string) ($r['passenger_link_mode'] ?? $r['passenger_link'] ?? '')), 'customer_sale_currency'=>strtoupper((string) ($r['customer_sale_currency'] ?? $r['sale_currency'] ?? $r['currency_code'] ?? '')), 'supplier_cost_currency'=>strtoupper((string) ($r['supplier_cost_currency'] ?? $r['cost_currency'] ?? '')), 'revenue_mapping'=>(string) ($r['revenue_mapping'] ?? $r['revenue_mapping_key'] ?? ''), 'cost_mapping'=>(string) ($r['cost_mapping'] ?? $r['cost_mapping_key'] ?? '')];
    }

    private function tables(): array
    {
        $tables = [];
        $foreign = $this->bookingServicesForeignKeyTarget();
        if ($foreign && Schema::hasTable($foreign)) $tables[] = $foreign;
        foreach (['product_services','product_service_master','product_service_masters','travel_product_services','service_products'] as $table) if (Schema::hasTable($table)) $tables[] = $table;
        return array_values(array_unique($tables));
    }

    private function bookingServicesForeignKeyTarget(): ?string
    {
        if (! Schema::hasTable('booking_services')) return null;
        try {
            $row = DB::selectOne("SELECT REFERENCED_TABLE_NAME AS target FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_services' AND COLUMN_NAME = 'product_service_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
            return $row?->target ? (string) $row->target : null;
        } catch (\Throwable) { return null; }
    }
}
