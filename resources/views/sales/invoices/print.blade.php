<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $invoice->invoice_no }} · Sales Invoice</title>
<style>
*{box-sizing:border-box}
:root{
    --navy:#103f79;
    --navy-dark:#0b2d58;
    --ink:#13233d;
    --muted:#66758a;
    --line:#d9e3ef;
    --soft:#f5f8fc;
    --green:#15803d;
}
body{margin:0;background:#edf3f8;color:var(--ink);font-family:Arial,Helvetica,sans-serif}
.actions{width:210mm;margin:18px auto 12px;display:flex;gap:8px}
.btn{border:0;border-radius:8px;background:var(--navy);color:#fff;font-weight:800;padding:11px 18px;cursor:pointer}
.sheet{width:210mm;min-height:297mm;margin:0 auto 28px;background:#fff;padding:14mm 13mm 12mm;box-shadow:0 8px 30px rgba(15,41,70,.13)}
.doc-header{display:grid;grid-template-columns:105px 1fr 290px;gap:20px;align-items:start;padding-bottom:18px;border-bottom:3px solid var(--navy)}
.logo-wrap{width:90px;height:90px;display:flex;align-items:center;justify-content:center}
.logo-wrap img{max-width:90px;max-height:90px;object-fit:contain}
.logo-fallback{width:74px;height:74px;border:2px solid var(--line);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;color:var(--navy)}
.brand-name{font-size:27px;line-height:1.05;font-weight:900;color:var(--navy);letter-spacing:.01em}
.brand-legal{font-size:13px;font-weight:800;margin-top:4px}
.brand-contact{font-size:11px;line-height:1.55;color:#34445d;margin-top:9px}
.doc-meta{border-left:1px solid var(--line);padding-left:22px;font-size:11px;line-height:1.75}.doc-meta-title{font-size:25px;line-height:1.05;font-weight:900;color:var(--navy-dark);letter-spacing:.03em;margin:0 0 9px}.doc-meta-booking{display:grid;grid-template-columns:88px 1fr;gap:8px;margin-bottom:3px}.doc-meta-booking strong{color:var(--navy)}
.doc-meta-row{display:grid;grid-template-columns:88px 1fr;gap:8px}
.doc-meta-row strong{color:var(--navy)}
.status-approved,.status-posted{color:var(--green);font-weight:900}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:8px 0 18px}
.info-box{border:1px solid var(--line);border-radius:10px;overflow:hidden;min-height:112px}
.info-head{background:var(--soft);padding:10px 13px;font-size:12px;font-weight:900;color:var(--navy);text-transform:uppercase;letter-spacing:.03em}
.info-body{padding:12px 13px;font-size:12px;line-height:1.6}
.customer-name{font-size:16px;font-weight:900;color:var(--ink);margin-bottom:2px}
.kv{display:grid;grid-template-columns:115px 1fr;gap:8px}
.kv strong{color:#3b4d66}
.invoice-table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--line);border-radius:9px;overflow:hidden;margin-top:8px}
.invoice-table th{background:var(--navy);color:#fff;text-align:left;padding:10px 9px;font-size:11px;text-transform:uppercase;letter-spacing:.025em}
.invoice-table td{padding:12px 9px;border-bottom:1px solid var(--line);border-right:1px solid #e8eef5;font-size:12px;vertical-align:top}
.invoice-table tr:last-child td{border-bottom:0}
.invoice-table td:last-child,.invoice-table th:last-child{border-right:0}
.invoice-table .sno{width:50px;text-align:center}
.invoice-table .desc{width:31%}
.invoice-table .pax{width:28%}
.invoice-table .ticket{width:17%;white-space:nowrap}
.invoice-table .amount{width:17%;text-align:right;white-space:nowrap;font-weight:900}
.service-title{font-size:13px;font-weight:900;color:#172b48;margin-bottom:4px}
.service-detail{font-size:10.5px;color:#5d6d81;line-height:1.45}
.pax-name{font-weight:800;line-height:1.4}
.pax-type{font-size:10px;color:#708096;margin-top:3px}
.totals{width:45%;margin-left:auto;margin-top:16px;font-size:12px}
.total-row{display:grid;grid-template-columns:1fr 140px;gap:12px;padding:5px 0}
.total-row span:last-child{text-align:right}
.total-row.grand{border-top:1px solid #aab8c8;margin-top:5px;padding-top:10px;font-size:16px;font-weight:900;color:var(--navy-dark)}
.grand-value{background:var(--navy);color:#fff!important;border-radius:6px;padding:8px 10px;text-align:center!important}
.notes{margin-top:18px;border:1px solid var(--line);border-radius:9px;overflow:hidden}
.notes .info-head{padding:8px 12px}
.notes-body{padding:10px 12px;font-size:11px;line-height:1.55}
.foot{margin-top:18px;padding-top:12px;border-top:1px dashed #aebccd;color:#65758b;font-size:9.5px;line-height:1.45}
.thanks{text-align:center;margin-top:12px;font-size:10px;color:#52647b}
.thanks strong{color:var(--navy)}
@page{size:A4 portrait;margin:0}
@media print{
    body{background:#fff}
    .actions{display:none}
    .sheet{margin:0;box-shadow:none;width:210mm;min-height:297mm;padding:12mm 12mm 10mm}
}
</style>
</head>
<body>
@php
    $company = $invoice->company;
    $branch = $invoice->branch;
    $displayStatus = ($invoice->posted_at || $invoice->journal_entry_id) ? 'POSTED' : $invoice->status;
    $statusClass = in_array($displayStatus,['APPROVED','POSTED'],true) ? 'status-approved' : '';
    $printRows = collect();

    foreach($invoice->lines as $line){
        $d = is_array($line->detail_snapshot) ? $line->detail_snapshot : [];
        $type = strtoupper((string)($d['type'] ?? $line->category_snapshot ?? ''));

        if($type === 'AIR_TICKET' && !empty($d['items']) && is_array($d['items'])){
            $items = collect($d['items'])->values();
            $itemCount = max(1,$items->count());
            $rawSum = (float)$items->sum(fn($it)=>(float)($it['selling_total'] ?? 0));
            $allocated = 0.0;

            foreach($items as $index=>$item){
                $passenger = $invoice->passengers->firstWhere('source_booking_passenger_id',$item['booking_passenger_id'] ?? null);

                if($index === $itemCount-1){
                    $amount = round((float)$line->line_total - $allocated,2);
                }elseif($rawSum > 0){
                    $amount = round((float)$line->line_total * ((float)($item['selling_total'] ?? 0) / $rawSum),2);
                    $allocated += $amount;
                }else{
                    $amount = round((float)$line->line_total / $itemCount,2);
                    $allocated += $amount;
                }

                $sector = $item['sector'] ?? trim(($item['origin'] ?? '').' → '.($item['destination'] ?? ''),' →');
                $travelDate = null;
                if(!empty($item['departure_at'])){
                    try{$travelDate=\Carbon\Carbon::parse($item['departure_at'])->format('d M Y H:i');}catch(\Throwable $e){}
                }

                $printRows->push([
                    'description'=>$line->description ?: 'Air Ticket',
                    'type'=>'AIR_TICKET',
                    'airline'=>$item['airline'] ?? null,
                    'pnr'=>$item['pnr'] ?? null,
                    'sector'=>$sector ?: null,
                    'flight'=>$item['flight_number'] ?? null,
                    'travel_date'=>$travelDate,
                    'passenger'=>$passenger?->fullName() ?: 'Passenger',
                    'pax_type'=>$passenger?->pax_type,
                    'ticket_number'=>$item['ticket_number'] ?? '—',
                    'amount'=>$amount,
                ]);
            }
        }else{
            $passengerNames = $line->passengers->map(fn($p)=>$p->fullName())->filter()->implode(', ');
            $detail1 = null;
            $detail2 = null;

            if($type === 'HOTEL'){
                $detail1 = trim(implode(' · ',array_filter([$d['hotel'] ?? null,$d['city'] ?? null,$d['room_type'] ?? null])));
                $detail2 = trim(($d['check_in'] ?? '').(!empty($d['check_out'])?' → '.$d['check_out']:''));
            }elseif($type === 'VISA'){
                $detail1 = collect($d['items'] ?? [])->map(fn($v)=>trim(($v['country'] ?? '').' '.($v['visa_type'] ?? '')))->filter()->implode(' · ');
            }elseif($type === 'TRANSPORT'){
                $detail1 = trim(($d['pickup_location'] ?? '').(!empty($d['dropoff_location'])?' → '.$d['dropoff_location']:''));
                $detail2 = $d['vehicle_type'] ?? null;
            }else{
                $detail1 = $line->category_snapshot;
                if($line->service_from || $line->service_to){
                    $detail2 = ($line->service_from?->format('d M Y') ?? '').($line->service_to?' → '.$line->service_to->format('d M Y'):'');
                }
            }

            $printRows->push([
                'description'=>$line->description,
                'type'=>$type ?: 'SERVICE',
                'airline'=>null,
                'pnr'=>null,
                'sector'=>$detail1,
                'flight'=>null,
                'travel_date'=>$detail2,
                'passenger'=>$passengerNames ?: '—',
                'pax_type'=>null,
                'ticket_number'=>'—',
                'amount'=>(float)$line->line_total,
            ]);
        }
    }
@endphp

<div class="actions"><button class="btn" onclick="window.print()">Print / Save PDF</button></div>

<div class="sheet">
    <header class="doc-header">
        <div class="logo-wrap">
            @if($company?->logo_data_uri)
                <img src="{{ $company->logo_data_uri }}" alt="{{ $company->name }} logo">
            @else
                <div class="logo-fallback">ET</div>
            @endif
        </div>

        <div>
            <div class="brand-name">{{ $company?->name ?: 'Easy Ticket' }}</div>
            <div class="brand-legal">{{ $company?->legal_name ?: 'Easy Group Of Travels' }}</div>
            <div class="brand-contact">
                @if($branch?->name)<strong>{{ $branch->name }}</strong>@endif
                @if($branch?->address) · {{ $branch->address }}@elseif($company?->address) · {{ $company->address }}@endif
                <br>
                @if($branch?->phone){{ $branch->phone }}@elseif($company?->phone){{ $company->phone }}@endif
                @if($branch?->email) &nbsp; | &nbsp; {{ $branch->email }}@elseif($company?->email) &nbsp; | &nbsp; {{ $company->email }}@endif
                @if($company?->website) &nbsp; | &nbsp; {{ $company->website }}@endif
            </div>
        </div>

        <div class="doc-meta">
            <div class="doc-meta-title">SALES INVOICE</div>
            @if($invoice->booking)
            <div class="doc-meta-booking"><strong>Booking No.</strong><span>{{ $invoice->booking->booking_no }}</span></div>
            @endif
            <div class="doc-meta-row"><strong>Document No.</strong><span>{{ $invoice->invoice_no }}</span></div>
            <div class="doc-meta-row"><strong>Invoice Date</strong><span>{{ $invoice->invoice_date?->format('d M Y') }}</span></div>
            <div class="doc-meta-row"><strong>Due Date</strong><span>{{ $invoice->due_date?->format('d M Y') ?: '—' }}</span></div>
            <div class="doc-meta-row"><strong>Status</strong><span class="{{ $statusClass }}">{{ str_replace('_',' ',$displayStatus) }}</span></div>
        </div>
    </header>


    <section class="info-grid">
        <div class="info-box">
            <div class="info-head">Bill To</div>
            <div class="info-body">
                <div class="customer-name">{{ $invoice->customer?->display_name ?: 'Customer' }}</div>
                @if($invoice->customer?->address_line_1)<div>{{ $invoice->customer->address_line_1 }}</div>@endif
                @if($invoice->customer?->city)<div>{{ $invoice->customer->city }}</div>@endif
                @if($invoice->customer?->mobile)<div>{{ $invoice->customer->mobile }}</div>@endif
            </div>
        </div>
        <div class="info-box">
            <div class="info-head">Invoice Details</div>
            <div class="info-body">
                <div class="kv"><strong>Payment Terms</strong><span>{{ $invoice->payment_terms_snapshot ?: 'Due on receipt' }}</span></div>
                <div class="kv"><strong>Currency</strong><span>{{ $invoice->currency_code }}</span></div>
                <div class="kv"><strong>Reference</strong><span>{{ $invoice->customer_reference ?: '—' }}</span></div>
                <div class="kv"><strong>Invoice Due Date</strong><span>{{ $invoice->due_date?->format('d M Y') ?: '—' }}</span></div>
            </div>
        </div>
    </section>

    <table class="invoice-table">
        <thead>
            <tr>
                <th class="sno">S.No.</th>
                <th class="desc">Description</th>
                <th class="pax">Passenger</th>
                <th class="ticket">Ticket Number</th>
                <th class="amount">Amount ({{ $invoice->currency_code }})</th>
            </tr>
        </thead>
        <tbody>
        @forelse($printRows as $row)
            <tr>
                <td class="sno">{{ $loop->iteration }}</td>
                <td class="desc">
                    <div class="service-title">{{ $row['description'] }}</div>
                    <div class="service-detail">
                        @if($row['type']==='AIR_TICKET')
                            @if($row['airline'])Airline: {{ $row['airline'] }}<br>@endif
                            @if($row['pnr'])PNR: {{ $row['pnr'] }}@endif
                            @if($row['sector']) · Sector: {{ $row['sector'] }}@endif
                            @if($row['flight'])<br>Flight: {{ $row['flight'] }}@endif
                            @if($row['travel_date']) · Travel: {{ $row['travel_date'] }}@endif
                        @else
                            @if($row['sector']){{ $row['sector'] }}@endif
                            @if($row['travel_date'])<br>{{ $row['travel_date'] }}@endif
                        @endif
                    </div>
                </td>
                <td class="pax">
                    <div class="pax-name">{{ $row['passenger'] }}</div>
                    @if($row['pax_type'])<div class="pax-type">{{ $row['pax_type'] }}</div>@endif
                </td>
                <td class="ticket">{{ $row['ticket_number'] }}</td>
                <td class="amount">{{ number_format((float)$row['amount'],2) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center;color:#66758a">No invoice lines available.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="totals">
        <div class="total-row"><span>Subtotal</span><span>{{ number_format((float)$invoice->subtotal,2) }}</span></div>
        @if((float)$invoice->discount_total > 0)
            <div class="total-row"><span>Discount</span><span>{{ number_format((float)$invoice->discount_total,2) }}</span></div>
        @endif
        <div class="total-row grand">
            <span>Grand Total</span>
            <span class="grand-value">{{ $invoice->currency_code }} {{ number_format((float)$invoice->grand_total,2) }}</span>
        </div>
    </div>

    @if($invoice->notes)
    <section class="notes">
        <div class="info-head">Notes</div>
        <div class="notes-body">{{ $invoice->notes }}</div>
    </section>
    @endif

    <div class="foot">
        This Sales Invoice is the customer commercial/accounting document. Booking Confirmation, Receipt Voucher, Hotel/Umrah/Travel Voucher and supplier documents remain separate controlled documents in the ERP.
    </div>
    <div class="thanks">Thank you for choosing <strong>{{ $company?->name ?: 'Easy Ticket' }}</strong>.</div>
</div>
</body>
</html>
