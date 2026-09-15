<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Sales Invoice focused workspace marker + drawer interaction.
 *
 * Outer geometry is owned by the professional shell CSS bundle. This
 * middleware only establishes the semantic html state before first paint and
 * adds the compact Menu interaction used on Sales Invoice show pages.
 */
class PresentSalesInvoiceFocusedWorkspace
{
    public function handle(Request $request, Closure $next): BaseResponse
    {
        $response = $next($request);

        if (
            ! $response instanceof Response
            || $response->getStatusCode() >= 400
            || ! str_contains(strtolower((string) $response->headers->get('content-type')), 'text/html')
        ) {
            return $response;
        }

        $html = $response->getContent();
        if (! is_string($html) || $html === '') {
            return $response;
        }

        if (str_contains($html, 'data-et-sales-invoice-focus-script="ERP-11.3.60"')) {
            return $response;
        }

        $html = $this->markHtml($html);

        $script = <<<'HTML'
<script data-et-sales-invoice-focus-script="ERP-11.3.60">
(function(){
'use strict';
var root=document.documentElement;
if(root.dataset.etSalesInvoiceFocusInit==='ERP-11.3.60') return;
root.dataset.etSalesInvoiceFocusInit='ERP-11.3.60';

var norm=function(v){return String(v||'').replace(/\s+/g,' ').trim().toLowerCase();};
var sidebar=document.querySelector('.app-shell > .sidebar,body > .sidebar,.navbar-vertical,.side-nav');
if(!sidebar) return;

sidebar.setAttribute('data-et-sales-invoice-sidebar','ERP-11.3.60');

var overlay=document.querySelector('[data-et-sales-invoice-overlay="ERP-11.3.60"]');
if(!overlay){
    overlay=document.createElement('div');
    overlay.className='et-sales-invoice-overlay';
    overlay.setAttribute('data-et-sales-invoice-overlay','ERP-11.3.60');
    document.body.appendChild(overlay);
}

var closeMenu=function(){
    sidebar.classList.remove('et-sales-invoice-sidebar-open');
    overlay.classList.remove('open');
};
var openMenu=function(){
    sidebar.classList.add('et-sales-invoice-sidebar-open');
    overlay.classList.add('open');
};

var actions=Array.prototype.slice.call(document.querySelectorAll('a[href],button,[role="button"]'))
    .filter(function(el){return !sidebar.contains(el);});
var registerAction=actions.find(function(el){
    var t=norm(el.textContent);
    return t==='register'||t==='invoice register'||t==='sales invoice register'||t==='back to register';
})||null;
var bookingAction=actions.find(function(el){
    var t=norm(el.textContent);
    return t==='booking'||t==='back to booking'||t==='open booking';
})||null;
var anchor=registerAction||bookingAction;

if(anchor&&anchor.parentNode&&!anchor.parentNode.querySelector('[data-et-sales-invoice-menu]')){
    var menu=document.createElement('button');
    menu.type='button';
    menu.className='et-sales-invoice-menu-button';
    menu.setAttribute('data-et-sales-invoice-menu','ERP-11.3.60');
    menu.textContent='☰ Menu';
    menu.addEventListener('click',openMenu);
    anchor.parentNode.insertBefore(menu,anchor);
}

overlay.addEventListener('click',closeMenu);
document.addEventListener('keydown',function(event){if(event.key==='Escape') closeMenu();});
})();
</script>
HTML;

        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $script."\n</body>", $html, 1) ?? $html;
        } else {
            $html .= $script;
        }

        $response->setContent($html);

        return $response;
    }

    private function markHtml(string $html): string
    {
        return preg_replace_callback(
            '/<html\b([^>]*)>/i',
            static function (array $match): string {
                $attrs = $match[1];

                if (preg_match('/\bclass=(["\'])(.*?)\1/i', $attrs, $classMatch)) {
                    $classes = preg_split('/\s+/', trim((string) $classMatch[2])) ?: [];
                    if (! in_array('et-sales-invoice-focus-prepaint', $classes, true)) {
                        $classes[] = 'et-sales-invoice-focus-prepaint';
                    }
                    $replacement = 'class='.$classMatch[1].implode(' ', array_filter($classes)).$classMatch[1];

                    return '<html'.preg_replace('/\bclass=(["\'])(.*?)\1/i', $replacement, $attrs, 1).'>';
                }

                return '<html'.$attrs.' class="et-sales-invoice-focus-prepaint">';
            },
            $html,
            1
        ) ?? $html;
    }
}
