<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP10.22.9
 *
 * The saved-ticket update controller predates the compact pricing UI and still
 * validates compatibility fields such as service_markup and the customer /
 * supplier discount type/value inputs. Those fields are intentionally optional
 * in the current UI. Normalise the compact edit payload before the established
 * controller validator runs so blank optional pricing values mean zero/FIXED.
 *
 * This middleware changes request compatibility only; it does not change the
 * ticket edit design, stored fare formulas, or the controller's commercial
 * calculations.
 */
class NormalizeAirTicketEditPricing
{
    public function handle(Request $request, Closure $next): Response
    {
        $customerServiceFee = $this->numericOrZero($request->input('customer_service_fee', 0));
        $markup = $this->numericOrZero($request->input('markup', 0));
        $customerDiscountAmount = $this->numericOrZero(
            $request->input('discount', $request->input('customer_discount_value', 0))
        );
        $customerDiscountType = $this->discountType($request->input('_edit_customer_discount_type', 'FIXED'));
        $customerDiscountValue = $this->numericOrZero(
            $request->input('_edit_customer_discount_value', $customerDiscountAmount)
        );

        $supplierCharges = $this->numericOrZero($request->input('supplier_charges', 0));
        $supplierOtherCharges = $this->numericOrZero($request->input('supplier_other_charges', 0));
        $supplierDiscountAmount = $this->numericOrZero(
            $request->input('supplier_discount', $request->input('supplier_discount_value', 0))
        );
        $supplierDiscountType = $this->discountType($request->input('_edit_supplier_discount_type', 'FIXED'));
        $supplierDiscountValue = $this->numericOrZero(
            $request->input('_edit_supplier_discount_value', $supplierDiscountAmount)
        );

        $request->merge([
            // Legacy edit-validator compatibility. The current UI intentionally
            // presents Service / Markup as one optional amount.
            'service_markup' => $this->numericSum($customerServiceFee, $markup),
            'customer_service_fee' => $customerServiceFee,
            'markup' => $markup,

            // Preserve the user's selected discount mode/value for the legacy
            // validator. The hidden discount field remains the UI's calculated
            // base-fare-only amount and is retained for controller compatibility.
            'customer_discount_type' => $customerDiscountType,
            'customer_discount_value' => $customerDiscountValue,
            'discount' => $customerDiscountAmount,

            'supplier_charges' => $supplierCharges,
            'supplier_other_charges' => $supplierOtherCharges,
            'supplier_discount_type' => $supplierDiscountType,
            'supplier_discount_value' => $supplierDiscountValue,
            'supplier_discount' => $supplierDiscountAmount,

            // PLB / Incentive was removed from the UI and commercial formula.
            'supplier_incentive' => 0,
            'supplier_commission' => $this->numericOrZero($request->input('supplier_commission', 0)),
        ]);

        return $next($request);
    }

    private function numericOrZero(mixed $value): float|int|string
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }

        return $value;
    }

    private function numericSum(mixed $left, mixed $right): float
    {
        return (float) $left + (float) $right;
    }

    private function discountType(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        return in_array($value, ['FIXED', 'PERCENT'], true) ? $value : 'FIXED';
    }
}
