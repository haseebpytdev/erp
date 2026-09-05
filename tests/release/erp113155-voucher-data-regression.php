<?php

require_once __DIR__.'/../../app/Services/Operations/ClientVoucherFooterResolver.php';
require_once __DIR__.'/../../app/Services/Operations/ClientVoucherPassengerVisaMap.php';

use App\Services\Operations\ClientVoucherFooterResolver;
use App\Services\Operations\ClientVoucherPassengerVisaMap;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    $checks++;
};

$footer = new ClientVoucherFooterResolver();
$assert($footer->resolve([['saudi_company_footer' => 'Saudi contacts']], 'Company footer') === 'Saudi contacts', 'Saudi footer overrides Company Profile footer');
$assert($footer->resolve([['saudi_company_footer' => '  ']], 'Company footer') === 'Company footer', 'blank Saudi footer falls back to Company Profile');
$assert($footer->resolve([], '') === '', 'both blank produces no footer');
$assert($footer->resolve([['saudi_company_footer' => 'First'], ['saudi_company_footer' => 'Second']], 'Company') === 'First', 'only one deterministic Saudi footer is selected');

$mapper = new ClientVoucherPassengerVisaMap();
$rows = [
    ['booking_passenger_id' => 20, 'visa_number' => 'VISA-20'],
    ['booking_passenger_id' => 10, 'visa_number' => 'VISA-10'],
    ['passenger_id' => 30, 'visa_number' => 'LEGACY-30'],
    ['booking_traveller_id' => 40, 'visa_number' => 'LEGACY-40'],
];
$map = $mapper->build($rows);
$assert(($map[10]['visa_number'] ?? '') === 'VISA-10', 'passenger 10 maps only to Visa 10');
$assert(($map[20]['visa_number'] ?? '') === 'VISA-20', 'passenger 20 maps only to Visa 20');
$assert(($map[30]['visa_number'] ?? '') === 'LEGACY-30', 'legacy passenger alias maps safely');
$assert(($map[40]['visa_number'] ?? '') === 'LEGACY-40', 'legacy traveller alias maps safely');
$assert(! isset($map[50]), 'passenger without Visa service has no mapped row');
$assert((string) (($mapper->build([['booking_passenger_id' => 60, 'visa_number' => '']]))[60]['visa_number'] ?? '') === '', 'blank Visa number remains blank');
$assert($mapper->build([]) === [], 'no Visa service produces an empty mapping');

echo "ERP-11.3.155 voucher data regression checks passed: {$checks}\n";
