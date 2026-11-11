<?php

namespace App\Http\Middleware;

use App\Models\AirTicketEntryDraft;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class NormalizeAirTicketBatchPricing
{
    public function handle(Request $request, Closure $next): Response
    {
        $fare = (array) $request->input('fare', []);
        $purchase = (array) $request->input('purchase', []);
        $fareTotal = (array) $request->input('fare_total', []);
        $uiCustomerType = (array) $request->input('ui_customer_discount_type', []);
        $uiCustomerValue = (array) $request->input('ui_customer_discount_value', []);
        $uiSupplierType = (array) $request->input('ui_supplier_discount_type', []);
        $uiSupplierValue = (array) $request->input('ui_supplier_discount_value', []);

        $types = array_values(array_unique(array_merge(
            array_keys($fare),
            array_keys($purchase),
            array_keys($fareTotal),
            array_keys($uiCustomerType),
            array_keys($uiSupplierType)
        )));

        foreach ($types as $type) {
            $fare[$type] = (array) ($fare[$type] ?? []);
            $purchase[$type] = (array) ($purchase[$type] ?? []);

            // Compatibility aliases expected by the established batch validator.
            // These fields are optional in the UI; blank means zero / FIXED.
            $fare[$type]['service_markup'] = $this->numericOrZero(
                $fare[$type]['service_markup'] ?? $fare[$type]['customer_service_fee'] ?? 0
            );
            $fare[$type]['customer_service_fee'] = $this->numericOrZero(
                $fare[$type]['customer_service_fee'] ?? $fare[$type]['service_markup'] ?? 0
            );
            $fare[$type]['markup'] = $this->numericOrZero($fare[$type]['markup'] ?? 0);
            $fare[$type]['discount_type'] = $this->discountType(
                $fare[$type]['discount_type'] ?? $uiCustomerType[$type] ?? 'FIXED'
            );
            $fare[$type]['discount_value'] = $this->numericOrZero(
                $fare[$type]['discount_value'] ?? $uiCustomerValue[$type] ?? 0
            );
            $fare[$type]['discount'] = $this->numericOrZero($fare[$type]['discount'] ?? 0);

            $purchase[$type]['supplier_charges'] = $this->numericOrZero($purchase[$type]['supplier_charges'] ?? 0);
            $purchase[$type]['supplier_other_charges'] = $this->numericOrZero($purchase[$type]['supplier_other_charges'] ?? 0);
            $purchase[$type]['supplier_commission'] = $this->numericOrZero($purchase[$type]['supplier_commission'] ?? 0);
            $purchase[$type]['supplier_incentive'] = 0;
            $purchase[$type]['supplier_discount'] = $this->numericOrZero($purchase[$type]['supplier_discount'] ?? 0);
            $purchase[$type]['discount_type'] = $this->discountType(
                $purchase[$type]['discount_type'] ?? $uiSupplierType[$type] ?? 'FIXED'
            );
            $purchase[$type]['discount_value'] = $this->numericOrZero(
                $purchase[$type]['discount_value'] ?? $uiSupplierValue[$type] ?? 0
            );
        }

        $request->merge([
            'fare' => $fare,
            'purchase' => $purchase,
        ]);

        $response = $next($request);

        // Final batch save succeeded: the working draft is no longer needed.
        if ($response->getStatusCode() < 400 && Schema::hasTable('air_ticket_entry_drafts')) {
            $booking = $request->route('booking');
            $service = $request->route('service');
            if ($booking && $service) {
                AirTicketEntryDraft::query()
                    ->where('booking_id', $booking->id)
                    ->where('booking_service_id', $service->id)
                    ->delete();
            }
        }

        return $response;
    }

    private function numericOrZero(mixed $value): float|int|string
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }

        return $value;
    }

    private function discountType(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        return in_array($value, ['FIXED', 'PERCENT'], true) ? $value : 'FIXED';
    }
}
