<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AdaptiveBookingWriter
{
    public function create(array $data): int
    {
        if (! Schema::hasTable('bookings')) {
            throw new RuntimeException('The bookings table is not available.');
        }

        $columns = Schema::getColumnListing('bookings');
        $row = [];

        /*
         * Save Commercial establishes the commercial package but does NOT
         * confirm the booking. Confirmation is an explicit workflow action:
         *
         * Save Commercial -> Confirm Booking -> Create Sales Invoice
         */
        $status = ($data['save_mode'] ?? 'complete') === 'draft'
            ? 'draft'
            : 'pending';

        $this->put($row, $columns, ['booking_date', 'date'], $data['booking_date']);
        $this->put($row, $columns, ['booking_type', 'type', 'product_type'], $data['booking_type'] ?? 'UMRAH');
        $this->put($row, $columns, $this->customerColumns(), $data['customer_id'] ?? null);
        $this->put($row, $columns, ['branch_id'], $data['branch_id'] ?? null);
        $this->put($row, $columns, ['currency_code', 'currency'], $data['currency_code'] ?? 'PKR');
        /*
         * Legacy Easy Ticket schemas can expose more than one booking status
         * column. Synchronize all recognized commercial-confirmation fields;
         * do not stop after the first existing column.
         */
        $this->putAll(
            $row,
            $columns,
            ['status', 'booking_status', 'workflow_status', 'confirmation_status'],
            $status
        );

        $this->putAll(
            $row,
            $columns,
            ['approval_status'],
            $status === 'draft' ? 'draft' : 'pending'
        );

        if ($status === 'confirmed') {
            $this->putAll($row, $columns, ['is_confirmed', 'confirmed'], 1);

            if (in_array('confirmed_at', $columns, true)) {
                $row['confirmed_at'] = now();
            }
        }
        $this->put($row, $columns, ['notes', 'remarks'], $data['notes'] ?? null);
        $this->put($row, $columns, ['booking_value', 'total_amount', 'sale_amount', 'package_sale_amount'], $data['final_sale_price'] ?? 0);
        $this->put($row, $columns, ['supplier_cost', 'forecast_supplier_cost', 'package_supplier_cost'], $data['supplier_cost'] ?? 0);
        $this->put($row, $columns, ['vendor_id', 'supplier_id', 'service_partner_id'], $data['vendor_id'] ?? null);
        $this->put($row, $columns, ['booked_pax', 'pax_count', 'passengers_count', 'quantity'], $data['booked_pax'] ?? null);
        $this->put($row, $columns, ['created_by', 'created_by_id', 'user_id'], Auth::id());

        $nextBookingId = max(
            1000,
            ((int) DB::table('bookings')->max('id')) + 1
        );
        $reference = 'BK-' . now()->format('Y') . '-' . (string) $nextBookingId;
        $this->put($row, $columns, ['booking_no', 'booking_number', 'booking_reference', 'reference', 'code'], $reference);

        if (in_array('created_at', $columns, true)) {
            $row['created_at'] = now();
        }
        if (in_array('updated_at', $columns, true)) {
            $row['updated_at'] = now();
        }

        $this->fillRequiredGenericColumns($row, $columns, $data, $status);

        return (int) DB::table('bookings')->insertGetId($row);
    }

    public function update(int $bookingId, array $data): void
    {
        $columns = Schema::getColumnListing('bookings');
        $row = [];

        $current = (array) (
            DB::table('bookings')->where('id', $bookingId)->first()
            ?? []
        );

        /*
         * Confirmation is a workflow state. Ordinary commercial/operational
         * saves must never demote an already-confirmed booking to Pending.
         */
        $alreadyConfirmed = $this->rowIsConfirmed($current);

        $status = $alreadyConfirmed
            ? 'confirmed'
            : (($data['save_mode'] ?? 'complete') === 'draft'
                ? 'draft'
                : 'pending');

        $this->put($row, $columns, ['booking_date', 'date'], $data['booking_date']);
        $this->put($row, $columns, ['booking_type', 'type', 'product_type'], $data['booking_type'] ?? 'UMRAH');
        $this->put($row, $columns, $this->customerColumns(), $data['customer_id'] ?? null);
        $this->put($row, $columns, ['branch_id'], $data['branch_id'] ?? null);
        $this->put($row, $columns, ['currency_code', 'currency'], $data['currency_code'] ?? 'PKR');
        /*
         * Legacy Easy Ticket schemas can expose more than one booking status
         * column. Synchronize all recognized commercial-confirmation fields;
         * do not stop after the first existing column.
         */
        $this->putAll(
            $row,
            $columns,
            ['status', 'booking_status', 'workflow_status', 'confirmation_status'],
            $status
        );

        if (! $alreadyConfirmed) {
            $this->putAll(
                $row,
                $columns,
                ['approval_status'],
                $status === 'draft' ? 'draft' : 'pending'
            );
        }

        if ($status === 'confirmed') {
            $this->putAll($row, $columns, ['is_confirmed', 'confirmed'], 1);
            // Preserve the original confirmed_at / confirmation actor.
        }
        $this->put($row, $columns, ['notes', 'remarks'], $data['notes'] ?? null);
        $this->put($row, $columns, ['booking_value', 'total_amount', 'sale_amount', 'package_sale_amount'], $data['final_sale_price'] ?? 0);
        $this->put($row, $columns, ['supplier_cost', 'forecast_supplier_cost', 'package_supplier_cost'], $data['supplier_cost'] ?? 0);
        $this->put($row, $columns, ['vendor_id', 'supplier_id', 'service_partner_id'], $data['vendor_id'] ?? null);
        $this->put($row, $columns, ['booked_pax', 'pax_count', 'passengers_count', 'quantity'], $data['booked_pax'] ?? null);

        if (in_array('updated_at', $columns, true)) {
            $row['updated_at'] = now();
        }

        if ($row) {
            DB::table('bookings')->where('id', $bookingId)->update($row);
        }
    }

    private function rowIsConfirmed(array $row): bool
    {
        foreach (['status','booking_status','workflow_status','confirmation_status'] as $field) {
            $value = strtolower(trim((string) ($row[$field] ?? '')));
            if (in_array($value, ['confirmed','approved','booked','final','finalized'], true)) {
                return true;
            }
        }

        if ((int) ($row['is_confirmed'] ?? 0) === 1) return true;
        if ((int) ($row['confirmed'] ?? 0) === 1) return true;

        return ! empty($row['confirmed_at']);
    }

    private function put(array &$row, array $columns, array $candidates, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                $row[$column] = $value;
                return;
            }
        }
    }

    private function customerColumns(): array
    {
        return [
            'customer_id',
            'party_id',
            'client_id',
            'customer_party_id',
            'customer_party_master_id',
            'party_master_id',
            'bill_to_party_id',
            'account_party_id',
            'customer_account_id',
        ];
    }

    private function putAll(
        array &$row,
        array $columns,
        array $candidates,
        mixed $value
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) {
                $row[$column] = $value;
            }
        }
    }

    private function fillRequiredGenericColumns(array &$row, array $columns, array $data, string $status): void
    {
        $defaults = [
            'company_id' => $data['company_id'] ?? 1,
            'office_id' => $data['branch_id'] ?? 1,
            'booking_source' => 'manual',
            'source' => 'manual',
            'channel' => 'backoffice',
            'travel_status' => 'pending',
            'approval_status' => $status === 'draft' ? 'draft' : 'pending',
            'is_confirmed' => $status === 'draft' ? 0 : 1,
            'confirmed' => $status === 'draft' ? 0 : 1,
            'payment_status' => 'unpaid',
            'is_active' => 1,
            'active' => 1,
        ];

        foreach ($defaults as $column => $value) {
            if (in_array($column, $columns, true) && ! array_key_exists($column, $row)) {
                $row[$column] = $value;
            }
        }
    }
}
