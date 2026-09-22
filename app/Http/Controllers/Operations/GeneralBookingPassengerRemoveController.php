<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\BookingEditLockResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class GeneralBookingPassengerRemoveController extends Controller
{
    public function __construct(
        private readonly BookingEditLockResolver $bookingLocks,
    ) {
    }

    public function __invoke(int $booking, int $passenger): JsonResponse
    {
        if (! Schema::hasTable('bookings') || ! DB::table('bookings')->where('id', $booking)->exists()) {
            abort(404);
        }

        $table = $this->bookingPassengerTable();
        if (! $table) {
            throw ValidationException::withMessages([
                'passenger' => 'The booking passenger store is not available on this installation.',
            ]);
        }

        $columns = Schema::getColumnListing($table);
        if (! in_array('id', $columns, true) || ! in_array('booking_id', $columns, true)) {
            throw ValidationException::withMessages([
                'passenger' => 'The booking passenger store cannot be safely updated on this installation.',
            ]);
        }

        $bookingRow = DB::table('bookings')->where('id', $booking)->first();
        $lock = $this->bookingLocks->fromRow((array) $bookingRow);
        if ($lock['locked']) {
            throw ValidationException::withMessages([
                'passenger' => $lock['reason'] ?: 'This booking is locked and cannot be edited.',
            ]);
        }

        $row = DB::table($table)
            ->where('booking_id', $booking)
            ->where('id', $passenger)
            ->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'passenger' => 'The selected passenger does not belong to this booking.',
            ]);
        }

        try {
            $deleted = DB::transaction(function () use ($table, $booking, $passenger): int {
                $affectedAirServiceIds = $this->cleanupBookingPassengerDependencies($booking, $passenger);

                $deleted = DB::table($table)
                    ->where('booking_id', $booking)
                    ->where('id', $passenger)
                    ->delete();
                $this->reconcileAirServiceSnapshots($booking, $affectedAirServiceIds);
                return $deleted;
            });
        } catch (\Throwable $e) {
            report($e);

            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'passenger' => 'This passenger is already linked to saved booking services. Remove those passenger assignments first, then try again.',
            ]);
        }

        if ($deleted !== 1) {
            throw ValidationException::withMessages([
                'passenger' => 'The passenger could not be removed from this booking.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'booking_passenger_id' => $passenger,
            'message' => 'Passenger removed from booking.',
        ]);
    }

    private function bookingPassengerTable(): ?string
    {
        foreach (['booking_passengers', 'booking_travellers', 'booking_travelers'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            if (in_array('booking_id', $columns, true)) {
                return $table;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function cleanupBookingPassengerDependencies(int $booking, int $passenger): array
    {
        if (Schema::hasTable('booking_visa_services')) {
            $rows = DB::table('booking_visa_services')
                ->where('booking_id', $booking)
                ->where('booking_passenger_id', $passenger)
                ->get(['id', 'status']);
            $this->assertDraftDependencies($rows, 'Visa service');
            if ($rows->isNotEmpty()) {
                DB::table('booking_visa_services')->whereIn('id', $rows->pluck('id')->all())->delete();
            }
        }

        foreach (['booking_service_passengers', 'booking_service_passenger_links', 'booking_booking_service_passengers'] as $linkTable) {
            if (! Schema::hasTable($linkTable)) continue;
            $columns = Schema::getColumnListing($linkTable);
            $passengerColumn = in_array('booking_passenger_id', $columns, true)
                ? 'booking_passenger_id'
                : (in_array('passenger_id', $columns, true) ? 'passenger_id' : null);
            if (! $passengerColumn) continue;

            $query = DB::table($linkTable)->where($passengerColumn, $passenger);
            if (in_array('booking_id', $columns, true)) {
                $query->where('booking_id', $booking);
            } elseif (in_array('booking_service_id', $columns, true) && Schema::hasTable('booking_services')) {
                $serviceIds = DB::table('booking_services')->where('booking_id', $booking)->pluck('id')->all();
                if (! $serviceIds) continue;
                $query->whereIn('booking_service_id', $serviceIds);
            } else {
                throw ValidationException::withMessages([
                    'passenger' => 'This passenger has a booking dependency that cannot be safely scoped automatically.',
                ]);
            }
            $query->delete();
        }

        return $this->cleanupAirTicketDetails($booking, $passenger);
    }

    /** @return list<int> */
    private function cleanupAirTicketDetails(int $booking, int $passenger): array
    {
        if (! Schema::hasTable('air_ticket_details')) return [];
        $columns = Schema::getColumnListing('air_ticket_details');
        if (! in_array('booking_passenger_id', $columns, true)) {
            if (collect(['passenger_id', 'traveller_id', 'traveler_id'])->contains(fn (string $column): bool => in_array($column, $columns, true))) {
                throw ValidationException::withMessages([
                    'passenger' => 'Passenger ticket identity cannot be mapped safely to this booking snapshot.',
                ]);
            }
            return [];
        }

        $passengerColumn = 'booking_passenger_id';

        $query = DB::table('air_ticket_details')->where($passengerColumn, $passenger);
        if (in_array('booking_id', $columns, true)) {
            $query->where('booking_id', $booking);
        } elseif (in_array('booking_service_id', $columns, true) && Schema::hasTable('booking_services')) {
            $serviceIds = DB::table('booking_services')->where('booking_id', $booking)->pluck('id')->all();
            if (! $serviceIds) return [];
            $query->whereIn('booking_service_id', $serviceIds);
        } else {
            throw ValidationException::withMessages([
                'passenger' => 'Passenger ticket dependency cannot be safely scoped to this booking.',
            ]);
        }

        $rows = $query->get();
        $this->assertDraftDependencies($rows, 'Passenger ticket');
        $affectedServiceIds = $rows->pluck('booking_service_id')->filter(static fn ($id): bool => (int) $id > 0)->map(static fn ($id): int => (int) $id)->unique()->values()->all();
        if ($rows->isNotEmpty() && in_array('id', $columns, true)) {
            DB::table('air_ticket_details')->whereIn('id', $rows->pluck('id')->all())->delete();
        }
        return $affectedServiceIds;
    }

    /** Rewrites only affected persisted Air snapshots from remaining native rows. */
    private function reconcileAirServiceSnapshots(int $booking, array $affectedServiceIds): void
    {
        if (! $affectedServiceIds || ! Schema::hasTable('booking_services') || ! Schema::hasTable('air_ticket_details')) return;
        $serviceColumns = Schema::getColumnListing('booking_services');
        $ticketColumns = Schema::getColumnListing('air_ticket_details');
        if (! in_array('id', $serviceColumns, true) || ! in_array('booking_id', $serviceColumns, true)
            || ! in_array('booking_service_id', $ticketColumns, true)) return;
        $services = DB::table('booking_services')->where('booking_id', $booking)->whereIn('id', $affectedServiceIds)->get(['id']);
        foreach ($services as $service) {
            $rows = DB::table('air_ticket_details')->where('booking_service_id', (int) $service->id)->get();
            $customer = 0.0;
            $supplier = 0.0;
            foreach ($rows as $row) {
                $customer += $this->airCustomerTotalFromRow((array) $row, $ticketColumns);
                $supplier += $this->airSupplierTotalFromRow((array) $row, $ticketColumns);
            }
            $update = [];
            foreach (['line_total', 'customer_total', 'selling_total', 'sale_amount', 'total_amount'] as $field) if (in_array($field, $serviceColumns, true)) $update[$field] = round($customer, 2);
            foreach (['supplier_total', 'vendor_total', 'supplier_amount', 'cost_amount'] as $field) if (in_array($field, $serviceColumns, true)) $update[$field] = round($supplier, 2);
            foreach (['quantity', 'qty'] as $field) if (in_array($field, $serviceColumns, true)) $update[$field] = count($rows);
            $count = max(1, count($rows));
            foreach (['unit_price', 'sale_price', 'selling_price'] as $field) if (in_array($field, $serviceColumns, true)) $update[$field] = round($customer / $count, 2);
            if ($update) DB::table('booking_services')->where('id', (int) $service->id)->update($update);
        }
    }

    private function airCustomerTotalFromRow(array $row, array $columns): float
    {
        $direct = $this->airMoneyFromRow($row, $columns, ['selling_total', 'customer_sale', 'customer_sell', 'customer_sale_amount', 'customer_sell_amount', 'sale_amount', 'sell_amount', 'selling_price', 'sale_price', 'customer_price', 'customer_total', 'receivable_amount']);
        if ($direct !== 0.0) return $direct;
        return max(0.0, $this->airMoneyFromRow($row, $columns, ['base_fare', 'basic_fare']) + $this->airMoneyFromRow($row, $columns, ['airline_taxes', 'taxes', 'tax_amount']) + $this->airMoneyFromRow($row, $columns, ['customer_service_fee', 'service_markup', 'service_charge', 'markup']) - $this->airMoneyFromRow($row, $columns, ['customer_discount_amount', 'discount_amount', 'discount']));
    }

    private function airSupplierTotalFromRow(array $row, array $columns): float
    {
        $direct = $this->airMoneyFromRow($row, $columns, ['net_supplier_cost', 'supplier_cost', 'supplier_cost_amount', 'net_cost', 'purchase_cost', 'purchase_price', 'supplier_total', 'cost_amount']);
        if ($direct !== 0.0) return $direct;
        return max(0.0, $this->airMoneyFromRow($row, $columns, ['supplier_base_fare', 'base_fare', 'basic_fare']) + $this->airMoneyFromRow($row, $columns, ['supplier_taxes', 'airline_taxes', 'taxes']) + $this->airMoneyFromRow($row, $columns, ['supplier_charges', 'supplier_charge', 'supplier_markup', 'supplier_other_charges', 'supplier_other_charge', 'supplier_other_cost', 'vendor_other_charges', 'vendor_other_charge', 'vendor_other_cost']));
    }

    private function airMoneyFromRow(array $row, array $columns, array $aliases): float
    {
        foreach ($aliases as $alias) {
            if (in_array($alias, $columns, true) && is_numeric($row[$alias] ?? null)) return round((float) $row[$alias], 2);
        }
        return 0.0;
    }

    private function assertDraftDependencies(iterable $rows, string $label): void
    {
        $terminal = ['issued', 'posted', 'paid', 'settled', 'closed', 'approved', 'completed', 'refunded', 'void', 'voided', 'cancelled', 'canceled'];
        foreach ($rows as $row) {
            $status = strtolower((string) ($row->status ?? $row->ticket_status ?? ''));
            $ticketEvidence = false;
            foreach (['ticket_number', 'ticket_no', 'e_ticket_number', 'eticket_number', 'document_number', 'document_no', 'issue_date', 'ticket_issue_date', 'issued_at'] as $field) {
                if (trim((string) ($row->{$field} ?? '')) !== '') {
                    $ticketEvidence = true;
                    break;
                }
            }
            if ($ticketEvidence || in_array($status, $terminal, true)) {
                throw ValidationException::withMessages([
                    'passenger' => $label.' is already issued or otherwise irreversible; remove is blocked.',
                ]);
            }
            if (! in_array($status, ['draft', 'booked', 'pending', 'active', 'new', 'open'], true)) {
                throw ValidationException::withMessages([
                    'passenger' => $label.' has an unknown status; removal is blocked for safety.',
                ]);
            }
        }
    }
}
