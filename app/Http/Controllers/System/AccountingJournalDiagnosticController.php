<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Accounting\ChartOfAccountsWorkspaceService;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Throwable;

final class AccountingJournalDiagnosticController extends Controller
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions,
        private readonly NativeErpLayoutResolver $layout,
        private readonly ChartOfAccountsWorkspaceService $chart,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($this->permissions->isSuperAdmin($request->user()), 403);

        $header = $this->discoverTable([
            'journals',
            'journal_vouchers',
            'journal_entries',
        ]);

        $lines = $this->discoverTable([
            'journal_lines',
            'journal_entry_lines',
            'journal_entries_lines',
            'journal_details',
            'journal_voucher_lines',
            'journal_posting_lines',
            'accounting_journal_lines',
        ]);

        $accountSchema = null;
        try {
            $accountSchema = $this->chart->schema();
        } catch (Throwable $e) {
            report($e);
        }

        $routes = $this->accountingRoutes();
        $accounts = $this->accountControlSnapshot($accountSchema);
        $journalHeaders = $this->journalHeaderSnapshot($header);
        $journalLines = $this->journalLineSnapshot($lines, $accountSchema);
        $cashVouchers = $this->cashVoucherSnapshot($header);
        $classes = $this->nativeClassSnapshot();

        $bridgeReady = (bool) (
            $header['table']
            && $lines['table']
            && $accountSchema
            && ($header['id'] ?? null)
            && ($lines['journal_id'] ?? null)
            && (
                ($lines['account_id'] ?? null)
                || ($lines['account_code'] ?? null)
            )
            && ($lines['debit'] ?? null)
            && ($lines['credit'] ?? null)
        );

        return view('system.accounting-journal-diagnostic-v11318', [
            'layoutMeta' => $this->layout->resolve(),
            'header' => $header,
            'lines' => $lines,
            'accountSchema' => $accountSchema,
            'routes' => $routes,
            'accounts' => $accounts,
            'journalHeaders' => $journalHeaders,
            'journalLines' => $journalLines,
            'cashVouchers' => $cashVouchers,
            'classes' => $classes,
            'bridgeReady' => $bridgeReady,
        ]);
    }

    private function discoverTable(array $candidates): array
    {
        foreach ($candidates as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $columns = Schema::getColumnListing($table);

                return [
                    'table' => $table,
                    'columns' => $columns,
                    'id' => $this->first($columns, ['id', 'journal_id', 'entry_id', 'voucher_id']),
                    'number' => $this->first($columns, ['journal_no', 'voucher_no', 'entry_no', 'number', 'reference']),
                    'type' => $this->first($columns, ['voucher_type', 'journal_type', 'entry_type', 'type', 'transaction_type']),
                    'date' => $this->first($columns, ['journal_date', 'voucher_date', 'entry_date', 'date', 'posting_date', 'posted_at', 'created_at']),
                    'status' => $this->first($columns, ['status', 'journal_status', 'posting_status']),
                    'reference' => $this->first($columns, ['reference', 'posting_reference', 'external_reference', 'source_reference', 'document_no']),
                    'reference_type' => $this->first($columns, ['reference_type', 'source_type', 'document_type']),
                    'reference_id' => $this->first($columns, ['reference_id', 'source_id', 'document_id']),
                    'total_debit' => $this->first($columns, ['total_debit', 'debit_total', 'debit']),
                    'total_credit' => $this->first($columns, ['total_credit', 'credit_total', 'credit']),
                    'total_amount' => $this->first($columns, ['total_amount', 'amount']),
                    'journal_id' => $this->first($columns, ['journal_id', 'journal_entry_id', 'journal_voucher_id', 'entry_id', 'header_id']),
                    'account_id' => $this->first($columns, ['account_id', 'chart_account_id', 'gl_account_id']),
                    'account_code' => $this->first($columns, ['account_code', 'gl_code', 'code']),
                    'party_type' => $this->first($columns, ['party_type', 'subledger_type']),
                    'party_id' => $this->first($columns, ['party_id', 'subledger_id']),
                    'debit' => $this->first($columns, ['debit', 'debit_amount']),
                    'credit' => $this->first($columns, ['credit', 'credit_amount']),
                ];
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [
            'table' => null,
            'columns' => [],
        ];
    }

    private function accountingRoutes(): array
    {
        $wanted = [
            'accounting.journals.index',
            'accounting.journals.store',
            'accounting.journals.post',
            'accounting.ledgers.index',
            'accounting.ledgers.account',
            'accounting.ledgers.customer',
            'accounting.reports.index',
            'accounting.reports.preview',
            'accounting.reports.print',
        ];

        $rows = [];

        foreach ($wanted as $name) {
            try {
                $route = Route::getRoutes()->getByName($name);
                $rows[] = [
                    'name' => $name,
                    'exists' => (bool) $route,
                    'uri' => $route?->uri(),
                    'methods' => $route ? implode('|', $route->methods()) : null,
                    'action' => $route?->getActionName(),
                ];
            } catch (Throwable $e) {
                $rows[] = [
                    'name' => $name,
                    'exists' => false,
                    'uri' => null,
                    'methods' => null,
                    'action' => null,
                ];
            }
        }

        return $rows;
    }

    private function accountControlSnapshot(?array $schema): array
    {
        if (! $schema || empty($schema['table']) || empty($schema['code']) || empty($schema['name'])) {
            return [];
        }

        try {
            $select = [
                $schema['id'].' as id',
                $schema['code'].' as code',
                $schema['name'].' as name',
            ];

            foreach (['type', 'subtype', 'control_type', 'posting', 'active', 'status'] as $key) {
                if (! empty($schema[$key])) {
                    $select[] = $schema[$key].' as '.$key;
                }
            }

            return DB::table($schema['table'])
                ->select($select)
                ->whereIn($schema['code'], ['1010', '1020', '1021', '1022', '1023', '1130', '1140', '2110', '2120'])
                ->orderBy($schema['code'])
                ->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function journalHeaderSnapshot(array $schema): array
    {
        if (empty($schema['table'])) {
            return [];
        }

        try {
            $select = [];

            foreach ([
                'id', 'number', 'type', 'date', 'status',
                'reference', 'reference_type', 'reference_id',
                'total_debit', 'total_credit', 'total_amount',
            ] as $key) {
                if (! empty($schema[$key])) {
                    $select[] = $schema[$key].' as '.$key;
                }
            }

            if ($select === []) {
                return [];
            }

            $query = DB::table($schema['table'])->select($select);

            if (! empty($schema['id'])) {
                $query->orderByDesc($schema['id']);
            } elseif (! empty($schema['date'])) {
                $query->orderByDesc($schema['date']);
            }

            return $query->limit(8)->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function journalLineSnapshot(array $schema, ?array $accountSchema): array
    {
        if (empty($schema['table'])) {
            return [];
        }

        try {
            $select = [];

            foreach ([
                'id', 'journal_id', 'account_id', 'account_code',
                'party_type', 'party_id', 'debit', 'credit',
            ] as $key) {
                if (! empty($schema[$key])) {
                    $select[] = $schema[$key].' as '.$key;
                }
            }

            if ($select === []) {
                return [];
            }

            $query = DB::table($schema['table'])->select($select);

            if (! empty($schema['id'])) {
                $query->orderByDesc($schema['id']);
            } elseif (! empty($schema['journal_id'])) {
                $query->orderByDesc($schema['journal_id']);
            }

            $rows = $query->limit(12)->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();

            if (
                $accountSchema
                && ! empty($accountSchema['table'])
                && ! empty($accountSchema['id'])
                && ! empty($accountSchema['code'])
                && ! empty($schema['account_id'])
            ) {
                $ids = array_values(array_unique(array_filter(array_map(
                    static fn (array $row): int => (int) ($row['account_id'] ?? 0),
                    $rows
                ))));

                if ($ids !== []) {
                    $map = DB::table($accountSchema['table'])
                        ->whereIn($accountSchema['id'], $ids)
                        ->pluck($accountSchema['code'], $accountSchema['id']);

                    foreach ($rows as &$row) {
                        $accountId = (int) ($row['account_id'] ?? 0);
                        if ($accountId > 0) {
                            $row['resolved_account_code'] = $map[$accountId] ?? null;
                        }
                    }
                    unset($row);
                }
            }

            return $rows;
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function cashVoucherSnapshot(array $headerSchema): array
    {
        if (! Schema::hasTable('cash_vouchers')) {
            return [];
        }

        try {
            $rows = DB::table('cash_vouchers')
                ->where('status', 'posted')
                ->orderByDesc('id')
                ->limit(12)
                ->get([
                    'id',
                    'voucher_no',
                    'voucher_type',
                    'party_type',
                    'party_id',
                    'amount',
                    'currency_code',
                    'cash_bank_account_code',
                    'posting_reference',
                    'status',
                ]);

            return $rows->map(function ($row) use ($headerSchema): array {
                $native = $this->nativeJournalMatch($row, $headerSchema);

                return [
                    'id' => (int) $row->id,
                    'voucher_no' => (string) $row->voucher_no,
                    'voucher_type' => (string) $row->voucher_type,
                    'party_type' => (string) $row->party_type,
                    'party_id' => $row->party_id ? (int) $row->party_id : null,
                    'amount' => (float) $row->amount,
                    'currency_code' => (string) $row->currency_code,
                    'cash_bank_account_code' => (string) $row->cash_bank_account_code,
                    'posting_reference' => (string) ($row->posting_reference ?? ''),
                    'status' => (string) $row->status,
                    'native_journal_found' => $native['found'],
                    'native_journal_id' => $native['id'],
                    'native_journal_number' => $native['number'],
                    'match_basis' => $native['basis'],
                ];
            })->all();
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    private function nativeJournalMatch(object $voucher, array $schema): array
    {
        if (empty($schema['table'])) {
            return ['found' => false, 'id' => null, 'number' => null, 'basis' => 'no native header table'];
        }

        $attempts = [];

        if (! empty($schema['reference'])) {
            foreach (array_filter([
                (string) ($voucher->voucher_no ?? ''),
                (string) ($voucher->posting_reference ?? ''),
            ]) as $value) {
                $attempts[] = ['column' => $schema['reference'], 'value' => $value, 'basis' => 'reference'];
            }
        }

        if (! empty($schema['number'])) {
            $attempts[] = [
                'column' => $schema['number'],
                'value' => (string) ($voucher->voucher_no ?? ''),
                'basis' => 'journal number',
            ];
        }

        foreach ($attempts as $attempt) {
            try {
                $row = DB::table($schema['table'])
                    ->where($attempt['column'], $attempt['value'])
                    ->first();

                if ($row) {
                    return [
                        'found' => true,
                        'id' => ! empty($schema['id']) ? ($row->{$schema['id']} ?? null) : null,
                        'number' => ! empty($schema['number']) ? ($row->{$schema['number']} ?? null) : null,
                        'basis' => $attempt['basis'].': '.$attempt['column'],
                    ];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (! empty($schema['reference_id'])) {
            try {
                $query = DB::table($schema['table'])
                    ->where($schema['reference_id'], (int) $voucher->id);

                if (! empty($schema['reference_type'])) {
                    $query->where(function ($q) use ($schema): void {
                        $q->where($schema['reference_type'], 'like', '%cash%')
                            ->orWhere($schema['reference_type'], 'like', '%voucher%')
                            ->orWhere($schema['reference_type'], 'like', '%payment%')
                            ->orWhere($schema['reference_type'], 'like', '%receipt%');
                    });
                }

                $row = $query->first();

                if ($row) {
                    return [
                        'found' => true,
                        'id' => ! empty($schema['id']) ? ($row->{$schema['id']} ?? null) : null,
                        'number' => ! empty($schema['number']) ? ($row->{$schema['number']} ?? null) : null,
                        'basis' => 'reference_id'.(! empty($schema['reference_type']) ? ' + reference_type' : ''),
                    ];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return ['found' => false, 'id' => null, 'number' => null, 'basis' => 'no match'];
    }

    private function nativeClassSnapshot(): array
    {
        $classes = [
            'App\\Http\\Controllers\\Accounting\\JournalController',
            'App\\Http\\Controllers\\Accounting\\LedgerController',
            'App\\Http\\Controllers\\Accounting\\ReportController',
            'App\\Services\\Accounting\\JournalService',
            'App\\Services\\Accounting\\JournalPostingService',
            'App\\Services\\Accounting\\AccountingPostingService',
        ];

        $result = [];

        foreach ($classes as $class) {
            $row = [
                'class' => $class,
                'exists' => class_exists($class),
                'public_methods' => [],
            ];

            if ($row['exists']) {
                try {
                    $reflection = new ReflectionClass($class);
                    foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                        if ($method->getDeclaringClass()->getName() !== $class) {
                            continue;
                        }

                        $params = array_map(
                            static fn ($param): string => '$'.$param->getName(),
                            $method->getParameters()
                        );

                        $row['public_methods'][] = $method->getName().'('.implode(', ', $params).')';
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $result[] = $row;
        }

        return $result;
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
