(function(window,document){
  'use strict';
  var registry=Object.create(null);
  var responseCache=Object.create(null),promiseCache=Object.create(null),passengerSnapshot=[];
  var activeRoot=null;
  var root=function(){
    if(activeRoot&&activeRoot.isConnected!==false)return activeRoot;
    activeRoot=null;
    return document.querySelector('[data-etgp-dedicated-product="1"]');
  };
  var context=function(){return window.etBookingWorkspaceContext||null;};
  var billingContext=function(){var c=context();if(c&&typeof c.getBillingContext==='function')return String(c.getBillingContext()||'ORIGINAL').toUpperCase();var r=root();return String(r&&r.dataset&&r.dataset.billingContext||'ORIGINAL').toUpperCase();};
  var billingBatchId=function(){var c=context();if(c&&typeof c.getBillingBatchId==='function')return Number(c.getBillingBatchId()||0)||0;var r=root();return Number(r&&r.dataset&&r.dataset.billingBatchId||0)||0;};
  var keyFor=function(product,booking){var contextName=billingContext(),batch=contextName==='SUPPLEMENTARY'?billingBatchId():0;return String(booking||0)+'::'+contextName+'::'+String(batch)+'::'+String(product||'').toLowerCase();};
  var cacheScope=function(){var r=root(),c=context(),user=r&&r.dataset?Number(r.dataset.etgpUserId||0):0;if(!user&&c&&typeof c.getUserId==='function')user=Number(c.getUserId()||0);if(user<=0)return null;var branch=r&&r.dataset?String(r.dataset.branchId||r.dataset.etgpBranchId||''):'';var company=r&&r.dataset?String(r.dataset.companyId||r.dataset.etgpCompanyId||''):'';if(!branch&&c&&typeof c.getBranchId==='function')branch=String(c.getBranchId()||'');if(!company&&c&&typeof c.getCompanyId==='function')company=String(c.getCompanyId()||'');return String(user)+'::'+(branch||'unknown')+'::'+(company||'unknown');};
  var cacheKey=function(product,booking){var scope=cacheScope();return scope?scope+'::'+keyFor(product,booking):null;};
  var generations=Object.create(null);
  var generationFor=function(product,booking){var key=cacheKey(product,booking);return key?(generations[key]||0):0;};
  var cacheToken=function(product,booking){var key=cacheKey(product,booking);if(!key)return null;if(generations[key]===undefined)generations[key]=0;return {key:key,generation:generations[key]};};
  var draftScopeKey=function(namespace,product,booking){var scope=cacheScope();return scope?'et-dedicated-draft:'+String(namespace||'general')+':'+scope+'::'+keyFor(product,booking):null;};
  var directRootValue=function(name){var r=root();return r&&r.dataset?String(r.dataset[name]||''):'';};
  var create=function(tag,className,text){var el=document.createElement(tag||'div');if(className)el.className=className;if(text!==undefined)el.textContent=String(text);return el;};
  var core={
    getBookingRoot:root,
    setActiveRoot:function(next){if(!next||!next.querySelector)return false;activeRoot=next;return true;},
    clearActiveRoot:function(expected){if(!expected||activeRoot===expected)activeRoot=null;return true;},
    getProductMount:function(){var r=root();return r&&r.querySelector('[data-etgp-dedicated-product-body]');},
    getBookingId:function(){var direct=Number(directRootValue('bookingId')||0);if(direct>0)return direct;var c=context();return c&&typeof c.getBookingId==='function'?Number(c.getBookingId()||0)||0:0;},
    getBookingReference:function(){var direct=directRootValue('bookingReference');if(direct)return direct;var c=context();return c&&typeof c.getBookingReference==='function'?c.getBookingReference():'Booking';},
    getProductKey:function(){return directRootValue('etgpProductKey').toLowerCase();},
    getBillingContext:billingContext,
    getBillingBatchId:billingBatchId,
    getProductScope:function(product,booking){return {booking_id:Number(booking||core.getBookingId())||0,billing_context:billingContext(),billing_batch_id:billingContext()==='SUPPLEMENTARY'?billingBatchId():0,product:String(product||core.getProductKey()).toLowerCase()};},
    getDraftScopeKey:function(product,booking){return keyFor(product,booking);},
    getProductEndpoint:function(product,booking,suffix){var p=String(product||core.getProductKey()).toLowerCase(),b=Number(booking||core.getBookingId())||0,s=String(suffix||'');if(billingContext()==='SUPPLEMENTARY'&&billingBatchId()>0)return '/system/erp-bookings/'+b+'/additional-services/'+billingBatchId()+'/'+p+'-product'+s;return '/system/erp-bookings/'+b+'/'+p+'-product'+s;},
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
    setProductResponse:function(product,booking,data,token){var key=cacheKey(product,booking),expected=token&&typeof token==='object'?token:cacheToken(product,booking);if(!key||!expected||expected.key!==key||expected.generation!==generationFor(product,booking))return null;responseCache[key]=data||{};if(String(product).toLowerCase()===core.getProductKey())core.setPassengerData((data&&data.passengers)||[]);return data;},
    getProductResponse:function(product,booking){var key=cacheKey(product,booking);return key?(responseCache[key]||null):null;},
    getProductPromise:function(product,booking){var key=cacheKey(product,booking);return key?(promiseCache[key]||null):null;},
    setProductPromise:function(product,booking,promise,token){var key=cacheKey(product,booking),expected=token&&typeof token==='object'?token:cacheToken(product,booking);if(key&&expected&&expected.key===key&&expected.generation===generationFor(product,booking))promiseCache[key]=promise;return promise;},
    getProductGeneration:function(product,booking){return generationFor(product,booking);},
    getProductCacheToken:function(product,booking){return cacheToken(product,booking);},
    invalidateBookingCaches:function(booking,exceptProduct){var scope=cacheScope();if(!scope)return false;var prefix=scope+'::'+String(booking||0)+'::';var excluded=exceptProduct?'::'+String(exceptProduct).toLowerCase():'';Object.keys(responseCache).forEach(function(key){if(key.indexOf(prefix)===0&&(!excluded||key.slice(prefix.length).slice(-excluded.length)!==excluded))delete responseCache[key];});Object.keys(promiseCache).forEach(function(key){if(key.indexOf(prefix)===0&&(!excluded||key.slice(prefix.length).slice(-excluded.length)!==excluded))delete promiseCache[key];});Object.keys(generations).forEach(function(key){if(key.indexOf(prefix)===0&&(!excluded||key.slice(prefix.length).slice(-excluded.length)!==excluded))generations[key]=(generations[key]||0)+1;});if(!exceptProduct&&String(core.getBookingId())===String(booking||0))core.setPassengerData([]);return true;},
    getPassengerData:function(){return passengerSnapshot.slice();},
    setPassengerData:function(passengers){passengerSnapshot=Array.isArray(passengers)?passengers.slice():[];return passengerSnapshot;},
    readDraft:function(namespace,booking,product){try{var key=draftScopeKey(namespace,product,booking),raw=key?localStorage.getItem(key):null;return raw?JSON.parse(raw):null;}catch(e){return null;}},
    writeDraft:function(namespace,booking,product,data){try{var key=draftScopeKey(namespace,product,booking);if(key)localStorage.setItem(key,JSON.stringify(data||{}));}catch(e){}return data;},
    clearDraft:function(namespace,booking,product){try{var key=draftScopeKey(namespace,product,booking);if(key)localStorage.removeItem(key);}catch(e){}},
    refreshBookingState:function(booking,preserveProduct){var id=Number(booking||core.getBookingId())||0;if(id<=0)return Promise.reject(new Error('Booking context is unavailable.'));core.invalidateBookingCaches(id,preserveProduct);var url='/system/erp-bookings/'+id+'/operational-summary';return core.requestJson(url).then(function(data){if(String(core.getBookingId())!==String(id))return null;return data;});},
    registerProduct:function(definition){if(!definition||!definition.key||typeof definition.mount!=='function')return false;registry[String(definition.key).toLowerCase()]=definition;return true;},
    getRegisteredProduct:function(key){return registry[String(key||'').toLowerCase()]||null;}
  };
  window.etDedicatedProductCore=core;
})(window,document);
