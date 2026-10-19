<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;

/** Builds a privacy-safe audit payload; it does not write audit_logs. */
final class AirCancellationAuditContract
{
    /** @return array<string,mixed> */
    public function payload(mixed $user, array|object $booking, array|object $service, string $action, string $reason, string $newStatus): array
    {
        $actorId = $this->userId($user);
        $companyId = (int) $this->value($booking, 'company_id');
        $branchId = (int) $this->value($booking, 'branch_id');
        $bookingId = (int) $this->value($booking, 'id');
        $serviceId = (int) $this->value($service, 'id');
        $previousStatus = $this->value($service, 'status');
        if ((int) $this->value($service, 'booking_id') !== $bookingId) {
            throw new \InvalidArgumentException('Air cancellation audit service does not belong to the booking.');
        }
        if (! in_array(strtolower(trim($newStatus)), ['cancelled', 'canceled', 'inactive', 'removed'], true)) {
            throw new \InvalidArgumentException('Air cancellation audit transition is not a supported retirement status.');
        }
        if ($actorId <= 0 || $companyId <= 0 || $branchId <= 0 || $bookingId <= 0 || $serviceId <= 0 || trim($action) === '' || trim($reason) === '' || mb_strlen(trim($reason)) > 500 || mb_strlen(trim($action)) > 120) {
            throw new \InvalidArgumentException('Complete Air cancellation audit context is required.');
        }
        return [
            'user_id' => $actorId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'booking_id' => $bookingId,
            'booking_service_id' => $serviceId,
            'action' => trim($action),
            'previous_status' => $this->status($previousStatus),
            'new_status' => $this->status($newStatus),
            'reason' => trim($reason),
            'created_at' => CarbonImmutable::now(),
        ];
    }

    /** Map the contract into the installed audit_logs column contract. */
    public function nativePayload(array $contract, ?string $ip = null, ?string $userAgent = null): array
    {
        foreach (['user_id', 'company_id', 'branch_id', 'booking_id', 'booking_service_id', 'action', 'reason'] as $key) {
            if (! array_key_exists($key, $contract)) throw new \InvalidArgumentException('Incomplete Air cancellation audit contract.');
        }
        return [
            'user_id' => (int) $contract['user_id'],
            'event' => trim((string) $contract['action']),
            'auditable_type' => 'App\\Models\\BookingService',
            'auditable_id' => (int) $contract['booking_service_id'],
            'old_values' => json_encode([
                'company_id' => (int) $contract['company_id'], 'branch_id' => (int) $contract['branch_id'],
                'booking_id' => (int) $contract['booking_id'], 'booking_service_id' => (int) $contract['booking_service_id'],
                'status' => $contract['previous_status'], 'reason' => (string) $contract['reason'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode(['status' => $contract['new_status']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'created_at' => $contract['created_at'],
        ];
    }

    private function status(?string $status): ?string
    {
        if ($status === null) return null;
        $status = trim($status);
        if ($status === '' || mb_strlen($status) > 80) throw new \InvalidArgumentException('Air cancellation status is invalid.');
        return $status;
    }

    private function userId(mixed $user): int
    { return (int) (is_object($user) && method_exists($user, 'getAttribute') ? $user->getAttribute('id') : ($user->id ?? 0)); }

    private function value(array|object $row, string $key): mixed
    { return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null); }
}
