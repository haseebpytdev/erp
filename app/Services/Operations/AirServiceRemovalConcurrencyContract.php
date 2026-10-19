<?php

namespace App\Services\Operations;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only stale-state contract for a future cancellation transaction.
 * The caller must execute lockForUpdate() inside its own DB transaction;
 * this class intentionally performs no state change.
 */
final class AirServiceRemovalConcurrencyContract
{
    public function __construct(private readonly AirServiceRemovalEligibilityResolver $eligibility)
    {
    }

    public function capture(int $bookingId, int $serviceId): array
    {
        if ($bookingId <= 0 || $serviceId <= 0 || ! Schema::hasTable('booking_services')) return $this->unknown('INVALID_CONTEXT');
        $columns = Schema::getColumnListing('booking_services');
        foreach (['id', 'booking_id', 'product_service_id', 'status'] as $column) if (! in_array($column, $columns, true)) return $this->unknown('UNKNOWN_SERVICE_STATE');
        $row = DB::table('booking_services')->where('id', $serviceId)->where('booking_id', $bookingId)->first();
        if (! $row) return $this->unknown('BOOKING_SERVICE_MISMATCH');
        return $this->snapshot((array) $row);
    }

    /** Build the signed token from a row already locked by a caller transaction. */
    public function snapshotForLockedRow(array|object $row): array
    { return $this->snapshot(is_array($row) ? $row : (array) $row); }

    public function validateLockedToken(array $expected, array $current): bool
    { return ($current['code'] ?? '') === 'SNAPSHOT' && (string) ($expected['token'] ?? '') !== '' && hash_equals((string) $expected['token'], (string) ($current['token'] ?? '')); }

    /** Compare a previously captured authoritative snapshot with current state. */
    public function validate(array $expected, int $bookingId, int $serviceId, mixed $user = null): array
    {
        $current = $this->capture($bookingId, $serviceId);
        if (($current['code'] ?? '') !== 'SNAPSHOT') return ['valid' => false, 'code' => 'UNKNOWN_SERVICE_STATE', 'snapshot' => $current];
        if ((string) ($expected['token'] ?? '') === '' || ! hash_equals((string) $expected['token'], (string) $current['token'])) return ['valid' => false, 'code' => 'STALE_SERVICE_STATE', 'snapshot' => $current];
        if (in_array(strtolower((string) ($current['status'] ?? '')), ['cancelled', 'canceled', 'deleted', 'removed'], true)) return ['valid' => false, 'code' => 'ALREADY_CANCELLED', 'snapshot' => $current];
        if ($user === null) return ['valid' => false, 'code' => 'FINANCIAL_RECHECK_REQUIRED', 'snapshot' => $current];
        $financial = $this->eligibility->resolve($bookingId, $serviceId, $user);
        if (empty($financial['eligible'])) return ['valid' => false, 'code' => 'FINANCIAL_DEPENDENCY_CHANGED', 'financial' => ['code' => $financial['code'] ?? 'UNKNOWN_DEPENDENCY'], 'snapshot' => $current];
        return ['valid' => true, 'code' => 'CURRENT_SERVICE_STATE', 'financial_recheck_required' => true, 'snapshot' => $current];
    }

    /** Future executor must call this builder inside DB::transaction(). */
    public function lockQuery(int $bookingId, int $serviceId): Builder
    { return DB::table('booking_services')->where('id', $serviceId)->where('booking_id', $bookingId)->lockForUpdate(); }

    private function snapshot(array $row): array
    {
        $state = [
            'id' => (int) ($row['id'] ?? 0),
            'booking_id' => (int) ($row['booking_id'] ?? 0),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'branch_id' => (int) ($row['branch_id'] ?? 0),
            'product_service_id' => (int) ($row['product_service_id'] ?? 0),
            'vendor_id' => (int) ($row['vendor_id'] ?? $row['supplier_id'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'is_active' => $row['is_active'] ?? null,
            'active' => $row['active'] ?? null,
            'customer_total' => $row['customer_total'] ?? $row['sale_total'] ?? $row['selling_total'] ?? null,
            'vendor_total' => $row['vendor_total'] ?? $row['supplier_total'] ?? $row['cost_total'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
        ksort($state);
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = hash('sha256', (string) $json);
        $key = (string) config('app.key');
        if ($key === '') return $this->unknown('APPLICATION_SIGNING_KEY_UNAVAILABLE');
        return ['code' => 'SNAPSHOT', 'state' => $state, 'hash' => $hash, 'token' => hash_hmac('sha256', (string) $json, $key)] + $state;
    }

    private function unknown(string $code): array
    { return ['code' => $code, 'hash' => '', 'token' => '', 'id' => 0, 'booking_id' => 0, 'product_service_id' => 0, 'status' => '']; }
}
