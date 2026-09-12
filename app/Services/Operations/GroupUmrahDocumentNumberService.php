<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GroupUmrahDocumentNumberService
{
    public function voucherNumber(int $bookingId): string
    {
        $year = $this->bookingYear($bookingId);

        return sprintf('ET-UV-%04d-%d', $year, $bookingId);
    }

    public function bookingReference(int $bookingId): string
    {
        try {
            if (Schema::hasTable('bookings')) {
                $columns = Schema::getColumnListing('bookings');
                $row = (array) (DB::table('bookings')->where('id', $bookingId)->first() ?? []);

                foreach ([
                    'booking_number', 'booking_no', 'booking_reference',
                    'reference_no', 'reference', 'public_reference',
                ] as $column) {
                    if (in_array($column, $columns, true)) {
                        $value = trim((string) ($row[$column] ?? ''));

                        if ($value !== '') {
                            return $value;
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return sprintf('BK-%04d-%d', $this->bookingYear($bookingId), $bookingId);
    }

    private function bookingYear(int $bookingId): int
    {
        try {
            if (Schema::hasTable('bookings')) {
                $columns = Schema::getColumnListing('bookings');

                foreach (['booking_date', 'date', 'created_at'] as $column) {
                    if (! in_array($column, $columns, true)) {
                        continue;
                    }

                    $value = DB::table('bookings')->where('id', $bookingId)->value($column);

                    if ($value) {
                        return (int) date('Y', strtotime((string) $value));
                    }
                }
            }
        } catch (\Throwable) {
        }

        return (int) date('Y');
    }
}
