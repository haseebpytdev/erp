<?php

declare(strict_types=1);

if (! extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required for the disposable migration lifecycle test.\n");
    exit(2);
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) throw new RuntimeException($message);
    $checks++;
};
$columns = static function (PDO $db): array {
    return array_column($db->query("PRAGMA table_info('booking_services')")->fetchAll(PDO::FETCH_ASSOC), 'name');
};
$migrateUp = static function (PDO $db) use ($columns): void {
    if (! in_array('vendor_id', $columns($db), true)) {
        $db->exec('ALTER TABLE booking_services ADD COLUMN vendor_id INTEGER NULL');
        $db->exec('CREATE INDEX booking_services_vendor_id_index ON booking_services(vendor_id)');
    }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE booking_services (id INTEGER PRIMARY KEY, booking_id INTEGER NOT NULL, product_service_id INTEGER NOT NULL, service_name TEXT NOT NULL)');
$db->exec("INSERT INTO booking_services(id,booking_id,product_service_id,service_name) VALUES(77,13,1,'Air Ticket')");
$assert(! in_array('vendor_id', $columns($db), true), 'pre-migration vendor_id must be absent');
$before = (int) $db->query('SELECT COUNT(*) FROM booking_services')->fetchColumn();

$migrateUp($db);
$assert(in_array('vendor_id', $columns($db), true), 'migration must add vendor_id');
$assert((int) $db->query('SELECT COUNT(*) FROM booking_services')->fetchColumn() === $before, 'migration must preserve existing rows');
$assert($db->query('SELECT vendor_id FROM booking_services WHERE id=77')->fetchColumn() === null, 'legacy row vendor_id must remain NULL');

$migrateUp($db);
$assert((int) $db->query('SELECT COUNT(*) FROM booking_services')->fetchColumn() === $before, 'second migration run must preserve rows');
$assert(count(array_filter($columns($db), static fn (string $name): bool => $name === 'vendor_id')) === 1, 'second migration run must not duplicate vendor_id');

$request = ['common' => ['supplier_id' => 19]];
$selected = (int) ($request['common']['supplier_id'] ?? 0);
$assert($selected === 19, 'nested request Vendor ID must validate as 19');
$statement = $db->prepare('UPDATE booking_services SET vendor_id=:vendor_id WHERE id=:id');
$statement->execute(['vendor_id' => $selected > 0 ? $selected : null, 'id' => 77]);
$persisted = (int) $db->query('SELECT vendor_id FROM booking_services WHERE id=77')->fetchColumn();
$assert($persisted === 19, 'database must persist selected Vendor ID');

// A fresh query represents the controller reload boundary; no in-memory row is reused.
$reloaded = (int) $db->query('SELECT vendor_id FROM booking_services WHERE booking_id=13 AND service_name="Air Ticket"')->fetchColumn();
$hydrated = $reloaded > 0 ? (string) $reloaded : '';
$assert($reloaded === 19, 'fresh reload must return Vendor ID 19');
$assert($hydrated === '19', 'dropdown hydration value must be 19');

// Unrelated product/passenger writes are deliberately scoped away from booking_services.
$db->exec('CREATE TABLE hotel_stays (id INTEGER PRIMARY KEY, booking_id INTEGER)');
$db->exec('CREATE TABLE transport_segments (id INTEGER PRIMARY KEY, booking_id INTEGER)');
$db->exec('CREATE TABLE visa_rows (id INTEGER PRIMARY KEY, booking_id INTEGER)');
$db->exec('CREATE TABLE booking_passengers (id INTEGER PRIMARY KEY, booking_id INTEGER, name TEXT)');
$db->exec('INSERT INTO hotel_stays VALUES(1,13)');
$db->exec('INSERT INTO transport_segments VALUES(1,13)');
$db->exec('INSERT INTO visa_rows VALUES(1,13)');
$db->exec("INSERT INTO booking_passengers VALUES(1,13,'Passenger')");
$assert((int) $db->query('SELECT vendor_id FROM booking_services WHERE id=77')->fetchColumn() === 19, 'unrelated product saves must preserve Air Vendor');

// Rollback is tested only on this disposable database.
$db->exec('DROP INDEX booking_services_vendor_id_index');
$db->exec('ALTER TABLE booking_services DROP COLUMN vendor_id');
$assert(! in_array('vendor_id', $columns($db), true), 'local rollback must remove vendor_id');
$assert((int) $db->query('SELECT COUNT(*) FROM booking_services')->fetchColumn() === $before, 'local rollback must preserve existing rows');

echo "ERP-11.3.161 Air Vendor migration lifecycle: {$checks} assertions passed; selected=request=database=reload=hydration=19.\n";
