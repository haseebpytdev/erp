<?php

namespace App\Services\Sales;

use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BookingSalesInvoiceScopeResolver
{
    public function scope(SalesInvoice $invoice): string
    {
        if (! Schema::hasTable('general_booking_invoice_links')) return 'base';

        $linked = DB::table('general_booking_invoice_links')
            ->where('sales_invoice_id', $invoice->getKey())
            ->where('link_type', 'supplementary')
            ->exists();

        return $linked ? 'supplementary' : 'base';
    }
}
