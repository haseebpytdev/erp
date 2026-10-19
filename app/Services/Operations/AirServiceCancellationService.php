<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Internal-only transactional Air service cancellation command.
 * It is intentionally not registered on a route or called by the Air editor.
 */
final class AirServiceCancellationService
{
    private const INTENT = 'AIR_SERVICE_CANCELLATION';
    private const EVENT = 'AIR_SERVICE_CANCELLED';
    private const DRAFT_TICKET_STATES = ['DRAFT', 'BOOKED', 'PENDING', 'ACTIVE', 'NEW', 'OPEN'];

    public function __construct(
        private readonly AirServiceRemovalAuthorization $authorization,
        private readonly AirServiceRemovalConcurrencyContract $concurrency,
        private readonly AirServiceRemovalEligibilityResolver $eligibility,
        private readonly AirCancellationAuditContract $audit,
    ) {
    }

    /** Read-only operational check used before issuing a cancellation token. */
    public function previewOperationalState(int $serviceId): array
    {
        return $this->ticketHistoryAllowsCancellation($serviceId);
    }

    /** @return array{outcome:string,code:string,booking_id:int,booking_service_id:int,status?:string} */
    public function cancel(array $command, mixed $actor): array
    {
        $input = $this->validateCommand($command, $actor);
        if (! $input['ok']) return $input['result'];
        $bookingId = $input['booking_id'];
        $serviceId = $input['booking_service_id'];

        try {
            return DB::transaction(function () use ($command, $actor, $bookingId, $serviceId): array {
                if (! Schema::hasTable('bookings') || ! Schema::hasTable('booking_services')) return $this->blocked($bookingId, $serviceId, 'UNKNOWN_SCHEMA');
                $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
                $service = DB::table('booking_services')->where('id', $serviceId)->where('booking_id', $bookingId)->lockForUpdate()->first();
                if (! $booking || ! $service) return $this->blocked($bookingId, $serviceId, 'BOOKING_SERVICE_MISMATCH');

                $auth = $this->authorization->authorize($actor, $bookingId, $serviceId);
                if (empty($auth['authorized'])) return $this->blocked($bookingId, $serviceId, (string) ($auth['code'] ?? 'UNAUTHORIZED'));
                $snapshot = $this->concurrency->snapshotForLockedRow($service);
                if (! $this->concurrency->validateLockedToken(['token' => $command['concurrency_token']], $snapshot)) return $this->blocked($bookingId, $serviceId, 'STALE_SERVICE_STATE');
                if (in_array(strtolower((string) ($service->status ?? '')), ['cancelled', 'canceled', 'deleted', 'removed'], true)) return $this->blocked($bookingId, $serviceId, 'ALREADY_CANCELLED');
                $cancelledStatus = $this->cancelledStatusValue();
                if ($cancelledStatus === null) return $this->blocked($bookingId, $serviceId, 'CANCELLED_STATUS_UNSUPPORTED');
                $financial = $this->eligibility->resolve($bookingId, $serviceId, $actor);
                if (empty($financial['eligible'])) return $this->blocked($bookingId, $serviceId, (string) ($financial['code'] ?? 'UNKNOWN_DEPENDENCY'));
                $ticketCheck = $this->ticketHistoryAllowsCancellation($serviceId);
                if (! $ticketCheck['ok']) return $this->blocked($bookingId, $serviceId, $ticketCheck['code']);
                if (! Schema::hasTable('audit_logs')) return $this->blocked($bookingId, $serviceId, 'AUDIT_STORAGE_UNAVAILABLE');
                foreach (['user_id', 'event', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'created_at'] as $column) if (! Schema::hasColumn('audit_logs', $column)) return $this->blocked($bookingId, $serviceId, 'AUDIT_SCHEMA_UNAVAILABLE');

                $audit = $this->audit->payload($actor, $booking, $service, self::EVENT, $command['cancellation_reason'], $cancelledStatus);
                $updated = ['status' => $cancelledStatus];
                if (Schema::hasColumn('booking_services', 'updated_at')) $updated['updated_at'] = now();
                $changed = DB::table('booking_services')->where('id', $serviceId)->where('booking_id', $bookingId)->update($updated);
                if ($changed !== 1) throw new \RuntimeException('Air service cancellation status was not persisted.');
                DB::table('audit_logs')->insert($this->audit->nativePayload($audit));
                return ['outcome' => 'SUCCESS', 'code' => self::EVENT, 'booking_id' => $bookingId, 'booking_service_id' => $serviceId, 'status' => $cancelledStatus];
            });
        } catch (\Throwable) {
            return ['outcome' => 'UNKNOWN', 'code' => 'TRANSACTION_OUTCOME_UNKNOWN', 'booking_id' => $bookingId, 'booking_service_id' => $serviceId];
        }
    }

    private function validateCommand(array $command, mixed $actor): array
    {
        $bookingId = (int) ($command['booking_id'] ?? 0);
        $serviceId = (int) ($command['booking_service_id'] ?? 0);
        $reason = trim((string) ($command['cancellation_reason'] ?? ''));
        $valid = $actor && $bookingId > 0 && $serviceId > 0
            && ($command['removal_intent'] ?? null) === self::INTENT
            && ($command['confirmation_acknowledged'] ?? null) === true
            && is_string($command['concurrency_token'] ?? null) && trim($command['concurrency_token']) !== '' && strlen($command['concurrency_token']) <= 512
            && $reason !== '' && mb_strlen($reason) <= 500;
        return $valid
            ? ['ok' => true, 'booking_id' => $bookingId, 'booking_service_id' => $serviceId]
            : ['ok' => false, 'result' => $this->blocked($bookingId, $serviceId, 'INVALID_CANCELLATION_COMMAND')];
    }

    private function ticketHistoryAllowsCancellation(int $serviceId): array
    {
        if (! Schema::hasTable('air_ticket_details')) return ['ok' => false, 'code' => 'UNKNOWN_TICKET_SCHEMA'];
        $columns = Schema::getColumnListing('air_ticket_details');
        $status = in_array('ticket_status', $columns, true) ? 'ticket_status' : (in_array('status', $columns, true) ? 'status' : null);
        $rows = DB::table('air_ticket_details')->where('booking_service_id', $serviceId)->get($status ? [$status] : ['booking_service_id']);
        if ($rows->isNotEmpty() && ! $status) return ['ok' => false, 'code' => 'UNKNOWN_TICKET_STATUS'];
        foreach ($rows as $row) if (! in_array(strtoupper(trim((string) ($row->{$status} ?? ''))), self::DRAFT_TICKET_STATES, true)) return ['ok' => false, 'code' => 'TICKET_HISTORY_PROTECTED'];
        return ['ok' => true, 'code' => 'TICKET_HISTORY_CLEAR'];
    }

    private function cancelledStatusValue(): ?string
    {
        if (! Schema::hasColumn('booking_services', 'status')) return null;
        try {
            $row = DB::selectOne("SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_services' AND COLUMN_NAME = 'status' LIMIT 1");
            if (! $row) return null;
            $type = strtolower((string) ($row->data_type ?? ''));
            $columnType = strtoupper((string) ($row->column_type ?? ''));
            if ($type !== 'enum') return 'CANCELLED';
            if (str_contains($columnType, "'CANCELLED'")) return 'CANCELLED';
            if (str_contains($columnType, "'cancelled'")) return 'cancelled';
            if (str_contains($columnType, "'CANCELED'")) return 'CANCELED';
            if (str_contains($columnType, "'canceled'")) return 'canceled';
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function blocked(int $bookingId, int $serviceId, string $code): array
    { return ['outcome' => 'BLOCKED', 'code' => $code, 'booking_id' => $bookingId, 'booking_service_id' => $serviceId]; }
}
