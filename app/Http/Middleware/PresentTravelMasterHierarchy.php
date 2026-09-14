<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** One deterministic, presentation-only Travel Masters hierarchy authority. */
final class PresentTravelMasterHierarchy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) return $response;
        $type = strtolower((string) $response->headers->get('content-type', ''));
        if ($type !== '' && ! str_contains($type, 'text/html')) return $response;
        $html = (string) $response->getContent();
        $lower = strtolower(strip_tags($html));
        if (! str_contains($lower, 'travel masters') || str_contains($html, 'data-et-travel-master-hierarchy="113279"')) return $response;
        $style = '<style id="et-travel-master-hierarchy-style-113279">.et-tm-child-nav-113279{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}.et-tm-child-nav-113279 a{padding:7px 11px;border:1px solid #cbd5e1;border-radius:7px;text-decoration:none}.et-tm-child-nav-113279 a.active{background:#e8f0ff;border-color:#2563eb}.et-tm-hotel-address-113279{max-width:220px!important;width:220px!important}.et-tm-hotel-final-row-113279,.et-tm-hotel-actions-113279{display:flex;flex-wrap:wrap;gap:8px;align-items:center}</style>';
        $script = <<<'HTML'
<script data-et-travel-master-hierarchy="113279">(()=>{const n=v=>String(v||'').replace(/\s+/g,' ').trim().toLowerCase(),href=a=>a?.getAttribute('href')||'',tab=a=>{try{return new URL(href(a),location.href).searchParams.get('tab')||''}catch(e){return''}},find=t=>[...document.querySelectorAll('a')].find(a=>n(a.textContent)===n(t)),labels=['Overview','Hotels','Transport Companies','Visa Management','Airlines','Booking Sources','Products & Services'],children={transport:['Transport Companies','Vehicle Types','Transport Routes','Transport Vendor Rates'],visa:['Saudi Visa Companies','Pakistan Visa / IATA','Visa Rates'],airlines:['Airlines','Flight Routes']};const params=new URLSearchParams(location.search),legacy=params.get('tab')==='airline-codes';if(legacy){params.set('tab','airlines');history.replaceState(null,'','?'+params.toString())}let visa=find('visa management');if(!visa){visa=document.createElement('a');visa.href='/master-data/travel-masters/visa-management?tab=rates';visa.textContent='Visa Management';visa.className='et-tm-generated-visa-113279';(document.querySelector('nav')||document.body).appendChild(visa)}const links=labels.map(find).filter(Boolean),nav=links[0]?.parentElement;if(nav)links.forEach(a=>nav.appendChild(a));const familyTabs=key=>children[key].map(find).filter(Boolean).map(tab),current=params.get('tab')||'',path=location.pathname,family=path.endsWith('/flight-routes')||familyTabs('airlines').includes(current)?'airlines':path.endsWith('/visa-management')||familyTabs('visa').includes(current)?'visa':familyTabs('transport').includes(current)?'transport':null;const make=key=>{if(key!==family)return;const holder=document.createElement('nav');holder.className='et-tm-child-nav-113279';holder.dataset.etTravelMasterChild=key;children[key].forEach(label=>{const source=find(label),child=source?source.cloneNode(true):document.createElement('a');if(!source&&key==='airlines'&&label==='Flight Routes')child.href='/master-data/travel-masters/flight-routes';child.textContent=label;holder.appendChild(child)});(nav||document.body).insertAdjacentElement('afterend',holder)};make('transport');make('visa');make('airlines');const old=find('airline codes');if(old)old.style.display='none';/* Airline Codes remains compatible but hidden from primary navigation. */document.querySelectorAll('input,textarea').forEach(x=>{if(n(x.getAttribute('name')).includes('address'))x.classList.add('et-tm-hotel-address-113279')});const finalizeHotel=()=>{const nodes=()=>[...document.querySelectorAll('a,button')],add=nodes().find(x=>n(x.textContent)==='add hotel'),bulk=nodes().find(x=>n(x.textContent)==='bulk import csv'),download=nodes().find(x=>n(x.textContent)==='download template'),active=[...document.querySelectorAll('input,button')].find(x=>n(x.getAttribute('name'))==='active'||n(x.textContent)==='active');if(!add||!bulk||!download||document.querySelector('.et-tm-hotel-final-row-113279'))return false;const row=document.createElement('div');row.className='et-tm-hotel-final-row-113279 et-tm-hotel-actions-113279';const form=add.closest('form');(form||add.parentElement||document.body).appendChild(row);[active,add,bulk,download].filter(Boolean).forEach(x=>row.appendChild(x));return true};if(!finalizeHotel()&&window.MutationObserver){const observer=new MutationObserver(()=>{if(finalizeHotel())observer.disconnect()});observer.observe(document.body,{childList:true,subtree:true})}})();</script>
HTML;
        $html = str_contains($html, '</head>') ? str_replace('</head>', $style.'</head>', $html) : $style.$html;
        $html = str_contains($html, '</body>') ? str_replace('</body>', $script.'</body>', $html) : $html.$script;
        $response->setContent($html); return $response;
    }
}
