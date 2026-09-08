<?php

declare(strict_types=1);

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE booking_services (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER NOT NULL, product_service_id INTEGER NOT NULL, service_name TEXT NOT NULL, status TEXT NOT NULL, is_active INTEGER NOT NULL)');

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) throw new RuntimeException($message);
    $checks++;
};
$active = static function (PDO $db): array {
    return $db->query("SELECT * FROM booking_services WHERE booking_id=13 AND product_service_id=41 AND is_active=1 AND status NOT IN ('deleted','removed','inactive','cancelled','canceled') ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
};
$activate = static function (PDO $db) use ($active): int {
    $rows = $active($db);
    if ($rows) return (int) $rows[0]['id'];
    $db->prepare("INSERT INTO booking_services (booking_id,product_service_id,service_name,status,is_active) VALUES (13,41,'Transport','active',1)")->execute();
    return (int) $db->lastInsertId();
};
$retire = static function (PDO $db) use ($active): void {
    foreach ($active($db) as $row) $db->prepare("UPDATE booking_services SET is_active=0,status='inactive' WHERE id=?")->execute([(int) $row['id']]);
};

// Air, Hotel and Visa remain active; the previous Transport row is retired.
$db->exec("INSERT INTO booking_services (booking_id,product_service_id,service_name,status,is_active) VALUES (13,1,'Air Ticket','active',1),(13,2,'Hotel','active',1),(13,3,'Visa','active',1),(13,41,'Transport','inactive',0)");
$assert(count($active($db)) === 0, 'retired Transport is not an active authority');
$first = $activate($db);
$assert($first > 0 && count($active($db)) === 1, 'Add Transport creates one active native service');
$assert((int) $active($db)[0]['product_service_id'] === 41, 'active service retains native Transport product-service authority');
$second = $activate($db);
$assert($second === $first && count($active($db)) === 1, 'second Add Transport does not duplicate the active service');
$assert((int) $db->query("SELECT count(*) FROM booking_services WHERE booking_id=13 AND product_service_id IN (1,2,3) AND is_active=1")->fetchColumn() === 3, 'Transport lifecycle does not mutate Air, Hotel or Visa');
$retire($db);
$assert(count($active($db)) === 0, 'Remove Transport retires only the active Transport service');
$third = $activate($db);
$assert($third !== $first && count($active($db)) === 1, 'Add Transport again creates one fresh active service');

echo "ERP-11.3.175 Transport selection lifecycle: {$checks} assertions passed.\n";
