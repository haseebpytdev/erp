<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class PartyStatementSourceLinkResolver
{
    public function resolve(array $row): ?array
    {
        $type = strtolower(trim((string) ($row['source_type'] ?? '')));
        $id = (int) ($row['source_id'] ?? 0);
        if ($id <= 0) return null;
        [$route, $tables, $kind] = $this->authority($type);
        if ($route === null || ! Route::has($route) || ! $this->exists($tables, $id)) return null;
        return ['source_url' => route($route, $id), 'source_route' => $route, 'source_kind' => $kind, 'source_linkable' => true];
    }

    private function authority(string $type): array
    {
        if (str_contains($type, 'invoice')) return ['sales.invoices.show', ['sales_invoices', 'sales_invoice_headers', 'invoices'], 'sales_invoice'];
        if (in_array($type, ['cash_voucher', 'cash_voucher_reversal', 'receipt', 'payment'], true) || str_contains($type, 'cash_voucher')) return ['accounting.cash-vouchers.show', ['cash_vouchers'], 'cash_voucher'];
        if (str_contains($type, 'advance_adjust')) return ['accounting.advance-adjustments.show', ['advance_adjustments'], 'advance_adjustment'];
        if (str_contains($type, 'supplier_cost')) return ['purchase.supplier-costing.show', ['supplier_costings'], 'supplier_costing'];
        return [null, [], null];
    }

    private function exists(array $tables, int $id): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) continue;
            try { if (DB::table($table)->where('id', $id)->exists()) return true; } catch (\Throwable) { }
        }
        return false;
    }
}
