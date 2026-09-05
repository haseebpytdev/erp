<?php

namespace App\Http\Middleware;

use App\Services\Dashboard\NativeFinancialSnapshotService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PresentDashboardFinancialSnapshot
{
    public function __construct(
        private readonly NativeFinancialSnapshotService $snapshot,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            return $response;
        }

        $path = trim(strtolower($request->path()), '/');
        $route = $request->route();
        $routeName = strtolower((string) ($route?->getName() ?? ''));
        $action = strtolower((string) ($route?->getActionName() ?? ''));

        $isDashboard = (
            $path === ''
            || $path === 'dashboard'
            || $routeName === 'dashboard'
            || str_contains($action, 'dashboardcontroller')
        );

        if (! $isDashboard) {
            return $response;
        }

        $html = (string) $response->getContent();

        if ($html === '') {
            return $response;
        }

        $data = $this->snapshot->get($request);

        $money = static function (float $amount, bool $signed = false): string {
            if ($signed) {
                if (abs($amount) < 0.005) {
                    return 'PKR 0.00';
                }

                return 'PKR '.number_format(abs($amount), 2)
                    .($amount > 0 ? ' Dr' : ' Cr');
            }

            return 'PKR '.number_format(max(0, $amount), 2);
        };

        $replacements = [
            'TODAY SALES' => $money((float) $data['today_sales']),
            'MONTH SALES' => $money((float) $data['month_sales']),
            'RECEIVABLES' => $money((float) $data['receivables']),
            'PAYABLES' => $money((float) $data['payables']),
            'CASH & BANK' => $money((float) $data['cash_bank'], true),
            'GROSS PROFIT' => $money((float) $data['gross_profit'], true),
            'Today Collection' => $money((float) $data['today_collection']),
            'Today Payments' => $money((float) $data['today_payments']),
            'Period Supplier Cost' => $money((float) $data['period_supplier_cost']),
            'Month Sales' => $money((float) $data['month_sales']),
        ];

        foreach ($replacements as $label => $value) {
            $html = $this->replaceFirstValueAfterLabel($html, $label, $value);
        }

        foreach (($data['recent_voucher_refs'] ?? []) as $postingRef => $voucherNo) {
            if ($postingRef !== '' && $voucherNo !== '') {
                $html = str_replace(
                    e($postingRef),
                    e($voucherNo),
                    $html
                );
                $html = str_replace(
                    $postingRef,
                    $voucherNo,
                    $html
                );
            }
        }

        // ERP-11.3.30: do not inject auxiliary accounting cards by searching
        // for generic shell text such as OPERATIONS. Core dashboard financial
        // values above remain synchronized; navigation markup is never targeted.

        $response->setContent($html);

        return $response;
    }

    private function replaceFirstValueAfterLabel(
        string $html,
        string $label,
        string $replacement
    ): string {
        $position = stripos($html, $label);

        if ($position === false) {
            return $html;
        }

        $length = min(1400, strlen($html) - $position);
        $window = substr($html, $position, $length);

        $pattern = '/>\s*(?:—|PKR\s*-?[\d,.]+(?:\s+(?:Dr|Cr))?|-?[\d,.]+\.\d{2})\s*</i';

        if (! preg_match($pattern, $window, $match, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $absolute = $position + $match[0][1];
        $old = $match[0][0];
        $new = '>'.e($replacement).'<';

        return substr_replace($html, $new, $absolute, strlen($old));
    }

}
