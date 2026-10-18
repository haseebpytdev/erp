<?php

return [
    'receipt_ai' => [
        'provider' => env('ET_ERP_RECEIPT_AI_PROVIDER', 'openai'),
        'model' => env('ET_ERP_RECEIPT_AI_MODEL'),
        'key' => env('OPENAI_API_KEY'),
        'timeout' => (int) env('ET_ERP_RECEIPT_AI_TIMEOUT', 25),
        'connect_timeout' => (int) env('ET_ERP_RECEIPT_AI_CONNECT_TIMEOUT', 8),
    ],
];
