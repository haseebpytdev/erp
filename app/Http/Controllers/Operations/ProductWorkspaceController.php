<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingEditLockResolver;
use App\Services\Operations\DedicatedProductTimingContext;
use App\Services\Operations\NativeBookingCustomerResolver;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

final class ProductWorkspaceController extends Controller
{
    private const PRODUCTS = ['air', 'hotel', 'transport', 'visa', 'other-services'];

    public function show(Request $request, int $booking, string $product, NativeErpLayoutResolver $layout, NativeBookingCustomerResolver $customer, BookingEditLockResolver $locks): View
    {
        $data = $this->workspaceData($request, $booking, $product, $layout, $customer, $locks, true);
        $timing = DedicatedProductTimingContext::forRequest($request);
        $timing?->start('view_object_create');
        $view = view('operations.bookings.product-workspace-v113305', $data);
        $timing?->stop('view_object_create');
        return $view;
    }

    public function fragment(Request $request, int $booking, NativeErpLayoutResolver $layout, NativeBookingCustomerResolver $customer, BookingEditLockResolver $locks): View
    {
        $data = $this->workspaceData($request, $booking, 'air', $layout, $customer, $locks, false);
        return view('operations.bookings.partials.product-workspace-v113305', $data);
    }

    private function workspaceData(Request $request, int $booking, string $product, NativeErpLayoutResolver $layout, NativeBookingCustomerResolver $customer, BookingEditLockResolver $locks, bool $resolveLayout): array
    {
        $timing = DedicatedProductTimingContext::forRequest($request);
        $timing?->start('controller_total');

        abort_unless(in_array($product, self::PRODUCTS, true), 404);
        $schemaHasBookings = $timing
            ? $timing->measure('schema_has_bookings', static fn (): bool => Schema::hasTable('bookings'))
            : Schema::hasTable('bookings');
        abort_unless($schemaHasBookings, 404);
        $row = $timing
            ? $timing->measure('booking_query', static fn () => DB::table('bookings')->where('id', $booking)->first())
            : DB::table('bookings')->where('id', $booking)->first();
        abort_unless($row, 404);
        $booking = (array) $row;

        $layoutMeta = $resolveLayout
            ? ($timing
                ? $timing->measure('layout_resolve', fn (): array => $layout->resolve())
                : $layout->resolve())
            : [];
        $customerIdentity = $timing
            ? $timing->measure('customer_resolve', fn (): array => $customer->resolve((int) $row->id, $timing))
            : $customer->resolve((int) $row->id);
        $lock = $timing
            ? $timing->measure('lock_from_row', fn (): array => $locks->fromRow($booking))
            : $locks->fromRow($booking);

        $timing?->stop('controller_total');

        return [
            'layoutMeta' => $layoutMeta,
            'bookingId' => (int) $row->id,
            'booking' => $booking,
            'customer' => $customerIdentity,
            'lock' => $lock,
            'product' => $product,
            'selectedProducts' => [],
        ];
    }
}
