<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PresentTravelMasterHotelBulkImport
{
    public function handle(Request $request, Closure $next): Response
    {
        $response=$next($request); if(!method_exists($response,'getContent')||!method_exists($response,'setContent'))return $response; $type=strtolower((string)$response->headers->get('content-type','')); if($type!==''&&!str_contains($type,'text/html'))return $response; $html=(string)$response->getContent();$lower=strtolower(strip_tags($html)); if(!str_contains($lower,'travel masters')||!str_contains($lower,'hotels')||!str_contains($lower,'add hotel')||str_contains($html,'data-et-hotel-bulk-import="113272"'))return $response;
        $template=route('travel-masters.hotels.bulk-template');$preview=route('travel-masters.hotels.bulk-preview');$import=route('travel-masters.hotels.bulk-import');$json=json_encode(compact('template','preview','import'),JSON_UNESCAPED_SLASHES);
        $style='<style id="et-hotel-bulk-import-style-113272">.et-hotel-bulk-import-113272{display:inline-flex;gap:8px;align-items:center;margin-left:8px}.et-hotel-bulk-import-113272 button{border:1px solid #cbd5e1;border-radius:6px;padding:7px 10px;background:#fff;color:#17396c;cursor:pointer}</style>';
        $script=<<<HTML
<script id="et-hotel-bulk-import-script-113272">(()=>{if(document.querySelector('[data-et-hotel-bulk-import="113272"]'))return;const u={$json};const n=s=>String(s||'').replace(/\s+/g,' ').trim().toLowerCase();const a=[...document.querySelectorAll('a,button')].find(x=>n(x.textContent)==='add hotel');if(!a)return;const w=document.createElement('span');w.className='et-hotel-bulk-import-113272';w.dataset.etHotelBulkImport='113272';const b=document.createElement('button');b.type='button';b.textContent='Bulk Import CSV';const d=document.createElement('a');d.href=u.template;d.textContent='Download Template';d.className='pm262-btn';w.append(b,d);a.insertAdjacentElement('afterend',w);b.onclick=()=>{const csv=prompt('Paste CSV (City,Hotel Name)');if(!csv)return;fetch(u.preview,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||''},body:JSON.stringify({csv})}).then(r=>r.json()).then(x=>alert(JSON.stringify(x.summary||x))).catch(()=>alert('Preview failed'));};})();</script>
HTML;
        $html=str_contains($html,'</head>')?str_replace('</head>',$style.'</head>',$html):$style.$html;$html=str_contains($html,'</body>')?str_replace('</body>',$script.'</body>',$html):$html.$script;$response->setContent($html);return $response;
    }
}
