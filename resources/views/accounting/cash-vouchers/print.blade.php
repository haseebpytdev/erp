<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $row->voucher_no }} · {{ $definition['label'] }}</title>
<style>
@page{size:A4 portrait;margin:9mm}
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#17243a}
.toolbar{max-width:790px;margin:10px auto;display:flex;justify-content:flex-end;gap:8px}
.btn{border:1px solid #cbd7e5;border-radius:7px;background:#fff;padding:8px 12px;font-size:11px;font-weight:800;color:#26384e;text-decoration:none;cursor:pointer}.btn.primary{background:#0964df;border-color:#0964df;color:#fff}
.voucher{width:190mm;margin:0 auto 14px;background:#fff;border:1px solid #dbe3ed;box-shadow:0 5px 18px rgba(21,41,69,.07);padding:10mm 10mm 9mm;position:relative;overflow:hidden}
.header{display:grid;grid-template-columns:auto 1fr auto;gap:11px;align-items:center;padding-bottom:9px;border-bottom:2px solid #173b69}
.logo{width:49px;height:49px;border-radius:9px;border:1px solid #d5dde8;display:flex;align-items:center;justify-content:center;overflow:hidden;color:#d72f37;font-size:21px;font-weight:900}.logo img{width:100%;height:100%;object-fit:contain}
.company-name{font-size:19px;font-weight:900;letter-spacing:.02em}.company-sub{font-size:10px;color:#315f98;font-weight:800;margin-top:2px}.company-contact{font-size:8.5px;color:#68798d;margin-top:3px;line-height:1.45}
.status{padding:5px 8px;border-radius:999px;background:#eef3f8;font-size:8.5px;font-weight:900;text-transform:uppercase}
.title{text-align:center;font-family:Georgia,serif;font-size:18px;font-weight:900;color:#213f6b;margin:11px 0 9px}
.meta{display:grid;grid-template-columns:1.2fr .8fr .9fr;border:1px solid #d9e3ef;border-radius:7px;overflow:hidden;margin-bottom:10px}.meta>div{padding:8px 9px;border-right:1px solid #e3e9f0}.meta>div:last-child{border-right:0}
.label{font-size:7.8px;text-transform:uppercase;font-weight:900;color:#697a8f;letter-spacing:.03em}.value{font-size:11px;font-weight:850;margin-top:2px}
.party{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;padding:9px 0;border-bottom:1px dashed #cbd5e1}.party-name{font-size:16px;font-weight:900;margin-top:3px}
.amount{display:flex;align-items:center;justify-content:space-between;gap:15px;margin:11px 0 9px;padding:10px 12px;border:1.5px solid #6c9ddd;border-radius:7px;background:#fbfdff}.amount .caption{font-size:10px;color:#155caf;font-weight:850}.amount .number{font-size:20px;font-weight:900}
.words{padding:0 0 9px;border-bottom:1px dashed #cbd5e1}.words .value{font-family:Georgia,serif;font-size:12px}
.details{display:grid;grid-template-columns:145px 1fr;margin-top:8px}.details div{padding:5px 0;border-bottom:1px solid #edf1f5;font-size:9.5px}.details .k{font-size:8px;font-weight:900;color:#65768b;text-transform:uppercase}
.alloc{margin-top:11px}.alloc-title{font-size:10.5px;font-weight:900;margin-bottom:5px}.alloc table{width:100%;border-collapse:collapse}.alloc th,.alloc td{padding:6px;border:1px solid #e2e8ef;text-align:left;font-size:8.5px}.alloc th{background:#f7f9fc;color:#53647a;text-transform:uppercase}
.signatures{display:grid;grid-template-columns:1fr 1fr;gap:75px;margin:30px 14px 0}.signature{text-align:center;border-top:1px dashed #8795a7;padding-top:5px;font-size:8.5px;color:#69798c}
.footer{margin-top:20px;padding-top:7px;border-top:1px solid #e5eaf0;text-align:center;font-size:8.3px;color:#708096;line-height:1.45}
.watermark{position:absolute;left:50%;top:54%;transform:translate(-50%,-50%) rotate(-28deg);font-size:65px;font-weight:900;color:rgba(47,70,97,.045);letter-spacing:.08em;pointer-events:none;white-space:nowrap}
.bar{position:absolute;left:0;right:0;bottom:0;height:6px;display:flex}.bar .b{flex:4;background:#113b69}.bar .r{flex:1.2;background:#d82e36}
@media print{body{background:#fff}.toolbar{display:none}.voucher{width:auto;margin:0;border:0;box-shadow:none;padding:3mm 4mm 7mm}.header{padding-top:0}}
</style>
</head>
<body>
@php
  $profile=$companyProfile ?? [];
  $contact=array_filter([
    $profile['address'] ?? null,
    $profile['phone'] ?? null,
    $profile['email'] ?? null,
    $profile['website'] ?? null,
  ]);
@endphp
<div class="toolbar">
  <a class="btn" href="{{ route('accounting.cash-vouchers.show',$row->id) }}">Back to Voucher</a>
  <button class="btn primary" onclick="window.print()">Print Voucher</button>
</div>

<article class="voucher" data-et-print-voucher="ERP-11.3.27">
  @if($row->status !== 'posted')<div class="watermark">{{ strtoupper(str_replace('_',' ',$row->status)) }}</div>@endif

  <header class="header">
    <div class="logo">
      @if(!empty($profile['logo']))<img src="{{ $profile['logo'] }}" alt="Company Logo">@else ET @endif
    </div>
    <div>
      <div class="company-name">{{ $profile['name'] ?? 'Easy Ticket' }}</div>
      <div class="company-sub">{{ $profile['subtitle'] ?? 'Easy Group Of Travels' }}</div>
      @if($contact)<div class="company-contact">{{ implode(' · ',$contact) }}</div>@endif
    </div>
    <div class="status">{{ str_replace('_',' ',$row->status) }}</div>
  </header>

  <div class="title">{{ strtoupper($definition['label']) }}</div>

  <div class="meta">
    <div><div class="label">Voucher No.</div><div class="value">{{ $row->voucher_no }}</div></div>
    <div><div class="label">Date</div><div class="value">{{ \Carbon\Carbon::parse($row->voucher_date)->format('d M Y') }}</div></div>
    <div><div class="label">Value / Bank Date</div><div class="value">{{ $row->value_date ? \Carbon\Carbon::parse($row->value_date)->format('d M Y') : '—' }}</div></div>
  </div>

  <div class="party">
    <div>
      <div class="label">{{ $definition['direction']==='in' ? 'Received From' : 'Paid To' }}</div>
      <div class="party-name">{{ $row->party_name ?: '—' }}</div>
    </div>
    @if($bookingReference)
      <div style="text-align:right"><div class="label">Booking Reference</div><div class="value">{{ $bookingReference }}</div></div>
    @endif
  </div>

  <div class="amount">
    <div class="caption">{{ $definition['direction']==='in' ? 'Amount Received' : 'Amount Paid' }}</div>
    <div class="number">{{ $row->currency_code }} {{ number_format($row->amount,2) }}</div>
  </div>

  <div class="words"><div class="label">Amount In Words</div><div class="value">{{ $amountInWords }}</div></div>

  <div class="details">
    <div class="k">Payment Method</div><div>{{ $row->payment_method ?: '—' }}</div>
    <div class="k">Cash / Bank Account</div><div>{{ $row->cash_bank_account_code }} · {{ $row->cash_bank_account_name }}</div>
    <div class="k">Bank Name</div><div>{{ $row->bank_name ?: '—' }}</div>
    <div class="k">Transaction / Bank Reference</div><div>{{ $row->transaction_reference ?: '—' }}</div>
    <div class="k">Cheque / Instrument No.</div><div>{{ $row->instrument_no ?: '—' }}</div>
    <div class="k">Narration / Remarks</div><div>{{ $row->narration ?: '—' }}</div>
  </div>

  @if($allocations->isNotEmpty())
    <div class="alloc">
      <div class="alloc-title">{{ $definition['direction']==='in' ? 'Receipt Allocation' : 'Payment Allocation' }}</div>
      <table>
        <thead><tr><th>Document</th><th>Allocated Amount</th><th>Notes</th></tr></thead>
        <tbody>@foreach($allocations as $allocation)<tr><td>{{ $allocation->target_number ?: ucfirst(str_replace('_',' ',$allocation->target_type)).' #'.$allocation->target_id }}</td><td>{{ $allocation->currency_code }} {{ number_format($allocation->amount,2) }}</td><td>{{ $allocation->notes ?: '—' }}</td></tr>@endforeach</tbody>
      </table>
    </div>
  @endif

  <div class="signatures"><div class="signature">Prepared By</div><div class="signature">Authorized Signature</div></div>

  @if(!empty($profile['footer']))<div class="footer">{!! $profile['footer'] !!}</div>@elseif($contact)<div class="footer">{{ implode(' · ',$contact) }}</div>@endif

  <div class="bar"><div class="b"></div><div class="r"></div></div>
</article>

@if($autoPrint)<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),250));</script>@endif
</body>
</html>
