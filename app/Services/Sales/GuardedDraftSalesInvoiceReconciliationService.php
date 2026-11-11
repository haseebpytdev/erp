<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Historical Draft-only reconciliation. This service never posts accounting.
 */
final class GuardedDraftSalesInvoiceReconciliationService
{
    public function preview(int $invoiceId, mixed $actor): array
    {
        $invoice = $this->invoice($invoiceId);
        $this->authorize($invoice, $actor);
        $this->assertDraftUnposted($invoice);

        $services = $this->canonicalServices((int) $invoice->booking_id);
        $lines = $this->lines($invoiceId);
        $this->assertUniqueServices($services);
        $this->assertExistingLines($lines, $services);

        $present = $lines->pluck('source_booking_service_id')->filter()->map(fn ($id) => (int) $id)->all();
        $missing = $services->filter(fn ($service) => ! in_array((int) $service->id, $present, true))->values();
        $currentTotal = round((float) $lines->sum('line_total'), 2);
        $expectedTotal = round((float) $services->sum('line_total'), 2);
        $payload = [
            'invoice_id' => $invoiceId,
            'invoice_updated_at' => (string) $invoice->updated_at,
            'source_ids' => $services->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'current_total' => $currentTotal,
            'expected_total' => $expectedTotal,
        ];

        return [
            'invoice_id' => $invoiceId,
            'status' => (string) $invoice->status,
            'existing_lines' => $lines->values()->all(),
            'missing_lines' => $missing->values()->all(),
            'current_total' => $currentTotal,
            'expected_total' => $expectedTotal,
            'amount_variance' => round($expectedTotal - $currentTotal, 2),
            'source_ids' => $payload['source_ids'],
            'preview_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
    }

    public function reconcile(int $invoiceId, mixed $actor, string $previewHash, string $reason): array
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reconciliation reason is required.']);
        }

        $invoice = $this->invoice($invoiceId);
        $this->authorize($invoice, $actor);
        $this->assertDraftUnposted($invoice);

        return DB::transaction(function () use ($invoiceId, $actor, $previewHash, $reason): array {
            $locked = DB::table('sales_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if (! $locked) {
                throw ValidationException::withMessages(['invoice' => 'Invoice not found.']);
            }
            $this->assertDraftUnposted($locked);
            DB::table('booking_services')->where('booking_id', $locked->booking_id)->lockForUpdate()->get();

            $before = $this->preview($invoiceId, $actor);
            if (! hash_equals((string) $previewHash, (string) $before['preview_hash'])) {
                throw ValidationException::withMessages(['preview' => 'The reconciliation preview is stale.']);
            }
            if ($before['missing_lines'] === []) {
                return ['invoice_id' => $invoiceId, 'inserted' => 0, 'total' => $before['current_total']];
            }

            $maxLine = (int) (DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoiceId)->max('line_no') ?? 0);
            foreach ($before['missing_lines'] as $service) {
                DB::table('sales_invoice_lines')->insert([
                    'sales_invoice_id' => $invoiceId,
                    'line_no' => ++$maxLine,
                    'source_booking_service_id' => $service->id,
                    'product_service_id' => $service->product_service_id,
                    'category_snapshot' => $service->category,
                    'pricing_basis_snapshot' => $service->pricing_basis_snapshot,
                    'description' => $service->description,
                    'quantity' => $service->quantity,
                    'unit_price' => $service->unit_price,
                    'line_subtotal' => $service->line_total,
                    'discount_amount' => 0,
                    'line_total' => $service->line_total,
                    'currency_code' => $service->currency_code,
                    'revenue_mapping_key' => $service->revenue_mapping_key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $total = round((float) DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoiceId)->sum('line_total'), 2);
            if (abs($total - (float) $before['expected_total']) > 0.005) {
                throw ValidationException::withMessages(['total' => 'Reconciliation total does not match canonical booking services.']);
            }
            DB::table('sales_invoices')->where('id', $invoiceId)->update(['subtotal' => $total, 'grand_total' => $total, 'updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'user_id' => $this->actorId($actor),
                'event' => 'sales_invoice.draft_reconciled',
                'auditable_type' => 'App\\Models\\SalesInvoice',
                'auditable_id' => $invoiceId,
                'old_values' => json_encode(['total' => $before['current_total'], 'source_ids' => array_column($before['existing_lines'], 'source_booking_service_id')]),
                'new_values' => json_encode(['total' => $total, 'source_ids' => $before['source_ids'], 'reason' => $reason]),
                'created_at' => now(),
            ]);

            return ['invoice_id' => $invoiceId, 'inserted' => count($before['missing_lines']), 'total' => $total];
        });
    }

    private function invoice(int $id): object
    {
        $invoice = DB::table('sales_invoices')->where('id', $id)->first();
        if (! $invoice) throw ValidationException::withMessages(['invoice' => 'Invoice not found.']);
        return $invoice;
    }

    private function authorize(object $invoice, mixed $actor): void
    {
        if ($this->actorCompany($actor) !== null && (int) $this->actorCompany($actor) !== (int) $invoice->company_id) {
            throw ValidationException::withMessages(['invoice' => 'Invoice belongs to another company.']);
        }
        if ($this->actorBranch($actor) !== null && (int) $this->actorBranch($actor) !== (int) $invoice->branch_id) {
            throw ValidationException::withMessages(['invoice' => 'Invoice belongs to another branch.']);
        }
        $allowed = is_object($actor) && method_exists($actor, 'can') && $actor->can('sales.invoice.draft.reconcile');
        if (! $allowed && is_object($actor) && method_exists($actor, 'hasPermissionTo')) $allowed = $actor->hasPermissionTo('sales.invoice.draft.reconcile');
        if (! $allowed && is_object($actor) && method_exists($actor, 'hasPermission')) $allowed = $actor->hasPermission('sales.invoice.draft.reconcile');
        if (! $allowed) throw ValidationException::withMessages(['invoice' => 'You are not authorized to reconcile Draft invoices.']);
    }

    private function assertDraftUnposted(object $invoice): void
    {
        if (strtolower((string) $invoice->status) !== 'draft' || $invoice->journal_entry_id !== null || $invoice->posted_at !== null || $invoice->approved_at !== null) {
            throw ValidationException::withMessages(['invoice' => 'Only an unapproved, unposted Draft invoice can be reconciled.']);
        }
    }

    private function canonicalServices(int $bookingId)
    {
        return DB::table('booking_services as bs')->join('products_services as ps', 'ps.id', '=', 'bs.product_service_id')
            ->where('bs.booking_id', $bookingId)->where('bs.status', 'active')->orderBy('bs.id')
            ->get(['bs.id','bs.product_service_id','ps.category','bs.description','bs.quantity','bs.unit_price','bs.line_total','bs.currency_code','bs.pricing_basis_snapshot','ps.revenue_mapping_key']);
    }

    private function lines(int $invoiceId)
    {
        return DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoiceId)->orderBy('line_no')->get();
    }

    private function assertUniqueServices($services): void
    {
        if ($services->pluck('id')->duplicates()->isNotEmpty()) throw ValidationException::withMessages(['services' => 'Duplicate canonical service identity.']);
    }

    private function assertExistingLines($lines, $services): void
    {
        $map = $services->keyBy('id');
        $seen = [];
        foreach ($lines as $line) {
            $id = (int) ($line->source_booking_service_id ?? 0);
            if ($id <= 0 || isset($seen[$id])) throw ValidationException::withMessages(['lines' => 'Ambiguous or duplicate invoice source identity.']);
            $seen[$id] = true;
            if (! $map->has($id)) throw ValidationException::withMessages(['lines' => 'Existing invoice line is not a canonical booking service.']);
            $service = $map->get($id);
            if ((int) $line->product_service_id !== (int) $service->product_service_id || strtoupper((string) $line->category_snapshot) !== strtoupper((string) $service->category) || abs((float) $line->quantity - (float) $service->quantity) > 0.0005 || abs((float) $line->line_total - (float) $service->line_total) > 0.005 || (string) $line->revenue_mapping_key !== (string) $service->revenue_mapping_key) {
                throw ValidationException::withMessages(['lines' => 'Existing invoice line was manually changed or conflicts with its source service.']);
            }
        }
    }

    private function actorId(mixed $actor): ?int { return is_object($actor) ? (isset($actor->id) ? (int) $actor->id : null) : (is_array($actor) && isset($actor['id']) ? (int) $actor['id'] : null); }
    private function actorCompany(mixed $actor): ?int { return is_object($actor) && isset($actor->company_id) ? (int) $actor->company_id : (is_array($actor) && isset($actor['company_id']) ? (int) $actor['company_id'] : null); }
    private function actorBranch(mixed $actor): ?int { return is_object($actor) && isset($actor->branch_id) ? (int) $actor->branch_id : (is_array($actor) && isset($actor['branch_id']) ? (int) $actor['branch_id'] : null); }
}
