<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_group_package_unified')) {
            Schema::table('booking_group_package_unified', function (Blueprint $table): void {
                if (! Schema::hasColumn('booking_group_package_unified', 'total_hotel_nights')) {
                    $table->unsignedInteger('total_hotel_nights')->default(0);
                }
            });
        }
        if (Schema::hasTable('booking_group_package_hotels')) {
            Schema::table('booking_group_package_hotels', function (Blueprint $table): void {
                if (! Schema::hasColumn('booking_group_package_hotels', 'nights')) {
                    $table->unsignedInteger('nights')->default(0);
                }
            });
        }
        if (Schema::hasTable('booking_group_package_transports')) {
            Schema::table('booking_group_package_transports', function (Blueprint $table): void {
                if (! Schema::hasColumn('booking_group_package_transports', 'route_name')) $table->string('route_name', 255)->nullable();
                if (! Schema::hasColumn('booking_group_package_transports', 'company_name')) $table->string('company_name', 180)->nullable();
                if (! Schema::hasColumn('booking_group_package_transports', 'contact_number')) $table->string('contact_number', 80)->nullable();
                if (! Schema::hasColumn('booking_group_package_transports', 'brn_number')) $table->string('brn_number', 120)->nullable();
            });
        }
    }
    public function down(): void
    {
        if (Schema::hasTable('booking_group_package_transports')) {
            $drop = [];
            foreach (['route_name','company_name','contact_number','brn_number'] as $column) if (Schema::hasColumn('booking_group_package_transports',$column)) $drop[]=$column;
            if ($drop) Schema::table('booking_group_package_transports', function (Blueprint $table) use ($drop): void { $table->dropColumn($drop); });
        }
        if (Schema::hasTable('booking_group_package_hotels') && Schema::hasColumn('booking_group_package_hotels','nights')) Schema::table('booking_group_package_hotels', function (Blueprint $table): void { $table->dropColumn('nights'); });
        if (Schema::hasTable('booking_group_package_unified') && Schema::hasColumn('booking_group_package_unified','total_hotel_nights')) Schema::table('booking_group_package_unified', function (Blueprint $table): void { $table->dropColumn('total_hotel_nights'); });
    }
};
