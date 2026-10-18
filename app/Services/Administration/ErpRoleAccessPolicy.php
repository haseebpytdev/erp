<?php

namespace App\Services\Administration;

use Illuminate\Http\Request;

/**
 * ERP-10.31.79
 *
 * Permission-driven authorization policy.
 *
 * Super Admin is unrestricted. Every other account receives navigation and
 * direct-route access from the effective permissions attached to its role(s).
 * Role names no longer hard-code the operational menu.
 */
class ErpRoleAccessPolicy
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions
    ) {
    }

    public function isSuperAdmin(mixed $user): bool
    {
        return $this->permissions->isSuperAdmin($user);
    }

    public function isPermissionControlled(mixed $user): bool
    {
        return (bool) $user && ! $this->isSuperAdmin($user);
    }

    public function mayAccess(Request $request, mixed $user): bool
    {
        if (! $this->isPermissionControlled($user)) {
            return true;
        }

        $path = strtolower(trim($request->path(), '/'));
        if ($path === '' || in_array($path, ['dashboard', 'home'], true)) {
            return true;
        }

        if (preg_match('#(^|/)(login|logout|password|profile|account)(/|$)#', $path) === 1) {
            return true;
        }

        /* C89.5.1B.0B deliberately distinguishes the two ownership markers
         * from unmarked native/external routes. Support assets stay
         * authenticated-only; marked business routes must be classified and
         * may never use the historical unknown-route pass-through. */
        $businessMarked = $this->routeHasMarker($request, 'C89ManagedBusinessRoute');
        $supportMarked = $this->routeHasMarker($request, 'C89AuthenticatedSupportAssetRoute');
        if ($supportMarked && ! $businessMarked) {
            return true;
        }

        $module = $this->moduleForRequest($request);
        if ($businessMarked && $module === null) {
            return false;
        }

        if ($module === 'additional_services') {
            return $this->additionalServicesAllowed($request, $user);
        }
        /* Booking-side invoice creation is an operational bridge, not invoice management. */
        if (
            $module === 'sales_invoices'
            && strtoupper($request->method()) === 'POST'
            && str_contains('/'.$path.'/', '/sales/invoices/from-booking/')
        ) {
            return $this->moduleAllowed($user, 'bookings');
        }

        if ($module !== null) {
            /* Known module routes always use their module permission, including
             * JSON/AJAX reads. The compatibility lookup applies only to routes
             * which cannot be classified to a module. */
            return $this->moduleAllowed(
                $user,
                $module,
                $this->actionTerms($request)
            );
        }

        /* Read-only lookup endpoints remain available to permitted operations. */
        if (! $businessMarked &&
            strtoupper($request->method()) === 'GET'
            && ($request->expectsJson() || $request->ajax() || str_contains((string) $request->header('accept'), 'application/json'))
            && $this->anyOperationalAccess($user)
        ) {
            return true;
        }

        if ($module === null) {
            /* Unknown native routes are left to their own existing middleware. */
            return true;
        }

        return $this->moduleAllowed(
            $user,
            $module,
            $this->actionTerms($request)
        );
    }

    public function canManageRbac(mixed $user): bool
    {
        return $this->isSuperAdmin($user)
            || $this->permissions->hasPermissionLike($user, [
                'manage roles and permissions',
                'manage role',
                'role permission',
                'manage permissions',
            ]);
    }

    public function moduleAllowed(mixed $user, string $module, array $actionTerms = []): bool
    {
        $definition = $this->definitions()[$module] ?? null;

        if (! $definition) {
            return true;
        }

        return $this->permissions->hasStrictCapability(
            $user,
            $definition['capability_phrases'] ?? [],
            $actionTerms
        );
    }

    private function routeHasMarker(Request $request, string $marker): bool
    {
        try {
            foreach ((array) ($request->route()?->gatherMiddleware() ?? []) as $middleware) {
                $middleware = (string) $middleware;
                if ($middleware === $marker || str_ends_with($middleware, '\\'.$marker)) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function additionalServicesAllowed(Request $request, mixed $user): bool
    {
        $path = strtolower('/'.trim($request->path(), '/').'/');
        $routeName = strtolower((string) ($request->route()?->getName() ?? ''));
        $method = strtoupper($request->method());

        if (str_contains($routeName, '.invoice') || str_contains($path, '/sales-invoice/')) {
            return $this->permissions->hasExactPermission($user, ['booking_services.manage'])
                && $this->permissions->hasExactPermission($user, ['sales_invoices.create']);
        }

        foreach (['submit', 'approve', 'reject'] as $action) {
            if (str_contains($routeName, '.workflow') && str_contains($path, '/'.$action.'/')) {
                $permission = match ($action) {
                    'approve' => 'bookings.confirm',
                    'reject' => 'bookings.cancel',
                    default => 'bookings.submit',
                };
                return $this->permissions->hasExactPermission($user, [$permission]);
            }
        }

        if ($method === 'GET' || $method === 'HEAD') {
            return $this->permissions->hasExactPermission($user, ['bookings.view']);
        }

        return $this->permissions->hasExactPermission($user, ['booking_services.manage']);
    }

    /** @return array<int,string> */
    public function hiddenNavigationLabels(mixed $user): array
    {
        if (! $this->isPermissionControlled($user)) {
            return [];
        }

        $labels = [];
        foreach ($this->definitions() as $key => $definition) {
            if (! $this->moduleAllowed($user, $key)) {
                $labels = array_merge($labels, $definition['labels']);
            }
        }

        $groups = [
            'MASTER DATA' => ['party_master', 'travel_masters', 'products_services'],
            'OPERATIONS' => ['bookings', 'sales_invoices', 'supplier_costing', 'passengers', 'vendor_bills', 'refunds'],
            'ACCOUNTING' => ['cash_vouchers', 'receipts', 'payments', 'journals', 'ledgers', 'reports'],
            'TRAVEL REPORTS' => ['travel_reports'],
        ];

        foreach ($groups as $label => $modules) {
            $any = false;
            foreach ($modules as $module) {
                if ($this->moduleAllowed($user, $module)) {
                    $any = true;
                    break;
                }
            }
            if (! $any) {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /** @return array<int,string> */
    public function hiddenNavigationHrefFragments(mixed $user): array
    {
        if (! $this->isPermissionControlled($user)) {
            return [];
        }

        $fragments = [];
        foreach ($this->definitions() as $key => $definition) {
            if (! $this->moduleAllowed($user, $key)) {
                $fragments = array_merge($fragments, $definition['hrefs']);
            }
        }

        return array_values(array_unique($fragments));
    }

    public function summary(): array
    {
        return $this->permissions->summary();
    }

    private function anyOperationalAccess(mixed $user): bool
    {
        foreach (['bookings', 'passengers', 'travel_masters', 'party_master'] as $module) {
            if ($this->moduleAllowed($user, $module)) {
                return true;
            }
        }
        return false;
    }

    private function moduleForRequest(Request $request): ?string
    {
        $path = strtolower('/'.trim($request->path(), '/').'/');
        $routeName = '';
        try {
            $routeName = strtolower((string) ($request->route()?->getName() ?? ''));
        } catch (\Throwable) {
        }
        $combined = $path.' '.$routeName;

        if (
            str_contains($routeName, 'bookings.additional-services.')
            || preg_match('#^/(?:operations/bookings|system/erp-bookings)/[0-9]+/additional-services(?:/|$)#', $path) === 1
        ) {
            return 'additional_services';
        }

        if (preg_match('#^/mobile/accounts(?:/|$)#', $path) === 1 || str_contains($routeName, 'mobile.accounts.')) {
            return 'mobile_accounts';
        }

        /* C89.1: keep critical system and reporting families inside the
         * canonical resolver instead of allowing them to fall through as
         * unknown routes. */
        if (preg_match('#^/system/erp-diagnostics(?:/|$)#', $path) === 1) {
            return 'system_diagnostics';
        }

        if (preg_match('#^/system/erp-repair(?:/|$)#', $path) === 1) {
            return 'system_repairs';
        }

        if (
            preg_match('#^/system/(?:post-reset-financial-cleanup|production-data-reset)(?:/|$)#', $path) === 1
        ) {
            return 'system_destructive';
        }

        if (preg_match('#^/system/visa-masters(?:/|$)#', $path) === 1) {
            return 'travel_masters';
        }

        if (preg_match('#^/reports/group-umrah-profitability(?:/|$)#', $path) === 1) {
            return 'travel_reports';
        }

        if (preg_match('#^/mobile/movements(?:/|$)#', $path) === 1) {
            return 'travel_reports';
        }

        if (preg_match('#^/operations/group-package-bookings(?:/|$)#', $path) === 1) {
            return 'bookings';
        }

        /* Booking Product workspaces are Booking Operations, not Master Data.
         * Resolve this explicit ownership before the broader /products master
         * patterns are considered. */
        if (
            preg_match('#^/operations/bookings/[0-9]+/products(?:/|$)#', $path) === 1
            || str_contains($routeName, 'bookings.products.')
            || preg_match('#^/system/erp-bookings/[0-9]+/(?:air|hotel|transport|visa)-product(?:/|$)#', $path) === 1
            || preg_match('#^/system/erp-bookings/[0-9]+/(?:operational-summary|invoice-summary)(?:/|$)#', $path) === 1
        ) {
            return 'bookings';
        }

        foreach ($this->definitions() as $key => $definition) {
            foreach ($definition['paths'] as $needle) {
                if (str_contains($combined, strtolower($needle))) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function actionTerms(Request $request): array
    {
        $path = strtolower('/'.trim($request->path(), '/').'/');
        $method = strtoupper($request->method());

        $special = [
            'approve' => ['approve', 'authorize', 'manage'],
            'reject' => ['reject', 'approve', 'manage'],
            'post' => ['post', 'manage'],
            'reverse' => ['reverse', 'void', 'manage'],
            'cancel' => ['cancel', 'void', 'manage'],
            'refund' => ['refund', 'manage'],
            'confirm' => ['confirm', 'approve', 'fulfill', 'manage'],
            'submit' => ['submit', 'create', 'manage'],
            'issue' => ['issue', 'fulfill', 'manage'],
        ];

        foreach ($special as $needle => $terms) {
            if (str_contains($path, '/'.$needle) || str_contains($path, $needle.'/')) {
                return $terms;
            }
        }

        return match ($method) {
            'GET', 'HEAD' => ['view', 'list', 'read', 'access', 'create', 'add', 'edit', 'update', 'manage'],
            'POST' => ['create', 'add', 'prepare', 'process', 'manage', 'fulfill'],
            'PUT', 'PATCH' => ['edit', 'update', 'manage'],
            'DELETE' => ['delete', 'remove', 'manage'],
            default => ['manage'],
        };
    }

    /** @return array<string,array<string,array<int,string>>> */
    private function definitions(): array
    {
        /*
         * ERP-10.31.79
         *
         * Navigation and route access are deliberately mapped to explicit
         * capability phrases from ERP-02. Avoid generic nouns such as:
         *   party, service, ticket, invoice, branch, product
         * because those words appear in unrelated accounting permissions.
         */
        return [
            'mobile_accounts' => [
                'labels' => ['Accounts Quick Posting'],
                'hrefs' => ['/mobile/accounts'],
                'paths' => ['/mobile/accounts', 'mobile.accounts.'],
                'capability_phrases' => [
                    'view payments', 'create payments', 'manage payments',
                    'view receipts', 'create receipts', 'manage receipts',
                    'customer advances', 'manage customer advances',
                    'supplier advances', 'manage supplier advances',
                    'customer advance returns', 'manage customer advance returns',
                    'view payment vouchers', 'create payment vouchers',
                    'view receipt vouchers', 'create receipt vouchers',
                ],
            ],
            'additional_services' => [
                'labels' => ['Additional Services'],
                'hrefs' => ['/additional-services'],
                'paths' => ['bookings.additional-services.'],
                'capability_phrases' => ['bookings.view'],
            ],
            'system_diagnostics' => [
                'labels' => ['System Diagnostics'],
                'hrefs' => ['/system/erp-diagnostics'],
                'paths' => ['/system/erp-diagnostics'],
                'capability_phrases' => [
                    'system.health',
                    'system health',
                ],
            ],

            'system_repairs' => [
                'labels' => ['System Repairs', 'System Maintenance'],
                'hrefs' => ['/system/erp-repair'],
                'paths' => ['/system/erp-repair'],
                'capability_phrases' => [
                    'system.update',
                    'system update',
                ],
            ],

            'system_destructive' => [
                'labels' => ['Production Data Reset', 'Post-Reset Financial Cleanup'],
                'hrefs' => ['/system/production-data-reset', '/system/post-reset-financial-cleanup'],
                'paths' => ['/system/production-data-reset', '/system/post-reset-financial-cleanup'],
                'capability_phrases' => [
                    'system.update',
                    'system update',
                ],
            ],

            'sales_invoices' => [
                'labels' => ['Sales Invoices', 'Sales Invoice'],
                'hrefs' => ['/sales/invoices'],
                'paths' => ['/sales/invoices', 'sales.invoices'],
                'capability_phrases' => [
                    'view sales invoices',
                    'create sales invoices',
                    'update sales invoices',
                    'edit sales invoices',
                    'approve sales invoices',
                    'post sales invoices',
                    'manage sales invoices',
                    'sales invoice management',
                ],
            ],

            'supplier_costing' => [
                'labels' => ['Supplier Costing', 'Supplier Costs'],
                'hrefs' => [
                    '/supplier-costing',
                    '/supplier-cost',
                    '/supplier/costing',
                    '/costing',
                ],
                'paths' => [
                    '/supplier-costing',
                    '/supplier-cost',
                    '/supplier/costing',
                    '/costing',
                ],
                'capability_phrases' => [
                    'view supplier costing',
                    'create supplier costing',
                    'manage supplier costing',
                    'update supplier costing',
                    'approve supplier costing',
                    'post supplier costing',
                    'supplier costing management',
                    'view supplier costs',
                    'manage supplier costs',
                ],
            ],

            'vendor_bills' => [
                'labels' => ['Vendor Bills'],
                'hrefs' => ['/vendor-bills', '/vendor/bills'],
                'paths' => ['/vendor-bills', '/vendor/bills'],
                'capability_phrases' => [
                    'view vendor bills',
                    'create vendor bills',
                    'update vendor bills',
                    'approve vendor bills',
                    'post vendor bills',
                    'manage vendor bills',
                    'view supplier bills',
                    'manage supplier bills',
                ],
            ],

            'refunds' => [
                'labels' => ['Refunds'],
                'hrefs' => ['/refunds'],
                'paths' => ['/refunds', 'refunds.'],
                'capability_phrases' => [
                    'view refunds',
                    'create refunds',
                    'update refunds',
                    'approve refunds',
                    'manage refunds',
                    'view credit notes',
                    'create credit notes',
                    'approve credit notes',
                ],
            ],

            'cash_vouchers' => [
                'labels' => ['Payments & Receipts', 'Cash Vouchers', 'Expense Vouchers', 'Contra Vouchers', 'Advance Adjustments'],
                'hrefs' => ['/accounting/cash-vouchers', '/accounting/advance-adjustments'],
                'paths' => ['/accounting/cash-vouchers', '/accounting/advance-adjustments', 'accounting.cash-vouchers', 'accounting.advance-adjustments'],
                'capability_phrases' => [
                    'view receipts', 'create receipts', 'update receipts', 'approve receipts', 'post receipts', 'manage receipts',
                    'view receipt vouchers', 'create receipt vouchers', 'approve receipt vouchers', 'post receipt vouchers', 'manage receipt vouchers',
                    'view payments', 'create payments', 'update payments', 'approve payments', 'post payments', 'manage payments',
                    'view payment vouchers', 'create payment vouchers', 'approve payment vouchers', 'post payment vouchers', 'manage payment vouchers',
                    'view expense vouchers', 'create expense vouchers', 'update expense vouchers', 'approve expense vouchers', 'post expense vouchers', 'reverse expense vouchers', 'manage expense vouchers',
                    'view contra vouchers', 'create contra vouchers', 'update contra vouchers', 'approve contra vouchers', 'post contra vouchers', 'reverse contra vouchers', 'manage contra vouchers',
                    'customer advances', 'supplier advances', 'manage customer advances', 'manage supplier advances',
                ],
            ],

            'receipts' => [
                'labels' => ['Receipts', 'Receipt Voucher'],
                'hrefs' => ['/receipts'],
                'paths' => ['/receipts', 'receipts.'],
                'capability_phrases' => [
                    'view receipts',
                    'create receipts',
                    'update receipts',
                    'manage receipts',
                    'view receipt vouchers',
                    'create receipt vouchers',
                    'manage receipt vouchers',
                ],
            ],

            'payments' => [
                'labels' => ['Payments', 'Payment Voucher'],
                'hrefs' => ['/payments'],
                'paths' => ['/payments', 'payments.'],
                'capability_phrases' => [
                    'view payments',
                    'create payments',
                    'update payments',
                    'manage payments',
                    'view payment vouchers',
                    'create payment vouchers',
                    'manage payment vouchers',
                ],
            ],

            'journals' => [
                'labels' => ['Journals'],
                'hrefs' => ['/journals'],
                'paths' => ['/journals', 'journals.'],
                'capability_phrases' => [
                    'view journals',
                    'create journals',
                    'update journals',
                    'approve journals',
                    'post journals',
                    'manage journals',
                    'view journal entries',
                    'create journal entries',
                    'post journal entries',
                ],
            ],

            'ledgers' => [
                'labels' => ['Ledgers', 'Ledger'],
                'hrefs' => ['/ledgers', '/ledger', '/trial-balance'],
                'paths' => ['/ledgers', '/ledger', '/trial-balance'],
                'capability_phrases' => [
                    'view ledgers',
                    'view ledger',
                    'view trial balance',
                    'view financial statements',
                    'view general ledger',
                ],
            ],

            'reports' => [
                'labels' => ['Reports'],
                'hrefs' => ['/reports'],
                'paths' => ['/reports', 'reports.'],
                'capability_phrases' => [
                    'view reports',
                    'export reports',
                    'manage reports',
                    'view analytics',
                    'financial reports',
                    'management reports',
                ],
            ],

            'travel_reports' => [
                'labels' => [
                    'Travel Reports', 'Report Center', 'Booking Report', 'Passenger Report',
                    'Air / Ticketing Report', 'Hotel Report', 'Visa Report', 'Transport Report',
                    'Group Umrah Report', 'Customer-wise Report', 'Supplier / Vendor-wise Report',
                    'Branch-wise Report', 'Agent / Salesperson Report', 'Airline-wise Report', 'Sector / Destination Report',
                ],
                'hrefs' => ['/travel-reports'],
                'paths' => ['/travel-reports', 'travel-reports.'],
                'capability_phrases' => [
                    'view travel reports', 'travel reports', 'manage travel reports', 'export travel reports',
                    'view reports', 'view analytics', 'manage reports',
                ],
            ],

            'party_master' => [
                'labels' => ['Party Master'],
                'hrefs' => ['/party-master', '/parties'],
                'paths' => ['/party-master', '/parties', 'parties.'],
                'capability_phrases' => [
                    'view party master',
                    'manage party master',
                    'create parties',
                    'update parties',
                    'manage customer profiles',
                    'manage supplier profiles',
                    'manage vendor profiles',
                    'manage agent profiles',
                    'view customer profiles',
                    'view supplier profiles',
                    'view vendor profiles',
                    'view agent profiles',
                ],
            ],

            'travel_masters' => [
                'labels' => ['Travel Masters'],
                'hrefs' => ['/master-data/travel-masters', '/travel-masters'],
                'paths' => ['/master-data/travel-masters', '/travel-masters', 'travel-masters.'],
                'capability_phrases' => [
                    'view travel masters',
                    'manage travel masters',
                    'manage airline master',
                    'manage airport master',
                    'manage hotel master',
                    'manage saved routes',
                    'view saved routes',
                ],
            ],

            'products_services' => [
                'labels' => [
                    'Products & Services',
                    'Products and Services',
                ],
                'hrefs' => [
                    '/products-services',
                    '/products-and-services',
                    '/products',
                ],
                'paths' => [
                    '/products-services',
                    '/products-and-services',
                    '/products',
                    'products.',
                ],
                'capability_phrases' => [
                    'view products and services',
                    'view products services',
                    'manage products and services',
                    'manage products services',
                    'create products and services',
                    'update products and services',
                    'products and services master',
                ],
            ],

            'currency_rates' => [
                'labels' => ['Currency Rates'],
                'hrefs' => ['/currency-rates'],
                'paths' => ['/currency-rates', 'currency-rates.'],
                'capability_phrases' => [
                    'view currency rates',
                    'manage currency rates',
                    'update currency rates',
                    'manage exchange rates',
                ],
            ],

            'financial_years' => [
                'labels' => ['Financial Years'],
                'hrefs' => ['/financial-years'],
                'paths' => ['/financial-years', 'financial-years.'],
                'capability_phrases' => [
                    'view financial years',
                    'manage financial years',
                    'create financial years',
                    'close financial years',
                    'manage fiscal years',
                ],
            ],

            'foundation' => [
                'labels' => ['Foundation'],
                'hrefs' => [
                    '/foundation',
                    '/accounting/chart-of-accounts',
                    '/accounting/chart-of-accounts-workspace',
                    '/accounting/account-mappings',
                    '/chart-of-accounts',
                    '/account-mappings',
                ],
                'paths' => [
                    '/foundation',
                    '/accounting/chart-of-accounts',
                    '/accounting/chart-of-accounts-workspace',
                    '/accounting/account-mappings',
                    '/chart-of-accounts',
                    '/account-mappings',
                ],
                'capability_phrases' => [
                    'view chart of accounts',
                    'manage chart of accounts',
                    'manage account mappings',
                    'view account mappings',
                    'manage foundation',
                ],
            ],

            'organization' => [
                'labels' => ['Organization'],
                'hrefs' => ['/organization'],
                'paths' => ['/organization', 'organization.'],
                'capability_phrases' => [
                    'view organization',
                    'manage organization',
                    'manage branches',
                    'create branches',
                    'update branches',
                    'manage legal entities',
                ],
            ],

            'administration' => [
                'labels' => ['Administration'],
                'hrefs' => ['/administration'],
                'paths' => ['/administration', 'administration.'],
                'capability_phrases' => [
                    'view administration',
                    'manage administration',
                    'manage users',
                    'create users',
                    'update users',
                    'manage staff',
                    'create staff',
                    'update staff',
                    'manage roles and permissions',
                    'view roles and permissions',
                    'manage approval authority',
                    'reset user passwords',
                ],
            ],

            'passengers' => [
                'labels' => ['Passengers'],
                'hrefs' => ['/passengers'],
                'paths' => ['/passengers', 'passengers.'],
                'capability_phrases' => [
                    'view passengers',
                    'manage passengers',
                    'create passengers',
                    'update passengers',
                    'passenger operations',
                ],
            ],

            'bookings' => [
                'labels' => [
                    'Bookings',
                    'Air Tickets',
                    'Hotel Stays',
                    'Visa',
                ],
                'hrefs' => [
                    '/operations/bookings',
                    '/bookings',
                ],
                'paths' => [
                    '/operations/bookings',
                    '/bookings',
                    'bookings.',
                ],
                'capability_phrases' => [
                    'view bookings',
                    'list bookings',
                    'create bookings',
                    'update bookings',
                    'manage bookings',
                    'booking lifecycle',
                    'manage booking lifecycle',
                    'booking operations',
                    'manage booking operations',
                    'export bookings',
                    'manage operational voucher details',
                    'view operational voucher details',
                ],
            ],
        ];
    }
}
