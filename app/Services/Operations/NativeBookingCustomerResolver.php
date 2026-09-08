<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

/**
 * ERP-10.31.13
 *
 * Resolves the authoritative Customer / Party already selected on the native
 * booking before staff enters the Group Umrah workspace.
 */
class NativeBookingCustomerResolver
{
    public function __construct(
        private readonly UnifiedGroupPackageDataSource $source,
    ) {}

    public function resolve(int $bookingId): array
    {
        if ($bookingId <= 0) {
            return $this->empty();
        }

        // This is the same authority used by the host main Booking page: its
        // native Booking model and Customer / Party relationship. The overlay
        // does not define or replace either model.
        if ($native = $this->fromNativeBookingModel($bookingId)) {
            return $native;
        }

        $customers = $this->source->customers();
        $byId = $customers->keyBy(fn (array $row): string => (string) ($row['id'] ?? ''))->all();
        $byName = $customers->keyBy(
            fn (array $row): string => $this->normalName((string) ($row['name'] ?? ''))
        )->all();

        if (Schema::hasTable('bookings')) {
            try {
                $booking = (array) (DB::table('bookings')->where('id', $bookingId)->first() ?? []);
                if ($identity = $this->fromRow($booking, $byId, $byName, 'bookings')) {
                    $this->remember($bookingId, $identity);
                    return $identity;
                }
            } catch (\Throwable) {
            }
        }

        foreach ($this->relatedBookingTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);
            } catch (\Throwable) {
                continue;
            }

            $bookingColumn = $this->firstColumn(
                $columns,
                ['booking_id', 'travel_booking_id', 'source_booking_id']
            );

            if (! $bookingColumn) {
                continue;
            }

            try {
                $rows = DB::table($table)
                    ->where($bookingColumn, $bookingId)
                    ->limit(20)
                    ->get();
            } catch (\Throwable) {
                continue;
            }

            foreach ($rows as $row) {
                if ($identity = $this->fromRow((array) $row, $byId, $byName, $table)) {
                    $this->remember($bookingId, $identity);
                    return $identity;
                }
            }
        }

        // Legacy context is a read-only fallback for bookings created through
        // an older native entry point. Native booking/relationship data above
        // remains the authority whenever it exists.
        if ($saved = $this->savedContext($bookingId)) {
            return $saved;
        }

        return $this->empty();
    }

    public function capture(
        int $bookingId,
        ?int $customerId,
        ?string $customerName = null,
        string $source = 'native-booking-create'
    ): void {
        if ($bookingId <= 0 || ! $customerId) {
            return;
        }

        $known = $this->source->customers()->first(
            fn (array $row): bool => (int) ($row['id'] ?? 0) === $customerId
        );

        $name = trim((string) ($customerName ?: ($known['name'] ?? '')));

        $this->remember($bookingId, [
            'id' => $customerId,
            'name' => $name !== '' ? $name : 'Customer #'.$customerId,
            'source' => $source,
            'resolved' => true,
        ]);
    }

    private function savedContext(int $bookingId): ?array
    {
        if (! Schema::hasTable('booking_group_umrah_contexts')) {
            return null;
        }

        try {
            $row = DB::table('booking_group_umrah_contexts')
                ->where('booking_id', $bookingId)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if (! $row || (int) ($row->customer_id ?? 0) <= 0) {
            return null;
        }

        return [
            'id' => (int) $row->customer_id,
            'name' => trim((string) ($row->customer_name ?? ''))
                ?: 'Customer #'.(int) $row->customer_id,
            'source' => (string) ($row->customer_source ?? 'context'),
            'resolved' => true,
        ];
    }

    private function fromNativeBookingModel(int $bookingId): ?array
    {
        foreach ([
            \App\Models\Booking::class,
            \App\Models\Operations\Booking::class,
            \App\Models\Travel\Booking::class,
        ] as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            try {
                /** @var Model|null $booking */
                $booking = (new $class())->newQuery()->find($bookingId);
            } catch (\Throwable) {
                continue;
            }

            if (! $booking) {
                continue;
            }

            foreach ([
                'customer',
                'party',
                'client',
                'customerParty',
                'billToParty',
                'accountParty',
            ] as $relation) {
                if (! method_exists($booking, $relation)) {
                    continue;
                }

                try {
                    $relationship = $booking->{$relation}();
                    $related = $booking->getRelationValue($relation);
                } catch (\Throwable) {
                    continue;
                }

                if ($identity = $this->fromRelatedModel($related, $relation, $relationship)) {
                    return $identity;
                }
            }
        }

        return null;
    }

    private function fromRelatedModel(mixed $related, string $relation, mixed $relationship): ?array
    {
        if ($related instanceof Model) {
            $row = $related->getAttributes();
            $id = (int) $related->getKey();
        } elseif (is_object($related)) {
            $row = (array) $related;
            $id = (int) ($row['id'] ?? $row['party_id'] ?? $row['customer_id'] ?? 0);
        } elseif (is_array($related)) {
            $row = $related;
            $id = (int) ($row['id'] ?? $row['party_id'] ?? $row['customer_id'] ?? 0);
        } else {
            return null;
        }

        $name = '';

        foreach (['name', 'display_name', 'legal_name', 'customer_name', 'party_name', 'client_name', 'title'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));

            if ($value !== '') {
                $name = $value;
                break;
            }
        }

        if ($id <= 0 || $name === '') {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'source' => 'native-booking-model.'.$relation,
            'field' => $this->relationForeignKey($relationship, $relation),
            'relation' => $relation,
            'resolved' => true,
        ];
    }

    private function relationForeignKey(mixed $relationship, string $relation): string
    {
        if (is_object($relationship) && method_exists($relationship, 'getForeignKeyName')) {
            try {
                $field = trim((string) $relationship->getForeignKeyName());
                if ($field !== '') return $field;
            } catch (\Throwable) {
            }
        }

        return Str::snake($relation).'_id';
    }

    private function fromRow(array $row, array $byId, array $byName, string $source): ?array
    {
        if (! $row) {
            return null;
        }

        foreach ([
            'customer_id', 'party_id', 'client_id', 'customer_party_id',
            'customer_party_master_id', 'party_master_id', 'bill_to_party_id',
            'account_party_id', 'customer_account_id',
        ] as $column) {
            $id = (int) ($row[$column] ?? 0);
            if ($id > 0 && isset($byId[(string) $id])) {
                return $this->identityFromMaster($byId[(string) $id], $source.'.'.$column);
            }
        }

        /*
         * Accept semantically named foreign keys only when their value exists
         * in the existing Customer Party Master. This avoids treating vendor,
         * branch or salesperson IDs as Customers.
         */
        foreach ($row as $column => $value) {
            $key = strtolower((string) $column);
            if (
                ! Str::contains($key, ['customer', 'party', 'client'])
                || ! (str_ends_with($key, '_id') || $key === 'party')
                || Str::contains($key, ['vendor', 'supplier', 'salesperson', 'agent'])
            ) {
                continue;
            }

            $id = (int) $value;
            if ($id > 0 && isset($byId[(string) $id])) {
                return $this->identityFromMaster($byId[(string) $id], $source.'.'.$column);
            }
        }

        foreach (['customer_name', 'party_name', 'client_name', 'bill_to_name', 'customer_display_name'] as $column) {
            $name = trim((string) ($row[$column] ?? ''));
            $normalized = $this->normalName($name);
            if ($normalized !== '' && isset($byName[$normalized])) {
                return $this->identityFromMaster($byName[$normalized], $source.'.'.$column);
            }
        }

        foreach ($row as $column => $value) {
            $key = strtolower((string) $column);
            if (
                ! Str::contains($key, ['customer', 'party', 'client'])
                || ! Str::contains($key, ['name', 'display', 'label'])
            ) {
                continue;
            }

            $normalized = $this->normalName((string) $value);
            if ($normalized !== '' && isset($byName[$normalized])) {
                return $this->identityFromMaster($byName[$normalized], $source.'.'.$column);
            }
        }

        return null;
    }

    private function identityFromMaster(array $master, string $source): array
    {
        return [
            'id' => (int) ($master['id'] ?? 0),
            'name' => (string) ($master['name'] ?? ''),
            'source' => $source,
            'resolved' => true,
        ];
    }

    private function relatedBookingTables(): array
    {
        $tables = [
            'booking_parties',
            'booking_party',
            'booking_customers',
            'booking_clients',
            'booking_party_links',
            'booking_party_assignments',
            'booking_headers',
        ];

        try {
            foreach (Schema::getTables() as $meta) {
                $name = is_array($meta) ? ($meta['name'] ?? null) : data_get($meta, 'name');
                if (! $name) {
                    continue;
                }

                $lower = strtolower((string) $name);
                if (
                    Str::contains($lower, 'booking')
                    && Str::contains($lower, ['customer', 'party', 'client'])
                    && ! Str::contains($lower, 'group_package')
                    && $lower !== 'booking_group_umrah_contexts'
                ) {
                    $tables[] = (string) $name;
                }
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($tables));
    }

    private function remember(int $bookingId, array $identity): void
    {
        if (
            ! Schema::hasTable('booking_group_umrah_contexts')
            || (int) ($identity['id'] ?? 0) <= 0
        ) {
            return;
        }

        try {
            $existing = DB::table('booking_group_umrah_contexts')
                ->where('booking_id', $bookingId)
                ->first();

            $values = [
                'customer_id' => (int) $identity['id'],
                'customer_name' => trim((string) ($identity['name'] ?? '')) ?: null,
                'customer_source' => trim((string) ($identity['source'] ?? 'resolver')) ?: null,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('booking_group_umrah_contexts')
                    ->where('booking_id', $bookingId)
                    ->update($values);
            } else {
                DB::table('booking_group_umrah_contexts')->insert([
                    'booking_id' => $bookingId,
                    ...$values,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable) {
        }
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    private function normalName(string $name): string
    {
        return strtolower(trim(preg_replace('/\\s+/', ' ', $name) ?: $name));
    }

    private function empty(): array
    {
        return ['id' => null, 'name' => '', 'source' => null, 'resolved' => false];
    }
}
