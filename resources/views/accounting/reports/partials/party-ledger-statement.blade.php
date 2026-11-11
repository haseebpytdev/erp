@php
    $party = $report['party'];
    $branch = $report['header_branch'] ?? null;
    $companyName = $company->legal_name ?: $company->name;
    $brandName = ($company->legal_name && $company->legal_name !== $company->name) ? $company->name : null;
    $headerBranchName = $branch?->name ?: $report['branch_label'];
    $headerAddress = $branch?->address ?: $company->address;
    $headerPhone = $branch?->phone ?: $company->phone;
    $headerEmail = $branch?->email ?: $company->email;
    $headerWebsite = $company->website ?? null;
    $contactBits = array_values(array_filter([
        $headerPhone,
        $headerEmail,
        $headerWebsite ? preg_replace('~^https?://~i','',$headerWebsite) : null,
        $company->tax_registration_no ? 'Tax/Reg: '.$company->tax_registration_no : null,
    ]));
@endphp
<style>
.party-ledger-paper{background:#fff;border:1px solid #cfd8e5;border-radius:8px;padding:18px;color:#101828;width:100%;max-width:1380px;margin:0 auto;box-shadow:0 6px 20px rgba(16,24,40,.05)}
.pl-company{display:grid;grid-template-columns:72px 1fr 190px;gap:16px;align-items:center;padding:0 2px 10px;border-bottom:2px solid #0b2c5f}
.pl-logo{width:62px;height:62px;border:1px solid #cdd8e8;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;color:#0b2c5f;background:#f7f9fc;letter-spacing:.4px}
.pl-company-main{text-align:center}.pl-company-name{font-size:25px;font-weight:900;letter-spacing:.25px;color:#0c2148;line-height:1.05}.pl-brand{font-size:12px;font-weight:700;margin-top:2px}.pl-address{font-size:11px;margin-top:4px;color:#344054;line-height:1.35}.pl-contact{font-size:10.5px;margin-top:4px;color:#344054}.pl-contact span+span:before{content:' | ';color:#98a2b3;margin:0 5px}
.pl-company-side{text-align:right;font-size:10px;color:#475467;line-height:1.55}.pl-company-side strong{color:#0c2148}
.pl-title{text-align:center;font-size:22px;font-weight:900;color:#101828;margin:10px 0 12px;letter-spacing:.25px}
.pl-meta{display:grid;grid-template-columns:1.15fr .9fr 1fr;gap:18px;padding:0 6px 10px;border-bottom:1px solid #98a2b3;font-size:10.5px}.pl-meta-row{display:grid;grid-template-columns:95px 10px 1fr;gap:2px;margin:4px 0;line-height:1.3}.pl-meta b{color:#0c2148}.pl-meta-right .pl-meta-row{grid-template-columns:88px 10px 1fr}
.pl-table-wrap{margin-top:12px;overflow:visible}.pl-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:10px}.pl-table th{background:#072d62;color:#fff;border:1px solid #284d7c;padding:7px 5px;text-align:center;font-weight:800;white-space:nowrap}.pl-table td{border:1px solid #d4dce7;padding:6px 5px;vertical-align:top;line-height:1.28;overflow-wrap:anywhere}.pl-table th:nth-child(1),.pl-table td:nth-child(1){width:5%}.pl-table th:nth-child(2),.pl-table td:nth-child(2){width:9%}.pl-table th:nth-child(3),.pl-table td:nth-child(3){width:13%}.pl-table th:nth-child(4),.pl-table td:nth-child(4){width:13%}.pl-table th:nth-child(5),.pl-table td:nth-child(5){width:28%}.pl-table th:nth-child(6),.pl-table td:nth-child(6){width:10.5%}.pl-table th:nth-child(7),.pl-table td:nth-child(7){width:10.5%}.pl-table th:nth-child(8),.pl-table td:nth-child(8){width:11%}
.pl-table td:nth-last-child(-n+3){text-align:right;white-space:nowrap}.pl-table td:nth-child(1),.pl-table td:nth-child(2){text-align:center;white-space:nowrap}.pl-table td:nth-child(3),.pl-table td:nth-child(4){white-space:nowrap}.pl-table .pl-opening td{background:#fafbfc}.pl-reference{color:#0b63ce;text-decoration:underline;text-underline-offset:2px;font-weight:700}.pl-total td{background:#e8f0fb;font-weight:900;color:#0c2148}.pl-summary{margin-top:12px;border:1px solid #0b2c5f;border-radius:5px;display:grid;grid-template-columns:repeat(4,1fr);padding:8px 0}.pl-summary-item{text-align:center;padding:1px 8px;border-right:1px solid #a7b4c7}.pl-summary-item:last-child{border-right:none}.pl-summary-label{font-size:10px;color:#475467}.pl-summary-value{font-size:15px;font-weight:900;color:#101828;margin-top:3px}.pl-footer{display:flex;justify-content:space-between;align-items:end;gap:18px;margin-top:13px;font-size:9.5px;color:#475467;font-style:italic}.pl-footer-note{max-width:70%}.pl-sign{font-style:normal;white-space:nowrap}.pl-sign-line{display:inline-block;width:150px;border-bottom:1px solid #667085;margin-left:6px}
@media(max-width:850px){.pl-company{grid-template-columns:60px 1fr}.pl-company-side{display:none}.pl-meta{grid-template-columns:1fr}.pl-logo{width:54px;height:54px}.pl-company-name{font-size:20px}.pl-table-wrap{overflow:auto}.pl-table{min-width:980px}.pl-summary{grid-template-columns:1fr 1fr}.pl-summary-item{border-bottom:1px solid #d0d5dd}.pl-summary-item:nth-child(2){border-right:none}}
@media print{
  .party-ledger-paper{border:0!important;border-radius:0!important;box-shadow:none!important;padding:0!important;max-width:none!important;width:100%!important}
  .pl-company{grid-template-columns:48px 1fr;gap:8px;padding-bottom:5px;position:relative}
  .pl-logo{width:44px;height:44px;font-size:14px;border-radius:7px}
  .pl-company-main{text-align:center;padding-right:48px}
  .pl-company-name{font-size:15pt;line-height:1.05}.pl-brand{font-size:7pt}.pl-address{font-size:6.6pt;margin-top:2px}.pl-contact{font-size:6.2pt;margin-top:2px}
  .pl-company-side{display:none!important}
  .pl-title{font-size:12.5pt;margin:5px 0 6px}
  .pl-meta{grid-template-columns:1fr 1fr 1fr;gap:7px;padding:0 2px 5px;font-size:6.4pt}
  .pl-meta-row{grid-template-columns:54px 5px 1fr;margin:1.5px 0;gap:1px;line-height:1.18}
  .pl-meta-right .pl-meta-row{grid-template-columns:54px 5px 1fr}
  .pl-table-wrap{margin-top:6px;overflow:visible}
  .pl-table{font-size:5.75pt;table-layout:fixed;width:100%}
  .pl-table thead{display:table-header-group}.pl-table tfoot{display:table-row-group}.pl-table tr{page-break-inside:avoid}
  .pl-table th{padding:4px 2px;font-size:5.6pt;line-height:1.05;white-space:normal}
  .pl-table td{padding:3px 2px;line-height:1.08;overflow-wrap:anywhere}
  .pl-table th:nth-child(1),.pl-table td:nth-child(1){width:4.5%}
  .pl-table th:nth-child(2),.pl-table td:nth-child(2){width:8.5%}
  .pl-table th:nth-child(3),.pl-table td:nth-child(3){width:13.5%}
  .pl-table th:nth-child(4),.pl-table td:nth-child(4){width:12.5%}
  .pl-table th:nth-child(5),.pl-table td:nth-child(5){width:25%}
  .pl-table th:nth-child(6),.pl-table td:nth-child(6){width:11.5%}
  .pl-table th:nth-child(7),.pl-table td:nth-child(7){width:11.5%}
  .pl-table th:nth-child(8),.pl-table td:nth-child(8){width:13%}
  .pl-table td:nth-child(3),.pl-table td:nth-child(4){white-space:normal}
  .pl-table td:nth-last-child(-n+3){white-space:nowrap;font-variant-numeric:tabular-nums}
  .pl-reference{color:#111!important;text-decoration:none!important}
  .pl-summary{margin-top:6px;padding:5px 0;page-break-inside:avoid}
  .pl-summary-label{font-size:6.2pt}.pl-summary-value{font-size:8.8pt;margin-top:2px}
  .pl-footer{margin-top:6px;font-size:5.9pt;page-break-inside:avoid}.pl-footer-note{max-width:68%}.pl-sign-line{width:95px}
}
</style>
<div class="party-ledger-paper">
    @include('partials.document-header',['company'=>$company,'branch'=>$branch,'documentTitle'=>strtoupper($report['party_label'] ?? 'Party').' Ledger Statement','documentDate'=>$report['generated_at']])    <div class="pl-meta">
        <div>
            <div class="pl-meta-row"><b>{{ $report['party_label'] ?? 'Party' }} Name</b><span>:</span><span>{{ $party->display_name }}</span></div>
            <div class="pl-meta-row"><b>{{ $report['party_label'] ?? 'Party' }} Code</b><span>:</span><span>{{ $party->code }}</span></div>
            <div class="pl-meta-row"><b>Branch</b><span>:</span><span>{{ $report['branch_label'] }}</span></div>
            <div class="pl-meta-row"><b>Currency</b><span>:</span><span>{{ $report['currency'] }}</span></div>
        </div>
        <div>
            <div class="pl-meta-row"><b>Date From</b><span>:</span><span>{{ \Carbon\Carbon::parse($report['from'])->format('d M Y') }}</span></div>
            <div class="pl-meta-row"><b>Date To</b><span>:</span><span>{{ \Carbon\Carbon::parse($report['to'])->format('d M Y') }}</span></div>
            @if(!empty($report['control_account']))<div class="pl-meta-row"><b>Control A/C</b><span>:</span><span>{{ $report['control_account']->code }} · {{ $report['control_account']->name }}</span></div>@endif
        </div>
        <div class="pl-meta-right">
            <div class="pl-meta-row"><b>Generated On</b><span>:</span><span>{{ $report['generated_at']->format('d M Y H:i') }}</span></div>
            <div class="pl-meta-row"><b>Generated By</b><span>:</span><span>{{ $report['generated_by'] }}</span></div>
            <div class="pl-meta-row"><b>Page No</b><span>:</span><span>Page 1</span></div>
        </div>
    </div>

    <div class="pl-table-wrap">
        <table class="pl-table">
            <thead><tr>@foreach($report['headers'] as $header)<th>{{ $header }}@if(in_array($header,['Debit','Credit','Balance'],true)) ({{ $report['currency'] }})@endif</th>@endforeach</tr></thead>
            <tbody>
            @forelse($report['rows'] as $row)
                <tr class="{{ !empty($row['is_opening']) ? 'pl-opening' : '' }}">
                @foreach($row['cells'] as $i=>$cell)
                    <td>
                    @if(($report['headers'][$i]??'')==='Reference' && !empty($row['source_url']))
                        <a class="pl-reference" href="{{ $row['source_url'] }}">{{ $cell }}</a>
                    @else
                        {{ $cell }}
                    @endif
                    </td>
                @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($report['headers']) }}" style="text-align:center;padding:24px;color:#667085">No posted movements match the selected filters.</td></tr>
            @endforelse
            </tbody>
            <tfoot><tr class="pl-total">@foreach($report['totals'] as $cell)<td>{{ $cell }}</td>@endforeach</tr></tfoot>
        </table>
    </div>

    <div class="pl-summary">
        <div class="pl-summary-item"><div class="pl-summary-label">Opening Balance</div><div class="pl-summary-value">{{ number_format(abs((float)$report['metrics']['opening']),2) }}</div></div>
        <div class="pl-summary-item"><div class="pl-summary-label">Total Debit</div><div class="pl-summary-value">{{ number_format((float)$report['metrics']['debit'],2) }}</div></div>
        <div class="pl-summary-item"><div class="pl-summary-label">Total Credit</div><div class="pl-summary-value">{{ number_format((float)$report['metrics']['credit'],2) }}</div></div>
        <div class="pl-summary-item"><div class="pl-summary-label">Closing Balance</div><div class="pl-summary-value">{{ number_format(abs((float)$report['metrics']['closing']),2) }}</div></div>
    </div>

    <div class="pl-footer">
        <div class="pl-footer-note">References are clickable in the web view and open the original source document; if a source screen is not available yet, the exact posted journal is opened.</div>
        <div class="pl-sign">Prepared By:<span class="pl-sign-line"></span></div>
    </div>
</div>
