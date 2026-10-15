<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CashVoucherLegacyBranchReconciliationService;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CashVoucherLegacyBranchReconciliationController extends Controller
{
    public function __construct(
        private readonly CashVoucherLegacyBranchReconciliationService $reconciliation,
        private readonly ErpPermissionMatrixService $permissions,
        private readonly NativeErpLayoutResolver $layout,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeDiagnostic($request);
        $filters = $request->only(['classification','voucher_type','status','q']);
        return view('accounting.cash-vouchers.legacy-branch-reconciliation', [
            'layoutMeta' => $this->layout->resolve(),
            'filters' => $filters,
            'summary' => $this->reconciliation->summary($filters),
            'rows' => $this->reconciliation->page($filters, (int) $request->query('page', 1)),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeDiagnostic($request);
        $filters = $request->only(['classification','voucher_type','status','q']);
        return response()->streamDownload(function () use ($filters): void {
            $handle = fopen('php://output', 'wb');
            foreach ($this->reconciliation->csvRows($filters) as $row) fputcsv($handle, $row);
            fclose($handle);
        }, 'cash-voucher-legacy-reconciliation.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeDiagnostic(Request $request): void
    {
        abort_unless($request->user() && $this->permissions->isSuperAdmin($request->user()), 403);
    }
}
