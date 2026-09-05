<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class PresentGroupUmrahSalesInvoice
{
    public function handle(Request $request, Closure $next): Response
    {
        $response=$next($request);

        if (!method_exists($response,'getContent') || !method_exists($response,'setContent')) {
            return $response;
        }

        $contentType=strtolower((string)$response->headers->get('Content-Type',''));

        if ($contentType!=='' && !str_contains($contentType,'text/html')) {
            return $response;
        }

        $invoiceId=$this->routeInvoiceId($request);

        if ($invoiceId<=0) {
            return $response;
        }

        $data=$this->presentation($invoiceId);

        if (!$data) {
            return $response;
        }

        $content=(string)$response->getContent();

        if ($content==='' || !str_contains(strtolower($content),'sales invoice')) {
            return $response;
        }

        $markup=$this->markup($data);

        if (stripos($content,'</body>')!==false) {
            $content=preg_replace('/<\/body>/i',$markup.'</body>',$content,1) ?: $content;
        } else {
            $content.=$markup;
        }

        $response->setContent($content);
        $response->headers->remove('Content-Length');

        return $response;
    }

    private function routeInvoiceId(Request $request): int
    {
        foreach (['invoice','sales_invoice','id'] as $parameter) {
            $value=$request->route($parameter);

            if ($value instanceof Model) {
                $id=(int)$value->getKey();
                if ($id>0) return $id;
            }

            if (is_numeric($value) && (int)$value>0) {
                return (int)$value;
            }
        }

        return 0;
    }

    private function presentation(int $invoiceId): ?array
    {
        if (
            !Schema::hasTable('booking_group_umrah_invoice_links')
            || !Schema::hasTable('booking_group_package_unified')
        ) {
            return null;
        }

        try {
            $link=DB::table('booking_group_umrah_invoice_links')
                ->where('invoice_id',$invoiceId)
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if (!$link) return null;

        $bookingId=(int)($link->booking_id ?? 0);
        if ($bookingId<=0) return null;

        try {
            $commercial=DB::table('booking_group_package_unified')
                ->where('booking_id',$bookingId)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if (!$commercial) return null;

        $adult=max(0,(int)($commercial->booked_adult_pax ?? 0));
        $child=max(0,(int)($commercial->booked_child_pax ?? 0));
        $infant=max(0,(int)($commercial->booked_infant_pax ?? 0));
        $booked=max(0,(int)($commercial->booked_pax ?? ($adult+$child+$infant)));

        if ($adult+$child+$infant===0 && $booked>0) {
            $adult=$booked;
        }

        $passengers=$this->passengers($bookingId);
        $nameCounts=['ADULT'=>0,'CHILD'=>0,'INFANT'=>0];

        foreach ($passengers as $passenger) {
            $fare=strtoupper(trim((string)($passenger['fare'] ?? 'ADULT')));
            if (!array_key_exists($fare,$nameCounts)) $fare='ADULT';
            $nameCounts[$fare]++;
        }

        $invoice=$this->invoiceRow($link,$invoiceId);
        $received=count($passengers);

        return [
            'booking_id'=>$bookingId,
            'invoice_id'=>$invoiceId,
            'invoice_number'=>trim((string)($invoice['number'] ?? $link->invoice_number ?? '')),
            'invoice_status'=>strtolower(trim((string)($invoice['status'] ?? ''))),
            'currency'=>strtoupper(trim((string)($commercial->currency_code ?? 'PKR'))) ?: 'PKR',
            'booked'=>[
                'adult'=>$adult,
                'child'=>$child,
                'infant'=>$infant,
                'total'=>$booked,
            ],
            'manifest'=>[
                'received'=>$received,
                'pending'=>max(0,$booked-$received),
                'adult_names'=>$nameCounts['ADULT'],
                'child_names'=>$nameCounts['CHILD'],
                'infant_names'=>$nameCounts['INFANT'],
                'passengers'=>$passengers,
            ],
            'lines'=>$this->invoiceLines($invoiceId),
        ];
    }

    private function passengers(int $bookingId): array
    {
        if (!Schema::hasTable('booking_group_package_passengers')) return [];

        try {
            $rows=DB::table('booking_group_package_passengers')
                ->where('booking_id',$bookingId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $result=[];

        foreach ($rows as $row) {
            $first=trim((string)($row->first_name ?? ''));
            if ($first==='') continue;

            $name=trim(implode(' ',array_filter([
                trim((string)($row->title ?? '')),
                $first,
                trim((string)($row->last_name ?? '')),
            ],fn(string $value): bool => $value!=='')));

            $result[]=[
                'name'=>$name,
                'fare'=>strtoupper(trim((string)($row->fare_as ?? 'ADULT'))) ?: 'ADULT',
                'passport'=>trim((string)($row->passport_no ?? '')),
                'ticket'=>trim((string)($row->ticket_number ?? '')),
            ];
        }

        return $result;
    }

    private function invoiceRow(object $link,int $invoiceId): array
    {
        $table=trim((string)($link->invoice_table ?? 'sales_invoices'));
        if ($table==='' || !Schema::hasTable($table)) $table='sales_invoices';
        if (!Schema::hasTable($table)) return [];

        try {
            $columns=Schema::getColumnListing($table);
        } catch (\Throwable) {
            return [];
        }

        $idColumn=$this->first($columns,['id','sales_invoice_id','invoice_id']);
        if (!$idColumn) return [];

        try {
            $row=DB::table($table)->where($idColumn,$invoiceId)->first();
        } catch (\Throwable) {
            return [];
        }

        if (!$row) return [];

        $a=(array)$row;
        $numberColumn=$this->first($columns,[
            'invoice_number','invoice_no','invoice_reference','reference_no',
            'reference','number','document_no'
        ]);
        $statusColumn=$this->first($columns,['status','invoice_status','document_status']);

        return [
            'number'=>$numberColumn ? ($a[$numberColumn] ?? '') : '',
            'status'=>$statusColumn ? ($a[$statusColumn] ?? '') : '',
        ];
    }

    private function invoiceLines(int $invoiceId): array
    {
        foreach (['sales_invoice_lines','sales_invoice_items','sales_invoice_details'] as $table) {
            if (!Schema::hasTable($table)) continue;

            try {
                $columns=Schema::getColumnListing($table);
            } catch (\Throwable) {
                continue;
            }

            $invoiceColumn=$this->first($columns,[
                'sales_invoice_id','invoice_id','sales_invoice_header_id','header_id'
            ]);

            if (!$invoiceColumn) continue;

            try {
                $query=DB::table($table)->where($invoiceColumn,$invoiceId);
                $order=$this->first($columns,[
                    'line_no','line_number','sequence_no','sequence','sort_order','id'
                ]);
                if ($order) $query->orderBy($order);
                $rows=$query->get();
            } catch (\Throwable) {
                continue;
            }

            if ($rows->isEmpty()) continue;

            $descriptionColumn=$this->first($columns,[
                'description','item_description','details','particulars',
                'name','service_name','product_name'
            ]);
            $qtyColumn=$this->first($columns,['quantity','qty']);
            $unitColumn=$this->first($columns,[
                'unit_price','rate','price','sale_price','selling_price'
            ]);
            $discountColumn=$this->first($columns,[
                'discount_amount','line_discount','discount'
            ]);
            $totalColumn=$this->first($columns,[
                'line_total','net_amount','total_amount','amount','total'
            ]);
            $mappingColumn=$this->first($columns,[
                'revenue_mapping_key','account_mapping_key','mapping_key'
            ]);
            $lineNoColumn=$this->first($columns,[
                'line_no','line_number','sequence_no','sequence','sort_order','id'
            ]);

            $result=[];

            foreach ($rows as $row) {
                $a=(array)$row;
                $description=trim((string)($descriptionColumn ? ($a[$descriptionColumn] ?? '') : ''));
                $quantity=max(0,(float)($qtyColumn ? ($a[$qtyColumn] ?? 0) : 0));
                $unit=max(0,(float)($unitColumn ? ($a[$unitColumn] ?? 0) : 0));
                $discount=max(0,(float)($discountColumn ? ($a[$discountColumn] ?? 0) : 0));
                $total=$totalColumn
                    ? (float)($a[$totalColumn] ?? 0)
                    : round(max(0,($quantity*$unit)-$discount),2);

                $fare='Package';
                if (preg_match('/\b(Adult|Child|Infant)\b/i',$description,$match)) {
                    $fare=ucfirst(strtolower($match[1]));
                }

                $amendment=null;
                if (preg_match('/Add\s*Pax\s*#\s*(\d+)/i',$description,$match)) {
                    $amendment=(int)$match[1];
                }

                $result[]=[
                    'line_no'=>$lineNoColumn ? (int)($a[$lineNoColumn] ?? 0) : 0,
                    'description'=>$description,
                    'fare'=>$fare,
                    'tranche'=>$amendment ? 'Add Pax #'.$amendment : 'Original Package',
                    'amendment'=>$amendment,
                    'quantity'=>$quantity,
                    'unit_price'=>$unit,
                    'discount'=>$discount,
                    'line_total'=>$total,
                    'mapping_key'=>$mappingColumn ? trim((string)($a[$mappingColumn] ?? '')) : '',
                ];
            }

            return $result;
        }

        return [];
    }

    private function first(array $columns,array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate,$columns,true)) return $candidate;
        }
        return null;
    }

    private function markup(array $data): string
    {
        $json=json_encode(
            $data,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        );

        if (!is_string($json)) return '';

        $template=<<<'HTML'
<style id="et-group-umrah-invoice-presentation">
.et-gu-invoice-composite{
    width:100%;
    max-width:none;
    box-sizing:border-box;
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(360px,.95fr);
    gap:16px;
    margin:16px 0 20px;
}
.et-gu-panel{
    background:#fff;
    border:1px solid #e4eaf2;
    border-radius:14px;
    box-shadow:0 6px 18px rgba(15,23,42,.045);
    overflow:hidden;
}
.et-gu-panel-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    padding:16px 18px 12px;
    border-bottom:1px solid #edf1f6;
}
.et-gu-panel-title{
    margin:0;
    color:#17233a;
    font-size:16px;
    line-height:1.2;
    font-weight:800;
}
.et-gu-panel-sub{
    margin-top:4px;
    color:#718096;
    font-size:11px;
    line-height:1.4;
}
.et-gu-count-badge{
    display:inline-flex;
    align-items:center;
    border:1px solid #dbe5f0;
    border-radius:999px;
    padding:4px 8px;
    background:#f8fbff;
    color:#1762b1;
    font-size:9px;
    font-weight:800;
    white-space:nowrap;
}
.et-gu-line-list{
    display:grid;
    gap:10px;
    padding:12px;
}
.et-gu-line{
    border:1px solid #e0e7ef;
    border-radius:11px;
    background:#fff;
    padding:12px 13px;
}
.et-gu-line-top{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:10px;
    align-items:start;
}
.et-gu-line-title{
    color:#17233a;
    font-size:12px;
    line-height:1.35;
    font-weight:800;
}
.et-gu-line-meta{
    margin-top:3px;
    color:#718096;
    font-size:9.5px;
    line-height:1.35;
}
.et-gu-line-total{
    color:#0f63d7;
    font-size:12px;
    line-height:1.2;
    font-weight:850;
    white-space:nowrap;
}
.et-gu-line-values{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:14px;
    margin-top:10px;
    padding-top:9px;
    border-top:1px solid #edf1f5;
}
.et-gu-line-value-label{
    color:#7a8799;
    font-size:8.5px;
}
.et-gu-line-value-number{
    margin-top:3px;
    color:#223149;
    font-size:10.5px;
    font-weight:800;
}
.et-gu-pax-body{
    padding:13px 15px 15px;
}
.et-gu-pax-hero{
    display:grid;
    grid-template-columns:auto 1fr;
    gap:14px;
    align-items:center;
    padding:13px 14px;
    border:1px solid #dce9f8;
    border-radius:11px;
    background:linear-gradient(90deg,#eef6ff 0%,#f8fbff 100%);
}
.et-gu-pax-number{
    color:#0f63d7;
    font-size:28px;
    line-height:1;
    font-weight:850;
}
.et-gu-pax-label{
    margin-top:4px;
    color:#718096;
    font-size:9.5px;
}
.et-gu-fare-pills{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    justify-content:flex-end;
}
.et-gu-fare-pill{
    border:1px solid #d9e3ef;
    border-radius:7px;
    background:#fff;
    padding:6px 9px;
    color:#2f3d53;
    font-size:9px;
    font-weight:800;
    white-space:nowrap;
}
.et-gu-pax-table{
    width:100%;
    border-collapse:collapse;
    margin-top:11px;
}
.et-gu-pax-table th,
.et-gu-pax-table td{
    padding:7px 4px;
    border-bottom:1px solid #edf1f5;
    font-size:10px;
}
.et-gu-pax-table th{
    color:#7a8799;
    font-size:8.5px;
    text-align:left;
    text-transform:uppercase;
    letter-spacing:.025em;
}
.et-gu-pax-table td{
    color:#2c3a51;
    font-weight:700;
}
.et-gu-pax-table th:not(:first-child),
.et-gu-pax-table td:not(:first-child){
    text-align:right;
}
.et-gu-manifest{
    margin-top:13px;
    padding-top:12px;
    border-top:1px solid #edf1f5;
}
.et-gu-manifest-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
}
.et-gu-manifest-title{
    color:#223149;
    font-size:11px;
    font-weight:800;
}
.et-gu-manifest-count{
    color:#0f63d7;
    font-size:10px;
    font-weight:850;
    white-space:nowrap;
}
.et-gu-progress{
    height:6px;
    margin-top:7px;
    border-radius:999px;
    background:#e9eef5;
    overflow:hidden;
}
.et-gu-progress > span{
    display:block;
    height:100%;
    border-radius:999px;
    background:#1769d2;
}
.et-gu-pending{
    margin-top:8px;
    color:#718096;
    font-size:9.5px;
}
.et-gu-passengers{
    display:grid;
    gap:0;
    margin-top:9px;
    border:1px solid #e5ebf2;
    border-radius:9px;
    overflow:hidden;
    background:#fff;
}
.et-gu-passenger{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:9px;
    padding:8px 10px;
    border-bottom:1px solid #edf1f5;
}
.et-gu-passenger:last-child{
    border-bottom:0;
}
.et-gu-passenger-name{
    min-width:0;
    color:#2b3a51;
    font-size:10px;
    font-weight:750;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
}
.et-gu-passenger-fare{
    flex:0 0 auto;
    border:1px solid #dce5ef;
    border-radius:6px;
    background:#f8fafc;
    padding:3px 6px;
    color:#41516a;
    font-size:8px;
    font-weight:800;
}
.et-gu-view-all{
    width:100%;
    margin-top:8px;
    border:1px solid #cfe0f6;
    border-radius:8px;
    background:#f6faff;
    padding:8px 10px;
    color:#0f63d7;
    font-size:9.5px;
    font-weight:800;
    cursor:pointer;
}
.et-gu-note{
    margin-top:10px;
    border:1px solid #ccebdc;
    border-radius:9px;
    background:#effaf5;
    padding:9px 10px;
    color:#1f6d4a;
    font-size:9px;
    line-height:1.45;
}
.et-gu-top-pax-card{
    display:flex;
    height:100%;
    flex-direction:column;
    justify-content:center;
}
.et-gu-top-pax-title{
    color:#5f6d81;
    font-size:10px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.025em;
}
.et-gu-top-pax-value{
    margin-top:7px;
    color:#17233a;
    font-size:25px;
    line-height:1;
    font-weight:850;
}
.et-gu-top-pax-sub{
    margin-top:7px;
    color:#718096;
    font-size:10px;
    line-height:1.35;
}
.et-gu-modal-backdrop{
    position:fixed;
    inset:0;
    z-index:99990;
    display:none;
    align-items:center;
    justify-content:center;
    padding:24px;
    background:rgba(15,23,42,.48);
}
.et-gu-modal-backdrop.is-open{
    display:flex;
}
.et-gu-modal{
    width:min(760px,96vw);
    max-height:min(78vh,720px);
    display:flex;
    flex-direction:column;
    overflow:hidden;
    border-radius:14px;
    background:#fff;
    box-shadow:0 24px 70px rgba(15,23,42,.28);
}
.et-gu-modal-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:15px 17px;
    border-bottom:1px solid #e8edf3;
}
.et-gu-modal-title{
    margin:0;
    color:#17233a;
    font-size:15px;
    font-weight:800;
}
.et-gu-modal-sub{
    margin-top:3px;
    color:#718096;
    font-size:9.5px;
}
.et-gu-modal-close{
    border:1px solid #dbe3ed;
    border-radius:8px;
    background:#fff;
    padding:6px 9px;
    color:#41516a;
    font-size:12px;
    cursor:pointer;
}
.et-gu-modal-tools{
    padding:10px 14px;
    border-bottom:1px solid #edf1f5;
    background:#fafcff;
}
.et-gu-modal-search{
    width:100%;
    box-sizing:border-box;
    border:1px solid #dce5ef;
    border-radius:8px;
    padding:9px 11px;
    font-size:10px;
    outline:none;
}
.et-gu-modal-body{
    overflow:auto;
    padding:10px 14px 14px;
}
.et-gu-modal-row{
    display:grid;
    grid-template-columns:46px minmax(0,1fr) 90px;
    gap:10px;
    align-items:center;
    padding:9px 4px;
    border-bottom:1px solid #edf1f5;
}
.et-gu-modal-index{
    color:#7a8799;
    font-size:9px;
    font-weight:700;
}
.et-gu-modal-name{
    min-width:0;
    color:#26354b;
    font-size:10px;
    font-weight:750;
    overflow:hidden;
    white-space:nowrap;
    text-overflow:ellipsis;
}
.et-gu-modal-fare{
    justify-self:end;
    border:1px solid #dce5ef;
    border-radius:6px;
    background:#f8fafc;
    padding:4px 7px;
    color:#41516a;
    font-size:8px;
    font-weight:800;
}
@media(max-width:1100px){
    .et-gu-invoice-composite{
        grid-template-columns:1fr;
    }
}
@media(max-width:760px){
    .et-gu-line-values{
        gap:8px;
    }
    .et-gu-modal-row{
        grid-template-columns:36px minmax(0,1fr) 72px;
    }
}
</style>
<script id="et-group-umrah-invoice-presentation-script">
(() => {
    const DATA=__DATA__;
    const esc=value=>String(value??'')
        .replaceAll('&','&amp;')
        .replaceAll('<','&lt;')
        .replaceAll('>','&gt;')
        .replaceAll('"','&quot;')
        .replaceAll("'",'&#039;');

    const money=value=>`${esc(DATA.currency||'PKR')} ${Number(value||0).toLocaleString(undefined,{
        minimumFractionDigits:2,
        maximumFractionDigits:2
    })}`;

    const qty=value=>{
        const n=Number(value||0);
        return Number.isInteger(n)
            ? n.toLocaleString(undefined,{minimumFractionDigits:3,maximumFractionDigits:3})
            : n.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:3});
    };

    const leafByText=phrase=>{
        const nodes=Array.from(document.querySelectorAll('body *'));
        return nodes.find(n=>n.children.length===0&&String(n.textContent||'').trim()===phrase)
            || nodes.find(n=>n.children.length===0&&String(n.textContent||'').includes(phrase))
            || null;
    };

    const nearestCard=node=>{
        if(!node)return null;
        const direct=node.closest('.card,[class*="card"],[class*="panel"]');
        if(direct)return direct;
        let current=node.parentElement;
        for(let i=0;i<5&&current;i++){
            const r=current.getBoundingClientRect();
            if(r.width>180&&r.height>55&&current!==document.body)return current;
            current=current.parentElement;
        }
        return node.parentElement;
    };

    if(document.querySelector('[data-et-group-umrah-invoice]')){
        return;
    }

    const booked=DATA.booked||{};
    const manifest=DATA.manifest||{};
    const lines=Array.isArray(DATA.lines)?DATA.lines:[];
    const passengers=Array.isArray(manifest.passengers)?manifest.passengers:[];

    const snapshotLabel=leafByText('Immutable passenger snapshot');
    if(snapshotLabel){
        const card=nearestCard(snapshotLabel);
        if(card){
            card.innerHTML=`
                <div class="et-gu-top-pax-card">
                    <div class="et-gu-top-pax-title">Booked Pax</div>
                    <div class="et-gu-top-pax-value">${esc(booked.total||0)}</div>
                    <div class="et-gu-top-pax-sub">
                        ${esc(booked.adult||0)} Adult ·
                        ${esc(booked.child||0)} Child ·
                        ${esc(booked.infant||0)} Infant
                    </div>
                </div>`;
        }
    }

    const lineCards=lines.map(line=>`
        <article class="et-gu-line">
            <div class="et-gu-line-top">
                <div style="min-width:0">
                    <div class="et-gu-line-title">
                        ${esc(line.description||`Group Umrah Package - ${line.fare||'Package'}`)}
                    </div>
                    <div class="et-gu-line-meta">
                        ${esc(line.mapping_key||'UMRAH_PACKAGE')}
                        &nbsp; · &nbsp;
                        ${line.amendment?`ADD PAX #${esc(line.amendment)}`:'PER_PERSON'}
                    </div>
                </div>
                <div class="et-gu-line-total">${money(line.line_total)}</div>
            </div>
            <div class="et-gu-line-values">
                <div>
                    <div class="et-gu-line-value-label">Qty</div>
                    <div class="et-gu-line-value-number">${qty(line.quantity)}</div>
                </div>
                <div>
                    <div class="et-gu-line-value-label">Unit Price</div>
                    <div class="et-gu-line-value-number">${money(line.unit_price)}</div>
                </div>
                <div>
                    <div class="et-gu-line-value-label">Discount</div>
                    <div class="et-gu-line-value-number">${money(line.discount)}</div>
                </div>
            </div>
        </article>
    `).join('');

    const received=Number(manifest.received||0);
    const total=Number(booked.total||0);
    const pending=Math.max(0,Number(manifest.pending||0));
    const percent=total>0?Math.min(100,Math.round((received/total)*100)):0;

    const previewPassengers=passengers.slice(0,5);
    const previewRows=previewPassengers.map((p,index)=>`
        <div class="et-gu-passenger">
            <div class="et-gu-passenger-name">
                ${index+1}. ${esc(p.name)}
            </div>
            <div class="et-gu-passenger-fare">${esc(p.fare)}</div>
        </div>
    `).join('');

    const composite=document.createElement('section');
    composite.className='et-gu-invoice-composite';
    composite.dataset.etGroupUmrahInvoice='ERP-10.31.72';
    document.body.classList.add('et-gu-invoice-redesigned');

    composite.innerHTML=`
        <div class="et-gu-panel">
            <div class="et-gu-panel-head">
                <div>
                    <h3 class="et-gu-panel-title">Invoice Lines</h3>
                    <div class="et-gu-panel-sub">${esc(lines.length)} service line item${lines.length===1?'':'s'}</div>
                </div>
                <span class="et-gu-count-badge">${esc(lines.length)} Lines</span>
            </div>
            ${lineCards
                ? `<div class="et-gu-line-list">${lineCards}</div>`
                : `<div style="padding:16px;color:#718096;font-size:11px">No Group Umrah invoice lines found.</div>`
            }
        </div>

        <div class="et-gu-panel">
            <div class="et-gu-panel-head">
                <div>
                    <h3 class="et-gu-panel-title">Passenger / Pax Summary</h3>
                    <div class="et-gu-panel-sub">Booking commercial capacity split & current manifest</div>
                </div>
            </div>

            <div class="et-gu-pax-body">
                <div class="et-gu-pax-hero">
                    <div>
                        <div class="et-gu-pax-number">${esc(total)}</div>
                        <div class="et-gu-pax-label">Total Booked Pax</div>
                    </div>
                    <div class="et-gu-fare-pills">
                        <span class="et-gu-fare-pill">${esc(booked.adult||0)} Adult</span>
                        <span class="et-gu-fare-pill">${esc(booked.child||0)} Child</span>
                        <span class="et-gu-fare-pill">${esc(booked.infant||0)} Infant</span>
                    </div>
                </div>

                <table class="et-gu-pax-table">
                    <thead>
                        <tr><th>Pax Type</th><th>Booked</th><th>Names</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Adult</td><td>${esc(booked.adult||0)}</td><td>${esc(manifest.adult_names||0)}</td></tr>
                        <tr><td>Child</td><td>${esc(booked.child||0)}</td><td>${esc(manifest.child_names||0)}</td></tr>
                        <tr><td>Infant</td><td>${esc(booked.infant||0)}</td><td>${esc(manifest.infant_names||0)}</td></tr>
                        <tr><td><strong>Total</strong></td><td><strong>${esc(total)}</strong></td><td><strong>${esc(received)} / ${esc(total)}</strong></td></tr>
                    </tbody>
                </table>

                <div class="et-gu-manifest">
                    <div class="et-gu-manifest-top">
                        <div class="et-gu-manifest-title">Current Booking Manifest</div>
                        <div class="et-gu-manifest-count">${esc(received)} / ${esc(total)} Names</div>
                    </div>

                    <div class="et-gu-progress"><span style="width:${percent}%"></span></div>
                    <div class="et-gu-pending">${esc(pending)} passenger name${pending===1?'':'s'} still pending</div>

                    <div class="et-gu-passengers">
                        ${previewRows || `
                            <div class="et-gu-passenger">
                                <div class="et-gu-passenger-name">No passenger names received yet</div>
                            </div>
                        `}
                    </div>

                    ${passengers.length>5 ? `
                        <button type="button" class="et-gu-view-all" id="et-gu-view-all-passengers">
                            View All Passengers (${esc(passengers.length)})
                        </button>
                    ` : ''}

                    <div class="et-gu-note">
                        Passenger names are live booking information.
                        Group Umrah invoice accounting remains package/fare based,
                        and posted accounting is not modified when names are added later.
                    </div>
                </div>
            </div>
        </div>
    `;

    const modal=document.createElement('div');
    modal.className='et-gu-modal-backdrop';
    modal.id='et-gu-passenger-modal';
    modal.innerHTML=`
        <div class="et-gu-modal" role="dialog" aria-modal="true" aria-labelledby="et-gu-passenger-modal-title">
            <div class="et-gu-modal-head">
                <div>
                    <h3 class="et-gu-modal-title" id="et-gu-passenger-modal-title">All Passengers</h3>
                    <div class="et-gu-modal-sub">${esc(received)} names received · ${esc(pending)} pending · ${esc(total)} booked pax</div>
                </div>
                <button type="button" class="et-gu-modal-close" id="et-gu-passenger-modal-close">✕</button>
            </div>
            <div class="et-gu-modal-tools">
                <input class="et-gu-modal-search" id="et-gu-passenger-search" type="search" placeholder="Search passenger name or fare type">
            </div>
            <div class="et-gu-modal-body" id="et-gu-passenger-modal-body">
                ${passengers.map((p,index)=>`
                    <div class="et-gu-modal-row" data-passenger-search="${esc((p.name+' '+p.fare).toLowerCase())}">
                        <div class="et-gu-modal-index">${index+1}</div>
                        <div class="et-gu-modal-name">${esc(p.name)}</div>
                        <div class="et-gu-modal-fare">${esc(p.fare)}</div>
                    </div>
                `).join('')}
            </div>
        </div>
    `;

    const oldLineSub=leafByText('Customer-facing services copied from the booking and retained as invoice snapshots.');
    const oldPassengerSub=leafByText('Snapshot captured when the invoice was created.');

    const ancestors=node=>{
        const list=[];
        let current=node;
        while(current&&current!==document.body){
            list.push(current);
            current=current.parentElement;
        }
        return list;
    };

    const lowestCommonAncestor=(a,b)=>{
        if(!a||!b)return null;
        const bSet=new Set(ancestors(b));
        return ancestors(a).find(node=>bSet.has(node))||null;
    };

    const containsPhrase=(node,phrase)=>
        !!node && String(node.textContent||'').includes(phrase);

    const isNativeInvoicePairContainer=node=>{
        if(!node||node===document.body)return false;
        const text=String(node.textContent||'');
        if(!text.includes('Invoice Lines')||!text.includes('Passengers'))return false;
        if(text.includes('Accounting Preview'))return false;

        const children=Array.from(node.children||[]);
        const lineChild=children.some(child=>
            containsPhrase(child,'Customer-facing services copied from the booking and retained as invoice snapshots.')
        );
        const passengerChild=children.some(child=>
            containsPhrase(child,'Snapshot captured when the invoice was created.')
        );
        return lineChild&&passengerChild;
    };

    let nativePair=lowestCommonAncestor(oldLineSub,oldPassengerSub);

    // The exact LCA may be an inner wrapper. Walk upward until its direct
    // children are the native Invoice Lines and Passengers columns, but stop
    // before Accounting Preview so only this one section is replaced.
    while(nativePair&&nativePair!==document.body&&!isNativeInvoicePairContainer(nativePair)){
        if(containsPhrase(nativePair,'Accounting Preview')){
            nativePair=null;
            break;
        }
        nativePair=nativePair.parentElement;
    }

    if(nativePair&&nativePair.parentNode){
        /*
         * Replace the WHOLE native two-column row. ERP-10.31.44 inserted our
         * redesign inside one native grid cell, leaving the old right column
         * and old line cards alive. Replacing the row removes both native
         * columns and lets the approved two-column design own the full width.
         */
        nativePair.replaceWith(composite);
    }else{
        const oldLineCard=nearestCard(oldLineSub);
        const oldPassengerCard=nearestCard(oldPassengerSub);
        const accountingPreview=leafByText('Accounting Preview');
        const accountingCard=nearestCard(accountingPreview);

        // Conservative fallback: insert full-width before Accounting Preview,
        // then hide the two known native section cards individually.
        if(accountingCard&&accountingCard.parentNode){
            accountingCard.parentNode.insertBefore(composite,accountingCard);
        }else if(oldLineCard&&oldLineCard.parentNode){
            oldLineCard.parentNode.insertBefore(composite,oldLineCard);
        }

        if(oldLineCard)oldLineCard.style.display='none';
        if(oldPassengerCard)oldPassengerCard.style.display='none';
    }

    document.body.appendChild(modal);

    const viewAll=document.getElementById('et-gu-view-all-passengers');
    const close=document.getElementById('et-gu-passenger-modal-close');
    const search=document.getElementById('et-gu-passenger-search');

    const openModal=()=>{
        modal.classList.add('is-open');
        document.body.style.overflow='hidden';
        setTimeout(()=>search?.focus(),0);
    };

    const closeModal=()=>{
        modal.classList.remove('is-open');
        document.body.style.overflow='';
        if(search){
            search.value='';
            modal.querySelectorAll('.et-gu-modal-row').forEach(row=>{
                row.style.display='';
            });
        }
    };

    viewAll?.addEventListener('click',openModal);
    close?.addEventListener('click',closeModal);

    modal.addEventListener('click',event=>{
        if(event.target===modal)closeModal();
    });

    document.addEventListener('keydown',event=>{
        if(event.key==='Escape'&&modal.classList.contains('is-open')){
            closeModal();
        }
    });

    search?.addEventListener('input',()=>{
        const term=search.value.trim().toLowerCase();
        modal.querySelectorAll('.et-gu-modal-row').forEach(row=>{
            const haystack=String(row.dataset.passengerSearch||'');
            row.style.display=(!term||haystack.includes(term))?'':'none';
        });
    });
})();
</script>
HTML;

        return str_replace('__DATA__',$json,$template);
    }
}
