@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Cash Voucher Native Journal Repair')
@section($layoutMeta['content_section'] ?? 'content')
<style>
.cjr{max-width:1400px;margin:0 auto;color:#17243a}.cjr *{box-sizing:border-box}
.cjr-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:15px}
.cjr-kicker{font-size:10px;font-weight:900;color:#0a63d8;text-transform:uppercase}.cjr h1{font-size:25px;margin:4px 0}.cjr-sub{font-size:12px;color:#6f8095}
.cjr-card{background:#fff;border:1px solid #dce5ef;border-radius:10px;overflow:hidden;margin-bottom:14px}.cjr-card h3{font-size:13px;margin:0;padding:11px 13px;border-bottom:1px solid #e8edf3}.cjr-body{padding:13px}
.cjr-table{width:100%;border-collapse:collapse}.cjr-table th,.cjr-table td{padding:8px;border-bottom:1px solid #edf1f5;text-align:left;font-size:10px}.cjr-table th{background:#f8fafc;text-transform:uppercase;color:#53647a;font-size:9px}
.ok{color:#15794f;font-weight:900}.missing{color:#b4454d;font-weight:900}.cjr-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 13px;border:1px solid #cad5e1;border-radius:7px;background:#fff;color:#26384e;text-decoration:none!important;font-size:11px;font-weight:800}.cjr-btn.primary{background:#0a63d8;border-color:#0a63d8;color:#fff!important}.cjr-warning{padding:11px 13px;border:1px solid #f1d6a8;background:#fff9ee;border-radius:8px;color:#75521a;font-size:11px;margin-bottom:12px}.cjr-result{padding:10px 12px;border:1px solid #bfe2cf;background:#effbf4;border-radius:8px;margin-bottom:12px;font-size:11px}
.cjr-confirm{display:flex;align-items:end;gap:9px;flex-wrap:wrap}.cjr-confirm label{display:block;font-size:10px;font-weight:800;margin-bottom:5px}.cjr-confirm input{width:320px;max-width:100%;height:38px;border:1px solid #d4dde8;border-radius:7px;padding:8px}
</style>
<div class="cjr">
  <div class="cjr-head">
    <div><div class="cjr-kicker">System Repair · ERP-11.3.21</div><h1>Posted Cash Voucher → Native Journal Repair</h1><div class="cjr-sub">Idempotent repair for historical Posted Receipts / Payments / Advances that are missing from native ledgers.</div></div>
    <a class="cjr-btn" href="{{ route('system.erp-diagnostics.accounting-journal') }}">Diagnostic</a>
  </div>

  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
  @if(session('repair_result'))
    @php($rr=session('repair_result'))
    <div class="cjr-result"><strong>Last run:</strong> scanned {{ $rr['scanned'] }}, created {{ $rr['created'] }}, skipped {{ $rr['skipped'] }}, failed {{ $rr['failed'] }}.</div>
    @if(!empty($rr['rows']))
      <section class="cjr-card">
        <h3>Last Run Details</h3>
        <table class="cjr-table">
          <thead><tr><th>Voucher</th><th>Result</th><th>Native Journal</th></tr></thead>
          <tbody>
          @foreach($rr['rows'] as $resultRow)
            <tr>
              <td><strong>{{ $resultRow['voucher'] ?? '—' }}</strong></td>
              <td class="{{ str_starts_with((string)($resultRow['result'] ?? ''),'FAILED:') ? 'missing' : 'ok' }}">{{ $resultRow['result'] ?? '—' }}</td>
              <td>{{ $resultRow['journal_id'] ?? '—' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </section>
    @endif
  @endif

  <section class="cjr-card">
    <h3>Posted Voucher Link Status</h3>
    <table class="cjr-table">
      <thead><tr><th>Voucher</th><th>Date</th><th>Type</th><th>Party</th><th>Amount</th><th>Bank</th><th>Native Journal</th></tr></thead>
      <tbody>
      @forelse($rows as $row)
        <tr>
          <td><strong>{{ $row['voucher_no'] }}</strong></td>
          <td>{{ \Carbon\Carbon::parse($row['voucher_date'])->format('d M Y') }}</td>
          <td>{{ str_replace('_',' ',$row['voucher_type']) }}</td>
          <td>{{ $row['party_name'] ?: ($row['party_type'].' #'.($row['party_id'] ?: '—')) }}</td>
          <td>{{ $row['currency_code'] }} {{ number_format($row['amount'],2) }}</td>
          <td>{{ $row['bank'] }}</td>
          <td class="{{ $row['needs_backfill'] ? 'missing' : 'ok' }}">{{ $row['needs_backfill'] ? 'MISSING' : 'Linked #'.$row['native_journal_id'] }}</td>
        </tr>
      @empty
        <tr><td colspan="7">No Posted cash vouchers found.</td></tr>
      @endforelse
      </tbody>
    </table>
  </section>

  @if($missing > 0)
    <section class="cjr-card">
      <h3>Backfill Missing Native Journals</h3>
      <div class="cjr-body">
        <div class="cjr-warning">
          This action creates native <strong>journal_entries</strong> and <strong>journal_lines</strong> only for Posted cash vouchers that do not already have a native journal. Existing linked vouchers are skipped. The source debit/credit lines are the already-posted controlled cash-voucher lines. ERP-11.3.21 also resolves the required native accounting period from each voucher date.
        </div>
        <form method="post" action="{{ route('system.erp-repair.cash-voucher-native-journals.execute') }}" class="cjr-confirm">
          @csrf
          <div><label>Type exactly: BACKFILL POSTED CASH VOUCHERS</label><input name="confirmation" autocomplete="off" required></div>
          <button class="cjr-btn primary">Run Native Journal Backfill</button>
        </form>
      </div>
    </section>
  @else
    <div class="cjr-result"><strong>All Posted cash vouchers are linked to native journals.</strong> No repair is required.</div>
  @endif
</div>
@endsection
