<?php

namespace App\Services\Purchase;

use App\Services\Accounting\CashVoucherDrilldownResolver;
use Illuminate\Support\Facades\Route;
use Throwable;

final class SupplierCostingDrilldownResolver
{
    public function __construct(private readonly CashVoucherDrilldownResolver $accounting) {}

    public function bookingUrl(?int $bookingId): ?string
    {
        if (! $bookingId) return null;
        try {
            $route = Route::getRoutes()->getByName('bookings.review.show');
            if (! $route || ! in_array('GET', $route->methods(), true) || count($route->parameterNames()) !== 1) return null;
            return route('bookings.review.show', [$route->parameterNames()[0] => $bookingId]);
        } catch (Throwable) {
            return null;
        }
    }

    public function supplierLedgerUrl(?int $supplierId): ?string
    {
        if (! $supplierId) return null;
        foreach (['accounting.ledgers.supplier', 'accounting.ledgers.vendor'] as $name) {
            try {
                $route = Route::getRoutes()->getByName($name);
                if (! $route || ! in_array('GET', $route->methods(), true) || count($route->parameterNames()) !== 1) continue;
                return route($name, [$route->parameterNames()[0] => $supplierId]);
            } catch (Throwable) {
            }
        }
        return null;
    }

    public function accountLedgerUrl(string $accountCode): ?string
    {
        return $this->accounting->accountLedgerUrl($accountCode);
    }

    public function journalUrl(int $costingId): ?string
    {
        return $this->accounting->nativeJournalUrlForSupplierCosting($costingId);
    }
}
