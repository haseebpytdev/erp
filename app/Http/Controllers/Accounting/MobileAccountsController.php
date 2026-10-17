<?php

namespace App\Http\Controllers\Accounting;

use App\Services\Accounting\CashVoucherService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            ->select(['voucher_no', 'voucher_type', 'party_name', 'amount', 'status', 'voucher_date'])
            ->orderByDesc('id');
        $this->cash->scopeVoucherQuery($recent, $user);

        return view('mobile.accounts.index', [
            'layoutMeta' => $this->layout->resolve(),
            'cards' => $cards,
            'recent' => $recent->limit(5)->get(),
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
        return app(CashVoucherController::class)->store($request);
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
        return app(AdvanceAdjustmentController::class)->store($request);
    }

    public function customerReturn(Request $request)
    {
        abort_unless($this->cash->canUseType($request->user(), 'customer_advance_return', 'create'), 403);
        return app(PartyBalanceLifecycleController::class)->customerAdvanceReturnCreate($request);
    }

    public function storeCustomerReturn(Request $request)
    {
        return app(PartyBalanceLifecycleController::class)->customerAdvanceReturnStore($request);
    }
}
