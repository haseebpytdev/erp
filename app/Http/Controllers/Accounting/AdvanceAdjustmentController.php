<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CashVoucherService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvanceAdjustmentController extends Controller
{
    public function __construct(
        private readonly CashVoucherService $service,
        private readonly NativeErpLayoutResolver $layout,
    ) {
    }

    public function create(Request $request)
    {
        $canCustomer = $this->service->canUseType($request->user(), 'customer_advance', 'create');
        $canSupplier = $this->service->canUseType($request->user(), 'supplier_advance', 'create');
        abort_unless($canCustomer || $canSupplier, 403);
        return view('accounting.advance-adjustments.form', $this->formData(null));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $source = DB::table('cash_vouchers')->where('id', $data['advance_voucher_id'])->first();
        abort_unless($source, 422, 'Selected advance voucher was not found.');
        abort_unless($this->service->canUseType($request->user(), (string) $source->voucher_type, 'create'), 403);
        $id = DB::transaction(function () use ($request, $data): int {
            $payload = $this->resolvePayload($data);
            $now = now();
            $id = DB::table('advance_adjustments')->insertGetId(array_merge($payload, [
                'adjustment_no' => $this->service->nextAdjustmentNumber(),
                'adjustment_date' => $data['adjustment_date'],
                'amount' => round((float) $data['amount'], 2),
                'remarks' => $data['remarks'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $this->service->adjustmentActivity($id, 'create', null, 'draft', $request->user(), 'Advance adjustment draft created.');
            return $id;
        });
        return redirect()->route('accounting.advance-adjustments.show', $id)->with('success', 'Advance adjustment draft created.');
    }

    public function show(Request $request, int $adjustment)
    {
        $row = $this->find($adjustment);
        $source = DB::table('cash_vouchers')->where('id', $row->advance_voucher_id)->first();
        abort_unless($source, 404);
        abort_unless($this->service->canUseType($request->user(), (string) $source->voucher_type, 'view'), 403);

        return view('accounting.advance-adjustments.show', [
            'row' => $row,
            'source' => $source,
            'availableNow' => $row->status === 'posted' ? null : $this->service->availableAdvance((int) $row->advance_voucher_id),
            'postings' => DB::table('advance_adjustment_posting_lines')->where('advance_adjustment_id', $adjustment)->orderBy('id')->get(),
            'activities' => DB::table('advance_adjustment_activities')->where('advance_adjustment_id', $adjustment)->orderByDesc('id')->get(),
            'layoutMeta' => $this->layout->resolve(),
            'canApprove' => $this->service->canApprove($request->user(), (string) $source->voucher_type),
        ]);
    }

    public function edit(Request $request, int $adjustment)
    {
        $row = $this->find($adjustment);
        abort_unless($row->status === 'draft', 409, 'Only Draft advance adjustments can be edited.');
        $source = DB::table('cash_vouchers')->where('id', $row->advance_voucher_id)->first();
        abort_unless($source, 404);
        abort_unless($this->service->canUseType($request->user(), (string) $source->voucher_type, 'update'), 403);
        return view('accounting.advance-adjustments.form', $this->formData($row));
    }

    public function update(Request $request, int $adjustment)
    {
        $row = $this->find($adjustment);
        abort_unless($row->status === 'draft', 409, 'Only Draft advance adjustments can be edited.');
        $source = DB::table('cash_vouchers')->where('id', $row->advance_voucher_id)->first();
        abort_unless($source, 404);
        abort_unless($this->service->canUseType($request->user(), (string) $source->voucher_type, 'update'), 403);
        $data = $this->validateData($request);
        $payload = $this->resolvePayload($data);
        DB::table('advance_adjustments')->where('id', $adjustment)->update(array_merge($payload, [
            'adjustment_date' => $data['adjustment_date'],
            'amount' => round((float) $data['amount'], 2),
            'remarks' => $data['remarks'] ?? null,
            'updated_at' => now(),
        ]));
        $this->service->adjustmentActivity($adjustment, 'update', 'draft', 'draft', $request->user(), 'Draft updated.');
        return redirect()->route('accounting.advance-adjustments.show', $adjustment)->with('success', 'Advance adjustment draft saved.');
    }

    public function workflow(Request $request, int $adjustment, string $action)
    {
        abort_unless(in_array($action, ['submit', 'approve', 'post'], true), 404);
        try {
            $this->service->transitionAdjustment($adjustment, $action, $request->user());
        } catch (\Throwable $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }
        return back()->with('success', 'Advance adjustment workflow updated.');
    }

    public function reverse(Request $request, int $adjustment)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            $this->service->reverseAdjustment($adjustment, $request->user(), (string) $data['reason']);
        } catch (\Throwable $e) {
            return back()->withErrors(['workflow' => $e->getMessage()]);
        }
        return back()->with('success', 'Advance adjustment reversed with a controlled accounting reversal.');
    }

    private function formData(?object $row): array
    {
        $user = request()->user();
        $allowCustomer = $this->service->canUseType($user, 'customer_advance', 'view') || $this->service->canUseType($user, 'customer_advance', 'create');
        $allowSupplier = $this->service->canUseType($user, 'supplier_advance', 'view') || $this->service->canUseType($user, 'supplier_advance', 'create');
        $advances = array_values(array_filter($this->service->advanceOptions(), function (array $advance) use ($allowCustomer, $allowSupplier): bool {
            return $advance['party_type'] === 'supplier' ? $allowSupplier : $allowCustomer;
        }));
        return [
            'row' => $row,
            'advances' => $advances,
            'salesInvoices' => $this->service->salesInvoiceOptions(),
            'supplierCostings' => $this->service->supplierCostingOptions(),
            'layoutMeta' => $this->layout->resolve(),
        ];
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'advance_voucher_id' => ['required', 'integer', 'min:1'],
            'target_id' => ['required', 'integer', 'min:1'],
            'adjustment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function resolvePayload(array $data): array
    {
        $source = DB::table('cash_vouchers')->where('id', $data['advance_voucher_id'])->where('status', 'posted')->first();
        abort_unless($source, 422, 'Selected advance voucher is not Posted or no longer exists.');
        $available = $this->service->availableAdvance((int) $source->id);
        abort_if((float) $data['amount'] > $available + 0.005, 422, 'Adjustment amount exceeds the available advance balance.');
        $targetType = $source->party_type === 'supplier' ? 'supplier_costing' : 'sales_invoice';
        $target = $this->service->documentSnapshot($targetType, (int) $data['target_id']);
        abort_if((float) $data['amount'] > (float) $target['outstanding'] + 0.005, 422, 'Adjustment amount exceeds the target outstanding balance.');
        if ($source->party_id && $target['party_id'] && (int) $source->party_id !== (int) $target['party_id']) {
            abort(422, 'Advance party does not match the selected target document party.');
        }

        return [
            'advance_voucher_id' => (int) $source->id,
            'party_type' => (string) $source->party_type,
            'party_id' => $source->party_id ? (int) $source->party_id : null,
            'party_name' => (string) ($source->party_name ?? ''),
            'target_type' => $targetType,
            'target_id' => (int) $target['id'],
            'target_number' => (string) $target['number'],
            'booking_id' => $target['booking_id'] ?? $source->booking_id ?? null,
            'currency_code' => (string) ($source->currency_code ?: 'PKR'),
            'exchange_rate' => (float) ($source->exchange_rate ?: 1),
        ];
    }

    private function find(int $id): object
    {
        $row = DB::table('advance_adjustments')->where('id', $id)->first();
        abort_unless($row, 404);
        return $row;
    }
}
