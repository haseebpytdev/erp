<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return;
        }

        $columns = Schema::getColumnListing('booking_group_package_unified');

        Schema::table('booking_group_package_unified', function (Blueprint $table) use ($columns): void {
            if (! in_array('booked_adult_pax', $columns, true)) $table->unsignedInteger('booked_adult_pax')->default(0);
            if (! in_array('booked_child_pax', $columns, true)) $table->unsignedInteger('booked_child_pax')->default(0);
            if (! in_array('booked_infant_pax', $columns, true)) $table->unsignedInteger('booked_infant_pax')->default(0);

            if (! in_array('adult_sale_price', $columns, true)) $table->decimal('adult_sale_price', 15, 2)->default(0);
            if (! in_array('child_sale_price', $columns, true)) $table->decimal('child_sale_price', 15, 2)->default(0);
            if (! in_array('infant_sale_price', $columns, true)) $table->decimal('infant_sale_price', 15, 2)->default(0);

            if (! in_array('adult_supplier_cost', $columns, true)) $table->decimal('adult_supplier_cost', 15, 2)->default(0);
            if (! in_array('child_supplier_cost', $columns, true)) $table->decimal('child_supplier_cost', 15, 2)->default(0);
            if (! in_array('infant_supplier_cost', $columns, true)) $table->decimal('infant_supplier_cost', 15, 2)->default(0);

            if (! in_array('gross_sale_total', $columns, true)) $table->decimal('gross_sale_total', 15, 2)->default(0);
            if (! in_array('supplier_cost_total', $columns, true)) $table->decimal('supplier_cost_total', 15, 2)->default(0);
            if (! in_array('discount_amount_total', $columns, true)) $table->decimal('discount_amount_total', 15, 2)->default(0);
            if (! in_array('final_sale_total', $columns, true)) $table->decimal('final_sale_total', 15, 2)->default(0);
            if (! in_array('net_margin_total', $columns, true)) $table->decimal('net_margin_total', 15, 2)->default(0);
        });

        /*
         * Legacy Group Umrah stored one per-pax Sale Price/Supplier Cost.
         * Preserve it as the Adult fare and treat all existing Booked Pax as
         * Adult until staff explicitly corrects the Adult/Child/Infant mix.
         *
         * Legacy aggregate columns are then normalized to booking totals.
         */
        foreach (DB::table('booking_group_package_unified')->get() as $row) {
            $pax = max(1, (int) ($row->booked_pax ?? 1));
            $adultSale = max(0, (float) ($row->package_sale_price ?? 0));
            $adultCost = max(0, (float) ($row->supplier_cost ?? 0));
            $gross = round($adultSale * $pax, 2);
            $supplierTotal = round($adultCost * $pax, 2);

            $discountType = strtolower((string) ($row->discount_type ?? 'none'));
            $discountValue = max(0, (float) ($row->discount_value ?? 0));
            $discount = match ($discountType) {
                'percent' => round($gross * min($discountValue, 100) / 100, 2),
                'fixed' => min($discountValue, $gross),
                default => 0,
            };

            $final = round(max(0, $gross - $discount), 2);
            $agent = max(0, (float) ($row->agent_commission ?? 0));
            $salesperson = max(0, (float) ($row->salesperson_commission ?? 0));
            $margin = round($final - $supplierTotal - $agent - $salesperson, 2);

            DB::table('booking_group_package_unified')
                ->where('booking_id', $row->booking_id)
                ->update([
                    'booked_adult_pax' => $pax,
                    'booked_child_pax' => 0,
                    'booked_infant_pax' => 0,
                    'adult_sale_price' => $adultSale,
                    'child_sale_price' => 0,
                    'infant_sale_price' => 0,
                    'adult_supplier_cost' => $adultCost,
                    'child_supplier_cost' => 0,
                    'infant_supplier_cost' => 0,
                    'gross_sale_total' => $gross,
                    'supplier_cost_total' => $supplierTotal,
                    'discount_amount_total' => $discount,
                    'final_sale_total' => $final,
                    'net_margin_total' => $margin,

                    // Legacy aggregate compatibility now uses BOOKING TOTALS.
                    'package_sale_price' => $gross,
                    'supplier_cost' => $supplierTotal,
                    'final_sale_price' => $final,
                    'net_margin' => $margin,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return;
        }

        $columns = Schema::getColumnListing('booking_group_package_unified');
        $drop = array_values(array_filter([
            'booked_adult_pax','booked_child_pax','booked_infant_pax',
            'adult_sale_price','child_sale_price','infant_sale_price',
            'adult_supplier_cost','child_supplier_cost','infant_supplier_cost',
            'gross_sale_total','supplier_cost_total','discount_amount_total',
            'final_sale_total','net_margin_total',
        ], fn (string $column): bool => in_array($column, $columns, true)));

        if ($drop) {
            Schema::table('booking_group_package_unified', function (Blueprint $table) use ($drop): void {
                $table->dropColumn($drop);
            });
        }
    }
};
