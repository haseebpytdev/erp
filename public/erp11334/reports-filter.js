(function(){
'use strict';

var form=document.querySelector('[data-et-report-filter="ERP-11.3.34"]');
if(!form)return;
form.setAttribute('data-et-report-js-running','1');

var report=form.querySelector('[data-et-report-type="1"]');
var wrap=form.querySelector('[data-et-report-subject-wrap="1"]');
var label=form.querySelector('[data-et-report-subject-label="1"]');
var subject=form.querySelector('[data-et-report-subject="1"]');
var dataNode=form.querySelector('[data-et-report-datasets="ERP-11.3.34"]');

if(!report||!wrap||!label||!subject||!dataNode)return;

var datasets={vendor:[],customer:[],party:[],account:[]};

try{
  datasets=JSON.parse(dataNode.textContent||'{}');
}catch(e){}

var partyField=form.getAttribute('data-party-field')||'party_id';
var accountField=form.getAttribute('data-account-field')||'account_id';
var currentParty=form.getAttribute('data-current-party')||'';
var currentAccount=form.getAttribute('data-current-account')||'';

function textOfSelected(){
  var option=report.options && report.selectedIndex>=0
    ? report.options[report.selectedIndex]
    : null;
  return String(option ? option.textContent : '').replace(/\s+/g,' ').trim().toLowerCase();
}

function mode(){
  var text=textOfSelected();

  if(text.indexOf('vendor')!==-1 || text.indexOf('supplier')!==-1)return 'vendor';
  if(text.indexOf('customer')!==-1 || text.indexOf('client')!==-1)return 'customer';
  if(text.indexOf('party')!==-1)return 'party';
  if(
    text.indexOf('account')!==-1 ||
    text.indexOf('general ledger')!==-1 ||
    text.indexOf('gl ledger')!==-1
  )return 'account';

  return 'general';
}

function configFor(m){
  if(m==='vendor')return {label:'Vendor',placeholder:'Select vendor',name:partyField,rows:datasets.vendor||[]};
  if(m==='customer')return {label:'Customer',placeholder:'Select customer',name:partyField,rows:datasets.customer||[]};
  if(m==='party')return {label:'Party',placeholder:'Select party',name:partyField,rows:datasets.party||[]};
  if(m==='account')return {label:'Account',placeholder:'Select account',name:accountField,rows:datasets.account||[]};
  return {label:'Ledger',placeholder:'Not required',name:'',rows:[]};
}

function addOption(value,text,selected){
  var opt=document.createElement('option');
  opt.value=String(value==null?'':value);
  opt.textContent=String(text==null?'':text);
  if(selected)opt.selected=true;
  subject.appendChild(opt);
}

function sync(initial){
  var m=mode();
  var cfg=configFor(m);

  label.textContent=cfg.label;
  subject.innerHTML='';

  if(m==='general'){
    subject.removeAttribute('name');
    subject.disabled=true;
    wrap.classList.add('et-rf-subject-hidden');
    addOption('',cfg.placeholder,true);
    return;
  }

  wrap.classList.remove('et-rf-subject-hidden');
  subject.disabled=false;
  subject.name=cfg.name;

  var wanted='';
  if(initial){
    wanted=m==='account' ? currentAccount : currentParty;
  }

  addOption('',cfg.placeholder,wanted==='');

  (cfg.rows||[]).forEach(function(row){
    var value=String(row && row.value!=null ? row.value : '');
    var text=String(row && row.label!=null ? row.label : '').trim();
    if(!value||!text)return;
    addOption(value,text,wanted!=='' && wanted===value);
  });

  // If the previously selected value no longer exists, return to placeholder.
  if(wanted!=='' && subject.value!==wanted){
    subject.value='';
  }
}

report.addEventListener('change',function(){
  currentParty='';
  currentAccount='';
  sync(false);
});

sync(true);
})();
