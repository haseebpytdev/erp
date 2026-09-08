<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class BookingEditLockResolver
{
    public function resolve(int $bookingId): array
    {
        if ($bookingId <= 0 || ! Schema::hasTable('bookings')) {
            return ['locked'=>false,'status'=>'Draft','travel_status'=>'PendingTravel','reason'=>''];
        }

        $row = DB::table('bookings')->where('id', $bookingId)->first();
        if (! $row) return ['locked'=>false,'status'=>'Draft','travel_status'=>'PendingTravel','reason'=>''];

        return $this->fromRow((array) $row);
    }

    public function fromRow(array $row): array
    {
        $approval = $this->value($row, ['approval_status','workflow_status','booking_status','status']);
        $travel = $this->value($row, ['travel_status','readiness_status','travel_readiness_status']);
        $approvalKey = $this->key($approval ?: 'draft');
        $travelKey = $this->key($travel ?: 'pendingtravel');
        $pending = in_array($approvalKey, ['pending','pending approval','submitted','awaiting approval'], true);
        $approved = in_array($approvalKey, ['approved','confirmed'], true);
        $ready = in_array($travelKey, ['ready','travel ready','travelready'], true);
        $locked = $pending || $approved || $ready;
        $label = $ready ? 'Travel Ready' : ($approved ? 'Approved' : ($pending ? 'Pending Approval' : $this->label($approvalKey)));

        return [
            'locked'=>$locked,
            'status'=>$label,
            'travel_status'=>$ready ? 'Ready' : ($travel ?: 'PendingTravel'),
            'reason'=>$locked
                ? ($ready ? 'Travel Ready — booking is locked. Reopen the booking before making changes.'
                    : $label.' booking — editing is locked. Reopen the booking to make changes.')
                : '',
        ];
    }

    private function value(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function key(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['_','-'], ' ', strtolower($value))) ?? '');
    }

    private function label(string $key): string
    {
        return ucwords($key ?: 'draft');
    }
}
