<?php

namespace App\Services\Sales;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only authority for customer-facing booking selling lines.
 *
 * This deliberately consumes the same persisted booking_services rows used by
 * BaseBookingInvoiceScopeResolver.  It never creates or updates an invoice and
 * never derives a second product-pricing model.
 */
final class BookingCommercialLineItemResolver
{
    public function __construct(private readonly BaseBookingInvoiceScopeResolver $scope)
    {
    }

    public function resolve(int|Booking $booking): array
    {
        $model = $booking instanceof Booking
            ? $booking
            : Booking::query()->findOrFail($booking);

        $model->loadMissing(['customer', 'services.product', 'services.passengers']);
        $resolved = $this->scope->resolve($model);
        $services = collect($resolved['expected_services'] ?? []);
        $customer = $model->customer;

        $lines = $services->values()->map(fn ($service, int $index): array => $this->line($service, $index + 1))->all();
        $lineTotal = round(array_sum(array_map(fn (array $line): float => (float) ($line['line_selling_total'] ?? 0), $lines)), 2);
        $bookingDiscount = $this->firstNumeric($model->getAttributes(), ['discount_amount', 'discount_value', 'total_discount']);
        $bookingAdjustment = $this->firstNumeric($model->getAttributes(), ['adjustment_amount', 'adjustment_value', 'total_adjustment']);
        $grandTotal = round($lineTotal - ($bookingDiscount ?? 0) + ($bookingAdjustment ?? 0), 2);

        return [
            'booking_id' => (int) $model->getKey(),
            'booking_number' => (string) $model->booking_no,
            'customer' => $customer ? [
                'id' => (int) $customer->getKey(),
                'display_name' => (string) ($customer->display_name ?? $customer->legal_name ?? ''),
                'email' => $customer->email,
                'phone' => $customer->phone ?? $customer->mobile,
                'address' => trim(implode(', ', array_filter([$customer->address_line_1, $customer->address_line_2, $customer->city, $customer->country_code]))),
            ] : null,
            'currency' => strtoupper((string) $model->currency_code),
            'lines' => $lines,
            'discounts' => $bookingDiscount === null ? [] : [['scope' => 'booking', 'amount' => round($bookingDiscount, 2)]],
            'adjustments' => $bookingAdjustment === null ? [] : [['scope' => 'booking', 'amount' => round($bookingAdjustment, 2)]],
            'grand_total' => $grandTotal,
            'commercial_completeness_status' => $this->completeness($lines),
            'source' => 'booking_services.persisted_line_total',
        ];
    }

    private function line(object $service, int $lineNo): array
    {
        $raw = $service->getAttributes();
        $quantity = $this->numberOrNull($raw['quantity'] ?? null);
        $unit = $this->numberOrNull($raw['unit_price'] ?? null);
        $total = $this->numberOrNull($raw['line_total'] ?? null);
        $product = $service->product;
        $category = strtoupper((string) ($product?->category ?? 'OTHER'));
        $discount = $this->firstNumeric($raw, ['discount_amount', 'discount']);
        $discount ??= $this->detailDiscount((int) $service->getKey(), $category);
        $adjustment = $this->firstNumeric($raw, ['adjustment_amount', 'adjustment']);

        return [
            'line_no' => $lineNo,
            'booking_service_id' => (int) $service->getKey(),
            'product_type' => $category,
            'product_code' => $product?->code,
            'product_description' => (string) ($service->description ?: ($product?->name ?? '')),
            'service_dates' => ['from' => $this->date($service->service_from), 'to' => $this->date($service->service_to)],
            'quantity' => $quantity,
            'unit_selling_price' => $unit,
            'line_selling_total' => $total,
            'applicable_discounts' => $discount === null ? [] : [['amount' => round($discount, 2)]],
            'applicable_adjustments' => $adjustment === null ? [] : [['amount' => round($adjustment, 2)]],
            'currency' => strtoupper((string) ($service->currency_code ?? '')),
            'commercial_completeness_status' => ($quantity === null || $unit === null || $total === null) ? 'INCOMPLETE_MISSING_SELLING_VALUE' : 'COMPLETE',
        ];
    }

    private function completeness(array $lines): string
    {
        if ($lines === []) return 'NO_SELECTED_PRODUCTS';
        foreach ($lines as $line) if ($line['commercial_completeness_status'] !== 'COMPLETE') return 'INCOMPLETE_MISSING_SELLING_VALUE';
        return 'COMPLETE';
    }

    private function detailDiscount(int $serviceId, string $category): ?float
    {
        $table = match ($category) {
            'AIR', 'AIR_TICKET', 'FLIGHT' => 'air_ticket_details',
            'HOTEL', 'ACCOMMODATION' => 'hotel_booking_details',
            default => null,
        };
        if ($serviceId <= 0 || $table === null || ! Schema::hasTable($table)) return null;
        $column = match ($table) {
            'air_ticket_details' => Schema::hasColumn($table, 'customer_discount') ? 'customer_discount' : (Schema::hasColumn($table, 'discount') ? 'discount' : null),
            'hotel_booking_details' => Schema::hasColumn($table, 'customer_discount') ? 'customer_discount' : null,
            default => null,
        };
        if ($column === null) return null;
        $value = DB::table($table)->where('booking_service_id', $serviceId)->sum($column);
        return $value === null ? null : (float) $value;
    }

    private function numberOrNull(mixed $value): ?float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    private function firstNumeric(array $row, array $keys): ?float
    {
        foreach ($keys as $key) if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '' && is_numeric($row[$key])) return (float) $row[$key];
        return null;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        return method_exists($value, 'toDateString') ? $value->toDateString() : (string) $value;
    }
}
