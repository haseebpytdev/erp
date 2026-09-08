<?php

require_once __DIR__.'/../../app/Services/Operations/BookingEditLockResolver.php';

use App\Services\Operations\BookingEditLockResolver;

$pass=0;$fail=0;
$assert=function(bool $ok,string $label)use(&$pass,&$fail){if($ok){$pass++;echo "PASS: $label\n";}else{$fail++;echo "FAIL: $label\n";}};
$resolver=new BookingEditLockResolver();
$assert(!$resolver->fromRow(['approval_status'=>'draft','travel_status'=>'PendingTravel'])['locked'],'Draft is editable');
$assert(!$resolver->fromRow(['approval_status'=>'reopened','travel_status'=>'PendingTravel'])['locked'],'Reopened is editable');
$assert($resolver->fromRow(['approval_status'=>'pending_approval'])['locked'],'Pending Approval is locked');
$assert($resolver->fromRow(['approval_status'=>'approved'])['locked'],'Approved is locked');
$assert($resolver->fromRow(['approval_status'=>'reopened','travel_status'=>'Ready'])['locked'],'Travel Ready is locked independently');
echo "TESTS_PASS=$pass\nTESTS_FAIL=$fail\n";exit($fail?1:0);
