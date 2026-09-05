<?php

require_once __DIR__ . '/../../app/Services/Operations/VisaMasterRelationshipResolver.php';

use App\Services\Operations\VisaMasterRelationshipResolver;

$resolver = new VisaMasterRelationshipResolver();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $checks++;
};

$vendors = [
    ['id' => 41, 'name' => 'Travel Victory International'],
    ['id' => 52, 'name' => 'Another Vendor'],
];
$iatas = [
    ['id' => 7, 'source_table' => 'travel_partners', 'master_key' => 'travel_partners:7', 'name' => 'Victory IATA', 'vendor_id' => 41],
    ['id' => 8, 'source_table' => 'travel_partners', 'master_key' => 'travel_partners:8', 'name' => 'Name Linked IATA', 'vendor_id' => 0, 'vendor_reference' => 'Travel Victory International'],
    ['id' => 9, 'source_table' => 'travel_partners', 'master_key' => 'travel_partners:9', 'name' => 'Broken IATA', 'vendor_id' => 999],
];
$saudis = [
    ['id' => 20, 'source_table' => 'travel_partners', 'master_key' => 'travel_partners:20', 'name' => 'Saudi Ready', 'linked_iata_id' => 7],
    ['id' => 21, 'source_table' => 'travel_partners', 'master_key' => 'travel_partners:21', 'name' => 'Saudi Vendor Missing', 'linked_iata_id' => 9],
    ['id' => 22, 'source_table' => 'travel_partners', 'master_key' => 'travel_partners:22', 'name' => 'Saudi IATA Missing', 'linked_iata_id' => 999],
];

$result = $resolver->resolve($iatas, $saudis, $vendors);
$iataById = array_column($result['iatas'], null, 'id');
$saudiById = array_column($result['saudis'], null, 'id');

$assert($iataById[7]['vendor_name'] === 'Travel Victory International', 'linked Pakistani IATA resolves the legitimate Vendor Party');
$assert($iataById[7]['status'] === 'READY', 'valid Pakistani IATA status is READY');
$assert($iataById[8]['vendor_id'] === 41, 'exact native Vendor name reference resolves to its Party ID');
$assert($iataById[9]['vendor_id'] === 0, 'unknown positive vendor ID is not treated as a legitimate link');
$assert($iataById[9]['status'] === 'VENDOR LINK REQUIRED', 'broken IATA vendor status is explicit');
$assert($saudiById[20]['status'] === 'READY' && $saudiById[20]['vendor_id'] === 41, 'complete Saudi chain resolves end to end');
$assert($saudiById[20]['link_complete'] === true, 'valid chain permits Visa Rate save');
$assert($saudiById[21]['status'] === 'VENDOR LINK REQUIRED', 'Saudi with broken downstream vendor is blocked');
$assert($saudiById[21]['link_complete'] === false, 'broken chain blocks Visa Rate save');
$assert($saudiById[22]['status'] === 'IATA LINK REQUIRED', 'Saudi with no IATA is blocked');

echo "ERP-11.3.152 relationship regression checks passed: {$checks}\n";
