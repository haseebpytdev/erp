<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\ChartOfAccountsWorkspaceService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class ChartOfAccountsWorkspaceController extends Controller
{
    public function __construct(
        private readonly ChartOfAccountsWorkspaceService $accounts,
        private readonly NativeErpLayoutResolver $layout,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'type' => strtolower(trim((string) $request->query('type', ''))),
            'status' => strtolower(trim((string) $request->query('status', ''))),
            'per_page' => (int) $request->query('per_page', 25),
        ];

        $schemaError = null;
        try {
            $rows = $this->accounts->indexRows($filters);
            $summary = $this->accounts->summary();
            $parents = $this->accounts->parentOptions();
            $schemaLabel = $this->accounts->schemaLabel();
        } catch (\Throwable $e) {
            $schemaError = $e->getMessage();
            $rows = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25, 1, ['path' => $request->url()]);
            $summary = ['asset'=>0,'liability'=>0,'equity'=>0,'income'=>0,'expense'=>0,'total'=>0];
            $parents = [];
            $schemaLabel = 'unresolved';
        }

        return view('accounting.chart-of-accounts.workspace', [
            'layoutMeta' => $this->layout->resolve(),
            'rows' => $rows,
            'summary' => $summary,
            'parents' => $parents,
            'filters' => $filters,
            'schemaError' => $schemaError,
            'schemaLabel' => $schemaLabel,
            'hasNativeEdit' => $this->hasNativeEditRoute(),
        ]);
    }

    public function nextCode(Request $request): JsonResponse
    {
        $data = $request->validate(['parent_id' => ['required', 'integer', 'min:1']]);
        try {
            return response()->json([
                'ok' => true,
                'code' => $this->accounts->nextCode((int) $data['parent_id']),
                'normal_balance' => null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'parent_id' => ['required', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:asset,liability,equity,income,expense'],
            'subtype' => ['nullable', 'string', 'max:100'],
            'control_type' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allow_posting' => ['nullable', 'boolean'],
            'is_control' => ['nullable', 'boolean'],
        ]);

        try {
            $id = $this->accounts->create($data);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['account' => $e->getMessage()]);
        }

        return redirect()->route('accounting.chart-of-accounts.workspace')->with('success', 'Account created successfully (ID '.$id.').');
    }

    private function hasNativeEditRoute(): bool
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (preg_match('#^accounting/chart-of-accounts/\{[^}]+\}/edit$#', $route->uri()) === 1) {
                return true;
            }
        }
        return false;
    }
}
