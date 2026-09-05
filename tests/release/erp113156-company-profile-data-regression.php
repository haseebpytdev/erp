<?php

require_once __DIR__.'/../../app/Services/Operations/ClientVoucherFooterResolver.php';
require_once __DIR__.'/../../app/Services/Operations/VisaMasterRelationshipResolver.php';
require_once __DIR__.'/../../app/Services/Organization/CompanyProfileSnapshotService.php';

use App\Services\Operations\ClientVoucherFooterResolver;
use App\Services\Operations\VisaMasterRelationshipResolver;
use App\Services\Organization\CompanyProfileSnapshotService;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    $checks++;
};

$footer = new ClientVoucherFooterResolver();
$assert($footer->resolve([['saudi_company_footer' => 'Saudi contacts']], 'Company contacts') === 'Saudi contacts', 'non-empty relationship footer overrides Company Profile');
$assert($footer->resolve([['saudi_company_footer' => null]], 'Company contacts') === 'Company contacts', 'NULL relationship footer falls back to Company Profile');
$assert($footer->resolve([['saudi_company_footer' => '']], 'Company contacts') === 'Company contacts', 'empty relationship footer falls back to Company Profile');
$assert($footer->resolve([['saudi_company_footer' => " \n\t "]], 'Company contacts') === 'Company contacts', 'whitespace relationship footer falls back to Company Profile');
$assert($footer->resolve([], '') === '', 'both empty omit the footer');
$assert($footer->resolve([['saudi_company_footer' => '<strong onclick="bad()">KSA</strong><script>bad()</script>']], '') === '<strong>KSA</strong>bad()', 'saved HTML keeps approved formatting and strips unsafe tags/attributes');

$relationship = new VisaMasterRelationshipResolver();
$resolved = $relationship->resolve(
    [['id' => 2, 'master_key' => 'travel_voucher_partners:2', 'source_table' => 'travel_voucher_partners', 'name' => 'Pakistan IATA', 'vendor_id' => 15, 'voucher_footer' => 'IATA contact']],
    [['id' => 3, 'master_key' => 'travel_voucher_partners:3', 'source_table' => 'travel_voucher_partners', 'name' => 'Saudi Company', 'linked_iata_id' => 2]],
    [['id' => 15, 'name' => 'Vendor']]
);
$assert(($resolved['saudis'][0]['voucher_footer'] ?? '') === 'IATA contact', 'native Pakistan IATA footer propagates through the selected Saudi relationship');

$profile = new CompanyProfileSnapshotService();
$logoMethod = new ReflectionMethod($profile, 'logoUrl');
$dataLogo = 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFFtest");
$assert($logoMethod->invoke($profile, $dataLogo) === $dataLogo, 'saved native data-URI logo renders unchanged');
$assert(str_starts_with((string) $logoMethod->invoke($profile, "\x89PNG\x0D\x0A\x1A\x0Atest"), 'data:image/png;base64,'), 'saved binary logo becomes a print-safe data URI');
$assert($logoMethod->invoke($profile, null) === null, 'missing Company Profile logo permits the fallback mark');

echo "ERP-11.3.156 Company Profile data regression checks passed: {$checks}\n";
