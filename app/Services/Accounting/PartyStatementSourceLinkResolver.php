<?php

namespace App\Services\Accounting;

use App\Services\Operations\NativeSalesInvoiceInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class PartyStatementSourceLinkResolver
{
    public function __construct(private readonly NativeSalesInvoiceInspector $salesInvoices) {}

    public function resolve(array $row): ?array
    {
        $type = strtolower(trim((string) ($row['source_type'] ?? '')));
        $id = (int) ($row['source_id'] ?? 0);
        if ($this->isInvoiceCandidate($type, $row)) return $this->resolveSalesInvoice((string) ($row['reference'] ?? ''));
        if ($id <= 0) return null;
        [$route, $tables, $kind] = $this->authority($type);
        if ($route === null || ! Route::has($route) || ! $this->exists($tables, $id)) return null;
        return ['source_url' => route($route, $id), 'source_route' => $route, 'source_kind' => $kind, 'source_linkable' => true];
    }

    private function isInvoiceCandidate(string $type, array $row): bool
    {
        return str_contains($type, 'invoice')
            || strtolower(trim((string) ($row['type'] ?? ''))) === 'invoice'
            || str_starts_with(strtoupper(trim((string) ($row['reference'] ?? ''))), 'SI-');
    }

    private function resolveSalesInvoice(string $reference): ?array
    {
        try {
            $invoice = $this->salesInvoices->findExactByNumber($reference);
            $id = (int) ($invoice['id'] ?? 0);
            if ($id <= 0) return null;
            $url = $this->salesInvoices->nativeInvoiceUrl($id);
            if (! is_string($url) || trim($url) === '') return null;
            return ['source_url' => $url, 'source_route' => 'native_sales_invoice', 'source_kind' => 'sales_invoice', 'source_linkable' => true];
        } catch (\Throwable) { return null; }
    }

    private function authority(string $type): array
    {
        if (in_array($type, ['party_opening_balance', 'party_opening_balance_reversal'], true)) return ['accounting.party-opening-balances.show', ['party_opening_balances'], 'party_opening_balance'];
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
