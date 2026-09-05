<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CashVoucherNativeJournalBridge;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;

final class CashVoucherNativeJournalRepairController extends Controller
{
    public function __construct(
        private readonly CashVoucherNativeJournalBridge $bridge,
        private readonly ErpPermissionMatrixService $permissions,
        private readonly NativeErpLayoutResolver $layout,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($this->permissions->isSuperAdmin($request->user()), 403);

        $rows = $this->bridge->previewMissing();

        return view('system.cash-voucher-native-journal-repair-v11319', [
            'layoutMeta' => $this->layout->resolve(),
            'rows' => $rows,
            'missing' => count(array_filter($rows, static fn (array $row): bool => $row['needs_backfill'])),
        ]);
    }

    public function execute(Request $request)
    {
        abort_unless($this->permissions->isSuperAdmin($request->user()), 403);

        $request->validate([
            'confirmation' => ['required', 'in:BACKFILL POSTED CASH VOUCHERS'],
        ]);

        $result = $this->bridge->backfillPostedVouchers($request->user());


        $redirect = redirect()
            ->route('system.erp-repair.cash-voucher-native-journals')
            ->with('repair_result', $result);

        if ((int) $result['failed'] > 0) {
            return $redirect->withErrors([
                'backfill' => 'Native journal backfill completed with '.$result['failed'].' failure(s). Review the exact errors below.',
            ]);
        }

        return $redirect->with('success', 'Native journal backfill completed successfully.');
    }
}
