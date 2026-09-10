<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

final class CashVoucherDrilldownResolver
{
    public function __construct(
        private readonly ChartOfAccountsWorkspaceService $chart,
        private readonly CashVoucherNativeJournalBridge $nativeJournal,
    ) {
    }

    public function accountLedgerUrl(string $accountCode): ?string
    {
        $accountCode = trim($accountCode);
        if ($accountCode === '') {
            return null;
        }

        try {
            $nativeRoute = Route::getRoutes()->getByName('accounting.ledgers.account');
            if (! $nativeRoute || ! in_array('GET', $nativeRoute->methods(), true)) {
                return null;
            }

            $parameters = $nativeRoute->parameterNames();
            if (count($parameters) !== 1) {
                return null;
            }

            $schema = $this->chart->schema();
            $account = DB::table($schema['table'])
                ->where($schema['code'], $accountCode)
                ->first([$schema['id'].' as id', $schema['code'].' as code']);
            if (! $account || (int) $account->id <= 0) {
                return null;
            }

            $parameter = $parameters[0];
            $bindingField = method_exists($nativeRoute, 'bindingFieldFor')
                ? $nativeRoute->bindingFieldFor($parameter)
                : null;
            $value = in_array($bindingField, ['code', 'account_code'], true)
                ? (string) $account->code
                : (int) $account->id;

            return route('accounting.ledgers.account', [$parameter => $value]);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    public function nativeJournalUrl(int $voucherId, bool $reversal = false): ?string
    {
        try {
            $journalId = $this->nativeJournal->journalIdForVoucher($voucherId, $reversal);
            if (! $journalId) {
                return null;
            }

            $nativeRoute = $this->journalDetailRoute();
            if (! $nativeRoute) {
                return null;
            }

            $name = $nativeRoute->getName();
            $parameters = $nativeRoute->parameterNames();
            if (! $name || count($parameters) !== 1) {
                return null;
            }

            return route($name, [$parameters[0] => $journalId]);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    public function manualJournalAuthority(): ?array
    {
        foreach (['accounting.journals.store', 'accounting.journal-entries.store'] as $name) {
            $nativeRoute = Route::getRoutes()->getByName($name);
            if ($nativeRoute && in_array('POST', $nativeRoute->methods(), true)) {
                return [
                    'route' => $name,
                    'uri' => $nativeRoute->uri(),
                    'controller' => $nativeRoute->getActionName(),
                ];
            }
        }

        return null;
    }

    private function journalDetailRoute(): mixed
    {
        foreach ([
            'accounting.journals.show',
            'accounting.journals.view',
            'accounting.journals.details',
            'accounting.journal-entries.show',
            'accounting.journal-entries.view',
        ] as $name) {
            $nativeRoute = Route::getRoutes()->getByName($name);
            if ($nativeRoute && in_array('GET', $nativeRoute->methods(), true)) {
                return $nativeRoute;
            }
        }

        foreach (Route::getRoutes() as $nativeRoute) {
            if (
                in_array('GET', $nativeRoute->methods(), true)
                && preg_match('#^accounting/(?:journals|journal-entries)/\{[^}]+\}(?:/(?:view|details))?$#', $nativeRoute->uri()) === 1
            ) {
                return $nativeRoute;
            }
        }

        return null;
    }
}
