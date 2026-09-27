(function(){
  'use strict';
  var key='visa', prefix='etgp-visa-product-draft-v113142:';
  function draftKey(id){return prefix+String(id||0);}
  function readDraft(id){try{var raw=localStorage.getItem(draftKey(id));return raw?JSON.parse(raw):null;}catch(e){return null;}}
  function writeDraft(id,data){try{localStorage.setItem(draftKey(id),JSON.stringify(data||{}));}catch(e){}}
  function clearDraft(id){try{localStorage.removeItem(draftKey(id));}catch(e){}}
  function request(id,method,payload){var options={method:method||'GET',credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}};if(method&&method!=='GET'){options.headers['Content-Type']='application/json';options.body=JSON.stringify(payload||{});}return fetch('/system/erp-bookings/'+id+'/visa-product',options).then(function(r){return r.json().catch(function(){return {};}).then(function(data){if(!r.ok||data.ok===false)throw new Error(data&&data.message||'Visa request failed.');return data;});});}
  function load(id,cache){var cached=cache&&cache.getProductResponse&&cache.getProductResponse(key,id);if(cached)return Promise.resolve(cached);var pending=cache&&cache.getProductPromise&&cache.getProductPromise(key,id);if(pending)return pending;var p=request(id,'GET').then(function(data){if(cache&&cache.setProductResponse)cache.setProductResponse(key,id,data);return data;});if(cache&&cache.setProductPromise)cache.setProductPromise(key,id,p);return p.catch(function(e){if(cache&&cache.setProductPromise)cache.setProductPromise(key,id,null);throw e;});}
  function money(v){var n=Number(String(v===undefined||v===null?'0':v).replace(/[^0-9.\-]/g,''));return Number.isFinite(n)?n:0;}
  function payload(rows){return {visas:(Array.isArray(rows)?rows:[]).map(function(r){return {booking_passenger_id:Number(r.booking_passenger_id||0),visa_rate_card_id:Number(r.visa_rate_card_id||0),sale_pkr:money(r.sale_pkr),status:r.status||'pending',application_reference:r.application_reference||'',visa_number:r.visa_number||'',issue_date:r.issue_date||null,expiry_date:r.expiry_date||null,notes:r.notes||''};})};}
  window.etVisaCore={key:key,draftKey:draftKey,readDraft:readDraft,writeDraft:writeDraft,clearDraft:clearDraft,request:request,load:load,money:money,payload:payload};
})();
