(function(){
  'use strict';
  function boot(){var root=document.querySelector('[data-etgp-dedicated-product="1"][data-etgp-product-key="visa"]');if(!root||!window.etVisaProductCore||!window.etVisaProductCore.mount)return;window.etDedicatedVisaProduct=window.etVisaProductCore.mount({mode:'dedicated',root:root,bookingId:Number(root.getAttribute('data-booking-id')||0)});}
  window.addEventListener('beforeunload',function(e){var s=window.etDedicatedVisaProduct&&window.etDedicatedVisaProduct.getState?window.etDedicatedVisaProduct.getState():null;if(s&&(s.saveInFlight||s.dirty||s.draftPending)){e.preventDefault();e.returnValue='';}});
  window.addEventListener('popstate',function(e){var s=window.etDedicatedVisaProduct&&window.etDedicatedVisaProduct.getState?window.etDedicatedVisaProduct.getState():null;if(s&&(s.saveInFlight||s.dirty||s.draftPending)&&!window.confirm('Visa data has unsaved changes. Leave this workspace?'))history.pushState(e.state||{},'',location.href);});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
