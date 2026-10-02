<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GeneralBookingAdditionalServiceWorkflowManager
{
    private const MESSAGE = 'Additional Services requires the General Booking Billing database upgrade.';

    public function __construct(private readonly BookingEditLockResolver $locks, private readonly GroupUmrahEditAuthority $authority, private readonly GeneralBookingAdditionalServiceSnapshotIntegrity $integrity) {}

    public function transition(int $bookingId, int $batchId, string $action, mixed $user, ?string $reason = null): array
    {
        $action = strtolower(trim($action)); $userId = (int) ($user?->id ?? 0);
        if (! in_array($action, ['submit', 'approve', 'reject'], true)) return $this->fail('invalid_action', 'Unsupported Additional Services workflow action.');
        if ($userId <= 0) return $this->fail('invalid_user', 'A valid authenticated user is required.');
        if (! $this->integrity->foundationReady()) return $this->fail('schema_not_ready', self::MESSAGE);
        if (in_array($action, ['approve', 'reject'], true) && ! $this->authority->canReopen($user)) return $this->fail('forbidden', 'Only an authorized approver may perform this action.');
        return DB::transaction(function () use ($bookingId, $batchId, $action, $userId, $reason): array {
            $batch = DB::table('general_booking_billing_batches')->where('id', $batchId)->where('booking_id', $bookingId)->lockForUpdate()->first();
            if (! $batch) return $this->fail('batch_missing', 'Supplementary batch was not found.');
            if (strtolower((string) $batch->batch_type) !== 'supplementary') return $this->fail('base_batch', 'The Base batch cannot enter Supplementary workflow.');
            if (DB::table('general_booking_invoice_links')->where('batch_id', $batchId)->exists()) return $this->fail('invoiced', 'An invoiced batch cannot re-enter workflow.');
            if ($action === 'submit' && strtolower((string) $batch->status) === 'pending_approval') return $this->fail('already_pending', 'This batch is already pending approval.');
            if ($action === 'approve' && strtolower((string) $batch->status) === 'approved') return $this->fail('already_approved', 'This batch is already approved.');
            if ($action === 'reject' && strtolower((string) $batch->status) === 'rejected') return $this->fail('already_rejected', 'This batch is already rejected.');
            if (in_array($action, ['submit', 'approve'], true) && ! $this->parentAllows($bookingId)) return $this->fail('parent_locked_state', 'The parent booking must remain Approved or Travel Ready.');
            if ($action === 'submit') return $this->submit($bookingId, $batchId, $batch, $userId);
            if (strtolower((string) $batch->status) !== 'pending_approval') return $this->fail('invalid_state', 'This batch is not pending approval.');
            $frozen = $this->integrity->build($bookingId, $batchId);
            if ((string) $batch->source_snapshot_hash !== (string) $frozen['hash']) return $this->fail('snapshot_integrity_failed', 'The submitted snapshot no longer matches persisted items.');
            if ($action === 'approve') {
                DB::table('general_booking_billing_batches')->where('id', $batchId)->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now(), 'updated_by' => $userId, 'updated_at' => now(), 'lock_version' => ((int) $batch->lock_version) + 1]);
                return ['ok' => true, 'status' => 'approved'];
            }
            $reason = trim((string) $reason); if ($reason === '' || mb_strlen($reason) > 2000) return $this->fail('rejection_reason_required', 'A rejection reason is required.');
            DB::table('general_booking_billing_batches')->where('id', $batchId)->update(['status' => 'rejected', 'rejected_by' => $userId, 'rejected_at' => now(), 'rejection_reason' => $reason, 'updated_by' => $userId, 'updated_at' => now(), 'lock_version' => ((int) $batch->lock_version) + 1]);
            return ['ok' => true, 'status' => 'rejected'];
        });
    }

    private function submit(int $bookingId, int $batchId, object $batch, int $userId): array
    {
        if (strtolower((string) $batch->status) !== 'draft') return $this->fail('invalid_state', 'Only a Draft batch can be submitted.');
        $itemCount = DB::table('general_booking_billing_batch_items')->where('batch_id', $batchId)->count();
        if ($itemCount < 1) return $this->fail('empty_batch', 'Add at least one supplementary item before submitting.');
        if ((float) $batch->customer_total <= 0) return $this->fail('zero_value_batch', 'A positive customer total is required before submitting.');
        $frozen = $this->integrity->build($bookingId, $batchId);
        DB::table('general_booking_billing_batches')->where('id', $batchId)->update(['status' => 'pending_approval', 'submitted_by' => $userId, 'submitted_at' => now(), 'updated_by' => $userId, 'updated_at' => now(), 'source_snapshot_hash' => $frozen['hash'], 'lock_version' => ((int) $batch->lock_version) + 1]);
        return ['ok' => true, 'status' => 'pending_approval'];
    }

    private function parentAllows(int $bookingId): bool
    {
        if (! Schema::hasTable('bookings')) return false;
        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        if (! $booking) return false;
        $status = strtolower((string) ($this->locks->fromRow((array) $booking)['status'] ?? ''));
        return in_array($status, ['approved', 'travel ready'], true);
    }

    private function fail(string $code, string $message): array { return ['ok' => false, 'code' => $code, 'message' => $message]; }
}
