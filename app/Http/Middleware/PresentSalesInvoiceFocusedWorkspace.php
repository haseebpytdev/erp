<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * ERP-11.3.60 — Sales Invoice focused workspace shell.
 *
 * The native Sales Invoice show route is the same page used after:
 * Draft -> Pending Approval -> Approved -> Posted.
 *
 * It must therefore retain one visual shell through every workflow transition:
 * no permanent left sidebar, centered full-width invoice canvas, native ERP
 * top header retained, and one compact Menu action beside Booking/Register.
 *
 * Sales Invoice Register/index keeps the normal permanent ERP sidebar.
 */
class PresentSalesInvoiceFocusedWorkspace
{
    public function handle(
        Request $request,
        Closure $next
    ): BaseResponse {
        $response = $next($request);

        if (
            ! $response instanceof Response
            || $response->getStatusCode() >= 400
            || ! str_contains(
                strtolower((string) $response->headers->get('content-type')),
                'text/html'
            )
        ) {
            return $response;
        }

        $html = $response->getContent();

        if (! is_string($html) || $html === '') {
            return $response;
        }

        if (str_contains(
            $html,
            'data-et-sales-invoice-focus="ERP-11.3.60"'
        )) {
            return $response;
        }

        $html = $this->markHtml($html);

        $injection = <<<'HTML'
<style data-et-sales-invoice-focus="ERP-11.3.60">
html.et-sales-invoice-focus-prepaint{
}
html.et-sales-invoice-focus-prepaint .app-shell{
    grid-template-columns:minmax(0,1fr)!important;
    width:100%!important;
    max-width:100%!important;
}
html.et-sales-invoice-focus-prepaint .app-shell > .sidebar:not(.et-sales-invoice-sidebar-open),
html.et-sales-invoice-focus-prepaint body > .sidebar:not(.et-sales-invoice-sidebar-open),
html.et-sales-invoice-focus-prepaint .sidebar:not(.et-sales-invoice-sidebar-open),
html.et-sales-invoice-focus-prepaint .navbar-vertical:not(.et-sales-invoice-sidebar-open),
html.et-sales-invoice-focus-prepaint .side-nav:not(.et-sales-invoice-sidebar-open){
    display:none!important;
}
html.et-sales-invoice-focus-prepaint main,
html.et-sales-invoice-focus-prepaint main.main,
html.et-sales-invoice-focus-prepaint .main,
html.et-sales-invoice-focus-prepaint .page-wrapper,
html.et-sales-invoice-focus-prepaint .content-wrapper{
    margin-left:0!important;
    width:100%!important;
    max-width:none!important;
    min-width:0!important;
}
/*
 * ERP-11.3.60 — use native ERP .content width exactly like booking products.
 *
 * Live Inspector proof at the same viewport:
 *   Product section.content = 1262.67px
 *   Invoice section.content = 1214.67px
 *   Difference              = 48px
 *
 * The 48px was introduced by the invoice focused shell:
 *   width: calc(100% - (24px * 2))
 *
 * Product pages do NOT need this override. Their native app.css .content rule
 * already provides the correct responsive canvas:
 *   padding: 28px;
 *   max-width: 1450px;
 *   margin: 0 auto;
 *
 * Therefore Sales Invoice section.content must not have any focused-shell
 * width/max-width/margin override. The native ERP CSS is authoritative.
 */

/*
 * Keep only immediate inner native invoice containers from adding an unrelated
 * Bootstrap-style max-width inside the already-correct native .content canvas.
 * Do not touch section.content itself.
 */
html.et-sales-invoice-focus-prepaint section.content > .container,
html.et-sales-invoice-focus-prepaint section.content > .container-xl{
    width:100%!important;
    max-width:none!important;
    margin-left:0!important;
    margin-right:0!important;
    box-sizing:border-box!important;
}
html.et-sales-invoice-focus-prepaint .et-sales-invoice-menu-button{
    min-height:34px!important;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    padding:6px 10px!important;
    border:1px solid #ccd7e5!important;
    border-radius:7px!important;
    background:#fff!important;
    color:#26384e!important;
    font:inherit!important;
    font-size:10px!important;
    line-height:1!important;
    font-weight:850!important;
    white-space:nowrap!important;
    cursor:pointer!important;
    text-decoration:none!important;
}
html.et-sales-invoice-focus-prepaint .et-sales-invoice-menu-button:hover{
    background:#f7f9fc!important;
}
html.et-sales-invoice-focus-prepaint .et-sales-invoice-sidebar-open{
    display:block!important;
    position:fixed!important;
    left:0!important;
    top:0!important;
    bottom:0!important;
    height:100vh!important;
    max-height:100vh!important;
    z-index:10050!important;
    overflow:hidden!important;
    box-shadow:12px 0 32px rgba(15,31,52,.22)!important;
}
html.et-sales-invoice-focus-prepaint .et-sales-invoice-sidebar-open .nav{
    min-height:0!important;
    max-height:100vh!important;
    overflow-y:auto!important;
}
.et-sales-invoice-overlay{
    display:none;
    position:fixed;
    inset:0;
    z-index:10040;
    background:rgba(15,31,52,.36);
}
.et-sales-invoice-overlay.open{display:block}
@media(max-width:760px){
    html.et-sales-invoice-focus-prepaint{
    }
}
</style>
<script data-et-sales-invoice-focus-script="ERP-11.3.60">
(function(){
'use strict';
if(document.documentElement.dataset.etSalesInvoiceFocusInit==='ERP-11.3.60'){
    return;
}
document.documentElement.dataset.etSalesInvoiceFocusInit='ERP-11.3.60';

var norm=function(v){
    return String(v||'').replace(/\s+/g,' ').trim().toLowerCase();
};

var sidebar=document.querySelector(
    '.app-shell > .sidebar,body > .sidebar,.navbar-vertical,.side-nav'
);

if(!sidebar){
    return;
}

sidebar.setAttribute(
    'data-et-sales-invoice-sidebar',
    'ERP-11.3.60'
);
sidebar.dataset.etSalesInvoiceWidth=
    Math.max(
        210,
        Math.round(
            sidebar.getBoundingClientRect().width
            || 240
        )
    )+'px';

var overlay=document.createElement('div');
overlay.className='et-sales-invoice-overlay';
overlay.setAttribute(
    'data-et-sales-invoice-overlay',
    'ERP-11.3.60'
);
document.body.appendChild(overlay);

var closeMenu=function(){
    sidebar.classList.remove(
        'et-sales-invoice-sidebar-open'
    );
    sidebar.style.removeProperty('width');
    overlay.classList.remove('open');
};

var openMenu=function(){
    sidebar.style.setProperty(
        'width',
        sidebar.dataset.etSalesInvoiceWidth||'240px',
        'important'
    );
    sidebar.classList.add(
        'et-sales-invoice-sidebar-open'
    );
    overlay.classList.add('open');
};

var actions=Array.prototype.slice.call(
    document.querySelectorAll(
        'a[href],button,[role="button"]'
    )
).filter(function(el){
    return !sidebar.contains(el);
});

var registerAction=actions.find(function(el){
    var t=norm(el.textContent);
    return t==='register'
        || t==='invoice register'
        || t==='sales invoice register'
        || t==='back to register';
}) || null;

var bookingAction=actions.find(function(el){
    var t=norm(el.textContent);
    return t==='booking'
        || t==='back to booking'
        || t==='open booking';
}) || null;

var anchor=registerAction||bookingAction;

if(anchor&&anchor.parentNode){
    var existing=anchor.parentNode.querySelector(
        '[data-et-sales-invoice-menu]'
    );

    if(!existing){
        var menu=document.createElement('button');
        menu.type='button';
        menu.className='et-sales-invoice-menu-button';
        menu.setAttribute(
            'data-et-sales-invoice-menu',
            'ERP-11.3.60'
        );
        menu.textContent='☰ Menu';
        menu.addEventListener('click',openMenu);
        anchor.parentNode.insertBefore(menu,anchor);
    }
}

overlay.addEventListener('click',closeMenu);
document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
        closeMenu();
    }
});
})();
</script>
HTML;

        if (str_contains($html, '</head>')) {
            $html = str_replace(
                '</head>',
                $injection.'</head>',
                $html
            );
        } elseif (str_contains($html, '</body>')) {
            $html = str_replace(
                '</body>',
                $injection.'</body>',
                $html
            );
        } else {
            $html .= $injection;
        }

        $response->setContent($html);

        return $response;
    }

    private function markHtml(
        string $html
    ): string {
        if (
            preg_match(
                '/<html\b([^>]*)>/i',
                $html,
                $matches
            ) !== 1
        ) {
            return $html;
        }

        $tag = $matches[0];

        if (
            preg_match(
                '/\bclass\s*=\s*(["\'])(.*?)\1/i',
                $tag,
                $classMatch
            ) === 1
        ) {
            $classes = trim(
                $classMatch[2]
                .' et-sales-invoice-focus-prepaint'
            );

            $replacement = str_replace(
                $classMatch[0],
                'class='.$classMatch[1]
                .$classes
                .$classMatch[1],
                $tag
            );
        } else {
            $replacement = preg_replace(
                '/<html\b/i',
                '<html class="et-sales-invoice-focus-prepaint"',
                $tag,
                1
            ) ?? $tag;
        }

        return preg_replace(
            '/'.preg_quote($tag, '/').'/',
            $replacement,
            $html,
            1
        ) ?? $html;
    }
}
