<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PresentBookingRegisterInvoiceVisibility
{
    public function handle(Request $request, Closure $next): Response
    {
        $response=$next($request);
        if(!method_exists($response,'getContent')||!method_exists($response,'setContent')) return $response;
        $html=(string)$response->getContent();
        if($html===''||stripos($html,'</body>')===false||str_contains($html,'data-et-invoice-register="1"')) return $response;
        $script=<<<'HTML'
<script data-et-invoice-register="1">
(function(){'use strict';
var links=[].slice.call(document.querySelectorAll('a[href*="/operations/bookings/"]'));
var seen={};links.forEach(function(link){var m=String(link.getAttribute('href')||'').match(/\/operations\/bookings\/(\d+)(?:\/|$)/);if(!m||seen[m[1]])return;seen[m[1]]=1;
fetch('/system/erp-bookings/'+m[1]+'/invoice-summary',{credentials:'same-origin',headers:{Accept:'application/json'}}).then(function(r){return r.ok?r.json():null}).then(function(d){if(!d||!d.ok)return;var badge=document.createElement(d.invoice?'a':'span');badge.style.cssText='display:inline-block;margin-left:6px;padding:2px 6px;border-radius:999px;background:#eef5ff;color:#245b91;font-size:9px;font-weight:700;white-space:nowrap';badge.textContent=d.invoice?String(d.invoice.number||('#'+d.invoice.id))+' · '+String(d.invoice.status||'Draft').replace(/_/g,' '):'Invoice: Not Created';if(d.invoice){badge.href=d.url;badge.target='_blank';badge.rel='noopener noreferrer';}link.parentNode.appendChild(badge);}).catch(function(){});
});})();
</script>
HTML;
        $response->setContent(preg_replace('/<\/body>/i',$script."\n</body>",$html,1)??$html);
        return $response;
    }
}
