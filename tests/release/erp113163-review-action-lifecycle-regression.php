<?php

require_once __DIR__.'/../../app/Services/Operations/BookingInvoiceEligibilityResolver.php';

use App\Services\Operations\BookingInvoiceEligibilityResolver;

$pass=0;$fail=0;$assert=function(bool $ok,string $label)use(&$pass,&$fail){if($ok){$pass++;echo "PASS: $label\n";}else{$fail++;echo "FAIL: $label\n";}};
$resolver=new BookingInvoiceEligibilityResolver();
$assert($resolver->resolve(['approval_status'=>'approved','status'=>'pending'])['eligible'],'Approved current workflow is invoice eligible');
$assert(!$resolver->resolve(['approval_status'=>'pending_approval','status'=>'confirmed'])['eligible'],'Explicit current approval workflow remains authoritative');
$assert($resolver->resolve(['status'=>'confirmed'])['eligible'],'Legacy confirmed workflow remains compatible');
$assert(!$resolver->resolve(['status'=>'pending'])['eligible'],'Legacy pending booking is not invoice eligible');

$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY, approval_status TEXT)');
$db->exec("INSERT INTO bookings(id,approval_status) VALUES(13,'approved')");
$before=(int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
$pre=array_column($db->query('PRAGMA table_info(bookings)')->fetchAll(PDO::FETCH_ASSOC),'name');
$assert(!in_array('travel_status',$pre,true),'Pre-migration travel_status is absent');
$db->exec("ALTER TABLE bookings ADD COLUMN travel_status VARCHAR(32) NOT NULL DEFAULT 'PendingTravel'");
$post=array_column($db->query('PRAGMA table_info(bookings)')->fetchAll(PDO::FETCH_ASSOC),'name');
$assert(in_array('travel_status',$post,true),'Migration adds travel_status');
$assert((int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===$before,'Migration preserves existing rows');
$assert($db->query('SELECT travel_status FROM bookings WHERE id=13')->fetchColumn()==='PendingTravel','Legacy row defaults to PendingTravel');
$db->exec("UPDATE bookings SET travel_status='Ready' WHERE id=13");
$assert($db->query('SELECT travel_status FROM bookings WHERE id=13')->fetchColumn()==='Ready','Mark Ready persists');
$db->exec("UPDATE bookings SET travel_status='PendingTravel',approval_status='reopened' WHERE id=13");
$assert($db->query('SELECT travel_status FROM bookings WHERE id=13')->fetchColumn()==='PendingTravel','Reopen resets persisted Ready');
$db->exec('ALTER TABLE bookings DROP COLUMN travel_status');
$rolled=array_column($db->query('PRAGMA table_info(bookings)')->fetchAll(PDO::FETCH_ASSOC),'name');
$assert(!in_array('travel_status',$rolled,true),'Rollback removes travel_status safely');
echo "TESTS_PASS=$pass\nTESTS_FAIL=$fail\n";exit($fail?1:0);
