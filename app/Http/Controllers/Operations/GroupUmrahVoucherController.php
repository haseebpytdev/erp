<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\GroupUmrahDocumentNumberService;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class GroupUmrahVoucherController extends Controller
{
    public function __construct(
        private readonly GroupUmrahDocumentNumberService $numbers,
        private readonly UnifiedGroupPackageDataSource $source,
    ) {}

    public function show(Request $request, int $booking): View
    {
        abort_unless(
            Schema::hasTable('bookings')
            && Schema::hasTable('booking_group_package_unified')
            && DB::table('bookings')->where('id', $booking)->exists()
            && DB::table('booking_group_package_unified')->where('booking_id', $booking)->exists(),
            404
        );

        $bookingRow = (array) DB::table('bookings')->where('id', $booking)->first();
        $package = (array) DB::table('booking_group_package_unified')->where('booking_id', $booking)->first();

        $customerId = (int) ($bookingRow['customer_id'] ?? $bookingRow['party_id'] ?? $bookingRow['client_id'] ?? 0);
        $customer = $customerId
            ? $this->source->customers()->first(fn (array $row): bool => (int) ($row['id'] ?? 0) === $customerId)
            : null;

        $branchId = (int) ($bookingRow['branch_id'] ?? 0);
        $branch = $branchId
            ? $this->source->branches()->first(fn (array $row): bool => (int) ($row['id'] ?? 0) === $branchId)
            : null;

        return view('operations.bookings.group-umrah-voucher-v10311', [
            'bookingId' => $booking,
            'booking' => $bookingRow,
            'package' => $package,
            'bookingReference' => $this->numbers->bookingReference($booking),
            'voucherNumber' => $this->numbers->voucherNumber($booking),
            'customerName' => (string) ($customer['name'] ?? $customer['party_name'] ?? 'Customer'),
            'branchName' => (string) ($branch['name'] ?? 'Head Office'),
            'passengers' => $this->rows('booking_group_package_passengers', $booking),
            'flights' => $this->rows('booking_group_package_flights', $booking),
            'hotels' => $this->rows('booking_group_package_hotels', $booking),
            'transports' => $this->rows('booking_group_package_transports', $booking),
            'services' => $this->rows('booking_group_package_services', $booking),
        ]);
    }

    private function rows(string $table, int $bookingId): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $query = DB::table($table)->where('booking_id', $bookingId);

        if (Schema::hasColumn($table, 'sort_order')) {
            $query->orderBy('sort_order');
        }

        return $query->get()->map(fn ($row): array => (array) $row)->all();
    }
}
