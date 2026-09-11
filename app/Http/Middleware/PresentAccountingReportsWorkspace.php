<?php

namespace App\Http\Middleware;

use App\Services\Accounting\CashVoucherService;
use App\Services\Accounting\ChartOfAccountsWorkspaceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ERP-11.3.67
 *
 * Native ReportController stays authoritative. This middleware transforms only
 * the native index/preview HTML after the controller has rendered it.
 * No DOM PHP extension, route guessing, inline JS, or replacement report SQL.
 */
final class PresentAccountingReportsWorkspace
{
    public function __construct(
        private readonly CashVoucherService $cashVouchers,
        private readonly ChartOfAccountsWorkspaceService $chart,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * ERP-11.3.37 — party-ledger control-account scope.
         *
         * Native ReportController remains authoritative, but it must receive the
         * correct control account before it calculates a Vendor/Customer ledger.
         * Otherwise every native journal line carrying party_type=vendor/customer
         * is included, including Vendor Advances (1140) / Customer Advances
         * (2120), which incorrectly nets advances against AP/AR.
         */
        $this->bindPartyLedgerControlAccount($request);

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

        $html = $this->enrichCashVoucherRows($html);
        $html = $this->enrichSupplierCostingRows($html);
        $html = $this->replaceNativeFilter($request, $html);
        $html = $this->injectManagementReportingNavigation($html);
        $html = $this->reconcilePartyControlLedger($request, $html);
        $html = $this->reconcileGeneralLedgerAccountFilter($request, $html);
        $html = $this->formatLedgerAmounts($html);
        $html = $this->finalizePartyLedgerTotalBalance($request, $html);
        $html = $this->presentAccountLedgerStatement($request, $html);
        $html = $this->presentTrialBalanceStatement($request, $html);
        $response->setContent($html);
        return $response;
    }

    private function bindPartyLedgerControlAccount(Request $request): void
    {
        $mode = $this->requestedReportMode($request);

        if (! in_array($mode, ['vendor', 'customer'], true)) {
            return;
        }

        $accountId = $this->controlAccountId(
            $mode === 'vendor' ? 'VENDOR_AP' : 'CUSTOMER_AR',
            $mode === 'vendor' ? '2110' : '1130'
        );

        if ($accountId <= 0) {
            return;
        }

        /*
         * The installed native Reports form uses account_id. merge() writes to
         * Laravel's active input source for the current request, so this reaches
         * the native ReportController before query construction.
         */
        $request->merge([
            'account_id' => $accountId,
        ]);
    }

    private function requestedReportMode(Request $request): string
    {
        foreach ([
            'report_type',
            'report',
            'type',
            'ledger_type',
        ] as $key) {
            $value = $request->input($key);

            if (! is_scalar($value)) {
                continue;
            }

            $mode = $this->modeFromReportValue((string) $value);

            if ($mode !== 'general') {
                return $mode;
            }
        }

        /*
         * Compatibility fallback for installations using another report-field
         * name: inspect only scalar fields whose KEY itself looks report-related.
         */
        foreach ($request->all() as $key => $value) {
            if (
                ! is_scalar($value)
                || (
                    ! str_contains(strtolower((string) $key), 'report')
                    && ! str_contains(strtolower((string) $key), 'ledger')
                )
            ) {
                continue;
            }

            $mode = $this->modeFromReportValue((string) $value);

            if ($mode !== 'general') {
                return $mode;
            }
        }

        return 'general';
    }

    private function modeFromReportValue(string $value): string
    {
        $value = strtolower(trim(str_replace(
            ['_', '-'],
            ' ',
            $value
        )));

        if (
            str_contains($value, 'vendor')
            || str_contains($value, 'supplier')
        ) {
            return 'vendor';
        }

        if (
            str_contains($value, 'customer')
            || str_contains($value, 'client')
        ) {
            return 'customer';
        }

        if (str_contains($value, 'party')) {
            return 'party';
        }

        if (
            str_contains($value, 'account')
            || str_contains($value, 'general ledger')
            || str_contains($value, 'gl ledger')
        ) {
            return 'account';
        }

        return 'general';
    }

    private function controlAccountId(string $controlType, string $fallbackCode): int
    {
        try {
            $schema = $this->chart->schema();
            $query = DB::table($schema['table']);

            if ($schema['control_type']) {
                $id = (int) (
                    (clone $query)
                        ->where($schema['control_type'], $controlType)
                        ->value($schema['id'])
                    ?? 0
                );

                if ($id > 0) {
                    return $id;
                }
            }

            return (int) (
                DB::table($schema['table'])
                    ->where($schema['code'], $fallbackCode)
                    ->value($schema['id'])
                ?? 0
            );
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function replaceNativeFilter(Request $request, string $html): string
    {
        if (str_contains($html, 'data-et-report-filter="ERP-11.3.34"')) {
            return $html;
        }

        if (! preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $html, $forms, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $target = null;

        foreach ($forms[0] as $form) {
            $plain = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($form[0]))));

            if (str_contains($plain, 'report type') && str_contains($plain, 'date from')) {
                $target = $form;
                break;
            }
        }

        if (! $target) {
            return $html;
        }

        $native = $target[0];

        $report = $this->controlAfterLabel(
            $native,
            static fn (string $x): bool => $x === 'report type'
        );

        $account = $this->controlAfterLabel(
            $native,
            static fn (string $x): bool => $x === 'account'
        );

        $party = $this->controlAfterLabel(
            $native,
            static fn (string $x): bool => in_array($x, ['customer', 'vendor', 'party'], true)
        );

        $from = $this->controlAfterLabel(
            $native,
            static fn (string $x): bool => $x === 'date from'
        );

        $to = $this->controlAfterLabel(
            $native,
            static fn (string $x): bool =>
                str_contains($x, 'date to') || str_contains($x, 'as of')
        );

        if (! $report || ! $from || ! $to) {
            return $html;
        }

        $partyName = $party['name'] ?: 'party_id';
        $accountName = $account['name'] ?? 'account_id';

        $vendors = array_map(
            static fn (array $row): array => [
                'value' => (string) ((int) ($row['id'] ?? 0)),
                'label' => trim((string) ($row['name'] ?? '')),
            ],
            $this->cashVouchers->partyOptions('supplier')
        );

        $customers = array_map(
            static fn (array $row): array => [
                'value' => (string) ((int) ($row['id'] ?? 0)),
                'label' => trim((string) ($row['name'] ?? '')),
            ],
            $this->cashVouchers->partyOptions('customer')
        );

        $partyRows = [];
        foreach (array_merge($customers, $vendors) as $row) {
            if ($row['value'] === '' || $row['value'] === '0' || $row['label'] === '') {
                continue;
            }
            $partyRows[$row['value']] = $row;
        }

        $accounts = $account
            ? array_values(array_filter(
                $this->selectOptions($account['html']),
                static fn (array $row): bool =>
                    $row['value'] !== '' && $row['label'] !== ''
            ))
            : [];

        $datasets = [
            'vendor' => array_values(array_filter(
                $vendors,
                static fn (array $row): bool =>
                    $row['value'] !== '' && $row['value'] !== '0' && $row['label'] !== ''
            )),
            'customer' => array_values(array_filter(
                $customers,
                static fn (array $row): bool =>
                    $row['value'] !== '' && $row['value'] !== '0' && $row['label'] !== ''
            )),
            'party' => array_values($partyRows),
            'account' => $accounts,
        ];

        $selectedReport = $this->selectedText($report['html']);
        $mode = $this->reportMode($selectedReport);

        $currentParty = (string) $request->input($partyName, '');
        $currentAccount = (string) $request->input($accountName, '');

        $initialRows = $datasets[$mode] ?? [];
        $initialLabel = match ($mode) {
            'vendor' => 'Vendor',
            'customer' => 'Customer',
            'party' => 'Party',
            'account' => 'Account',
            default => 'Ledger',
        };
        $initialPlaceholder = match ($mode) {
            'vendor' => 'Select vendor',
            'customer' => 'Select customer',
            'party' => 'Select party',
            'account' => 'Select account',
            default => 'Not required',
        };
        $initialName = $mode === 'account' ? $accountName : $partyName;
        $initialSelected = $mode === 'account' ? $currentAccount : $currentParty;

        $subjectHtml = $this->datasetSelect(
            $initialName,
            $initialRows,
            $initialSelected,
            $initialPlaceholder,
            $mode === 'general'
        );

        $reportHtml = $this->cleanControl(
            $report['html'],
            ['data-et-report-type' => '1']
        );
        $fromHtml = $this->cleanControl($from['html']);
        $toHtml = $this->cleanControl($to['html']);

        preg_match('/^<form\b([^>]*)>/is', $native, $open);
        $attrs = $open[1] ?? '';
        $action = $this->attr($attrs, 'action') ?: route('accounting.reports.index');
        $method = strtolower($this->attr($attrs, 'method') ?: 'get');

        if (! in_array($method, ['get', 'post'], true)) {
            $method = 'get';
        }

        $hidden = '';

        if (preg_match_all(
            '/<input\b[^>]*type=["\']hidden["\'][^>]*>/is',
            $native,
            $hiddens
        )) {
            foreach ($hiddens[0] as $input) {
                if (preg_match('/\bname=["\']_token["\']/i', $input)) {
                    continue;
                }

                // Do not preserve a stale subject from the native four-filter form.
                $hiddenName = $this->controlName($input);

                if (in_array($hiddenName, [$partyName, $accountName], true)) {
                    continue;
                }

                $hidden .= $input;
            }
        }

        if ($method === 'post') {
            $hidden .= '<input type="hidden" name="_token" value="'.e(csrf_token()).'">';
        }

        $json = json_encode(
            [
                'vendor' => $datasets['vendor'],
                'customer' => $datasets['customer'],
                'party' => $datasets['party'],
                'account' => $datasets['account'],
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        $style = <<<'HTML'
<style data-et-report-filter-style="ERP-11.3.34">
.et-rf{margin:0;width:100%}
.et-rf-grid{display:grid;grid-template-columns:minmax(170px,.9fr) minmax(220px,1.15fr) minmax(150px,.72fr) minmax(150px,.72fr) minmax(205px,.82fr);gap:11px;align-items:end;width:100%}
.et-rf-field{min-width:0}
.et-rf-field label{display:block;margin:0 0 6px;font-size:10px;font-weight:900;color:#485a70}
.et-rf-control{display:block;width:100%!important;max-width:none!important;min-width:0!important;height:42px!important;min-height:42px!important;margin:0!important;padding:8px 11px!important;border:1px solid #d4deea!important;border-radius:7px!important;background:#fff!important;color:#24364d!important;font-size:12px!important;box-shadow:none!important}
.et-rf-actions{display:grid;grid-template-columns:minmax(125px,1fr) 74px;gap:8px;min-width:0}
.et-rf-btn{height:42px;min-width:0;padding:0 12px;border:1px solid #ccd7e4;border-radius:7px;background:#fff;color:#26384e!important;text-decoration:none!important;font-size:10.5px;font-weight:850;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;cursor:pointer}
.et-rf-btn.primary{background:#0964df;border-color:#0964df;color:#fff!important}
.et-rf-note{margin-top:8px;font-size:9.5px;color:#7a899b;line-height:1.45}
.et-rf-balance{white-space:nowrap!important;font-weight:800!important}
.et-rf-subject-hidden{display:none!important}
@media(max-width:1180px){
  .et-rf-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .et-rf-actions{grid-column:1/-1;grid-template-columns:minmax(150px,220px) 90px;justify-content:end}
}
@media(max-width:650px){
  .et-rf-grid{grid-template-columns:1fr}
  .et-rf-actions{grid-column:auto;grid-template-columns:1fr 1fr;justify-content:stretch}
}
.et-general-ledger-account-scope{margin:-4px 0 10px;color:#64748b;font-size:12px;font-weight:600}
</style>
HTML;

        $subjectClass = $mode === 'general'
            ? ' et-rf-subject-hidden'
            : '';

        $form = $style
            .'<form class="et-rf" data-et-report-filter="ERP-11.3.34"'
            .' data-party-field="'.e($partyName).'"'
            .' data-account-field="'.e($accountName).'"'
            .' data-current-party="'.e($currentParty).'"'
            .' data-current-account="'.e($currentAccount).'"'
            .' method="'.e($method).'" action="'.e($action).'">'
            .$hidden
            .'<div class="et-rf-grid">'
            .'<div class="et-rf-field"><label>Report Type</label>'.$reportHtml.'</div>'
            .'<div class="et-rf-field'.$subjectClass.'" data-et-report-subject-wrap="1">'
            .'<label data-et-report-subject-label="1">'.e($initialLabel).'</label>'
            .$subjectHtml
            .'</div>'
            .'<div class="et-rf-field"><label>Date From</label>'.$fromHtml.'</div>'
            .'<div class="et-rf-field"><label>Date To / As Of</label>'.$toHtml.'</div>'
            .'<div class="et-rf-actions">'
            .'<button class="et-rf-btn primary" type="submit">Apply &amp; Preview</button>'
            .'<a class="et-rf-btn" href="'.e(route('accounting.reports.index')).'">Reset</a>'
            .'</div>'
            .'</div>'
            .'<div class="et-rf-note">Choose the report, then the matching Vendor, Customer or Account. The second list changes instantly; no preliminary Apply step is required.</div>'
            .'<script type="application/json" data-et-report-datasets="ERP-11.3.34">'.$json.'</script>'
            .'</form>';

        $html = substr_replace(
            $html,
            $form,
            $target[1],
            strlen($target[0])
        );

        $script = '<script src="'.e(route('system.erp-assets.reports-filter')).'?v=11.3.34" defer data-et-report-filter-js="ERP-11.3.34"></script>';

        if (
            ! str_contains($html, 'data-et-report-filter-js="ERP-11.3.34"')
            && str_contains($html, '</body>')
        ) {
            $html = str_replace('</body>', $script.'</body>', $html);
        }

        return $html;
    }

    private function injectManagementReportingNavigation(string $html): string
    {
        if (str_contains($html, 'data-et-management-report-nav=')) {
            return $html;
        }

        try {
            $links = [
                ['Management Overview', route('accounting.management-reports.management')],
                ['Profit & Loss', route('accounting.management-reports.profit-and-loss')],
                ['Trial Balance', route('accounting.management-reports.trial-balance')],
                ['Balance Sheet', route('accounting.management-reports.balance-sheet')],
                ['Ledger Reports', route('accounting.reports.index')],
            ];
        } catch (Throwable) {
            return $html;
        }

        $items = '';
        foreach ($links as [$label, $url]) {
            $items .= '<a href="'.e($url).'">'.e($label).'</a>';
        }

        $release = (string) config('et_erp_release.release', 'ERP-11.3');
        $navigation = <<<'HTML'
<style data-et-management-report-nav-style="__RELEASE__">
.et-management-report-nav{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 13px;padding:10px;border:1px solid #dbe4ef;border-radius:9px;background:#f8fafc}
.et-management-report-nav a{display:inline-flex;align-items:center;min-height:34px;padding:7px 11px;border:1px solid #d3deea;border-radius:7px;background:#fff;color:#29415f!important;text-decoration:none!important;font-size:10px;font-weight:850}
.et-management-report-nav a:hover{border-color:#1769d2;color:#1769d2!important}
@media(max-width:650px){.et-management-report-nav a{flex:1 1 calc(50% - 6px);justify-content:center;text-align:center}}
@media print{.et-management-report-nav{display:none!important}}
</style>
<nav class="et-management-report-nav" data-et-management-report-nav="__RELEASE__" aria-label="Management accounting reports">__LINKS__</nav>
HTML;
        $navigation = str_replace(['__LINKS__', '__RELEASE__'], [$items, e($release)], $navigation);

        if (preg_match('/<form\b/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            return substr_replace($html, $navigation, $match[0][1], 0);
        }

        return $navigation.$html;
    }

    private function controlAfterLabel(string $form, callable $matches): ?array
    {
        /*
         * ERP-11.3.34 native-form parser.
         *
         * The installed ERP uses the established field pattern:
         *   <label><span>Report Type</span><select ...>...</select></label>
         *
         * Earlier report overlays incorrectly stripped the WHOLE label text,
         * so option text became part of the label name (for example
         * "report type vendor ledger customer ledger ...") and the exact
         * label match could never succeed. They also searched only AFTER the
         * closing </label>, while the control is actually INSIDE that label.
         *
         * Read the first <span> as the field caption and the control from the
         * same label. Keep the old sibling-control path as compatibility for
         * any alternate/native screen markup.
         */
        if (! preg_match_all(
            '/<label\\b[^>]*>(.*?)<\\/label>/is',
            $form,
            $labels,
            PREG_OFFSET_CAPTURE
        )) {
            return null;
        }

        $count = count($labels[0]);

        for ($i = 0; $i < $count; $i++) {
            $inner = (string) $labels[1][$i][0];

            if (preg_match('/<span\\b[^>]*>(.*?)<\\/span>/is', $inner, $caption)) {
                $captionHtml = $caption[1];
            } else {
                $parts = preg_split(
                    '/<(?:select|input|textarea)\\b/i',
                    $inner,
                    2
                );
                $captionHtml = (string) ($parts[0] ?? $inner);
            }

            $labelText = strtolower(trim((string) preg_replace(
                '/\\s+/',
                ' ',
                strip_tags($captionHtml)
            )));

            if (! $matches($labelText)) {
                continue;
            }

            // Primary/native path: control is nested inside the same label.
            if (preg_match(
                "/(<select\\b[^>]*>.*?<\\/select>|<input\\b(?![^>]*type=[\"']hidden[\"'])[^>]*>|<textarea\\b[^>]*>.*?<\\/textarea>)/is",
                $inner,
                $control
            )) {
                return [
                    'html' => $control[1],
                    'name' => $this->controlName($control[1]),
                ];
            }

            // Compatibility path: some views may place control after </label>.
            $start = $labels[0][$i][1] + strlen($labels[0][$i][0]);
            $end = $i + 1 < $count
                ? $labels[0][$i + 1][1]
                : strlen($form);
            $segment = substr($form, $start, max(0, $end - $start));

            if (preg_match(
                "/(<select\\b[^>]*>.*?<\\/select>|<input\\b(?![^>]*type=[\"']hidden[\"'])[^>]*>|<textarea\\b[^>]*>.*?<\\/textarea>)/is",
                $segment,
                $control
            )) {
                return [
                    'html' => $control[1],
                    'name' => $this->controlName($control[1]),
                ];
            }
        }

        return null;
    }

    private function selectOptions(string $selectHtml): array
    {
        $rows = [];

        if (! preg_match_all(
            '/<option\b([^>]*)>(.*?)<\/option>/is',
            $selectHtml,
            $options,
            PREG_SET_ORDER
        )) {
            return $rows;
        }

        foreach ($options as $option) {
            $value = $this->attr($option[1], 'value');
            $label = trim((string) preg_replace(
                '/\s+/',
                ' ',
                html_entity_decode(strip_tags($option[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            ));

            $rows[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $rows;
    }

    private function datasetSelect(
        string $name,
        array $rows,
        string $selected,
        string $placeholder,
        bool $disabled = false
    ): string {
        $html = '<select class="et-rf-control"'
            .' data-et-report-subject="1"'
            .' name="'.e($name).'"'
            .($disabled ? ' disabled' : '')
            .'>';

        $html .= '<option value="">'.e($placeholder).'</option>';

        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            $label = trim((string) ($row['label'] ?? ''));

            if ($value === '' || $label === '') {
                continue;
            }

            $html .= '<option value="'.e($value).'"'
                .($selected !== '' && $selected === $value ? ' selected' : '')
                .'>'.e($label).'</option>';
        }

        return $html.'</select>';
    }

    private function controlName(string $html): string
    {
        if(preg_match('/\bname\s*=\s*["\']([^"\']+)["\']/i',$html,$m)) return $m[1];
        return '';
    }

    private function selectedText(string $select): string
    {
        if(preg_match('/<option\b[^>]*selected[^>]*>(.*?)<\/option>/is',$select,$m)) return trim(strip_tags($m[1]));
        if(preg_match('/<option\b[^>]*>(.*?)<\/option>/is',$select,$m)) return trim(strip_tags($m[1]));
        return '';
    }

    private function reportMode(string $text): string
    {
        $t=strtolower(trim($text));
        if(str_contains($t,'vendor')) return 'vendor';
        if(str_contains($t,'customer')) return 'customer';
        if(str_contains($t,'party')) return 'party';
        if(str_contains($t,'account')||str_contains($t,'general ledger')||str_contains($t,'gl ledger')) return 'account';
        return 'general';
    }

    private function cleanControl(string $html, array $extra=[]): string
    {
        $html=preg_replace('/\s(?:class|style|id)\s*=\s*["\'][^"\']*["\']/i','',$html)??$html;
        $attrs=' class="et-rf-control"';
        foreach($extra as $k=>$v)$attrs.=' '.e($k).'="'.e($v).'"';
        return preg_replace('/^<([a-z0-9]+)/i','<$1'.$attrs,trim($html),1)??$html;
    }

    private function partySelect(string $name,array $options,int $selected,string $placeholder): string
    {
        $html='<select class="et-rf-control" name="'.e($name).'" data-et-report-subject="party"><option value="">'.e($placeholder).'</option>';
        foreach($options as $option){$id=(int)($option['id']??0);$n=trim((string)($option['name']??''));if($id<=0||$n==='')continue;$html.='<option value="'.$id.'"'.($selected===$id?' selected':'').'>'.e($n).'</option>';}
        return $html.'</select>';
    }

    private function attr(string $attrs,string $name): string
    {
        if(preg_match('/\b'.preg_quote($name,'/').'\s*=\s*["\']([^"\']*)["\']/i',$attrs,$m)) return html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
        return '';
    }

    private function enrichCashVoucherRows(string $html): string
    {
        if (! Schema::hasTable('cash_vouchers')) {
            return $html;
        }

        preg_match_all(
            '/\b(?:PV|RV|CAV|SAV|CAR|SAP)-\d{4}-\d{4,}\b/i',
            strip_tags($html),
            $matches
        );

        $numbers = array_values(array_unique(array_map(
            static fn ($x) => strtoupper(trim((string) $x)),
            $matches[0] ?? []
        )));

        if ($numbers === []) {
            return $html;
        }

        try {
            $columns = Schema::getColumnListing('cash_vouchers');
            $wanted = array_values(array_intersect([
                'voucher_no',
                'transaction_reference',
                'instrument_no',
                'narration',
                'party_name',
                'party_type',
                'party_id',
            ], $columns));

            if (! in_array('voucher_no', $wanted, true)) {
                return $html;
            }

            $records = DB::table('cash_vouchers')
                ->whereIn('voucher_no', $numbers)
                ->get($wanted)
                ->keyBy(
                    static fn ($row) =>
                        strtoupper((string) $row->voucher_no)
                );

            return preg_replace_callback(
                '/<table\b[^>]*>.*?<\/table>/is',
                function (array $tableMatch) use ($records): string {
                    $table = $tableMatch[0];

                    if (
                        ! preg_match(
                            '/<thead\b[^>]*>(.*?)<\/thead>/is',
                            $table,
                            $thead
                        )
                        || ! preg_match_all(
                            '/<th\b[^>]*>(.*?)<\/th>/is',
                            $thead[1],
                            $ths
                        )
                    ) {
                        return $table;
                    }

                    $headers = array_map(
                        static fn ($cell) => strtolower(trim(
                            preg_replace(
                                '/\s+/',
                                ' ',
                                strip_tags($cell)
                            )
                        )),
                        $ths[1]
                    );

                    $referenceIndex = $this->headerIndex(
                        $headers,
                        ['reference']
                    );
                    $documentIndex = $this->headerIndex(
                        $headers,
                        ['document no', 'document']
                    );
                    $descriptionIndex = $this->headerIndex(
                        $headers,
                        ['description', 'narration', 'particulars']
                    );
                    $partyIndex = $this->headerIndex(
                        $headers,
                        ['party']
                    );

                    if ($referenceIndex === null) {
                        return $table;
                    }

                    return preg_replace_callback(
                        '/<tr\b([^>]*)>(.*?)<\/tr>/is',
                        function (array $rowMatch) use (
                            $records,
                            $referenceIndex,
                            $documentIndex,
                            $descriptionIndex,
                            $partyIndex
                        ): string {
                            if (
                                ! preg_match_all(
                                    '/<td\b([^>]*)>(.*?)<\/td>/is',
                                    $rowMatch[2],
                                    $cells,
                                    PREG_SET_ORDER
                                )
                                || ! isset($cells[$referenceIndex])
                            ) {
                                return $rowMatch[0];
                            }

                            $referenceText = strtoupper(trim(
                                strip_tags(
                                    $cells[$referenceIndex][2]
                                )
                            ));

                            if (
                                ! preg_match(
                                    '/\b((?:PV|RV|CAV|SAV|CAR|SAP)-'
                                    .'\d{4}-\d{4,})\b/i',
                                    $referenceText,
                                    $match
                                )
                            ) {
                                return $rowMatch[0];
                            }

                            $record = $records->get(
                                strtoupper($match[1])
                            );

                            if (! $record) {
                                return $rowMatch[0];
                            }

                            $documentNo = trim((string) (
                                $record->transaction_reference ?? ''
                            ));

                            if ($documentNo === '') {
                                $documentNo = trim((string) (
                                    $record->instrument_no ?? ''
                                ));
                            }

                            if ($documentNo === '') {
                                $documentNo = strtoupper($match[1]);
                            }

                            $description = trim((string) (
                                $record->narration ?? ''
                            ));

                            /*
                             * ERP-11.3.43:
                             * Bank/cash journal lines intentionally stay free of
                             * party_id so Party Ledgers do not double-count both
                             * AP/AR and bank legs. The Account/Bank Ledger can
                             * still show the operational counterparty safely by
                             * resolving it from the source cash voucher.
                             */
                            $party = trim((string) (
                                $record->party_name ?? ''
                            ));

                            $body = $rowMatch[2];

                            foreach ([
                                [$documentIndex, $documentNo],
                                [$descriptionIndex, $description],
                                [$partyIndex, $party],
                            ] as [$index, $value]) {
                                if (
                                    $index === null
                                    || $value === ''
                                    || ! isset($cells[$index])
                                ) {
                                    continue;
                                }

                                $old = $cells[$index][0];
                                $new = '<td'.$cells[$index][1].'>'
                                    .e($value)
                                    .'</td>';

                                $body = str_replace(
                                    $old,
                                    $new,
                                    $body
                                );
                            }

                            return '<tr'.$rowMatch[1].'>'
                                .$body
                                .'</tr>';
                        },
                        $table
                    ) ?? $table;
                },
                $html
            ) ?? $html;
        } catch (Throwable $e) {
            report($e);

            return $html;
        }
    }

    private function reconcilePartyControlLedger(
        Request $request,
        string $html
    ): string {
        $mode = $this->requestedReportMode($request);

        if (! in_array($mode, ['vendor', 'customer'], true)) {
            return $html;
        }

        if (
            ! Schema::hasTable('journal_entries')
            || ! Schema::hasTable('journal_lines')
        ) {
            return $html;
        }

        $partyId = $this->selectedPartyId($request, $html);

        if ($partyId <= 0) {
            return $html;
        }

        $accountId = $this->controlAccountId(
            $mode === 'vendor' ? 'VENDOR_AP' : 'CUSTOMER_AR',
            $mode === 'vendor' ? '2110' : '1130'
        );

        if ($accountId <= 0) {
            return $html;
        }

        [$dateFrom, $dateTo] = $this->selectedReportDates(
            $request,
            $html
        );

        if ($dateFrom === null || $dateTo === null) {
            return $html;
        }

        try {
            $journalColumns = Schema::getColumnListing('journal_entries');
            $lineColumns = Schema::getColumnListing('journal_lines');

            foreach ([
                'id',
                'journal_date',
            ] as $required) {
                if (! in_array($required, $journalColumns, true)) {
                    return $html;
                }
            }

            foreach ([
                'journal_entry_id',
                'account_id',
                'party_type',
                'party_id',
                'debit',
                'credit',
            ] as $required) {
                if (! in_array($required, $lineColumns, true)) {
                    return $html;
                }
            }

            $nativePartyType = $mode === 'vendor'
                ? 'vendor'
                : 'customer';

            $base = DB::table('journal_lines as jl')
                ->join(
                    'journal_entries as je',
                    'je.id',
                    '=',
                    'jl.journal_entry_id'
                )
                ->where('jl.account_id', $accountId)
                ->where('jl.party_type', $nativePartyType)
                ->where('jl.party_id', $partyId);

            if (in_array('status', $journalColumns, true)) {
                $base->where('je.status', 'posted');
            }

            $openingRow = (clone $base)
                ->whereDate('je.journal_date', '<', $dateFrom)
                ->selectRaw(
                    'COALESCE(SUM(jl.debit),0) AS debit_total, '
                    .'COALESCE(SUM(jl.credit),0) AS credit_total'
                )
                ->first();

            $openingDebit = round(
                (float) ($openingRow->debit_total ?? 0),
                2
            );
            $openingCredit = round(
                (float) ($openingRow->credit_total ?? 0),
                2
            );

            $opening = $mode === 'vendor'
                ? round($openingCredit - $openingDebit, 2)
                : round($openingDebit - $openingCredit, 2);

            $select = [
                'je.id as journal_entry_id',
                'je.journal_date',
                'jl.debit',
                'jl.credit',
            ];

            foreach ([
                'reference',
                'journal_no',
                'description',
                'source_type',
                'source_id',
            ] as $column) {
                if (in_array($column, $journalColumns, true)) {
                    $select[] = 'je.'.$column.' as '.$column;
                }
            }

            if (in_array('line_no', $lineColumns, true)) {
                $select[] = 'jl.line_no';
            }

            $periodLines = (clone $base)
                ->whereDate('je.journal_date', '>=', $dateFrom)
                ->whereDate('je.journal_date', '<=', $dateTo)
                ->select($select)
                ->orderBy('je.journal_date')
                ->orderBy('je.id');

            if (in_array('line_no', $lineColumns, true)) {
                $periodLines->orderBy('jl.line_no');
            }

            $periodLines = $periodLines->get();

            $totalDebit = round(
                (float) $periodLines->sum(
                    static fn ($line) => (float) $line->debit
                ),
                2
            );
            $totalCredit = round(
                (float) $periodLines->sum(
                    static fn ($line) => (float) $line->credit
                ),
                2
            );

            $closing = $mode === 'vendor'
                ? round(
                    $opening + $totalCredit - $totalDebit,
                    2
                )
                : round(
                    $opening + $totalDebit - $totalCredit,
                    2
                );

            $allowed = [];

            foreach ($periodLines as $line) {
                $reference = strtoupper(trim((string) (
                    $line->reference
                    ?? $line->journal_no
                    ?? ''
                )));

                if ($reference === '') {
                    continue;
                }

                $key = $this->ledgerRowSignature(
                    $reference,
                    (float) $line->debit,
                    (float) $line->credit
                );

                $allowed[$key] = ($allowed[$key] ?? 0) + 1;
            }

            $html = $this->filterPartyLedgerTable(
                $html,
                $allowed,
                $opening,
                $totalDebit,
                $totalCredit,
                $closing,
                $mode
            );

            $html = $this->replaceReportMetric(
                $html,
                'OPENING BALANCE',
                $opening
            );
            $html = $this->replaceReportMetric(
                $html,
                'TOTAL DEBIT',
                $totalDebit
            );
            $html = $this->replaceReportMetric(
                $html,
                'TOTAL CREDIT',
                $totalCredit
            );
            $html = $this->replaceReportMetric(
                $html,
                'CLOSING BALANCE',
                $closing
            );

            return $html;
        } catch (Throwable $e) {
            report($e);

            return $html;
        }
    }

    /**
     * ERP-11.3.66
     *
     * Native General Ledger in the installed ERP renders every posted account
     * movement even when the Account selector contains a specific account.
     * The selector is therefore treated here as an authoritative report scope.
     *
     * Native journal tables remain the accounting source of truth. We do not
     * invent report rows; we restrict the native rendered General Ledger to
     * the selected account and recalculate its opening/debit/credit/closing
     * metrics from posted journal lines for the selected period.
     */
    private function reconcileGeneralLedgerAccountFilter(
        Request $request,
        string $html
    ): string {
        if (! $this->isRequestedGeneralLedger($request, $html)) {
            return $html;
        }

        if (
            ! Schema::hasTable('journal_entries')
            || ! Schema::hasTable('journal_lines')
        ) {
            return $html;
        }

        $accountId = $this->selectedAccountId(
            $request,
            $html
        );

        if ($accountId <= 0) {
            return $html;
        }

        [$dateFrom, $dateTo] = $this->selectedReportDates(
            $request,
            $html
        );

        if ($dateFrom === null || $dateTo === null) {
            return $html;
        }

        try {
            $account = $this->accountIdentity(
                $accountId
            );

            if ($account === null) {
                return $html;
            }

            $entryColumns = Schema::getColumnListing(
                'journal_entries'
            );
            $lineColumns = Schema::getColumnListing(
                'journal_lines'
            );

            foreach ([
                'id',
                'journal_date',
            ] as $required) {
                if (! in_array(
                    $required,
                    $entryColumns,
                    true
                )) {
                    return $html;
                }
            }

            foreach ([
                'journal_entry_id',
                'account_id',
            ] as $required) {
                if (! in_array(
                    $required,
                    $lineColumns,
                    true
                )) {
                    return $html;
                }
            }

            $debitColumn = (
                in_array(
                    'base_debit',
                    $lineColumns,
                    true
                )
                && in_array(
                    'base_credit',
                    $lineColumns,
                    true
                )
            )
                ? 'base_debit'
                : 'debit';

            $creditColumn = $debitColumn === 'base_debit'
                ? 'base_credit'
                : 'credit';

            if (
                ! in_array(
                    $debitColumn,
                    $lineColumns,
                    true
                )
                || ! in_array(
                    $creditColumn,
                    $lineColumns,
                    true
                )
            ) {
                return $html;
            }

            $base = DB::table('journal_lines as jl')
                ->join(
                    'journal_entries as je',
                    'je.id',
                    '=',
                    'jl.journal_entry_id'
                )
                ->where(
                    'jl.account_id',
                    $accountId
                );

            if (in_array(
                'status',
                $entryColumns,
                true
            )) {
                $base->where(
                    'je.status',
                    'posted'
                );
            }

            $openingRow = (clone $base)
                ->whereDate(
                    'je.journal_date',
                    '<',
                    $dateFrom
                )
                ->selectRaw(
                    'COALESCE(SUM(jl.'
                    .$debitColumn
                    .'),0) AS debit_total, '
                    .'COALESCE(SUM(jl.'
                    .$creditColumn
                    .'),0) AS credit_total'
                )
                ->first();

            $periodRow = (clone $base)
                ->whereDate(
                    'je.journal_date',
                    '>=',
                    $dateFrom
                )
                ->whereDate(
                    'je.journal_date',
                    '<=',
                    $dateTo
                )
                ->selectRaw(
                    'COALESCE(SUM(jl.'
                    .$debitColumn
                    .'),0) AS debit_total, '
                    .'COALESCE(SUM(jl.'
                    .$creditColumn
                    .'),0) AS credit_total'
                )
                ->first();

            $openingDebit = round(
                (float) (
                    $openingRow->debit_total
                    ?? 0
                ),
                2
            );
            $openingCredit = round(
                (float) (
                    $openingRow->credit_total
                    ?? 0
                ),
                2
            );

            $openingSigned = round(
                $openingDebit
                - $openingCredit,
                2
            );

            $totalDebit = round(
                (float) (
                    $periodRow->debit_total
                    ?? 0
                ),
                2
            );

            $totalCredit = round(
                (float) (
                    $periodRow->credit_total
                    ?? 0
                ),
                2
            );

            $closingSigned = round(
                $openingSigned
                + $totalDebit
                - $totalCredit,
                2
            );

            /*
             * ERP-11.3.67: keep exact selected-account period totals available
             * to the printable bottom-summary reconciler.
             */
            $request->attributes->set(
                'et_gl_selected_total_debit',
                $totalDebit
            );
            $request->attributes->set(
                'et_gl_selected_total_credit',
                $totalCredit
            );

            $html = $this->filterGeneralLedgerTableToAccount(
                $html,
                (string) $account['code'],
                (string) $account['name'],
                $totalDebit,
                $totalCredit
            );

            $html = $this->replaceReportMetric(
                $html,
                'OPENING BALANCE',
                $openingSigned
            );
            $html = $this->replaceReportMetric(
                $html,
                'TOTAL DEBIT',
                $totalDebit
            );
            $html = $this->replaceReportMetric(
                $html,
                'TOTAL CREDIT',
                $totalCredit
            );
            $html = $this->replaceReportMetric(
                $html,
                'CLOSING / DIFFERENCE',
                $closingSigned
            );

            $html = $this->replaceGeneralLedgerBalanceSides(
                $html,
                $openingSigned,
                $closingSigned
            );

            /*
             * Make the selected scope explicit in the printable report header.
             * This is display-only and prevents a filtered General Ledger from
             * being mistaken for an all-account report.
             */
            $accountLabel = trim(
                (string) $account['code']
                .' - '
                .(string) $account['name']
            );

            $html = preg_replace(
                '/(<[^>]+>\s*General Ledger\s*<\/[^>]+>)/i',
                '$1'
                .'<div class="et-general-ledger-account-scope">'
                .'Account: '
                .e($accountLabel)
                .'</div>',
                $html,
                1
            ) ?? $html;

            return $html;
        } catch (Throwable $e) {
            report($e);

            return $html;
        }
    }

    private function isRequestedGeneralLedger(
        Request $request,
        string $html
    ): bool {
        foreach ([
            'report_type',
            'report',
            'type',
            'ledger_type',
        ] as $key) {
            $value = $request->input($key);

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = strtolower(
                str_replace(
                    ['_', '-'],
                    ' ',
                    trim((string) $value)
                )
            );

            if (
                str_contains(
                    $normalized,
                    'general ledger'
                )
                || $normalized === 'gl'
                || str_contains(
                    $normalized,
                    'gl ledger'
                )
            ) {
                return true;
            }
        }

        foreach ($request->all() as $key => $value) {
            if (
                ! is_scalar($value)
                || (
                    ! str_contains(
                        strtolower((string) $key),
                        'report'
                    )
                    && ! str_contains(
                        strtolower((string) $key),
                        'ledger'
                    )
                )
            ) {
                continue;
            }

            $normalized = strtolower(
                str_replace(
                    ['_', '-'],
                    ' ',
                    trim((string) $value)
                )
            );

            if (
                str_contains(
                    $normalized,
                    'general ledger'
                )
                || str_contains(
                    $normalized,
                    'gl ledger'
                )
            ) {
                return true;
            }
        }

        $plain = strtolower(trim(
            preg_replace(
                '/\s+/',
                ' ',
                strip_tags($html)
            )
        ));

        return (
            str_contains(
                $plain,
                'posted journal movements across selected accounts'
            )
            && str_contains(
                $plain,
                'general ledger'
            )
            && ! str_contains(
                $plain,
                'account ledger statement'
            )
        );
    }

    private function selectedAccountId(
        Request $request,
        string $html
    ): int {
        foreach ([
            'account_id',
            'ledger_account_id',
            'gl_account_id',
            'account',
        ] as $key) {
            $value = $request->input($key);

            if (
                is_scalar($value)
                && (int) $value > 0
            ) {
                return (int) $value;
            }
        }

        foreach ($request->all() as $key => $value) {
            if (
                ! is_scalar($value)
                || ! str_contains(
                    strtolower((string) $key),
                    'account'
                )
            ) {
                continue;
            }

            if ((int) $value > 0) {
                return (int) $value;
            }
        }

        if (
            preg_match(
                '/data-current-account=["\'](\d+)["\']/i',
                $html,
                $match
            )
        ) {
            return (int) $match[1];
        }

        return 0;
    }

    /**
     * @return array{code:string,name:string}|null
     */
    private function accountIdentity(
        int $accountId
    ): ?array {
        if ($accountId <= 0) {
            return null;
        }

        $schema = $this->chart->schema();

        $row = DB::table(
            $schema['table']
        )
            ->where(
                $schema['id'],
                $accountId
            )
            ->first([
                $schema['code'].' as account_code',
                $schema['name'].' as account_name',
            ]);

        if (! $row) {
            return null;
        }

        $code = trim((string) (
            $row->account_code
            ?? ''
        ));

        $name = trim((string) (
            $row->account_name
            ?? ''
        ));

        if ($code === '') {
            return null;
        }

        return [
            'code' => $code,
            'name' => $name,
        ];
    }

    private function filterGeneralLedgerTableToAccount(
        string $html,
        string $accountCode,
        string $accountName,
        float $totalDebit,
        float $totalCredit
    ): string {
        return preg_replace_callback(
            '/<table\b[^>]*>.*?<\/table>/is',
            function (array $tableMatch) use (
                $accountCode,
                $accountName,
                $totalDebit,
                $totalCredit
            ): string {
                $table = $tableMatch[0];

                if (
                    ! preg_match(
                        '/<thead\b[^>]*>(.*?)<\/thead>/is',
                        $table,
                        $thead
                    )
                    || ! preg_match_all(
                        '/<th\b[^>]*>(.*?)<\/th>/is',
                        $thead[1],
                        $ths
                    )
                ) {
                    return $table;
                }

                $headers = array_map(
                    static fn ($cell) => strtolower(trim(
                        preg_replace(
                            '/\s+/',
                            ' ',
                            strip_tags($cell)
                        )
                    )),
                    $ths[1]
                );

                $accountIndex = $this->headerIndex(
                    $headers,
                    ['account']
                );
                $referenceIndex = $this->headerIndex(
                    $headers,
                    ['reference']
                );
                $debitIndex = $this->headerIndex(
                    $headers,
                    ['debit']
                );
                $creditIndex = $this->headerIndex(
                    $headers,
                    ['credit']
                );
                $journalIndex = $this->headerIndex(
                    $headers,
                    ['journal']
                );

                if (
                    $accountIndex === null
                    || $referenceIndex === null
                    || $debitIndex === null
                    || $creditIndex === null
                    || $journalIndex === null
                ) {
                    return $table;
                }

                $table = preg_replace_callback(
                    '/<tbody\b([^>]*)>(.*?)<\/tbody>/is',
                    function (array $bodyMatch) use (
                        $accountIndex,
                        $accountCode,
                        $accountName,
                        $totalDebit,
                        $totalCredit,
                        $debitIndex,
                        $creditIndex
                    ): string {
                        if (
                            ! preg_match_all(
                                '/<tr\b([^>]*)>(.*?)<\/tr>/is',
                                $bodyMatch[2],
                                $rows,
                                PREG_SET_ORDER
                            )
                        ) {
                            return $bodyMatch[0];
                        }

                        $kept = [];

                        foreach ($rows as $row) {
                            $plainRow = strtolower(trim(
                                preg_replace(
                                    '/\s+/',
                                    ' ',
                                    strip_tags($row[2])
                                )
                            ));

                            if (str_contains(
                                $plainRow,
                                'total'
                            )) {
                                $kept[] = $this->rewriteGeneralLedgerTotalRow(
                                    $row[0],
                                    $totalDebit,
                                    $totalCredit,
                                    $debitIndex,
                                    $creditIndex
                                );
                                continue;
                            }

                            if (
                                ! preg_match_all(
                                    '/<td\b([^>]*)>(.*?)<\/td>/is',
                                    $row[2],
                                    $cells,
                                    PREG_SET_ORDER
                                )
                                || ! isset(
                                    $cells[$accountIndex]
                                )
                            ) {
                                $kept[] = $row[0];
                                continue;
                            }

                            $accountText = trim(
                                html_entity_decode(
                                    preg_replace(
                                        '/\s+/',
                                        ' ',
                                        strip_tags(
                                            $cells[$accountIndex][2]
                                        )
                                    ),
                                    ENT_QUOTES | ENT_HTML5,
                                    'UTF-8'
                                )
                            );

                            $matchesCode = preg_match(
                                '/(?:^|[^0-9A-Za-z])'
                                .preg_quote(
                                    $accountCode,
                                    '/'
                                )
                                .'(?:$|[^0-9A-Za-z])/i',
                                $accountText
                            ) === 1;

                            $matchesName = (
                                $accountName !== ''
                                && strcasecmp(
                                    trim($accountText),
                                    trim($accountName)
                                ) === 0
                            );

                            if (
                                ! $matchesCode
                                && ! $matchesName
                            ) {
                                continue;
                            }

                            $kept[] = $row[0];
                        }

                        return '<tbody'
                            .$bodyMatch[1]
                            .'>'
                            .implode('', $kept)
                            .'</tbody>';
                    },
                    $table,
                    1
                ) ?? $table;

                /*
                 * Some native variants put TOTAL in tfoot instead of tbody.
                 */
                $table = preg_replace_callback(
                    '/<tfoot\b([^>]*)>(.*?)<\/tfoot>/is',
                    function (array $footMatch) use (
                        $totalDebit,
                        $totalCredit,
                        $debitIndex,
                        $creditIndex
                    ): string {
                        $body = preg_replace_callback(
                            '/<tr\b[^>]*>.*?<\/tr>/is',
                            fn (array $row): string =>
                                $this->rewriteGeneralLedgerTotalRow(
                                    $row[0],
                                    $totalDebit,
                                    $totalCredit,
                                    $debitIndex,
                                    $creditIndex
                                ),
                            $footMatch[2]
                        ) ?? $footMatch[2];

                        return '<tfoot'
                            .$footMatch[1]
                            .'>'
                            .$body
                            .'</tfoot>';
                    },
                    $table,
                    1
                ) ?? $table;

                return $table;
            },
            $html
        ) ?? $html;
    }

    /**
     * ERP-11.3.67
     *
     * Rewrite the native General Ledger TOTAL row by logical column position,
     * respecting colspan. The previous implementation assumed "last two TDs"
     * and used str_replace against original cells; that is unsafe when native
     * rows collapse descriptive columns or when Debit/Credit have equal text.
     */
    private function rewriteGeneralLedgerTotalRow(
        string $rowHtml,
        float $totalDebit,
        float $totalCredit,
        ?int $debitIndex = null,
        ?int $creditIndex = null
    ): string {
        if (
            ! str_contains(
                strtolower(strip_tags($rowHtml)),
                'total'
            )
            || $debitIndex === null
            || $creditIndex === null
        ) {
            return $rowHtml;
        }

        $logicalColumn = 0;
        $didDebit = false;
        $didCredit = false;

        $rewritten = preg_replace_callback(
            '/<(td|th)\b([^>]*)>(.*?)<\/\1>/is',
            function (array $cell) use (
                &$logicalColumn,
                &$didDebit,
                &$didCredit,
                $debitIndex,
                $creditIndex,
                $totalDebit,
                $totalCredit
            ): string {
                $tag = strtolower(
                    (string) $cell[1]
                );

                $attrs = (string) $cell[2];

                $span = 1;

                if (
                    preg_match(
                        '/\bcolspan\s*=\s*["\']?(\d+)/i',
                        $attrs,
                        $spanMatch
                    )
                ) {
                    $span = max(
                        1,
                        (int) $spanMatch[1]
                    );
                }

                $from = $logicalColumn;
                $to = $logicalColumn + $span - 1;
                $logicalColumn += $span;

                $replacement = null;

                if (
                    ! $didDebit
                    && $debitIndex >= $from
                    && $debitIndex <= $to
                ) {
                    $replacement = number_format(
                        $totalDebit,
                        2
                    );
                    $didDebit = true;
                }

                if (
                    ! $didCredit
                    && $creditIndex >= $from
                    && $creditIndex <= $to
                ) {
                    /*
                     * A native row should not span both accounting amount
                     * columns in one cell. If it does, Debit wins above and
                     * Credit is left for a later cell rather than corrupting
                     * the markup.
                     */
                    if ($replacement === null) {
                        $replacement = number_format(
                            $totalCredit,
                            2
                        );
                        $didCredit = true;
                    }
                }

                if ($replacement === null) {
                    return $cell[0];
                }

                return '<'
                    .$tag
                    .$attrs
                    .'>'
                    .e($replacement)
                    .'</'
                    .$tag
                    .'>';
            },
            $rowHtml
        );

        return is_string($rewritten)
            ? $rewritten
            : $rowHtml;
    }

    /**
     * ERP-11.3.67
     *
     * Correct all printable General Ledger summaries after selected-account
     * filtering. Use callbacks rather than replacement strings such as
     * "$1".$amount: when $amount begins with digits PHP/PCRE can interpret the
     * result as a different numbered back-reference and drop labels/digits.
     */
    private function replaceGeneralLedgerBalanceSides(
        string $html,
        float $openingSigned,
        float $closingSigned
    ): string {
        $closingLabel = abs(
            $closingSigned
        ) < 0.005
            ? 'Balanced'
            : (
                $closingSigned > 0
                    ? 'Debit / positive'
                    : 'Credit / negative'
            );

        $html = preg_replace_callback(
            '/(CLOSING\s*\/\s*DIFFERENCE.{0,600}?)'
            .'(?:Debit\s*\/\s*positive|Credit\s*\/\s*negative|Balanced)/is',
            static function (array $match) use (
                $closingLabel
            ): string {
                return $match[1]
                    .$closingLabel;
            },
            $html,
            1
        ) ?? $html;

        $openingAmount = number_format(
            abs($openingSigned),
            2
        );

        $openingSide = abs(
            $openingSigned
        ) < 0.005
            ? ''
            : (
                $openingSigned > 0
                    ? ' Dr'
                    : ' Cr'
            );

        $closingAmount = number_format(
            abs($closingSigned),
            2
        );

        $closingSide = abs(
            $closingSigned
        ) < 0.005
            ? ''
            : (
                $closingSigned > 0
                    ? ' Dr'
                    : ' Cr'
            );

        /*
         * Printable bottom summary — "Opening: PKR ..."
         */
        $html = preg_replace_callback(
            '/(Opening\s*:\s*PKR\s*)'
            .'[\d,]+(?:\.\d+)?'
            .'(?:\s+(?:Dr|Cr))?/i',
            static function (array $match) use (
                $openingAmount,
                $openingSide
            ): string {
                return $match[1]
                    .$openingAmount
                    .$openingSide;
            },
            $html,
            1
        ) ?? $html;

        /*
         * Printable bottom summary — Total Debit / Total Credit. These were
         * previously left at the native all-account totals even after rows
         * were correctly filtered to one account.
         */
        $debitAmount = number_format(
            (float) request()->attributes->get(
                'et_gl_selected_total_debit',
                0
            ),
            2
        );

        $creditAmount = number_format(
            (float) request()->attributes->get(
                'et_gl_selected_total_credit',
                0
            ),
            2
        );

        $html = preg_replace_callback(
            '/(Total\s*Debit\s*:\s*PKR\s*)'
            .'[\d,]+(?:\.\d+)?/i',
            static function (array $match) use (
                $debitAmount
            ): string {
                return $match[1]
                    .$debitAmount;
            },
            $html,
            1
        ) ?? $html;

        $html = preg_replace_callback(
            '/(Total\s*Credit\s*:\s*PKR\s*)'
            .'[\d,]+(?:\.\d+)?/i',
            static function (array $match) use (
                $creditAmount
            ): string {
                return $match[1]
                    .$creditAmount;
            },
            $html,
            1
        ) ?? $html;

        $html = preg_replace_callback(
            '/(Closing\s*\/?\s*Difference\s*:\s*PKR\s*)'
            .'[\d,]+(?:\.\d+)?'
            .'(?:\s+(?:Dr|Cr))?/i',
            static function (array $match) use (
                $closingAmount,
                $closingSide
            ): string {
                return $match[1]
                    .$closingAmount
                    .$closingSide;
            },
            $html,
            1
        ) ?? $html;

        return $html;
    }

    private function selectedPartyId(
        Request $request,
        string $html
    ): int {
        foreach ([
            'party_id',
            'vendor_id',
            'supplier_id',
            'customer_id',
            'client_id',
        ] as $key) {
            $value = $request->input($key);

            if (
                is_scalar($value)
                && (int) $value > 0
            ) {
                return (int) $value;
            }
        }

        if (
            preg_match(
                '/data-current-party=["\'](\d+)["\']/i',
                $html,
                $match
            )
        ) {
            return (int) $match[1];
        }

        return 0;
    }

    private function selectedReportDates(
        Request $request,
        string $html
    ): array {
        $from = null;
        $to = null;

        foreach ([
            'date_from',
            'from_date',
            'start_date',
            'date_start',
        ] as $key) {
            $value = $request->input($key);

            if (
                is_scalar($value)
                && preg_match(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    (string) $value
                )
            ) {
                $from = (string) $value;
                break;
            }
        }

        foreach ([
            'date_to',
            'to_date',
            'end_date',
            'date_end',
            'as_of',
            'as_of_date',
        ] as $key) {
            $value = $request->input($key);

            if (
                is_scalar($value)
                && preg_match(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    (string) $value
                )
            ) {
                $to = (string) $value;
                break;
            }
        }

        /*
         * Compatibility fallback: inspect report-like date input names only.
         */
        if ($from === null || $to === null) {
            foreach ($request->all() as $key => $value) {
                if (
                    ! is_scalar($value)
                    || ! preg_match(
                        '/^\d{4}-\d{2}-\d{2}$/',
                        (string) $value
                    )
                ) {
                    continue;
                }

                $name = strtolower((string) $key);

                if (
                    $from === null
                    && (
                        str_contains($name, 'from')
                        || str_contains($name, 'start')
                    )
                ) {
                    $from = (string) $value;
                }

                if (
                    $to === null
                    && (
                        str_contains($name, 'to')
                        || str_contains($name, 'end')
                        || str_contains($name, 'as_of')
                    )
                ) {
                    $to = (string) $value;
                }
            }
        }

        /*
         * Last fallback for the already-rendered replacement form.
         */
        if ($from === null) {
            if (
                preg_match(
                    '/<label>Date From<\/label>\s*'
                    .'<(?:input)\b[^>]*value=["\']'
                    .'(\d{4}-\d{2}-\d{2})["\']/is',
                    $html,
                    $match
                )
            ) {
                $from = $match[1];
            }
        }

        if ($to === null) {
            if (
                preg_match(
                    '/<label>Date To \/ As Of<\/label>\s*'
                    .'<(?:input)\b[^>]*value=["\']'
                    .'(\d{4}-\d{2}-\d{2})["\']/is',
                    $html,
                    $match
                )
            ) {
                $to = $match[1];
            }
        }

        return [$from, $to];
    }

    private function ledgerRowSignature(
        string $reference,
        float $debit,
        float $credit
    ): string {
        return strtoupper(trim($reference))
            .'|'
            .number_format(round($debit, 2), 2, '.', '')
            .'|'
            .number_format(round($credit, 2), 2, '.', '');
    }

    private function filterPartyLedgerTable(
        string $html,
        array $allowed,
        float $opening,
        float $totalDebit,
        float $totalCredit,
        float $closing,
        string $mode
    ): string {
        return preg_replace_callback(
            '/<table\b[^>]*>.*?<\/table>/is',
            function (array $tableMatch) use (
                &$allowed,
                $opening,
                $totalDebit,
                $totalCredit,
                $closing,
                $mode
            ): string {
                $table = $tableMatch[0];

                if (
                    ! preg_match(
                        '/<thead\b[^>]*>(.*?)<\/thead>/is',
                        $table,
                        $thead
                    )
                    || ! preg_match_all(
                        '/<th\b[^>]*>(.*?)<\/th>/is',
                        $thead[1],
                        $ths
                    )
                ) {
                    return $table;
                }

                $headers = array_map(
                    static fn ($cell) => strtolower(trim(
                        preg_replace('/\s+/', ' ', strip_tags($cell))
                    )),
                    $ths[1]
                );

                $typeIndex = $this->headerIndex(
                    $headers,
                    ['type']
                );
                $referenceIndex = $this->headerIndex(
                    $headers,
                    ['reference']
                );
                $debitIndex = $this->headerIndex(
                    $headers,
                    ['debit']
                );
                $creditIndex = $this->headerIndex(
                    $headers,
                    ['credit']
                );
                $balanceIndex = $this->headerIndex(
                    $headers,
                    ['balance']
                );

                if (
                    $referenceIndex === null
                    || $debitIndex === null
                    || $creditIndex === null
                    || $balanceIndex === null
                ) {
                    return $table;
                }

                $running = $opening;

                $table = preg_replace_callback(
                    '/<tbody\b([^>]*)>(.*?)<\/tbody>/is',
                    function (array $bodyMatch) use (
                        &$allowed,
                        &$running,
                        $typeIndex,
                        $referenceIndex,
                        $debitIndex,
                        $creditIndex,
                        $balanceIndex,
                        $mode
                    ): string {
                        $rows = [];

                        if (
                            ! preg_match_all(
                                '/<tr\b([^>]*)>(.*?)<\/tr>/is',
                                $bodyMatch[2],
                                $rowMatches,
                                PREG_SET_ORDER
                            )
                        ) {
                            return $bodyMatch[0];
                        }

                        foreach ($rowMatches as $rowMatch) {
                            if (
                                ! preg_match_all(
                                    '/<td\b([^>]*)>(.*?)<\/td>/is',
                                    $rowMatch[2],
                                    $cells,
                                    PREG_SET_ORDER
                                )
                            ) {
                                $rows[] = $rowMatch[0];
                                continue;
                            }

                            $typeText = $typeIndex !== null
                                && isset($cells[$typeIndex])
                                ? strtoupper(trim(strip_tags(
                                    $cells[$typeIndex][2]
                                )))
                                : '';

                            if ($typeText === 'BAL') {
                                $body = $rowMatch[2];

                                $body = $this->replaceLedgerCell(
                                    $body,
                                    $cells,
                                    $balanceIndex,
                                    number_format($running, 2)
                                );

                                $rows[] = '<tr'.$rowMatch[1].'>'
                                    .$body
                                    .'</tr>';

                                continue;
                            }

                            if (
                                ! isset(
                                    $cells[$referenceIndex],
                                    $cells[$debitIndex],
                                    $cells[$creditIndex],
                                    $cells[$balanceIndex]
                                )
                            ) {
                                $rows[] = $rowMatch[0];
                                continue;
                            }

                            $reference = strtoupper(trim(
                                strip_tags(
                                    $cells[$referenceIndex][2]
                                )
                            ));

                            if (
                                $reference === ''
                                || $reference === '—'
                                || $reference === '-'
                            ) {
                                $rows[] = $rowMatch[0];
                                continue;
                            }

                            $debit = $this->numericCell(
                                $cells[$debitIndex][2]
                            );
                            $credit = $this->numericCell(
                                $cells[$creditIndex][2]
                            );

                            $key = $this->ledgerRowSignature(
                                $reference,
                                $debit,
                                $credit
                            );

                            if (($allowed[$key] ?? 0) <= 0) {
                                continue;
                            }

                            $allowed[$key]--;

                            $running = $mode === 'vendor'
                                ? round(
                                    $running + $credit - $debit,
                                    2
                                )
                                : round(
                                    $running + $debit - $credit,
                                    2
                                );

                            $body = $this->replaceLedgerCell(
                                $rowMatch[2],
                                $cells,
                                $balanceIndex,
                                number_format($running, 2)
                            );

                            $rows[] = '<tr'.$rowMatch[1].'>'
                                .$body
                                .'</tr>';
                        }

                        return '<tbody'.$bodyMatch[1].'>'
                            .implode('', $rows)
                            .'</tbody>';
                    },
                    $table,
                    1
                ) ?? $table;

                /*
                 * Replace the explicit TOTAL row in tbody/tfoot if present.
                 */
                $table = preg_replace_callback(
                    '/<tr\b([^>]*)>(.*?)<\/tr>/is',
                    function (array $rowMatch) use (
                        $debitIndex,
                        $creditIndex,
                        $balanceIndex,
                        $totalDebit,
                        $totalCredit,
                        $closing
                    ): string {
                        $plain = strtolower(trim(
                            preg_replace(
                                '/\s+/',
                                ' ',
                                strip_tags($rowMatch[2])
                            )
                        ));

                        if (
                            ! str_contains($plain, 'total')
                            || ! preg_match_all(
                                '/<td\b([^>]*)>(.*?)<\/td>/is',
                                $rowMatch[2],
                                $cells,
                                PREG_SET_ORDER
                            )
                        ) {
                            return $rowMatch[0];
                        }

                        $body = $rowMatch[2];

                        /*
                         * Native TOTAL rows collapse the first descriptive
                         * columns with colspan, so their td array is shorter
                         * than a normal journal row. When the normal header
                         * indexes do not exist, the last three cells are
                         * authoritatively Debit / Credit / Balance.
                         */
                        /*
                         * TOTAL rows are structurally different from normal
                         * transaction rows because the descriptive columns are
                         * collapsed with colspan. Do NOT mix normal header
                         * indexes with TOTAL-row cell indexes. The native
                         * statement contract is stable: the final three cells
                         * are Debit, Credit and Balance.
                         */
                        $totalDebitIndex = max(0, count($cells) - 3);
                        $totalCreditIndex = max(0, count($cells) - 2);
                        $totalBalanceIndex = max(0, count($cells) - 1);

                        $body = $this->replaceLedgerCell(
                            $body,
                            $cells,
                            $totalDebitIndex,
                            number_format($totalDebit, 2)
                        );
                        $body = $this->replaceLedgerCell(
                            $body,
                            $cells,
                            $totalCreditIndex,
                            number_format($totalCredit, 2)
                        );
                        $body = $this->replaceLedgerCell(
                            $body,
                            $cells,
                            $totalBalanceIndex,
                            number_format($closing, 2)
                        );

                        return '<tr'.$rowMatch[1].'>'
                            .$body
                            .'</tr>';
                    },
                    $table
                ) ?? $table;

                return $table;
            },
            $html
        ) ?? $html;
    }

    private function numericCell(string $html): float
    {
        $plain = trim(html_entity_decode(
            strip_tags($html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));

        if (
            $plain === ''
            || $plain === '—'
            || $plain === '-'
            || ! preg_match(
                '/-?[\d,]+(?:\.\d+)?/',
                $plain,
                $match
            )
        ) {
            return 0.0;
        }

        return round(
            (float) str_replace(',', '', $match[0]),
            2
        );
    }

    private function replaceLedgerCell(
        string $body,
        array $cells,
        ?int $index,
        string $value
    ): string {
        if (
            $index === null
            || ! isset($cells[$index])
        ) {
            return $body;
        }

        $old = $cells[$index][0];
        $new = '<td'.$cells[$index][1].'>'
            .e($value)
            .'</td>';

        return str_replace($old, $new, $body);
    }

    private function replaceReportMetric(
        string $html,
        string $label,
        float $value
    ): string {
        $amount = number_format(abs($value), 2);
        $labelPattern = preg_quote($label, '/');

        /*
         * Top KPI cards use PKR. Constrain the scan to a compact block after the
         * specific label so one card cannot consume the next card.
         */
        $html = preg_replace_callback(
            '/('
            .$labelPattern
            .'.{0,500}?PKR\s*)-?[\d,]+(?:\.\d+)?/is',
            static function (array $match) use ($amount): string {
                return $match[1].$amount;
            },
            $html,
            1
        ) ?? $html;

        /*
         * The printable statement's bottom summary uses the same label without
         * the PKR prefix. Replace the first compact numeric value after that
         * label outside the already-replaced top card.
         */
        $html = preg_replace_callback(
            '/(<[^>]+>[^<]*'
            .$labelPattern
            .'[^<]*<\/[^>]+>.{0,250}?)(<[^>]+>)(-?[\d,]+(?:\.\d+)?)(<\/[^>]+>)/is',
            static function (array $match) use ($amount): string {
                return $match[1]
                    .$match[2]
                    .$amount
                    .$match[4];
            },
            $html,
            1
        ) ?? $html;

        return $html;
    }

    private function enrichSupplierCostingRows(string $html): string
    {
        if (
            ! Schema::hasTable('supplier_costings')
            || ! Schema::hasTable('supplier_costing_lines')
        ) {
            return $html;
        }

        preg_match_all(
            '/\bSC-\d{4}-\d{6,}\b/i',
            strip_tags($html),
            $matches
        );

        $numbers = array_values(array_unique(array_map(
            static fn ($x) => strtoupper(trim((string) $x)),
            $matches[0] ?? []
        )));

        if ($numbers === []) {
            return $html;
        }

        try {
            $columns = Schema::getColumnListing('supplier_costings');
            $wanted = array_values(array_intersect([
                'id',
                'costing_no',
                'supplier_invoice_no',
                'supplier_reference',
                'remarks',
                'service_type',
                'supplier_name',
            ], $columns));

            if (
                ! in_array('id', $wanted, true)
                || ! in_array('costing_no', $wanted, true)
            ) {
                return $html;
            }

            $records = DB::table('supplier_costings')
                ->whereIn('costing_no', $numbers)
                ->get($wanted);

            if ($records->isEmpty()) {
                return $html;
            }

            $ids = $records->pluck('id')->map(
                static fn ($id) => (int) $id
            )->all();

            $lineColumns = Schema::getColumnListing('supplier_costing_lines');
            $lineWanted = array_values(array_intersect([
                'supplier_costing_id',
                'line_no',
                'service_type',
                'description',
                'passenger_name',
                'supplier_service_ref',
            ], $lineColumns));

            $linesByCosting = collect();

            if (
                in_array('supplier_costing_id', $lineWanted, true)
                && $ids !== []
            ) {
                $lineQuery = DB::table('supplier_costing_lines')
                    ->whereIn('supplier_costing_id', $ids);

                if (in_array('line_no', $lineWanted, true)) {
                    $lineQuery->orderBy('line_no');
                }

                $linesByCosting = $lineQuery
                    ->get($lineWanted)
                    ->groupBy(
                        static fn ($line) =>
                            (int) ($line->supplier_costing_id ?? 0)
                    );
            }

            $records = $records->keyBy(
                static fn ($row) =>
                    strtoupper((string) $row->costing_no)
            );

            return preg_replace_callback(
                '/<table\b[^>]*>.*?<\/table>/is',
                function (array $tableMatch) use ($records, $linesByCosting): string {
                    $table = $tableMatch[0];

                    if (
                        ! preg_match(
                            '/<thead\b[^>]*>(.*?)<\/thead>/is',
                            $table,
                            $thead
                        )
                        || ! preg_match_all(
                            '/<th\b[^>]*>(.*?)<\/th>/is',
                            $thead[1],
                            $ths
                        )
                    ) {
                        return $table;
                    }

                    $headers = array_map(
                        static fn ($cell) => strtolower(trim(
                            preg_replace('/\s+/', ' ', strip_tags($cell))
                        )),
                        $ths[1]
                    );

                    $referenceIndex = $this->headerIndex(
                        $headers,
                        ['reference']
                    );
                    $documentIndex = $this->headerIndex(
                        $headers,
                        ['document no', 'document']
                    );
                    $descriptionIndex = $this->headerIndex(
                        $headers,
                        ['description', 'narration', 'particulars']
                    );

                    if ($referenceIndex === null) {
                        return $table;
                    }

                    return preg_replace_callback(
                        '/<tr\b([^>]*)>(.*?)<\/tr>/is',
                        function (array $rowMatch) use (
                            $records,
                            $linesByCosting,
                            $referenceIndex,
                            $documentIndex,
                            $descriptionIndex
                        ): string {
                            if (
                                ! preg_match_all(
                                    '/<td\b([^>]*)>(.*?)<\/td>/is',
                                    $rowMatch[2],
                                    $cells,
                                    PREG_SET_ORDER
                                )
                                || ! isset($cells[$referenceIndex])
                            ) {
                                return $rowMatch[0];
                            }

                            $referenceText = strtoupper(trim(strip_tags(
                                $cells[$referenceIndex][2]
                            )));

                            if (
                                ! preg_match(
                                    '/\b(SC-\d{4}-\d{6,})\b/i',
                                    $referenceText,
                                    $match
                                )
                            ) {
                                return $rowMatch[0];
                            }

                            $record = $records->get(strtoupper($match[1]));

                            if (! $record) {
                                return $rowMatch[0];
                            }

                            /*
                             * Reference remains the internal Supplier Costing number.
                             * Document No. prefers the real supplier-side reference,
                             * then supplier invoice number, then the internal number.
                             */
                            $documentNo = trim((string) (
                                $record->supplier_reference ?? ''
                            ));

                            if ($documentNo === '') {
                                $documentNo = trim((string) (
                                    $record->supplier_invoice_no ?? ''
                                ));
                            }

                            if ($documentNo === '') {
                                $documentNo = strtoupper($match[1]);
                            }

                            /*
                             * Description authority:
                             * 1. Explicit Supplier Costing remarks/narration.
                             * 2. Actual line service + description entered by user.
                             * 3. Header service type as a final non-generic fallback.
                             */
                            $description = trim((string) (
                                $record->remarks ?? ''
                            ));

                            if ($description === '') {
                                $lineDescriptions = [];

                                foreach (
                                    $linesByCosting->get(
                                        (int) $record->id,
                                        collect()
                                    ) as $line
                                ) {
                                    $service = trim((string) (
                                        $line->service_type ?? ''
                                    ));
                                    $detail = trim((string) (
                                        $line->description ?? ''
                                    ));

                                    $piece = trim(
                                        $service
                                        .($service !== '' && $detail !== ''
                                            ? ' · '
                                            : '')
                                        .$detail
                                    );

                                    if (
                                        $piece !== ''
                                        && ! in_array(
                                            $piece,
                                            $lineDescriptions,
                                            true
                                        )
                                    ) {
                                        $lineDescriptions[] = $piece;
                                    }
                                }

                                if ($lineDescriptions !== []) {
                                    $visible = array_slice(
                                        $lineDescriptions,
                                        0,
                                        3
                                    );
                                    $description = implode(
                                        ' | ',
                                        $visible
                                    );

                                    $extra = count($lineDescriptions)
                                        - count($visible);

                                    if ($extra > 0) {
                                        $description .= ' | +'
                                            .$extra
                                            .' more';
                                    }
                                }
                            }

                            if ($description === '') {
                                $description = trim((string) (
                                    $record->service_type
                                    ?? 'Supplier Costing'
                                ));
                            }

                            $body = $rowMatch[2];

                            foreach ([
                                [$documentIndex, $documentNo],
                                [$descriptionIndex, $description],
                            ] as [$index, $value]) {
                                if (
                                    $index === null
                                    || $value === ''
                                    || ! isset($cells[$index])
                                ) {
                                    continue;
                                }

                                $old = $cells[$index][0];
                                $new = '<td'.$cells[$index][1].'>'
                                    .e($value)
                                    .'</td>';

                                $body = str_replace(
                                    $old,
                                    $new,
                                    $body
                                );
                            }

                            return '<tr'.$rowMatch[1].'>'
                                .$body
                                .'</tr>';
                        },
                        $table
                    ) ?? $table;
                },
                $html
            ) ?? $html;
        } catch (Throwable $e) {
            report($e);

            return $html;
        }
    }

    private function formatLedgerAmounts(string $html): string
    {
        $mode = $this->renderedReportMode($html);

        return preg_replace_callback(
            '/<table\b[^>]*>.*?<\/table>/is',
            function (array $tableMatch) use ($mode): string {
                $table = $tableMatch[0];

                if (
                    ! preg_match(
                        '/<thead\b[^>]*>(.*?)<\/thead>/is',
                        $table,
                        $thead
                    )
                    || ! preg_match_all(
                        '/<th\b[^>]*>(.*?)<\/th>/is',
                        $thead[1],
                        $ths
                    )
                ) {
                    return $table;
                }

                $headers = array_map(
                    static fn ($cell) => strtolower(trim(
                        preg_replace('/\s+/', ' ', strip_tags($cell))
                    )),
                    $ths[1]
                );

                $debitIndex = $this->headerIndex(
                    $headers,
                    ['debit']
                );
                $creditIndex = $this->headerIndex(
                    $headers,
                    ['credit']
                );
                $balanceIndex = $this->headerIndex(
                    $headers,
                    ['balance']
                );

                if (
                    $debitIndex === null
                    && $creditIndex === null
                    && $balanceIndex === null
                ) {
                    return $table;
                }

                return preg_replace_callback(
                    '/<tr\b([^>]*)>(.*?)<\/tr>/is',
                    function (array $rowMatch) use (
                        $debitIndex,
                        $creditIndex,
                        $balanceIndex,
                        $mode
                    ): string {
                        if (
                            ! preg_match_all(
                                '/<td\b([^>]*)>(.*?)<\/td>/is',
                                $rowMatch[2],
                                $cells,
                                PREG_SET_ORDER
                            )
                        ) {
                            return $rowMatch[0];
                        }

                        $body = $rowMatch[2];

                        foreach ([
                            [$debitIndex, 'debit'],
                            [$creditIndex, 'credit'],
                            [$balanceIndex, 'balance'],
                        ] as [$index, $kind]) {
                            if (
                                $index === null
                                || ! isset($cells[$index])
                            ) {
                                continue;
                            }

                            $plain = trim(html_entity_decode(
                                strip_tags($cells[$index][2]),
                                ENT_QUOTES | ENT_HTML5,
                                'UTF-8'
                            ));

                            if (
                                $plain === ''
                                || $plain === '—'
                                || $plain === '-'
                                || ! preg_match(
                                    '/-?[\d,]+(?:\.\d+)?/',
                                    $plain,
                                    $numberMatch
                                )
                            ) {
                                continue;
                            }

                            $number = (float) str_replace(
                                ',',
                                '',
                                $numberMatch[0]
                            );

                            if (abs($number) < 0.005) {
                                $formatted = '0.00';
                            } else {
                                $formatted = number_format(
                                    abs($number),
                                    2
                                );

                                if ($kind === 'debit') {
                                    $formatted .= ' Dr';
                                } elseif ($kind === 'credit') {
                                    $formatted .= ' Cr';
                                } else {
                                    /*
                                     * Vendor Ledgers are credit-normal in the
                                     * native ReportController. A positive
                                     * running vendor balance therefore means Cr.
                                     * Customer/Account/Party retain the native
                                     * debit-positive convention.
                                     */
                                    if ($mode === 'vendor') {
                                        $formatted .= $number >= 0
                                            ? ' Cr'
                                            : ' Dr';
                                    } else {
                                        $formatted .= $number >= 0
                                            ? ' Dr'
                                            : ' Cr';
                                    }
                                }
                            }

                            $replacement = '<td'
                                .$cells[$index][1]
                                .' class="et-rf-balance">'
                                .e($formatted)
                                .'</td>';

                            $body = str_replace(
                                $cells[$index][0],
                                $replacement,
                                $body
                            );
                        }

                        return '<tr'.$rowMatch[1].'>'
                            .$body
                            .'</tr>';
                    },
                    $table
                ) ?? $table;
            },
            $html
        ) ?? $html;
    }

    private function renderedReportMode(string $html): string
    {
        if (
            preg_match(
                '/<select\b[^>]*data-et-report-type=["\']1["\'][^>]*>.*?<\/select>/is',
                $html,
                $select
            )
        ) {
            return $this->reportMode(
                $this->selectedText($select[0])
            );
        }

        $plain = strtolower(trim(
            preg_replace('/\s+/', ' ', strip_tags($html))
        ));

        if (str_contains($plain, 'vendor ledger statement')) {
            return 'vendor';
        }

        if (str_contains($plain, 'customer ledger statement')) {
            return 'customer';
        }

        if (str_contains($plain, 'party ledger statement')) {
            return 'party';
        }

        if (
            str_contains($plain, 'account ledger statement')
            || str_contains($plain, 'general ledger statement')
        ) {
            return 'account';
        }

        return 'general';
    }

    private function presentTrialBalanceStatement(
        Request $request,
        string $html
    ): string {
        if (! $this->isTrialBalanceReport($request, $html)) {
            return $html;
        }

        [$dateFrom, $dateTo] = $this->selectedReportDates(
            $request,
            $html
        );

        /*
         * Native Trial Balance in the installed ERP calculates the selected
         * journal population correctly, but its printable header has been
         * rendering the As-Of date on BOTH sides of "Period". Keep native
         * calculations untouched and synchronize only the visible statement
         * period with the user's actual filter.
         */
        if ($dateFrom !== null && $dateTo !== null) {
            $period = $dateFrom.' — '.$dateTo;

            $html = preg_replace_callback(
                '/(>\s*Period\s*<.{0,450}?)(\d{4}-\d{2}-\d{2})'
                .'\s*(?:—|-|&mdash;|&#8212;)\s*'
                .'(\d{4}-\d{2}-\d{2})/is',
                static function (array $match) use ($period): string {
                    return $match[1].$period;
                },
                $html,
                1
            ) ?? $html;

            // ERP-11.3.45: align the Opening Balance caption to period start.
            $html = preg_replace_callback(
                '/(OPENING\s*BALANCE.{0,600}?)(?:Debit|Credit)'
                .'\s+as\s+of\s+\d{4}-\d{2}-\d{2}/is',
                static function (array $match) use ($dateFrom): string {
                    return $match[1].'Opening as of '.$dateFrom;
                },
                $html,
                1
            ) ?? $html;
        }

        $difference = $this->renderedKpiAmount(
            $html,
            'CLOSING / DIFFERENCE'
        );

        if (
            $difference !== null
            && abs($difference) < 0.005
        ) {
            /*
             * A zero Trial Balance difference has no debit/credit side. The
             * correct user-facing state is simply Balanced.
             */
            $html = preg_replace_callback(
                '/(CLOSING\s*\/\s*DIFFERENCE.{0,600}?)'
                .'(?:Debit\s*\/\s*positive|Credit\s*\/\s*negative)/is',
                static function (array $match): string {
                    return $match[1].'Balanced';
                },
                $html,
                1
            ) ?? $html;

            /*
             * Native printable summary may append "Dr" or "Cr" to zero.
             * Strip that meaningless side only from Closing/Difference.
             */
            $html = preg_replace(
                '/(Closing\s*\/?\s*Difference\s*:\s*'
                .'PKR\s*0(?:\.0+)?)(?:\s+Dr|\s+Cr)/i',
                '$1',
                $html,
                1
            ) ?? $html;
        }

        return $html;
    }

    private function isTrialBalanceReport(
        Request $request,
        string $html
    ): bool {
        foreach ([
            'report_type',
            'report',
            'type',
            'ledger_type',
        ] as $key) {
            $value = $request->input($key);

            if (
                is_scalar($value)
                && str_contains(
                    strtolower((string) $value),
                    'trial'
                )
            ) {
                return true;
            }
        }

        return str_contains(
            strtolower(trim(
                preg_replace('/\s+/', ' ', strip_tags($html))
            )),
            'trial balance'
        );
    }

    private function presentAccountLedgerStatement(
        Request $request,
        string $html
    ): string {
        if (
            $this->requestedReportMode($request) !== 'account'
            && $this->renderedReportMode($html) !== 'account'
        ) {
            return $html;
        }

        if (
            ! str_contains(
                strtolower(strip_tags($html)),
                'account ledger statement'
            )
        ) {
            return $html;
        }

        $opening = $this->renderedKpiAmount(
            $html,
            'OPENING BALANCE'
        ) ?? 0.0;
        $totalDebit = $this->renderedKpiAmount(
            $html,
            'TOTAL DEBIT'
        ) ?? 0.0;
        $totalCredit = $this->renderedKpiAmount(
            $html,
            'TOTAL CREDIT'
        ) ?? 0.0;

        /*
         * Native Account Ledger KPI captions expose the opening side:
         * "Debit as of ..." / "Credit as of ...".
         */
        $openingSigned = $this->accountOpeningIsCredit($html)
            ? -abs($opening)
            : abs($opening);

        $closingSigned = round(
            $openingSigned + $totalDebit - $totalCredit,
            2
        );

        $html = preg_replace_callback(
            '/<table\b([^>]*)>.*?<\/table>/is',
            function (array $tableMatch) use (
                $openingSigned,
                $closingSigned
            ): string {
                $table = $tableMatch[0];

                if (
                    ! preg_match(
                        '/<thead\b[^>]*>(.*?)<\/thead>/is',
                        $table,
                        $thead
                    )
                    || ! preg_match_all(
                        '/<th\b([^>]*)>(.*?)<\/th>/is',
                        $thead[1],
                        $ths,
                        PREG_SET_ORDER
                    )
                ) {
                    return $table;
                }

                $headers = array_map(
                    static fn (array $cell): string =>
                        strtolower(trim(
                            preg_replace(
                                '/\s+/',
                                ' ',
                                strip_tags($cell[2])
                            )
                        )),
                    $ths
                );

                $dateIndex = $this->headerIndex(
                    $headers,
                    ['date']
                );
                $referenceIndex = $this->headerIndex(
                    $headers,
                    ['reference']
                );
                $descriptionIndex = $this->headerIndex(
                    $headers,
                    ['description', 'narration', 'particulars']
                );
                $partyIndex = $this->headerIndex(
                    $headers,
                    ['party']
                );
                $branchIndex = $this->headerIndex(
                    $headers,
                    ['branch']
                );
                $debitIndex = $this->headerIndex(
                    $headers,
                    ['debit']
                );
                $creditIndex = $this->headerIndex(
                    $headers,
                    ['credit']
                );
                $balanceIndex = $this->headerIndex(
                    $headers,
                    ['running balance', 'balance']
                );
                $journalIndex = $this->headerIndex(
                    $headers,
                    ['journal']
                );

                if (
                    $dateIndex === null
                    || $referenceIndex === null
                    || $descriptionIndex === null
                    || $debitIndex === null
                    || $creditIndex === null
                    || $balanceIndex === null
                ) {
                    return $table;
                }

                $table = preg_replace(
                    '/^<table\b/i',
                    '<table class="et-account-ledger-table"',
                    $table,
                    1
                ) ?? $table;

                $running = $openingSigned;

                return preg_replace_callback(
                    '/<tr\b([^>]*)>(.*?)<\/tr>/is',
                    function (array $rowMatch) use (
                        &$running,
                        $closingSigned,
                        $dateIndex,
                        $referenceIndex,
                        $descriptionIndex,
                        $partyIndex,
                        $branchIndex,
                        $debitIndex,
                        $creditIndex,
                        $balanceIndex,
                        $journalIndex
                    ): string {
                        if (
                            ! preg_match_all(
                                '/<(th|td)\b([^>]*)>(.*?)<\/\1>/is',
                                $rowMatch[2],
                                $cells,
                                PREG_SET_ORDER
                            )
                        ) {
                            return $rowMatch[0];
                        }

                        $isHeader = strtolower(
                            (string) ($cells[0][1] ?? '')
                        ) === 'th';

                        $plainRow = strtolower(trim(
                            preg_replace(
                                '/\s+/',
                                ' ',
                                strip_tags($rowMatch[2])
                            )
                        ));

                        $isTotal = str_contains(
                            $plainRow,
                            'total'
                        );

                        /*
                         * Header and normal transaction rows use the native
                         * column count. TOTAL rows can use colspan and are left
                         * structurally intact except for their closing balance.
                         */
                        if ($isHeader) {
                            $labels = [
                                $dateIndex => 'DATE',
                                $referenceIndex => 'REFERENCE',
                                $descriptionIndex => 'DESCRIPTION',
                                $partyIndex => 'PARTY',
                                $debitIndex => 'DEBIT (PKR)',
                                $creditIndex => 'CREDIT (PKR)',
                                $balanceIndex => 'BALANCE (PKR)',
                                $journalIndex => 'JOURNAL REF.',
                            ];

                            $body = $rowMatch[2];

                            foreach ($cells as $index => $cell) {
                                if (
                                    $branchIndex !== null
                                    && $index === $branchIndex
                                ) {
                                    $body = str_replace(
                                        $cell[0],
                                        '',
                                        $body
                                    );
                                    continue;
                                }

                                if (! array_key_exists($index, $labels)) {
                                    continue;
                                }

                                $class = $this->accountLedgerCellClass(
                                    $index,
                                    $debitIndex,
                                    $creditIndex,
                                    $balanceIndex,
                                    $journalIndex
                                );

                                $new = '<th'
                                    .$cell[2]
                                    .' class="'.$class.'">'
                                    .e((string) $labels[$index])
                                    .'</th>';

                                $body = str_replace(
                                    $cell[0],
                                    $new,
                                    $body
                                );
                            }

                            return '<tr'.$rowMatch[1].'>'
                                .$body
                                .'</tr>';
                        }

                        if ($isTotal) {
                            $body = $rowMatch[2];

                            if (
                                preg_match_all(
                                    '/<(td|th)\b([^>]*)>(.*?)<\/\1>/is',
                                    $body,
                                    $totalCells,
                                    PREG_SET_ORDER
                                )
                                && count($totalCells) >= 2
                            ) {
                                $last = $totalCells[
                                    count($totalCells) - 1
                                ];
                                $new = '<'.$last[1]
                                    .$last[2]
                                    .' class="et-al-num">'
                                    .e(
                                        $this->formatSignedAccountBalance(
                                            $closingSigned
                                        )
                                    )
                                    .'</'.$last[1].'>';

                                $position = strrpos(
                                    $body,
                                    $last[0]
                                );

                                if ($position !== false) {
                                    $body = substr_replace(
                                        $body,
                                        $new,
                                        $position,
                                        strlen($last[0])
                                    );
                                }
                            }

                            return '<tr'.$rowMatch[1]
                                .' class="et-al-total">'
                                .$body
                                .'</tr>';
                        }

                        if (
                            ! isset(
                                $cells[$debitIndex],
                                $cells[$creditIndex],
                                $cells[$balanceIndex]
                            )
                        ) {
                            return $rowMatch[0];
                        }

                        $debit = $this->numericCell(
                            $cells[$debitIndex][3]
                        );
                        $credit = $this->numericCell(
                            $cells[$creditIndex][3]
                        );

                        $running = round(
                            $running + $debit - $credit,
                            2
                        );

                        $body = $rowMatch[2];

                        /*
                         * Replace cells from right to left so removing Branch
                         * and changing content cannot disturb later matches.
                         */
                        for (
                            $index = count($cells) - 1;
                            $index >= 0;
                            $index--
                        ) {
                            $cell = $cells[$index];

                            if (
                                $branchIndex !== null
                                && $index === $branchIndex
                            ) {
                                $position = strrpos(
                                    $body,
                                    $cell[0]
                                );

                                if ($position !== false) {
                                    $body = substr_replace(
                                        $body,
                                        '',
                                        $position,
                                        strlen($cell[0])
                                    );
                                }

                                continue;
                            }

                            $value = $cell[3];
                            $class = $this->accountLedgerCellClass(
                                $index,
                                $debitIndex,
                                $creditIndex,
                                $balanceIndex,
                                $journalIndex
                            );

                            if ($index === $balanceIndex) {
                                $value = e(
                                    $this->formatSignedAccountBalance(
                                        $running
                                    )
                                );
                            }

                            $new = '<td'
                                .$cell[2]
                                .' class="'.$class.'">'
                                .$value
                                .'</td>';

                            $position = strrpos(
                                $body,
                                $cell[0]
                            );

                            if ($position !== false) {
                                $body = substr_replace(
                                    $body,
                                    $new,
                                    $position,
                                    strlen($cell[0])
                                );
                            }
                        }

                        return '<tr'.$rowMatch[1]
                            .' class="et-al-row">'
                            .$body
                            .'</tr>';
                    },
                    $table
                ) ?? $table;
            },
            $html
        ) ?? $html;

        if (
            ! str_contains(
                $html,
                'data-et-account-ledger-style="ERP-11.3.42"'
            )
        ) {
            $style = <<<'HTML'
<style data-et-account-ledger-style="ERP-11.3.42">
.et-account-ledger-table{
    width:100%!important;
    border-collapse:separate!important;
    border-spacing:0!important;
    table-layout:fixed!important;
    margin-top:12px!important;
    border:1px solid #d8e1ec!important;
    border-radius:8px!important;
    overflow:hidden!important;
    background:#fff!important;
}
.et-account-ledger-table thead th{
    background:#0b376f!important;
    color:#fff!important;
    border:0!important;
    padding:9px 8px!important;
    font-size:9.5px!important;
    font-weight:900!important;
    letter-spacing:.035em!important;
    line-height:1.15!important;
    vertical-align:middle!important;
}
.et-account-ledger-table td{
    padding:9px 8px!important;
    border:0!important;
    border-bottom:1px solid #e5ebf2!important;
    color:#26384e!important;
    font-size:10px!important;
    line-height:1.35!important;
    vertical-align:top!important;
    background:#fff!important;
    overflow-wrap:anywhere!important;
}
.et-account-ledger-table .et-al-row:nth-child(even) td{
    background:#fbfcfe!important;
}
.et-account-ledger-table th:nth-child(1),
.et-account-ledger-table td:nth-child(1){width:10%!important}
.et-account-ledger-table th:nth-child(2),
.et-account-ledger-table td:nth-child(2){width:13%!important}
.et-account-ledger-table th:nth-child(3),
.et-account-ledger-table td:nth-child(3){width:29%!important}
.et-account-ledger-table th:nth-child(4),
.et-account-ledger-table td:nth-child(4){width:11%!important}
.et-account-ledger-table th:nth-child(5),
.et-account-ledger-table td:nth-child(5){width:10%!important}
.et-account-ledger-table th:nth-child(6),
.et-account-ledger-table td:nth-child(6){width:10%!important}
.et-account-ledger-table th:nth-child(7),
.et-account-ledger-table td:nth-child(7){width:11%!important}
.et-account-ledger-table th:nth-child(8),
.et-account-ledger-table td:nth-child(8){width:12%!important}
.et-account-ledger-table .et-al-num{
    text-align:right!important;
    white-space:nowrap!important;
    font-variant-numeric:tabular-nums!important;
    font-weight:750!important;
}
.et-account-ledger-table .et-al-journal{
    font-size:9px!important;
    white-space:normal!important;
}
.et-account-ledger-table .et-al-journal a,
.et-account-ledger-table td:nth-child(2) a{
    color:#075dcc!important;
    font-weight:800!important;
    text-decoration:none!important;
}
.et-account-ledger-table .et-al-total td,
.et-account-ledger-table .et-al-total th{
    background:#eaf2fc!important;
    border-top:1px solid #c8d7ea!important;
    border-bottom:0!important;
    font-weight:900!important;
}
@media print{
    @page{size:A4 landscape;margin:8mm}
    .et-account-ledger-table{
        border-radius:0!important;
        margin-top:8px!important;
    }
    .et-account-ledger-table thead th{
        -webkit-print-color-adjust:exact!important;
        print-color-adjust:exact!important;
        background:#0b376f!important;
        color:#fff!important;
        padding:6px 5px!important;
        font-size:8px!important;
    }
    .et-account-ledger-table td{
        padding:6px 5px!important;
        font-size:8.5px!important;
    }
    .et-account-ledger-table .et-al-journal{
        display:none!important;
    }
    .et-account-ledger-table th:nth-child(3),
    .et-account-ledger-table td:nth-child(3){
        width:34%!important;
    }
}
</style>
HTML;

            if (stripos($html, '</head>') !== false) {
                $html = preg_replace(
                    '/<\/head>/i',
                    $style."\n</head>",
                    $html,
                    1
                ) ?? $html;
            } else {
                $html = $style.$html;
            }
        }

        return $html;
    }

    private function accountOpeningIsCredit(string $html): bool
    {
        if (
            preg_match(
                '/OPENING\s*BALANCE.{0,700}?'
                .'(Debit|Credit)\s+as\s+of/is',
                $html,
                $match
            )
        ) {
            return strtolower($match[1]) === 'credit';
        }

        return false;
    }

    private function formatSignedAccountBalance(float $signed): string
    {
        if (abs($signed) < 0.005) {
            return '0.00';
        }

        return number_format(abs($signed), 2)
            .($signed > 0 ? ' Dr' : ' Cr');
    }

    private function accountLedgerCellClass(
        int $index,
        ?int $debitIndex,
        ?int $creditIndex,
        ?int $balanceIndex,
        ?int $journalIndex
    ): string {
        if (
            in_array(
                $index,
                [
                    $debitIndex,
                    $creditIndex,
                    $balanceIndex,
                ],
                true
            )
        ) {
            return 'et-al-num';
        }

        if (
            $journalIndex !== null
            && $index === $journalIndex
        ) {
            return 'et-al-journal';
        }

        return 'et-al-text';
    }

    private function finalizePartyLedgerTotalBalance(
        Request $request,
        string $html
    ): string {
        $mode = $this->requestedReportMode($request);

        if (! in_array($mode, ['vendor', 'customer'], true)) {
            return $html;
        }

        /*
         * Read authoritative totals from the already-correct KPI cards. This
         * final pass exists only because the native TOTAL row has colspan-based
         * markup and cannot safely share normal transaction-row indexes.
         */
        $debit = $this->renderedKpiAmount($html, 'TOTAL DEBIT');
        $credit = $this->renderedKpiAmount($html, 'TOTAL CREDIT');
        $closing = $this->renderedKpiAmount($html, 'CLOSING BALANCE');

        if (
            $debit === null
            || $credit === null
            || $closing === null
        ) {
            return $html;
        }

        $debitText = abs($debit) < 0.005
            ? '0.00'
            : number_format(abs($debit), 2).' Dr';

        $creditText = abs($credit) < 0.005
            ? '0.00'
            : number_format(abs($credit), 2).' Cr';

        $closingText = number_format(abs($closing), 2);

        if (abs($closing) >= 0.005) {
            if ($mode === 'vendor') {
                $closingText .= $closing >= 0
                    ? ' Cr'
                    : ' Dr';
            } else {
                $closingText .= $closing >= 0
                    ? ' Dr'
                    : ' Cr';
            }
        }

        return preg_replace_callback(
            '/<tr\b([^>]*)>(.*?)<\/tr>/is',
            static function (array $rowMatch) use (
                $debitText,
                $creditText,
                $closingText
            ): string {
                $plain = strtolower(trim(
                    preg_replace(
                        '/\s+/',
                        ' ',
                        strip_tags($rowMatch[2])
                    )
                ));

                if (! str_contains($plain, 'total')) {
                    return $rowMatch[0];
                }

                if (
                    ! preg_match_all(
                        '/<(td|th)\b([^>]*)>(.*?)<\/\1>/is',
                        $rowMatch[2],
                        $cells,
                        PREG_SET_ORDER
                    )
                    || count($cells) < 4
                ) {
                    return $rowMatch[0];
                }

                /*
                 * Regardless of how many descriptive cells the native row uses,
                 * its final three cells are the accounting totals.
                 */
                $count = count($cells);
                $values = [
                    $count - 3 => $debitText,
                    $count - 2 => $creditText,
                    $count - 1 => $closingText,
                ];

                $body = $rowMatch[2];

                /*
                 * Replace from the end so duplicate HTML snippets cannot make a
                 * prior replacement shift the intended later cell.
                 */
                foreach (array_reverse(array_keys($values)) as $index) {
                    $cell = $cells[$index];
                    $old = $cell[0];
                    $new = '<'.$cell[1].$cell[2].'>'
                        .e($values[$index])
                        .'</'.$cell[1].'>';

                    $position = strrpos($body, $old);

                    if ($position === false) {
                        continue;
                    }

                    $body = substr_replace(
                        $body,
                        $new,
                        $position,
                        strlen($old)
                    );
                }

                return '<tr'.$rowMatch[1].'>'
                    .$body
                    .'</tr>';
            },
            $html
        ) ?? $html;
    }

    private function renderedKpiAmount(
        string $html,
        string $label
    ): ?float {
        $labelPattern = preg_quote($label, '/');

        if (
            ! preg_match(
                '/'.$labelPattern
                .'.{0,500}?PKR\s*'
                .'([\d,]+(?:\.\d+)?)/is',
                $html,
                $match
            )
        ) {
            return null;
        }

        return round(
            (float) str_replace(',', '', $match[1]),
            2
        );
    }

    private function headerIndex(array $headers,array $needles): ?int
    {
        foreach($headers as $i=>$h)foreach($needles as $n)if($h===$n||str_contains($h,$n))return $i;return null;
    }
}
