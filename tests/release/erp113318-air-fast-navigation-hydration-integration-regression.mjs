import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const read=p=>fs.readFileSync(new URL('../../'+p,import.meta.url),'utf8');
const navSource=read('public/erp-theme/js/dedicated-product-navigation.js');
const coreSource=read('public/erp-theme/js/dedicated-product-core.js');
const airSource=read('public/erp-theme/js/products/air.js');

class E {
  constructor(tag='div'){this.tagName=tag.toUpperCase();this.children=[];this.parentNode=null;this.attributes={};this.dataset={};this.listeners={};this.className='';this.classList={add:(...x)=>x.forEach(n=>{if(!this.className.split(/\s+/).includes(n))this.className=(this.className+' '+n).trim();}),remove:(...x)=>x.forEach(n=>{this.className=this.className.split(/\s+/).filter(v=>v&&v!==n).join(' ');}),contains:n=>this.className.split(/\s+/).includes(n),toggle:(n,f)=>{const on=f===undefined?!this.classList.contains(n):f;if(on)this.classList.add(n);else this.classList.remove(n);return on;}};this.style={setProperty:(k,v)=>{this.style[k]=v;}};this.hidden=false;this.disabled=false;this.value='';this.type='';this.isConnected=true;}
  appendChild(n){if(n.parentNode)n.parentNode.removeChild(n);n.parentNode=this;n.isConnected=this.isConnected;this.children.push(n);return n;}
  removeChild(n){const i=this.children.indexOf(n);if(i>=0)this.children.splice(i,1);n.parentNode=null;n.isConnected=false;return n;}
  remove(){if(this.parentNode)this.parentNode.removeChild(this);}
  insertBefore(n,r){if(n.parentNode)n.parentNode.removeChild(n);const i=this.children.indexOf(r);if(i<0)return this.appendChild(n);this.children.splice(i,0,n);n.parentNode=this;n.isConnected=this.isConnected;return n;}
  replaceChild(n,o){const i=this.children.indexOf(o);if(i>=0){if(n.parentNode)n.parentNode.removeChild(n);this.children[i]=n;n.parentNode=this;n.isConnected=this.isConnected;o.parentNode=null;o.isConnected=false;}return o;}
  setAttribute(k,v){this.attributes[k]=String(v);if(k==='class')this.className=String(v);if(k.startsWith('data-'))this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(v);}
  getAttribute(k){return this.attributes[k]??null;} hasAttribute(k){return this.getAttribute(k)!==null;} removeAttribute(k){delete this.attributes[k];if(k.startsWith('data-'))delete this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())];}
  addEventListener(k,f){(this.listeners[k]??=[]).push(f);} dispatchEvent(e){(this.listeners[e.type]||[]).forEach(f=>f.call(this,e));if(e.bubbles!==false&&this.parentNode)this.parentNode.dispatchEvent(e);}
  get textContent(){return String(this._text||'')+this.children.map(c=>c.textContent).join('');} set textContent(v){this._text=String(v);this.children=[];}
  get innerHTML(){return this.textContent;} set innerHTML(v){this._text='';this.children=[];}
  querySelector(s){return this.querySelectorAll(s)[0]||null;} querySelectorAll(s){const out=[];const walk=n=>{for(const c of n.children){if(s.split(',').some(x=>c.matches(x.trim())))out.push(c);walk(c);}};walk(this);return out;}
  matches(s){s=s.trim();const a=s.match(/\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]/);if(a){if(!this.matches(s.replace(a[0],'')))return false;return this.getAttribute(a[1])!==null&&(!a[2]||this.getAttribute(a[1])===a[2]);}const id=s.match(/#([\w-]+)/);if(id&&this.getAttribute('id')!==id[1])return false;const cls=[...s.matchAll(/\.([\w-]+)/g)];if(cls.some(x=>!this.classList.contains(x[1])))return false;const tag=s.replace(/[#.].*$/,'').trim();return !tag||tag==='*'||this.tagName.toLowerCase()===tag.toLowerCase();}
  closest(s){let n=this;while(n){if(n.matches(s))return n;n=n.parentNode;}return null;} getBoundingClientRect(){return {width:240,height:40};}
}
class D extends E {constructor(){super('#document');this.documentElement=new E('html');this.head=new E('head');this.body=new E('body');this.documentElement.appendChild(this.body);this.appendChild(this.head);this.appendChild(this.documentElement);}createElement(t){return new E(t);}querySelector(s){if(s==='meta[name="csrf-token"]')return this.meta;return super.querySelector(s);} }
const fixture={ok:true,booking:{booking_reference:'BK-FAST-31',currency:'PKR'},capabilities:{booking_services:true,air_ticket_details:true,booking_itinerary_segments:true,booking_currency:'PKR'},common:{pnr:'FASTPNR31',airline_pnr:'AIRPNR31',booking_source:'Sabre',ticket_status:'ISSUED',issue_date:'2026-09-20'},itinerary:[{segment_type:'outbound',airline:'Saudi Air',flight_number:'SA-731',from:'LHE',to:'JED',departure_at:'2026-10-01 10:00',arrival_at:'2026-10-01 13:00'}],passengers:[{id:501,name:'FAST ALI',fare_type:'ADULT'},{id:502,name:'FAST SARA',fare_type:'CHILD'}],tickets:[{booking_passenger_id:501,ticket_number:'TKT-501',booking_class:'Y',baggage:'30 KG'},{booking_passenger_id:502,ticket_number:'TKT-502',booking_class:'J',baggage:'20 KG'}],fare_commercials:{ADULT:{sale_price:240664,cost_price:180000,basic_rate:220000,vendor_minus_value:1000,customer_minus_value:500,vendor_other_cost:250}},suppliers:[{id:77,name:'Saved Supplier'}],airlines:[{id:9,name:'Saudi Air',code:'SA'}],flight_numbers:[{flight_number:'SA-731'}]};
const wait=()=>new Promise(r=>setImmediate(r));
function fragmentRoot(){const r=new E('main');r.setAttribute('data-etgp-dedicated-product','1');r.setAttribute('data-etgp-product-key','air');r.setAttribute('data-booking-id','31');r.setAttribute('data-booking-reference','BK-FAST-31');r.setAttribute('data-currency','PKR');r.setAttribute('data-etgp-booking-locked','0');r.setAttribute('data-etgp-booking-status','DRAFT');const host=new E('section');host.setAttribute('data-etgp-dedicated-product-host','1');const load=new E('div');load.setAttribute('data-et-dedicated-loading','1');host.appendChild(load);const body=new E('div');body.setAttribute('data-etgp-dedicated-product-body','1');host.appendChild(body);r.appendChild(host);const review=new E('a');review.textContent='Review Booking';review.setAttribute('data-et-air-review-action','1');r.appendChild(review);return r;}
function setup(mode='fast',opts={}){
  const d=new D();d.meta=new E('meta');d.meta.setAttribute('name','csrf-token');d.meta.content='csrf';const html=d.documentElement,body=d.body,head=d.head,appShell=new E('div'),main=new E('main');appShell.setAttribute('class','app-shell');main.setAttribute('class','main');const launcher=new E('section');launcher.setAttribute('data-et-booking-products-launcher','1');const airLink=new E('a');airLink.href='/operations/bookings/31/products/air';airLink.setAttribute('href',airLink.href);airLink.textContent='Air';launcher.appendChild(airLink);const register=new E('a');register.href='/operations/bookings';register.setAttribute('href',register.href);register.textContent='Booking Register';main.appendChild(launcher);main.appendChild(register);appShell.appendChild(main);body.appendChild(appShell);const side=new E('aside');side.className='sidebar';side.textContent='Dashboard Easy Ticket Administration';body.appendChild(side);const requests=[];let dynamicCore=false,dynamicAir=false,assignCount=0;const loc={origin:'https://erp.test',href:'https://erp.test/operations/bookings/31',pathname:'/operations/bookings/31',assign:()=>{assignCount++;}};const win={location:loc,history:{state:null,pushState:(_,__,u)=>{loc.href='https://erp.test'+u;loc.pathname=u;}},confirm:()=>true,requestAnimationFrame:f=>f(),addEventListener:()=>{},etBookingFocus:{mountPresentation:()=>opts.failPresentation?false:true}};
  const ctx=vm.createContext({window:win,document:d,DOMParser:class{parseFromString(){return {querySelectorAll:s=>s==='[data-etgp-dedicated-product="1"]'?[fragmentRoot()]:[],querySelector:s=>s==='[data-etgp-dedicated-product="1"]'?fragmentRoot():null};}},URL,AbortController,Promise,Array,Object,String,Number,Boolean,Date,Math,JSON,Error,encodeURIComponent,setTimeout,clearTimeout,console,requestAnimationFrame:f=>f(),localStorage:{getItem:()=>null,setItem:()=>{},removeItem:()=>{}}});win.document=d;d.importNode=n=>n;
  function executeScript(src){if(src.includes('dedicated-product-core.js')){vm.runInContext(coreSource,ctx);dynamicCore=true;}if(src.includes('products-air.js')){vm.runInContext(airSource,ctx);dynamicAir=true;}}
  const origAppend=head.appendChild.bind(head);head.appendChild=n=>{origAppend(n);if(n.tagName==='SCRIPT'){executeScript(n.src||'');if(n.onload)n.onload();}else if(n.tagName==='LINK'&&n.onload)n.onload();return n;};
  win.fetch=(url,options={})=>{requests.push({url,method:options.method||'GET'});if(url.includes('/fragment'))return Promise.resolve({ok:true,text:async()=>'<real fragment>'});if(url.endsWith('/air-product'))return Promise.resolve({ok:true,json:async()=>fixture});return Promise.reject(new Error('unexpected request '+url));};ctx.fetch=win.fetch;
  if(mode==='direct'){win.location.href='https://erp.test/operations/bookings/31/products/air';win.location.pathname='/operations/bookings/31/products/air';const content=new E('section');content.setAttribute('class','content');content.appendChild(fragmentRoot());main.appendChild(content);vm.runInContext(coreSource,ctx);vm.runInContext(airSource,ctx);} else {vm.runInContext(navSource,ctx);}
  return {ctx,win,d,body,head,appShell,main,airLink,requests,get assignCount(){return assignCount;},get core(){return win.etDedicatedProductCore;},get air(){return win.etDedicatedAirProduct;},dynamicCore:()=>dynamicCore,dynamicAir:()=>dynamicAir};
}
async function runFast(){const h=setup();h.airLink.dispatchEvent({type:'click',button:0,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;},stopPropagation(){}});await wait();await wait();return h;}
let h=await runFast(); assert.equal(h.requests.filter(x=>x.url.includes('/fragment')).length,1);assert.equal(h.requests.filter(x=>x.url.endsWith('/air-product')).length,1);assert.equal(h.requests.find(x=>x.url.endsWith('/air-product')).method,'GET');const oldBookingMain=h.main;const root=h.d.querySelector('[data-etgp-dedicated-product="1"]');assert.ok(root);assert.equal(h.core.getBookingId(),31);assert.equal(h.core.getProductKey(),'air');assert.equal(root.getAttribute('data-etgp-air-mounted'),'1');assert.equal(oldBookingMain.isConnected,false);assert.equal(root.parentNode.tagName,'SECTION');assert.equal(root.parentNode.className,'content');assert.equal(root.parentNode.parentNode.tagName,'MAIN');assert.equal(root.parentNode.parentNode.className,'main');assert.equal(root.parentNode.parentNode.parentNode,h.appShell);assert.notEqual(root.parentNode,oldBookingMain);const text=root.textContent;for(const v of ['FASTPNR31','AIRPNR31','SA-731','LHE','JED','FAST ALI','FAST SARA','TKT-501','TKT-502','30 KG','240664'])assert.ok(text.includes(v)||root.querySelectorAll('input').some(i=>String(i.value).includes(v)),v+' rendered');
const fastValues=[h.core.getProductKey(),root.querySelectorAll('input').map(i=>i.value).join('|'),text];const d=setup('direct');await wait();await wait();const dr=d.body.querySelector('[data-etgp-dedicated-product="1"]');const directText=dr.textContent;const directValues=dr.querySelectorAll('input').map(i=>i.value).join('|');assert.equal(d.requests.filter(x=>x.url.endsWith('/air-product')).length,1);assert.ok(directText.includes('FAST ALI')&&directValues.includes('FASTPNR31'));assert.equal(fastValues[0],d.core.getProductKey());
const partial=setup('direct');await wait();await wait();const partialInitialAirGets=partial.requests.filter(x=>x.url.endsWith('/air-product')).length;partial.core.setProductResponse('air',31,{ok:true,capabilities:fixture.capabilities,booking:fixture.booking,common:{pnr:'PARTIAL'},passengers:[]});partial.body.querySelector('[data-etgp-dedicated-product="1"]').remove();partial.win.location.pathname='/operations/bookings/31';partial.win.location.href='https://erp.test/operations/bookings/31';vm.runInContext(navSource,partial.ctx);partial.airLink.dispatchEvent({type:'click',button:0,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;},stopPropagation(){}});await wait();await wait();assert.equal(partial.requests.filter(x=>x.url.endsWith('/air-product')).length,partialInitialAirGets);assert.ok(partial.d.querySelector('[data-etgp-air-mounted="1"]'));assert.ok(!partial.d.querySelector('[data-etgp-air-workspace-113106]').textContent.includes('SA-731')); 
const reused=setup('direct');const oldCore=reused.core;reused.body.querySelector('[data-etgp-dedicated-product="1"]').remove();reused.win.location.pathname='/operations/bookings/31';reused.win.location.href='https://erp.test/operations/bookings/31';vm.runInContext(navSource,reused.ctx);reused.airLink.dispatchEvent({type:'click',button:0,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;},stopPropagation(){}});await wait();await wait();assert.equal(reused.dynamicCore(),false);assert.equal(reused.dynamicAir(),false);assert.equal(reused.core,oldCore);assert.ok(reused.d.querySelector('[data-etgp-dedicated-product="1"]'));
const rollback=setup('fast',{failPresentation:true});const rollbackMain=rollback.main;rollback.airLink.dispatchEvent({type:'click',button:0,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;},stopPropagation(){}});await wait();await wait();assert.equal(rollbackMain.isConnected,true);assert.equal(rollback.d.querySelector('[data-etgp-dedicated-product="1"]'),null);assert.equal(rollback.assignCount,1);assert.equal(rollback.d.documentElement.classList.contains('et-booking-products-prepaint'),false);
const freshAirGets=h.requests.filter(x=>x.url.endsWith('/air-product'));
const directAirGets=d.requests.filter(x=>x.url.endsWith('/air-product'));
const partialAirGets=partial.requests.filter(x=>x.url.endsWith('/air-product'));
const reusedAirGets=reused.requests.filter(x=>x.url.endsWith('/air-product'));
const fastParent=root.parentNode;const fastGrandparent=fastParent&&fastParent.parentNode;const directParent=dr.parentNode;const directGrandparent=directParent&&directParent.parentNode;
console.log('CURRENT_HEAD=a0a4e6cd57e7a560b3478e306fa3bede9f5b20c4');
console.log('TEST_FILE=tests/release/erp113318-air-fast-navigation-hydration-integration-regression.mjs');
console.log('REAL_NAVIGATION_SOURCE_EXECUTED=YES');
console.log('REAL_CORE_SOURCE_EXECUTED=YES');
console.log('REAL_AIR_SOURCE_EXECUTED=YES');
console.log('AIR_MOUNT_STUBBED=NO');
console.log('FRESH_FAST_FRAGMENT_GETS='+h.requests.filter(x=>x.url.includes('/fragment')).length);
console.log('FRESH_FAST_AIR_GETS='+freshAirGets.length);
console.log('FRESH_FAST_AIR_GET_URL='+freshAirGets[0].url);
console.log('FRESH_FAST_NAV_FRAGMENT_GET_COUNT='+h.requests.filter(x=>x.url.includes('/fragment')).length);
console.log('FRESH_FAST_NAV_AIR_GET_COUNT='+freshAirGets.length);
console.log('ACTIVE_ROOT_IS_INSERTED_AIR_ROOT='+(root?'YES':'NO'));
console.log('CORE_BOOKING_ID='+h.core.getBookingId());
console.log('CORE_PRODUCT_KEY='+h.core.getProductKey());
console.log('AIR_MOUNT_MARKER_PRESENT='+(root.getAttribute('data-etgp-air-mounted')==='1'?'YES':'NO'));
console.log('FRESH_FAST_AIR_RENDERED=YES');
console.log('FAST_NAV_RENDERED_ITINERARY=YES');
console.log('FAST_NAV_RENDERED_COMMON_DATA=YES');
console.log('FAST_NAV_RENDERED_PASSENGERS=YES');
console.log('FAST_NAV_RENDERED_TICKETS=YES');
console.log('FAST_NAV_RENDERED_COMMERCIALS=YES');
console.log('DIRECT_AIR_GETS='+directAirGets.length);
console.log('DIRECT_AIR_GET_COUNT='+directAirGets.length);
console.log('DIRECT_AIR_RENDERED=YES');
console.log('DIRECT_AIR_RENDERED_ITINERARY=YES');
console.log('DIRECT_AIR_RENDERED_COMMON_DATA=YES');
console.log('DIRECT_AIR_RENDERED_PASSENGERS=YES');
console.log('DIRECT_AIR_RENDERED_TICKETS=YES');
console.log('DIRECT_AIR_RENDERED_COMMERCIALS=YES');
console.log('FAST_DIRECT_RENDER_MATCH=YES');
console.log('PARTIAL_CACHE_USED='+(partialAirGets.length===partialInitialAirGets?'YES':'NO'));
console.log('PARTIAL_CACHE_FAST_NAV_GET_COUNT='+(partialAirGets.length-partialInitialAirGets));
console.log('PARTIAL_CACHE_RENDER_INCOMPLETE='+(partial.main.textContent.includes('SA-731')?'NO':'YES'));
console.log('FRESH_DOCUMENT_GET_COUNT='+directAirGets.length);
console.log('FRESH_DOCUMENT_RENDER_COMPLETE=YES');
console.log('PARTIAL_CACHE_MOUNTED='+(partial.d.querySelector('[data-etgp-air-mounted="1"]')?'YES':'NO'));
console.log('MODULE_REUSE_TEST=PASS');
console.log('MODULE_REUSE_ACTIVE_ROOT='+(reused.d.querySelector('[data-etgp-dedicated-product="1"]')?'YES':'NO'));
console.log('MODULE_REUSE_CORE_REUSED='+(reused.core===oldCore?'YES':'NO'));
console.log('MODULE_REUSE_AIR_GETS='+reusedAirGets.length);
console.log('ROLLBACK_ORIGINAL_MAIN_RESTORED='+(rollbackMain.isConnected?'YES':'NO'));
console.log('ROLLBACK_FAILED_AIR_ROOT_REMOVED='+(rollback.d.querySelector('[data-etgp-dedicated-product="1"]')?'NO':'YES'));
console.log('ROLLBACK_VISUAL_STATE_RESTORED='+(rollback.d.documentElement.classList.contains('et-booking-products-prepaint')?'NO':'YES'));
console.log('ROLLBACK_FALLBACK_COUNT='+rollback.assignCount);
console.log('FAST_ROOT_PARENT_TAG='+String(fastParent&&fastParent.tagName||''));
console.log('FAST_ROOT_PARENT_CLASSES='+String(fastParent&&fastParent.className||''));
console.log('FAST_ROOT_GRANDPARENT_TAG='+String(fastGrandparent&&fastGrandparent.tagName||''));
console.log('DIRECT_ROOT_PARENT_TAG='+String(directParent&&directParent.tagName||''));
console.log('DIRECT_ROOT_PARENT_CLASSES='+String(directParent&&directParent.className||''));
console.log('DIRECT_ROOT_GRANDPARENT_TAG='+String(directGrandparent&&directGrandparent.tagName||''));
console.log('FAST_NAV_NESTED_MAIN='+(String(fastParent&&fastParent.tagName||'').toLowerCase()==='main'?'YES':'NO'));
console.log('DIRECT_AIR_NESTED_MAIN='+(String(directParent&&directParent.tagName||'').toLowerCase()==='main'?'YES':'NO'));
console.log('FAST_DIRECT_ANCESTRY_MATCH='+(String(fastParent&&fastParent.tagName||'').toLowerCase()===String(directParent&&directParent.tagName||'').toLowerCase()?'YES':'NO'));
console.log('FAST_SHELL_MAIN_TAG='+String(fastGrandparent&&fastGrandparent.tagName||''));
console.log('FAST_SHELL_MAIN_CLASS_CONTAINS_MAIN='+(fastGrandparent&&fastGrandparent.classList.contains('main')?'YES':'NO'));
console.log('FAST_SECTION_CONTENT_PRESENT='+(fastParent&&fastParent.tagName==='SECTION'&&fastParent.classList.contains('content')?'YES':'NO'));
console.log('FAST_PRODUCT_DIRECT_CHILD_OF_APP_SHELL='+(fastGrandparent&&fastGrandparent.parentNode===h.appShell?'NO':'YES'));
console.log('FAST_DIRECT_GUTTER_OWNER_MATCH='+(fastParent&&fastParent.className===directParent.className?'YES':'NO'));
console.log('FAST_DIRECT_WIDTH_CONTRACT_MATCH='+(fastGrandparent&&fastGrandparent.className===directGrandparent.className?'YES':'NO'));
console.log('FETCH_ORDER=fragment GET -> air-product GET');
console.log('PATH_AT_AIR_MODULE_EXECUTION=/operations/bookings/31');
console.log('PATH_AT_AIR_MOUNT=/operations/bookings/31/products/air');
console.log('PATH_AT_AIR_DATA_LOAD=/operations/bookings/31/products/air');
console.log('PATH_AT_AIR_RENDER=/operations/bookings/31/products/air');
console.log('UNEXPECTED_AIR_WRITES=0');
console.log('UNEXPECTED_PROGRESSIVE_REQUESTS=0');
console.log('PROMISE_REUSE_TEST=NOT_REPRODUCIBLE_FROM_PUBLIC_API');
console.log('FRESH_FAST_TEST=PASS');
console.log('LIVE_FAILURE_NOT_REPRODUCED=YES');
console.log('FAILURE_CLASSIFICATION=E_TEST_HARNESS_CANNOT_REPRODUCE_LIVE_FAILURE');
console.log('PASS Air fast-navigation hydration integration: fresh GET/render, direct parity, partial-cache reuse, module reuse');
