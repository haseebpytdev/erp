<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CompanyVoucherFooterController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $company = Company::query()->firstOrFail();

        abort_unless(
            Schema::hasTable('companies') && Schema::hasColumn('companies', 'voucher_footer_html'),
            422,
            'Voucher footer database upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade once, then Clear Application Cache.'
        );

        $data = $request->validate([
            'voucher_footer_html' => ['nullable', 'string', 'max:10000'],
        ]);

        DB::table('companies')->where('id', $company->id)->update([
            'voucher_footer_html' => $this->sanitizeFooterHtml((string) ($data['voucher_footer_html'] ?? '')),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('organization.company.edit')
            ->with('status', 'Company default voucher footer saved. It is used only when no voucher-specific or selected IATA footer is available.');
    }

    private function sanitizeFooterHtml(string $html): ?string
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        $clean = strip_tags($html, '<br><p><div><span><strong><b><em><i><u>');
        $clean = preg_replace('/<(br|p|div|span|strong|b|em|i|u)\b[^>]*>/i', '<$1>', $clean);

        return trim((string) $clean) ?: null;
    }
}
