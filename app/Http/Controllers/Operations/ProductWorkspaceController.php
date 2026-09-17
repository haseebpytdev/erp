<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingEditLockResolver;
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
        abort_unless(in_array($product, self::PRODUCTS, true), 404);
        abort_unless(Schema::hasTable('bookings'), 404);
        $row = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($row, 404);
        $booking = (array) $row;

        return view('operations.bookings.product-workspace-v113305', [
            'layoutMeta' => $layout->resolve(),
            'bookingId' => (int) $row->id,
            'booking' => $booking,
            'customer' => $customer->resolve((int) $row->id),
            'lock' => $locks->fromRow($booking),
            'product' => $product,
            'selectedProducts' => [],
        ]);
    }
}
