/* Focused shell drawer interaction only; no DOM reconstruction or layout math. */
(function(){'use strict';var sidebar=document.querySelector('.et-sales-invoice-sidebar-open,.gp-focus-sidebar,.et-air-focus-sidebar');if(!sidebar)return;document.addEventListener('keydown',function(e){if(e.key==='Escape')sidebar.classList.remove('et-sales-invoice-sidebar-open','gp-focus-sidebar-open','et-air-focus-sidebar-open-103172');});})();

/* Extracted generic Sales Invoice focused drawer interaction */

(function(){
'use strict';
if(document.documentElement.dataset.etSalesInvoiceFocusInit==='ERP-11.3.60') return;
document.documentElement.dataset.etSalesInvoiceFocusInit='ERP-11.3.60';

var norm=function(v){return String(v||'').replace(/\s+/g,' ').trim().toLowerCase();};
var sidebar=document.querySelector('.app-shell > .sidebar,body > .sidebar,.navbar-vertical,.side-nav');
if(!sidebar) return;

sidebar.setAttribute('data-et-sales-invoice-sidebar','ERP-11.3.60');
sidebar.dataset.etSalesInvoiceWidth=Math.max(210,Math.round(sidebar.getBoundingClientRect().width||240))+'px';

var overlay=document.createElement('div');
overlay.className='et-sales-invoice-overlay';
overlay.setAttribute('data-et-sales-invoice-overlay','ERP-11.3.60');
document.body.appendChild(overlay);

var closeMenu=function(){
    sidebar.classList.remove('et-sales-invoice-sidebar-open');
    sidebar.style.removeProperty('width');
    overlay.classList.remove('open');
};
var openMenu=function(){
    sidebar.style.setProperty('width',sidebar.dataset.etSalesInvoiceWidth||'240px','important');
    sidebar.classList.add('et-sales-invoice-sidebar-open');
    overlay.classList.add('open');
};

var actions=Array.prototype.slice.call(document.querySelectorAll('a[href],button,[role="button"]')).filter(function(el){
    return !sidebar.contains(el);
});
var registerAction=actions.find(function(el){
    var t=norm(el.textContent);
    return t==='register'||t==='invoice register'||t==='sales invoice register'||t==='back to register';
})||null;
var bookingAction=actions.find(function(el){
    var t=norm(el.textContent);
    return t==='booking'||t==='back to booking'||t==='open booking';
})||null;
var anchor=registerAction||bookingAction;

if(anchor&&anchor.parentNode){
    var existing=anchor.parentNode.querySelector('[data-et-sales-invoice-menu]');
    if(!existing){
        var menu=document.createElement('button');
        menu.type='button';
        menu.className='et-sales-invoice-menu-button';
        menu.setAttribute('data-et-sales-invoice-menu','ERP-11.3.60');
        menu.textContent='☰ Menu';
        menu.addEventListener('click',openMenu);
        anchor.parentNode.insertBefore(menu,anchor);
    }
}

overlay.addEventListener('click',closeMenu);
document.addEventListener('keydown',function(e){if(e.key==='Escape') closeMenu();});
})();

