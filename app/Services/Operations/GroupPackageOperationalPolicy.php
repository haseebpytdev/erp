<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class GroupPackageOperationalPolicy
{
    /** @var array<int,bool> */
    private static array $bookingCache = [];

    /**
     * Detect package ownership across old/new ERP booking shapes.
     *
     * Important: a booking labelled only "UMRAH" is not blindly treated as a
     * Group Package. We require package evidence from the booking itself,
     * loaded relations, or booking-owned service/package rows.
     */
    public static function isGroupPackage(object|array|null $booking): bool
    {
        if ($booking === null) {
            return false;
        }

        $read = static function (string $key) use ($booking) {
            if (is_array($booking)) {
                return data_get($booking, $key);
            }

            try {
                return data_get($booking, $key);
            } catch (\Throwable) {
                return null;
            }
        };

        $bookingId = (int) ($read('id') ?? 0);
        if ($bookingId > 0 && array_key_exists($bookingId, self::$bookingCache)) {
            return self::$bookingCache[$bookingId];
        }

        if (self::hasDirectPackageEvidence($read)) {
            return self::remember($bookingId, true);
        }

        foreach (['groupPackage', 'group_package', 'package', 'travelPackage', 'travel_package'] as $relation) {
            $value = $read($relation);
            if (self::nonEmptyRelation($value) && self::rowLooksLikePackage($value)) {
                return self::remember($bookingId, true);
            }
        }

        foreach (['services', 'bookingServices', 'booking_services', 'serviceItems', 'service_items', 'items'] as $relation) {
            $rows = $read($relation);
            if ($rows === null) {
                continue;
            }

            try {
                foreach (collect($rows) as $row) {
                    if (self::rowLooksLikePackage($row)) {
                        return self::remember($bookingId, true);
                    }
                }
            } catch (\Throwable) {
                // Continue to database fallback.
            }
        }

        if ($bookingId > 0 && self::databaseHasPackageEvidence($bookingId)) {
            return self::remember($bookingId, true);
        }

        return self::remember($bookingId, false);
    }

    public static function forceOperationalOnly(object|array|null $booking, string $serviceType): bool
    {
        if (! self::isGroupPackage($booking)) {
            return false;
        }

        return in_array(Str::lower(trim($serviceType)), [
            'hotel',
            'accommodation',
            'transport',
            'transfer',
            'air',
            'air_ticket',
            'air ticket',
            'flight',
        ], true);
    }

    /**
     * @param callable(string):mixed $read
     */
    private static function hasDirectPackageEvidence(callable $read): bool
    {
        foreach ([
            'group_package_id',
            'travel_group_package_id',
            'package_id',
            'travel_package_id',
            'package_rate_id',
            'groupPackage.id',
            'group_package.id',
            'package.id',
            'travelPackage.id',
            'travel_package.id',
        ] as $key) {
            if (self::meaningfulId($read($key))) {
                return true;
            }
        }

        foreach ([
            'booking_type',
            'type',
            'product_type',
            'service_type',
            'package_type',
            'booking_category',
            'category',
            'source_type',
        ] as $key) {
            if (self::textLooksLikePackage($read($key))) {
                return true;
            }
        }

        foreach ([
            'package_sale_amount',
            'group_package_sale_amount',
            'package_vendor_id',
            'group_package_vendor_id',
        ] as $key) {
            $value = $read($key);
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private static function databaseHasPackageEvidence(int $bookingId): bool
    {
        try {
            // The unified package parent is authoritative for new Complete
            // Package bookings even when package_id is intentionally null.
            if (Schema::hasTable('booking_group_package_unified')
                && DB::table('booking_group_package_unified')->where('booking_id', $bookingId)->exists()) {
                return true;
            }

            foreach ([
                'booking_services',
                'booking_service_items',
                'booking_products',
                'booking_packages',
                'booking_group_packages',
                'booking_travel_packages',
            ] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $columns = Schema::getColumnListing($table);
                if (! in_array('booking_id', $columns, true)) {
                    continue;
                }

                $rows = DB::table($table)->where('booking_id', $bookingId)->get();
                foreach ($rows as $row) {
                    if (self::rowLooksLikePackage($row, $columns)) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            // Fail closed. UI may remain standalone rather than hiding real prices.
        }

        return false;
    }

    /**
     * @param array<int,string>|null $knownColumns
     */
    private static function rowLooksLikePackage(mixed $row, ?array $knownColumns = null): bool
    {
        if ($row === null) {
            return false;
        }

        if (is_array($row)) {
            $data = $row;
        } elseif (is_object($row) && method_exists($row, 'toArray')) {
            try {
                $data = $row->toArray();
            } catch (\Throwable) {
                $data = (array) $row;
            }
        } else {
            $data = (array) $row;
        }
        if ($data === []) {
            return false;
        }

        foreach ([
            'group_package_id',
            'travel_group_package_id',
            'package_id',
            'travel_package_id',
            'package_rate_id',
            'package_option_id',
        ] as $key) {
            if (array_key_exists($key, $data) && self::meaningfulId($data[$key])) {
                return true;
            }
        }

        foreach ([
            'service_type',
            'service_code',
            'product_type',
            'product_code',
            'code',
            'subtype',
            'type',
            'kind',
            'category',
            'service_category',
            'product_category',
            'package_type',
            'serviceable_type',
            'productable_type',
            'title',
            'name',
            'label',
            'description',
        ] as $key) {
            if (array_key_exists($key, $data) && self::textLooksLikePackage($data[$key])) {
                return true;
            }
        }

        foreach ($data as $key => $value) {
            $keyLower = Str::lower((string) $key);
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            if (Str::contains($keyLower, ['type', 'name', 'code', 'label', 'category', 'package', 'service', 'product'])
                && self::textLooksLikePackage($value)) {
                return true;
            }
        }

        if ($knownColumns !== null) {
            foreach ($knownColumns as $column) {
                $columnLower = Str::lower($column);
                if (! array_key_exists($column, $data)) {
                    continue;
                }

                if (Str::contains($columnLower, 'package') && self::meaningfulId($data[$column])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function textLooksLikePackage(mixed $value): bool
    {
        $value = Str::lower(trim((string) ($value ?? '')));
        if ($value === '') {
            return false;
        }

        return Str::contains($value, [
            'group package',
            'group_package',
            'group-package',
            'umrah package',
            'umrah_package',
            'umrah-package',
            'travel package',
            'travel_package',
            'package booking',
            'package_booking',
        ]) || in_array($value, ['package', 'group', 'group_package', 'umrah_package'], true);
    }

    private static function meaningfulId(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== 0 && $value !== '0' && $value !== false;
    }

    private static function nonEmptyRelation(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            return $value->isNotEmpty();
        }

        return true;
    }

    private static function remember(int $bookingId, bool $value): bool
    {
        if ($bookingId > 0) {
            self::$bookingCache[$bookingId] = $value;
        }

        return $value;
    }
}
