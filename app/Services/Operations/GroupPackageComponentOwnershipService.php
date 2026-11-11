<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class GroupPackageComponentOwnershipService
{
    private const TYPES = ['air', 'hotel', 'visa', 'transport', 'other'];

    /**
     * Replace the selected included-component links for one package inside the
     * caller's transaction. Empty input is a valid package with no inclusions.
     */
    public function replace(int $bookingId, array $components, ?int $actorId = null): void
    {
        if ($bookingId <= 0 || ! Schema::hasTable('booking_group_package_components')) {
            throw ValidationException::withMessages(['components' => 'Package component ownership is unavailable.']);
        }

        $package = DB::table('booking_group_package_unified')
            ->where('booking_id', $bookingId)
            ->lockForUpdate()
            ->first();

        if (! $package) {
            throw ValidationException::withMessages(['components' => 'Save the package commercial record before linking included services.']);
        }

        $normalized = $this->normalize($components);
        $seen = [];
        foreach ($normalized as $component) {
            $key = $component['component_type'].'|'.implode(':', array_filter([
                $component['booking_service_id'] ?? null,
                $component['group_package_flight_id'] ?? null,
                $component['group_package_hotel_id'] ?? null,
                $component['group_package_transport_id'] ?? null,
                $component['group_package_service_id'] ?? null,
            ], static fn ($value): bool => $value !== null));
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['components' => 'Duplicate package component identities are not allowed.']);
            }
            $seen[$key] = true;
            $this->assertComponentBelongsToBooking($component, $bookingId);
        }

        DB::table('booking_group_package_components')
            ->where('booking_group_package_id', $package->id)
            ->delete();

        $now = now();
        foreach ($normalized as $component) {
            DB::table('booking_group_package_components')->insert([
                'booking_group_package_id' => $package->id,
                'booking_id' => $bookingId,
                ...$component,
                'selected' => (bool) ($component['selected'] ?? true),
                'billing_treatment' => 'included_non_billable',
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function normalize(array $components): array
    {
        $out = [];
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            $type = strtolower(trim((string) ($component['component_type'] ?? '')));
            if (! in_array($type, self::TYPES, true)) {
                throw ValidationException::withMessages(['components' => 'Unsupported package component type.']);
            }
            $identityFields = [
                'booking_service_id', 'booking_visa_service_id', 'group_package_flight_id', 'group_package_hotel_id',
                'group_package_transport_id', 'group_package_service_id',
            ];
            $identity = array_filter(
                array_intersect_key($component, array_flip($identityFields)),
                static fn ($value): bool => (int) $value > 0
            );
            if (count($identity) !== 1) {
                throw ValidationException::withMessages(['components' => 'Each package component must have exactly one supported identity.']);
            }
            $identityField = (string) array_key_first($identity);
            $allowedIdentity = [
                'air' => ['booking_service_id', 'group_package_flight_id'],
                'hotel' => ['booking_service_id', 'group_package_hotel_id'],
                'visa' => ['booking_service_id', 'booking_visa_service_id'],
                'transport' => ['booking_service_id', 'group_package_transport_id'],
                'other' => ['booking_service_id', 'group_package_service_id'],
            ][$type];
            if (! in_array($identityField, $allowedIdentity, true)) {
                throw ValidationException::withMessages(['components' => 'The component identity does not match its authoritative type.']);
            }
            $out[] = [
                'component_type' => $type,
                'selected' => (bool) ($component['selected'] ?? true),
                ...array_map(static fn ($value): int => (int) $value, $identity),
            ];
        }
        return $out;
    }

    private function assertComponentBelongsToBooking(array $component, int $bookingId): void
    {
        $tableMap = [
            'booking_service_id' => ['booking_services', 'booking_id'],
            'booking_visa_service_id' => ['booking_visa_services', 'booking_id'],
            'group_package_flight_id' => ['booking_group_package_flights', 'booking_id'],
            'group_package_hotel_id' => ['booking_group_package_hotels', 'booking_id'],
            'group_package_transport_id' => ['booking_group_package_transports', 'booking_id'],
            'group_package_service_id' => ['booking_group_package_services', 'booking_id'],
        ];

        foreach ($tableMap as $field => [$table, $bookingColumn]) {
            if (! isset($component[$field])) {
                continue;
            }
            $row = DB::table($table)->where('id', $component[$field])->lockForUpdate()->first();
            if (! $row || (int) ($row->{$bookingColumn} ?? 0) !== $bookingId) {
                throw ValidationException::withMessages(['components' => 'A package component does not belong to this booking.']);
            }
            if ($field === 'booking_service_id') {
                $category = strtoupper(trim((string) DB::table('booking_services as bs')
                    ->join('products_services as ps', 'ps.id', '=', 'bs.product_service_id')
                    ->where('bs.id', $component[$field])
                    ->value('ps.category')));
                $expected = [
                    'air' => ['AIR', 'AIR_TICKET', 'AIR TICKET'],
                    'hotel' => ['HOTEL', 'ACCOMMODATION'],
                    'visa' => ['VISA'],
                    'transport' => ['TRANSPORT', 'TRANSFER'],
                    'other' => ['OTHER', 'PACKAGE', 'UMRAH', 'UMRAH_PACKAGE', 'UMRAH PACKAGE'],
                ];
                if (! in_array($category, $expected[$component['component_type']] ?? [], true)) {
                    throw ValidationException::withMessages(['components' => 'The native service product category does not match the component type.']);
                }
            }
        }
    }
}
