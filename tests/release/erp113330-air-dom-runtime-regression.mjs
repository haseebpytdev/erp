import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

class Node {
  constructor(tag='div') { this.tagName=tag.toUpperCase(); this.children=[]; this.parentNode=null; this.attributes={}; this.listeners={}; this.hidden=false; this.value=''; this.textContent=''; this.classList={_s:new Set(),add:(...x)=>x.forEach(v=>this.classList._s.add(v)),remove:(...x)=>x.forEach(v=>this.classList._s.delete(v)),contains:v=>this.classList._s.has(v),toggle:(v,on)=>on?this.classList._s.add(v):this.classList._s.delete(v)}; }
  appendChild(n){this.children.push(n);n.parentNode=this;return n;}
  removeChild(n){n.remove();}
  remove(){if(this.parentNode)this.parentNode.children=this.parentNode.children.filter(x=>x!==this);this.parentNode=null;}
  setAttribute(k,v){this.attributes[k]=String(v);}
  getAttribute(k){return this.attributes[k]??null;}
  removeAttribute(k){delete this.attributes[k];}
  addEventListener(k,fn){(this.listeners[k]??=[]).push(fn);}
  removeEventListener(k,fn){this.listeners[k]=(this.listeners[k]||[]).filter(x=>x!==fn);}
  dispatchEvent(e){e.target??=this;(this.listeners[e.type]||[]).slice().forEach(fn=>fn.call(this,e));return !e.defaultPrevented;}
  click(){this.dispatchEvent(new Ev('click'));}
  contains(n){return n===this||this.children.some(x=>x.contains(n));}
  matches(sel){if(sel==='*')return true;if(sel.startsWith('.'))return this.classList.contains(sel.slice(1));if(sel.startsWith('[')){let m=sel.match(/^\[([^=\]]+)(?:="?([^\]"]+)"?)?\]$/);return !!m&&this.getAttribute(m[1])!==null&&(!m[2]||this.getAttribute(m[1])===m[2]);}return this.tagName.toLowerCase()===sel.toLowerCase();}
  querySelectorAll(sel){let out=[];for(const c of this.children){if(c.matches(sel))out.push(c);out.push(...c.querySelectorAll(sel));}return out;}
  querySelector(sel){return this.querySelectorAll(sel)[0]||null;}
}
class Ev { constructor(type, init={}){this.type=type;this.key=init.key;this.target=init.target||null;this.defaultPrevented=false;} preventDefault(){this.defaultPrevented=true;} }
class Doc extends Node { constructor(){super('#document');this.documentElement=this;this.body=new Node('body');this.appendChild(this.body);} createElement(t){return new Node(t);} }
const document=new Doc();
const core={create:(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.classList.add(...cls.split(/\s+/));if(text)n.textContent=text;return n;},plain:v=>String(v??'').trim(),norm:v=>String(v??'').toLowerCase(),getBookingRoot:()=>null};
const window={etDedicatedProductCore:core};
const source=fs.readFileSync('public/erp-theme/js/products/air.js','utf8');
const exposed=source.replace("window.etDedicatedAirProduct={mount:mountAir,getState:function(){return {saveInFlight:airLifecycle.saveInFlight,dirty:airLifecycle.dirty,draftPending:airLifecycle.draftPending,bookingId:airLifecycle.bookingId};}};", "window.__test={etgpAirSearchableAirline113330,etgpAirDefaultSegmentType113329,etgpAirIsBlankUnsavedSegment113330,etgpAirNormalizeBlankSegments113330,renderPageItinerary113324,renderTicketGroupEditor113106};$&");
vm.runInNewContext(exposed,{window,document,Event:Ev,localStorage:{getItem:()=>null,setItem(){},removeItem(){}},requestAnimationFrame:fn=>fn(),console});
const t=window.__test;let n=0;const ok=(v,m)=>{assert.equal(Boolean(v),true,m);n++;};
ok(t.etgpAirDefaultSegmentType113329(0)==='outbound','default 0');ok(t.etgpAirDefaultSegmentType113329(1)==='return','default 1');ok(t.etgpAirDefaultSegmentType113329(2)==='connection','default 2');ok(t.etgpAirDefaultSegmentType113329(3)==='connection','default 3');
const airlines=[{id:1,code:'SV',name:'Saudia',label:'Saudia (SV)'},{id:2,code:'EK',name:'Emirates',label:'Emirates (EK)'},{id:3,code:'PK',name:'Pakistan International Airlines',label:'Pakistan International Airlines (PK)'}];
const c=t.etgpAirSearchableAirline113330('Airline','',airlines,'');document.body.appendChild(c.unit);c.input.value='sau';c.input.dispatchEvent(new Ev('input'));ok(c.unit.querySelector('[role="option"]').textContent.includes('Saudia'),'name filter');c.input.dispatchEvent(new Ev('keydown',{key:'ArrowDown'}));ok(c.input.getAttribute('data-etgp-airline-active')==='0','first arrow');c.input.dispatchEvent(new Ev('keydown',{key:'Enter'}));ok(c.getSelected().id===1,'enter select');const invalid=t.etgpAirSearchableAirline113330('Airline','',airlines,'');document.body.appendChild(invalid.unit);invalid.input.value='XYZ';invalid.input.dispatchEvent(new Ev('input'));document.dispatchEvent(new Ev('click',{target:document.body}));ok(invalid.getSelected()===null&&invalid.input.value==='','invalid clears');c.input.value='XYZ';c.input.dispatchEvent(new Ev('input'));document.dispatchEvent(new Ev('click',{target:document.body}));ok(c.getSelected().id===1&&c.input.value.includes('Saudia'),'invalid reverts');
const saved=t.etgpAirSearchableAirline113330('Airline','2',airlines,'');ok(saved.getSelected().code==='EK','saved id rehydrates');saved.input.dispatchEvent(new Ev('keydown',{key:'Escape'}));ok(saved.popup===undefined||saved.input.getAttribute('aria-expanded')==='false','escape closes');
const pageState={segments:[],groups:[{client_key:'g1',segment_keys:[]} ]};const itineraryHost=document.createElement('div');const pageData={airlines:[]};const rerender=()=>{itineraryHost.children=[];t.renderPageItinerary113324(itineraryHost,pageState,pageData,rerender);};t.renderPageItinerary113324(itineraryHost,pageState,pageData,rerender);const rowCount=()=>itineraryHost.querySelectorAll('[data-etgp-air-segment-113106]').length;ok(pageState.segments.length===1&&rowCount()===1,'multi initial bootstrap');ok(pageState.segments[0].segment_type==='outbound','multi outbound');itineraryHost.querySelectorAll('button')[0].click();ok(pageState.segments[1].segment_type==='return','multi return');itineraryHost.querySelectorAll('button')[0].click();ok(pageState.segments[2].segment_type==='connection','multi connection');itineraryHost.querySelectorAll('button')[0].click();ok(pageState.segments[3].segment_type==='connection','multi fourth connection');
const blank=t.etgpAirNormalizeBlankSegments113330([{segment_type:'connection'},{segment_type:'connection'},{segment_type:'connection'}]);ok(blank.map(x=>x.segment_type).join(',')==='outbound,return,connection','blank normalize');ok(t.etgpAirNormalizeBlankSegments113330([{id:91,segment_type:'connection',from:'LHE'}])[0].segment_type==='connection','persisted preserved');ok(t.etgpAirNormalizeBlankSegments113330([{id:0,segment_type:'connection',from:'LHE'}])[0].segment_type==='connection','meaningful draft preserved');
console.log(`ERP-11.3.330 Air DOM/VM runtime regression: PASS (${n} behavioral assertions)`);
