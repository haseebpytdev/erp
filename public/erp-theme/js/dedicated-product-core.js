(function(window,document){
  'use strict';
  var registry=Object.create(null);
  var responseCache=Object.create(null),promiseCache=Object.create(null),passengerSnapshot=[];
  var root=function(){return document.querySelector('[data-etgp-dedicated-product="1"]');};
  var context=function(){return window.etBookingWorkspaceContext||null;};
  var keyFor=function(product,booking){return String(booking||0)+'::'+String(product||'').toLowerCase();};
  var directRootValue=function(name){var r=root();return r&&r.dataset?String(r.dataset[name]||''):'';};
  var create=function(tag,className,text){var el=document.createElement(tag||'div');if(className)el.className=className;if(text!==undefined)el.textContent=String(text);return el;};
  var core={
    getBookingRoot:root,
    getProductMount:function(){var r=root();return r&&r.querySelector('[data-etgp-dedicated-product-body]');},
    getBookingId:function(){var direct=Number(directRootValue('bookingId')||0);if(direct>0)return direct;var c=context();return c&&typeof c.getBookingId==='function'?Number(c.getBookingId()||0)||0:0;},
    getBookingReference:function(){var direct=directRootValue('bookingReference');if(direct)return direct;var c=context();return c&&typeof c.getBookingReference==='function'?c.getBookingReference():'Booking';},
    getProductKey:function(){return directRootValue('etgpProductKey').toLowerCase();},
    getApiBase:function(){var c=context();return c&&typeof c.getApiBase==='function'?c.getApiBase():'/system/erp-bookings';},
    getCsrfToken:function(){var m=document.querySelector('meta[name="csrf-token"]');return m?String(m.content||''):'';},
    getLockState:function(){var locked=directRootValue('etgpBookingLocked');var status=directRootValue('etgpBookingStatus');if(locked||status)return {locked:locked==='1'||['PENDING APPROVAL','APPROVED','TRAVEL READY'].indexOf(status.toUpperCase())!==-1,status:status||'DRAFT',reason:directRootValue('etgpBookingLockReason')};var c=context();return c&&typeof c.getLockState==='function'?c.getLockState():{locked:false,status:'DRAFT',reason:''};},
    isLocked:function(){return core.getLockState().locked===true;},
    applyReadOnly:function(r){if(!r)return false;var locked=core.isLocked();r.querySelectorAll('input,select,textarea,button,[role="button"]').forEach(function(el){if(locked){if(!el.disabled){el.disabled=true;el.setAttribute('data-et-dedicated-lock-disabled','1');}el.setAttribute('aria-disabled','true');}else if(el.getAttribute('data-et-dedicated-lock-disabled')==='1'){el.disabled=false;el.removeAttribute('data-et-dedicated-lock-disabled');el.removeAttribute('aria-disabled');}});return locked;},
    loadPassengerData:function(){if(passengerSnapshot.length)return Promise.resolve(passengerSnapshot.slice());var cached=core.getProductResponse(core.getProductKey(),core.getBookingId());if(cached&&Array.isArray(cached.passengers)){core.setPassengerData(cached.passengers);return Promise.resolve(passengerSnapshot.slice());}var c=context();return root()?Promise.resolve([]):(c&&typeof c.loadPassengerData==='function'?c.loadPassengerData():Promise.resolve([]));},
    getCurrency:function(){var direct=directRootValue('currency');if(direct)return direct;var c=context();return c&&typeof c.getCurrency==='function'?c.getCurrency():'PKR';},
    plain:function(v){return String(v==null?'':v).replace(/\s+/g,' ').trim();}, norm:function(v){return core.plain(v).toLowerCase();},
    formatMoney:function(v,c){var n=Number(String(v==null?'0':v).replace(/[^0-9.\-]/g,''));return (c||core.getCurrency())+' '+(Number.isFinite(n)?n:0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});},
    requestJson:function(url,options){var o=Object.assign({credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}},options||{});o.headers=Object.assign({Accept:'application/json','X-Requested-With':'XMLHttpRequest'},o.headers||{});return fetch(url,o).then(function(r){return r.json().catch(function(){return {};}).then(function(data){if(!r.ok)throw new Error(data&&data.message||'Request failed.');return data;});});},
    requestText:function(url,options){var o=Object.assign({credentials:'same-origin',headers:{Accept:'text/html','X-Requested-With':'XMLHttpRequest'}},options||{});o.headers=Object.assign({Accept:'text/html','X-Requested-With':'XMLHttpRequest'},o.headers||{});return fetch(url,o).then(function(r){return r.text().then(function(data){if(!r.ok)throw new Error('Request failed.');return data;});});},
    saveJson:function(url,payload,method){return core.requestJson(url,{method:method||'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':core.getCsrfToken()},body:JSON.stringify(payload||{})});},
    create:create,
    showNotice:function(message){var n=document.querySelector('[data-et-dedicated-notice]');if(n){n.textContent=core.plain(message);n.hidden=false;}}, showError:function(message){core.showNotice(message||'Unable to load this product.');},
    markMounted:function(r){var n=r&&r.querySelector?r.querySelector('[data-et-dedicated-loading]'):null;if(n){n.hidden=true;n.setAttribute('aria-hidden','true');}if(r&&r.dataset)r.dataset.etDedicatedMounted='1';return true;},
    markFailed:function(r,message){var n=r&&r.querySelector?r.querySelector('[data-et-dedicated-loading]'):null;if(n){n.hidden=false;n.removeAttribute('aria-hidden');n.textContent=message||'This workspace could not be loaded. Please refresh and try again.';n.setAttribute('data-et-dedicated-failure','1');}if(r&&r.dataset)r.dataset.etDedicatedMountFailed='1';return false;},
    setProductResponse:function(product,booking,data){responseCache[keyFor(product,booking)]=data||{};if(String(product).toLowerCase()===core.getProductKey())core.setPassengerData((data&&data.passengers)||[]);return data;},
    getProductResponse:function(product,booking){return responseCache[keyFor(product,booking)]||null;},
    getProductPromise:function(product,booking){return promiseCache[keyFor(product,booking)]||null;},
    setProductPromise:function(product,booking,promise){promiseCache[keyFor(product,booking)]=promise;return promise;},
    getPassengerData:function(){return passengerSnapshot.slice();},
    setPassengerData:function(passengers){passengerSnapshot=Array.isArray(passengers)?passengers.slice():[];return passengerSnapshot;},
    readDraft:function(namespace,booking,product){try{var raw=localStorage.getItem('et-dedicated-draft:'+namespace+':'+keyFor(product,booking));return raw?JSON.parse(raw):null;}catch(e){return null;}},
    writeDraft:function(namespace,booking,product,data){try{localStorage.setItem('et-dedicated-draft:'+namespace+':'+keyFor(product,booking),JSON.stringify(data||{}));}catch(e){}return data;},
    clearDraft:function(namespace,booking,product){try{localStorage.removeItem('et-dedicated-draft:'+namespace+':'+keyFor(product,booking));}catch(e){}},
    refreshBookingState:function(){return core.requestText(window.location.href);},
    registerProduct:function(definition){if(!definition||!definition.key||typeof definition.mount!=='function')return false;registry[String(definition.key).toLowerCase()]=definition;return true;},
    getRegisteredProduct:function(key){return registry[String(key||'').toLowerCase()]||null;}
  };
  window.etDedicatedProductCore=core;
})(window,document);
