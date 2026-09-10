<?php

namespace App\Http\Middleware;

use App\Services\Accounting\CashVoucherService;
use App\Services\Administration\ErpRoleAccessPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-11.3.30
 *
 * Server-side navigation normalization only. No browser-side menu mutation.
 * Focused Air Ticket / Group Umrah workspaces retain authority to hide the
 * native sidebar and expose it only through their compact Menu drawer.
 */
final class PresentCashVoucherLinks
{
    public function __construct(
        private readonly ErpRoleAccessPolicy $policy,
        private readonly CashVoucherService $cashVouchers,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            return $response;
        }

        $type = strtolower((string) $response->headers->get('content-type', ''));
        if ($type !== '' && ! str_contains($type, 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();
        if ($html === '') {
            return $response;
        }

        // Canonicalize known live workspace links without replacing native layout.
        $html = $this->rewriteAnchor($html, 'Chart of Accounts', route('accounting.chart-of-accounts.workspace'), 'coa');
        $html = $this->rewriteAnchor($html, 'Supplier Costing', route('purchase.supplier-costing.index'), 'supplier-costing');

        // Remove native placeholder rows for modules that are not part of the
        // current controlled ERP flow. They can return later when implemented.
        foreach (['Passengers', 'Vendor Bills', 'Refunds'] as $unused) {
            $html = preg_replace(
                '~<div\b[^>]*class=(?:"[^"]*nav-item[^"\']*muted[^"]*"|\'[^\']*nav-item[^\']*muted[^\']*\')[^>]*>.*?<span>\s*'.preg_quote($unused, '~').'\s*</span>.*?</div>~is',
                '',
                $html
            ) ?? $html;
        }

        // Chart/Supplier link fixes are independent from cash-voucher permission.
        if ($this->policy->moduleAllowed($request->user(), 'cash_vouchers')) {
            $receiptUrl = route('accounting.cash-vouchers.index', ['type' => 'receipt']);
            $paymentUrl = route('accounting.cash-vouchers.index', ['type' => 'payment']);
            $expenseUrl = route('accounting.cash-vouchers.index', ['mode' => 'expenses']);
            $contraUrl = route('accounting.cash-vouchers.index', ['mode' => 'contra']);

            $onVoucherWorkspace = str_starts_with(trim($request->path(), '/'), 'accounting/cash-vouchers');
            $selectedType = strtolower(trim((string) $request->query('type', '')));
            $receiptActive = $onVoucherWorkspace && $selectedType === 'receipt' ? ' active' : '';
            $paymentActive = $onVoucherWorkspace && $selectedType === 'payment' ? ' active' : '';
            $expenseActive = $onVoucherWorkspace
                && strtolower((string) $request->query('mode', '')) === 'expenses'
                ? ' active'
                : '';
            $contraActive = $onVoucherWorkspace
                && strtolower((string) $request->query('mode', '')) === 'contra'
                ? ' active'
                : '';

            $receiptLink = '<a class="nav-item'.$receiptActive.'" href="'.e($receiptUrl).'" data-et-live-accounting-nav="receipt"><span>↓</span><span>Receipts</span></a>';
            $paymentLink = '<a class="nav-item'.$paymentActive.'" href="'.e($paymentUrl).'" data-et-live-accounting-nav="payment"><span>↑</span><span>Payments</span></a>';
            $expenseLink = '<a class="nav-item'.$expenseActive.'" href="'.e($expenseUrl).'" data-et-live-accounting-nav="expense"><span>≡</span><span>Expense Vouchers</span></a>';
            $contraLink = '<a class="nav-item'.$contraActive.'" href="'.e($contraUrl).'" data-et-live-accounting-nav="contra"><span>⇄</span><span>Contra Vouchers</span></a>';

            $html = str_replace(
                '<div class="nav-item muted"><span>•</span><span>Receipts</span><em>Soon</em></div>',
                $receiptLink,
                $html
            );
            $html = str_replace(
                '<div class="nav-item muted"><span>•</span><span>Payments</span><em>Soon</em></div>',
                $paymentLink,
                $html
            );

            if (
                $this->cashVouchers->canUseType($request->user(), 'expense', 'view')
                && ! str_contains($html, 'data-et-live-accounting-nav="expense"')
            ) {
                $html = str_replace($paymentLink, $paymentLink.$expenseLink, $html);
            }
            if (
                $this->cashVouchers->canUseType($request->user(), 'contra', 'view')
                && ! str_contains($html, 'data-et-live-accounting-nav="contra"')
            ) {
                $anchor = str_contains($html, 'data-et-live-accounting-nav="expense"')
                    ? $expenseLink
                    : $paymentLink;
                $html = str_replace($anchor, $anchor.$contraLink, $html);
            }
        }

        // Never force sidebar display mode. Focused booking pages intentionally
        // hide it with display:none and reopen it as a drawer through Menu.
        $style = <<<'HTML'
<style data-et-sidebar-shell="ERP-11.3.30">
@media (min-width:900px){
  body:not(.gp-focus-mode):not(.et-air-focus-mode-103172) .app-shell{min-height:100vh!important;align-items:flex-start!important}
  body:not(.gp-focus-mode):not(.et-air-focus-mode-103172) .sidebar{position:sticky!important;top:0!important;height:100vh!important;max-height:100vh!important;align-self:flex-start!important;overflow:hidden!important}
  body:not(.gp-focus-mode):not(.et-air-focus-mode-103172) .sidebar .nav{min-height:0!important;overflow-y:auto!important;overscroll-behavior:contain;scrollbar-gutter:stable}
  [data-gp-focus-sidebar]:not(.gp-focus-sidebar-open){display:none!important}
  [data-et-air-focus-sidebar]:not(.et-air-focus-sidebar-open-103172){display:none!important}
  [data-gp-focus-sidebar].gp-focus-sidebar-open{display:block!important;position:fixed!important}
  [data-et-air-focus-sidebar].et-air-focus-sidebar-open-103172{display:block!important;position:fixed!important}
}
</style>
HTML;

        if (! str_contains($html, 'data-et-sidebar-shell="ERP-11.3.30"')) {
            $html = str_contains($html, '</head>')
                ? str_replace('</head>', $style.'</head>', $html)
                : $style.$html;
        }

        $response->setContent($html);
        return $response;
    }

    private function rewriteAnchor(string $html, string $labelNeedle, string $url, string $key): string
    {
        return preg_replace_callback(
            '~<a\b([^>]*)>(.*?)</a>~is',
            static function (array $match) use ($labelNeedle, $url, $key): string {
                $label = trim(preg_replace('/\s+/', ' ', strip_tags($match[2])) ?? '');
                if (stripos($label, $labelNeedle) === false) {
                    return $match[0];
                }

                $attrs = $match[1];
                if (preg_match('/\bhref\s*=\s*(["\']).*?\1/is', $attrs)) {
                    $attrs = preg_replace(
                        '/\bhref\s*=\s*(["\']).*?\1/is',
                        'href="'.e($url).'"',
                        $attrs,
                        1
                    ) ?? $attrs;
                } else {
                    $attrs .= ' href="'.e($url).'"';
                }

                $attrs .= ' data-et-canonical-nav="'.e($key).'"';
                return '<a'.$attrs.'>'.$match[2].'</a>';
            },
            $html
        ) ?? $html;
    }
}
