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
        $query = $this->eligibleQuery($role);
        if ($query === null) throw new RuntimeException('The live party-role authority is unavailable.');
        $row = $query->where('p.id', $partyId)->first();
        if (! $row) {
            $label = $this->normaliseRole($role) === 'CUSTOMER' ? 'Customer' : 'Vendor/Supplier';
            throw new RuntimeException('Selected party is not configured as a '.$label.'.');
        }
        return ['party_id' => (int) $row->party_id, 'party_name' => (string) $row->party_name,
            'role' => strtolower($role) === 'vendor' ? 'supplier' : strtolower($role), 'source' => 'parties.party_roles'];
    }

    public function options(string $role): array
    {
        $query = $this->eligibleQuery($role);
        if ($query === null) return [];
        try {
            return $query->orderBy('party_name')->limit(1200)->get()->map(static fn ($row): array => [
                'id' => (int) $row->party_id, 'name' => (string) $row->party_name,
            ])->values()->all();
        } catch (\Throwable) { return []; }
    }

    private function eligibleQuery(string $role): ?\Illuminate\Database\Query\Builder
    {
        $roleValue = $this->normaliseRole($role);
        if (! Schema::hasTable('parties') || ! Schema::hasTable('party_roles')) return null;
        try { $partyColumns = Schema::getColumnListing('parties'); $roleColumns = Schema::getColumnListing('party_roles'); }
        catch (\Throwable) { return null; }
        foreach (['id', 'is_active'] as $column) if (! in_array($column, $partyColumns, true)) return null;
        foreach (['party_id', 'role', 'is_active'] as $column) if (! in_array($column, $roleColumns, true)) return null;
        $nameColumn = in_array('display_name', $partyColumns, true) ? 'display_name' : (in_array('legal_name', $partyColumns, true) ? 'legal_name' : null);
        if ($nameColumn === null) return null;
        $nameExpression = in_array('legal_name', $partyColumns, true)
            ? "COALESCE(NULLIF(p.{$nameColumn}, ''), p.legal_name)"
            : "NULLIF(p.{$nameColumn}, '')";
        $query = DB::table('parties as p')->join('party_roles as pr', 'pr.party_id', '=', 'p.id')
            ->where('p.is_active', 1)->where('pr.is_active', 1)->whereRaw('UPPER(pr.role) = ?', [$roleValue]);
        if (in_array('starts_on', $roleColumns, true)) $query->where(function ($q): void { $q->whereNull('pr.starts_on')->orWhereDate('pr.starts_on', '<=', now()->toDateString()); });
        if (in_array('ends_on', $roleColumns, true)) $query->where(function ($q): void { $q->whereNull('pr.ends_on')->orWhereDate('pr.ends_on', '>=', now()->toDateString()); });
        return $query->select(['p.id as party_id', DB::raw($nameExpression.' as party_name')])->distinct();
    }

    private function normaliseRole(string $role): string
    {
        return match (strtolower(trim($role))) {
            'customer' => 'CUSTOMER', 'supplier', 'vendor' => 'VENDOR',
            default => throw new RuntimeException('Unsupported accounting party role.'),
        };
    }
}
