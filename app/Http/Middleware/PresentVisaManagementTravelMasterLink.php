<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-11.3.152
 *
 * Adds one Visa Management entry to the NATIVE Travel Masters tab strip.
 * Detection is response-content based so it works even when the installed
 * Travel Masters URI/name differs between Easy Ticket ERP baselines.
 */
final class PresentVisaManagementTravelMasterLink
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            return $response;
        }
        $type = strtolower((string) $response->headers->get('content-type', ''));
        if ($type !== '' && ! str_contains($type, 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();
        if ($html === '' || str_contains($html, 'data-et-visa-management-entry="113152"')) {
            return $response;
        }

        $lower = strtolower(strip_tags($html));
        $isNativeTravelMasters = str_contains($lower, 'travel masters')
            && (str_contains($lower, 'saudi visa companies') || str_contains($lower, 'pakistan visa / iata'))
            && str_contains($lower, 'transport vendor rates');
        if (! $isNativeTravelMasters) {
            return $response;
        }

        $url = route('travel-masters.visa-management', ['tab' => 'rates']);
        $urlJson = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $style = <<<'HTML'
<style id="et-visa-management-entry-113152-style">
.et-visa-management-tab-113152{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-height:34px!important;padding:7px 12px!important;border:1px solid #ccd7e4!important;border-radius:8px!important;background:#14223a!important;color:#fff!important;text-decoration:none!important;font-size:12px!important;font-weight:800!important;white-space:nowrap!important;box-shadow:none!important}
.et-visa-management-tab-113152:hover{background:#0e1b30!important;color:#fff!important;text-decoration:none!important}
</style>
HTML;
        $script = <<<HTML
<script id="et-visa-management-entry-script-113152">
(function(){
'use strict';
if(document.querySelector('[data-et-visa-management-entry="113152"]'))return;
const norm=v=>String(v||'').replace(/\s+/g,' ').trim().toLowerCase();
const items=Array.from(document.querySelectorAll('a,button'));
const transport=items.find(el=>norm(el.textContent)==='transport vendor rates');
const saudi=items.find(el=>norm(el.textContent)==='saudi visa companies');
const pakistan=items.find(el=>norm(el.textContent)==='pakistan visa / iata');
const anchor=saudi||pakistan||transport;
if(!anchor||!anchor.parentElement)return;
const link=document.createElement('a');
link.href={$urlJson};
link.className='et-visa-management-tab-113152';
link.setAttribute('data-et-visa-management-entry','113152');
link.textContent='Visa Management';
link.title='Existing Pakistani IATA + Saudi Companies + Visa Rates';
if(saudi){saudi.insertAdjacentElement('afterend',link);}else if(pakistan){pakistan.insertAdjacentElement('afterend',link);}else{transport.insertAdjacentElement('afterend',link);}
})();
</script>
HTML;

        $html = str_contains($html, '</head>') ? str_replace('</head>', $style . '</head>', $html) : $style . $html;
        $html = str_contains($html, '</body>') ? str_replace('</body>', $script . '</body>', $html) : $html . $script;
        $response->setContent($html);
        return $response;
    }
}
