<?php

namespace App\Services\Operations;

final class NativeSalesInvoiceCreateCapability
{
    public function enabled(): bool
    {
        if (config('general_booking.native_sales_invoice_create_enabled', false) !== true) return false;
        $service=\App\Services\Sales\SalesInvoiceService::class;
        return class_exists($service) && method_exists($service, 'createFromBooking');
    }

    public function disabledMessage(): string
    {
        return 'Native Sales Invoice creation is awaiting runtime verification.';
    }
}
