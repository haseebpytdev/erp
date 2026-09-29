<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Canonical, fail-closed accounting party role authority. */
final class AccountingPartyRoleResolver
{
    public function resolveCustomer(int $partyId): array { return $this->assertRole($partyId, 'customer'); }
    public function resolveSupplier(int $partyId): array { return $this->assertRole($partyId, 'supplier'); }

    public function assertRole(int $partyId, string $role): array
    {
        if ($partyId <= 0) throw new RuntimeException('A canonical party is required for this accounting workflow.');
        $role = $role === 'vendor' ? 'supplier' : $role;
        foreach ($this->sources() as $source) {
            if (! Schema::hasTable($source['table'])) continue;
            $columns = Schema::getColumnListing($source['table']);
            $id = $this->first($columns, ['id', 'party_id']);
            $name = $this->first($columns, ['name', 'display_name', 'legal_name', 'party_name', 'customer_name']);
            if (! $id || ! $name) continue;
            $row = DB::table($source['table'])->where($id, $partyId)->first();
            if ($row && $this->roleMatches($row, $columns, $role)) return ['party_id' => $partyId, 'party_name' => (string) $row->{$name}, 'role' => $role, 'source' => $source['table']];
        }
        $label = $role === 'customer' ? 'Customer' : 'Vendor/Supplier';
        throw new RuntimeException('Selected party is not configured as a '.$label.'.');
    }

    public function options(string $role): array
    {
        $rows = [];
        foreach ($this->sources() as $source) {
            if (! Schema::hasTable($source['table'])) continue;
            $columns = Schema::getColumnListing($source['table']);
            $id = $this->first($columns, ['id', 'party_id']);
            $name = $this->first($columns, ['name', 'display_name', 'legal_name', 'party_name', 'customer_name']);
            if (! $id || ! $name) continue;
            try {
                foreach (DB::table($source['table'])->orderBy($name)->limit(1200)->get() as $row) {
                    if (! $this->roleMatches($row, $columns, $role === 'vendor' ? 'supplier' : $role)) continue;
                    $value = (int) $row->{$id}; $label = trim((string) $row->{$name});
                    if ($value > 0 && $label !== '') $rows[$value] = ['id' => $value, 'name' => $label];
                }
            } catch (\Throwable) { continue; }
        }
        return array_values($rows);
    }

    private function sources(): array
    {
        // cash_vouchers.party_id is the unified Party identity. Dedicated
        // table IDs are never accepted without a proven canonical FK.
        return [['table' => 'parties']];
    }

    private function roleMatches(object $row, array $columns, string $role): bool
    {
        foreach ($role === 'customer' ? ['is_customer'] : ['is_supplier', 'is_vendor'] as $flag) {
            if (in_array($flag, $columns, true) && (bool) ($row->{$flag} ?? false)) return true;
        }
        $column = $this->first($columns, ['party_type', 'type', 'category', 'role']);
        if (! $column) return false;
        $tokens = preg_split('/[^a-z]+/', strtolower(trim((string) ($row->{$column} ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return $role === 'customer'
            ? (in_array('customer', $tokens, true) || in_array('client', $tokens, true))
            : (in_array('supplier', $tokens, true) || in_array('vendor', $tokens, true));
    }

    private function first(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate;
        return null;
    }
}
