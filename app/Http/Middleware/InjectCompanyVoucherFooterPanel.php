<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class InjectCompanyVoucherFooterPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (!$request->isMethod('GET') || !$response->isSuccessful()) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (!is_string($content) || $content === '' || !str_contains($content, '</section></main>')) {
            return $response;
        }

        $company = Company::query()->first();
        if (!$company) {
            return $response;
        }

        $upgradeInstalled = Schema::hasTable('companies')
            && Schema::hasColumn('companies', 'voucher_footer_html');

        $canManage = (bool) ($request->user()?->hasPermission('organization.company.manage'));

        $panel = view('organization.partials.company-voucher-footer', [
            'company' => $company,
            'voucherFooterUpgradeInstalled' => $upgradeInstalled,
            'canManageCompanyVoucherFooter' => $canManage,
        ])->render();

        $response->setContent(str_replace(
            '</section></main>',
            $panel . '</section></main>',
            $content
        ));

        return $response;
    }
}
