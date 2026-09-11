@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Accounting Journal Diagnostic')
@section($layoutMeta['content_section'] ?? 'content')
<style>
.ajd{max-width:1500px;margin:0 auto;color:#17243a}.ajd *{box-sizing:border-box}
.ajd-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:15px}
.ajd-kicker{font-size:10px;font-weight:900;color:#0a63d8;text-transform:uppercase;letter-spacing:.04em}
.ajd h1{font-size:26px;margin:4px 0}.ajd-sub{font-size:12px;color:#6f8095}
.ajd-safe{padding:9px 12px;border:1px solid #bfe2cf;background:#effbf4;border-radius:8px;color:#176143;font-size:11px;font-weight:800}
.ajd-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.ajd-card{background:#fff;border:1px solid #dce5ef;border-radius:10px;overflow:hidden;margin-bottom:14px}
.ajd-card h3{font-size:13px;margin:0;padding:11px 13px;border-bottom:1px solid #e8edf3}
.ajd-body{padding:12px 13px}.ajd-kv{display:grid;grid-template-columns:190px 1fr;gap:7px;font-size:10.5px;margin-bottom:5px}
.ajd-kv b{color:#5a6b80}.ajd-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:10px;background:#f7f9fc;border:1px solid #e4e9f0;border-radius:6px;padding:7px;overflow:auto;white-space:pre-wrap;word-break:break-word}
.ajd-table{width:100%;border-collapse:collapse;table-layout:auto}.ajd-table th,.ajd-table td{padding:7px 8px;border-bottom:1px solid #edf1f5;text-align:left;vertical-align:top;font-size:9.5px;overflow-wrap:anywhere}
.ajd-table th{background:#f8fafc;color:#53647a;text-transform:uppercase;font-size:8.5px;letter-spacing:.02em}
.ajd-pass{color:#167a50;font-weight:900}.ajd-fail{color:#b4434c;font-weight:900}.ajd-warn{color:#9a6810;font-weight:900}
.ajd-banner{padding:12px 13px;border-radius:9px;margin-bottom:14px;font-size:11px;border:1px solid #dce5ef;background:#fff}
.ajd-banner.ready{border-color:#bfe2cf;background:#f1fbf5}.ajd-banner.wait{border-color:#f1d6a8;background:#fff9ee}
@media(max-width:900px){.ajd-grid{grid-template-columns:1fr}.ajd-kv{grid-template-columns:1fr}}
</style>

<div class="ajd" data-et-accounting-diagnostic="ERP-11.3.21">
  <div class="ajd-head">
    <div>
      <div class="ajd-kicker">System Diagnostic</div>
      <h1>Native Accounting Journal Integration</h1>
      <div class="ajd-sub">Read-only inspection for Receipt / Payment → Vendor / Customer Ledger integration.</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <a href="{{ route('system.erp-repair.cash-voucher-native-journals') }}" style="display:inline-flex;padding:9px 12px;border-radius:8px;background:#0a63d8;color:#fff;text-decoration:none;font-size:11px;font-weight:800">Open Repair</a>
      <div class="ajd-safe">READ ONLY · DATABASE WRITES = 0</div>
    </div>
  </div>

  <div class="ajd-banner {{ $bridgeReady ? 'ready' : 'wait' }}">
    <strong>Bridge readiness:</strong>
    @if($bridgeReady)
      <span class="ajd-pass">STRUCTURE DISCOVERED</span> — send this page to engineering before enabling any native-journal backfill.
    @else
      <span class="ajd-warn">MORE SCHEMA INFORMATION REQUIRED</span> — no financial rows have been changed.
    @endif
  </div>

  <div class="ajd-grid">
    <section class="ajd-card">
      <h3>Native Journal Header</h3>
      <div class="ajd-body">
        <div class="ajd-kv"><b>Resolved table</b><span class="{{ $header['table'] ? 'ajd-pass' : 'ajd-fail' }}">{{ $header['table'] ?: 'NOT FOUND' }}</span></div>
        <div class="ajd-kv"><b>ID column</b><span>{{ $header['id'] ?? '—' }}</span></div>
        <div class="ajd-kv"><b>Number column</b><span>{{ $header['number'] ?? '—' }}</span></div>
        <div class="ajd-kv"><b>Type column</b><span>{{ $header['type'] ?? '—' }}</span></div>
        <div class="ajd-kv"><b>Date column</b><span>{{ $header['date'] ?? '—' }}</span></div>
        <div class="ajd-kv"><b>Status column</b><span>{{ $header['status'] ?? '—' }}</span></div>
        <div class="ajd-kv"><b>Reference columns</b><span>{{ implode(', ',array_filter([$header['reference']??null,$header['reference_type']??null,$header['reference_id']??null])) ?: '—' }}</span></div>
        <div class="ajd-code">{{ implode(', ', $header['columns'] ?? []) }}</div>
      </div>
    </section>

    <section class="ajd-card">
      <h3>Native Journal Lines</h3>
      <div class="ajd-body">
        <div class="ajd-kv"><b>Resolved table</b><span class="{{ $lines['table'] ? 'ajd-pass' : 'ajd-fail' }}">{{ $lines['table'] ?: 'NOT FOUND' }}</span></div>
        <div class="ajd-kv"><b>Journal FK</b><span>{{ $lines['journal_id'] ?? '—' }}</span></div>
        <div class="ajd-kv"><b>Account</b><span>{{ $lines['account_id'] ?? $lines['account_code'] ?? '—' }}</span></div>
        <div class="ajd-kv"><b>Party</b><span>{{ implode(' / ',array_filter([$lines['party_type']??null,$lines['party_id']??null])) ?: '—' }}</span></div>
        <div class="ajd-kv"><b>Debit / Credit</b><span>{{ ($lines['debit']??'—').' / '.($lines['credit']??'—') }}</span></div>
        <div class="ajd-code">{{ implode(', ', $lines['columns'] ?? []) }}</div>
      </div>
    </section>
  </div>

  <section class="ajd-card">
    <h3>Native Accounting Routes</h3>
    <table class="ajd-table">
      <thead><tr><th>Name</th><th>Exists</th><th>Method</th><th>URI</th><th>Action</th></tr></thead>
      <tbody>
        @foreach($routes as $r)
          <tr><td>{{ $r['name'] }}</td><td class="{{ $r['exists']?'ajd-pass':'ajd-fail' }}">{{ $r['exists']?'YES':'NO' }}</td><td>{{ $r['methods'] ?: '—' }}</td><td>{{ $r['uri'] ?: '—' }}</td><td>{{ $r['action'] ?: '—' }}</td></tr>
        @endforeach
      </tbody>
    </table>
  </section>

  <section class="ajd-card">
    <h3>Controlled Chart Accounts</h3>
    <table class="ajd-table">
      <thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Type</th><th>Subtype</th><th>Control</th><th>Posting</th><th>Status</th></tr></thead>
      <tbody>
        @forelse($accounts as $a)
          <tr><td>{{ $a['id']??'—' }}</td><td><strong>{{ $a['code']??'—' }}</strong></td><td>{{ $a['name']??'—' }}</td><td>{{ $a['type']??'—' }}</td><td>{{ $a['subtype']??'—' }}</td><td>{{ $a['control_type']??'—' }}</td><td>{{ $a['posting']??'—' }}</td><td>{{ $a['status']??($a['active']??'—') }}</td></tr>
        @empty
          <tr><td colspan="8">No account snapshot available.</td></tr>
        @endforelse
      </tbody>
    </table>
  </section>

  <section class="ajd-card">
    <h3>Posted Cash Vouchers → Native Journal Match</h3>
    <table class="ajd-table">
      <thead><tr><th>ID</th><th>Voucher</th><th>Type</th><th>Party</th><th>Amount</th><th>Bank</th><th>Posting Ref</th><th>Native Journal?</th><th>Native ID / No.</th><th>Match Basis</th></tr></thead>
      <tbody>
        @forelse($cashVouchers as $v)
          <tr>
            <td>{{ $v['id'] }}</td><td><strong>{{ $v['voucher_no'] }}</strong></td><td>{{ $v['voucher_type'] }}</td>
            <td>{{ $v['party_type'] }} #{{ $v['party_id'] ?: '—' }}</td>
            <td>{{ $v['currency_code'] }} {{ number_format($v['amount'],2) }}</td><td>{{ $v['cash_bank_account_code'] }}</td>
            <td>{{ $v['posting_reference'] ?: '—' }}</td>
            <td class="{{ $v['native_journal_found']?'ajd-pass':'ajd-fail' }}">{{ $v['native_journal_found']?'YES':'NO' }}</td>
            <td>{{ $v['native_journal_id'] ?: '—' }} / {{ $v['native_journal_number'] ?: '—' }}</td><td>{{ $v['match_basis'] }}</td>
          </tr>
        @empty
          <tr><td colspan="10">No posted cash vouchers found.</td></tr>
        @endforelse
      </tbody>
    </table>
  </section>

  <section class="ajd-card">
    <h3>Recent Native Journal Headers (sanitized)</h3>
    @if($journalHeaders)
      <table class="ajd-table">
        <thead><tr>@foreach(array_keys($journalHeaders[0]) as $key)<th>{{ $key }}</th>@endforeach</tr></thead>
        <tbody>@foreach($journalHeaders as $row)<tr>@foreach($row as $value)<td>{{ is_scalar($value)||$value===null ? ($value??'—') : '[complex]' }}</td>@endforeach</tr>@endforeach</tbody>
      </table>
    @else
      <div class="ajd-body">No native journal header snapshot available.</div>
    @endif
  </section>

  <section class="ajd-card">
    <h3>Recent Native Journal Lines (sanitized)</h3>
    @if($journalLines)
      <table class="ajd-table">
        <thead><tr>@foreach(array_keys($journalLines[0]) as $key)<th>{{ $key }}</th>@endforeach</tr></thead>
        <tbody>@foreach($journalLines as $row)<tr>@foreach($row as $value)<td>{{ is_scalar($value)||$value===null ? ($value??'—') : '[complex]' }}</td>@endforeach</tr>@endforeach</tbody>
      </table>
    @else
      <div class="ajd-body">No native journal line snapshot available.</div>
    @endif
  </section>

  <section class="ajd-card">
    <h3>Native Accounting Classes</h3>
    <table class="ajd-table">
      <thead><tr><th>Class</th><th>Exists</th><th>Public Methods</th></tr></thead>
      <tbody>@foreach($classes as $c)<tr><td>{{ $c['class'] }}</td><td class="{{ $c['exists']?'ajd-pass':'ajd-fail' }}">{{ $c['exists']?'YES':'NO' }}</td><td>{{ $c['public_methods'] ? implode(', ',$c['public_methods']) : '—' }}</td></tr>@endforeach</tbody>
    </table>
  </section>
</div>
@endsection
