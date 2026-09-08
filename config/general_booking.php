<?php

return [
    /*
     * Accounting write kill-switch. Keep false until the installed host's
     * SalesInvoiceService::createFromBooking contract has been verified.
     */
    'native_sales_invoice_create_enabled' => true,
];
