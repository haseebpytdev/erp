<?php

namespace App\Services\Sales;

use App\Models\SalesInvoice;

final class BaseSalesInvoiceConsistencyResolver
{
    public function __construct(
        private readonly BaseBookingInvoiceScopeResolver $scope,
        private readonly BookingSalesInvoiceScopeResolver $classifier,
    ) {}

    public function resolve(SalesInvoice $invoice): array
    {
        $invoice->loadMissing(['booking.services.product', 'lines']);
        if (! $invoice->booking_id || $this->classifier->scope($invoice) !== 'base') {
            return ['scope' => 'supplementary', 'status' => 'IN_SYNC', 'expected_service_ids' => [], 'actual_service_ids' => [], 'missing_service_ids' => [], 'stale_service_ids' => [], 'expected_count' => 0, 'actual_count' => 0, 'expected_total' => null, 'invoice_total' => (float) $invoice->grand_total, 'total_matches' => true, 'missing_services' => [], 'stale_services' => []];
        }

        $resolved = $this->scope->resolve($invoice->booking);
        $expected = collect($resolved['expected_service_ids']);
        $actual = $invoice->lines->pluck('source_booking_service_id')
            ->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->values();
        $invalidLines = $invoice->lines->contains(fn ($line): bool => (int) ($line->source_booking_service_id ?? 0) <= 0);
        $missing = $expected->diff($actual)->values();
        $stale = $actual->diff($expected)->values();
        $status = $invalidLines || ($missing->isNotEmpty() && $stale->isNotEmpty()) ? 'MISMATCH' : ($missing->isNotEmpty() ? 'MISSING_SERVICES' : ($stale->isNotEmpty() ? 'STALE_EXTRA_SERVICES' : 'IN_SYNC'));
        $byId = collect($resolved['expected_services'])->keyBy(fn ($service): int => (int) $service->id);
        $missingRows = $missing->map(fn (int $id): array => $this->serviceRow($byId->get($id), $id))->values()->all();
        $staleRows = $stale->map(function (int $id) use ($invoice): array {
            $line = $invoice->lines->first(fn ($item): bool => (int) $item->source_booking_service_id === $id);
            return ['id' => $id, 'description' => (string) ($line->description ?? 'Service'), 'amount' => round((float) ($line->line_total ?? 0), 2)];
        })->values()->all();

        return [
            'scope' => 'base', 'status' => $status,
            'expected_service_ids' => $expected->all(), 'actual_service_ids' => $actual->unique()->values()->all(),
            'missing_service_ids' => $missing->all(), 'stale_service_ids' => $stale->all(),
            'expected_count' => $expected->count(), 'actual_count' => $actual->unique()->count(),
            'expected_total' => (float) $resolved['expected_total'], 'invoice_total' => round((float) $invoice->grand_total, 2),
            'total_matches' => abs((float) $resolved['expected_total'] - (float) $invoice->grand_total) <= 0.01,
            'missing_services' => $missingRows, 'stale_services' => $staleRows,
        ];
    }

    private function serviceRow($service, int $id): array
    {
        return ['id' => $id, 'description' => (string) ($service->description ?? 'Service'), 'category' => (string) ($service->product?->category ?? ''), 'amount' => round((float) ($service->line_total ?? 0), 2)];
    }
}
