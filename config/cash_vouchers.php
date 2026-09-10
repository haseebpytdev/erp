<?php

return [
    'accounts' => [
        'accounts_receivable' => ['code' => '1130', 'name' => 'Customer Receivables'],
        'accounts_payable' => ['code' => '2110', 'name' => 'Vendor Payables'],
        'customer_advances' => ['code' => '2120', 'name' => 'Customer Advances'],
        'supplier_advances' => ['code' => '1140', 'name' => 'Vendor Advances'],
    ],
    'fallback_cash_bank_accounts' => [
        ['code' => '1010', 'name' => 'Cash'],
        ['code' => '1020', 'name' => 'Bank'],
    ],
    'payment_methods' => ['Cash', 'Bank Transfer', 'Cheque', 'Card', 'Online', 'Other'],
    'transfer_methods' => ['Cash Transfer', 'Bank Transfer', 'Cheque', 'Online', 'Other'],
    'proof_max_kb' => 5120,
];
