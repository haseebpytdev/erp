<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Canonical, fail-closed accounting party role authority. */
final class AccountingPartyRoleResolver
{
    public function resolveCustomer(int $partyId): array
    {
        return $this->assertRole($partyId, 'customer');
    }

    public function resolveSupplier(int $partyId): array
    {
        return $this->assertRole($partyId, 'supplier');
    }

    public function assertRole(int $partyId, string $role): array
    {
        if ($partyId <= 0) {
            throw new RuntimeException('A canonical party is required for this accounting workflow.');
        }
        $role = $role === 'vendor' ? 'supplier' : $role;
        foreach ($this->sources($role) as $source) {
            if (! Schema::hasTable($source['table'])) continue;
            $columns = Schema::getColumnListing($source['table']);
            if (! in_array($source['id'], $columns, true) || ! in_array($source['name'], $columns, true)) continue;
            $row = DB::table($source['table'])->where($source['id'], $partyId)->first();
            if ($row && $this->roleMatches($row, $columns, $role, $source['dedicated'])) {
                return ['party_id' => $partyId, 'party_name' => (string) $row->{$source['name']], 'role' => $role, 'source' => $source['table']];
            }
        }
        $label = $role === 'customer' ? 'Customer' : 'Vendor/Supplier';
        throw new RuntimeException('Selected party is not configured as a '.$label.'.');
    }

    public function options(string $role): array
    {
        $rows = [];
        foreach ($this->sources($role === 'vendor' ? 'supplier' : $role) as $source) {
            if (! Schema::hasTable($source['table'])) continue;
            $columns = Schema::getColumnListing($source['table']);
            if (! in_array($source['id'], $columns, true) || ! in_array($source['name'], $columns, true)) continue;
            try {
                foreach (DB::table($source['table'])->orderBy($source['name'])->limit(1200)->get() as $row) {
                    if (! $this->roleMatches($row, $columns, $role === 'vendor' ? 'supplier' : $role, $source['dedicated'])) continue;
                    $id = (int) $row->{$source['id']}; $name = trim((string) $row->{$source['name']});
                    if ($id > 0 && $name !== '') $rows[$id] = ['id' => $id, 'name' => $name];
                }
            } catch (\Throwable) { continue; }
        }
        return array_values($rows);
    }

    private function sources(string $role): array
    {
        return $role === 'customer'
            ? [['table' => 'parties', 'id' => 'id', 'name' => 'name', 'dedicated' => false], ['table' => 'customers', 'id' => 'id', 'name' => 'name', 'dedicated' => true]]
            : [['table' => 'parties', 'id' => 'id', 'name' => 'name', 'dedicated' => false], ['table' => 'vendors', 'id' => 'id', 'name' => 'name', 'dedicated' => true], ['table' => 'suppliers', 'id' => 'id', 'name' => 'name', 'dedicated' => true]];
    }

    private function roleMatches(object $row, array $columns, string $role, bool $dedicated): bool
    {
        if ($dedicated) return true;
        $column = collect(['party_type', 'type', 'category', 'role'])->first(fn (string $c): bool => in_array($c, $columns, true));
        if (! $column) return false;
        $value = strtolower(trim((string) ($row->{$column} ?? '')));
        if ($role === 'customer') return str_contains($value, 'customer') || str_contains($value, 'client');
        return str_contains($value, 'supplier') || str_contains($value, 'vendor');
    }
}
