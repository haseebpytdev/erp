<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Services\Purchase\BookingSupplierObligationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Transactional Hotel/Transport vendor-cost correction boundary for ET-CV.
 * This service deliberately does not accept or write selling-price fields.
 */
final class EtCvOperationalCorrectionService
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions,
        private readonly BookingSupplierObligationResolver $obligations,
        private readonly BookingEditLockResolver $locks,
    ) {}

    public function authorize(Request $request): void
    {
        $user = $request->user();
        $allowed = $this->canCorrect($user);
        abort_unless($allowed, 403, 'Vendor-cost correction requires the authorized operational costing capability.');
    }

    private function canCorrect(mixed $user): bool
    {
        if (! $user) return false;
        if ($this->permissions->isSuperAdmin($user)) return true;
        $service = $this->permissions->hasCapability($user, ['service details', 'service_details'], ['manage', 'edit']);
        $cost = $this->permissions->hasCapability($user, ['supplier costing', 'supplier_costing'], ['actual cost edit', 'actual_cost_edit', 'expense manage', 'expense_manage', 'manage']);
        return $service && $cost;
    }

    /** @return array<string,mixed> */
    public function lockSummary(int $bookingId): array
    {
        $rows = $this->obligations->resolve($bookingId);
        $locks = [];
        foreach ($rows as $row) {
            if (! in_array((string) ($row['product_type'] ?? ''), ['Hotel', 'Transport'], true)) continue;
            if (! in_array((string) ($row['status'] ?? ''), ['available'], true)) {
                $locks[(string) ($row['source_key'] ?? '')] = [
                    'locked' => true,
                    'status' => (string) ($row['status'] ?? 'unknown'),
                    'label' => (string) ($row['status_label'] ?? 'Vendor cost locked'),
                    'costing_id' => (int) ($row['costing_id'] ?? 0),
                    'costing_no' => (string) ($row['costing_no'] ?? ''),
                ];
            }
        }
        return $locks;
    }

    /** @return list<array<string,mixed>> */
    public function rows(int $bookingId): array
    {
        $rows = array_values(array_filter(
            $this->obligations->resolve($bookingId),
            static fn (array $row): bool => in_array((string) ($row['product_type'] ?? ''), ['Hotel', 'Transport'], true)
        ));
        foreach ($rows as &$row) {
            $table = (string) ($row['source_type'] ?? ''); $id = (int) ($row['source_id'] ?? 0);
            // Booking-service snapshots are the display authority, but when a
            // native Hotel detail row exists for the same service, corrections
            // must target that persisted row rather than a non-addressable
            // snapshot (which has no source_id).
            if ($id <= 0 && ($row['product_type'] ?? '') === 'Hotel'
                && (int) ($row['booking_service_id'] ?? 0) > 0
                && Schema::hasTable('hotel_booking_details')
            ) {
                $physical = DB::table('hotel_booking_details')
                    ->where('booking_service_id', (int) $row['booking_service_id'])
                    ->first();
                if ($physical) {
                    $table = 'hotel_booking_details';
                    $id = (int) $physical->id;
                    $row['source_type'] = $table;
                    $row['source_id'] = $id;
                }
            }
            if ($id <= 0 && ($row['product_type'] ?? '') === 'Transport'
                && (int) ($row['booking_service_id'] ?? 0) > 0
            ) {
                foreach (['booking_transport_segments', 'booking_transports', 'transport_booking_details', 'booking_transport_details'] as $transportTable) {
                    if (! Schema::hasTable($transportTable)) continue;
                    $transportColumns = Schema::getColumnListing($transportTable);
                    $query = DB::table($transportTable);
                    if (in_array('booking_service_id', $transportColumns, true)) {
                        $query->where('booking_service_id', (int) $row['booking_service_id']);
                    } elseif (in_array('service_id', $transportColumns, true)) {
                        $query->where('service_id', (int) $row['booking_service_id']);
                    } else {
                        continue;
                    }
                    $physical = $query->orderBy('id')->first();
                    if ($physical) {
                        $table = $transportTable;
                        $id = (int) $physical->id;
                        $row['source_type'] = $table;
                        $row['source_id'] = $id;
                        break;
                    }
                }
            }
            if ($id <= 0 || ! Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table); $record = (array) (DB::table($table)->where('id', $id)->first() ?? (object) []);
            if (($row['product_type'] ?? '') === 'Hotel') $row['hotel_id'] = (int) ($record['hotel_id'] ?? 0);
            if (($row['product_type'] ?? '') === 'Transport') $row['transport_company_id'] = (int) ($record['transport_company_id'] ?? 0);
            $row['vendor_id'] = (int) ($record['supplier_party_id'] ?? $record['supplier_vendor_party_id'] ?? $record['vendor_id'] ?? $record['supplier_id'] ?? 0);
            $row['columns'] = $columns;
        }
        unset($row);
        return array_values(array_filter($rows, static fn (array $row): bool => (int) ($row['source_id'] ?? 0) > 0));
    }

    /**
     * @param array{hotel?:list<array<string,mixed>>,transport?:list<array<string,mixed>>} $changes
     */
    public function apply(Request $request, int $bookingId, array $changes, string $reason): void
    {
        $this->authorize($request);
        $reason = trim($reason);
        if ($reason === '') throw ValidationException::withMessages(['correction_reason' => 'A correction reason is required.']);

        DB::transaction(function () use ($request, $bookingId, $changes, $reason): void {
            $booking = DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->first();
            abort_unless($booking, 404, 'Booking not found.');
            $state = $this->locks->resolve($bookingId);
            $user = $request->user();
            $admin = $this->canCorrect($user);
            if (($state['locked'] ?? false) && ! $admin) {
                throw ValidationException::withMessages(['booking' => 'Approved or Travel Ready bookings require the authorized Admin correction policy.']);
            }

            $before = [];
            $after = [];
            foreach (['hotel' => 'Hotel', 'transport' => 'Transport'] as $kind => $label) {
                foreach ((array) ($changes[$kind] ?? []) as $change) {
                    $this->assertNoActiveCostClaim($bookingId, $change);
                    $this->assertVendorAllowed($booking, $change);
                    $this->assertMasterRefs($change);
                    if ($kind === 'hotel') {
                        [$old, $new] = $this->updateHotel($bookingId, $change);
                    } else {
                        [$old, $new] = $this->updateTransport($bookingId, $change);
                    }
                    $before[] = ['product' => $label, 'source' => $old];
                    $after[] = ['product' => $label, 'source' => $new];
                }
            }
            if ($before === []) throw ValidationException::withMessages(['correction' => 'No Hotel or Transport correction was selected.']);
            $this->syncDraftDisplay($bookingId, $changes);
            \App\Services\AuditService::log($request, 'et_cv.operational_correction', Booking::query()->findOrFail($bookingId),
                ['reason' => $reason, 'changes' => $before], ['reason' => $reason, 'changes' => $after]);
        });
    }

    private function syncDraftDisplay(int $bookingId, array $changes): void
    {
        if (! Schema::hasTable('et_cv_voucher_drafts')) return;
        $draft = DB::table('et_cv_voucher_drafts')->where('booking_id', $bookingId)->lockForUpdate()->first();
        if (! $draft) return;
        $fields = json_decode((string) $draft->fields_json, true) ?: [];
        foreach ((array) ($changes['hotel'] ?? []) as $change) {
            $i = (int) ($change['display_index'] ?? -1); if ($i < 0) continue;
            $hotel = (int) ($change['hotel_id'] ?? 0) > 0 && Schema::hasTable('hotels') ? (array) DB::table('hotels')->where('id', (int) $change['hotel_id'])->first() : [];
            $vendor = (int) ($change['vendor_id'] ?? 0) > 0 && Schema::hasTable('parties') ? (array) DB::table('parties')->where('id', (int) $change['vendor_id'])->first() : [];
            if ($hotel) $fields['hotelStays'][$i]['hotel_name'] = (string) ($hotel['name'] ?? $hotel['hotel_name'] ?? '');
            if ($vendor) $fields['hotelStays'][$i]['vendor_name'] = (string) ($vendor['name'] ?? $vendor['party_name'] ?? $vendor['display_name'] ?? '');
        }
        foreach ((array) ($changes['transport'] ?? []) as $change) {
            $i = (int) ($change['display_index'] ?? -1); if ($i < 0) continue;
            $vendor = (int) ($change['vendor_id'] ?? 0) > 0 && Schema::hasTable('parties') ? (array) DB::table('parties')->where('id', (int) $change['vendor_id'])->first() : [];
            if ($vendor) $fields['transportRows'][$i]['company_name'] = (string) ($vendor['name'] ?? $vendor['party_name'] ?? $vendor['display_name'] ?? '');
        }
        DB::table('et_cv_voucher_drafts')->where('booking_id', $bookingId)->update(['fields_json' => json_encode($fields, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
    }

    private function assertNoActiveCostClaim(int $bookingId, array $change): void
    {
        $sourceKey = trim((string) ($change['source_key'] ?? ''));
        if ($sourceKey === '') throw ValidationException::withMessages(['correction' => 'Each correction must identify its persisted vendor-cost source.']);
        $row = collect($this->obligations->resolve($bookingId))->firstWhere('source_key', $sourceKey);
        if (! $row) throw ValidationException::withMessages(['correction' => 'The selected vendor-cost source no longer exists. Reload the voucher.']);
        if (! in_array((string) ($row['status'] ?? ''), ['available', 'cost_required', 'vendor_required'], true)) {
            $label = trim((string) ($row['status_label'] ?? 'Vendor Cost Locked'));
            throw ValidationException::withMessages(['correction' => 'Vendor Cost Locked — '.$label]);
        }
        if ((float) ($change['cost_amount'] ?? 0) < 0) throw ValidationException::withMessages(['correction' => 'Buying cost cannot be negative.']);
    }

    private function assertVendorAllowed(object $booking, array $change): void
    {
        $vendorId = (int) ($change['vendor_id'] ?? 0);
        if ($vendorId <= 0) return; // empty means retain the persisted vendor
        abort_unless(Schema::hasTable('parties'), 422, 'Vendor master is unavailable.');
        $query = DB::table('parties')->where('id', $vendorId);
        $columns = Schema::getColumnListing('parties');
        if (in_array('company_id', $columns, true) && (int) ($booking->company_id ?? 0) > 0) $query->where('company_id', (int) $booking->company_id);
        if (in_array('branch_id', $columns, true) && (int) ($booking->branch_id ?? 0) > 0) $query->where(function ($q) use ($booking): void { $q->where('branch_id', (int) $booking->branch_id)->orWhereNull('branch_id'); });
        abort_unless($query->exists(), 422, 'Selected Vendor is outside the booking company/branch authority.');
    }

    private function assertMasterRefs(array $change): void
    {
        $hotelId = (int) ($change['hotel_id'] ?? 0);
        if ($hotelId > 0 && Schema::hasTable('hotels')) abort_unless(DB::table('hotels')->where('id', $hotelId)->exists(), 422, 'Selected Hotel is not an existing master record.');
        $companyId = (int) ($change['transport_company_id'] ?? 0);
        if ($companyId > 0) {
            $table = Schema::hasTable('travel_voucher_partners') ? 'travel_voucher_partners' : (Schema::hasTable('transport_companies') ? 'transport_companies' : null);
            if ($table) abort_unless(DB::table($table)->where('id', $companyId)->exists(), 422, 'Selected Transport Company is not an existing master record.');
        }
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function updateHotel(int $bookingId, array $change): array
    {
        $id = (int) ($change['source_id'] ?? 0);
        abort_unless($id > 0, 422, 'Hotel source id is required.');
        $table = 'hotel_booking_details';
        abort_unless(Schema::hasTable($table), 422, 'Native Hotel detail storage is unavailable.');
        $columns = Schema::getColumnListing($table);
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404, 'Hotel source row not found.');
        if (Schema::hasColumn($table, 'booking_service_id')) abort_unless(DB::table('booking_services')->where('id', (int) $row->booking_service_id)->where('booking_id', $bookingId)->exists(), 403, 'Hotel source is outside this booking.');
        $old = (array) $row;
        $update = [];
        if (array_key_exists('hotel_id', $change) && (int) $change['hotel_id'] > 0) $this->put($update, $columns, ['hotel_id'], (int) $change['hotel_id']);
        if (array_key_exists('vendor_id', $change) && (int) $change['vendor_id'] > 0) $this->put($update, $columns, ['supplier_vendor_party_id', 'vendor_id', 'supplier_id', 'service_provider_id'], (int) $change['vendor_id']);
        $cost = round((float) ($change['cost_amount'] ?? 0), 2);
        $nights = max(1, (int) ($row->nights ?? 1));
        $rate = round((float) ($change['cost_rate'] ?? ($cost / $nights)), 2);
        $this->put($update, $columns, ['supplier_base_rate'], $rate);
        $this->put($update, $columns, ['supplier_base_total'], $cost);
        $this->put($update, $columns, ['net_supplier_cost', 'supplier_cost_base'], $cost);
        $this->put($update, $columns, ['supplier_currency_code'], strtoupper(trim((string) ($change['currency_code'] ?? 'PKR'))));
        $this->put($update, $columns, ['supplier_fx_rate'], (float) ($change['fx_rate'] ?? 1));
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        $query = DB::table($table)->where('id', $id);
        if (Schema::hasColumn($table, 'booking_service_id')) $query->where('booking_service_id', (int) $row->booking_service_id);
        $query->update($update);
        if ((int) ($change['vendor_id'] ?? 0) > 0) $this->syncServiceVendor((int) ($row->booking_service_id ?? 0), (int) $change['vendor_id']);
        return [$old, (array) DB::table($table)->where('id', $id)->first()];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function updateTransport(int $bookingId, array $change): array
    {
        $id = (int) ($change['source_id'] ?? 0);
        $table = (string) ($change['source_table'] ?? 'booking_transport_segments');
        abort_unless(in_array($table, ['booking_transport_segments', 'transport_booking_details', 'booking_transport_details'], true), 422, 'Invalid Transport source table.');
        abort_unless(Schema::hasTable($table), 422, 'Native Transport detail storage is unavailable.');
        $columns = Schema::getColumnListing($table);
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404, 'Transport source row not found.');
        if (Schema::hasColumn($table, 'booking_id')) abort_unless((int) $row->booking_id === $bookingId, 403, 'Transport source is outside this booking.');
        elseif (Schema::hasColumn($table, 'booking_service_id')) abort_unless(DB::table('booking_services')->where('id', (int) $row->booking_service_id)->where('booking_id', $bookingId)->exists(), 403, 'Transport source is outside this booking.');
        $old = (array) $row;
        $update = [];
        if (array_key_exists('transport_company_id', $change) && (int) $change['transport_company_id'] > 0) $this->put($update, $columns, ['transport_company_id'], (int) $change['transport_company_id']);
        if (array_key_exists('vendor_id', $change) && (int) $change['vendor_id'] > 0) $this->put($update, $columns, ['supplier_party_id', 'vendor_id', 'supplier_id', 'service_provider_id'], (int) $change['vendor_id']);
        $cost = round((float) ($change['cost_amount'] ?? 0), 2);
        $currency = strtoupper(trim((string) ($change['currency_code'] ?? 'PKR')));
        $fx = (float) ($change['fx_rate'] ?? 1);
        $this->put($update, $columns, ['supplier_amount'], round((float) ($change['cost_foreign'] ?? $cost), 2));
        $this->put($update, $columns, ['supplier_amount_pkr', 'cost_price_base', 'cost_amount_pkr'], $cost);
        $this->put($update, $columns, ['cost_price'], $cost);
        $this->put($update, $columns, ['cost_currency_code', 'supplier_currency_code'], $currency);
        $this->put($update, $columns, ['cost_fx_rate', 'fx_rate_to_pkr'], $fx);
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if ($update) DB::table($table)->where('id', $id)->update($update);
        if ((int) ($change['vendor_id'] ?? 0) > 0 && (int) ($row->booking_service_id ?? 0) > 0) $this->syncServiceVendor((int) $row->booking_service_id, (int) $change['vendor_id']);
        return [$old, (array) DB::table($table)->where('id', $id)->first()];
    }

    private function syncServiceVendor(int $serviceId, int $vendorId): void
    {
        if ($serviceId <= 0 || ! Schema::hasTable('booking_services')) return;
        $columns = Schema::getColumnListing('booking_services'); $update = [];
        $this->put($update, $columns, ['vendor_id', 'supplier_id', 'service_provider_id'], $vendorId);
        if (in_array('updated_at', $columns, true)) $update['updated_at'] = now();
        if ($update) DB::table('booking_services')->where('id', $serviceId)->update($update);
    }

    private function put(array &$target, array $columns, array $candidates, mixed $value): void
    {
        foreach ($candidates as $column) {
            if (in_array($column, $columns, true)) { $target[$column] = $value; return; }
        }
    }
}
