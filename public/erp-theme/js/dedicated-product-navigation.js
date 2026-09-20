(function(window,document){'use strict';
  var launcher=document.querySelector('[data-et-booking-products-launcher="1"]');
  if(!launcher)return;
  var airLink=Array.prototype.find.call(launcher.querySelectorAll('a[href]'),function(link){
    try{var url=new URL(link.href,window.location.href);return url.origin===window.location.origin&&/^\/operations\/bookings\/\d+\/products\/air$/.test(url.pathname);}catch(e){return false;}
  });
  if(!airLink)return;
  var target=launcher.closest('main');
  if(!target)return;
  var navigation=null,assetPromises=Object.create(null),transaction=null;
  var productPrepaintClass='et-booking-products-prepaint';
  var navigationProductPrepaintWasPresent=false;
  var visualState=null;
  var normalAirUrl=airLink.href;
  var committedAirUrl=normalAirUrl;
  var navigationScript=document.querySelector('script[data-et-dedicated-product-navigation]');
  var navigationVersion=navigationScript&&navigationScript.getAttribute('data-et-dedicated-product-navigation')||'';
  var bookingMatch=normalAirUrl.match(/\/operations\/bookings\/(\d+)\/products\/air$/);
  if(!bookingMatch)return;
  var bookingId=bookingMatch[1];
  var fragmentUrl='/operations/bookings/'+bookingId+'/products/air/fragment';
  var stylesheet=function(href,marker){
    if(document.querySelector('link[data-et-fast-nav-asset="'+marker+'"]'))return Promise.resolve();
    var existing=Array.prototype.find.call(document.querySelectorAll('link[rel="stylesheet"]'),function(link){return link.href===new URL(href,window.location.href).href;});
    if(existing)return Promise.resolve();
    return new Promise(function(resolve,reject){var link=document.createElement('link');link.rel='stylesheet';link.href=href;link.dataset.etFastNavAsset=marker;link.onload=resolve;link.onerror=reject;document.head.appendChild(link);});
  };
  var script=function(src,marker,ready){
    if(ready&&ready())return Promise.resolve();
    if(assetPromises[marker])return assetPromises[marker];
    assetPromises[marker]=new Promise(function(resolve,reject){var node=document.querySelector('script[data-et-fast-nav-asset="'+marker+'"]')||document.createElement('script');if(!node.parentNode){node.src=src;node.async=false;node.dataset.etFastNavAsset=marker;node.onload=resolve;node.onerror=reject;document.head.appendChild(node);}else resolve();});
    return assetPromises[marker];
  };
  var ensureAssets=function(){
    var version=navigationVersion;
    var existing=document.querySelector('[data-et-dedicated-product-core]');
    if(!version&&existing)version=existing.getAttribute('data-et-dedicated-product-core')||'';
    var css='/system/erp-assets/erp-professional.css?module=operations&role=focused&dedicated=1&product=air'+(version?'&v='+encodeURIComponent(version):'');
    return Promise.all([stylesheet(css,'dedicated-air-css')])
      .then(function(){return script('/system/erp-assets/dedicated-product-core.js'+(version?'?v='+encodeURIComponent(version):''),'dedicated-product-core',function(){return !!window.etDedicatedProductCore;});})
      .then(function(){return script('/system/erp-assets/products-air.js'+(version?'?v='+encodeURIComponent(version):''),'products-air',function(){return !!window.etDedicatedAirProduct;});});
  };
  var validFragment=function(html){
    var parsed=new DOMParser().parseFromString(html,'text/html');
    var roots=parsed.querySelectorAll('[data-etgp-dedicated-product="1"]');
    if(roots.length!==1)return null;
    var root=roots[0];
    if(String(root.getAttribute('data-etgp-product-key')||'').toLowerCase()!=='air')return null;
    if(String(root.getAttribute('data-booking-id')||'')!==String(bookingId))return null;
    if(!root.querySelector('[data-etgp-dedicated-product-host]')||!root.querySelector('[data-etgp-dedicated-product-body]'))return null;
    return root;
  };
  var captureVisualState=function(){
    var root=document.documentElement,classes={};
    ['et-booking-products-prepaint','et-general-progressive-step1-11390','etgp-step1-live-11390','etgp-step1-ready-11390','etgp-step1-fallback-11390'].forEach(function(name){classes[name]=!!(root&&root.classList&&root.classList.contains(name));});
    var stylesheet=document.querySelector('link[data-et-general-progressive-css]');
    if(!stylesheet)stylesheet=Array.prototype.find.call(document.querySelectorAll('link[rel="stylesheet"]'),function(link){return /general-progressive-step1\.css/i.test(String(link.href||link.getAttribute('href')||''));});
    return {classes:classes,stylesheet:stylesheet,stylesheetDisabled:stylesheet?!!stylesheet.disabled:false};
  };
  var restoreVisualState=function(state){
    if(!state||!document.documentElement||!document.documentElement.classList)return;
    Object.keys(state.classes).forEach(function(name){document.documentElement.classList.toggle(name,state.classes[name]);});
    if(state.stylesheet)state.stylesheet.disabled=state.stylesheetDisabled;
  };
  var retireGeneralVisualState=function(state){
    var root=document.documentElement;
    ['et-general-progressive-step1-11390','etgp-step1-live-11390','etgp-step1-ready-11390','etgp-step1-fallback-11390'].forEach(function(name){root.classList.remove(name);});
    if(state&&state.stylesheet)state.stylesheet.disabled=true;
  };
  var rollbackProductPrepaint=function(){
    if(!navigationProductPrepaintWasPresent&&document.documentElement&&document.documentElement.classList){
      document.documentElement.classList.remove(productPrepaintClass);
    }
  };
  var fallback=function(){
    if(transaction&&transaction.parent&&transaction.original&&transaction.mountedRoot&&transaction.mountedRoot.parentNode===transaction.parent){
      transaction.parent.replaceChild(transaction.original,transaction.mountedRoot);
    }
    transaction=null;
    restoreVisualState(visualState);
    rollbackProductPrepaint();
    if(navigation&&navigation.controller)navigation.controller.abort();
    window.location.assign(normalAirUrl);
  };
  var navigate=function(event){
    if(event.button!==0||event.defaultPrevented||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||airLink.target==='_blank'||airLink.hasAttribute('download'))return;
    event.preventDefault();
    if(navigation)return;
    navigationProductPrepaintWasPresent=!!(
      document.documentElement
      && document.documentElement.classList
      && document.documentElement.classList.contains(productPrepaintClass)
    );
    visualState=captureVisualState();
    var state=window.etDedicatedAirProduct&&window.etDedicatedAirProduct.getState?window.etDedicatedAirProduct.getState():null;
    if(state&&(state.saveInFlight||state.dirty||state.draftPending)){if(!window.confirm('Air data has unsaved changes. Continue to Air workspace?'))return;}
    navigation={controller:new AbortController()};airLink.setAttribute('data-et-fast-nav-loading','1');
    var controller=navigation.controller;
    Promise.all([ensureAssets(),fetch(fragmentUrl,{credentials:'same-origin',headers:{Accept:'text/html','X-Requested-With':'XMLHttpRequest'},signal:controller.signal}).then(function(response){if(!response.ok)throw new Error('Fragment request failed.');return response.text();})])
      .then(function(results){
        var root=validFragment(results[1]);
        if(!root)throw new Error('Invalid Air fragment.');
        var parent=target.parentNode;
        if(!parent)throw new Error('Booking host is no longer attached.');
        var mountedRoot=document.importNode(root,true);
        transaction={parent:parent,original:target,mountedRoot:mountedRoot};
        parent.replaceChild(mountedRoot,target);
        if(document.documentElement&&document.documentElement.classList)document.documentElement.classList.add(productPrepaintClass);
        retireGeneralVisualState(visualState);
        if(!window.etBookingFocus||typeof window.etBookingFocus.mountPresentation!=='function'||window.etBookingFocus.mountPresentation(mountedRoot)!==true)throw new Error('Booking focus presentation failed.');
        if(!window.etDedicatedAirProduct||window.etDedicatedAirProduct.mount(mountedRoot)!==true)throw new Error('Air mount failed.');
        var review=document.querySelector('[data-et-booking-review-entry="1"]');if(review)review.remove();
        committedAirUrl=normalAirUrl;window.history.pushState({etAirFast:true},'',normalAirUrl);transaction=null;
      })
      .catch(function(){fallback();})
      .finally(function(){airLink.removeAttribute('data-et-fast-nav-loading');navigation=null;});
  };
  airLink.addEventListener('click',navigate);
  window.addEventListener('popstate',function(){var api=window.etDedicatedAirProduct;if(api&&api.getState){var state=api.getState();if(state.saveInFlight||(state.dirty||state.draftPending)&&!window.confirm('Air data has unsaved changes. Leave this workspace?')){window.history.replaceState(window.history.state,'',committedAirUrl);return;}}window.location.assign(window.location.href);});
})(window,document);
