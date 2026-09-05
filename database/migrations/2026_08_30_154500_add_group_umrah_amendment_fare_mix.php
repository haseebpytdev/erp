<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_group_package_commercial_amendments')) {
            return;
        }

        $columns = Schema::getColumnListing(
            'booking_group_package_commercial_amendments'
        );

        Schema::table(
            'booking_group_package_commercial_amendments',
            function (Blueprint $table) use ($columns): void {
                foreach ([
                    'previous_adult_pax',
                    'previous_child_pax',
                    'previous_infant_pax',
                    'additional_adult_pax',
                    'additional_child_pax',
                    'additional_infant_pax',
                    'resulting_adult_pax',
                    'resulting_child_pax',
                    'resulting_infant_pax',
                ] as $column) {
                    if (! in_array($column, $columns, true)) {
                        $table->unsignedInteger($column)->default(0);
                    }
                }

                foreach ([
                    'adult_sale_price_snapshot',
                    'child_sale_price_snapshot',
                    'infant_sale_price_snapshot',
                    'adult_supplier_cost_snapshot',
                    'child_supplier_cost_snapshot',
                    'infant_supplier_cost_snapshot',
                    'additional_gross_sale_total',
                ] as $column) {
                    if (! in_array($column, $columns, true)) {
                        $table->decimal($column, 18, 2)->default(0);
                    }
                }
            }
        );

        foreach (
            DB::table('booking_group_package_commercial_amendments')->get()
            as $row
        ) {
            $additional = max(0, (int) ($row->additional_pax ?? 0));
            $previous = max(0, (int) ($row->previous_booked_pax ?? 0));
            $resulting = max(0, (int) ($row->resulting_booked_pax ?? 0));

            $gross = max(0, (float) ($row->additional_sale_price ?? 0));
            $supplier = max(0, (float) ($row->additional_supplier_cost ?? 0));

            $salePerPax = $additional > 0
                ? round($gross / $additional, 2)
                : 0;

            $supplierPerPax = $additional > 0
                ? round($supplier / $additional, 2)
                : 0;

            DB::table('booking_group_package_commercial_amendments')
                ->where('id', $row->id)
                ->update([
                    // Legacy amendments did not record a fare split.
                    // Preserve them as Adult-only audit snapshots.
                    'previous_adult_pax' => $previous,
                    'previous_child_pax' => 0,
                    'previous_infant_pax' => 0,
                    'additional_adult_pax' => $additional,
                    'additional_child_pax' => 0,
                    'additional_infant_pax' => 0,
                    'resulting_adult_pax' => $resulting,
                    'resulting_child_pax' => 0,
                    'resulting_infant_pax' => 0,
                    'adult_sale_price_snapshot' => $salePerPax,
                    'child_sale_price_snapshot' => 0,
                    'infant_sale_price_snapshot' => 0,
                    'adult_supplier_cost_snapshot' => $supplierPerPax,
                    'child_supplier_cost_snapshot' => 0,
                    'infant_supplier_cost_snapshot' => 0,
                    'additional_gross_sale_total' => $gross,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_group_package_commercial_amendments')) {
            return;
        }

        $columns = Schema::getColumnListing(
            'booking_group_package_commercial_amendments'
        );

        $drop = array_values(array_filter([
            'previous_adult_pax',
            'previous_child_pax',
            'previous_infant_pax',
            'additional_adult_pax',
            'additional_child_pax',
            'additional_infant_pax',
            'resulting_adult_pax',
            'resulting_child_pax',
            'resulting_infant_pax',
            'adult_sale_price_snapshot',
            'child_sale_price_snapshot',
            'infant_sale_price_snapshot',
            'adult_supplier_cost_snapshot',
            'child_supplier_cost_snapshot',
            'infant_supplier_cost_snapshot',
            'additional_gross_sale_total',
        ], fn (string $column): bool => in_array($column, $columns, true)));

        if ($drop) {
            Schema::table(
                'booking_group_package_commercial_amendments',
                function (Blueprint $table) use ($drop): void {
                    $table->dropColumn($drop);
                }
            );
        }
    }
};
