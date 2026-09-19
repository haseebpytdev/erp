import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/erp-theme/js/dedicated-product-navigation.js', import.meta.url), 'utf8');
class Node {
  constructor(tag='div') { this.tagName=tag.toUpperCase(); this.children=[]; this.parentNode=null; this.attrs={}; this.dataset={}; this.listeners={}; this.innerHTML=''; this.href=''; this.target=''; }
  appendChild(n){n.parentNode=this;this.children.push(n);if(n.onload)n.onload();return n;}
  remove(){if(this.parentNode){this.parentNode.children=this.parentNode.children.filter(x=>x!==this);this.parentNode=null;}}
  setAttribute(k,v){this.attrs[k]=String(v);if(k==='href')this.href=String(v);if(k.startsWith('data-'))this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(v);}
  removeAttribute(k){delete this.attrs[k];}
  getAttribute(k){return this.attrs[k]??null;}
  hasAttribute(k){return this.getAttribute(k)!==null;}
  addEventListener(t,f){(this.listeners[t]??=[]).push(f);}
  dispatchEvent(e){for(const f of this.listeners[e.type]||[])f.call(this,e);}
  closest(s){let n=this;while(n){if(n.matches(s))return n;n=n.parentNode;}return null;}
  matches(s){const attr=s.match(/^([\w-]+)?\[([^=\]]+)(?:="?([^\]"]+)"?)?\]$/);if(attr){if(attr[1]&&this.tagName!==attr[1].toUpperCase())return false;return this.getAttribute(attr[2])!==null&&(attr[3]===undefined||this.getAttribute(attr[2])===attr[3]);}return this.tagName===s.toUpperCase();}
  querySelectorAll(s){const out=[];const walk=n=>{for(const c of n.children){if(c.matches(s))out.push(c);walk(c);}};walk(this);return out;}
  querySelector(s){return this.querySelectorAll(s)[0]||null;}
}
const build = () => {
  const body=new Node('body'),head=new Node('head'),main=new Node('main'),launcher=new Node('section');
  launcher.setAttribute('data-et-booking-products-launcher','1');
  for(const [name,path] of [['air','air'],['hotel','hotel'],['transport','transport'],['visa','visa'],['other-services','other-services']]){const a=new Node('a');a.textContent=name;a.setAttribute('href','/operations/bookings/31/products/'+path);launcher.appendChild(a);}
  const review=new Node('a');review.setAttribute('data-et-booking-review-entry','1');review.textContent='Review Booking';body.appendChild(review);main.appendChild(launcher);body.appendChild(main);const sidebar=new Node('aside');sidebar.setAttribute('data-sidebar-sentinel','1');body.appendChild(sidebar);
  const doc=new Node('#document');doc.head=head;doc.body=body;doc.appendChild(head);doc.appendChild(body);return {doc,body,head,main,launcher,review,sidebar,air:launcher.children[0]};
};
const page=build(); let assigned='';let fetches=[];let pushed=[];let replaced=[];const location={href:'https://erp.test/operations/bookings/31',origin:'https://erp.test',assign:u=>{assigned=u;}};
const api={mount:root=>{replaced.push(root);return true;},getState:()=>({saveInFlight:false,dirty:false,draftPending:false})};
const fragmentRoot=new Node('main');fragmentRoot.setAttribute('data-etgp-dedicated-product','1');fragmentRoot.setAttribute('data-etgp-product-key','air');fragmentRoot.setAttribute('data-booking-id','31');const host=new Node('div');host.setAttribute('data-etgp-dedicated-product-host','1');const mount=new Node('div');mount.setAttribute('data-etgp-dedicated-product-body','1');host.appendChild(mount);fragmentRoot.appendChild(host);const fragmentReview=new Node('a');fragmentReview.textContent='Review Booking';fragmentRoot.appendChild(fragmentReview);
const fragmentDoc={querySelectorAll:s=>s==='[data-etgp-dedicated-product="1"]'?[fragmentRoot]:[],querySelector:s=>s==='[data-etgp-dedicated-product="1"]'?fragmentRoot:null};
const document={...page.doc,querySelector:s=>{if(s==='[data-et-booking-products-launcher="1"]')return page.launcher;if(s==='script[data-et-dedicated-product-navigation]'){const n=new Node('script');n.setAttribute('data-et-dedicated-product-navigation','ERP-11.3.315');return n;}return page.doc.querySelector(s);},querySelectorAll:s=>page.doc.querySelectorAll(s),createElement:t=>new Node(t),importNode:n=>n,head:page.head};
const window={location,document,etDedicatedAirProduct:api,etDedicatedProductCore:{},history:{state:null,pushState:(s,t,u)=>pushed.push(u),replaceState:()=>{}},confirm:()=>true,addEventListener:()=>{}};
const ctx=vm.createContext({window,document,DOMParser:class{parseFromString(){return fragmentDoc;}},URL,AbortController,fetch:(url,opt)=>{fetches.push({url,opt});return Promise.resolve({ok:true,text:async()=>'<main data-etgp-dedicated-product="1"></main>'});},Promise,Array,String,Number,Error,encodeURIComponent});
// Existing assets are marked ready so this execution exercises the real navigation transaction without loading arbitrary script text.
vm.runInContext(source,ctx);
assert.equal(page.air.href,'/operations/bookings/31/products/air');
assert.ok(page.air.listeners.click && page.air.listeners.click.length===1);
page.air.dispatchEvent({type:'click',button:0,defaultPrevented:false,metaKey:false,ctrlKey:false,shiftKey:false,altKey:false,preventDefault(){this.prevented=true;}});
await new Promise(r=>setImmediate(r));
assert.equal(fetches[0].url,'/operations/bookings/31/products/air/fragment');
assert.equal(fetches[0].opt.credentials,'same-origin');
assert.equal(fetches[0].opt.headers.Accept,'text/html');
assert.equal(fetches[0].opt.headers['X-Requested-With'],'XMLHttpRequest');
assert.equal(pushed[0],'/operations/bookings/31/products/air');
assert.equal(replaced[0],fragmentRoot);
assert.equal(page.review.parentNode,null);
assert.ok(page.sidebar.parentNode===page.body);
console.log('PASS 9 Air fast-navigation behavioral assertions');
