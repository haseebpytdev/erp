<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PresentTravelMasterHierarchy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) return $response;
        $type = strtolower((string) $response->headers->get('content-type', ''));
        if ($type !== '' && ! str_contains($type, 'text/html')) return $response;
        $html = (string) $response->getContent();
        if ($request->path() !== 'master-data/travel-masters' || str_contains($html, 'data-et-travel-master-hierarchy="113287"')) return $response;

        $style = '<style>.et-tm-primary-113287,.et-tm-child-nav-113287{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}.et-tm-primary-113287 a,.et-tm-child-nav-113287 a{padding:7px 11px;border:1px solid #cbd5e1;border-radius:7px;text-decoration:none;background:#fff;color:#13233b}.et-tm-active-113287{background:#13233b!important;border-color:#13233b!important;color:#fff!important}</style>';
        $script = <<<'HTML'
<script data-et-travel-master-hierarchy="113287">
(()=>{
 const textNorm=value=>String(value||'').replace(/\s+/g,' ').trim().toLowerCase();
 const controlNorm=value=>String(value||'').replace(/[^a-z0-9]+/gi,' ').replace(/\s+/g,' ').trim().toLowerCase();
 const labels=['Overview','Hotels','Transport Companies','Vehicle Types','Transport Routes','Transport Vendor Rates','Saudi Visa Companies','Pakistan Visa / IATA','Airlines','Airline Codes','Booking Sources','Products & Services'];
 const normalizedLabels=new Set(labels.map(textNorm));
 const tabOf=anchor=>{if(!anchor)return null;try{return new URL(anchor.href,location.href).searchParams.get('tab')||null}catch(e){return null}};
 const resolveTravelMasterState=(currentTab,tabs)=>{const current=currentTab||null;const transportTabs=[tabs.transportCompanies,tabs.vehicleTypes,tabs.transportRoutes,tabs.transportVendorRates].filter(Boolean);const airlineTabs=[tabs.airlines,tabs.airlineCodes].filter(Boolean);if(!current)return{effectiveCurrent:null,primaryActive:'Overview',family:null,transportActive:null,airlinesActive:null,normalizeLegacyAirlineCodes:false};if(current===tabs.airlineCodes&&tabs.airlines)return{effectiveCurrent:tabs.airlines,primaryActive:'Airlines',family:'airlines',transportActive:null,airlinesActive:'Airlines',normalizeLegacyAirlineCodes:true};if(transportTabs.includes(current))return{effectiveCurrent:current,primaryActive:'Transport Companies',family:'transport',transportActive:Object.entries({transportCompanies:'Transport Companies',vehicleTypes:'Vehicle Types',transportRoutes:'Transport Routes',transportVendorRates:'Transport Vendor Rates'}).find(([k])=>tabs[k]===current)?.[1]||null,airlinesActive:null,normalizeLegacyAirlineCodes:false};if(airlineTabs.includes(current))return{effectiveCurrent:current,primaryActive:'Airlines',family:'airlines',transportActive:null,airlinesActive:'Airlines',normalizeLegacyAirlineCodes:false};const p=Object.entries({hotels:'Hotels',bookingSources:'Booking Sources',productsServices:'Products & Services'}).find(([k])=>tabs[k]===current)?.[1]||'Overview';return{effectiveCurrent:current,primaryActive:p,family:null,transportActive:null,airlinesActive:null,normalizeLegacyAirlineCodes:false};};
 const discover=()=>{const anchors=[...document.querySelectorAll('a')].filter(anchor=>{try{const url=new URL(anchor.href,location.href);return url.pathname==='/master-data/travel-masters'&&normalizedLabels.has(textNorm(anchor.textContent))}catch(e){return false}});const overview=anchors.find(a=>textNorm(a.textContent)==='overview');let nativeStrip=null;for(let p=overview?.parentElement;p&&p!==document.body&&p!==document.documentElement;p=p.parentElement){if(anchors.filter(a=>p.contains(a)).length>=8){nativeStrip=p;break}}return{anchors,nativeStrip};};
 const normalizeKicker=()=>{for(const node of [...document.querySelectorAll('h1,h2,h3,h4,p,div,span')]){const value=textNorm(node.textContent);if(value.includes('erp-10.25.7')&&value.includes('transport master management'))node.textContent='TRAVEL MASTER MANAGEMENT';}};
 const tryFinalizeHierarchy=()=>{if(document.querySelector('[data-et-travel-master-primary="113287"]'))return true;const {anchors,nativeStrip}=discover();if(!nativeStrip)return false;const byLabel=label=>anchors.find(a=>textNorm(a.textContent)===textNorm(label));const tabs={hotels:tabOf(byLabel('Hotels')),transportCompanies:tabOf(byLabel('Transport Companies')),vehicleTypes:tabOf(byLabel('Vehicle Types')),transportRoutes:tabOf(byLabel('Transport Routes')),transportVendorRates:tabOf(byLabel('Transport Vendor Rates')),airlines:tabOf(byLabel('Airlines')),airlineCodes:tabOf(byLabel('Airline Codes')),bookingSources:tabOf(byLabel('Booking Sources')),productsServices:tabOf(byLabel('Products & Services'))};let current=new URL(location.href).searchParams.get('tab')||null;let state=resolveTravelMasterState(current,tabs);if(state.normalizeLegacyAirlineCodes){const url=new URL(location.href);url.searchParams.set('tab',state.effectiveCurrent);history.replaceState(null,'',url.pathname+url.search+url.hash);current=state.effectiveCurrent;state=resolveTravelMasterState(current,tabs)}nativeStrip.style.setProperty('display','none','important');const primary=document.createElement('nav');primary.className='et-tm-primary-113287';primary.dataset.etTravelMasterPrimary='113287';['Overview','Hotels','Transport Companies','Visa Management','Airlines','Booking Sources','Products & Services'].forEach(label=>{const source=byLabel(label),a=document.createElement('a');a.href=source?.href||(label==='Visa Management'?'/master-data/travel-masters/visa-management?tab=rates':'#');a.textContent=label;if(label===state.primaryActive){a.className='et-tm-active-113287';a.setAttribute('aria-current','page')}primary.appendChild(a)});nativeStrip.insertAdjacentElement('afterend',primary);const make=(key,items,active)=>{if(state.family!==key)return;const holder=document.createElement('nav');holder.className='et-tm-child-nav-113287';holder.dataset.etTravelMasterChild=key;items.forEach(label=>{const source=byLabel(label),a=document.createElement('a');a.href=source?.href||(label==='Flight Routes'?'/master-data/travel-masters/flight-routes':'#');a.textContent=label;if(label===active)a.className='et-tm-active-113287';holder.appendChild(a)});primary.insertAdjacentElement('afterend',holder)};make('transport',['Transport Companies','Vehicle Types','Transport Routes','Transport Vendor Rates'],state.transportActive);make('airlines',['Airlines','Flight Routes'],state.airlinesActive);normalizeKicker();return true;};
 const root=document.documentElement;let observer=null;let settled=false;const restore=()=>{if(settled)return;settled=true;if(observer)observer.disconnect();const native=discover().nativeStrip;if(native&&!document.querySelector('[data-et-travel-master-primary="113287"]'))native.style.removeProperty('display');};const succeed=()=>{if(settled)return;settled=true;if(observer)observer.disconnect()};observer=window.MutationObserver?new MutationObserver(()=>{if(tryFinalizeHierarchy())succeed()}):null;if(observer)observer.observe(root,{childList:true,subtree:true});if(tryFinalizeHierarchy())succeed();document.addEventListener('DOMContentLoaded',()=>{if(tryFinalizeHierarchy())succeed();normalizeKicker()},{once:true});setTimeout(()=>{if(!settled){restore();normalizeKicker()}},1800);
})();
</script>
HTML;
        $html = str_contains($html, '</head>') ? str_replace('</head>', $style.$script.'</head>', $html) : $style.$html.$script;
        $response->setContent($html);
        return $response;
    }
}
