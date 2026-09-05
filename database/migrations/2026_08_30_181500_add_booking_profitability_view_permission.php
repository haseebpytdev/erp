<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        try {
            $columns=Schema::getColumnListing(
                'permissions'
            );
        } catch (\Throwable) {
            return;
        }

        if (!in_array('name',$columns,true)) {
            return;
        }

        try {
            if (
                DB::table('permissions')
                    ->where(
                        'name',
                        'booking_profitability.view'
                    )
                    ->exists()
            ) {
                return;
            }

            $row=[
                'name'=>'booking_profitability.view',
            ];

            if (
                in_array(
                    'guard_name',
                    $columns,
                    true
                )
            ) {
                $row['guard_name']='web';
            }

            if (
                in_array(
                    'created_at',
                    $columns,
                    true
                )
            ) {
                $row['created_at']=now();
            }

            if (
                in_array(
                    'updated_at',
                    $columns,
                    true
                )
            ) {
                $row['updated_at']=now();
            }

            /*
             * Do not auto-assign this sensitive permission to ordinary staff.
             * Super Admin/Admin/Owner/Finance Manager role fallbacks are handled
             * by BookingProfitabilityAuthority; all other roles must be granted
             * booking_profitability.view explicitly in the existing RBAC UI.
             */
            DB::table('permissions')
                ->insert($row);
        } catch (\Throwable) {
            /*
             * Custom/non-Spatie permission schemas may have additional required
             * columns. A permission-master mismatch must not break the safe DB
             * upgrade; privileged role fallback still keeps the report usable.
             */
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        try {
            $columns=Schema::getColumnListing(
                'permissions'
            );

            if (
                in_array(
                    'name',
                    $columns,
                    true
                )
            ) {
                DB::table('permissions')
                    ->where(
                        'name',
                        'booking_profitability.view'
                    )
                    ->delete();
            }
        } catch (\Throwable) {
        }
    }
};
