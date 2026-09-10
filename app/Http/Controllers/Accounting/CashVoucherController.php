<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CashVoucherService;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Organization\CompanyProfileSnapshotService;
use App\Support\Accounting\AmountInWords;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CashVoucherController extends Controller
{
    public function __construct(
        private readonly CashVoucherService $service,
        private readonly NativeErpLayoutResolver $layout,
        private readonly CompanyProfileSnapshotService $companyProfile,
    ) {
    }

    public function index(Request $request)
    {
        $allowedTypes = array_values(array_filter(
            ['receipt', 'payment', 'expense', 'customer_advance', 'supplier_advance'],
            fn (string $type): bool => $this->service->canUseType($request->user(), $type, 'view')
        ));
        abort_if($allowedTypes === [], 403);

        $query = DB::table('cash_vouchers')
            ->whereIn('voucher_type', $allowedTypes)
            ->orderByDesc('voucher_date')
            ->orderByDesc('id');

        if ($request->filled('mode')) {
            $mode = strtolower(trim((string) $request->string('mode')));

            if ($mode === 'receipts' && in_array('receipt', $allowedTypes, true)) {
                $query->where('voucher_type', 'receipt');
            } elseif ($mode === 'payments' && in_array('payment', $allowedTypes, true)) {
                $query->where('voucher_type', 'payment');
            } elseif ($mode === 'expenses' && in_array('expense', $allowedTypes, true)) {
                $query->where('voucher_type', 'expense');
            } elseif ($mode === 'advances') {
                $advanceTypes = array_values(array_intersect(
                    ['customer_advance', 'supplier_advance'],
                    $allowedTypes
                ));

                if ($advanceTypes !== []) {
                    $query->whereIn('voucher_type', $advanceTypes);
                }
            }
        } elseif ($request->filled('type') && in_array((string) $request->string('type'), $allowedTypes, true)) {
            $query->where('voucher_type', (string) $request->string('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('voucher_date', '>=', (string) $request->string('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('voucher_date', '<=', (string) $request->string('date_to'));
        }
        if ($request->filled('account')) {
            $query->where('cash_bank_account_code', (string) $request->string('account'));
        }
        if ($request->filled('q')) {
            $term = '%'.trim((string) $request->string('q')).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('voucher_no', 'like', $term)
                    ->orWhere('party_name', 'like', $term)
                    ->orWhere('transaction_reference', 'like', $term)
                    ->orWhere('instrument_no', 'like', $term)
                    ->orWhere('cash_bank_account_name', 'like', $term);
            });
        }

        $summary = DB::table('cash_vouchers')
            ->whereIn('voucher_type', $allowedTypes)
            ->where('status', '!=', 'reversed')
            ->selectRaw("
                COALESCE(SUM(CASE WHEN voucher_type = 'receipt' THEN amount * COALESCE(exchange_rate, 1) ELSE 0 END), 0) AS receipts,
                COALESCE(SUM(CASE WHEN voucher_type = 'payment' THEN amount * COALESCE(exchange_rate, 1) ELSE 0 END), 0) AS payments,
                COALESCE(SUM(CASE WHEN voucher_type = 'expense' THEN amount * COALESCE(exchange_rate, 1) ELSE 0 END), 0) AS expenses,
                COALESCE(SUM(CASE WHEN voucher_type = 'customer_advance' THEN amount * COALESCE(exchange_rate, 1) ELSE 0 END), 0) AS customer_advances,
                COALESCE(SUM(CASE WHEN voucher_type = 'supplier_advance' THEN amount * COALESCE(exchange_rate, 1) ELSE 0 END), 0) AS supplier_advances,
                COALESCE(SUM(CASE WHEN status = 'pending_approval' THEN amount * COALESCE(exchange_rate, 1) ELSE 0 END), 0) AS pending_approval,
                COALESCE(SUM(allocated_amount * COALESCE(exchange_rate, 1)), 0) AS allocated,
                COALESCE(SUM(unallocated_amount * COALESCE(exchange_rate, 1)), 0) AS unallocated,
                COALESCE(SUM(amount * COALESCE(exchange_rate, 1)), 0) AS total_value
            ")
            ->first();

        $adjustmentPartyTypes = array_values(array_unique(array_map(
            fn (string $type): string => $this->service->voucherDefinition($type)['party_type'],
            $allowedTypes
        )));

        $adjustments = DB::table('advance_adjustments')
            ->whereIn('party_type', $adjustmentPartyTypes)
            ->orderByDesc('adjustment_date')
            ->orderByDesc('id')
            ->paginate(5, ['*'], 'adjustment_page')
            ->withQueryString();

        return view('accounting.cash-vouchers.index', [
            'rows' => $query->paginate(5, ['*'], 'voucher_page')->withQueryString(),
            'adjustments' => $adjustments,
            'layoutMeta' => $this->layout->resolve(),
            'service' => $this->service,
            'allowedTypes' => $allowedTypes,
            'cashBankAccounts' => $this->service->cashBankAccounts(),
            'summary' => $summary,
        ]);
    }

    public function create(Request $request)
    {
        $type = (string) $request->query('type', 'receipt');
        abort_unless(in_array($type, ['receipt', 'payment', 'expense', 'customer_advance', 'supplier_advance'], true), 404);
        abort_unless($this->service->canUseType($request->user(), $type, 'create'), 403);

        return view('accounting.cash-vouchers.form', $this->formData(null, $type));
    }

    public function store(Request $request)
    {
        $data = $this->validateHeader($request);
        $type = (string) $data['voucher_type'];
        abort_unless($this->service->canUseType($request->user(), $type, 'create'), 403);
        $definition = $this->service->voucherDefinition($type);
        $expenseLines = $type === 'expense'
            ? $this->validateExpenseLines($request, $data)
            : [];
        $allocations = $type === 'expense'
            ? []
            : $this->validateAllocations($request, $definition['target_type']);
        $proof = $this->storeProof($request);

        $id = DB::transaction(function () use ($request, $data, $definition, $allocations, $expenseLines, $proof): int {
            $now = now();
            $partyId = $data['voucher_type'] === 'expense'
                ? null
                : (! empty($data['party_id']) ? (int) $data['party_id'] : null);
            $account = $this->resolveCashBankAccount((string) $data['cash_bank_account']);
            $id = DB::table('cash_vouchers')->insertGetId([
                'voucher_no' => $this->service->nextNumber((string) $data['voucher_type']),
                'voucher_type' => $data['voucher_type'],
                'direction' => $definition['direction'],
                'party_type' => $definition['party_type'],
                'party_id' => $partyId,
                'party_name' => $this->service->resolvePartyName($definition['party_type'], $partyId, $data['party_name'] ?? null),
                'booking_id' => $data['booking_id'] ?? null,
                'voucher_date' => $data['voucher_date'],
                'value_date' => $data['value_date'] ?? null,
                'currency_code' => strtoupper((string) $data['currency_code']),
                'exchange_rate' => $data['exchange_rate'],
                'amount' => round((float) $data['amount'], 2),
                'allocated_amount' => 0,
                'unallocated_amount' => round((float) $data['amount'], 2),
                'payment_method' => $data['payment_method'],
                'cash_bank_account_code' => $account['code'],
                'cash_bank_account_name' => $account['name'],
                'bank_name' => $data['bank_name'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'payment_proof_path' => $proof['path'] ?? null,
                'payment_proof_original_name' => $proof['name'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->replaceAllocations($id, $allocations, $definition['target_type'], (string) $data['currency_code']);
            if ($data['voucher_type'] === 'expense') {
                $this->replaceExpenseLines($id, $expenseLines);
            }
            $this->service->recalculate($id);
            $this->service->activity($id, 'create', null, 'draft', $request->user(), 'Cash voucher draft created.');
            return $id;
        });

        $afterSave = strtolower(trim((string) $request->input('after_save', 'draft')));

        if ($afterSave === 'submit') {
            try {
                $this->service->transition($id, 'submit', $request->user());
                return redirect()->route('accounting.cash-vouchers.show', $id)
                    ->with('success', 'Voucher saved and submitted for approval.');
            } catch (\Throwable $e) {
                return redirect()->route('accounting.cash-vouchers.show', $id)
                    ->withErrors(['workflow' => $e->getMessage()]);
            }
        }

        if ($afterSave === 'print') {
            return redirect()->route('accounting.cash-vouchers.print', [
                'voucher' => $id,
                'autoprint' => 1,
            ]);
        }

        return redirect()->route('accounting.cash-vouchers.show', $id)
            ->with('success', 'Cash voucher draft created.');
    }

    public function show(Request $request, int $voucher)
    {
        $row = $this->find($voucher);
        abort_unless($this->service->canUseType($request->user(), (string) $row->voucher_type, 'view'), 403);

        return view('accounting.cash-vouchers.show', [
            'row' => $row,
            'definition' => $this->service->voucherDefinition((string) $row->voucher_type),
            'allocations' => DB::table('cash_voucher_allocations')->where('cash_voucher_id', $voucher)->orderBy('line_no')->get(),
            'expenseLines' => DB::table('cash_voucher_expense_lines')->where('cash_voucher_id', $voucher)->orderBy('line_no')->get(),
            'postings' => DB::table('cash_voucher_posting_lines')->where('cash_voucher_id', $voucher)->orderBy('id')->get(),
            'activities' => DB::table('cash_voucher_activities')->where('cash_voucher_id', $voucher)->orderByDesc('id')->get(),
            'layoutMeta' => $this->layout->resolve(),
            'canApprove' => $this->service->canApprove($request->user(), (string) $row->voucher_type),
        ]);
    }

    public function edit(Request $request, int $voucher)
    {
        $row = $this->find($voucher);
        abort_unless($row->status === 'draft', 409, 'Only Draft cash vouchers can be edited.');
        abort_unless($this->service->canUseType($request->user(), (string) $row->voucher_type, 'update'), 403);

        return view('accounting.cash-vouchers.form', array_merge(
            $this->formData($row, (string) $row->voucher_type),
            [
                'allocations' => DB::table('cash_voucher_allocations')->where('cash_voucher_id', $voucher)->orderBy('line_no')->get(),
                'expenseLines' => DB::table('cash_voucher_expense_lines')->where('cash_voucher_id', $voucher)->orderBy('line_no')->get(),
            ]
        ));
    }

    public function update(Request $request, int $voucher)
    {
        $row = $this->find($voucher);
        abort_unless($row->status === 'draft', 409, 'Only Draft cash vouchers can be edited.');
        abort_unless($this->service->canUseType($request->user(), (string) $row->voucher_type, 'update'), 403);
        $data = $this->validateHeader($request, (string) $row->voucher_type);
        $definition = $this->service->voucherDefinition((string) $row->voucher_type);
        $expenseLines = (string) $row->voucher_type === 'expense'
            ? $this->validateExpenseLines($request, $data)
            : [];
        $allocations = (string) $row->voucher_type === 'expense'
            ? []
            : $this->validateAllocations($request, $definition['target_type']);
        $proof = $this->storeProof($request);

        DB::transaction(function () use ($request, $voucher, $row, $data, $definition, $allocations, $expenseLines, $proof): void {
            $partyId = (string) $row->voucher_type === 'expense'
                ? null
                : (! empty($data['party_id']) ? (int) $data['party_id'] : null);
            $account = $this->resolveCashBankAccount((string) $data['cash_bank_account']);
            $update = [
                'party_id' => $partyId,
                'party_name' => $this->service->resolvePartyName($definition['party_type'], $partyId, $data['party_name'] ?? null),
                'booking_id' => $data['booking_id'] ?? null,
                'voucher_date' => $data['voucher_date'],
                'value_date' => $data['value_date'] ?? null,
                'currency_code' => strtoupper((string) $data['currency_code']),
                'exchange_rate' => $data['exchange_rate'],
                'amount' => round((float) $data['amount'], 2),
                'payment_method' => $data['payment_method'],
                'cash_bank_account_code' => $account['code'],
                'cash_bank_account_name' => $account['name'],
                'bank_name' => $data['bank_name'] ?? null,
                'instrument_no' => $data['instrument_no'] ?? null,
                'transaction_reference' => $data['transaction_reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'updated_at' => now(),
            ];
            if ($proof) {
                $this->deleteProof($row->payment_proof_path ?? null);
                $update['payment_proof_path'] = $proof['path'];
                $update['payment_proof_original_name'] = $proof['name'];
            }
            DB::table('cash_vouchers')->where('id', $voucher)->update($update);
            $this->replaceAllocations($voucher, $allocations, $definition['target_type'], (string) $data['currency_code']);
            if ((string) $row->voucher_type === 'expense') {
                $this->replaceExpenseLines($voucher, $expenseLines);
            }
            $this->service->recalculate($voucher);
            $this->service->activity($voucher, 'update', 'draft', 'draft', $request->user(), 'Draft updated.');
        });

        $afterSave = strtolower(trim((string) $request->input('after_save', 'draft')));

        if ($afterSave === 'submit') {
            try {
                $this->service->transition($voucher, 'submit', $request->user());
                return redirect()->route('accounting.cash-vouchers.show', $voucher)
                    ->with('success', 'Voucher saved and submitted for approval.');
            } catch (\Throwable $e) {
                return redirect()->route('accounting.cash-vouchers.show', $voucher)
                    ->withErrors(['workflow' => $e->getMessage()]);
            }
        }

        if ($afterSave === 'print') {
            return redirect()->route('accounting.cash-vouchers.print', [
                'voucher' => $voucher,
                'autoprint' => 1,
            ]);
        }

        return redirect()->route('accounting.cash-vouchers.show', $voucher)
            ->with('success', 'Cash voucher draft saved.');
    }

    public function workflow(Request $request, int $voucher, string $action)
    {
        abort_unless(in_array($action, ['submit', 'approve', 'post'], true), 404);
        try {
            $this->service->transition($voucher, $action, $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }
        return back()->with('success', 'Cash voucher workflow updated.');
    }

    public function reverse(Request $request, int $voucher)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            $this->service->reverseVoucher($voucher, $request->user(), (string) $data['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }
        return back()->with('success', 'Posted cash voucher reversed with a controlled accounting reversal.');
    }

    public function printVoucher(Request $request, int $voucher)
    {
        $row = $this->find($voucher);
        abort_unless(
            $this->service->canUseType($request->user(), (string) $row->voucher_type, 'view'),
            403
        );

        $definition = $this->service->voucherDefinition((string) $row->voucher_type);
        $bookingReference = null;

        if ($row->booking_id) {
            foreach ($this->service->bookingOptions() as $booking) {
                if ((int) $booking['id'] === (int) $row->booking_id) {
                    $bookingReference = (string) $booking['reference'];
                    break;
                }
            }
        }

        return view('accounting.cash-vouchers.print', [
            'row' => $row,
            'definition' => $definition,
            'allocations' => DB::table('cash_voucher_allocations')
                ->where('cash_voucher_id', $voucher)
                ->orderBy('line_no')
                ->get(),
            'expenseLines' => DB::table('cash_voucher_expense_lines')
                ->where('cash_voucher_id', $voucher)
                ->orderBy('line_no')
                ->get(),
            'bookingReference' => $bookingReference,
            'amountInWords' => AmountInWords::money(
                (float) $row->amount,
                (string) ($row->currency_code ?: 'PKR')
            ),
            'autoPrint' => $request->boolean('autoprint'),
            'companyProfile' => $this->companyProfile->get((array) $row),
        ]);
    }

    public function proof(Request $request, int $voucher)
    {
        $row = $this->find($voucher);
        abort_unless($this->service->canUseType($request->user(), (string) $row->voucher_type, 'view'), 403);
        abort_unless($row->payment_proof_path && Storage::disk('local')->exists($row->payment_proof_path), 404);
        return Storage::disk('local')->download($row->payment_proof_path, $row->payment_proof_original_name ?: 'payment-proof');
    }

    private function formData(?object $row, string $type): array
    {
        $definition = $this->service->voucherDefinition($type);
        return [
            'row' => $row,
            'type' => $type,
            'definition' => $definition,
            'parties' => $type === 'expense'
                ? []
                : $this->service->partyOptions($definition['party_type']),
            'bookings' => $this->service->bookingOptions(),
            'cashBankAccounts' => $this->service->cashBankAccounts(),
            'paymentMethods' => $this->service->paymentMethods(),
            'documents' => $definition['target_type'] ? $this->service->documentOptions($definition['target_type']) : [],
            'expenseAccounts' => $type === 'expense' ? $this->service->expenseAccounts() : [],
            'layoutMeta' => $this->layout->resolve(),
        ];
    }

    private function validateHeader(Request $request, ?string $lockedType = null): array
    {
        $data = $request->validate([
            'voucher_type' => ['required', Rule::in(['receipt', 'payment', 'expense', 'customer_advance', 'supplier_advance'])],
            'party_id' => ['nullable', 'integer', 'min:1'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'booking_id' => ['nullable', 'integer', 'min:1'],
            'voucher_date' => ['required', 'date'],
            'value_date' => ['nullable', 'date'],
            'currency_code' => ['required', 'string', 'max:10'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', 'max:50'],
            'cash_bank_account' => ['required', 'string', 'max:80'],
            'bank_name' => ['nullable', 'string', 'max:190'],
            'instrument_no' => ['nullable', 'string', 'max:120'],
            'transaction_reference' => ['nullable', 'string', 'max:190'],
            'narration' => ['nullable', 'string', 'max:5000'],
            'payment_proof' => ['nullable', 'file', 'max:'.(int) config('cash_vouchers.proof_max_kb', 5120), 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
        if ($lockedType !== null && $data['voucher_type'] !== $lockedType) {
            abort(409, 'Voucher type cannot be changed after creation.');
        }
        if (
            $data['voucher_type'] !== 'expense'
            && empty($data['party_id'])
            && trim((string) ($data['party_name'] ?? '')) === ''
        ) {
            abort(422, 'Select a party or enter a party name.');
        }
        return $data;
    }

    private function validateAllocations(Request $request, ?string $targetType): array
    {
        if ($targetType === null) {
            return [];
        }
        $validated = $request->validate([
            'allocations' => ['nullable', 'array'],
            'allocations.*.target_id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
            'allocations.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return array_values((array) ($validated['allocations'] ?? []));
    }

    private function validateExpenseLines(Request $request, array $header): array
    {
        $validated = $request->validate([
            'expense_lines' => ['required', 'array', 'min:1'],
            'expense_lines.*.expense_account_id' => ['required', 'integer', 'min:1'],
            'expense_lines.*.description' => ['nullable', 'string', 'max:2000'],
            'expense_lines.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $currency = strtoupper(trim((string) $header['currency_code']));
        $rate = (float) $header['exchange_rate'];
        $rows = [];
        $total = 0.0;

        foreach (array_values($validated['expense_lines']) as $index => $line) {
            $account = $this->service->expenseAccount((int) $line['expense_account_id']);
            $amount = round((float) $line['amount'], 2);
            $total += $amount;
            $rows[] = [
                'line_no' => $index + 1,
                'expense_account_id' => (int) $account['id'],
                'expense_account_code' => (string) $account['code'],
                'expense_account_name' => (string) $account['name'],
                'description' => trim((string) ($line['description'] ?? '')) ?: null,
                'amount' => $amount,
                'currency_code' => $currency,
                'exchange_rate' => $rate,
                'base_amount' => round($amount * $rate, 2),
            ];
        }

        if (abs(round($total, 2) - round((float) $header['amount'], 2)) > 0.005) {
            abort(422, 'Expense line total must equal the voucher header amount.');
        }

        return $rows;
    }

    private function replaceExpenseLines(int $voucherId, array $expenseLines): void
    {
        DB::table('cash_voucher_expense_lines')->where('cash_voucher_id', $voucherId)->delete();

        if ($expenseLines === []) {
            return;
        }

        $now = now();
        $rows = array_map(static fn (array $line): array => array_merge($line, [
            'cash_voucher_id' => $voucherId,
            'created_at' => $now,
            'updated_at' => $now,
        ]), $expenseLines);

        DB::table('cash_voucher_expense_lines')->insert($rows);
    }

    private function replaceAllocations(int $voucherId, array $allocations, ?string $targetType, string $currencyCode): void
    {
        DB::table('cash_voucher_allocations')->where('cash_voucher_id', $voucherId)->delete();
        if ($targetType === null || $allocations === []) {
            return;
        }
        $rows = [];
        $now = now();
        foreach ($allocations as $index => $allocation) {
            $document = $this->service->documentSnapshot($targetType, (int) $allocation['target_id']);
            $voucher = DB::table('cash_vouchers')->where('id', $voucherId)->first();
            if ($voucher?->party_id && $document['party_id'] && (int) $voucher->party_id !== (int) $document['party_id']) {
                abort(422, 'Selected document party does not match the voucher party.');
            }
            if (strtoupper((string) ($document['currency_code'] ?? 'PKR')) !== strtoupper($currencyCode)) {
                abort(422, 'Voucher currency must match the allocated document currency. Create a separate voucher for another currency.');
            }
            $amount = round((float) $allocation['amount'], 2);
            if ($amount > (float) $document['outstanding'] + 0.005) {
                abort(422, 'Allocation for '.$document['number'].' exceeds its current outstanding balance.');
            }
            $rows[] = [
                'cash_voucher_id' => $voucherId,
                'line_no' => $index + 1,
                'target_type' => $targetType,
                'target_id' => (int) $document['id'],
                'target_number' => (string) $document['number'],
                'booking_id' => $document['booking_id'] ?? null,
                'target_total_snapshot' => (float) $document['total'],
                'outstanding_before_snapshot' => (float) $document['outstanding'],
                'amount' => $amount,
                'currency_code' => strtoupper($currencyCode),
                'notes' => $allocation['notes'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('cash_voucher_allocations')->insert($rows);
    }

    private function resolveCashBankAccount(string $code): array
    {
        foreach ($this->service->cashBankAccounts() as $account) {
            if ((string) $account['code'] === $code) {
                return ['code' => (string) $account['code'], 'name' => (string) $account['name']];
            }
        }
        abort(422, 'Selected Cash / Bank account is not available.');
    }

    private function storeProof(Request $request): ?array
    {
        if (! $request->hasFile('payment_proof')) {
            return null;
        }
        $file = $request->file('payment_proof');
        $name = $file->getClientOriginalName();
        $path = $file->store('finance/payment-proofs/'.now()->format('Y/m'), 'local');
        return ['path' => $path, 'name' => $name];
    }

    private function deleteProof(?string $path): void
    {
        if ($path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable) {
            }
        }
    }

    private function find(int $id): object
    {
        $row = DB::table('cash_vouchers')->where('id', $id)->first();
        abort_unless($row, 404);
        return $row;
    }
}
