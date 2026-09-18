(function(window,document){
  'use strict';
  var registry=Object.create(null);
  var root=function(){return document.querySelector('[data-etgp-dedicated-product="1"]');};
  var context=function(){return window.etBookingWorkspaceContext||null;};
  var core={
    getBookingRoot:root,
    getProductMount:function(){var r=root();return r&&r.querySelector('[data-etgp-dedicated-product-body]');},
    getBookingId:function(){var c=context();if(c&&typeof c.getBookingId==='function')return Number(c.getBookingId()||0)||0;var r=root();return Number(r&&r.dataset.bookingId||0)||0;},
    getBookingReference:function(){var c=context();if(c&&typeof c.getBookingReference==='function')return c.getBookingReference();var r=root();return String(r&&r.dataset.bookingReference||'Booking');},
    getProductKey:function(){var r=root();return String(r&&r.dataset.etgpProductKey||'').toLowerCase();},
    getApiBase:function(){var c=context();return c&&typeof c.getApiBase==='function'?c.getApiBase():'/system/erp-bookings';},
    getCsrfToken:function(){var c=context();if(c&&typeof c.getCsrfToken==='function')return c.getCsrfToken();var m=document.querySelector('meta[name="csrf-token"]');return m?String(m.content||''):'';},
    getLockState:function(){var c=context();if(c&&typeof c.getLockState==='function')return c.getLockState();var r=root();return {locked:!!(r&&r.dataset.etgpBookingLocked==='1'),status:String(r&&r.dataset.etgpBookingStatus||'DRAFT'),reason:''};},
    isLocked:function(){var c=context();return c&&typeof c.isLocked==='function'?c.isLocked():core.getLockState().locked===true;},
    getPassengerData:function(){var c=context();return c&&typeof c.getPassengerData==='function'?c.getPassengerData():[];},
    loadPassengerData:function(){var c=context();return c&&typeof c.loadPassengerData==='function'?c.loadPassengerData():Promise.resolve([]);},
    getCurrency:function(){var c=context();if(c&&typeof c.getCurrency==='function')return c.getCurrency();var r=root();return String(r&&r.dataset.currency||'PKR');},
    plain:function(v){return String(v==null?'':v).replace(/\s+/g,' ').trim();}, norm:function(v){return core.plain(v).toLowerCase();},
    formatMoney:function(v,c){var n=Number(String(v==null?'0':v).replace(/[^0-9.\-]/g,''));return (c||core.getCurrency())+' '+(Number.isFinite(n)?n:0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});},
    requestJson:function(url,options){var o=Object.assign({credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}},options||{});o.headers=Object.assign({Accept:'application/json','X-Requested-With':'XMLHttpRequest'},o.headers||{});return fetch(url,o).then(function(r){return r.json().catch(function(){return {};}).then(function(data){if(!r.ok)throw new Error(data&&data.message||'Request failed.');return data;});});},
    saveJson:function(url,payload,method){return core.requestJson(url,{method:method||'PUT',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':core.getCsrfToken()},body:JSON.stringify(payload||{})});},
    showNotice:function(message){var n=document.querySelector('[data-et-dedicated-notice]');if(n){n.textContent=core.plain(message);n.hidden=false;}}, showError:function(message){core.showNotice(message||'Unable to load this product.');},
    registerProduct:function(definition){if(!definition||!definition.key||typeof definition.mount!=='function')return false;registry[String(definition.key).toLowerCase()]=definition;return true;},
    getRegisteredProduct:function(key){return registry[String(key||'').toLowerCase()]||null;}
  };
  window.etDedicatedProductCore=core;
})(window,document);
