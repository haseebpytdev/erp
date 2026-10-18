<?php

namespace App\Http\Controllers\Accounting;

use App\Services\Accounting\CashVoucherService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Thin responsive-web orchestration over the native accounting authorities. */
final class MobileAccountsController
{
    public function __construct(
        private readonly CashVoucherService $cash,
        private readonly NativeErpLayoutResolver $layout,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $cards = [
            'payment' => $this->cash->canUseType($user, 'payment', 'create'),
            'receipt' => $this->cash->canUseType($user, 'receipt', 'create'),
            'advance' => $this->cash->canUseType($user, 'customer_advance', 'create')
                || $this->cash->canUseType($user, 'supplier_advance', 'create'),
            'adjustment' => $this->cash->canUseType($user, 'customer_advance', 'create')
                || $this->cash->canUseType($user, 'supplier_advance', 'create'),
            'return' => $this->cash->canUseType($user, 'customer_advance_return', 'create'),
        ];

        $recent = DB::table('cash_vouchers')
            ->select(['id', 'voucher_no', 'voucher_type', 'party_name', 'amount', 'status', 'voucher_date'])
            ->orderByDesc('id');
        $this->cash->scopeVoucherQuery($recent, $user);

        return view('mobile.accounts.index', [
            'layoutMeta' => $this->layout->resolve(),
            'cards' => $cards,
            'recent' => $recent->limit(5)->get(),
            'movementsUrl' => route('mobile.movements.index'),
        ]);
    }

    public function form(Request $request, string $type)
    {
        abort_unless(in_array($type, ['payment', 'receipt', 'customer-advance', 'supplier-advance'], true), 404);
        $voucherType = match ($type) {
            'payment' => 'payment', 'receipt' => 'receipt',
            'customer-advance' => 'customer_advance', default => 'supplier_advance',
        };
        abort_unless($this->cash->canUseType($request->user(), $voucherType, 'create'), 403);
        $definition = $this->cash->voucherDefinition($voucherType);

        return view('mobile.accounts.form', [
            'layoutMeta' => $this->layout->resolve(),
            'type' => $type,
            'voucherType' => $voucherType,
            'definition' => $definition,
            'parties' => $this->cash->partyOptions($definition['party_type']),
            'cashBankAccounts' => $this->cash->cashBankAccounts(),
            'paymentMethods' => $this->cash->paymentMethods(),
            'branchOptions' => $this->cash->branchOptions($request->user()),
        ]);
    }

    public function store(Request $request, string $type)
    {
        $voucherType = match ($type) {
            'payment' => 'payment', 'receipt' => 'receipt',
            'customer-advance' => 'customer_advance', 'supplier-advance' => 'supplier_advance',
            default => abort(404),
        };
        abort_unless($this->cash->canUseType($request->user(), $voucherType, 'create'), 403);
        $request->merge(['voucher_type' => $voucherType]);
        $this->assertNoDuplicateVoucherReference($request, $request->user());
        return $this->mobileRedirect(app(CashVoucherController::class)->store($request), 'voucher');
    }

    public function advanceChoice(Request $request)
    {
        abort_unless(
            $this->cash->canUseType($request->user(), 'customer_advance', 'create')
            || $this->cash->canUseType($request->user(), 'supplier_advance', 'create'),
            403
        );
        return view('mobile.accounts.advance', [
            'layoutMeta' => $this->layout->resolve(),
            'customer' => $this->cash->canUseType($request->user(), 'customer_advance', 'create'),
            'supplier' => $this->cash->canUseType($request->user(), 'supplier_advance', 'create'),
        ]);
    }

    public function adjustment(Request $request)
    {
        abort_unless(
            $this->cash->canUseType($request->user(), 'customer_advance', 'create')
            || $this->cash->canUseType($request->user(), 'supplier_advance', 'create'),
            403
        );
        return app(AdvanceAdjustmentController::class)->create($request);
    }

    public function storeAdjustment(Request $request)
    {
        return $this->mobileRedirect(app(AdvanceAdjustmentController::class)->store($request), 'adjustment');
    }

    public function customerReturn(Request $request)
    {
        abort_unless($this->cash->canUseType($request->user(), 'customer_advance_return', 'create'), 403);
        return app(PartyBalanceLifecycleController::class)->customerAdvanceReturnCreate($request);
    }

    public function storeCustomerReturn(Request $request)
    {
        $this->assertNoDuplicateReturnReference($request, $request->user());
        return $this->mobileRedirect(app(PartyBalanceLifecycleController::class)->customerAdvanceReturnStore($request), 'return');
    }

    public function voucherShow(Request $request, int $voucher)
    {
        $row = DB::table('cash_vouchers')->where('id', $voucher)->first();
        abort_unless($row, 404);
        try { $this->cash->assertVoucherAccess($row, $request->user(), 'view'); } catch (\Throwable $e) { abort(404); }
        abort_unless($this->cash->canUseType($request->user(), (string) $row->voucher_type, 'view'), 403);
        return view('mobile.accounts.show', $this->showData('voucher', $row, $voucher, [
            'allocations' => DB::table('cash_voucher_allocations')->where('cash_voucher_id', $voucher)->orderBy('line_no')->get(),
            'activities' => DB::table('cash_voucher_activities')->where('cash_voucher_id', $voucher)->orderByDesc('id')->get(),
            'canSubmit' => $this->cash->canUseType($request->user(), (string) $row->voucher_type, 'update'),
            'canApprove' => $this->cash->canUseType($request->user(), (string) $row->voucher_type, 'approve'),
            'canPost' => $this->cash->canUseType($request->user(), (string) $row->voucher_type, 'post'),
            'canReverse' => $this->cash->canUseType($request->user(), (string) $row->voucher_type, 'reverse'),
        ]));
    }

    public function voucherWorkflow(Request $request, int $voucher, string $action)
    {
        abort_unless(in_array($action, ['submit', 'approve', 'post'], true), 404);
        try { $this->cash->assertVoucherAccess(DB::table('cash_vouchers')->where('id', $voucher)->firstOrFail(), $request->user(), $action); $this->cash->transition($voucher, $action, $request->user()); return redirect()->route('mobile.accounts.vouchers.show', $voucher)->with('success', 'Voucher workflow updated.'); }
        catch (\Throwable $e) { return redirect()->route('mobile.accounts.vouchers.show', $voucher)->withErrors(['workflow' => $e->getMessage()]); }
    }

    public function voucherReverse(Request $request, int $voucher)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try { $this->cash->assertVoucherAccess(DB::table('cash_vouchers')->where('id', $voucher)->firstOrFail(), $request->user(), 'reverse'); $this->cash->reverseVoucher($voucher, $request->user(), (string) $data['reason']); return redirect()->route('mobile.accounts.vouchers.show', $voucher)->with('success', 'Voucher reversed.'); }
        catch (\Throwable $e) { return redirect()->route('mobile.accounts.vouchers.show', $voucher)->withErrors(['workflow' => $e->getMessage()]); }
    }

    public function adjustmentShow(Request $request, int $adjustment)
    {
        $row = DB::table('advance_adjustments')->where('id', $adjustment)->first(); abort_unless($row, 404);
        $this->assertScopedBranch($request, (int) ($row->branch_id ?? 0));
        abort_unless($this->cash->canUseType($request->user(), (string) ($row->party_type === 'supplier' ? 'supplier_advance' : 'customer_advance'), 'view'), 403);
        $domain = (string) ($row->party_type === 'supplier' ? 'supplier_advance' : 'customer_advance');
        return view('mobile.accounts.show', $this->showData('adjustment', $row, $adjustment, ['activities' => DB::table('advance_adjustment_activities')->where('advance_adjustment_id', $adjustment)->orderByDesc('id')->get(), 'canSubmit' => $this->cash->canUseType($request->user(), $domain, 'update'), 'canApprove' => $this->cash->canUseType($request->user(), $domain, 'approve'), 'canPost' => $this->cash->canUseType($request->user(), $domain, 'post'), 'canReverse' => $this->cash->canUseType($request->user(), $domain, 'reverse')]));
    }

    public function adjustmentWorkflow(Request $request, int $adjustment, string $action)
    {
        abort_unless(in_array($action, ['submit', 'approve', 'post'], true), 404);
        try { $this->cash->transitionAdjustment($adjustment, $action, $request->user()); return redirect()->route('mobile.accounts.adjustments.show', $adjustment)->with('success', 'Advance adjustment workflow updated.'); }
        catch (\Throwable $e) { return redirect()->route('mobile.accounts.adjustments.show', $adjustment)->withErrors(['workflow' => $e->getMessage()]); }
    }

    public function adjustmentReverse(Request $request, int $adjustment)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try { $this->cash->reverseAdjustment($adjustment, $request->user(), (string) $data['reason']); return redirect()->route('mobile.accounts.adjustments.show', $adjustment)->with('success', 'Advance adjustment reversed.'); }
        catch (\Throwable $e) { return redirect()->route('mobile.accounts.adjustments.show', $adjustment)->withErrors(['workflow' => $e->getMessage()]); }
    }

    public function customerReturnShow(Request $request, int $id)
    {
        $row = DB::table('customer_advance_returns')->where('id', $id)->first(); abort_unless($row, 404);
        $this->assertScopedBranch($request, (int) ($row->branch_id ?? 0));
        abort_unless($this->cash->canUseType($request->user(), 'customer_advance_return', 'view'), 403);
        return view('mobile.accounts.show', $this->showData('return', $row, $id, ['allocations' => DB::table('customer_advance_return_allocations')->where('customer_advance_return_id', $id)->get(), 'canSubmit' => $this->cash->canUseType($request->user(), 'customer_advance_return', 'update'), 'canApprove' => $this->cash->canUseType($request->user(), 'customer_advance_return', 'approve'), 'canPost' => $this->cash->canUseType($request->user(), 'customer_advance_return', 'post'), 'canReverse' => $this->cash->canUseType($request->user(), 'customer_advance_return', 'reverse')]));
    }

    public function customerReturnWorkflow(Request $request, int $id, string $action)
    {
        abort_unless(in_array($action, ['submit', 'approve', 'post'], true), 404);
        try { app(\App\Services\Accounting\PartyAdvanceReturnService::class)->transition($id, $action, $request->user()); return redirect()->route('mobile.accounts.customer-returns.show', $id)->with('success', 'Customer advance return workflow updated.'); }
        catch (\Throwable $e) { return redirect()->route('mobile.accounts.customer-returns.show', $id)->withErrors(['workflow' => $e->getMessage()]); }
    }

    public function customerReturnReverse(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try { app(\App\Services\Accounting\PartyAdvanceReturnService::class)->reverse($id, (string) $data['reason'], $request->user()); return redirect()->route('mobile.accounts.customer-returns.show', $id)->with('success', 'Customer advance return reversed.'); }
        catch (\Throwable $e) { return redirect()->route('mobile.accounts.customer-returns.show', $id)->withErrors(['workflow' => $e->getMessage()]); }
    }

    private function mobileRedirect(mixed $response, string $kind): mixed
    {
        if (! method_exists($response, 'getTargetUrl')) return $response;
        $url = $response->getTargetUrl();
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) return $response;
        $patterns = ['voucher' => ['#/cash-vouchers/(\d+)/?$#', 'vouchers.show'], 'adjustment' => ['#/advance-adjustments/(\d+)/?$#', 'adjustments.show'], 'return' => ['#/customer-advance-returns/(\d+)/?$#', 'customer-returns.show']];
        if (! preg_match($patterns[$kind][0], $path, $m)) return $response;
        return redirect()->route('mobile.accounts.'.$patterns[$kind][1], (int) $m[1]);
    }

    private function assertNoDuplicateVoucherReference(Request $request, mixed $user): void
    {
        $reference = $this->normalizeReference($request->input('transaction_reference'));
        if ($reference === '') return;

        $ownership = $this->cash->resolveVoucherOwnership(
            $user,
            $this->nullableInt($request->input('booking_id')),
            $this->nullableInt($request->input('branch_id')),
            $this->nullableInt($request->input('company_id')),
        );
        $existing = DB::table('cash_vouchers')
            ->where('company_id', $ownership['company_id'])
            ->whereNotNull('transaction_reference')
            ->select(['voucher_no', 'transaction_reference'])
            ->get()
            ->first(fn (object $row): bool => $this->normalizeReference($row->transaction_reference) === $reference);
        if ($existing) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'This transaction reference already exists. Duplicate transaction was not saved.',
            ]);
        }
    }

    private function assertNoDuplicateReturnReference(Request $request, mixed $user): void
    {
        $reference = $this->normalizeReference($request->input('transaction_reference'));
        if ($reference === '') return;
        $branch = $this->nullableInt($request->input('branch_id'));
        if (! $branch) return;

        $ownership = $this->cash->resolveVoucherOwnership($user, null, $branch, null);
        $existing = DB::table('customer_advance_returns')
            ->where(function ($query) use ($ownership, $branch): void {
                $query->where('company_id', $ownership['company_id'])
                    ->orWhere(function ($fallback) use ($branch): void {
                        $fallback->whereNull('company_id')->where('branch_id', $branch);
                    });
            })
            ->whereNotNull('transaction_reference')
            ->select(['transaction_reference'])
            ->get()
            ->first(fn (object $row): bool => $this->normalizeReference($row->transaction_reference) === $reference);
        if ($existing) {
            throw ValidationException::withMessages([
                'transaction_reference' => 'This transaction reference already exists. Duplicate transaction was not saved.',
            ]);
        }
    }

    private function normalizeReference(mixed $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) ($value ?? ''))));
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || trim((string) $value) === '' ? null : (int) $value;
    }

    private function showData(string $kind, object $row, int $id, array $extra = []): array
    {
        return array_merge(['layoutMeta' => $this->layout->resolve(), 'kind' => $kind, 'row' => $row, 'id' => $id], $extra);
    }

    private function assertScopedBranch(Request $request, int $branch): void
    {
        abort_if($branch < 1, 404);
        app(\App\Services\Administration\ErpBranchAccessAuthority::class)->assertMayAccessBranch($request->user(), $branch);
    }
}
