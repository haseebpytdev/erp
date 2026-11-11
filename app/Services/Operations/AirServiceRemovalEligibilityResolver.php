<?php

namespace App\Services\Operations;

use App\Services\Administration\ErpBranchAccessAuthority;
use App\Services\Purchase\BookingSupplierObligationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only, fail-closed authority for a future Air-service removal flow.
 *
 * This class deliberately performs no mutation and is not wired to the Air
 * save/delete controller.  ELIGIBLE is returned only when every dependency
 * contract has been inspected successfully and no reference was found.
 */
final class AirServiceRemovalEligibilityResolver
{
    public const ELIGIBLE = 'ELIGIBLE';
    public const UNKNOWN = 'UNKNOWN_DEPENDENCY';

    public function __construct(
        private readonly NativeProductServiceResolver $products,
        private readonly ErpBranchAccessAuthority $branches,
        private readonly BookingBillingEditLockResolver $billingLocks,
        private readonly BookingSupplierObligationResolver $obligations,
    ) {
    }

    /** @return array{eligible:bool,code:string,message:string,booking_id:int,booking_service_id:int,checks:array,reason_codes:string[]} */
    public function resolve(int $bookingId, int $serviceId, mixed $user = null): array
    {
        $result = $this->base($bookingId, $serviceId);
        if ($bookingId <= 0 || $serviceId <= 0) {
            return $this->blocked($result, 'INVALID_CONTEXT', 'Air service removal context is invalid.');
        }

        try {
            $this->requireColumns('bookings', ['id']);
            $this->requireColumns('booking_services', ['id', 'booking_id', 'product_service_id']);
            $booking = DB::table('bookings')->where('id', $bookingId)->first();
            $service = DB::table('booking_services')->where('id', $serviceId)->where('booking_id', $bookingId)->first();
            if (! $booking || ! $service) {
                return $this->blocked($result, 'CONTEXT_NOT_FOUND', 'The Air service context could not be verified.');
            }
            $air = $this->products->findAir();
            if (! $air || (int) ($air['id'] ?? 0) !== (int) $service->product_service_id) {
                return $this->blocked($result, 'NOT_AIR_SERVICE', 'The requested service is not the native Air service.');
            }
            $result['checks']['service_context'] = 'passed';
            $this->checkUserContext($booking, $user);

            $this->checkInvoiceLines($serviceId, $result);
            $this->checkServiceCostAllocations($serviceId, $result);
            $this->checkSupplierCostingLinks($serviceId, $result);
            $this->checkSupplierObligations($bookingId, $serviceId, $result);
            $this->checkBillingBatchItems($serviceId, $result);
            $this->checkBookingFinancialLock($bookingId, $result);
            $this->checkVoucherAndAccounting($bookingId, $serviceId, $result);
            $this->checkRefundContracts($bookingId, $serviceId, $result);
        } catch (AirRemovalBlocked $e) {
            return $this->blocked($result, $e->getMessage(), 'A financial or operational dependency blocks Air service removal.');
        } catch (AirRemovalUnknownDependency $e) {
            return $this->blocked($result, self::UNKNOWN, 'A required Air financial dependency could not be evaluated safely.', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->blocked($result, self::UNKNOWN, 'A required Air financial dependency could not be evaluated safely.', get_class($e));
        }

        $result['eligible'] = true;
        $result['code'] = self::ELIGIBLE;
        $result['message'] = 'The Air service has no evaluated financial or operational dependency blocking removal.';
        return $result;
    }

    private function checkUserContext(object $booking, mixed $user): void
    {
        if ($user === null) throw new AirRemovalUnknownDependency('authenticated_scope');
        $companyColumn = Schema::hasColumn('bookings', 'company_id');
        $branchColumn = Schema::hasColumn('bookings', 'branch_id');
        if (! $companyColumn || ! $branchColumn) throw new AirRemovalUnknownDependency('booking_scope_columns');
        $company = (int) ($booking->company_id ?? 0);
        $branch = (int) ($booking->branch_id ?? 0);
        if ($company <= 0 || $branch <= 0 || (int) $this->branches->companyIdForUser($user) !== $company || ! $this->branches->mayAccessBranch($user, $branch)) {
            throw new AirRemovalUnknownDependency('authorized_booking_scope');
        }
    }

    private function checkInvoiceLines(int $serviceId, array &$result): void
    {
        $this->requireColumns('sales_invoice_lines', ['source_booking_service_id']);
        $hit = DB::table('sales_invoice_lines')->where('source_booking_service_id', $serviceId)->exists();
        $result['checks']['invoice_lines'] = $hit ? 'blocked' : 'passed';
        if ($hit) throw new AirRemovalBlocked('INVOICE_LINE_LINKED');
    }

    private function checkServiceCostAllocations(int $serviceId, array &$result): void
    {
        $this->requireColumns('service_cost_allocations', ['booking_service_id']);
        $hit = DB::table('service_cost_allocations')->where('booking_service_id', $serviceId)->exists();
        $result['checks']['service_cost_allocations'] = $hit ? 'blocked' : 'passed';
        if ($hit) throw new AirRemovalBlocked('SERVICE_COST_ALLOCATION_LINKED');
    }

    private function checkSupplierCostingLinks(int $serviceId, array &$result): void
    {
        $this->requireColumns('supplier_costing_source_links', ['booking_service_id']);
        $hit = DB::table('supplier_costing_source_links')->where('booking_service_id', $serviceId)->exists();
        $result['checks']['supplier_costing_source_links'] = $hit ? 'blocked' : 'passed';
        if ($hit) throw new AirRemovalBlocked('SUPPLIER_COSTING_LINKED');
    }

    private function checkBillingBatchItems(int $serviceId, array &$result): void
    {
        $this->requireColumns('general_booking_billing_batch_items', ['booking_service_id']);
        $hit = DB::table('general_booking_billing_batch_items')->where('booking_service_id', $serviceId)->exists();
        $result['checks']['billing_batch_items'] = $hit ? 'blocked' : 'passed';
        if ($hit) throw new AirRemovalBlocked('BILLING_BATCH_ITEM_LINKED');
    }

    private function checkSupplierObligations(int $bookingId, int $serviceId, array &$result): void
    {
        $this->requireColumns('air_ticket_details', ['booking_service_id']);
        $rows = $this->obligations->resolve($bookingId);
        foreach ($rows as $row) {
            if ((int) ($row['booking_service_id'] ?? 0) !== $serviceId) continue;
            $result['checks']['supplier_obligations'] = 'blocked';
            throw new AirRemovalBlocked('SUPPLIER_OBLIGATION_LINKED');
        }
        $result['checks']['supplier_obligations'] = 'passed';
    }

    private function checkBookingFinancialLock(int $bookingId, array &$result): void
    {
        $lock = $this->billingLocks->resolve($bookingId);
        if (! is_array($lock) || ! array_key_exists('locked', $lock)) throw new AirRemovalUnknownDependency('booking_billing_lock');
        $result['checks']['booking_billing_lock'] = ! empty($lock['locked']) ? 'blocked' : 'passed';
        if (! empty($lock['locked'])) throw new AirRemovalBlocked('BOOKING_FINANCIAL_LOCK');
    }

    private function checkVoucherAndAccounting(int $bookingId, int $serviceId, array &$result): void
    {
        $this->requireColumns('cash_vouchers', ['booking_id']);
        $voucher = DB::table('cash_vouchers')->where('booking_id', $bookingId)->exists();
        $result['checks']['cash_vouchers'] = $voucher ? 'blocked' : 'passed';
        if ($voucher) throw new AirRemovalBlocked('VOUCHER_LINKED');
        $this->requireColumns('journal_entries', ['id']);
        $journalColumns = Schema::getColumnListing('journal_entries');
        $serviceColumn = in_array('booking_service_id', $journalColumns, true) ? 'booking_service_id' : null;
        $bookingColumn = in_array('booking_id', $journalColumns, true) ? 'booking_id' : null;
        if (! $serviceColumn && ! $bookingColumn) {
            throw new AirRemovalUnknownDependency('journal_entries.service_link');
        }
        $journalQuery = DB::table('journal_entries');
        if ($serviceColumn) $journalQuery->where($serviceColumn, $serviceId);
        else $journalQuery->where($bookingColumn, $bookingId);
        $journal = $journalQuery->exists();
        $result['checks']['journal_entries'] = $journal ? 'blocked' : 'passed';
        if ($journal) throw new AirRemovalBlocked('JOURNAL_ENTRY_LINKED');
    }

    private function checkRefundContracts(int $bookingId, int $serviceId, array &$result): void
    {
        // The installed schema has no refunds/booking_refunds table. Native
        // customer refunds are represented by return allocations, whose
        // booking_id is the authoritative booking-level bridge.
        $this->requireColumns('customer_advance_returns', ['id', 'status']);
        $this->requireColumns('customer_advance_return_allocations', ['customer_advance_return_id', 'booking_id']);
        $hit = DB::table('customer_advance_return_allocations as a')
            ->join('customer_advance_returns as r', 'r.id', '=', 'a.customer_advance_return_id')
            ->where('a.booking_id', $bookingId)
            ->exists();
        $result['checks']['refund_allocations'] = $hit ? 'blocked' : 'passed';
        if ($hit) throw new AirRemovalBlocked('REFUND_LINKED');
    }

    private function requireColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) throw new AirRemovalUnknownDependency($table . '.table');
        foreach ($columns as $column) if (! Schema::hasColumn($table, $column)) throw new AirRemovalUnknownDependency($table . '.' . $column);
    }

    private function base(int $bookingId, int $serviceId): array
    { return ['eligible' => false, 'code' => self::UNKNOWN, 'message' => '', 'booking_id' => $bookingId, 'booking_service_id' => $serviceId, 'checks' => [], 'reason_codes' => []]; }

    private function blocked(array $result, string $code, string $message, ?string $detail = null): array
    { $result['eligible'] = false; $result['code'] = $code; $result['message'] = $message; $result['reason_codes'] = [$code]; if ($detail !== null) $result['checks']['blocked_detail'] = $detail; return $result; }
}

final class AirRemovalBlocked extends \RuntimeException
{
    public function __construct(string $code) { parent::__construct($code); }
}

final class AirRemovalUnknownDependency extends \RuntimeException
{
}
