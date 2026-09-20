import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

class Node {
  constructor(tag='div') { this.tagName=tag.toUpperCase(); this.children=[]; this.parentNode=null; this.attributes={}; this.style={setProperty:(k,v)=>{this.style[k]=v;}}; this.listeners={}; this.classList={add:(...c)=>{const s=this._classes();c.forEach(x=>s.add(x));this.attributes.class=[...s].join(' ');},remove:(...c)=>{const s=this._classes();c.forEach(x=>s.delete(x));this.attributes.class=[...s].join(' ');},contains:c=>this._classes().has(c)}; }
  _classes(){ return new Set(String(this.attributes.class||'').split(/\s+/).filter(Boolean)); }
  get className(){return this.attributes.class||'';} set className(v){this.attributes.class=String(v);}
  appendChild(n){ if(n.parentNode)n.parentNode.removeChild(n); this.children.push(n); n.parentNode=this; return n; }
  insertBefore(n,r){ if(n.parentNode)n.parentNode.removeChild(n); const i=this.children.indexOf(r); if(i<0)return this.appendChild(n); this.children.splice(i,0,n); n.parentNode=this; return n; }
  replaceChild(n,o){ const i=this.children.indexOf(o); if(i>=0){if(n.parentNode)n.parentNode.removeChild(n); this.children[i]=n; n.parentNode=this; o.parentNode=null;} return o; }
  removeChild(n){const i=this.children.indexOf(n); if(i>=0){this.children.splice(i,1);n.parentNode=null;} return n;}
  remove(){if(this.parentNode)this.parentNode.removeChild(this);}
  setAttribute(k,v){this.attributes[k]=String(v); if(k==='class')this.attributes.class=String(v);}
  getAttribute(k){return Object.prototype.hasOwnProperty.call(this.attributes,k)?this.attributes[k]:null;}
  hasAttribute(k){return this.getAttribute(k)!==null;}
  removeAttribute(k){delete this.attributes[k];}
  addEventListener(k,fn){(this.listeners[k]??=[]).push(fn);}
  dispatchEvent(e){e.target=this;(this.listeners[e.type]||[]).forEach(fn=>fn(e)); return !e.defaultPrevented;}
  get textContent(){return this._text??this.children.map(c=>c.textContent).join('');}
  set textContent(v){this._text=String(v);this.children=[];}
  get href(){return this.getAttribute('href')||'';} set href(v){this.setAttribute('href',v);}
  get parentElement(){return this.parentNode;}
  contains(n){if(n===this)return true; return this.children.some(c=>c.contains(n));}
  matches(sel){return match(this,sel);}
  closest(sel){let n=this; while(n){if(n.matches(sel))return n;n=n.parentNode;} return null;}
  querySelector(sel){return this.querySelectorAll(sel)[0]||null;}
  querySelectorAll(sel){let out=[]; const walk=n=>{n.children.forEach(c=>{if(match(c,sel))out.push(c);walk(c);});};walk(this);return out;}
}
function match(n,sel){
  return sel.split(',').some(s=>{s=s.trim(); if(!s)return false; const attr=[...s.matchAll(/\[([^\]=]+)(?:=["']?([^\]"']+)["']?)?\]/g)]; for(const a of attr){if(!n.hasAttribute(a[1])||(a[2]&&n.getAttribute(a[1])!==a[2]))return false;} s=s.replace(/\[[^\]]+\]/g,''); const id=s.match(/#([\w-]+)/);if(id&&n.getAttribute('id')!==id[1])return false; const cls=[...s.matchAll(/\.([\w-]+)/g)];if(cls.some(c=>!n.classList.contains(c[1])))return false; const tag=s.replace(/[#.].*$/,'').trim(); return !tag||tag==='*'||n.tagName.toLowerCase()===tag.toLowerCase();});
}
function harness(withUnified=false){
  const html=new Node('html'), body=new Node('body'); html.appendChild(body); html.setAttribute('class','et-booking-focus-prepaint');
  const document={documentElement:html,body, listeners:{}, createElement:t=>new Node(t), addEventListener(k,fn){(this.listeners[k]??=[]).push(fn);}, querySelector:s=>html.querySelector(s), querySelectorAll:s=>html.querySelectorAll(s)};
  const window={document,location:{origin:'https://erp.test',pathname:'/operations/bookings/1'},listeners:{},addEventListener(k,fn){(this.listeners[k]??=[]).push(fn);},getComputedStyle:()=>({display:'block',visibility:'visible'}),requestAnimationFrame:fn=>fn()};
  Node.prototype.getBoundingClientRect=function(){return {width:240,height:40};};
  const sidebar=new Node('aside');sidebar.classList.add('sidebar');sidebar.textContent='Dashboard Easy Ticket Administration'; const booking=new Node('a');booking.href='/operations/bookings';booking.textContent='Bookings';sidebar.appendChild(booking); body.appendChild(sidebar);
  const main=new Node('main'); main.setAttribute('data-booking-workspace','1'); const actions=new Node('div');actions.setAttribute('data-et-action-row','1'); const register=new Node('a');register.href='/operations/bookings';register.textContent='Booking Register';actions.appendChild(register);main.appendChild(actions);body.appendChild(main);
  if(withUnified){const u=new Node('button');u.setAttribute('id','gp-focus-menu');body.appendChild(u);}
  const context={window,document,Node,requestAnimationFrame:window.requestAnimationFrame,console}; vm.createContext(context); return {context,html,body,sidebar,main,document,window};
}
const src=fs.readFileSync('public/erp11335/booking-focus.js','utf8'); const first=src.slice(0,src.indexOf('\n\n/* ========================================================================',src.indexOf('})();')+4));
const h=harness(); vm.runInContext(first,h.context); const api=h.window.etBookingFocus;
const count=s=>h.document.querySelectorAll(s).length;
assert.equal(count('.et-booking-focus-overlay'),1); assert.equal(count('[data-et-booking-focus-toolbar="1"]'),1); assert.equal(count('[data-et-booking-focus-menu="1"]'),1); assert.equal(h.document.listeners.keydown.length,1); assert.equal(h.window.listeners.pageshow.length,1);
api.mountPresentation(h.main); assert.equal(count('.et-booking-focus-overlay'),1); assert.equal(count('[data-et-booking-focus-toolbar="1"]'),1); assert.equal(count('[data-et-booking-focus-menu="1"]'),1); assert.equal(h.document.listeners.keydown.length,1); assert.equal(h.window.listeners.pageshow.length,1);
const oldMain=h.main; oldMain.remove(); const next=new Node('main'); next.setAttribute('data-booking-workspace','1'); const nextActions=new Node('div'); const nextRegister=new Node('a'); nextRegister.href='/operations/bookings'; nextRegister.textContent='Booking Register'; nextActions.appendChild(nextRegister); next.appendChild(nextActions); h.body.appendChild(next); api.mountPresentation(next);
assert.equal(count('.et-booking-focus-overlay'),1); assert.equal(count('[data-et-booking-focus-toolbar="1"]'),1); assert.equal(count('[data-et-booking-focus-menu="1"]'),1); assert.equal(h.document.listeners.keydown.length,1); assert.equal(h.window.listeners.pageshow.length,1); assert.equal(next.querySelector('[data-et-booking-focus-menu="1"]')!==null,true); assert.equal(oldMain.parentNode,null);
const menu=next.querySelector('[data-et-booking-focus-menu="1"]'), overlay=h.document.querySelector('.et-booking-focus-overlay'); menu.dispatchEvent({type:'click',preventDefault(){this.defaultPrevented=true;},stopPropagation(){}}); assert.equal(h.sidebar.classList.contains('et-booking-focus-sidebar-open'),true); overlay.dispatchEvent({type:'click',preventDefault(){this.defaultPrevented=true;}}); assert.equal(h.sidebar.classList.contains('et-booking-focus-sidebar-open'),false); menu.dispatchEvent({type:'click',preventDefault(){},stopPropagation(){}}); h.document.listeners.keydown[0]({key:'Escape'}); assert.equal(h.sidebar.classList.contains('et-booking-focus-sidebar-open'),false);
const u=harness(true); vm.runInContext(first,u.context); assert.equal(u.document.querySelector('#gp-focus-menu')!==null,true); assert.equal(u.document.querySelectorAll('[data-et-booking-focus-toolbar="1"]').length,0);
console.log('PASS 23 booking-focus presentation idempotency assertions');
