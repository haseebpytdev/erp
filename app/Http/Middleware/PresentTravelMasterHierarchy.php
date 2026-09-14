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
        if (! str_contains($lower, 'travel masters') || str_contains($html, 'data-et-travel-master-hierarchy="113277"')) return $response;
        $style = '<style id="et-travel-master-hierarchy-style-113277">.et-tm-child-nav-113277{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0}.et-tm-child-nav-113277 a{padding:7px 11px;border:1px solid #cbd5e1;border-radius:7px;text-decoration:none}.et-tm-child-nav-113277 a.active{background:#e8f0ff;border-color:#2563eb}.et-tm-hotel-address-113277{max-width:220px!important;width:220px!important}.et-tm-hotel-actions-113277{display:flex;flex-wrap:wrap;gap:8px;align-items:center}</style>';
        $script = <<<'HTML'
<script data-et-travel-master-hierarchy="113277">(()=>{const n=v=>String(v||'').replace(/\s+/g,' ').trim().toLowerCase(),all=[...document.querySelectorAll('a')],find=t=>all.find(a=>n(a.textContent)===t),labels=['Overview','Hotels','Transport Companies','Visa Management','Airlines','Booking Sources','Products & Services'],children={transport:['Transport Companies','Vehicle Types','Transport Routes','Transport Vendor Rates'],visa:['Saudi Visa Companies','Pakistan Visa / IATA','Visa Rates'],airlines:['Airlines','Flight Routes']};const params=new URLSearchParams(location.search);if(params.get('tab')==='airline-codes'){params.set('tab','airlines');history.replaceState(null,'','?'+params.toString());}const links=labels.map(find).filter(Boolean);const nav=links[0]?.parentElement;if(nav&&links.length){links.forEach(a=>nav.appendChild(a));const unwanted=['Vehicle Types','Transport Routes','Transport Vendor Rates','Saudi Visa Companies','Pakistan Visa / IATA','Airline Codes'];[...nav.querySelectorAll('a')].forEach(a=>{if(unwanted.includes(a.textContent.trim()))a.style.display='none';});}const make=(key)=>{const found=children[key].map(find).filter(Boolean);if(!found.length)return;const holder=document.createElement('nav');holder.className='et-tm-child-nav-113277';holder.dataset.etTravelMasterChild=key;found.forEach(a=>holder.appendChild(a));(nav||document.body).insertAdjacentElement('afterend',holder);};make('transport');make('visa');make('airlines');const old=all.find(a=>n(a.textContent)==='airline codes');if(old)old.style.display='none';document.querySelectorAll('input,textarea').forEach(x=>{if(n(x.getAttribute('name')).includes('address'))x.classList.add('et-tm-hotel-address-113277');});})();</script>
HTML;
        $html = str_contains($html, '</head>') ? str_replace('</head>', $style.'</head>', $html) : $style.$html;
        $html = str_contains($html, '</body>') ? str_replace('</body>', $script.'</body>', $html) : $html.$script;
        $response->setContent($html); return $response;
    }
}
