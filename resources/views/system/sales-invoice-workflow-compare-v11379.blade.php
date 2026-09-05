@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','AIR vs GENERAL Invoice Workflow Compare')
@section($layoutMeta['content_section'] ?? 'content')
<style>
.sic{max-width:1500px;margin:0 auto;color:#17243a}.sic *{box-sizing:border-box}
.sic-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:14px}
.sic-kicker{font-size:10px;font-weight:900;color:#0a63d8;text-transform:uppercase;letter-spacing:.05em}
.sic h1{font-size:26px;margin:4px 0}.sic-sub{font-size:12px;color:#6e8095}
.sic-safe{padding:9px 11px;border:1px solid #bfe2cf;background:#effbf4;border-radius:8px;color:#176143;font-size:10px;font-weight:900}
.sic-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.sic-card{background:#fff;border:1px solid #dce5ef;border-radius:10px;overflow:hidden;margin-bottom:14px}
.sic-card h3{font-size:13px;margin:0;padding:11px 13px;border-bottom:1px solid #e8edf3}
.sic-body{padding:12px 13px}.sic-kv{display:grid;grid-template-columns:170px 1fr;gap:7px;font-size:10.5px;margin-bottom:6px}
.sic-kv b{color:#5a6c82}.sic-air{border-top:3px solid #c45a5a}.sic-general{border-top:3px solid #2c8a5b}
.sic-pass{color:#167a50;font-weight:900}.sic-fail{color:#b4434c;font-weight:900}.sic-warn{color:#9a6810;font-weight:900}
.sic-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:9px;background:#f7f9fc;border:1px solid #e4e9f0;border-radius:6px;padding:8px;white-space:pre-wrap;word-break:break-word;overflow:auto}
.sic-table{width:100%;border-collapse:collapse;table-layout:auto}.sic-table th,.sic-table td{padding:7px 8px;border-bottom:1px solid #edf1f5;text-align:left;vertical-align:top;font-size:9px;overflow-wrap:anywhere}
.sic-table th{background:#f8fafc;color:#53647a;text-transform:uppercase;font-size:8px;letter-spacing:.02em}
.sic-diff{background:#fff5e8!important}.sic-match{background:#f2fbf6!important}
.sic-probe{border:1px solid #dce5ef;border-radius:8px;padding:10px;background:#fbfcfe}
.sic-frame{position:absolute;left:-99999px;top:-99999px;width:1px;height:1px;border:0}
@media(max-width:950px){.sic-grid{grid-template-columns:1fr}.sic-kv{grid-template-columns:1fr}}
</style>

<div class="sic" data-et-invoice-compare="ERP-11.3.79">
  <div class="sic-head">
    <div>
      <div class="sic-kicker">System Diagnostic · ERP-11.3.79</div>
      <h1>AIR ONLY vs GENERAL — Sales Invoice Workflow</h1>
      <div class="sic-sub">Read-only comparison of the failing AIR invoice against the working GENERAL invoice.</div>
    </div>
    <div class="sic-safe">READ ONLY · DATABASE WRITES = 0</div>
  </div>

  <div class="sic-grid">
    @foreach([['data'=>$air,'class'=>'sic-air','title'=>'AIR ONLY · FAILING'],['data'=>$general,'class'=>'sic-general','title'=>'GENERAL · WORKING']] as $box)
      @php($s=$box['data'])
      <section class="sic-card {{ $box['class'] }}">
        <h3>{{ $box['title'] }}</h3>
        <div class="sic-body">
          @if(!($s['found']??false))
            <div class="sic-fail">Invoice not found.</div>
          @else
            <div class="sic-kv"><b>Invoice</b><span>#{{ $s['id'] }} · {{ $s['number'] ?: '—' }}</span></div>
            <div class="sic-kv"><b>Expected Booking Type</b><span>{{ $s['expected_type'] }}</span></div>
            <div class="sic-kv"><b>Booking ID</b><span>{{ $s['booking_id'] ?: '—' }}</span></div>
            <div class="sic-kv"><b>Model / Table</b><span>{{ $s['model'] }} · {{ $s['table'] }}</span></div>
            <div class="sic-kv"><b>Invoice URL</b><span>{{ $s['url'] }}</span></div>
            <div class="sic-kv"><b>Booking URL</b><span>{{ $s['booking_url'] ?: '—' }}</span></div>
          @endif
        </div>
      </section>
    @endforeach
  </div>

  @if(($air['found']??false)&&($general['found']??false))
    <section class="sic-card">
      <h3>Native Workflow / Status Fields — Side by Side</h3>
      <table class="sic-table">
        <thead><tr><th>Field</th><th>AIR ONLY #{{ $air['id'] }}</th><th>GENERAL #{{ $general['id'] }}</th><th>Compare</th></tr></thead>
        <tbody>
        @php($workflowKeys=array_values(array_unique(array_merge(array_keys($air['workflow_fields']),array_keys($general['workflow_fields'])))))
        @sort($workflowKeys)
        @forelse($workflowKeys as $field)
          @php($av=$air['workflow_fields'][$field]??null)
          @php($gv=$general['workflow_fields'][$field]??null)
          @php($same=(string)$av===(string)$gv)
          <tr class="{{ $same?'sic-match':'sic-diff' }}">
            <td><strong>{{ $field }}</strong></td>
            <td>{{ $av===null?'NULL':$av }}</td>
            <td>{{ $gv===null?'NULL':$gv }}</td>
            <td class="{{ $same?'sic-pass':'sic-warn' }}">{{ $same?'MATCH':'DIFFERENT' }}</td>
          </tr>
        @empty
          <tr><td colspan="4">No workflow fields discovered.</td></tr>
        @endforelse
        </tbody>
      </table>
    </section>

    <section class="sic-card">
      <h3>Booking / Source Identity Fields — Side by Side</h3>
      <table class="sic-table">
        <thead><tr><th>Field</th><th>AIR ONLY</th><th>GENERAL</th><th>Compare</th></tr></thead>
        <tbody>
        @php($identityKeys=array_values(array_unique(array_merge(array_keys($air['identity_fields']),array_keys($general['identity_fields'])))))
        @sort($identityKeys)
        @foreach($identityKeys as $field)
          @php($av=$air['identity_fields'][$field]??null)
          @php($gv=$general['identity_fields'][$field]??null)
          @php($same=(string)$av===(string)$gv)
          <tr class="{{ $same?'':'sic-diff' }}">
            <td><strong>{{ $field }}</strong></td><td>{{ $av===null?'NULL':$av }}</td><td>{{ $gv===null?'NULL':$gv }}</td>
            <td class="{{ $same?'sic-pass':'sic-warn' }}">{{ $same?'MATCH':'DIFFERENT' }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </section>

    <section class="sic-card">
      <h3>Commercial / Amount Fields — Side by Side</h3>
      <table class="sic-table">
        <thead><tr><th>Field</th><th>AIR ONLY</th><th>GENERAL</th><th>Compare</th></tr></thead>
        <tbody>
        @php($commercialKeys=array_values(array_unique(array_merge(array_keys($air['commercial_fields']),array_keys($general['commercial_fields'])))))
        @sort($commercialKeys)
        @foreach($commercialKeys as $field)
          @php($av=$air['commercial_fields'][$field]??null)
          @php($gv=$general['commercial_fields'][$field]??null)
          @php($same=(string)$av===(string)$gv)
          <tr class="{{ $same?'':'sic-diff' }}">
            <td><strong>{{ $field }}</strong></td><td>{{ $av===null?'NULL':$av }}</td><td>{{ $gv===null?'NULL':$gv }}</td>
            <td class="{{ $same?'sic-pass':'sic-warn' }}">{{ $same?'MATCH':'DIFFERENT' }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </section>

    <section class="sic-card">
      <h3>Actual Browser Workflow Forms — Most Important Comparison</h3>
      <div class="sic-body">
        <div class="sic-grid">
          <div class="sic-probe">
            <strong>AIR ONLY #{{ $air['id'] }}</strong>
            <div id="sic-air-probe" class="sic-code">Loading actual AIR invoice forms…</div>
          </div>
          <div class="sic-probe">
            <strong>GENERAL #{{ $general['id'] }}</strong>
            <div id="sic-general-probe" class="sic-code">Loading actual GENERAL invoice forms…</div>
          </div>
        </div>
        <div class="sic-probe">
          <strong>Automatic Form Difference</strong>
          <div id="sic-form-diff" class="sic-code">Waiting for both invoice pages…</div>
        </div>
        <iframe id="sic-air-frame" class="sic-frame" src="{{ $air['url'] }}?et_workflow_compare=air" title="AIR invoice probe"></iframe>
        <iframe id="sic-general-frame" class="sic-frame" src="{{ $general['url'] }}?et_workflow_compare=general" title="GENERAL invoice probe"></iframe>
      </div>
    </section>
  @endif

  <section class="sic-card">
    <h3>Registered Invoice Workflow Routes — Final Live Route Table</h3>
    <table class="sic-table">
      <thead><tr><th>Name</th><th>Methods</th><th>URI</th><th>Action</th><th>Middleware</th></tr></thead>
      <tbody>
      @forelse($workflowRoutes as $r)
        <tr><td>{{ $r['name'] ?: '—' }}</td><td>{{ $r['methods'] }}</td><td><strong>{{ $r['uri'] }}</strong></td><td>{{ $r['action'] }}</td><td>{{ $r['middleware'] ?: '—' }}</td></tr>
      @empty
        <tr><td colspan="5">No workflow routes discovered.</td></tr>
      @endforelse
      </tbody>
    </table>
  </section>

  <section class="sic-card">
    <h3>Native Sales Invoice Service / Controller Methods</h3>
    <table class="sic-table">
      <thead><tr><th>Class</th><th>Exists</th><th>Public Method Signature</th><th>Source</th></tr></thead>
      <tbody>
      @foreach($nativeClasses as $class)
        @if($class['methods'])
          @foreach($class['methods'] as $i=>$m)
            <tr>
              @if($i===0)
                <td rowspan="{{ count($class['methods']) }}"><strong>{{ $class['class'] }}</strong></td>
                <td rowspan="{{ count($class['methods']) }}" class="{{ $class['exists']?'sic-pass':'sic-fail' }}">{{ $class['exists']?'YES':'NO' }}</td>
              @endif
              <td>{{ $m['signature'] }}</td>
              <td>{{ $m['file'] ?: '—' }}{{ $m['line']?':'.$m['line']:'' }}</td>
            </tr>
          @endforeach
        @else
          <tr><td><strong>{{ $class['class'] }}</strong></td><td class="{{ $class['exists']?'sic-pass':'sic-fail' }}">{{ $class['exists']?'YES':'NO' }}</td><td>—</td><td>—</td></tr>
        @endif
      @endforeach
      </tbody>
    </table>
  </section>
</div>

<script>
(function(){
'use strict';

const states={air:null,general:null};
const norm=v=>String(v||'').replace(/\s+/g,' ').trim();

function workflowForms(doc){
    return Array.from(doc.querySelectorAll('form')).map((form,index)=>{
        const buttons=Array.from(
            form.querySelectorAll('button,input[type="submit"]')
        ).map(btn=>norm(btn.textContent||btn.value)).filter(Boolean);

        const fields=Array.from(
            form.querySelectorAll('input[name],select[name],textarea[name]')
        ).map(field=>{
            let value='';
            const type=String(field.type||'').toLowerCase();

            if(field.name==='_token'){
                value='[csrf]';
            }else if(
                type==='hidden'
                || type==='submit'
                || field.name==='_method'
                || /action|status|workflow|submit|approve|post/i.test(field.name)
            ){
                value=String(field.value||'');
            }

            return {
                name:field.name,
                type:type||field.tagName.toLowerCase(),
                value:value
            };
        });

        const method=String(form.method||'GET').toUpperCase();
        const spoof=form.querySelector('input[name="_method"]');

        return {
            index:index+1,
            action:form.action||'',
            method:method,
            spoof_method:spoof?String(spoof.value||'').toUpperCase():'',
            buttons:buttons,
            fields:fields
        };
    }).filter(row=>{
        const haystack=JSON.stringify(row).toLowerCase();
        return haystack.includes('submit')
            || haystack.includes('approv')
            || haystack.includes('post')
            || haystack.includes('workflow');
    });
}

function compare(){
    if(!states.air||!states.general)return;

    const a=states.air;
    const g=states.general;
    const out=[];

    out.push('AIR forms: '+a.length);
    out.push('GENERAL forms: '+g.length);

    const max=Math.max(a.length,g.length);

    for(let i=0;i<max;i++){
        const ar=a[i]||null;
        const gr=g[i]||null;
        out.push('');
        out.push('FORM '+String(i+1));

        if(!ar||!gr){
            out.push('DIFFERENCE: form exists on only one invoice.');
            out.push('AIR: '+JSON.stringify(ar));
            out.push('GENERAL: '+JSON.stringify(gr));
            continue;
        }

        [
            ['action',ar.action,gr.action],
            ['method',ar.method,gr.method],
            ['spoof_method',ar.spoof_method,gr.spoof_method],
            ['buttons',JSON.stringify(ar.buttons),JSON.stringify(gr.buttons)],
            ['fields',JSON.stringify(ar.fields),JSON.stringify(gr.fields)]
        ].forEach(([label,av,gv])=>{
            out.push(
                label+': '
                +(av===gv?'MATCH':'DIFFERENT')
                +'\n  AIR     = '+av
                +'\n  GENERAL = '+gv
            );
        });
    }

    document.getElementById('sic-form-diff').textContent=out.join('\n');
}

function bind(kind){
    const frame=document.getElementById('sic-'+kind+'-frame');
    const out=document.getElementById('sic-'+kind+'-probe');

    if(!frame||!out)return;

    frame.addEventListener('load',()=>{
        try{
            const doc=frame.contentDocument||frame.contentWindow.document;
            const forms=workflowForms(doc);
            states[kind]=forms;
            out.textContent=forms.length
                ? JSON.stringify(forms,null,2)
                : 'No Submit / Approve / Post workflow form found.';
            compare();
        }catch(error){
            out.textContent='Probe failed: '+String(error&&error.message||error);
        }
    });
}

bind('air');
bind('general');
})();
</script>
@endsection
