<?php

namespace App\Services\Accounting;

use App\Services\Administration\ErpPermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CashVoucherService
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions,
        private readonly ChartOfAccountsWorkspaceService $chartAccounts,
        private readonly CashVoucherNativeJournalBridge $nativeJournal,
    ) {
    }

    public function nextNumber(string $type): string
    {
        $prefix = match ($type) {
            'receipt' => 'RV',
            'payment' => 'PV',
            'expense' => 'EV',
            'contra' => 'CV',
            'customer_advance' => 'CAR',
            'supplier_advance' => 'SAP',
            default => throw new RuntimeException('Unsupported cash voucher type.'),
        };

        $year = now()->format('Y');
        $stem = $prefix.'-'.$year.'-';
        $last = DB::table('cash_vouchers')
            ->where('voucher_no', 'like', $stem.'%')
            ->orderByDesc('id')
            ->value('voucher_no');
        $seq = $last && preg_match('/(\d+)$/', (string) $last, $m)
            ? ((int) $m[1] + 1)
            : 1000;

        return $stem.(string) $seq;
    }

    public function nextAdjustmentNumber(): string
    {
        $year = now()->format('Y');
        $stem = 'AA-'.$year.'-';
        $last = DB::table('advance_adjustments')
            ->where('adjustment_no', 'like', $stem.'%')
            ->orderByDesc('id')
            ->value('adjustment_no');
        $seq = $last && preg_match('/(\d+)$/', (string) $last, $m)
            ? ((int) $m[1] + 1)
            : 1000;

        return $stem.(string) $seq;
    }

    public function voucherDefinition(string $type): array
    {
        return match ($type) {
            'receipt' => [
                'label' => 'Receipt Voucher',
                'short' => 'Receipt',
                'direction' => 'in',
                'party_type' => 'customer',
                'target_type' => 'sales_invoice',
                'target_label' => 'Sales Invoice',
            ],
            'payment' => [
                'label' => 'Payment Voucher',
                'short' => 'Payment',
                'direction' => 'out',
                'party_type' => 'supplier',
                'target_type' => 'supplier_costing',
                'target_label' => 'Supplier Costing',
            ],
            'expense' => [
                'label' => 'Expense Voucher',
                'short' => 'Expense',
                'direction' => 'out',
                'party_type' => 'expense',
                'target_type' => null,
                'target_label' => null,
            ],
            'contra' => [
                'label' => 'Contra Voucher',
                'short' => 'Contra',
                'direction' => 'transfer',
                'party_type' => 'none',
                'target_type' => null,
                'target_label' => null,
            ],
            'customer_advance' => [
                'label' => 'Customer Advance Receipt',
                'short' => 'Customer Advance',
                'direction' => 'in',
                'party_type' => 'customer',
                'target_type' => null,
                'target_label' => null,
            ],
            'supplier_advance' => [
                'label' => 'Supplier Advance Payment',
                'short' => 'Supplier Advance',
                'direction' => 'out',
                'party_type' => 'supplier',
                'target_type' => null,
                'target_label' => null,
            ],
            default => throw new RuntimeException('Unsupported cash voucher type.'),
        };
    }

    public function canUseType(mixed $user, string $type, string $action = 'view'): bool
    {
        if ($this->permissions->isSuperAdmin($user)) {
            return true;
        }

        if ($type === 'contra') {
            $phrases = [
                'view contra vouchers',
                'create contra vouchers',
                'update contra vouchers',
                'approve contra vouchers',
                'post contra vouchers',
                'reverse contra vouchers',
                'manage contra vouchers',
            ];
        } elseif ($type === 'expense') {
            $phrases = [
                'view expense vouchers',
                'create expense vouchers',
                'update expense vouchers',
                'approve expense vouchers',
                'post expense vouchers',
                'reverse expense vouchers',
                'manage expense vouchers',
            ];
        } else {
            $isReceipt = in_array($type, ['receipt', 'customer_advance'], true);
            $phrases = $isReceipt
            ? [
                'view receipts', 'create receipts', 'update receipts', 'approve receipts', 'post receipts', 'manage receipts',
                'view receipt vouchers', 'create receipt vouchers', 'approve receipt vouchers', 'post receipt vouchers', 'manage receipt vouchers',
                'manage customer advances', 'customer advances',
            ]
            : [
                'view payments', 'create payments', 'update payments', 'approve payments', 'post payments', 'manage payments',
                'view payment vouchers', 'create payment vouchers', 'approve payment vouchers', 'post payment vouchers', 'manage payment vouchers',
                'manage supplier advances', 'supplier advances',
            ];
        }

        $actionPhrases = match ($action) {
            'view' => array_values(array_filter($phrases, fn (string $p): bool => str_contains($p, 'view') || str_contains($p, 'manage'))),
            'create' => array_values(array_filter($phrases, fn (string $p): bool => str_contains($p, 'create') || str_contains($p, 'manage') || str_contains($p, 'advance'))),
            'update' => array_values(array_filter($phrases, fn (string $p): bool => str_contains($p, 'update') || str_contains($p, 'manage'))),
            'approve' => array_values(array_filter($phrases, fn (string $p): bool => str_contains($p, 'approve') || str_contains($p, 'manage'))),
            'post' => array_values(array_filter($phrases, fn (string $p): bool => str_contains($p, 'post') || str_contains($p, 'manage'))),
            'reverse' => array_values(array_filter($phrases, fn (string $p): bool => str_contains($p, 'reverse') || str_contains($p, 'post') || str_contains($p, 'manage'))),
            default => $phrases,
        };

        return $this->permissions->hasPermissionLike($user, $actionPhrases ?: $phrases);
    }

    public function canApprove(mixed $user, string $type): bool
    {
        return $this->canUseType($user, $type, 'approve')
            || $this->canUseType($user, $type, 'post');
    }

    public function partyOptions(string $partyType): array
    {
        foreach (['parties', 'party_master', 'party_masters', 'customers', 'suppliers', 'vendors'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $cols = Schema::getColumnListing($table);
            $id = $this->first($cols, ['id', 'party_id', 'customer_id', 'supplier_id', 'vendor_id']);
            $name = $this->first($cols, ['name', 'party_name', 'customer_name', 'supplier_name', 'vendor_name', 'display_name', 'company_name']);
            if (! $id || ! $name) {
                continue;
            }

            $query = DB::table($table)->select([$id.' as id', $name.' as name']);
            $type = $this->first($cols, ['type', 'party_type', 'category']);
            if ($type) {
                $query->where(function ($q) use ($type, $partyType): void {
                    if ($partyType === 'supplier') {
                        $q->where($type, 'like', '%supplier%')
                            ->orWhere($type, 'like', '%vendor%');
                    } else {
                        $q->where($type, 'like', '%customer%')
                            ->orWhere($type, 'like', '%client%')
                            ->orWhere($type, 'like', '%agent%');
                    }
                });
            } elseif ($partyType === 'supplier' && in_array($table, ['customers'], true)) {
                continue;
            } elseif ($partyType === 'customer' && in_array($table, ['suppliers', 'vendors'], true)) {
                continue;
            }

            $rows = $query->orderBy($name)->limit(1000)->get();
            if ($rows->isNotEmpty()) {
                return $rows->map(fn ($r): array => ['id' => (int) $r->id, 'name' => (string) $r->name])->all();
            }
        }

        return [];
    }

    public function resolvePartyName(string $partyType, ?int $partyId, ?string $fallback = null): ?string
    {
        if ($partyId) {
            foreach ($this->partyOptions($partyType) as $party) {
                if ((int) $party['id'] === $partyId) {
                    return (string) $party['name'];
                }
            }
        }

        return $fallback !== null && trim($fallback) !== '' ? trim($fallback) : null;
    }

    public function bookingOptions(): array
    {
        foreach (['bookings', 'travel_bookings', 'booking_group_package_unified'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $cols = Schema::getColumnListing($table);
            $id = $this->first($cols, ['id', 'booking_id']);
            $ref = $this->first($cols, ['booking_no', 'booking_number', 'booking_ref', 'reference', 'booking_reference']);
            if (! $id) {
                continue;
            }
            $select = [$id.' as id'];
            if ($ref) {
                $select[] = $ref.' as reference';
            }
            return DB::table($table)
                ->select($select)
                ->orderByDesc($id)
                ->limit(500)
                ->get()
                ->map(fn ($r): array => [
                    'id' => (int) $r->id,
                    'reference' => (string) ($r->reference ?? ('Booking #'.$r->id)),
                ])->all();
        }

        return [];
    }

    public function cashBankAccounts(): array
    {
        /*
         * ERP-11.3.9
         * Real bank ledgers are Asset accounts with Subtype=BANK beneath 1020.
         * Read them from the same adaptive live Chart used by the COA workspace.
         */
        try {
            $s = $this->chartAccounts->schema();
            $select = [
                $s['id'].' as id',
                $s['code'].' as code',
                $s['name'].' as name',
            ];
            if ($s['type']) {
                $select[] = $s['type'].' as account_type';
            }
            if ($s['subtype']) {
                $select[] = $s['subtype'].' as account_subtype';
            }
            if ($s['parent']) {
                $select[] = $s['parent'].' as parent_value';
            }
            if ($s['posting']) {
                $select[] = $s['posting'].' as posting_allowed';
            }
            if ($s['active']) {
                $select[] = $s['active'].' as active_flag';
            }
            if ($s['status']) {
                $select[] = $s['status'].' as account_status';
            }

            $query = DB::table($s['table'])->select($select);

            if ($s['active']) {
                $query->where($s['active'], 1);
            }
            if ($s['status']) {
                $query->where(function ($q) use ($s): void {
                    $q->whereNull($s['status'])
                        ->orWhereRaw('LOWER('.$s['status'].') IN (?, ?, ?)', ['active', 'enabled', 'open']);
                });
            }
            if ($s['posting']) {
                $query->where($s['posting'], 1);
            }

            $query->where(function ($q) use ($s): void {
                $hasFirst = false;

                if ($s['subtype']) {
                    $q->where(function ($sub) use ($s): void {
                        $sub->where($s['subtype'], 'like', '%bank%')
                            ->orWhere($s['subtype'], 'like', '%cash%');
                    });
                    $hasFirst = true;
                }

                if ($s['name']) {
                    if ($hasFirst) {
                        $q->orWhere(function ($names) use ($s): void {
                            $names->where($s['name'], 'like', '%bank%')
                                ->orWhere($s['name'], 'like', '%cash%');
                        });
                    } else {
                        $q->where(function ($names) use ($s): void {
                            $names->where($s['name'], 'like', '%bank%')
                                ->orWhere($s['name'], 'like', '%cash%');
                        });
                    }
                }

                $q->orWhereIn($s['code'], ['1010', '1020']);
            });

            $rows = $query->orderBy($s['code'])->limit(500)->get();

            if ($rows->isNotEmpty()) {
                $accounts = $rows->map(static fn ($r): array => [
                    'id' => isset($r->id) ? (int) $r->id : null,
                    'code' => trim((string) $r->code),
                    'name' => trim((string) $r->name),
                    'subtype' => strtoupper(trim((string) ($r->account_subtype ?? ''))),
                ])->filter(static fn (array $a): bool => $a['code'] !== '' && $a['name'] !== '')
                  ->values();

                $hasRealChildBank = $accounts->contains(static fn (array $a): bool =>
                    $a['code'] !== '1020'
                    && (
                        str_contains($a['subtype'], 'BANK')
                        || stripos($a['name'], 'bank') !== false
                    )
                );

                if ($hasRealChildBank) {
                    $accounts = $accounts
                        ->reject(static fn (array $a): bool => $a['code'] === '1020')
                        ->values();
                }

                return $accounts
                    ->unique('code')
                    ->map(static fn (array $a): array => [
                        'id' => $a['id'],
                        'code' => $a['code'],
                        'name' => $a['name'],
                        'subtype' => $a['subtype'],
                    ])
                    ->values()
                    ->all();
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return (array) config('cash_vouchers.fallback_cash_bank_accounts', [
            ['code' => '1010', 'name' => 'Cash'],
            ['code' => '1020', 'name' => 'Bank'],
        ]);
    }

    public function paymentMethods(): array
    {
        return (array) config('cash_vouchers.payment_methods', ['Cash', 'Bank Transfer', 'Cheque', 'Card', 'Online', 'Other']);
    }

    public function transferMethods(): array
    {
        return (array) config('cash_vouchers.transfer_methods', ['Cash Transfer', 'Bank Transfer', 'Cheque', 'Online', 'Other']);
    }

    public function cashBankAccount(string $code): array
    {
        foreach ($this->cashBankAccounts() as $account) {
            if ((string) $account['code'] === $code) {
                return [
                    'id' => isset($account['id']) ? (int) $account['id'] : null,
                    'code' => (string) $account['code'],
                    'name' => (string) $account['name'],
                    'subtype' => (string) ($account['subtype'] ?? ''),
                ];
            }
        }

        throw new RuntimeException('Selected Cash / Bank account is not an active posting Cash / Bank account.');
    }

    /**
     * Active posting Expense accounts from the same adaptive Chart authority.
     * No code-prefix inference or fallback expense account is permitted.
     */
    public function expenseAccounts(): array
    {
        $s = $this->chartAccounts->schema();
        $select = [
            $s['id'].' as id',
            $s['code'].' as code',
            $s['name'].' as name',
            $s['type'].' as account_type',
        ];

        $query = DB::table($s['table'])->select($select)
            ->where(function ($q) use ($s): void {
                $q->whereRaw('LOWER('.$s['type'].') LIKE ?', ['%expense%'])
                    ->orWhereRaw('LOWER('.$s['type'].') LIKE ?', ['%cost%']);
            });

        if ($s['active']) {
            $query->where($s['active'], 1);
        }
        if ($s['status']) {
            $query->where(function ($q) use ($s): void {
                $q->whereNull($s['status'])
                    ->orWhereRaw(
                        'LOWER('.$s['status'].') IN (?, ?, ?)',
                        ['active', 'enabled', 'open']
                    );
            });
        }
        if ($s['posting']) {
            $query->where($s['posting'], 1);
        }
        if ($s['control_flag']) {
            $query->where(function ($q) use ($s): void {
                $q->whereNull($s['control_flag'])->orWhere($s['control_flag'], 0);
            });
        }

        $cashBankCodes = array_column($this->cashBankAccounts(), 'code');

        return $query->orderBy($s['code'])->limit(1000)->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'code' => trim((string) $row->code),
                'name' => trim((string) $row->name),
                'type' => trim((string) $row->account_type),
            ])
            ->reject(static fn (array $account): bool =>
                $account['id'] <= 0
                || $account['code'] === ''
                || $account['name'] === ''
                || in_array($account['code'], $cashBankCodes, true)
            )
            ->unique('id')
            ->values()
            ->all();
    }

    public function expenseAccount(int $accountId): array
    {
        foreach ($this->expenseAccounts() as $account) {
            if ((int) $account['id'] === $accountId) {
                return $account;
            }
        }

        throw new RuntimeException(
            'Selected Expense account is not an active posting Expense account.'
        );
    }

    public function documentOptions(string $targetType): array
    {
        return $targetType === 'supplier_costing'
            ? $this->supplierCostingOptions()
            : $this->salesInvoiceOptions();
    }

    public function salesInvoiceOptions(): array
    {
        $schema = $this->salesInvoiceSchema();
        if (! $schema) {
            return [];
        }

        $select = [$schema['id'].' as id'];
        foreach (['number', 'status', 'amount', 'booking', 'party_id', 'party_name', 'currency'] as $key) {
            if ($schema[$key]) {
                $select[] = $schema[$key].' as '.$key;
            }
        }

        $query = DB::table($schema['table'])->select($select)->orderByDesc($schema['id'])->limit(500);
        $rows = $query->get();
        $result = [];
        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row->status ?? '')));
            if (in_array($status, ['draft', 'new', 'pending', 'pending_approval', 'submitted', 'approved', 'authorized', 'authorised', 'cancelled', 'canceled', 'void', 'voided', 'rejected'], true)) {
                continue;
            }
            $id = (int) $row->id;
            $total = round((float) ($row->amount ?? 0), 2);
            $outstanding = $this->targetOutstanding('sales_invoice', $id, $total);
            if ($total <= 0) {
                continue;
            }
            $result[] = [
                'id' => $id,
                'number' => trim((string) ($row->number ?? '')) ?: ('Invoice #'.$id),
                'status' => $status ?: 'open',
                'total' => $total,
                'outstanding' => $outstanding,
                'booking_id' => isset($row->booking) ? (int) $row->booking : null,
                'party_id' => isset($row->party_id) ? (int) $row->party_id : null,
                'party_name' => (string) ($row->party_name ?? ''),
                'currency_code' => (string) ($row->currency ?? 'PKR'),
                'target_type' => 'sales_invoice',
            ];
        }

        return $result;
    }

    public function supplierCostingOptions(): array
    {
        if (! Schema::hasTable('supplier_costings')) {
            return [];
        }

        return DB::table('supplier_costings')
            ->where('status', 'posted')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(function ($row): array {
                $total = round((float) $row->total_cost, 2);
                return [
                    'id' => (int) $row->id,
                    'number' => (string) $row->costing_no,
                    'status' => (string) $row->status,
                    'total' => $total,
                    'outstanding' => $this->targetOutstanding('supplier_costing', (int) $row->id, $total),
                    'booking_id' => $row->booking_id ? (int) $row->booking_id : null,
                    'party_id' => $row->supplier_id ? (int) $row->supplier_id : null,
                    'party_name' => (string) ($row->supplier_name ?? ''),
                    'currency_code' => (string) ($row->currency_code ?: 'PKR'),
                    'target_type' => 'supplier_costing',
                ];
            })->all();
    }

    public function documentSnapshot(string $targetType, int $targetId): array
    {
        foreach ($this->documentOptions($targetType) as $document) {
            if ((int) $document['id'] === $targetId) {
                return $document;
            }
        }

        throw new RuntimeException('The selected accounting document is not available for allocation. It may be Draft, reversed, cancelled, or missing.');
    }

    public function targetOutstanding(string $targetType, int $targetId, ?float $knownTotal = null): float
    {
        $total = $knownTotal;
        if ($total === null) {
            $total = $this->targetTotal($targetType, $targetId);
        }

        $allocated = 0.0;
        if (Schema::hasTable('cash_voucher_allocations') && Schema::hasTable('cash_vouchers')) {
            $allocated += (float) DB::table('cash_voucher_allocations as a')
                ->join('cash_vouchers as v', 'v.id', '=', 'a.cash_voucher_id')
                ->where('a.target_type', $targetType)
                ->where('a.target_id', $targetId)
                ->where('v.status', 'posted')
                ->sum('a.amount');
        }

        if (Schema::hasTable('advance_adjustments')) {
            $allocated += (float) DB::table('advance_adjustments')
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->where('status', 'posted')
                ->sum('amount');
        }

        return max(0, round((float) $total - $allocated, 2));
    }

    public function availableAdvance(int $voucherId): float
    {
        $voucher = DB::table('cash_vouchers')->where('id', $voucherId)->first();
        if (! $voucher || $voucher->status !== 'posted') {
            return 0.0;
        }
        $base = round((float) $voucher->unallocated_amount, 2);
        if ($base <= 0) {
            return 0.0;
        }
        $used = Schema::hasTable('advance_adjustments')
            ? (float) DB::table('advance_adjustments')
                ->where('advance_voucher_id', $voucherId)
                ->where('status', 'posted')
                ->sum('amount')
            : 0.0;

        return max(0, round($base - $used, 2));
    }

    public function advanceOptions(?string $partyType = null): array
    {
        if (! Schema::hasTable('cash_vouchers')) {
            return [];
        }

        $query = DB::table('cash_vouchers')->where('status', 'posted')->where('unallocated_amount', '>', 0);
        if ($partyType === 'customer') {
            $query->where('party_type', 'customer');
        } elseif ($partyType === 'supplier') {
            $query->where('party_type', 'supplier');
        }

        $result = [];
        foreach ($query->orderByDesc('id')->limit(500)->get() as $row) {
            $available = $this->availableAdvance((int) $row->id);
            if ($available <= 0) {
                continue;
            }
            $result[] = [
                'id' => (int) $row->id,
                'voucher_no' => (string) $row->voucher_no,
                'voucher_type' => (string) $row->voucher_type,
                'party_type' => (string) $row->party_type,
                'party_id' => $row->party_id ? (int) $row->party_id : null,
                'party_name' => (string) ($row->party_name ?? ''),
                'booking_id' => $row->booking_id ? (int) $row->booking_id : null,
                'currency_code' => (string) ($row->currency_code ?: 'PKR'),
                'exchange_rate' => (float) ($row->exchange_rate ?: 1),
                'available' => $available,
            ];
        }

        return $result;
    }

    public function recalculate(int $voucherId): void
    {
        $voucher = DB::table('cash_vouchers')->where('id', $voucherId)->first();
        if (! $voucher) {
            throw new RuntimeException('Cash voucher not found.');
        }
        if ((string) $voucher->voucher_type === 'contra') {
            $detail = DB::table('cash_voucher_contra_details')
                ->where('cash_voucher_id', $voucherId)
                ->first();

            if (! $detail || (float) $detail->amount <= 0) {
                throw new RuntimeException('Contra Voucher requires one positive destination transfer detail.');
            }

            DB::table('cash_vouchers')->where('id', $voucherId)->update([
                'amount' => round((float) $detail->amount, 2),
                'allocated_amount' => 0,
                'unallocated_amount' => 0,
                'updated_at' => now(),
            ]);
            return;
        }
        if ((string) $voucher->voucher_type === 'expense') {
            $amount = round((float) DB::table('cash_voucher_expense_lines')
                ->where('cash_voucher_id', $voucherId)
                ->sum('amount'), 2);

            if ($amount <= 0) {
                throw new RuntimeException('Expense Voucher requires at least one positive expense line.');
            }

            DB::table('cash_vouchers')->where('id', $voucherId)->update([
                'amount' => $amount,
                'allocated_amount' => 0,
                'unallocated_amount' => 0,
                'updated_at' => now(),
            ]);
            return;
        }

        $allocated = (float) DB::table('cash_voucher_allocations')
            ->where('cash_voucher_id', $voucherId)
            ->sum('amount');
        $amount = round((float) $voucher->amount, 2);
        $allocated = round($allocated, 2);
        if ($allocated > $amount + 0.005) {
            throw new RuntimeException('Allocated amount cannot exceed the voucher amount.');
        }

        DB::table('cash_vouchers')->where('id', $voucherId)->update([
            'allocated_amount' => $allocated,
            'unallocated_amount' => max(0, round($amount - $allocated, 2)),
            'updated_at' => now(),
        ]);
    }

    public function transition(int $voucherId, string $action, mixed $user): void
    {
        DB::transaction(function () use ($voucherId, $action, $user): void {
            $voucher = DB::table('cash_vouchers')->where('id', $voucherId)->lockForUpdate()->first();
            if (! $voucher) {
                throw new RuntimeException('Cash voucher not found.');
            }

            $map = [
                'submit' => ['draft', 'pending_approval'],
                'approve' => ['pending_approval', 'approved'],
                'post' => ['approved', 'posted'],
            ];
            if (! isset($map[$action])) {
                throw new RuntimeException('Unsupported cash voucher workflow action.');
            }
            [$from, $to] = $map[$action];
            if ($voucher->status !== $from) {
                throw new RuntimeException('Workflow action is not valid for the current voucher status.');
            }
            if (! $this->canUseType($user, (string) $voucher->voucher_type, $action === 'submit' ? 'update' : $action)) {
                throw new RuntimeException('You are not authorized for this cash voucher workflow action.');
            }
            if ($action === 'post') {
                $this->lockAllocationTargets($voucherId);
            }

            $this->validateVoucherForPosting($voucherId, (string) $voucher->voucher_type);
            $update = ['status' => $to, 'updated_at' => now()];
            if ($action === 'submit') {
                $update['submitted_by'] = $user?->id;
                $update['submitted_at'] = now();
            }
            if ($action === 'approve') {
                $update['approved_by'] = $user?->id;
                $update['approved_at'] = now();
            }
            if ($action === 'post') {
                $reference = 'CVPOST-'.now()->format('Ymd').'-'.(string) $voucherId;
                $this->createVoucherPosting($voucherId, $reference);

                // ERP-11.3.19: mirror the controlled posting into the native
                // journal inside this same transaction. Any bridge failure
                // rolls back the entire Post action.
                $this->nativeJournal->postVoucher($voucherId, $user);

                $update['posted_by'] = $user?->id;
                $update['posted_at'] = now();
                $update['posting_reference'] = $reference;
            }

            DB::table('cash_vouchers')->where('id', $voucherId)->update($update);
            $this->activity($voucherId, $action, $from, $to, $user, $action === 'post' ? 'Balanced cash/bank posting created.' : null);
        });
    }

    public function reverseVoucher(int $voucherId, mixed $user, string $reason): void
    {
        DB::transaction(function () use ($voucherId, $user, $reason): void {
            $voucher = DB::table('cash_vouchers')->where('id', $voucherId)->lockForUpdate()->first();
            if (! $voucher) {
                throw new RuntimeException('Cash voucher not found.');
            }
            if ($voucher->status !== 'posted') {
                throw new RuntimeException('Only a Posted cash voucher can be reversed.');
            }
            if (! $this->canUseType($user, (string) $voucher->voucher_type, 'reverse')) {
                throw new RuntimeException('You are not authorized to reverse this cash voucher.');
            }
            if (trim($reason) === '') {
                throw new RuntimeException('A reversal reason is required.');
            }
            if (Schema::hasTable('advance_adjustments') && DB::table('advance_adjustments')->where('advance_voucher_id', $voucherId)->where('status', 'posted')->exists()) {
                throw new RuntimeException('This voucher has Posted advance adjustments. Reverse those adjustments first, then reverse the source voucher.');
            }
            if (DB::table('cash_voucher_posting_lines')->where('cash_voucher_id', $voucherId)->where('entry_type', 'reversal')->exists()) {
                throw new RuntimeException('This cash voucher is already reversed.');
            }

            $reference = 'CVREV-'.now()->format('Ymd').'-'.(string) $voucherId;
            $original = DB::table('cash_voucher_posting_lines')
                ->where('cash_voucher_id', $voucherId)
                ->where('entry_type', 'original')
                ->orderBy('id')
                ->get();
            if ($original->isEmpty()) {
                throw new RuntimeException('Original accounting posting lines are missing; reversal stopped.');
            }
            $now = now();
            foreach ($original as $line) {
                DB::table('cash_voucher_posting_lines')->insert([
                    'cash_voucher_id' => $voucherId,
                    'posting_reference' => $reference,
                    'entry_type' => 'reversal',
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'party_type' => $line->party_type,
                    'party_id' => $line->party_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'currency_code' => $line->currency_code,
                    'exchange_rate' => $line->exchange_rate,
                    'narration' => 'REVERSAL: '.$reason.' | '.($line->narration ?? ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // ERP-11.3.19: native reversal journal is created before the
            // voucher is marked Reversed, in this same transaction.
            $this->nativeJournal->postReversal($voucherId, $user);

            DB::table('cash_vouchers')->where('id', $voucherId)->update([
                'status' => 'reversed',
                'reversed_by' => $user?->id,
                'reversed_at' => now(),
                'reversal_reference' => $reference,
                'reversal_reason' => $reason,
                'updated_at' => now(),
            ]);
            $this->activity($voucherId, 'reverse', 'posted', 'reversed', $user, $reason);
        });
    }

    public function transitionAdjustment(int $adjustmentId, string $action, mixed $user): void
    {
        DB::transaction(function () use ($adjustmentId, $action, $user): void {
            $adjustment = DB::table('advance_adjustments')->where('id', $adjustmentId)->lockForUpdate()->first();
            if (! $adjustment) {
                throw new RuntimeException('Advance adjustment not found.');
            }
            $map = [
                'submit' => ['draft', 'pending_approval'],
                'approve' => ['pending_approval', 'approved'],
                'post' => ['approved', 'posted'],
            ];
            if (! isset($map[$action])) {
                throw new RuntimeException('Unsupported advance adjustment workflow action.');
            }
            [$from, $to] = $map[$action];
            if ($adjustment->status !== $from) {
                throw new RuntimeException('Workflow action is not valid for the current adjustment status.');
            }
            $voucher = DB::table('cash_vouchers')->where('id', $adjustment->advance_voucher_id)->lockForUpdate()->first();
            if (! $voucher) {
                throw new RuntimeException('Source advance voucher not found.');
            }
            if (! $this->canUseType($user, (string) $voucher->voucher_type, $action === 'submit' ? 'update' : $action)) {
                throw new RuntimeException('You are not authorized for this advance adjustment workflow action.');
            }

            if ($action === 'post') {
                $this->lockTarget((string) $adjustment->target_type, (int) $adjustment->target_id);
            }
            $available = $this->availableAdvance((int) $adjustment->advance_voucher_id);
            if ((float) $adjustment->amount <= 0 || (float) $adjustment->amount > $available + 0.005) {
                throw new RuntimeException('Adjustment amount exceeds the currently available advance balance.');
            }
            $outstanding = $this->targetOutstanding((string) $adjustment->target_type, (int) $adjustment->target_id);
            if ((float) $adjustment->amount > $outstanding + 0.005) {
                throw new RuntimeException('Adjustment amount exceeds the target document outstanding balance.');
            }

            $update = ['status' => $to, 'updated_at' => now()];
            if ($action === 'submit') {
                $update['submitted_by'] = $user?->id;
                $update['submitted_at'] = now();
            }
            if ($action === 'approve') {
                $update['approved_by'] = $user?->id;
                $update['approved_at'] = now();
            }
            if ($action === 'post') {
                $reference = 'AAPOST-'.now()->format('Ymd').'-'.(string) $adjustmentId;
                $this->createAdjustmentPosting($adjustmentId, $reference);
                $this->nativeJournal->postAdvanceAdjustment($adjustmentId, $user, $reference);
                $update['posted_by'] = $user?->id;
                $update['posted_at'] = now();
                $update['posting_reference'] = $reference;
            }
            DB::table('advance_adjustments')->where('id', $adjustmentId)->update($update);
            $this->adjustmentActivity($adjustmentId, $action, $from, $to, $user, $action === 'post' ? 'Advance applied without a cash movement.' : null);
        });
    }

    public function reverseAdjustment(int $adjustmentId, mixed $user, string $reason): void
    {
        DB::transaction(function () use ($adjustmentId, $user, $reason): void {
            $adjustment = DB::table('advance_adjustments')->where('id', $adjustmentId)->lockForUpdate()->first();
            if (! $adjustment || $adjustment->status !== 'posted') {
                throw new RuntimeException('Only a Posted advance adjustment can be reversed.');
            }
            $voucher = DB::table('cash_vouchers')->where('id', $adjustment->advance_voucher_id)->first();
            if (! $voucher || ! $this->canUseType($user, (string) $voucher->voucher_type, 'reverse')) {
                throw new RuntimeException('You are not authorized to reverse this advance adjustment.');
            }
            if (trim($reason) === '') {
                throw new RuntimeException('A reversal reason is required.');
            }
            if (DB::table('advance_adjustment_posting_lines')->where('advance_adjustment_id', $adjustmentId)->where('entry_type', 'reversal')->exists()) {
                throw new RuntimeException('This advance adjustment is already reversed.');
            }

            $reference = 'AAREV-'.now()->format('Ymd').'-'.(string) $adjustmentId;
            $original = DB::table('advance_adjustment_posting_lines')
                ->where('advance_adjustment_id', $adjustmentId)
                ->where('entry_type', 'original')
                ->orderBy('id')->get();
            if ($original->isEmpty()) {
                throw new RuntimeException('Original adjustment posting lines are missing; reversal stopped.');
            }
            $now = now();
            foreach ($original as $line) {
                DB::table('advance_adjustment_posting_lines')->insert([
                    'advance_adjustment_id' => $adjustmentId,
                    'posting_reference' => $reference,
                    'entry_type' => 'reversal',
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'party_type' => $line->party_type,
                    'party_id' => $line->party_id,
                    'debit' => (float) $line->credit,
                    'credit' => (float) $line->debit,
                    'currency_code' => $line->currency_code,
                    'exchange_rate' => $line->exchange_rate,
                    'narration' => 'REVERSAL: '.$reason.' | '.($line->narration ?? ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->nativeJournal->postAdvanceAdjustmentReversal($adjustmentId, $user);

            DB::table('advance_adjustments')->where('id', $adjustmentId)->update([
                'status' => 'reversed',
                'reversed_by' => $user?->id,
                'reversed_at' => now(),
                'reversal_reference' => $reference,
                'reversal_reason' => $reason,
                'updated_at' => now(),
            ]);
            $this->adjustmentActivity($adjustmentId, 'reverse', 'posted', 'reversed', $user, $reason);
        });
    }

    public function activity(int $voucherId, string $action, ?string $from, ?string $to, mixed $user, ?string $notes = null): void
    {
        DB::table('cash_voucher_activities')->insert([
            'cash_voucher_id' => $voucherId,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? $user?->email,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function adjustmentActivity(int $adjustmentId, string $action, ?string $from, ?string $to, mixed $user, ?string $notes = null): void
    {
        DB::table('advance_adjustment_activities')->insert([
            'advance_adjustment_id' => $adjustmentId,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? $user?->email,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validateVoucherForPosting(int $voucherId, string $type): void
    {
        $voucher = DB::table('cash_vouchers')->where('id', $voucherId)->first();
        if (! $voucher) {
            throw new RuntimeException('Cash voucher not found.');
        }
        if ((float) $voucher->amount <= 0) {
            throw new RuntimeException('Voucher amount must be greater than zero.');
        }
        if (trim((string) $voucher->cash_bank_account_code) === '') {
            throw new RuntimeException('Cash / Bank account is required.');
        }
        if (! in_array(
            (string) $voucher->cash_bank_account_code,
            array_column($this->cashBankAccounts(), 'code'),
            true
        )) {
            throw new RuntimeException('Cash / Bank account is no longer an active posting account.');
        }
        $definition = $this->voucherDefinition($type);
        $allocations = DB::table('cash_voucher_allocations')->where('cash_voucher_id', $voucherId)->orderBy('line_no')->get();
        if ($type === 'contra') {
            if ($allocations->isNotEmpty()) {
                throw new RuntimeException('Contra Vouchers cannot contain document allocations.');
            }

            $detail = DB::table('cash_voucher_contra_details')
                ->where('cash_voucher_id', $voucherId)
                ->first();
            if (! $detail) {
                throw new RuntimeException('Contra Voucher destination account detail is missing.');
            }

            $source = $this->cashBankAccount((string) $voucher->cash_bank_account_code);
            $destination = $this->cashBankAccount((string) $detail->destination_account_code);
            if ($source['code'] === $destination['code']) {
                throw new RuntimeException('Contra Voucher source and destination accounts must be different.');
            }
            if ((string) $detail->destination_account_name !== $destination['name']) {
                throw new RuntimeException('Contra destination account snapshot no longer matches the Chart of Accounts.');
            }
            if ((float) $detail->amount <= 0 || (float) $detail->exchange_rate <= 0) {
                throw new RuntimeException('Contra amount and exchange rate must be greater than zero.');
            }
            if (strtoupper((string) $detail->currency_code) !== strtoupper((string) $voucher->currency_code)) {
                throw new RuntimeException('Contra destination currency must match the voucher currency.');
            }
            if (abs((float) $detail->exchange_rate - (float) $voucher->exchange_rate) > 0.000000005) {
                throw new RuntimeException('Contra destination exchange rate must match the voucher exchange rate.');
            }
            if (abs((float) $detail->amount - (float) $voucher->amount) > 0.005) {
                throw new RuntimeException('Contra destination amount must equal the voucher header amount.');
            }

            $expectedBase = round((float) $detail->amount * (float) $detail->exchange_rate, 2);
            $headerBase = round((float) $voucher->amount * (float) $voucher->exchange_rate, 2);
            if (abs($expectedBase - (float) $detail->base_amount) > 0.005 || abs($expectedBase - $headerBase) > 0.005) {
                throw new RuntimeException('Contra base debit must equal the source Cash / Bank base credit.');
            }

            $this->recalculate($voucherId);
            return;
        }
        if ($type === 'expense') {
            if ($allocations->isNotEmpty()) {
                throw new RuntimeException('Expense Vouchers cannot contain document allocations.');
            }

            $lines = DB::table('cash_voucher_expense_lines')
                ->where('cash_voucher_id', $voucherId)
                ->orderBy('line_no')
                ->get();
            if ($lines->isEmpty()) {
                throw new RuntimeException('Expense Voucher requires at least one expense line.');
            }

            $lineAmount = 0.0;
            $baseAmount = 0.0;
            foreach ($lines as $line) {
                $account = $this->expenseAccount((int) $line->expense_account_id);
                if ((string) $line->expense_account_code !== (string) $account['code']) {
                    throw new RuntimeException('Expense account snapshot no longer matches the Chart of Accounts.');
                }
                if ((float) $line->amount <= 0 || (float) $line->exchange_rate <= 0) {
                    throw new RuntimeException('Expense line amount and exchange rate must be greater than zero.');
                }
                if (strtoupper((string) $line->currency_code) !== strtoupper((string) $voucher->currency_code)) {
                    throw new RuntimeException('Expense line currency must match the voucher currency.');
                }
                $expectedBase = round((float) $line->amount * (float) $line->exchange_rate, 2);
                if (abs($expectedBase - (float) $line->base_amount) > 0.005) {
                    throw new RuntimeException('Expense line base amount does not reconcile with currency and FX.');
                }
                $lineAmount += (float) $line->amount;
                $baseAmount += (float) $line->base_amount;
            }

            if (abs(round($lineAmount, 2) - round((float) $voucher->amount, 2)) > 0.005) {
                throw new RuntimeException('Expense line total does not equal the voucher header amount.');
            }
            if ($baseAmount <= 0) {
                throw new RuntimeException('Expense Voucher base total must be greater than zero.');
            }
            $headerBase = round(
                (float) $voucher->amount * (float) $voucher->exchange_rate,
                2
            );
            if (abs(round($baseAmount, 2) - $headerBase) > 0.005) {
                throw new RuntimeException(
                    'Expense line base total does not equal the Cash / Bank credit base total.'
                );
            }

            $this->recalculate($voucherId);
            return;
        }
        if ($definition['target_type'] === null && $allocations->isNotEmpty()) {
            throw new RuntimeException('Explicit advance vouchers cannot contain invoice/costing allocations. Use Advance Adjustment later.');
        }
        $grouped = [];
        foreach ($allocations as $allocation) {
            if ((string) $allocation->target_type !== (string) $definition['target_type']) {
                throw new RuntimeException('Allocation document type does not match this voucher type.');
            }
            $document = $this->documentSnapshot((string) $allocation->target_type, (int) $allocation->target_id);
            if ((float) $allocation->amount <= 0) {
                throw new RuntimeException('Allocation amount must be greater than zero.');
            }
            if ($voucher->party_id && $document['party_id'] && (int) $voucher->party_id !== (int) $document['party_id']) {
                throw new RuntimeException('Allocation party does not match the voucher party for '.$document['number'].'.');
            }
            if (strtoupper((string) ($document['currency_code'] ?? 'PKR')) !== strtoupper((string) ($voucher->currency_code ?? 'PKR'))) {
                throw new RuntimeException('Voucher currency does not match '.$document['number'].' currency.');
            }
            $key = $allocation->target_type.':'.$allocation->target_id;
            $grouped[$key] ??= ['document' => $document, 'amount' => 0.0];
            $grouped[$key]['amount'] += (float) $allocation->amount;
        }
        foreach ($grouped as $item) {
            $document = $item['document'];
            $postedElsewhere = $this->targetOutstanding((string) $document['target_type'], (int) $document['id'], (float) $document['total']);
            if ((float) $item['amount'] > $postedElsewhere + 0.005) {
                throw new RuntimeException('Combined allocation for '.$document['number'].' exceeds its current outstanding balance.');
            }
        }
        $this->recalculate($voucherId);
    }

    private function createVoucherPosting(int $voucherId, string $reference): void
    {
        if (DB::table('cash_voucher_posting_lines')->where('cash_voucher_id', $voucherId)->where('entry_type', 'original')->exists()) {
            throw new RuntimeException('This cash voucher already has accounting posting lines.');
        }
        $voucher = DB::table('cash_vouchers')->where('id', $voucherId)->first();
        if (! $voucher) {
            throw new RuntimeException('Cash voucher not found.');
        }
        $this->recalculate($voucherId);
        $voucher = DB::table('cash_vouchers')->where('id', $voucherId)->first();
        $amount = round((float) $voucher->amount, 2);
        $allocated = round((float) $voucher->allocated_amount, 2);
        $unallocated = round((float) $voucher->unallocated_amount, 2);
        $currency = (string) ($voucher->currency_code ?: 'PKR');
        $rate = (float) ($voucher->exchange_rate ?: 1);
        $bank = ['code' => (string) $voucher->cash_bank_account_code, 'name' => (string) $voucher->cash_bank_account_name];
        $lines = [];

        // ERP-11.3.24: preserve the user's actual voucher narration as the
        // transaction description presented by native ledgers/reports.
        // Accounting-specific generated text remains a fallback only.
        $voucherNarration = trim((string) ($voucher->narration ?? ''));

        $narration = static function (string $fallback) use ($voucherNarration): string {
            return $voucherNarration !== '' ? $voucherNarration : $fallback;
        };

        if ((string) $voucher->voucher_type === 'contra') {
            $detail = DB::table('cash_voucher_contra_details')
                ->where('cash_voucher_id', $voucherId)
                ->lockForUpdate()
                ->first();
            if (! $detail) {
                throw new RuntimeException('Contra Voucher destination account detail is missing.');
            }
            $destination = [
                'code' => (string) $detail->destination_account_code,
                'name' => (string) $detail->destination_account_name,
            ];
            $lines[] = $this->postingLine($voucherId, $reference, $destination, null, null, $amount, 0, $currency, $rate, $narration('Contra transfer received '.$voucher->voucher_no));
            $lines[] = $this->postingLine($voucherId, $reference, $bank, null, null, 0, $amount, $currency, $rate, $narration('Contra transfer sent '.$voucher->voucher_no));
        } elseif ((string) $voucher->voucher_type === 'expense') {
            $expenseLines = DB::table('cash_voucher_expense_lines')
                ->where('cash_voucher_id', $voucherId)
                ->orderBy('line_no')
                ->lockForUpdate()
                ->get();

            foreach ($expenseLines as $expenseLine) {
                $lines[] = $this->postingLine(
                    $voucherId,
                    $reference,
                    [
                        'code' => (string) $expenseLine->expense_account_code,
                        'name' => (string) $expenseLine->expense_account_name,
                    ],
                    null,
                    null,
                    (float) $expenseLine->amount,
                    0,
                    $currency,
                    $rate,
                    trim((string) ($expenseLine->description ?? '')) !== ''
                        ? (string) $expenseLine->description
                        : $narration('Direct expense '.$voucher->voucher_no)
                );
            }
            $lines[] = $this->postingLine(
                $voucherId,
                $reference,
                $bank,
                null,
                null,
                0,
                $amount,
                $currency,
                $rate,
                $narration('Cash/Bank direct expense payment '.$voucher->voucher_no)
            );
        } elseif ($voucher->direction === 'in') {
            $ar = $this->account('accounts_receivable');
            $ca = $this->account('customer_advances');
            $lines[] = $this->postingLine($voucherId, $reference, $bank, null, null, $amount, 0, $currency, $rate, $narration('Cash/Bank receipt '.$voucher->voucher_no));
            if ($allocated > 0) {
                $lines[] = $this->postingLine($voucherId, $reference, $ar, 'customer', $voucher->party_id, 0, $allocated, $currency, $rate, $narration('Customer receivable settlement '.$voucher->voucher_no));
            }
            if ($unallocated > 0) {
                $lines[] = $this->postingLine($voucherId, $reference, $ca, 'customer', $voucher->party_id, 0, $unallocated, $currency, $rate, $narration('Customer advance balance '.$voucher->voucher_no));
            }
        } else {
            $ap = $this->account('accounts_payable');
            $sa = $this->account('supplier_advances');
            if ($allocated > 0) {
                $lines[] = $this->postingLine($voucherId, $reference, $ap, 'supplier', $voucher->party_id, $allocated, 0, $currency, $rate, $narration('Supplier payable settlement '.$voucher->voucher_no));
            }
            if ($unallocated > 0) {
                $lines[] = $this->postingLine($voucherId, $reference, $sa, 'supplier', $voucher->party_id, $unallocated, 0, $currency, $rate, $narration('Supplier advance balance '.$voucher->voucher_no));
            }
            $lines[] = $this->postingLine($voucherId, $reference, $bank, null, null, 0, $amount, $currency, $rate, $narration('Cash/Bank payment '.$voucher->voucher_no));
        }

        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);
        if (abs($debit - $credit) > 0.005) {
            throw new RuntimeException('Cash voucher accounting entry is not balanced; posting stopped.');
        }
        DB::table('cash_voucher_posting_lines')->insert($lines);
    }

    private function createAdjustmentPosting(int $adjustmentId, string $reference): void
    {
        if (DB::table('advance_adjustment_posting_lines')->where('advance_adjustment_id', $adjustmentId)->where('entry_type', 'original')->exists()) {
            throw new RuntimeException('This advance adjustment already has accounting posting lines.');
        }
        $adjustment = DB::table('advance_adjustments')->where('id', $adjustmentId)->first();
        if (! $adjustment) {
            throw new RuntimeException('Advance adjustment not found.');
        }
        $amount = round((float) $adjustment->amount, 2);
        $currency = (string) ($adjustment->currency_code ?: 'PKR');
        $rate = (float) ($adjustment->exchange_rate ?: 1);
        $ar = $this->account('accounts_receivable');
        $ap = $this->account('accounts_payable');
        $ca = $this->account('customer_advances');
        $sa = $this->account('supplier_advances');
        $now = now();

        if ($adjustment->party_type === 'customer') {
            $lines = [
                ['advance_adjustment_id' => $adjustmentId, 'posting_reference' => $reference, 'entry_type' => 'original', 'account_code' => $ca['code'], 'account_name' => $ca['name'], 'party_type' => 'customer', 'party_id' => $adjustment->party_id, 'debit' => $amount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => $rate, 'narration' => 'Apply customer advance '.$adjustment->adjustment_no, 'created_at' => $now, 'updated_at' => $now],
                ['advance_adjustment_id' => $adjustmentId, 'posting_reference' => $reference, 'entry_type' => 'original', 'account_code' => $ar['code'], 'account_name' => $ar['name'], 'party_type' => 'customer', 'party_id' => $adjustment->party_id, 'debit' => 0, 'credit' => $amount, 'currency_code' => $currency, 'exchange_rate' => $rate, 'narration' => 'Settle customer receivable '.$adjustment->target_number, 'created_at' => $now, 'updated_at' => $now],
            ];
        } else {
            $lines = [
                ['advance_adjustment_id' => $adjustmentId, 'posting_reference' => $reference, 'entry_type' => 'original', 'account_code' => $ap['code'], 'account_name' => $ap['name'], 'party_type' => 'supplier', 'party_id' => $adjustment->party_id, 'debit' => $amount, 'credit' => 0, 'currency_code' => $currency, 'exchange_rate' => $rate, 'narration' => 'Settle supplier payable '.$adjustment->target_number, 'created_at' => $now, 'updated_at' => $now],
                ['advance_adjustment_id' => $adjustmentId, 'posting_reference' => $reference, 'entry_type' => 'original', 'account_code' => $sa['code'], 'account_name' => $sa['name'], 'party_type' => 'supplier', 'party_id' => $adjustment->party_id, 'debit' => 0, 'credit' => $amount, 'currency_code' => $currency, 'exchange_rate' => $rate, 'narration' => 'Apply supplier advance '.$adjustment->adjustment_no, 'created_at' => $now, 'updated_at' => $now],
            ];
        }
        DB::table('advance_adjustment_posting_lines')->insert($lines);
    }

    private function postingLine(int $voucherId, string $reference, array $account, ?string $partyType, mixed $partyId, float $debit, float $credit, string $currency, float $rate, string $narration): array
    {
        return [
            'cash_voucher_id' => $voucherId,
            'posting_reference' => $reference,
            'entry_type' => 'original',
            'account_code' => $account['code'],
            'account_name' => $account['name'],
            'party_type' => $partyType,
            'party_id' => $partyId,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'narration' => $narration,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function account(string $key): array
    {
        /*
         * ERP-11.3.9
         * Resolve the current controlled Chart accounts before posting.
         */
        $definitions = [
            'accounts_receivable' => ['control' => 'CUSTOMER_AR', 'code' => '1130', 'name' => 'Customer Receivables'],
            'accounts_payable' => ['control' => 'VENDOR_AP', 'code' => '2110', 'name' => 'Vendor Payables'],
            'customer_advances' => ['control' => 'CUSTOMER_ADVANCE', 'code' => '2120', 'name' => 'Customer Advances'],
            'supplier_advances' => ['control' => 'VENDOR_ADVANCE', 'code' => '1140', 'name' => 'Vendor Advances'],
        ];

        $definition = $definitions[$key] ?? null;

        if ($definition) {
            try {
                $s = $this->chartAccounts->schema();

                if ($s['control_type']) {
                    $row = DB::table($s['table'])
                        ->whereRaw('UPPER('.$s['control_type'].') = ?', [$definition['control']])
                        ->first();

                    if ($row) {
                        return [
                            'code' => (string) $row->{$s['code']},
                            'name' => (string) $row->{$s['name']},
                        ];
                    }
                }

                $row = DB::table($s['table'])
                    ->where($s['code'], $definition['code'])
                    ->first();

                if ($row) {
                    return [
                        'code' => (string) $row->{$s['code']},
                        'name' => (string) $row->{$s['name']},
                    ];
                }
            } catch (\Throwable $e) {
                report($e);
            }

            return [
                'code' => $definition['code'],
                'name' => $definition['name'],
            ];
        }

        $account = (array) config('cash_vouchers.accounts.'.$key, []);

        return [
            'code' => (string) ($account['code'] ?? ''),
            'name' => (string) ($account['name'] ?? ucwords(str_replace('_', ' ', $key))),
        ];
    }

    private function lockAllocationTargets(int $voucherId): void
    {
        $targets = DB::table('cash_voucher_allocations')
            ->where('cash_voucher_id', $voucherId)
            ->select(['target_type', 'target_id'])
            ->distinct()
            ->orderBy('target_type')
            ->orderBy('target_id')
            ->get();
        foreach ($targets as $target) {
            $this->lockTarget((string) $target->target_type, (int) $target->target_id);
        }
    }

    private function lockTarget(string $targetType, int $targetId): void
    {
        if ($targetType === 'supplier_costing') {
            if (Schema::hasTable('supplier_costings')) {
                DB::table('supplier_costings')->where('id', $targetId)->lockForUpdate()->first();
            }
            return;
        }
        if ($targetType !== 'sales_invoice') {
            return;
        }
        $schema = $this->salesInvoiceSchema();
        if ($schema) {
            DB::table($schema['table'])->where($schema['id'], $targetId)->lockForUpdate()->first();
        }
    }

    private function targetTotal(string $targetType, int $targetId): float
    {
        if ($targetType === 'supplier_costing') {
            return (float) (DB::table('supplier_costings')->where('id', $targetId)->value('total_cost') ?? 0);
        }
        $schema = $this->salesInvoiceSchema();
        if (! $schema || ! $schema['amount']) {
            return 0.0;
        }
        return (float) (DB::table($schema['table'])->where($schema['id'], $targetId)->value($schema['amount']) ?? 0);
    }

    private function salesInvoiceSchema(): ?array
    {
        foreach (['sales_invoices', 'sales_invoice_headers', 'sales_invoice', 'customer_invoices', 'invoices'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            $id = $this->first($columns, ['id', 'sales_invoice_id', 'invoice_id']);
            $amount = $this->first($columns, ['grand_total', 'total_amount', 'net_total', 'total', 'amount', 'invoice_total']);
            if (! $id || ! $amount) {
                continue;
            }
            return [
                'table' => $table,
                'id' => $id,
                'number' => $this->first($columns, ['invoice_number', 'invoice_no', 'invoice_reference', 'reference_no', 'reference', 'number', 'document_no']),
                'status' => $this->first($columns, ['status', 'invoice_status', 'document_status']),
                'amount' => $amount,
                'booking' => $this->first($columns, ['booking_id', 'travel_booking_id', 'source_booking_id']),
                'party_id' => $this->first($columns, ['customer_id', 'party_id', 'client_id', 'agent_id']),
                'party_name' => $this->first($columns, ['customer_name', 'party_name', 'client_name', 'agent_name']),
                'currency' => $this->first($columns, ['currency_code', 'currency', 'currency_iso']),
            ];
        }

        return null;
    }

    private function first(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }
}
