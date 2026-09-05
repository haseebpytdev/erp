(function(){
'use strict';

var html=document.documentElement;

if(
  !html.classList.contains(
    'et-general-progressive-step1-11390'
  )
){
  return;
}

var VERSION='ERP-11.3.142';


/*
 * If parsing/building succeeds slowly or a required native panel is missing,
 * reveal the native page instead of leaving section.content hidden.
 */
var nativeRevealFallback11390=window.setTimeout(
  function(){
    if(
      !html.classList.contains(
        'etgp-step1-ready-11390'
      )
    ){
      html.classList.add(
        'etgp-step1-fallback-11390'
      );
    }
  },
  6000
);

var norm=function(value){
  return String(value||'')
    .replace(/\s+/g,' ')
    .trim()
    .toLowerCase();
};

var plain=function(value){
  return String(value||'')
    .replace(/\s+/g,' ')
    .trim();
};

var create=function(tag,className,text){
  var node=document.createElement(tag);

  if(className){
    node.className=className;
  }

  if(text!==undefined&&text!==null){
    node.textContent=String(text);
  }

  return node;
};

var exactNodes=function(root,label){
  if(!root)return [];

  var wanted=norm(label);

  return Array.prototype.slice.call(
    root.querySelectorAll(
      'h1,h2,h3,h4,h5,h6,strong,b,label,small,span,div,p,dt,th'
    )
  ).filter(function(node){
    return norm(node.textContent)===wanted;
  });
};

var outsideEditor=function(node){
  return !node.closest(
    'form,details,[hidden],[aria-hidden="true"]'
  );
};

var summaryLabels=[
  'Booking Type',
  'Booking Date',
  'Customer',
  'Branch',
  'Agent / Service Partner',
  'Salesperson',
  'Travel',
  'Currency'
];

var countSummaryLabels=function(node){
  if(!node)return 0;

  return summaryLabels.filter(function(label){
    return exactNodes(
      node,
      label
    ).filter(outsideEditor).length>0;
  }).length;
};

var compactVisibleText=function(node){
  if(!node)return '';

  var clone=node.cloneNode(true);

  Array.prototype.slice.call(
    clone.querySelectorAll(
      'form,details,[hidden],[aria-hidden="true"],script,style'
    )
  ).forEach(function(el){
    el.remove();
  });

  return plain(
    clone.textContent
  );
};

var summaryCell=function(panel,label){
  if(!panel)return null;

  var candidates=exactNodes(
    panel,
    label
  ).filter(outsideEditor);

  for(
    var c=0;
    c<candidates.length;
    c++
  ){
    var current=candidates[c];

    for(
      var depth=0;
      depth<5&&current;
      depth++
    ){
      if(
        depth>0
        && countSummaryLabels(current)===1
        && compactVisibleText(current).length>plain(label).length
        && compactVisibleText(current).length<=260
      ){
        return {
          cell:current,
          labelNode:candidates[c]
        };
      }

      current=current.parentElement;
    }
  }

  return null;
};

var summaryValue=function(panel,label){
  var found=summaryCell(
    panel,
    label
  );

  if(!found){
    return '';
  }

  var raw=compactVisibleText(
    found.cell
  );

  if(!raw){
    return '';
  }

  var labelText=plain(label);
  var index=raw.toLowerCase().indexOf(
    labelText.toLowerCase()
  );

  if(index!==-1){
    raw=plain(
      raw.slice(
        index+labelText.length
      )
    );
  }

  raw=raw
    .replace(/^[:\-–—\s]+/,'')
    .replace(/\s+(editable|edit booking header)$/i,'')
    .trim();

  return raw;
};

var metricLabels=[
  'Passengers',
  'Tickets',
  'Booking Value',
  'Travel Status'
];

var metricCardSource=function(
  metrics,
  label
){
  if(!metrics)return null;

  var candidates=exactNodes(
    metrics,
    label
  ).filter(outsideEditor);

  for(
    var c=0;
    c<candidates.length;
    c++
  ){
    var current=candidates[c];

    for(
      var depth=0;
      depth<6&&current;
      depth++
    ){
      var text=compactVisibleText(
        current
      ).toLowerCase();

      var hits=metricLabels.filter(function(item){
        return text.indexOf(
          item.toLowerCase()
        )!==-1;
      }).length;

      if(
        depth>0
        && hits===1
        && compactVisibleText(current).length>label.length
        && compactVisibleText(current).length<=320
      ){
        return current;
      }

      current=current.parentElement;
    }
  }

  return null;
};

var metricSnapshot=function(
  metrics,
  label
){
  var source=metricCardSource(
    metrics,
    label
  );

  var raw=source&&typeof source.innerText==='string'
    ? plain(source.innerText)
    : compactVisibleText(source);

  var value='—';
  var note='';

  if(!raw){
    return {
      label:label,
      value:value,
      note:note
    };
  }

  var lower=raw.toLowerCase();
  var labelIndex=lower.indexOf(
    label.toLowerCase()
  );

  var tail=labelIndex!==-1
    ? plain(
        raw.slice(
          labelIndex+label.length
        )
      )
    : raw;

  if(label==='Passengers'){
    var pax=tail.match(/\b(\d+)\b/);
    value=pax
      ? pax[1]
      : '0';

    var paxNote=tail.match(
      /(Adult\s*\d+\s*[·|]\s*Child\s*\d+\s*[·|]\s*Infant\s*\d+)/i
    );
    note=paxNote
      ? paxNote[1].replace(/\|/g,'·')
      : tail.replace(/^\d+\s*/,'').trim();
  }else if(label==='Tickets'){
    /* ERP-11.3.125 — never derive the Tickets KPI from legacy/native DOM text.
       The native card can contain both passenger/ticket digits and the Air
       service helper count, which previously flashed bogus values such as 31.
       Keep the controlled card neutral until the authoritative Air API loads. */
    value='—';
    var serviceNote=tail.match(/(\d+\s+Air Ticket service\(s\))/i);
    note=serviceNote?serviceNote[1]:'';
  }else if(label==='Booking Value'){
    var money=tail.match(
      /\b([A-Z]{3})\s*([\d,]+(?:\.\d{1,2})?)\b/
    );

    if(money){
      value=money[1]+' '+money[2];
      note=plain(
        tail.replace(
          money[0],
          ''
        )
      );
    }else{
      var numeric=tail.match(
        /([\d,]+(?:\.\d{1,2})?)/
      );
      value=numeric
        ? numeric[1]
        : 'PKR 0.00';
      note=numeric
        ? plain(
            tail.replace(
              numeric[0],
              ''
            )
          )
        : '';
    }
  }else if(label==='Travel Status'){
    var known=tail.match(
      /\b(Pending Approval|Pending|Confirmed|Completed|Cancelled|Canceled|Draft|Travelling|Traveling)\b/i
    );

    if(known){
      value=known[1];
      note=plain(
        tail.replace(
          known[0],
          ''
        )
      );
    }else{
      var words=tail.split(/\s+/);
      value=words.shift()||'Pending';
      note=words.join(' ');
    }
  }

  note=note
    .replace(/^[:\-–—·\s]+/,'')
    .trim();

  return {
    label:label,
    value:value,
    note:note
  };
};

var buildMetricGrid=function(
  metrics
){
  var grid=create(
    'section',
    'etgp-kpis'
  );
  grid.setAttribute(
    'data-etgp-kpis',
    '1'
  );

  metricLabels.forEach(function(label){
    var snapshot=metricSnapshot(
      metrics,
      label
    );

    var card=create(
      'article',
      'etgp-kpi'
    );

    card.appendChild(
      create(
        'div',
        'etgp-kpi-label',
        snapshot.label
      )
    );
    card.appendChild(
      create(
        'div',
        'etgp-kpi-value',
        snapshot.value
      )
    );

    if(snapshot.note){
      card.appendChild(
        create(
          'div',
          'etgp-kpi-note',
          snapshot.note
        )
      );
    }

    grid.appendChild(
      card
    );
  });

  return grid;
};

var refreshMetricGrid=function(
  freshMetrics
){
  var current=document.querySelector(
    '[data-etgp-kpis]'
  );

  if(!current||!freshMetrics){
    return;
  }

  var fresh=buildMetricGrid(
    freshMetrics
  );

  current.replaceWith(
    fresh
  );
  etgpTicketKpiGuard113126();
};

var bookingReference=function(root){
  var match=String(
    root&&root.textContent||''
  ).match(/\bBK-\d{4}-\d{5,8}\b/i);

  return match
    ? match[0].toUpperCase()
    : 'Booking';
};

var directUnder=function(node,parent){
  var current=node;

  while(
    current
    && current.parentElement
    && current.parentElement!==parent
  ){
    current=current.parentElement;
  }

  return current&&current.parentElement===parent
    ? current
    : null;
};

var heroCandidate=function(content){
  var rows=Array.prototype.slice.call(
    content.querySelectorAll(
      'div,section,article,header'
    )
  ).filter(function(node){
    var text=norm(
      node.textContent
    );

    return text.indexOf(
      'booking workspace'
    )!==-1
      && text.indexOf(
        'booking register'
      )!==-1
      && /\bbk-\d{4}-\d+/i.test(
        String(node.textContent||'')
      );
  });

  return rows.sort(function(a,b){
    return (
      plain(a.textContent).length
      -plain(b.textContent).length
    );
  })[0]||null;
};

var actionUnits=function(hero){
  if(!hero)return [];

  var allowed=[
    'client preview',
    'menu',
    'booking register'
  ];

  var units=[];

  Array.prototype.slice.call(
    hero.querySelectorAll(
      'a,button,summary,input[type="submit"]'
    )
  ).forEach(function(control){
    var label=norm(
      control.textContent
      || control.value
    );

    if(
      !allowed.some(function(item){
        return label===item
          || label.indexOf(item)!==-1;
      })
    ){
      return;
    }

    var unit=control.closest('form')
      || control.closest('details')
      || control.closest('.dropdown')
      || control;

    if(
      unit
      && units.indexOf(unit)===-1
    ){
      units.push(unit);
    }
  });

  return units;
};

var bookingStatus=function(hero){
  if(!hero)return 'DRAFT';

  var allowed=[
    'draft',
    'pending',
    'pending approval',
    'confirmed',
    'cancelled'
  ];

  var match=Array.prototype.slice.call(
    hero.querySelectorAll(
      'span,strong,b,div'
    )
  ).find(function(node){
    return allowed.indexOf(
      norm(node.textContent)
    )!==-1;
  });

  return match
    ? plain(match.textContent)
    : 'DRAFT';
};

var hideDuplicateHeading=function(
  panel,
  label
){
  exactNodes(
    panel,
    label
  ).forEach(function(node){
    if(
      outsideEditor(node)
      && !node.querySelector(
        'input,select,textarea,button'
      )
    ){
      node.setAttribute(
        'data-etgp-duplicate-heading',
        '1'
      );
    }
  });
};

var passengerCount=function(panel){
  if(!panel)return 0;

  var body=panel.querySelector(
    '.passenger-table tbody'
  );

  if(body){
    var rows=Array.prototype.slice.call(
      body.querySelectorAll('tr')
    ).filter(function(row){
      var text=norm(
        row.textContent
      );

      return text!==''
        && text.indexOf(
          'no passenger'
        )===-1
        && text.indexOf(
          'no booking passenger'
        )===-1;
    });

    if(rows.length>0){
      return rows.length;
    }
  }

  var label=panel.querySelector(
    '.passenger-current-label'
  );

  if(label){
    var match=String(
      label.textContent||''
    ).match(/\b(\d+)\b/);

    if(match){
      return Number(
        match[1]
      )||0;
    }
  }

  return 0;
};

var storageKey=function(reference){
  return 'et.general.step1.products.'
    +String(reference||'booking');
};

var productDefinitions=[
  {
    key:'air',
    label:'Tickets / Flight Data',
    short:'Tickets / Flight',
    detail:'Flight itinerary, PNR, ticket data and its own customer/supplier commercials.'
  },
  {
    key:'hotel',
    label:'Hotel Data',
    short:'Hotel',
    detail:'Hotel stay data and its own sale/cost commercials.'
  },
  {
    key:'transport',
    label:'Transport',
    short:'Transport',
    detail:'Transport movement data and its own sale/cost commercials.'
  },
  {
    key:'visa',
    label:'Visa',
    short:'Visa',
    detail:'Passenger-linked Visa operations, reporting chain and customer/vendor commercials.'
  },
  {
    key:'other',
    label:'Other Products',
    short:'Other Products',
    detail:'Miscellaneous services/products with operational data and commercials together.'
  }
];

var etgpProductCollapseKey113127=function(reference,key){return 'et.general.step1.product-collapse.'+String(reference||'booking')+'.'+String(key||'product');};
var etgpProductCollapsed113127=function(reference,key){try{return localStorage.getItem(etgpProductCollapseKey113127(reference,key))==='1';}catch(e){return false;}};
var etgpSetProductCollapsed113127=function(reference,key,value){try{localStorage.setItem(etgpProductCollapseKey113127(reference,key),value?'1':'0');}catch(e){}};

/* ERP-11.3.153 — last authoritative server snapshot, retained for consumers
   that need per-product persisted totals. */
var etgpProductCustomerTotals113127={};
var etgpProductCurrency113127='PKR';

/* ERP-11.3.153 — authoritative persisted booking summary.  Product editors may
   show unsaved local totals inside their own workspace, but only this endpoint
   is allowed to refresh the top Booking Value / Travel Status cards. */
var etgpOperationalSummarySequence113153=0;
var etgpRefreshPersistedBookingState113153=function(bookingId,selectedProducts){
  bookingId=Number(bookingId||0);if(!bookingId)return Promise.resolve(null);
  var sequence=++etgpOperationalSummarySequence113153;
  var selected=Array.isArray(selectedProducts)?selectedProducts:null;
  if(!selected){
    var marker=document.querySelector('[data-etgp-product-buttons]'),owner=marker;
    while(owner&&!(owner.dataset&&owner.dataset.bookingReference))owner=owner.parentElement;
    selected=owner&&typeof loadSelected==='function'?loadSelected(owner.dataset.bookingReference):[];
  }
  var url='/system/erp-bookings/'+bookingId+'/operational-summary';
  if(selected.length)url+='?selected_products='+encodeURIComponent(selected.join(','));
  return fetch(url,{method:'GET',credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}})
    .then(function(response){return response.json().catch(function(){return {};}).then(function(data){if(!response.ok||!data||data.ok!==true)throw new Error('Booking summary could not be refreshed.');return data;});})
    .then(function(data){
      if(sequence!==etgpOperationalSummarySequence113153)return data;
      var amount=Math.max(0,Number(data.booking_value||0));
      var currency=String(data.currency||'PKR').trim().toUpperCase()||'PKR';
      etgpProductCustomerTotals113127=Object.assign({},data.product_customer_totals||{});
      etgpProductCurrency113127=currency;
      etgpAirSetKpi113124('Booking Value',currency+' '+amount.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}),'Derived from saved product customer totals');
      var blockers=Array.isArray(data.readiness_blockers)?data.readiness_blockers:[];
      etgpAirSetKpi113124('Travel Status',data.travel_status||'PendingTravel',blockers.length?blockers[0]:'All selected travel services are ready');
      return data;
    }).catch(function(){return null;});
};
window.etgpRefreshPersistedBookingState113153=etgpRefreshPersistedBookingState113153;
document.addEventListener('et:booking-product-saved',function(event){
  var detail=event&&event.detail||{};
  etgpRefreshPersistedBookingState113153(detail.bookingId||etgpBookingId11397(),detail.selectedProducts);
});


/* ======================================================================
 * ERP-11.3.108 — GENERAL TICKETS / FLIGHT DATA PRODUCT WORKSPACE
 *
 * One controlled same-page component owns operational flight rows, common
 * PNR/ticket context, passenger ticket numbers and compact fare-type
 * Sale/Cost/Basic/Tax/Minus commercial rows. The server bridge writes the native ERP Air
 * stores; the browser does not submit or reposition legacy Air DOM fragments.
 * ====================================================================== */
var etgpAirMoney113106=function(value){
  var n=Number(String(value===undefined||value===null?'0':value).replace(/[^0-9.\-]/g,''));
  return Number.isFinite(n)?n:0;
};

var etgpAirMoneyText113106=function(value,currency){
  var n=etgpAirMoney113106(value);
  return (currency||'PKR')+' '+n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
};

var etgpAirInput113106=function(label,type,value,placeholder){
  var unit=create('div','etgp-air-field-113106');
  var lab=create('label','form-label',label);
  var input=create('input','form-control');
  input.type=type||'text';
  input.value=value===undefined||value===null?'':String(value);
  if(placeholder)input.placeholder=placeholder;
  input.autocomplete='off';
  unit.appendChild(lab);
  unit.appendChild(input);
  return {unit:unit,input:input};
};

var etgpAirSelect113106=function(label,value,options){
  var unit=create('div','etgp-air-field-113106');
  var lab=create('label','form-label',label);
  var select=create('select','form-select');
  (options||[]).forEach(function(option){
    var item=create('option','',option.label);
    item.value=String(option.value===undefined?'':option.value);
    if(String(item.value)===String(value===undefined||value===null?'':value))item.selected=true;
    select.appendChild(item);
  });
  unit.appendChild(lab);
  unit.appendChild(select);
  return {unit:unit,select:select};
};

var etgpAirSubhead113106=function(title,note){
  var head=create('div','etgp-air-subhead-113106');
  var copy=create('div','etgp-air-subhead-copy-113106');
  copy.appendChild(create('h4','etgp-air-subtitle-113106',title));
  if(note)copy.appendChild(create('p','etgp-air-subnote-113106',note));
  head.appendChild(copy);
  return head;
};

var etgpAirErrorMessage113106=function(data,fallback){
  if(data&&data.errors){
    var keys=Object.keys(data.errors);
    if(keys.length&&data.errors[keys[0]]&&data.errors[keys[0]][0])return data.errors[keys[0]][0];
  }
  return data&&data.message?String(data.message):fallback;
};

var etgpAirRequest113106=function(bookingId,method,payload){
  return fetch('/system/erp-bookings/'+bookingId+'/air-product',{
    method:method,
    credentials:'same-origin',
    headers:{
      'Accept':'application/json',
      'Content-Type':'application/json',
      'X-Requested-With':'XMLHttpRequest',
      'X-CSRF-TOKEN':etgpCsrf11397()
    },
    body:payload===undefined?undefined:JSON.stringify(payload)
  }).then(function(response){
    return response.json().catch(function(){return {};}).then(function(data){
      if(!response.ok||!data||data.ok!==true){
        throw new Error(etgpAirErrorMessage113106(data,'Tickets / Flight Data request failed.'));
      }
      return data;
    });
  });
};

var etgpAirDraftKey113119=function(bookingId){return 'etgp-air-product-draft-v113119:'+String(bookingId||'');};
var etgpAirDraftRead113119=function(bookingId){
  try{
    var raw=localStorage.getItem(etgpAirDraftKey113119(bookingId));
    if(!raw)return null;
    var parsed=JSON.parse(raw);
    return parsed&&parsed.payload&&typeof parsed.payload==='object'?parsed:null;
  }catch(e){return null;}
};
var etgpAirDraftWrite113119=function(bookingId,payload){
  try{localStorage.setItem(etgpAirDraftKey113119(bookingId),JSON.stringify({saved_at:Date.now(),payload:payload}));}catch(e){}
};
var etgpAirDraftClear113119=function(bookingId){try{localStorage.removeItem(etgpAirDraftKey113119(bookingId));}catch(e){}};
var etgpAirApplyDraft113119=function(data,bookingId){
  var draft=etgpAirDraftRead113119(bookingId);
  if(!draft||!draft.payload)return data;
  var payload=draft.payload||{};
  var merged=Object.assign({},data||{});
  if(payload.common)merged.common=Object.assign({},merged.common||{},payload.common);
  if(Array.isArray(payload.segments))merged.itinerary=payload.segments;
  if(Array.isArray(payload.tickets))merged.tickets=payload.tickets;
  if(Array.isArray(payload.fare_commercials)){
    var fareMap={};payload.fare_commercials.forEach(function(row){if(row&&row.fare_type)fareMap[String(row.fare_type).toUpperCase()]=row;});
    merged.fare_commercials=fareMap;
  }
  merged._etgpDraftRestored113119=true;
  return merged;
};

var etgpAirLoad113106=function(bookingId){
  return fetch('/system/erp-bookings/'+bookingId+'/air-product',{
    method:'GET',
    credentials:'same-origin',
    headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
  }).then(function(response){
    return response.json().catch(function(){return {};}).then(function(data){
      if(!response.ok||!data||data.ok!==true){
        throw new Error(etgpAirErrorMessage113106(data,'Tickets / Flight Data could not be loaded.'));
      }
      return data;
    });
  });
};

var etgpAirTicketMap113106=function(rows){
  var map={};
  (rows||[]).forEach(function(row){
    var id=Number(row.booking_passenger_id||0);
    if(id>0)map[id]=row;
  });
  return map;
};

var etgpAirSetKpi113124=function(labelWanted,valueText,noteText){
  var wanted=norm(labelWanted);
  Array.prototype.slice.call(document.querySelectorAll('.etgp-kpi')).forEach(function(card){
    var label=norm(card.querySelector('.etgp-kpi-label')&&card.querySelector('.etgp-kpi-label').textContent||'');
    if(label!==wanted)return;
    var value=card.querySelector('.etgp-kpi-value');
    if(value)value.textContent=String(valueText===undefined||valueText===null?'—':valueText);
    if(noteText!==undefined){
      var note=card.querySelector('.etgp-kpi-note');
      if(!note&&noteText){note=create('div','etgp-kpi-note','');card.appendChild(note);}
      if(note)note.textContent=String(noteText||'');
    }
  });
};

/* ERP-11.3.126 — one Tickets KPI writer only.  Legacy/native refresh code can
   still touch the same card during navigation/reload.  Keep a single desired
   value and immediately restore it if any other writer mutates the card. */
var etgpTicketKpiState113126={desired:'—',note:'',observer:null};
var etgpTicketKpiApply113126=function(){
  etgpAirSetKpi113124('Tickets',etgpTicketKpiState113126.desired,etgpTicketKpiState113126.note);
};
var etgpTicketKpiGuard113126=function(){
  var grid=document.querySelector('[data-etgp-kpis]');
  if(!grid)return;
  if(etgpTicketKpiState113126.observer){try{etgpTicketKpiState113126.observer.disconnect();}catch(e){}}
  etgpTicketKpiApply113126();
  var busy=false;
  etgpTicketKpiState113126.observer=new MutationObserver(function(){
    if(busy)return;
    var card=Array.prototype.slice.call(document.querySelectorAll('.etgp-kpi')).find(function(item){
      return norm(item.querySelector('.etgp-kpi-label')&&item.querySelector('.etgp-kpi-label').textContent||'')==='tickets';
    });
    if(!card)return;
    var value=card.querySelector('.etgp-kpi-value');
    var note=card.querySelector('.etgp-kpi-note');
    var current=value?String(value.textContent||'').trim():'';
    var currentNote=note?String(note.textContent||'').trim():'';
    if(current===String(etgpTicketKpiState113126.desired)&&currentNote===String(etgpTicketKpiState113126.note||''))return;
    busy=true;
    etgpTicketKpiApply113126();
    busy=false;
  });
  etgpTicketKpiState113126.observer.observe(grid,{subtree:true,childList:true,characterData:true});
};
var etgpAirUpdateTicketMetric113106=function(count){
  etgpTicketKpiState113126.desired=String(Number(count)||0);
  etgpTicketKpiState113126.note='1 Air Ticket service(s)';
  etgpTicketKpiApply113126();
  etgpTicketKpiGuard113126();
};

var etgpAirUpdatePassengerMetric113124=function(passengers){
  var mix={ADULT:0,CHILD:0,INFANT:0};
  (passengers||[]).forEach(function(passenger){
    var fare=String(passenger&&passenger.fare_type||'ADULT').toUpperCase();
    if(fare.indexOf('INF')!==-1)mix.INFANT+=1;
    else if(fare.indexOf('CH')!==-1)mix.CHILD+=1;
    else mix.ADULT+=1;
  });
  var total=mix.ADULT+mix.CHILD+mix.INFANT;
  etgpAirSetKpi113124('Passengers',String(total),'Adult '+mix.ADULT+' · Child '+mix.CHILD+' · Infant '+mix.INFANT);
};

var etgpAirApplySummaryKpis113124=function(summary,currency){
  summary=summary||{};
  etgpAirUpdateTicketMetric113106(summary.ticket_count||0);
};

var etgpAirBackgroundMetricRefresh113106=function(){
  fetch(window.location.href,{
    method:'GET',credentials:'same-origin',
    headers:{'X-Requested-With':'XMLHttpRequest','Accept':'text/html,application/xhtml+xml'}
  }).then(function(response){
    if(!response.ok)return null;
    return response.text();
  }).then(function(htmlText){
    if(!htmlText)return;
    var fresh=new DOMParser().parseFromString(htmlText,'text/html');
    if(typeof window.etGeneralProgressiveStep1Sync11390==='function'){
      window.etGeneralProgressiveStep1Sync11390(fresh,['metrics']);
    }
  }).catch(function(){});
};

var etgpAirRender113106=function(host,data,bookingId){
  data=etgpAirApplyDraft113119(data||{},bookingId);
  if(typeof etgpApplyPassengerFareOverrides113137==='function'){
    data=etgpApplyPassengerFareOverrides113137(data);
  }
  host.innerHTML='';
  host.classList.remove('is-loading');
  host.classList.remove('is-ready');

  var capabilities=data.capabilities||{};
  /* ERP-11.3.124 — the Air product API is authoritative for saved ticket count
     and saved customer sale. Do not leave the top KPI row dependent on the
     legacy native metric DOM, which can concatenate ticket/service counts or
     omit Booking Value after the controlled GENERAL workspace is rendered. */
  etgpAirUpdatePassengerMetric113124(data.passengers||[]);
  if(data.summary){
    etgpAirApplySummaryKpis113124(data.summary,(data.booking&&data.booking.currency)||'PKR');
  }
  if(capabilities.booking_services===false||capabilities.air_ticket_details===false||capabilities.booking_itinerary_segments===false){
    host.appendChild(create('div','etgp-air-feedback-113106 is-error','Required native Air Ticket stores are not available on this installation.'));
    return;
  }

  var passengers=Array.isArray(data.passengers)?data.passengers:[];
  var savedTickets=etgpAirTicketMap113106(Array.isArray(data.tickets)?data.tickets:[]);
  var common=data.common||{};
  var fareCommercials=data.fare_commercials||{};
  var suppliers=Array.isArray(data.suppliers)?data.suppliers:[];
  var airlines=Array.isArray(data.airlines)?data.airlines:[];
  var flightNumbers=Array.isArray(data.flight_numbers)?data.flight_numbers:[];
  var currency=capabilities.booking_currency||'PKR';

  var fareCounts={ADULT:0,CHILD:0,INFANT:0};
  passengers.forEach(function(passenger){
    var type=String(passenger.fare_type||'ADULT').toUpperCase();
    if(type.indexOf('INF')!==-1)type='INFANT';
    else if(type.indexOf('CH')!==-1)type='CHILD';
    else type='ADULT';
    fareCounts[type]=(fareCounts[type]||0)+1;
  });

  var airlineOptions=[{value:'',label:'Select airline'}].concat(airlines.map(function(item){
    return {value:String(item.id||''),label:item.label||item.name||item.code||'Airline'};
  }));

  var airlineById={};
  airlines.forEach(function(item){airlineById[String(item.id||'')]=item;});

  var normalizeAir=function(value){return String(value||'').replace(/[^a-z0-9]/gi,'').toLowerCase();};
  var selectedAirlineData=function(select){
    var id=String(select&&select.value||'');
    return airlineById[id]||null;
  };

  var itinerary=create('section','etgp-air-block-113106');
  var itineraryHead=etgpAirSubhead113106('Flight Itinerary','Add outbound, return or connection segments. Airline comes from Airline Master; flight numbers are suggested from previously saved flights. PNR and status are controlled by Booking / Ticket Data.');
  var addSegment=create('button','btn btn-outline-primary etgp-air-mini-button-113106','+ Add Flight Segment');
  addSegment.type='button';
  itineraryHead.appendChild(addSegment);
  itinerary.appendChild(itineraryHead);

  var segmentList=create('div','etgp-air-segments-113106');
  itinerary.appendChild(segmentList);
  var segmentCounter=0;

  var segmentRow=function(segment){
    segment=segment||{};
    segmentCounter+=1;
    var row=create('div','etgp-air-segment-row-113106 etgp-air-segment-row-113107');
    row.setAttribute('data-etgp-air-segment-113106','1');

    var type=etgpAirSelect113106('Type',segment.segment_type||'outbound',[
      {value:'outbound',label:'Outbound'},{value:'return',label:'Return'},{value:'connection',label:'Connection'}
    ]);

    var selectedAirlineId=String(segment.airline_id||'');
    if(!selectedAirlineId&&airlines.length){
      var legacy=normalizeAir(segment.airline_code||segment.airline||'');
      var match=airlines.find(function(item){return legacy&&(normalizeAir(item.code)===legacy||normalizeAir(item.name)===legacy||normalizeAir(item.label)===legacy);});
      if(match)selectedAirlineId=String(match.id||'');
    }

    var airline;
    if(airlines.length){
      var rowAirlineOptions=airlineOptions.slice();
      var legacyName=String(segment.airline||segment.airline_code||'').trim();
      if(!selectedAirlineId&&legacyName){
        var legacyKey='legacy-'+segmentCounter;
        airlineById[legacyKey]={id:0,code:String(segment.airline_code||''),name:legacyName,label:legacyName};
        rowAirlineOptions.push({value:legacyKey,label:legacyName+' (saved)'});
        selectedAirlineId=legacyKey;
      }
      airline=etgpAirSelect113106('Airline',selectedAirlineId,rowAirlineOptions);
    }else{
      airline=etgpAirInput113106('Airline','text',segment.airline||segment.airline_code||'','Airline Master unavailable');
    }

    var flight=etgpAirInput113106('Flight No.','text',segment.flight_number||'','SV739');
    if(airlines.length){
      var listId='etgp-air-flight-list-113107-'+bookingId+'-'+segmentCounter;
      var list=document.createElement('datalist');list.id=listId;
      flight.input.setAttribute('list',listId);
      flight.unit.appendChild(list);
      var refreshFlightSuggestions=function(){
        list.innerHTML='';
        var a=selectedAirlineData(airline.select);
        flightNumbers.filter(function(item){
          if(!a)return false;
          if(Number(item.airline_id||0)>0&&Number(item.airline_id||0)===Number(a.id||0))return true;
          var code=normalizeAir(item.airline_code||'');
          var name=normalizeAir(item.airline||'');
          return (code&&code===normalizeAir(a.code||''))||(name&&name===normalizeAir(a.name||''));
        }).slice(0,80).forEach(function(item){
          var option=document.createElement('option');option.value=String(item.flight_number||'');list.appendChild(option);
        });
      };
      airline.select.addEventListener('change',refreshFlightSuggestions);
      refreshFlightSuggestions();
    }

    var from=etgpAirInput113106('From','text',segment.from||'','LHE');
    var to=etgpAirInput113106('To','text',segment.to||'','JED');
    var departure=etgpAirInput113106('Departure','datetime-local',segment.departure_at||'','');
    var arrival=etgpAirInput113106('Arrival','datetime-local',segment.arrival_at||'','');
    [type.unit,airline.unit,flight.unit,from.unit,to.unit,departure.unit,arrival.unit].forEach(function(unit){row.appendChild(unit);});

    var actions=create('div','etgp-air-segment-actions-113106');
    var remove=create('button','btn btn-outline-danger etgp-air-remove-row-113106','×');
    remove.type='button';remove.title='Remove segment';
    remove.addEventListener('click',function(){row.remove();});
    actions.appendChild(remove);row.appendChild(actions);

    row._etgpAir={type:type.select,airline:airline,flight:flight.input,from:from.input,to:to.input,departure:departure.input,arrival:arrival.input};
    segmentList.appendChild(row);
    return row;
  };

  var itineraryRows=Array.isArray(data.itinerary)?data.itinerary:[];
  if(itineraryRows.length){itineraryRows.forEach(segmentRow);}else{segmentRow({segment_type:'outbound'});}
  addSegment.addEventListener('click',function(){segmentRow({segment_type:'connection'});});
  host.appendChild(itinerary);

  /* Booking / ticket common data: vendor owns this PNR. */
  var commonBlock=create('section','etgp-air-block-113106');
  commonBlock.appendChild(etgpAirSubhead113106('Booking / Ticket Data','One PNR belongs to one Vendor / Supplier. Ticket Status is the authority for every passenger ticket in this PNR.'));
  var commonGrid=create('div','etgp-air-common-grid-113106 etgp-air-common-grid-113107');

  var supplierControl;
  if(suppliers.length){
    var supplierOpts=[{value:'',label:'Select vendor / supplier'}].concat(suppliers.map(function(item){return {value:item.id,label:item.name};}));
    supplierControl=etgpAirSelect113106('Vendor / Supplier *',common.supplier_id||'',supplierOpts);
    if(common.supplier_name){
      var supplierNameKey=normalizeAir(common.supplier_name);
      var selectedSupplierOption=supplierControl.select.options[supplierControl.select.selectedIndex];
      var selectedSupplierName=selectedSupplierOption?normalizeAir(selectedSupplierOption.textContent||''):'';
      if(!supplierControl.select.value||String(supplierControl.select.value)==='0'||selectedSupplierName!==supplierNameKey){
        var supplierMatch=suppliers.find(function(item){return normalizeAir(item.name||'')===supplierNameKey;});
        if(supplierMatch)supplierControl.select.value=String(supplierMatch.id||'');
      }
    }
  }else{
    supplierControl=etgpAirInput113106('Vendor / Supplier *','text',common.supplier_name||'','Vendor / Supplier');
  }
  var commonPnr=etgpAirInput113106('PNR','text',common.pnr||'','PNR');
  var airlinePnr=etgpAirInput113106('Airline PNR','text',common.airline_pnr||'','Airline PNR');
  var bookingSource=etgpAirInput113106('GDS / Source','text',common.booking_source||'','Sabre / Direct / NDC');
  var ticketStatus=etgpAirSelect113106('Ticket Status',String(common.ticket_status||'BOOKED').toUpperCase(),[
    {value:'BOOKED',label:'Booked'},{value:'ISSUED',label:'Issued'},{value:'PENDING',label:'Pending'},{value:'VOID',label:'Void'},{value:'REFUNDED',label:'Refunded'},{value:'CANCELLED',label:'Cancelled'}
  ]);
  var issueDate=etgpAirInput113106('Issue Date','date',common.issue_date||'','');
  [supplierControl.unit,commonPnr.unit,airlinePnr.unit,bookingSource.unit,ticketStatus.unit,issueDate.unit].forEach(function(unit){commonGrid.appendChild(unit);});
  commonBlock.appendChild(commonGrid);
  host.appendChild(commonBlock);

  /* Passenger tickets */
  var ticketsBlock=create('section','etgp-air-block-113106');
  ticketsBlock.appendChild(etgpAirSubhead113106('Passenger Tickets','Enter the first ticket number and the following blank ticket numbers auto-increment. Staff can edit any generated number. Passenger status follows the PNR Ticket Status.'));
  var ticketWrap=create('div','etgp-air-ticket-wrap-113106');
  var table=create('table','table etgp-air-ticket-table-113106 etgp-air-ticket-table-113123');
  var thead=create('thead','');
  thead.innerHTML='<tr><th>Passenger</th><th>Fare Type</th><th>Ticket No.</th><th>Class</th><th>Baggage</th><th>Status</th><th>Sale Price</th><th>Cost Price</th><th>Cust Net</th><th>Vendor Net</th></tr>';
  table.appendChild(thead);
  var tbody=create('tbody','');table.appendChild(tbody);

  if(!passengers.length){
    var empty=create('tr','');empty.innerHTML='<td colspan="10" class="etgp-air-empty-113106">Add a passenger first.</td>';tbody.appendChild(empty);
  }

  var ticketInputs=[];
  var incrementTicketNumber=function(value,step){
    var raw=String(value||'').trim();
    var match=raw.match(/^(.*?)(\d+)$/);
    if(!match)return '';
    var prefix=match[1],digits=match[2],next=String(Number(digits)+(Number(step)||1));
    if(!Number.isFinite(Number(digits)))return '';
    while(next.length<digits.length)next='0'+next;
    return prefix+next;
  };

  var fillFollowingTickets=function(startIndex){
    if(startIndex<0||startIndex>=ticketInputs.length)return;
    var seed=ticketInputs[startIndex].value;
    if(!seed)return;
    var current=seed;
    for(var i=startIndex+1;i<ticketInputs.length;i+=1){
      if(ticketInputs[i].value) { current=ticketInputs[i].value; continue; }
      var next=incrementTicketNumber(current,1);
      if(!next)break;
      ticketInputs[i].value=next;
      current=next;
    }
  };

  var statusSpans=[];
  var ticketCommercialCells=[];
  var commercialCell113123=function(value){
    var td=create('td','etgp-air-ticket-money-113123');
    var span=create('span','etgp-air-ticket-money-value-113123',Number(value||0).toFixed(2));
    td.appendChild(span);
    return {td:td,span:span};
  };
  passengers.forEach(function(passenger,index){
    var saved=savedTickets[Number(passenger.id)]||{};
    var tr=create('tr','');tr.setAttribute('data-etgp-air-ticket-row-113106',String(passenger.id));
    var name=create('td','etgp-air-passenger-name-113106');name.appendChild(create('strong','',passenger.name||'Passenger'));
    var fareType=String(passenger.fare_type||'ADULT').toUpperCase();
    if(fareType.indexOf('INF')!==-1)fareType='INFANT';else if(fareType.indexOf('CH')!==-1)fareType='CHILD';else fareType='ADULT';
    var fare=create('td','');fare.textContent=fareType;
    var ticketTd=create('td','');var ticket=create('input','form-control');ticket.type='text';ticket.value=saved.ticket_number||'';ticket.placeholder='065-1234567890';ticketTd.appendChild(ticket);
    var classTd=create('td','');var bookingClass=create('input','form-control');bookingClass.type='text';bookingClass.value=saved.booking_class||'';bookingClass.placeholder='Y';classTd.appendChild(bookingClass);
    var baggageTd=create('td','');var baggage=create('input','form-control');baggage.type='text';baggage.value=saved.baggage||'';baggage.placeholder='23 KG';baggageTd.appendChild(baggage);
    var statusTd=create('td','');var status=create('span','etgp-air-ticket-status-113107','');statusTd.appendChild(status);statusSpans.push(status);
    var saleCell=commercialCell113123(saved.customer_total_sale_value||0);
    var costCell=commercialCell113123(saved.supplier_cost_price||0);
    var customerNetCell=commercialCell113123(saved.customer_total||0);
    var vendorNetCell=commercialCell113123(saved.supplier_total||0);
    [name,fare,ticketTd,classTd,baggageTd,statusTd,saleCell.td,costCell.td,customerNetCell.td,vendorNetCell.td].forEach(function(td){tr.appendChild(td);});
    tr._etgpAir={passengerId:Number(passenger.id),ticket:ticket,bookingClass:bookingClass,baggage:baggage};
    tbody.appendChild(tr);
    ticketCommercialCells.push({fareType:fareType,sale:saleCell.span,cost:costCell.span,customerNet:customerNetCell.span,vendorNet:vendorNetCell.span});
    ticketInputs.push(ticket);
    ticket.addEventListener('change',function(){fillFollowingTickets(index);});
    ticket.addEventListener('blur',function(){fillFollowingTickets(index);});
  });

  /* ERP-11.3.126 — a PNR may be Issued while a newly-added passenger still
     has no ticket document.  Such a row remains Pending Ticket until a real
     ticket number exists; this keeps the visual status aligned with the
     authoritative Tickets KPI. */
  var syncTicketStatuses=function(){
    var pnrValue=String(ticketStatus.select.value||'BOOKED').toUpperCase();
    statusSpans.forEach(function(span,index){
      var hasTicket=!!String(ticketInputs[index]&&ticketInputs[index].value||'').trim();
      var value=(pnrValue==='ISSUED'&&!hasTicket)?'PENDING_TICKET':pnrValue;
      var label=value==='PENDING_TICKET'?'Pending Ticket':(value.charAt(0)+value.slice(1).toLowerCase());
      span.textContent=label;span.setAttribute('data-status',value);
    });
  };
  ticketStatus.select.addEventListener('change',syncTicketStatuses);
  ticketInputs.forEach(function(input){input.addEventListener('input',syncTicketStatuses);input.addEventListener('change',syncTicketStatuses);});
  /* When a passenger is appended after the Air product already has ticket
     numbers, derive only the following blank rows from the last real ticket. */
  var lastTicketSeed=-1;
  ticketInputs.forEach(function(input,index){if(String(input.value||'').trim())lastTicketSeed=index;});
  if(lastTicketSeed>=0)fillFollowingTickets(lastTicketSeed);
  syncTicketStatuses();

  ticketWrap.appendChild(table);ticketsBlock.appendChild(ticketWrap);host.appendChild(ticketsBlock);

  /* ERP-11.3.109 — compact one-row fare commercial matrix; Taxes = Cost Price - Basic Rate */
  var commercial=create('section','etgp-air-block-113106 etgp-air-commercial-113108');
  commercial.appendChild(etgpAirSubhead113106('PNR Fare Commercials','One row per fare type. Taxes = Cost Price - Basic Rate. Vendor Minus and Customer Minus are always calculated against Basic Rate.'));

  var commercialWrap=create('div','etgp-air-fare-wrap-113108');
  var commercialTable=create('table','etgp-air-fare-table-113108');
  commercialTable.innerHTML='<thead><tr><th>Fare Type</th><th>Pax</th><th>Sale Price</th><th>Cost Price</th><th>Basic Rate</th><th>Taxes</th><th>Vendor Minus</th><th>Customer Minus</th><th>V O Cost</th><th>Answer</th></tr></thead>';
  var commercialBody=create('tbody','');commercialTable.appendChild(commercialBody);commercialWrap.appendChild(commercialTable);commercial.appendChild(commercialWrap);host.appendChild(commercial);

  var fareControls={};
  var makeMoneyInput=function(value,disabled){
    var input=document.createElement('input');input.type='number';input.step='0.01';input.min='0';input.className='form-control';input.value=String(value||0);input.disabled=!!disabled;return input;
  };
  var makeMinusControl=function(type,value,disabled){
    var wrap=create('div','etgp-air-minus-113108 etgp-air-minus-113110');
    var select=document.createElement('select');select.className='form-select';select.innerHTML='<option value="FIXED">Fixed</option><option value="PERCENT">%</option>';select.value=String(type||'FIXED').toUpperCase();select.disabled=!!disabled;
    var input=makeMoneyInput(value,disabled);
    var amount=create('span','etgp-air-minus-amount-113110','= 0.00');
    wrap.appendChild(select);wrap.appendChild(input);wrap.appendChild(amount);return {wrap:wrap,select:select,input:input,amount:amount};
  };
  var makeAnswer=function(){
    var box=create('div','etgp-air-answer-113108');
    var customer=create('span','customer','C 0.00');
    var vendor=create('span','vendor','V 0.00');
    var margin=create('strong','margin','M 0.00');
    box.appendChild(customer);box.appendChild(vendor);box.appendChild(margin);return {box:box,customer:customer,vendor:vendor,margin:margin};
  };

  ['ADULT','CHILD','INFANT'].forEach(function(fareType){
    var count=Number(fareCounts[fareType]||0);
    var saved=fareCommercials[fareType]||{};
    var tr=create('tr',count?'':'is-inactive');tr.setAttribute('data-fare-type',fareType);
    var label=fareType==='ADULT'?'Adult':fareType==='CHILD'?'Child':'Infant';
    var typeTd=create('td','etgp-air-fare-type-113108',label);
    var paxTd=create('td','etgp-air-fare-pax-113108',String(count));
    var sale=makeMoneyInput(saved.sale_price!==undefined?saved.sale_price:(saved.customer_total_sale_value||0),!count);
    var cost=makeMoneyInput(saved.cost_price!==undefined?saved.cost_price:((saved.supplier_total||0)+(saved.supplier_discount_amount||0)-(saved.supplier_other_cost||0)),!count);
    var basic=makeMoneyInput(saved.basic_rate!==undefined?saved.basic_rate:(saved.customer_base_fare||0),!count);
    var taxes=document.createElement('input');taxes.type='text';taxes.readOnly=true;taxes.className='form-control is-auto';taxes.value=Number(saved.taxes!==undefined?saved.taxes:(saved.customer_taxes||0)).toFixed(2);
    var vendorMinus=makeMinusControl(saved.vendor_minus_type||saved.supplier_discount_type||'FIXED',saved.vendor_minus_value!==undefined?saved.vendor_minus_value:(saved.supplier_discount_value||0),!count);
    var customerMinus=makeMinusControl(saved.customer_minus_type||saved.customer_discount_type||'FIXED',saved.customer_minus_value!==undefined?saved.customer_minus_value:(saved.customer_discount_value||0),!count);
    var other=makeMoneyInput(saved.vendor_other_cost!==undefined?saved.vendor_other_cost:(saved.supplier_other_cost||0),!count);
    var answer=makeAnswer();

    [typeTd,paxTd,sale,cost,basic,taxes,vendorMinus.wrap,customerMinus.wrap,other,answer.box].forEach(function(control){
      var td=control.tagName==='TD'?control:create('td','');if(control.tagName!=='TD')td.appendChild(control);tr.appendChild(td);
    });
    commercialBody.appendChild(tr);
    fareControls[fareType]={count:count,sale:sale,cost:cost,basic:basic,taxes:taxes,vendorMinusType:vendorMinus.select,vendorMinus:vendorMinus.input,vendorMinusAmount:vendorMinus.amount,customerMinusType:customerMinus.select,customerMinus:customerMinus.input,customerMinusAmount:customerMinus.amount,other:other,answerCustomer:answer.customer,answerVendor:answer.vendor,answerMargin:answer.margin};
  });

  var summary=create('div','etgp-air-summary-113106 etgp-air-summary-113108');
  var summaryCustomer=create('div','etgp-air-summary-item-113106');
  var summaryVendor=create('div','etgp-air-summary-item-113106');
  var summaryMargin=create('div','etgp-air-summary-item-113106');
  [summaryCustomer,summaryVendor,summaryMargin].forEach(function(item){summary.appendChild(item);});host.appendChild(summary);

  var discountAmount=function(gross,type,value){
    var amount=String(type||'FIXED').toUpperCase()==='PERCENT'?gross*Math.min(100,Math.max(0,value))/100:Math.max(0,value);
    return Math.min(gross,amount);
  };

  var recalc=function(){
    var customerBooking=0,vendorBooking=0;
    Object.keys(fareControls).forEach(function(fareType){
      var c=fareControls[fareType],count=c.count;
      var sale=Math.max(0,etgpAirMoney113106(c.sale.value));
      var cost=Math.max(0,etgpAirMoney113106(c.cost.value));
      var basic=Math.max(0,etgpAirMoney113106(c.basic.value));
      var taxes=Math.max(0,cost-basic);
      var customerMinusAmount=discountAmount(basic,c.customerMinusType.value,etgpAirMoney113106(c.customerMinus.value));
      var vendorMinusAmount=discountAmount(basic,c.vendorMinusType.value,etgpAirMoney113106(c.vendorMinus.value));
      var other=Math.max(0,etgpAirMoney113106(c.other.value));
      var customerNet=Math.max(0,sale-customerMinusAmount);
      var vendorBaseNet=Math.max(0,cost-vendorMinusAmount);
      c.taxes.value=taxes.toFixed(2);
      c.vendorMinusAmount.textContent='= '+vendorMinusAmount.toFixed(2);
      c.customerMinusAmount.textContent='= '+customerMinusAmount.toFixed(2);
      var customerRowTotal=customerNet*count;
      // V O Cost is one fare-type row total, so add it once after Pax multiplication.
      var vendorRowTotal=(vendorBaseNet*count)+other;
      var marginRowTotal=customerRowTotal-vendorRowTotal;
      c.answerCustomer.textContent='C '+customerRowTotal.toFixed(2);
      c.answerVendor.textContent='V '+vendorRowTotal.toFixed(2);
      c.answerMargin.textContent='M '+marginRowTotal.toFixed(2);
      c.answerMargin.classList.toggle('is-negative',marginRowTotal<0);
      var vendorOtherAllocation=count>0?other/count:0;
      ticketCommercialCells.filter(function(cell){return cell.fareType===fareType;}).forEach(function(cell){
        cell.sale.textContent=sale.toFixed(2);
        cell.cost.textContent=cost.toFixed(2);
        cell.customerNet.textContent=customerNet.toFixed(2);
        cell.vendorNet.textContent=(vendorBaseNet+vendorOtherAllocation).toFixed(2);
      });
      customerBooking+=customerRowTotal;vendorBooking+=vendorRowTotal;
      c.basic.classList.toggle('is-invalid',basic>cost+0.009);
    });
    summaryCustomer.innerHTML='<span>PNR Customer Total</span><strong>'+etgpAirMoneyText113106(customerBooking,currency)+'</strong>';
    summaryVendor.innerHTML='<span>PNR Vendor Total</span><strong>'+etgpAirMoneyText113106(vendorBooking,currency)+'</strong>';
    summaryMargin.innerHTML='<span>Gross Margin</span><strong>'+etgpAirMoneyText113106(customerBooking-vendorBooking,currency)+'</strong>';
  };
  Object.keys(fareControls).forEach(function(key){
    var c=fareControls[key];[c.sale,c.cost,c.basic,c.vendorMinusType,c.vendorMinus,c.customerMinusType,c.customerMinus,c.other].forEach(function(input){input.addEventListener('input',recalc);input.addEventListener('change',recalc);});
  });recalc();

  var actions=create('div','etgp-air-actions-113106');
  var feedback=create('div','etgp-air-feedback-113106');feedback.hidden=true;
  if(data._etgpDraftRestored113119){
    feedback.hidden=false;feedback.classList.add('is-draft');feedback.textContent='Unsaved Air data was restored from this browser. Save again when ready.';
  }
  var save=create('button','btn btn-primary etgp-air-save-113106','Save Tickets / Flight Data');save.type='button';
  actions.appendChild(feedback);actions.appendChild(save);host.appendChild(actions);

  var supplierPayload=function(){
    if(suppliers.length){
      var id=Number(supplierControl.select.value||0),option=supplierControl.select.options[supplierControl.select.selectedIndex];
      return {supplier_id:id||null,supplier_name:id&&option?plain(option.textContent):''};
    }
    return {supplier_id:null,supplier_name:plain(supplierControl.input.value||'')};
  };

  var buildPayload113119=function(){
    var segments=Array.prototype.slice.call(segmentList.querySelectorAll('[data-etgp-air-segment-113106]')).map(function(row){
      var c=row._etgpAir,a={id:0,code:'',name:''};
      if(airlines.length){var item=selectedAirlineData(c.airline.select);if(item)a={id:Number(item.id||0),code:String(item.code||''),name:String(item.name||item.label||'')};}
      else a.name=plain(c.airline.input.value);
      return {segment_type:c.type.value,airline_id:a.id||null,airline_code:plain(a.code),airline:plain(a.name),flight_number:plain(c.flight.value).toUpperCase(),from:plain(c.from.value).toUpperCase(),to:plain(c.to.value).toUpperCase(),departure_at:c.departure.value||null,arrival_at:c.arrival.value||null};
    }).filter(function(segment){return segment.from||segment.to||segment.airline||segment.flight_number||segment.departure_at;});

    var tickets=Array.prototype.slice.call(tbody.querySelectorAll('[data-etgp-air-ticket-row-113106]')).map(function(row){
      var c=row._etgpAir;
      return {booking_passenger_id:c.passengerId,ticket_number:plain(c.ticket.value),booking_class:plain(c.bookingClass.value),baggage:plain(c.baggage.value)};
    });

    var farePayload=Object.keys(fareControls).map(function(fareType){
      var c=fareControls[fareType];
      return {fare_type:fareType,sale_price:etgpAirMoney113106(c.sale.value),cost_price:etgpAirMoney113106(c.cost.value),basic_rate:etgpAirMoney113106(c.basic.value),vendor_minus_type:c.vendorMinusType.value,vendor_minus_value:etgpAirMoney113106(c.vendorMinus.value),customer_minus_type:c.customerMinusType.value,customer_minus_value:etgpAirMoney113106(c.customerMinus.value),vendor_other_cost:etgpAirMoney113106(c.other.value)};
    });
    var supplierData=supplierPayload();
    return {
      common:{pnr:plain(commonPnr.input.value),airline_pnr:plain(airlinePnr.input.value),booking_source:plain(bookingSource.input.value),ticket_status:ticketStatus.select.value,issue_date:issueDate.input.value||null,supplier_id:supplierData.supplier_id,supplier_name:supplierData.supplier_name},
      segments:segments,tickets:tickets,fare_commercials:farePayload
    };
  };

  host._etgpBuildPayload113126=buildPayload113119;

  var draftTimer113119=null;
  var persistDraft113119=function(){
    clearTimeout(draftTimer113119);
    draftTimer113119=setTimeout(function(){try{etgpAirDraftWrite113119(bookingId,buildPayload113119());}catch(e){}},180);
  };
  host.addEventListener('input',persistDraft113119);
  host.addEventListener('change',persistDraft113119);

  save.addEventListener('click',function(){
    if(save.disabled)return;
    feedback.hidden=true;feedback.className='etgp-air-feedback-113106';

    var invalidFare='';
    Object.keys(fareControls).some(function(key){var c=fareControls[key];if(c.count&&etgpAirMoney113106(c.basic.value)>etgpAirMoney113106(c.cost.value)+0.009){invalidFare=key;return true;}return false;});
    if(invalidFare){feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent=invalidFare+' Basic Rate cannot be greater than Cost Price.';return;}

    var payload=buildPayload113119();
    var hasAirVendorCost=payload.fare_commercials.some(function(row){return Number(row.cost_price||0)>0;});
    if(hasAirVendorCost&&!payload.common.supplier_id&&!plain(payload.common.supplier_name)){feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent='Select Vendor / Supplier before saving Air commercial data.';return;}
    etgpAirDraftWrite113119(bookingId,payload);

    save.disabled=true;save.textContent='Saving…';
    etgpAirRequest113106(bookingId,'PUT',payload).then(function(result){
      etgpAirDraftClear113119(bookingId);
      feedback.hidden=false;feedback.className='etgp-air-feedback-113106 is-success';feedback.textContent=result.message||'Tickets / Flight Data saved.';
      var summaryResult=result.summary||{};
      etgpAirApplySummaryKpis113124(summaryResult,currency);
      etgpRefreshPersistedBookingState113153(bookingId);
    }).catch(function(error){
      feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent=error&&error.message?error.message:'Tickets / Flight Data could not be saved.';
    }).finally(function(){save.disabled=false;save.textContent='Save Tickets / Flight Data';});
  });
  requestAnimationFrame(function(){host.classList.add('is-ready');});
};

var renderAirProductWorkspace113106=function(shell){
  var bookingId=etgpBookingId11397();
  var host=create('div','etgp-air-workspace-113106 is-loading');
  host.setAttribute('data-etgp-air-workspace-113106','1');
  var loadingShell=create('div','etgp-air-loading-shell-113112');
  loadingShell.innerHTML='<div class="etgp-air-loading-head-113112">Loading saved flight and ticket data…</div>' +
    '<div class="etgp-air-loading-card-113112 is-itinerary"><span></span><i></i><i></i><i></i></div>' +
    '<div class="etgp-air-loading-card-113112 is-common"><span></span><i></i><i></i></div>' +
    '<div class="etgp-air-loading-card-113112 is-tickets"><span></span><i></i><i></i></div>' +
    '<div class="etgp-air-loading-card-113112 is-commercial"><span></span><i></i><i></i><i></i></div>' +
    '<div class="etgp-air-loading-summary-113112"><i></i><i></i><i></i></div>';
  host.appendChild(loadingShell);
  shell.appendChild(host);
  if(!bookingId){
    host.innerHTML='';host.appendChild(create('div','etgp-air-feedback-113106 is-error','Booking ID could not be resolved from this page.'));return;
  }
  etgpAirLoad113106(bookingId).then(function(data){etgpAirRender113106(host,data,bookingId);}).catch(function(error){
    host.innerHTML='';host.classList.remove('is-loading');host.appendChild(create('div','etgp-air-feedback-113106 is-error',error&&error.message?error.message:'Tickets / Flight Data could not be loaded.'));
  });
};

/* ======================================================================
 * ERP-11.3.127 — GENERAL HOTEL DATA controlled one-line multi-stay workspace
 * ====================================================================== */
var etgpHotelMoney113127=function(value){
  var n=Number(String(value===undefined||value===null?'0':value).replace(/[^0-9.\-]/g,''));
  return Number.isFinite(n)?n:0;
};
var etgpHotelMoneyText113127=function(value,currency){
  return (currency||'PKR')+' '+etgpHotelMoney113127(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
};
var etgpHotelDateNights113127=function(checkIn,checkOut){
  if(!checkIn||!checkOut)return 0;
  var a=new Date(String(checkIn)+'T00:00:00'),b=new Date(String(checkOut)+'T00:00:00');
  if(!Number.isFinite(a.getTime())||!Number.isFinite(b.getTime()))return 0;
  return Math.max(0,Math.round((b.getTime()-a.getTime())/86400000));
};
var etgpHotelError113127=function(data,fallback){
  if(data&&data.errors){var keys=Object.keys(data.errors);if(keys.length&&data.errors[keys[0]]&&data.errors[keys[0]][0])return data.errors[keys[0]][0];}
  return data&&data.message?String(data.message):fallback;
};
var etgpHotelRequest113127=function(bookingId,method,payload){
  return fetch('/system/erp-bookings/'+bookingId+'/hotel-product',{
    method:method,credentials:'same-origin',
    headers:{'Accept':'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':etgpCsrf11397()},
    body:payload===undefined?undefined:JSON.stringify(payload)
  }).then(function(response){return response.json().catch(function(){return {};}).then(function(data){if(!response.ok||!data||data.ok!==true)throw new Error(etgpHotelError113127(data,'Hotel Data request failed.'));return data;});});
};
var etgpHotelLoad113127=function(bookingId){
  return fetch('/system/erp-bookings/'+bookingId+'/hotel-product',{method:'GET',credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}})
    .then(function(response){return response.json().catch(function(){return {};}).then(function(data){if(!response.ok||!data||data.ok!==true)throw new Error(etgpHotelError113127(data,'Hotel Data could not be loaded.'));return data;});});
};
var etgpHotelDraftKey113127=function(bookingId){return 'etgp-hotel-product-draft-v113132:'+String(bookingId||'');};
var etgpHotelDraftRead113127=function(bookingId){try{var raw=localStorage.getItem(etgpHotelDraftKey113127(bookingId));return raw?JSON.parse(raw):null;}catch(e){return null;}};
var etgpHotelDraftWrite113127=function(bookingId,payload){try{localStorage.setItem(etgpHotelDraftKey113127(bookingId),JSON.stringify({saved_at:Date.now(),payload:payload}));}catch(e){}};
var etgpHotelDraftClear113127=function(bookingId){try{localStorage.removeItem(etgpHotelDraftKey113127(bookingId));}catch(e){}};
/* ERP-11.3.138 — browser Hotel draft is recovery-only. A successfully persisted
 * Hotel payload must not be recreated merely because the product workspace
 * re-renders/collapses after Save. */
var etgpHotelDraftSignature113138=function(payload){
  var stays=payload&&Array.isArray(payload.stays)?payload.stays:[];
  return JSON.stringify(stays.map(function(row){return {
    vendor_id:Number(row&&row.vendor_id||0)||0,
    city:norm(row&&row.city||''),
    hotel_name:norm(row&&row.hotel_name||''),
    confirmation_no:plain(row&&row.confirmation_no||''),
    room_type:norm(row&&row.room_type||''),
    board:String(row&&row.board||'RO').toUpperCase(),
    check_in:String(row&&row.check_in||''),
    check_out:String(row&&row.check_out||''),
    sale_rate:Number(etgpHotelMoney113127(row&&row.sale_rate||0).toFixed(2)),
    cost_rate:Number(etgpHotelMoney113127(row&&row.cost_rate||0).toFixed(2))
  };}));
};
var etgpHotelDraftMatchesSaved113138=function(draftPayload,savedStays){
  return etgpHotelDraftSignature113138(draftPayload)===etgpHotelDraftSignature113138({stays:Array.isArray(savedStays)?savedStays:[]});
};
var etgpHotelFlushVisibleDraft113127=function(){
  var host=document.querySelector('[data-etgp-hotel-workspace-113127]');
  if(!host||typeof host._etgpHotelPayload113127!=='function'||!host._etgpHotelDirty113138)return;
  var bookingId=etgpBookingId11397();if(!bookingId)return;
  try{etgpHotelDraftWrite113127(bookingId,host._etgpHotelPayload113127());}catch(e){}
};
var etgpHotelRender113127=function(host,data,bookingId){
  data=data||{};
  var draft=etgpHotelDraftRead113127(bookingId);
  if(draft&&draft.payload&&Array.isArray(draft.payload.stays)&&draft.payload.stays.length){
    if(etgpHotelDraftMatchesSaved113138(draft.payload,data.stays)){
      etgpHotelDraftClear113127(bookingId);
    }else{
      data=Object.assign({},data,{stays:draft.payload.stays,_draftRestored:true});
    }
  }
  host.innerHTML='';host.classList.remove('is-loading');host.classList.add('is-ready');
  var currency=String(data.booking&&data.booking.currency||'PKR').toUpperCase();
  var suppliers=Array.isArray(data.suppliers)?data.suppliers:[];
  var cities=Array.isArray(data.cities)?data.cities:[];
  var hotels=Array.isArray(data.hotels)?data.hotels:[];
  var roomTypes=Array.isArray(data.room_types)&&data.room_types.length?data.room_types:['Single','Double','Triple','Quad','Quint','Sharing'];
  var boards=Array.isArray(data.boards)&&data.boards.length?data.boards:['RO','BB'];
  var rows=[];
  var block=create('div','etgp-hotel-block-113127');
  var head=create('div','etgp-hotel-head-113127');
  var headCopy=create('div','');headCopy.appendChild(create('h4','etgp-hotel-title-113127','Hotel Stays'));headCopy.appendChild(create('p','etgp-hotel-note-113127','One line per hotel. City filters Hotel Name; Nights and Answer calculate automatically.'));
  var add=create('button','btn btn-light etgp-hotel-add-113127','+ Add Hotel Stay');add.type='button';
  head.appendChild(headCopy);head.appendChild(add);block.appendChild(head);
  var scroll=create('div','etgp-hotel-grid-scroll-113127');
  var header=create('div','etgp-hotel-grid-113127 etgp-hotel-grid-head-113127');
  ['#','City','Vendor *','Hotel Name','C Number','R Type','Board','Check In','Check Out','Nights','Sale','Cost','Answer',''].forEach(function(label){header.appendChild(create('div','',label));});
  scroll.appendChild(header);
  var body=create('div','etgp-hotel-rows-113127');scroll.appendChild(body);block.appendChild(scroll);

  var cityList=document.createElement('datalist');cityList.id='etgp-hotel-cities-'+bookingId;cities.forEach(function(city){var o=document.createElement('option');o.value=String(city.name||'');cityList.appendChild(o);});block.appendChild(cityList);
  var roomList=document.createElement('datalist');roomList.id='etgp-hotel-room-types-'+bookingId;roomTypes.forEach(function(name){var o=document.createElement('option');o.value=String(name||'');roomList.appendChild(o);});block.appendChild(roomList);

  var findCity=function(name){var wanted=norm(name);return cities.find(function(city){return norm(city.name)===wanted;})||null;};
  var hotelsForCity=function(city){var wanted=norm(city);return hotels.filter(function(h){return !wanted||norm(h.city)===wanted;});};
  var findHotel=function(city,name){var wanted=norm(name),cityWanted=norm(city);var matches=hotelsForCity(city);var exact=matches.find(function(h){return norm(h.name)===wanted;})||null;if(exact)return exact;return cityWanted?null:(hotels.find(function(h){return norm(h.name)===wanted;})||null);};
  var vendorOption=function(id,name){var wanted=Number(id||0);return suppliers.find(function(v){return Number(v.id||0)===wanted;})||suppliers.find(function(v){return norm(v.name)===norm(name);})||null;};

  var makeInput=function(value,type,placeholder){var input=create('input','form-control');input.type=type||'text';input.value=value===undefined||value===null?'':String(value);if(placeholder)input.placeholder=placeholder;input.autocomplete='off';return input;};
  var makeSelect=function(options,value){var select=create('select','form-select');(options||[]).forEach(function(item){var o=create('option','',typeof item==='string'?item:String(item.name||item.label||''));o.value=typeof item==='string'?item:String(item.id||'');if(String(o.value)===String(value===undefined||value===null?'':value))o.selected=true;select.appendChild(o);});return select;};
  var recalcAll=function(){
    var customer=0,vendor=0;
    rows.forEach(function(c){
      var nights=etgpHotelDateNights113127(c.checkIn.value,c.checkOut.value);c.nights.value=String(nights||0);
      c.checkOut.classList.toggle('is-invalid',!!c.checkIn.value&&!!c.checkOut.value&&nights<1);
      var sale=Math.max(0,etgpHotelMoney113127(c.sale.value)),cost=Math.max(0,etgpHotelMoney113127(c.cost.value));
      var ct=sale*nights,vt=cost*nights,margin=ct-vt;
      c.answer.innerHTML='<span>C '+ct.toFixed(2)+'</span><span>V '+vt.toFixed(2)+'</span><strong'+(margin<0?' class="is-negative"':'')+'>M '+margin.toFixed(2)+'</strong>';
      customer+=ct;vendor+=vt;
    });
    summaryCustomer.innerHTML='<span>Hotel Customer Total</span><strong>'+etgpHotelMoneyText113127(customer,currency)+'</strong>';
    summaryVendor.innerHTML='<span>Hotel Vendor Total</span><strong>'+etgpHotelMoneyText113127(vendor,currency)+'</strong>';
    summaryMargin.innerHTML='<span>Gross Margin</span><strong>'+etgpHotelMoneyText113127(customer-vendor,currency)+'</strong>';
  };
  var refreshHotelList=function(c){
    c.hotelList.innerHTML='';hotelsForCity(c.city.value).forEach(function(h){var o=document.createElement('option');o.value=String(h.name||'');c.hotelList.appendChild(o);});
  };
  var addRow=function(stay){
    stay=stay||{};var row=create('div','etgp-hotel-grid-113127 etgp-hotel-row-113127');var idx=rows.length+1;
    var chip=create('div','etgp-hotel-chip-113127','Hotel '+idx);row.appendChild(chip);
    var city=makeInput(stay.city||'','text','Makkah');city.setAttribute('list',cityList.id);row.appendChild(city);
    var vendor=makeSelect([{id:'',name:'Select'}].concat(suppliers),stay.vendor_id||'');if(stay.vendor_name){var chosen=vendor.options[vendor.selectedIndex],chosenName=chosen?plain(chosen.textContent||'').toLowerCase():'';var expectedName=plain(stay.vendor_name).toLowerCase();if(!vendor.value||chosenName!==expectedName){var match=vendorOption(0,stay.vendor_name);if(match)vendor.value=String(match.id);}}row.appendChild(vendor);
    var hotel=makeInput(stay.hotel_name||'','text','Hotel name');var hotelList=document.createElement('datalist');hotelList.id='etgp-hotel-list-'+bookingId+'-'+idx;hotel.setAttribute('list',hotelList.id);var hotelWrap=create('div','etgp-hotel-combo-113127');hotelWrap.appendChild(hotel);hotelWrap.appendChild(hotelList);row.appendChild(hotelWrap);
    var confirmation=makeInput(stay.confirmation_no||'','text','BRN / Ref');row.appendChild(confirmation);
    var room=makeInput(stay.room_type||'','text','Double');room.setAttribute('list',roomList.id);row.appendChild(room);
    var board=makeSelect(boards,stay.board||'RO');row.appendChild(board);
    var checkIn=makeInput(stay.check_in||'','date');row.appendChild(checkIn);
    var checkOut=makeInput(stay.check_out||'','date');row.appendChild(checkOut);
    var nights=makeInput(stay.nights||0,'number');nights.readOnly=true;nights.tabIndex=-1;nights.classList.add('is-auto');row.appendChild(nights);
    var sale=makeInput(stay.sale_rate||0,'number');sale.min='0';sale.step='0.01';row.appendChild(sale);
    var cost=makeInput(stay.cost_rate||0,'number');cost.min='0';cost.step='0.01';row.appendChild(cost);
    var answer=create('div','etgp-hotel-answer-113127');row.appendChild(answer);
    var remove=create('button','etgp-hotel-remove-113127','×');remove.type='button';remove.title='Remove hotel stay';row.appendChild(remove);
    body.appendChild(row);
    var controls={row:row,city:city,vendor:vendor,hotel:hotel,hotelList:hotelList,confirmation:confirmation,room:room,board:board,checkIn:checkIn,checkOut:checkOut,nights:nights,sale:sale,cost:cost,answer:answer};rows.push(controls);refreshHotelList(controls);
    city.addEventListener('input',function(){refreshHotelList(controls);});
    city.addEventListener('change',function(){refreshHotelList(controls);var h=findHotel(city.value,hotel.value);if(h&&norm(h.city)!==norm(city.value))hotel.value='';});
    hotel.addEventListener('change',function(){var h=findHotel(city.value,hotel.value);if(h&&h.city&&!city.value){city.value=h.city;refreshHotelList(controls);}});
    [checkIn,checkOut,sale,cost].forEach(function(input){input.addEventListener('input',recalcAll);input.addEventListener('change',recalcAll);});
    remove.addEventListener('click',function(){if(rows.length<=1){[city,hotel,confirmation,room,checkIn,checkOut,sale,cost].forEach(function(input){input.value='';});vendor.value='';board.value='RO';recalcAll();return;}var pos=rows.indexOf(controls);if(pos!==-1)rows.splice(pos,1);row.remove();rows.forEach(function(item,i){item.row.querySelector('.etgp-hotel-chip-113127').textContent='Hotel '+(i+1);});recalcAll();});
    recalcAll();
  };

  var summary=create('div','etgp-hotel-summary-113127');var summaryCustomer=create('div','etgp-hotel-summary-item-113127'),summaryVendor=create('div','etgp-hotel-summary-item-113127'),summaryMargin=create('div','etgp-hotel-summary-item-113127');summary.appendChild(summaryCustomer);summary.appendChild(summaryVendor);summary.appendChild(summaryMargin);block.appendChild(summary);
  var actions=create('div','etgp-hotel-actions-113127');var feedback=create('div','etgp-hotel-feedback-113127');feedback.hidden=true;var save=create('button','btn btn-primary etgp-hotel-save-113127','Save Hotel Data');save.type='button';actions.appendChild(feedback);actions.appendChild(save);block.appendChild(actions);host.appendChild(block);

  (Array.isArray(data.stays)&&data.stays.length?data.stays:[{}]).forEach(addRow);
  add.addEventListener('click',function(){addRow({});rows[rows.length-1].city.focus();});
  var payload=function(){
    return {stays:rows.map(function(c){var cityMatch=findCity(c.city.value),hotelMatch=findHotel(c.city.value,c.hotel.value),vendorMatch=suppliers.find(function(v){return String(v.id)===String(c.vendor.value);});return {vendor_id:Number(c.vendor.value||0)||null,vendor_name:vendorMatch?String(vendorMatch.name||''):'',city_id:cityMatch?Number(cityMatch.id||0)||null:null,city:plain(c.city.value),hotel_id:hotelMatch?Number(hotelMatch.id||0)||null:null,hotel_name:plain(c.hotel.value),confirmation_no:plain(c.confirmation.value),room_type:plain(c.room.value),board:String(c.board.value||'RO').toUpperCase(),check_in:c.checkIn.value||null,check_out:c.checkOut.value||null,sale_rate:etgpHotelMoney113127(c.sale.value),cost_rate:etgpHotelMoney113127(c.cost.value)};}).filter(function(row){return row.city||row.hotel_name||row.check_in||row.check_out||row.sale_rate||row.cost_rate;})};
  };
  host._etgpHotelPayload113127=payload;
  host._etgpHotelDirty113138=!!data._draftRestored;
  var timer=null;var persist=function(){host._etgpHotelDirty113138=true;clearTimeout(timer);timer=setTimeout(function(){if(!host._etgpHotelDirty113138)return;try{etgpHotelDraftWrite113127(bookingId,payload());}catch(e){}},180);};host.addEventListener('input',persist);host.addEventListener('change',persist);
  if(data._draftRestored){feedback.hidden=false;feedback.className='etgp-hotel-feedback-113127 is-draft';feedback.textContent='Unsaved Hotel data was restored from this browser.';}
  save.addEventListener('click',function(){
    if(save.disabled)return;feedback.hidden=true;feedback.className='etgp-hotel-feedback-113127';var bodyPayload=payload();
    if(!bodyPayload.stays.length){feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent='Add at least one Hotel stay before saving.';return;}
    var invalid=bodyPayload.stays.find(function(row){return !row.city||!row.hotel_name||!row.room_type||!row.check_in||!row.check_out||etgpHotelDateNights113127(row.check_in,row.check_out)<1;});
    if(invalid){feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent='Complete City, Hotel, Room Type, Check In and Check Out. Check Out must be after Check In.';return;}
    var missingHotelVendors=bodyPayload.stays.map(function(row,index){return Number(row.cost_rate||0)>0&&!row.vendor_id?'Hotel '+String(index+1):null;}).filter(Boolean);
    if(missingHotelVendors.length){feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent=missingHotelVendors.join(', ')+': Vendor is required because vendor cost has been entered.';return;}
    clearTimeout(timer);host._etgpHotelDirty113138=true;etgpHotelDraftWrite113127(bookingId,bodyPayload);var submittedSignature=etgpHotelDraftSignature113138(bodyPayload);save.disabled=true;save.textContent='Saving…';
    etgpHotelRequest113127(bookingId,'PUT',bodyPayload).then(function(result){
      clearTimeout(timer);
      var currentPayload=payload(),hasNewChanges=etgpHotelDraftSignature113138(currentPayload)!==submittedSignature;
      if(hasNewChanges){host._etgpHotelDirty113138=true;etgpHotelDraftWrite113127(bookingId,currentPayload);}else{host._etgpHotelDirty113138=false;etgpHotelDraftClear113127(bookingId);}
      feedback.hidden=false;feedback.className='etgp-hotel-feedback-113127 is-success';feedback.textContent=hasNewChanges?'Hotel Data saved. New unsaved changes remain.':(result.message||'Hotel Data saved.');
      etgpRefreshPersistedBookingState113153(bookingId);
      if(Array.isArray(result.cities))cities=result.cities;if(Array.isArray(result.hotels))hotels=result.hotels;
    }).catch(function(error){host._etgpHotelDirty113138=true;feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent=error&&error.message?error.message:'Hotel Data could not be saved.';}).finally(function(){save.disabled=false;save.textContent='Save Hotel Data';});
  });
};
var renderHotelProductWorkspace113127=function(shell){
  var bookingId=etgpBookingId11397();var host=create('div','etgp-hotel-workspace-113127 is-loading');host.setAttribute('data-etgp-hotel-workspace-113127','1');
  var loading=create('div','etgp-hotel-loading-113127','Loading saved Hotel data…');host.appendChild(loading);shell.appendChild(host);
  if(!bookingId){host.innerHTML='';host.appendChild(create('div','etgp-hotel-feedback-113127 is-error','Booking ID could not be resolved from this page.'));return;}
  etgpHotelLoad113127(bookingId).then(function(data){etgpHotelRender113127(host,data,bookingId);}).catch(function(error){host.innerHTML='';host.classList.remove('is-loading');host.appendChild(create('div','etgp-hotel-feedback-113127 is-error',error&&error.message?error.message:'Hotel Data could not be loaded.'));});
};


/* ======================================================================
 * ERP-11.3.141 — GENERAL TRANSPORT master-rate + FX-to-PKR workspace
 * Company -> Route/Rate Card -> Vehicle -> Qty -> BRN -> Sale PKR ->
 * Cost Rate (source currency) -> Exchange Rate -> Answer PKR.
 * Driver/Cell/Plate/Notes remain on the compact operational second line.
 * ====================================================================== */
var etgpTransportMoney113139=function(value){
  var n=Number(String(value===undefined||value===null?'0':value).replace(/[^0-9.\-]/g,''));
  return Number.isFinite(n)?n:0;
};
var etgpTransportMoneyText113139=function(value,currency){
  return (currency||'PKR')+' '+etgpTransportMoney113139(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
};
var etgpTransportError113139=function(data,fallback){
  if(data&&data.errors){var keys=Object.keys(data.errors);if(keys.length&&data.errors[keys[0]]&&data.errors[keys[0]][0])return data.errors[keys[0]][0];}
  return data&&data.message?String(data.message):fallback;
};
var etgpTransportRequest113139=function(bookingId,method,payload){
  return fetch('/system/erp-bookings/'+bookingId+'/transport-product',{
    method:method,credentials:'same-origin',
    headers:{'Accept':'application/json','Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':etgpCsrf11397()},
    body:payload===undefined?undefined:JSON.stringify(payload)
  }).then(function(response){return response.json().catch(function(){return {};}).then(function(data){if(!response.ok||!data||data.ok!==true)throw new Error(etgpTransportError113139(data,'Transport Data request failed.'));return data;});});
};
var etgpTransportLoad113139=function(bookingId){
  return fetch('/system/erp-bookings/'+bookingId+'/transport-product',{method:'GET',credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}})
    .then(function(response){return response.json().catch(function(){return {};}).then(function(data){if(!response.ok||!data||data.ok!==true)throw new Error(etgpTransportError113139(data,'Transport Data could not be loaded.'));return data;});});
};
var etgpTransportDraftKey113139=function(bookingId){return 'etgp-transport-product-draft-v113141:'+String(bookingId||'');};
var etgpTransportDraftRead113139=function(bookingId){try{var raw=localStorage.getItem(etgpTransportDraftKey113139(bookingId));return raw?JSON.parse(raw):null;}catch(e){return null;}};
var etgpTransportDraftWrite113139=function(bookingId,payload){try{localStorage.setItem(etgpTransportDraftKey113139(bookingId),JSON.stringify({saved_at:Date.now(),payload:payload}));}catch(e){}};
var etgpTransportDraftClear113139=function(bookingId){try{localStorage.removeItem(etgpTransportDraftKey113139(bookingId));}catch(e){}};
var etgpTransportDraftSignature113139=function(payload){
  var rows=payload&&Array.isArray(payload.transports)?payload.transports:[];
  return JSON.stringify(rows.map(function(row){return {
    route_master_id:Number(row&&row.route_master_id||0)||0,
    route_source_table:plain(row&&row.route_source_table||''),
    route_name:norm(row&&row.route_name||''),
    vehicle_master_id:Number(row&&row.vehicle_master_id||0)||0,
    vehicle_source_table:plain(row&&row.vehicle_source_table||''),
    vehicle_type:norm(row&&row.vehicle_type||''),
    quantity:Math.max(1,Number(row&&row.quantity||1)||1),
    driver_name:plain(row&&row.driver_name||''),
    driver_cell:plain(row&&row.driver_cell||''),
    plate_number:plain(row&&row.plate_number||''),
    vendor_id:Number(row&&row.vendor_id||0)||0,
    company_name:norm(row&&row.company_name||''),
    brn_number:plain(row&&row.brn_number||''),
    sale_amount:Number(etgpTransportMoney113139(row&&row.sale_amount||0).toFixed(2)),
    cost_rate:Number(etgpTransportMoney113139(row&&row.cost_rate||0).toFixed(2)),
    cost_currency:String(row&&row.cost_currency||'PKR').toUpperCase(),
    exchange_rate:Number(etgpTransportMoney113139(row&&row.exchange_rate||0).toFixed(8)),
    cost_amount:Number(etgpTransportMoney113139(row&&row.cost_amount||0).toFixed(2)),
    notes:plain(row&&row.notes||'')
  };}));
};
var etgpTransportDraftMatchesSaved113139=function(draftPayload,savedRows){
  return etgpTransportDraftSignature113139(draftPayload)===etgpTransportDraftSignature113139({transports:Array.isArray(savedRows)?savedRows:[]});
};
var etgpTransportFlushVisibleDraft113139=function(){
  var host=document.querySelector('[data-etgp-transport-workspace-113139]');
  if(!host||typeof host._etgpTransportPayload113139!=='function'||!host._etgpTransportDirty113139)return;
  var bookingId=etgpBookingId11397();if(!bookingId)return;
  try{etgpTransportDraftWrite113139(bookingId,host._etgpTransportPayload113139());}catch(e){}
};
var etgpTransportRender113139=function(host,data,bookingId){
  data=data||{};
  var draft=etgpTransportDraftRead113139(bookingId);
  if(draft&&draft.payload&&Array.isArray(draft.payload.transports)&&draft.payload.transports.length){
    if(etgpTransportDraftMatchesSaved113139(draft.payload,data.transports)){
      etgpTransportDraftClear113139(bookingId);
    }else{
      data=Object.assign({},data,{transports:draft.payload.transports,_draftRestored:true});
    }
  }
  host.innerHTML='';host.classList.remove('is-loading');host.classList.add('is-ready');
  var currency='PKR';
  var routeOptions=Array.isArray(data.routes)?data.routes:[];
  var vehicleOptions=Array.isArray(data.vehicles)?data.vehicles:[];
  var suppliers=Array.isArray(data.suppliers)?data.suppliers:[];
  var rows=[];

  var block=create('div','etgp-transport-block-113139');
  var head=create('div','etgp-transport-head-113139');
  var headCopy=create('div','etgp-transport-head-copy-113139');
  headCopy.appendChild(create('h4','etgp-transport-title-113139','Transport Services'));
  var countChip=create('span','etgp-transport-count-113139','Transport Services: 0');headCopy.appendChild(countChip);
  var headActions=create('div','etgp-transport-head-actions-113139');
  var add=create('button','btn btn-light etgp-transport-add-113139','+ Add Another Transport');add.type='button';
  var save=create('button','btn btn-primary etgp-transport-save-113139','Save Transport Data');save.type='button';
  headActions.appendChild(add);headActions.appendChild(save);head.appendChild(headCopy);head.appendChild(headActions);block.appendChild(head);

  var scroll=create('div','etgp-transport-grid-scroll-113139');
  var header=create('div','etgp-transport-grid-113139 etgp-transport-grid-head-113139');
  ['#','Transport Company *','Route / Rate Card','Vehicle Type','Qty','BRN / Reference','Sale PKR','Cost Rate','Exchange Rate','Answer PKR',''].forEach(function(label){header.appendChild(create('div','',label));});
  scroll.appendChild(header);
  var body=create('div','etgp-transport-rows-113139');scroll.appendChild(body);block.appendChild(scroll);

  var supplierList=document.createElement('datalist');supplierList.id='etgp-transport-suppliers-'+bookingId;
  suppliers.forEach(function(v){var option=document.createElement('option');option.value=String(v.name||'');supplierList.appendChild(option);});block.appendChild(supplierList);

  var makeInput=function(value,type,placeholder){var input=create('input','form-control');input.type=type||'text';input.value=value===undefined||value===null?'':String(value);if(placeholder)input.placeholder=placeholder;input.autocomplete='off';return input;};
  var makeMasterSelect=function(options,savedId,savedSource,savedName,placeholder){
    var select=create('select','form-select');var blank=create('option','',placeholder||'Select');blank.value='';select.appendChild(blank);
    var matched=false;
    (options||[]).forEach(function(item,index){
      var label=String(item.display||item.name||'');
      var option=create('option','',label);option.value='master:'+index;option._etgpMaster113139=item;
      var byId=Number(savedId||0)>0&&Number(item.id||0)===Number(savedId||0)&&(!savedSource||!item.source_table||String(item.source_table)===String(savedSource));
      var byName=!byId&&norm(item.name||'')===norm(savedName||'');
      if(!matched&&(byId||byName)){option.selected=true;matched=true;}
      select.appendChild(option);
    });
    if(!matched&&plain(savedName||'')){
      var legacy=create('option','',String(savedName));legacy.value='saved';legacy.selected=true;legacy._etgpMaster113139={id:Number(savedId||0)||0,source_table:plain(savedSource||''),name:plain(savedName||'')};select.appendChild(legacy);
    }
    return select;
  };
  var currentMaster=function(select){var option=select&&select.options?select.options[select.selectedIndex]:null;return option&&option._etgpMaster113139?option._etgpMaster113139:null;};
  var supplierMatch=function(name){var wanted=norm(name);return suppliers.find(function(v){return norm(v.name||'')===wanted;})||null;};

  var sourceCost=function(c){return etgpTransportMoney113139(c.costRate.value);};
  var sourceCurrency=function(c){return String(c.costCurrency.textContent||c.costRate.dataset.currency||'PKR').toUpperCase();};
  var fxValue=function(c){var n=etgpTransportMoney113139(c.fx.value);return n>0?n:0;};
  var vendorPkr=function(c){return sourceCost(c)*Math.max(1,Number(c.qty.value||1)||1)*fxValue(c);};

  var recalcAll=function(){
    var customer=0,vendor=0;
    rows.forEach(function(c){
      var saleAmount=etgpTransportMoney113139(c.sale.value),costPkr=vendorPkr(c),margin=saleAmount-costPkr;
      customer+=saleAmount;vendor+=costPkr;
      c.answer.innerHTML='';
      var cLine=create('span','','C ');cLine.appendChild(create('strong','',saleAmount.toFixed(2)));c.answer.appendChild(cLine);
      var vLine=create('span','','V ');vLine.appendChild(create('strong','',costPkr.toFixed(2)));c.answer.appendChild(vLine);
      var mLine=create('span','','M ');var mStrong=create('strong',margin<0?'is-negative':'',margin.toFixed(2));mLine.appendChild(mStrong);c.answer.appendChild(mLine);
      c.answer.title='PKR Customer '+saleAmount.toFixed(2)+' | Vendor '+costPkr.toFixed(2)+' | Margin '+margin.toFixed(2);
    });
    countChip.textContent='Transport Services: '+rows.length;
    summaryCustomer.innerHTML='<span>Transport Customer Total</span><strong>'+etgpTransportMoneyText113139(customer,currency)+'</strong>';
    summaryVendor.innerHTML='<span>Transport Vendor Total</span><strong>'+etgpTransportMoneyText113139(vendor,currency)+'</strong>';
    summaryMargin.innerHTML='<span>Gross Margin</span><strong'+(customer-vendor<0?' class="is-negative"':'')+'>'+etgpTransportMoneyText113139(customer-vendor,currency)+'</strong>';
  };

  var setCostMaster=function(c,master,preserveSaved){
    master=master||{};
    var hasRate=master.rate_amount!==null&&master.rate_amount!==undefined&&master.rate_amount!==''&&Number.isFinite(Number(master.rate_amount));
    var masterCurrency=String(master.rate_currency||'').toUpperCase();
    var savedCurrency=String(c.savedCostCurrency||'').toUpperCase();
    var costCurrency=masterCurrency||savedCurrency||'PKR';
    var rate=hasRate?Number(master.rate_amount):etgpTransportMoney113139(c.costRate.value);
    var fx=Number(master.exchange_rate_to_pkr||0);
    if(preserveSaved&&Number(c.savedExchangeRate||0)>0)fx=Number(c.savedExchangeRate);
    if(costCurrency==='PKR'&&fx<=0)fx=1;
    c.costRate.value=Number(rate||0).toFixed(2);
    c.costRate.dataset.currency=costCurrency;
    c.costCurrency.textContent=costCurrency;
    c.costRate.readOnly=!!hasRate;
    c.costRate.classList.toggle('is-master-linked',!!hasRate);
    c.fx.value=fx>0?Number(fx).toFixed(6):'';
    c.fx.readOnly=true;
    c.fx.classList.toggle('is-missing',fx<=0&&costCurrency!=='PKR');
    c.fx.title=fx>0?(costCurrency+' → PKR rate from Currency Rates'):(costCurrency+' → PKR rate not found in Currency Rates');
    recalcAll();
  };

  var addRow=function(saved){
    saved=saved||{};
    var entry=create('div','etgp-transport-entry-113140');
    var row=create('div','etgp-transport-grid-113139 etgp-transport-row-113139');
    var idx=rows.length+1;var index=create('div','etgp-transport-index-113139',String(idx));row.appendChild(index);

    var company=makeInput(saved.company_name||'','text','Transport company');company.setAttribute('list',supplierList.id);row.appendChild(company);
    var route=makeMasterSelect(routeOptions,saved.route_master_id,saved.route_source_table,saved.route_name,'Select route / rate');row.appendChild(route);
    var vehicle=makeMasterSelect(vehicleOptions,saved.vehicle_master_id,saved.vehicle_source_table,saved.vehicle_type,'Select vehicle');row.appendChild(vehicle);
    var qty=makeInput(saved.quantity||1,'number','1');qty.min='1';qty.max='99';qty.step='1';row.appendChild(qty);
    var brn=makeInput(saved.brn_number||'','text','BRN / Reference');row.appendChild(brn);
    var sale=makeInput(saved.sale_amount===undefined?'':saved.sale_amount,'number','0.00');sale.min='0';sale.step='0.01';row.appendChild(sale);

    var costWrap=create('div','etgp-transport-cost-wrap-113141');
    var costCurrency=create('span','etgp-transport-cost-currency-113141',String(saved.cost_currency||'PKR').toUpperCase());
    var costRate=makeInput(saved.cost_rate===undefined?(saved.cost_amount===undefined?'':saved.cost_amount):saved.cost_rate,'number','0.00');costRate.min='0';costRate.step='0.01';costRate.dataset.currency=String(saved.cost_currency||'PKR').toUpperCase();
    costWrap.appendChild(costCurrency);costWrap.appendChild(costRate);row.appendChild(costWrap);

    var fx=makeInput(saved.exchange_rate===undefined?'':saved.exchange_rate,'number','0.000000');fx.min='0';fx.step='0.000001';fx.readOnly=true;row.appendChild(fx);
    var answer=create('div','etgp-transport-answer-113139');row.appendChild(answer);
    var remove=create('button','etgp-transport-remove-113139','Remove');remove.type='button';remove.title='Remove transport service';row.appendChild(remove);
    entry.appendChild(row);

    var detail=create('div','etgp-transport-detail-113140');
    var detailField=function(label,input){var field=create('div','etgp-transport-detail-field-113140');field.appendChild(create('label','',label));field.appendChild(input);detail.appendChild(field);};
    var driver=makeInput(saved.driver_name||'','text','Driver name');detailField('Driver Name',driver);
    var cell=makeInput(saved.driver_cell||'','text','+966...');detailField('Cell #',cell);
    var plate=makeInput(saved.plate_number||'','text','Plate #');detailField('Plate #',plate);
    var notes=makeInput(saved.notes||'','text','Notes');detailField('Notes',notes);
    entry.appendChild(detail);
    body.appendChild(entry);

    var controls={row:entry,index:index,route:route,vehicle:vehicle,qty:qty,driver:driver,cell:cell,plate:plate,company:company,brn:brn,sale:sale,costRate:costRate,costCurrency:costCurrency,fx:fx,answer:answer,notes:notes,savedCostCurrency:String(saved.cost_currency||''),savedExchangeRate:Number(saved.exchange_rate||0)};rows.push(controls);
    var initialMaster=currentMaster(route);
    if(initialMaster){
      if(!vehicle.value&&initialMaster.vehicle_type){var im=vehicleOptions.findIndex(function(v){return norm(v.name||'')===norm(initialMaster.vehicle_type||'');});if(im>=0)vehicle.value='master:'+im;}
      if(!company.value&&initialMaster.company_name)company.value=String(initialMaster.company_name);
      setCostMaster(controls,initialMaster,false);
    }else{
      if(!controls.costCurrency.textContent)controls.costCurrency.textContent='PKR';
      if(!fx.value&&String(controls.costCurrency.textContent).toUpperCase()==='PKR')fx.value='1.000000';
      setCostMaster(controls,{rate_amount:null,rate_currency:controls.costCurrency.textContent,exchange_rate_to_pkr:fx.value||1},true);
    }

    route.addEventListener('change',function(){
      var master=currentMaster(route);
      if(!master){setCostMaster(controls,{rate_amount:null,rate_currency:'PKR',exchange_rate_to_pkr:1},false);return;}
      if(master.vehicle_type){var match=vehicleOptions.findIndex(function(v){return norm(v.name||'')===norm(master.vehicle_type||'');});if(match>=0)vehicle.value='master:'+match;}
      if(master.company_name)company.value=String(master.company_name);
      if(master.brn_number&&!brn.value)brn.value=String(master.brn_number);
      controls.savedExchangeRate=0;controls.savedCostCurrency='';
      setCostMaster(controls,master,false);
    });
    [sale,costRate,qty].forEach(function(input){input.addEventListener('input',recalcAll);input.addEventListener('change',recalcAll);});
    remove.addEventListener('click',function(){if(rows.length<=1){[driver,cell,plate,company,brn,sale,costRate,notes].forEach(function(input){input.value='';});route.value='';vehicle.value='';qty.value='1';costCurrency.textContent='PKR';fx.value='1.000000';recalcAll();return;}var pos=rows.indexOf(controls);if(pos!==-1)rows.splice(pos,1);entry.remove();rows.forEach(function(item,i){item.index.textContent=String(i+1);});recalcAll();});
    recalcAll();
  };

  var summary=create('div','etgp-transport-summary-113139');
  var summaryCustomer=create('div','etgp-transport-summary-item-113139'),summaryVendor=create('div','etgp-transport-summary-item-113139'),summaryMargin=create('div','etgp-transport-summary-item-113139');summary.appendChild(summaryCustomer);summary.appendChild(summaryVendor);summary.appendChild(summaryMargin);block.appendChild(summary);
  var feedback=create('div','etgp-transport-feedback-113139');feedback.hidden=true;block.appendChild(feedback);
  var helper=create('div','etgp-transport-helper-113139','Transport Cost Rate comes from Transport Rates in its source currency. Exchange Rate comes from Currency Rates. Vendor total and Answer are converted to PKR.');block.appendChild(helper);
  host.appendChild(block);

  (Array.isArray(data.transports)&&data.transports.length?data.transports:[{}]).forEach(addRow);
  add.addEventListener('click',function(){addRow({});rows[rows.length-1].route.focus();});
  var payload=function(){
    return {transports:rows.map(function(c){
      var routeMaster=currentMaster(c.route),vehicleMaster=currentMaster(c.vehicle),supplier=supplierMatch(c.company.value),fx=fxValue(c),rate=sourceCost(c),qty=Math.max(1,Number(c.qty.value||1)||1),costPkr=rate*qty*fx;
      return {
        route_master_id:routeMaster?Number(routeMaster.id||0)||null:null,
        route_source_table:routeMaster?plain(routeMaster.source_table||''):'',
        route_name:routeMaster?plain(routeMaster.name||''):'',
        vehicle_master_id:vehicleMaster?Number(vehicleMaster.id||0)||null:null,
        vehicle_source_table:vehicleMaster?plain(vehicleMaster.source_table||''):'',
        vehicle_type:vehicleMaster?plain(vehicleMaster.name||''):'',
        quantity:qty,
        driver_name:plain(c.driver.value),driver_cell:plain(c.cell.value),plate_number:plain(c.plate.value),
        vendor_id:supplier?Number(supplier.id||0)||null:null,company_name:plain(c.company.value),brn_number:plain(c.brn.value),
        sale_amount:etgpTransportMoney113139(c.sale.value),cost_rate:rate,cost_currency:sourceCurrency(c),exchange_rate:fx,cost_amount:Number(costPkr.toFixed(2)),notes:plain(c.notes.value)
      };
    }).filter(function(row){return row.route_name||row.vehicle_type||row.driver_name||row.company_name||row.sale_amount||row.cost_rate;})};
  };
  host._etgpTransportPayload113139=payload;
  host._etgpTransportDirty113139=!!data._draftRestored;
  var timer=null;var persist=function(){host._etgpTransportDirty113139=true;clearTimeout(timer);timer=setTimeout(function(){if(host._etgpTransportDirty113139)etgpTransportDraftWrite113139(bookingId,payload());},180);};host.addEventListener('input',persist);host.addEventListener('change',persist);
  if(data._draftRestored){feedback.hidden=false;feedback.className='etgp-transport-feedback-113139 is-draft';feedback.textContent='Unsaved Transport data was restored from this browser.';}
  save.addEventListener('click',function(){
    if(save.disabled)return;feedback.hidden=true;feedback.className='etgp-transport-feedback-113139';var bodyPayload=payload();
    if(!bodyPayload.transports.length){feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent='Add at least one Transport service before saving.';return;}
    var invalid=bodyPayload.transports.find(function(row){return !row.route_name||!row.vehicle_type||Number(row.quantity||0)<1;});
    if(invalid){feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent='Complete Route, Vehicle Type and Qty for every Transport row.';return;}
    var missingFx=bodyPayload.transports.find(function(row){return String(row.cost_currency||'PKR').toUpperCase()!=='PKR'&&Number(row.exchange_rate||0)<=0;});
    if(missingFx){feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent='Exchange Rate is missing for '+String(missingFx.cost_currency||'foreign currency')+' → PKR. Update Currency Rates first.';return;}
    var missingTransportVendor=bodyPayload.transports.find(function(row){return Number(row.cost_amount||0)>0&&(!row.vendor_id||!plain(row.company_name));});
    if(missingTransportVendor){feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent='Select a valid Transport Company / Vendor before saving supplier cost.';return;}
    clearTimeout(timer);host._etgpTransportDirty113139=true;etgpTransportDraftWrite113139(bookingId,bodyPayload);var submittedSignature=etgpTransportDraftSignature113139(bodyPayload);save.disabled=true;save.textContent='Saving…';
    etgpTransportRequest113139(bookingId,'PUT',bodyPayload).then(function(result){
      clearTimeout(timer);var currentPayload=payload(),hasNewChanges=etgpTransportDraftSignature113139(currentPayload)!==submittedSignature;
      if(hasNewChanges){host._etgpTransportDirty113139=true;etgpTransportDraftWrite113139(bookingId,currentPayload);}else{host._etgpTransportDirty113139=false;etgpTransportDraftClear113139(bookingId);}
      feedback.hidden=false;feedback.className='etgp-transport-feedback-113139 is-success';feedback.textContent=hasNewChanges?'Transport Data saved. New unsaved changes remain.':(result.message||'Transport Data saved.');
      etgpRefreshPersistedBookingState113153(bookingId);
      if(Array.isArray(result.routes))routeOptions=result.routes;if(Array.isArray(result.vehicles))vehicleOptions=result.vehicles;if(Array.isArray(result.suppliers))suppliers=result.suppliers;
    }).catch(function(error){host._etgpTransportDirty113139=true;feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent=error&&error.message?error.message:'Transport Data could not be saved.';}).finally(function(){save.disabled=false;save.textContent='Save Transport Data';});
  });
};
var renderTransportProductWorkspace113139=function(shell){
  var bookingId=etgpBookingId11397();var host=create('div','etgp-transport-workspace-113139 is-loading');host.setAttribute('data-etgp-transport-workspace-113139','1');
  var loading=create('div','etgp-transport-loading-113139','Loading saved Transport data…');host.appendChild(loading);shell.appendChild(host);
  if(!bookingId){host.innerHTML='';host.appendChild(create('div','etgp-transport-feedback-113139 is-error','Booking ID could not be resolved from this page.'));return;}
  etgpTransportLoad113139(bookingId).then(function(data){etgpTransportRender113139(host,data,bookingId);}).catch(function(error){host.innerHTML='';host.classList.remove('is-loading');host.appendChild(create('div','etgp-transport-feedback-113139 is-error',error&&error.message?error.message:'Transport Data could not be loaded.'));});
};

var loadSelected=function(reference){
  try{
    var raw=localStorage.getItem(
      storageKey(reference)
    );

    if(!raw)return [];

    var parsed=JSON.parse(raw);

    return Array.isArray(parsed)
      ? parsed.filter(function(key){
          return productDefinitions.some(function(item){
            return item.key===key;
          });
        })
      : [];
  }catch(e){
    return [];
  }
};

var saveSelected=function(
  reference,
  selected
){
  try{
    localStorage.setItem(
      storageKey(reference),
      JSON.stringify(selected)
    );
  }catch(e){}
};

var summaryCard=function(
  label,
  value
){
  var item=create(
    'div',
    'etgp-summary-item'
  );

  item.appendChild(
    create(
      'div',
      'etgp-summary-label',
      label
    )
  );
  item.appendChild(
    create(
      'div',
      'etgp-summary-value',
      value||'—'
    )
  );

  return item;
};

var editorDetails=function(panel){
  if(!panel)return null;

  return Array.prototype.slice.call(
    panel.querySelectorAll(
      'details'
    )
  ).find(function(details){
    return norm(
      details.textContent
    ).indexOf(
      'edit booking header'
    )!==-1;
  })||null;
};

var updateHeaderSummary=function(
  root,
  panel
){
  var grid=root.querySelector(
    '[data-etgp-header-summary]'
  );

  if(!grid||!panel)return;

  grid.innerHTML='';

  summaryLabels.forEach(function(label){
    grid.appendChild(
      summaryCard(
        label,
        summaryValue(
          panel,
          label
        )
      )
    );
  });

  var head=root.querySelector(
    '.etgp-booking-head'
  );

  if(!head)return;

  var old=head.querySelector(
    '[data-etgp-header-editor]'
  );

  var fresh=editorDetails(
    panel
  );

  if(fresh){
    fresh.removeAttribute('open');
    fresh.setAttribute(
      'data-etgp-header-editor',
      '1'
    );

    if(old){
      old.replaceWith(fresh);
    }else{
      head.appendChild(fresh);
    }
  }
};

var renderProgress=function(
  root,
  paxCount,
  selectedCount
){
  var steps=root.querySelectorAll(
    '[data-etgp-progress-step]'
  );

  if(steps.length<3)return;

  steps[0].classList.add(
    'is-done'
  );

  if(paxCount>0){
    steps[1].classList.add(
      'is-done'
    );
    steps[1].classList.remove(
      'is-current'
    );
    steps[2].classList.add(
      'is-current'
    );
  }else{
    steps[1].classList.add(
      'is-current'
    );
    steps[1].classList.remove(
      'is-done'
    );
    steps[2].classList.remove(
      'is-current'
    );
  }

  var selected=root.querySelector(
    '[data-etgp-selected-count]'
  );

  if(selected){
    selected.textContent=String(
      selectedCount
    );
  }
};

/* ERP-11.3.126 — before Passenger quick-add rebuilds selected product shells,
   synchronously preserve the currently visible Air draft.  This prevents a
   late-added passenger from wiping an unsaved PNR, itinerary, ticket numbers
   or fare commercials during the Air workspace reload. */
var etgpAirFlushVisibleDraft113126=function(){
  var host=document.querySelector('[data-etgp-air-workspace-113106]');
  if(!host||typeof host._etgpBuildPayload113126!=='function')return;
  var bookingId=etgpBookingId11397();
  if(!bookingId)return;
  try{etgpAirDraftWrite113119(bookingId,host._etgpBuildPayload113126());}catch(e){}
};


/* ======================================================================
 * ERP-11.3.154 — GENERAL VISA PRODUCT
 * Approved two-step Add Visa wizard + compact main-row operations.
 * Saudi Company -> Pakistani IATA -> Vendor is authoritative and reporting-only.
 * ====================================================================== */
var etgpVisaDraftKey113142=function(bookingId){return 'etgp-visa-product-draft-v113142:'+String(bookingId||'');};
var etgpVisaDraftRead113142=function(bookingId){try{var raw=localStorage.getItem(etgpVisaDraftKey113142(bookingId));return raw?JSON.parse(raw):null;}catch(e){return null;}};
var etgpVisaDraftWrite113142=function(bookingId,payload){try{localStorage.setItem(etgpVisaDraftKey113142(bookingId),JSON.stringify(payload||{}));}catch(e){}};
var etgpVisaDraftClear113142=function(bookingId){try{localStorage.removeItem(etgpVisaDraftKey113142(bookingId));}catch(e){}};
var etgpVisaSignature113142=function(payload){try{return JSON.stringify(payload||{});}catch(e){return '';}};
var etgpVisaMoney113142=function(v){var n=Number(String(v===undefined||v===null?'0':v).replace(/[^0-9.\-]/g,''));return Number.isFinite(n)?n:0;};
var etgpVisaMoneyText113142=function(v){return 'PKR '+etgpVisaMoney113142(v).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});};
var etgpVisaError113142=function(data,fallback){if(data&&data.errors){var keys=Object.keys(data.errors);if(keys.length){var v=data.errors[keys[0]];return Array.isArray(v)?String(v[0]||fallback):String(v||fallback);}}return String((data&&data.message)||fallback||'Visa request failed.');};
var etgpVisaRequest113142=function(bookingId,method,payload){
  var options={method:method||'GET',credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}};
  if(method&&method!=='GET'){options.headers['Content-Type']='application/json';options.headers['X-CSRF-TOKEN']=etgpCsrf11397();options.body=JSON.stringify(payload||{});}
  return fetch('/system/erp-bookings/'+bookingId+'/visa-product',options).then(function(response){return response.json().catch(function(){return {};}).then(function(data){if(!response.ok||data.ok===false)throw new Error(etgpVisaError113142(data,'Visa request failed.'));return data;});});
};
var etgpVisaLoad113142=function(bookingId){return etgpVisaRequest113142(bookingId,'GET').then(function(data){var draft=etgpVisaDraftRead113142(bookingId);if(draft&&Array.isArray(draft.visas)){data.visa_rows=draft.visas;data._draftRestored=true;}return data;});};
var etgpVisaFlushVisibleDraft113142=function(){var host=document.querySelector('[data-etgp-visa-workspace-113142]');if(!host||typeof host._etgpVisaPayload113142!=='function')return;var bookingId=etgpBookingId11397();if(!bookingId)return;try{etgpVisaDraftWrite113142(bookingId,host._etgpVisaPayload113142());}catch(e){}};

var etgpVisaRender113142=function(host,data,bookingId){
  host.innerHTML='';host.classList.remove('is-loading');
  var passengers=Array.isArray(data.passengers)?data.passengers:[];
  var rates=Array.isArray(data.rates)?data.rates:[];
  var statuses=Array.isArray(data.statuses)?data.statuses:['pending','submitted','approved','issued','rejected','cancelled'];
  var rows=(Array.isArray(data.visa_rows)?data.visa_rows:[]).map(function(row){return Object.assign({},row);});
  var selected={};var expanded={};var page=1;var pageSize=10;var query='';var dirty=!!data._draftRestored;var timer=null;
  var rateById={};rates.forEach(function(r){rateById[String(r.id)]=r;});
  var passengerById={};passengers.forEach(function(p){passengerById[String(p.id)]=p;});

  var block=create('div','etgp-visa-block-113142');host.appendChild(block);
  var head=create('div','etgp-visa-head-113142');
  var titleWrap=create('div','etgp-visa-title-wrap-113142');titleWrap.appendChild(create('div','etgp-visa-title-113142','Visa Services'));
  var count=create('span','etgp-visa-count-113142','Visa Services: '+rows.length);titleWrap.appendChild(count);head.appendChild(titleWrap);
  var actions=create('div','etgp-visa-head-actions-113142');
  var setup=create('a','etgp-visa-btn-113142','Visa Setup / Rates');setup.href=String(data.setup_url||('/master-data/travel-masters/visa-management?booking='+bookingId));setup.target='_blank';actions.appendChild(setup);
  var bulk=create('button','etgp-visa-btn-113142','Bulk Actions');bulk.type='button';actions.appendChild(bulk);
  var add=create('button','etgp-visa-btn-113142','+ Add Visa');add.type='button';actions.appendChild(add);
  var save=create('button','etgp-visa-btn-113142 is-primary','Save Visa Data');save.type='button';actions.appendChild(save);head.appendChild(actions);block.appendChild(head);

  var helper=create('div','etgp-visa-helper-113142','Add Visa selects passengers and applies one Visa Rate, sale and initial status before rows are created. Bulk Actions remains available for later corrections.');block.appendChild(helper);
  var tools=create('div','etgp-visa-tools-113142');var search=create('input','form-control');search.type='search';search.placeholder='Search passenger, passport, visa no., reference…';tools.appendChild(search);var shown=create('span','etgp-visa-shown-113142','');tools.appendChild(shown);block.appendChild(tools);
  var feedback=create('div','etgp-visa-feedback-113142');feedback.hidden=true;block.appendChild(feedback);
  var table=create('div','etgp-visa-table-113142');block.appendChild(table);
  var pager=create('div','etgp-visa-pager-113142');block.appendChild(pager);
  var summary=create('div','etgp-visa-summary-113142');var sc=create('div','etgp-visa-summary-item-113142'),sv=create('div','etgp-visa-summary-item-113142'),sm=create('div','etgp-visa-summary-item-113142');summary.appendChild(sc);summary.appendChild(sv);summary.appendChild(sm);block.appendChild(summary);

  var normalizeRow=function(row){
    var rate=rateById[String(row.visa_rate_card_id||'')];var p=passengerById[String(row.booking_passenger_id||'')];
    if(p){row.passenger_name=p.name||row.passenger_name||'';row.passport_number=p.passport_number||row.passport_number||'';}
    if(rate){row.country=rate.country;row.visa_type=rate.visa_type;row.saudi_company_id=rate.saudi_company_id;row.saudi_company_name=rate.saudi_company_name;row.pakistani_iata_id=rate.pakistani_iata_id;row.pakistani_iata_name=rate.pakistani_iata_name;row.vendor_id=rate.vendor_id;row.vendor_name=rate.vendor_name;row.cost_currency=rate.cost_currency;row.cost_rate=rate.cost_rate;row.exchange_rate=rate.exchange_rate||0;row.vendor_cost_pkr=rate.vendor_cost_pkr||0;if((row.sale_pkr===undefined||row.sale_pkr===null||row.sale_pkr===''))row.sale_pkr=rate.default_sale_pkr||0;}
    row.status=String(row.status||'pending').toLowerCase();row.sale_pkr=etgpVisaMoney113142(row.sale_pkr);row.vendor_cost_pkr=etgpVisaMoney113142(row.vendor_cost_pkr);row.margin_pkr=row.sale_pkr-row.vendor_cost_pkr;return row;
  };
  rows.forEach(normalizeRow);

  var persist=function(){dirty=true;clearTimeout(timer);timer=setTimeout(function(){if(dirty)etgpVisaDraftWrite113142(bookingId,payload());},180);};
  var refreshSummary=function(){var c=0,v=0;rows.forEach(function(r){c+=etgpVisaMoney113142(r.sale_pkr);v+=etgpVisaMoney113142(r.vendor_cost_pkr);});sc.innerHTML='<span>Visa Customer Total</span><strong>'+etgpVisaMoneyText113142(c)+'</strong>';sv.innerHTML='<span>Visa Vendor Total</span><strong>'+etgpVisaMoneyText113142(v)+'</strong>';sm.innerHTML='<span>Gross Margin</span><strong'+(c-v<0?' class="is-negative"':'')+'>'+etgpVisaMoneyText113142(c-v)+'</strong>';};
  var rateSelect=function(row){var sel=create('select','form-select');var opt=create('option','','Select Visa Rate');opt.value='';sel.appendChild(opt);rates.forEach(function(r){var o=create('option','',r.display||((r.country||'')+' · '+(r.visa_type||'')+' · '+(r.saudi_company_name||'')));o.value=String(r.id);if(String(row.visa_rate_card_id||'')===String(r.id))o.selected=true;sel.appendChild(o);});return sel;};
  var statusSelect=function(row){var sel=create('select','form-select etgp-visa-status-113142');statuses.forEach(function(s){var o=create('option','',String(s).charAt(0).toUpperCase()+String(s).slice(1));o.value=String(s);if(String(row.status||'pending')===String(s))o.selected=true;sel.appendChild(o);});return sel;};

  var filtered=function(){var q=norm(query);if(!q)return rows.slice();return rows.filter(function(r){return norm([r.passenger_name,r.passport_number,r.country,r.visa_type,r.saudi_company_name,r.pakistani_iata_name,r.vendor_name,r.visa_number,r.application_reference,r.status].join(' ')).indexOf(q)!==-1;});};
  var updatePageBounds=function(){var total=Math.max(1,Math.ceil(filtered().length/pageSize));if(page>total)page=total;if(page<1)page=1;return total;};

  var render=function(){
    count.textContent='Visa Services: '+rows.length;table.innerHTML='';pager.innerHTML='';var list=filtered();var pages=updatePageBounds();var start=(page-1)*pageSize;var visible=list.slice(start,start+pageSize);shown.textContent='Showing '+(list.length?start+1:0)+' to '+Math.min(start+pageSize,list.length)+' of '+list.length+' Visa passenger(s)';
    var header=create('div','etgp-visa-grid-113142 etgp-visa-grid-head-113142');var selectAll=create('input','');selectAll.type='checkbox';selectAll.title='Select all Visa passengers';selectAll.checked=rows.length>0&&rows.every(function(r){return !!selected[String(r.booking_passenger_id)];});selectAll.addEventListener('change',function(){rows.forEach(function(r){selected[String(r.booking_passenger_id)]=selectAll.checked;});render();});header.appendChild(selectAll);['Passenger','Country','Visa Type','Saudi Company','Pakistani IATA','Status','Sale (PKR)','Vendor Cost (PKR)','Answer','Actions'].forEach(function(t){header.appendChild(create('div','',t));});table.appendChild(header);
    visible.forEach(function(row){normalizeRow(row);var wrap=create('div','etgp-visa-entry-113142');
      var line=create('div','etgp-visa-grid-113142 etgp-visa-main-row-113142');var chk=create('input','');chk.type='checkbox';chk.checked=!!selected[String(row.booking_passenger_id)];chk.addEventListener('change',function(){selected[String(row.booking_passenger_id)]=chk.checked;});line.appendChild(chk);
      var pn=create('div','etgp-visa-passenger-113142');pn.appendChild(create('strong','',row.passenger_name||'Passenger'));pn.appendChild(create('span','',row.passport_number||'—'));line.appendChild(pn);
      line.appendChild(create('div','',row.country||'—'));line.appendChild(create('div','',row.visa_type||'—'));line.appendChild(create('div','',row.saudi_company_name||'—'));line.appendChild(create('div','',row.pakistani_iata_name||'—'));
      var st=statusSelect(row);st.addEventListener('change',function(){row.status=st.value;persist();});line.appendChild(st);
      var sale=create('input','form-control');sale.type='number';sale.step='0.01';sale.min='0';sale.value=String(row.sale_pkr||0);sale.addEventListener('input',function(){row.sale_pkr=etgpVisaMoney113142(sale.value);row.margin_pkr=row.sale_pkr-etgpVisaMoney113142(row.vendor_cost_pkr);refreshSummary();persist();});line.appendChild(sale);
      line.appendChild(create('div','etgp-visa-money-113142',Number(row.vendor_cost_pkr||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})));
      var ans=create('div','etgp-visa-answer-113142');ans.innerHTML='<span>C <strong>'+Number(row.sale_pkr||0).toLocaleString()+'</strong></span><span>V <strong>'+Number(row.vendor_cost_pkr||0).toLocaleString()+'</strong></span><span>M <strong class="'+(row.margin_pkr<0?'is-negative':'')+'">'+Number(row.margin_pkr||0).toLocaleString()+'</strong></span>';line.appendChild(ans);
      var act=create('div','etgp-visa-actions-113142');var edit=create('button','etgp-visa-icon-btn-113142',expanded[String(row.booking_passenger_id)]?'▲':'✎');edit.type='button';edit.title='Edit Visa details';var del=create('button','etgp-visa-icon-btn-113142 is-danger','×');del.type='button';del.title='Remove Visa passenger';act.appendChild(edit);act.appendChild(del);line.appendChild(act);wrap.appendChild(line);
      var detail=create('div','etgp-visa-detail-113142');detail.hidden=!expanded[String(row.booking_passenger_id)];
      var addField=function(label,input){var f=create('div','etgp-visa-detail-field-113142');f.appendChild(create('label','',label));f.appendChild(input);detail.appendChild(f);};
      var app=create('input','form-control');app.value=row.application_reference||'';app.placeholder='Application / Reference';addField('Application Ref.',app);var vn=create('input','form-control');vn.value=row.visa_number||'';vn.placeholder='Visa No.';addField('Visa No.',vn);var issue=create('input','form-control');issue.type='date';issue.value=row.issue_date||'';addField('Issue Date',issue);var expiry=create('input','form-control');expiry.type='date';expiry.value=row.expiry_date||'';addField('Expiry Date',expiry);var note=create('input','form-control');note.value=row.notes||'';note.placeholder='Operational notes';addField('Notes',note);
      [[app,'application_reference'],[vn,'visa_number'],[issue,'issue_date'],[expiry,'expiry_date'],[note,'notes']].forEach(function(pair){pair[0].addEventListener('input',function(){row[pair[1]]=pair[0].value;persist();});pair[0].addEventListener('change',function(){row[pair[1]]=pair[0].value;persist();});});
      edit.addEventListener('click',function(){expanded[String(row.booking_passenger_id)]=!expanded[String(row.booking_passenger_id)];render();});del.addEventListener('click',function(){rows=rows.filter(function(x){return x!==row;});delete selected[String(row.booking_passenger_id)];persist();refreshSummary();render();});
      wrap.appendChild(detail);table.appendChild(wrap);
    });
    var prev=create('button','etgp-visa-page-btn-113142','‹');prev.type='button';prev.disabled=page<=1;prev.addEventListener('click',function(){page--;render();});pager.appendChild(prev);for(var i=1;i<=pages;i++){var b=create('button','etgp-visa-page-btn-113142'+(i===page?' is-current':''),String(i));b.type='button';(function(n){b.addEventListener('click',function(){page=n;render();});})(i);pager.appendChild(b);}var next=create('button','etgp-visa-page-btn-113142','›');next.type='button';next.disabled=page>=pages;next.addEventListener('click',function(){page++;render();});pager.appendChild(next);
    refreshSummary();
  };

  var modal=function(title){var overlay=create('div','etgp-visa-modal-overlay-113142');var box=create('div','etgp-visa-modal-113142');var mh=create('div','etgp-visa-modal-head-113142');var titleNode=create('strong','',title);mh.appendChild(titleNode);var close=create('button','etgp-visa-icon-btn-113142','×');close.type='button';mh.appendChild(close);box.appendChild(mh);var body=create('div','etgp-visa-modal-body-113142');box.appendChild(body);overlay.appendChild(box);document.body.appendChild(overlay);close.addEventListener('click',function(){overlay.remove();});overlay.addEventListener('click',function(e){if(e.target===overlay)overlay.remove();});return {overlay:overlay,body:body,box:box,title:titleNode};};

  add.addEventListener('click',function(){
    var m=modal('Select Passengers for Visa');m.box.classList.add('etgp-visa-pax-modal-113152');var already={};
    rows.forEach(function(r){already[String(r.booking_passenger_id)]=true;});
    var available=passengers.filter(function(p){return !already[String(p.id)];});
    if(!available.length){m.body.appendChild(create('div','etgp-visa-empty-113142','All booking passengers are already added to Visa.'));return;}
    var selection={};var modalPage=1;var modalPageSize=10;
    var pickedCount=function(){return Object.keys(selection).filter(function(k){return selection[k];}).length;};
    var renderStep1=function(){
      m.title.textContent='Select Passengers for Visa';m.body.innerHTML='';
      var q=create('input','form-control etgp-visa-pax-search-113152');q.type='search';q.placeholder='Search passenger name or passport';q.setAttribute('aria-label','Search passenger name or passport');m.body.appendChild(q);
      var top=create('div','etgp-visa-modal-tools-113142 etgp-visa-pax-toolbar-113152');
      var all=create('button','etgp-visa-btn-113142','Select All');all.type='button';var clear=create('button','etgp-visa-clear-113152','Clear');clear.type='button';var picked=create('strong','etgp-visa-picked-113152','0 selected');top.appendChild(all);top.appendChild(clear);top.appendChild(picked);m.body.appendChild(top);
      var tableWrap=create('div','etgp-visa-pax-table-113152');var tableHead=create('div','etgp-visa-pax-row-113152 etgp-visa-pax-head-113152');['','Passenger Name','Passport No.','Fare Type'].forEach(function(label){tableHead.appendChild(create('div','',label));});tableWrap.appendChild(tableHead);
      var list=create('div','etgp-visa-pax-list-113142 etgp-visa-pax-body-113152');tableWrap.appendChild(list);m.body.appendChild(tableWrap);
      var footer=create('div','etgp-visa-modal-footer-113142 etgp-visa-pax-footer-113152');var showing=create('span','etgp-visa-pax-showing-113152','');var modalPager=create('div','etgp-visa-pager-113142 etgp-visa-pax-pager-113152');var footerActions=create('div','etgp-visa-pax-footer-actions-113152');var cancel=create('button','etgp-visa-btn-113142','Cancel');cancel.type='button';var confirm=create('button','etgp-visa-btn-113142 is-primary','Continue');confirm.type='button';confirm.disabled=true;footerActions.appendChild(cancel);footerActions.appendChild(confirm);footer.appendChild(showing);footer.appendChild(modalPager);footer.appendChild(footerActions);m.body.appendChild(footer);
      var updatePicked=function(){var count=pickedCount();picked.textContent=count+' selected';confirm.disabled=count===0;};
      var matching=function(){var term=norm(q.value);return available.filter(function(p){return !term||norm((p.name||'')+' '+(p.passport_number||'')).indexOf(term)!==-1;});};
      var draw=function(){
        list.innerHTML='';modalPager.innerHTML='';var filteredPassengers=matching();var pages=Math.max(1,Math.ceil(filteredPassengers.length/modalPageSize));if(modalPage>pages)modalPage=pages;if(modalPage<1)modalPage=1;var start=(modalPage-1)*modalPageSize;var visible=filteredPassengers.slice(start,start+modalPageSize);showing.textContent='Showing '+(filteredPassengers.length?start+1:0)+'–'+Math.min(start+modalPageSize,filteredPassengers.length)+' of '+filteredPassengers.length+' passengers';
        visible.forEach(function(p){var line=create('label','etgp-visa-pax-option-113142 etgp-visa-pax-row-113152');var c=create('input','');c.type='checkbox';c.checked=!!selection[String(p.id)];c.setAttribute('aria-label','Select '+String(p.name||'Passenger'));c.addEventListener('change',function(){selection[String(p.id)]=c.checked;updatePicked();});line.appendChild(c);line.appendChild(create('strong','etgp-visa-pax-name-113152',String(p.name||'Passenger')));line.appendChild(create('span','etgp-visa-pax-passport-113152',String(p.passport_number||'No passport')));line.appendChild(create('span','etgp-visa-fare-badge-113152',String(p.fare_type||'ADULT').toUpperCase()));list.appendChild(line);});
        var prev=create('button','etgp-visa-page-btn-113142','‹');prev.type='button';prev.disabled=modalPage<=1;prev.addEventListener('click',function(){modalPage--;draw();});modalPager.appendChild(prev);modalPager.appendChild(create('span','',String(modalPage)+' / '+String(pages)));var next=create('button','etgp-visa-page-btn-113142','›');next.type='button';next.disabled=modalPage>=pages;next.addEventListener('click',function(){modalPage++;draw();});modalPager.appendChild(next);updatePicked();
      };
      q.addEventListener('input',function(){modalPage=1;draw();});all.addEventListener('click',function(){available.forEach(function(p){selection[String(p.id)]=true;});draw();});clear.addEventListener('click',function(){selection={};draw();});cancel.addEventListener('click',function(){m.overlay.remove();});confirm.addEventListener('click',renderStep2);draw();
    };

    var renderStep2=function(){
      var selectedPassengers=available.filter(function(p){return !!selection[String(p.id)];});if(!selectedPassengers.length){renderStep1();return;}
      m.title.textContent='Visa Details';m.body.innerHTML='';m.body.appendChild(create('div','etgp-visa-wizard-count-113154',selectedPassengers.length+' passengers selected'));
      var fields=create('div','etgp-visa-wizard-fields-113154');m.body.appendChild(fields);var addWizardField=function(label,input){var field=create('div','etgp-visa-wizard-field-113154');field.appendChild(create('label','',label));field.appendChild(input);fields.appendChild(field);return input;};
      var wizardRate=rateSelect({});addWizardField('Visa Rate *',wizardRate);var sale=addWizardField('Sale PKR / Passenger',create('input','form-control'));sale.type='number';sale.step='0.01';sale.min='0';var status=addWizardField('Initial Status',statusSelect({status:'pending'}));
      var resolved=create('div','etgp-visa-wizard-resolved-113154');m.body.appendChild(resolved);var resolvedFields={};[['country','Country'],['visa_type','Visa Type'],['saudi_company_name','Saudi Company'],['pakistani_iata_name','Pakistani IATA'],['vendor_name','Vendor Account'],['cost_currency','Cost Currency'],['cost_rate','Cost Rate'],['exchange_rate','Exchange Rate'],['vendor_cost_pkr','Vendor Cost PKR / Passenger'],['default_sale_pkr','Default Sale PKR / Passenger']].forEach(function(pair){var input=create('input','form-control');input.readOnly=true;input.setAttribute('aria-readonly','true');resolvedFields[pair[0]]=input;var field=create('div','etgp-visa-wizard-field-113154');field.appendChild(create('label','',pair[1]));field.appendChild(input);resolved.appendChild(field);});
      var error=create('div','etgp-visa-feedback-113142 is-error');error.hidden=true;m.body.appendChild(error);var totals=create('div','etgp-visa-wizard-summary-113154');m.body.appendChild(totals);
      var footer=create('div','etgp-visa-modal-footer-113142 etgp-visa-wizard-footer-113154');var back=create('button','etgp-visa-btn-113142','Back');back.type='button';var finish=create('button','etgp-visa-btn-113142 is-primary','Add '+selectedPassengers.length+' Visa Passengers');finish.type='button';finish.disabled=true;footer.appendChild(back);footer.appendChild(finish);m.body.appendChild(footer);
      var chosenRate=function(){return rateById[String(wizardRate.value||'')]||null;};
      var relationshipReady=function(rate){return !!(rate&&Number(rate.id||0)>0&&Number(rate.saudi_company_id||0)>0&&plain(rate.saudi_company_name||'')&&Number(rate.pakistani_iata_id||0)>0&&plain(rate.pakistani_iata_name||'')&&Number(rate.vendor_id||0)>0&&plain(rate.vendor_name||'')&&plain(rate.cost_currency||'')&&Number(rate.exchange_rate||0)>0&&Number.isFinite(Number(rate.vendor_cost_pkr)));};
      var refreshWizard=function(useDefault){var rate=chosenRate();if(useDefault&&rate)sale.value=String(Number(rate.default_sale_pkr||0));Object.keys(resolvedFields).forEach(function(key){var value=rate?rate[key]:'';if(['vendor_cost_pkr','default_sale_pkr'].indexOf(key)!==-1&&value!=='')value=etgpVisaMoneyText113142(value);else if(key==='cost_rate'&&value!=='')value=Number(value).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:4});else if(key==='exchange_rate'&&value!=='')value=Number(value).toFixed(4);resolvedFields[key].value=value===null||value===undefined||value===''?'—':String(value);});var saleValid=sale.value!==''&&Number.isFinite(Number(sale.value))&&Number(sale.value)>=0;var ready=relationshipReady(rate)&&saleValid;finish.disabled=!ready;error.hidden=true;var salePax=etgpVisaMoney113142(sale.value),vendorPax=rate?etgpVisaMoney113142(rate.vendor_cost_pkr):0,customerTotal=salePax*selectedPassengers.length,vendorTotal=vendorPax*selectedPassengers.length;totals.innerHTML='<div><span>Selected Pax</span><strong>'+selectedPassengers.length+'</strong></div><div><span>Sale / Pax</span><strong>'+etgpVisaMoneyText113142(salePax)+'</strong></div><div><span>Vendor / Pax</span><strong>'+etgpVisaMoneyText113142(vendorPax)+'</strong></div><div><span>Customer Total</span><strong>'+etgpVisaMoneyText113142(customerTotal)+'</strong></div><div><span>Vendor Total</span><strong>'+etgpVisaMoneyText113142(vendorTotal)+'</strong></div><div><span>Gross Margin</span><strong class="'+(customerTotal-vendorTotal<0?'is-negative':'')+'">'+etgpVisaMoneyText113142(customerTotal-vendorTotal)+'</strong></div>';};
      wizardRate.addEventListener('change',function(){refreshWizard(true);});sale.addEventListener('input',function(){refreshWizard(false);});back.addEventListener('click',renderStep1);
      finish.addEventListener('click',function(){var rate=chosenRate();if(!relationshipReady(rate)||sale.value===''||Number(sale.value)<0){error.hidden=false;error.textContent='Select a valid Visa Rate with a complete Saudi Company → Pakistani IATA → Vendor Account relationship and enter Sale PKR.';return;}var current={};rows.forEach(function(row){current[String(row.booking_passenger_id)]=true;});var additions=selectedPassengers.filter(function(p){return !current[String(p.id)];}).map(function(p){return normalizeRow({booking_passenger_id:p.id,passenger_name:p.name,passport_number:p.passport_number,visa_rate_card_id:Number(rate.id),country:rate.country,visa_type:rate.visa_type,saudi_company_id:Number(rate.saudi_company_id),saudi_company_name:rate.saudi_company_name,pakistani_iata_id:Number(rate.pakistani_iata_id),pakistani_iata_name:rate.pakistani_iata_name,vendor_id:Number(rate.vendor_id),vendor_name:rate.vendor_name,cost_currency:rate.cost_currency,cost_rate:Number(rate.cost_rate||0),exchange_rate:Number(rate.exchange_rate||0),vendor_cost_pkr:Number(rate.vendor_cost_pkr||0),sale_pkr:Number(sale.value),status:String(status.value||'pending'),application_reference:'',visa_number:'',issue_date:null,expiry_date:null,notes:''});});if(additions.length!==selectedPassengers.length){error.hidden=false;error.textContent='One or more selected passengers are already added. Go Back and refresh the selection.';return;}rows=rows.concat(additions);m.overlay.remove();persist();page=Math.max(1,Math.ceil(rows.length/pageSize));render();});refreshWizard(false);
    };
    renderStep1();
  });

  bulk.addEventListener('click',function(){var ids=Object.keys(selected).filter(function(k){return selected[k];});if(!ids.length){feedback.hidden=false;feedback.className='etgp-visa-feedback-113142 is-error';feedback.textContent='Select Visa passenger rows first, then use Bulk Actions.';return;}var m=modal('Bulk Actions · '+ids.length+' selected');var rateLabel=create('label','etgp-visa-modal-label-113142','Visa Rate');m.body.appendChild(rateLabel);var dummy={};var rs=rateSelect(dummy);m.body.appendChild(rs);var statusLabel=create('label','etgp-visa-modal-label-113142','Status (optional)');m.body.appendChild(statusLabel);var st=create('select','form-select');st.appendChild(create('option','','Keep current status'));statuses.forEach(function(s){var o=create('option','',s.charAt(0).toUpperCase()+s.slice(1));o.value=s;st.appendChild(o);});m.body.appendChild(st);var saleLabel=create('label','etgp-visa-modal-label-113142','Sale PKR (leave blank to use rate default)');m.body.appendChild(saleLabel);var sale=create('input','form-control');sale.type='number';sale.step='0.01';sale.min='0';m.body.appendChild(sale);var footer=create('div','etgp-visa-modal-footer-113142');var apply=create('button','etgp-visa-btn-113142 is-primary','Apply to '+ids.length+' Passenger(s)');apply.type='button';footer.appendChild(apply);m.body.appendChild(footer);apply.addEventListener('click',function(){var r=rateById[String(rs.value||'')];rows.forEach(function(row){if(ids.indexOf(String(row.booking_passenger_id))===-1)return;if(r){row.visa_rate_card_id=r.id;row.country=r.country;row.visa_type=r.visa_type;row.saudi_company_id=r.saudi_company_id;row.saudi_company_name=r.saudi_company_name;row.pakistani_iata_id=r.pakistani_iata_id;row.pakistani_iata_name=r.pakistani_iata_name;row.vendor_id=r.vendor_id;row.vendor_name=r.vendor_name;row.cost_currency=r.cost_currency;row.cost_rate=r.cost_rate;row.exchange_rate=r.exchange_rate||0;row.vendor_cost_pkr=r.vendor_cost_pkr||0;row.sale_pkr=sale.value!==''?etgpVisaMoney113142(sale.value):etgpVisaMoney113142(r.default_sale_pkr||0);}else if(sale.value!=='')row.sale_pkr=etgpVisaMoney113142(sale.value);if(st.value)row.status=st.value;normalizeRow(row);});m.overlay.remove();persist();render();});});

  search.addEventListener('input',function(){query=search.value;page=1;render();});
  var payload=function(){return {visas:rows.map(function(r){return {booking_passenger_id:Number(r.booking_passenger_id||0),visa_rate_card_id:Number(r.visa_rate_card_id||0),country:r.country||'',visa_type:r.visa_type||'',saudi_company_id:Number(r.saudi_company_id||0),application_reference:r.application_reference||'',visa_number:r.visa_number||'',status:r.status||'pending',issue_date:r.issue_date||null,expiry_date:r.expiry_date||null,sale_pkr:etgpVisaMoney113142(r.sale_pkr),cost_currency:r.cost_currency||'SAR',cost_rate:etgpVisaMoney113142(r.cost_rate),notes:r.notes||''};})};};
  host._etgpVisaPayload113142=payload;
  if(data._draftRestored){feedback.hidden=false;feedback.className='etgp-visa-feedback-113142 is-draft';feedback.textContent='Unsaved Visa data was restored from this browser.';}
  save.addEventListener('click',function(){if(!rows.length&&!dirty){feedback.hidden=false;feedback.className='etgp-visa-feedback-113142 is-error';feedback.textContent='Add at least one passenger to Visa before saving.';return;}var incomplete=rows.filter(function(r){return !Number(r.booking_passenger_id||0)||!Number(r.visa_rate_card_id||0);});if(incomplete.length){feedback.hidden=false;feedback.className='etgp-visa-feedback-113142 is-error';feedback.textContent=incomplete.length+' Visa passenger(s) are incomplete. Select those rows and use Bulk Actions or remove them.';return;}var bodyPayload=payload();var signature=etgpVisaSignature113142(bodyPayload);clearTimeout(timer);dirty=true;etgpVisaDraftWrite113142(bookingId,bodyPayload);save.disabled=true;save.textContent='Saving…';etgpVisaRequest113142(bookingId,'PUT',bodyPayload).then(function(result){var current=payload();var changed=etgpVisaSignature113142(current)!==signature;if(changed){dirty=true;etgpVisaDraftWrite113142(bookingId,current);}else{dirty=false;etgpVisaDraftClear113142(bookingId);rows=(Array.isArray(result.visa_rows)?result.visa_rows:rows).map(function(r){return normalizeRow(Object.assign({},r));});}feedback.hidden=false;feedback.className='etgp-visa-feedback-113142 is-success';feedback.textContent=changed?'Visa Data saved. New unsaved changes remain.':(result.message||'Visa Data saved.');render();etgpRefreshPersistedBookingState113153(bookingId);}).catch(function(error){dirty=true;feedback.hidden=false;feedback.className='etgp-visa-feedback-113142 is-error';feedback.textContent=error&&error.message?error.message:'Visa Data could not be saved.';}).finally(function(){save.disabled=false;save.textContent='Save Visa Data';});});
  refreshSummary();render();
};

var renderVisaProductWorkspace113142=function(shell){
  var bookingId=etgpBookingId11397();var host=create('div','etgp-visa-workspace-113142 is-loading');host.setAttribute('data-etgp-visa-workspace-113142','1');host.appendChild(create('div','etgp-visa-loading-113142','Loading saved Visa data…'));shell.appendChild(host);if(!bookingId){host.innerHTML='';host.appendChild(create('div','etgp-visa-feedback-113142 is-error','Booking ID could not be resolved from this page.'));return;}etgpVisaLoad113142(bookingId).then(function(data){etgpVisaRender113142(host,data,bookingId);}).catch(function(error){host.innerHTML='';host.classList.remove('is-loading');host.appendChild(create('div','etgp-visa-feedback-113142 is-error',error&&error.message?error.message:'Visa Data could not be loaded.'));});
};

var renderProducts=function(
  root,
  reference,
  paxCount
){
  var selected=loadSelected(reference);
  var buttons=root.querySelector('[data-etgp-product-buttons]');
  var shells=root.querySelector('[data-etgp-product-shells]');
  if(!buttons||!shells)return;

  buttons.innerHTML='';
  shells.innerHTML='';

  productDefinitions.forEach(function(product){
    var button=create('button','etgp-product-button','+ '+product.short);
    button.type='button';button.dataset.product=product.key;
    var isSelected=selected.indexOf(product.key)!==-1;
    if(isSelected){button.classList.add('is-selected');button.textContent='✓ '+product.short;}
    if(paxCount<1){button.disabled=true;button.title='Add at least one passenger first.';}

    button.addEventListener('click',function(){
      if(paxCount<1)return;
      etgpAirFlushVisibleDraft113126();
      etgpHotelFlushVisibleDraft113127();
      etgpTransportFlushVisibleDraft113139();
      etgpVisaFlushVisibleDraft113142();
      var next=loadSelected(reference),index=next.indexOf(product.key);
      if(index===-1)next.push(product.key);else next.splice(index,1);
      saveSelected(reference,next);
      renderProducts(root,reference,paxCount);
    });
    buttons.appendChild(button);
    if(!isSelected)return;

    var shell=create('section','etgp-product-shell');shell.dataset.product=product.key;
    var collapsed=etgpProductCollapsed113127(reference,product.key);
    shell.classList.toggle('is-collapsed',collapsed);
    var shellHead=create('div','etgp-product-shell-head');
    var titleBox=create('div','etgp-product-shell-titlebox');
    titleBox.appendChild(create('h3','etgp-product-shell-title',product.label));
    titleBox.appendChild(create('p','etgp-product-shell-note',product.detail));

    var controls=create('div','etgp-product-shell-controls-113127');
    var toggle=create('button','etgp-toggle-product-113127',collapsed?'Expand':'Collapse');
    toggle.type='button';toggle.setAttribute('aria-expanded',collapsed?'false':'true');
    var remove=create('button','etgp-remove-product','Remove');remove.type='button';
    controls.appendChild(toggle);controls.appendChild(remove);
    shellHead.appendChild(titleBox);shellHead.appendChild(controls);shell.appendChild(shellHead);

    var shellBody=create('div','etgp-product-shell-body-113127');
    shellBody.hidden=collapsed;shell.appendChild(shellBody);
    var setCollapsed=function(value){
      collapsed=!!value;etgpSetProductCollapsed113127(reference,product.key,collapsed);
      shell.classList.toggle('is-collapsed',collapsed);shellBody.hidden=collapsed;
      toggle.textContent=collapsed?'Expand':'Collapse';toggle.setAttribute('aria-expanded',collapsed?'false':'true');
    };
    toggle.addEventListener('click',function(){setCollapsed(!collapsed);});
    shellHead.addEventListener('dblclick',function(event){if(event.target.closest('button'))return;setCollapsed(!collapsed);});
    remove.addEventListener('click',function(){
      etgpAirFlushVisibleDraft113126();etgpHotelFlushVisibleDraft113127();etgpTransportFlushVisibleDraft113139();
      etgpVisaFlushVisibleDraft113142();
      var next=loadSelected(reference).filter(function(key){return key!==product.key;});
      saveSelected(reference,next);renderProducts(root,reference,paxCount);
    });

    if(product.key==='air'){
      renderAirProductWorkspace113106(shellBody);
    }else if(product.key==='hotel'){
      renderHotelProductWorkspace113127(shellBody);
    }else if(product.key==='visa'){
      renderVisaProductWorkspace113142(shellBody);
    }else if(product.key==='transport'){
      renderTransportProductWorkspace113139(shellBody);
    }else{
      var readiness=create('div','etgp-product-readiness');
      readiness.innerHTML='<strong>Product selected.</strong> Operational data and commercials will live together inside this section.';
      shellBody.appendChild(readiness);
    }
    shells.appendChild(shell);
  });

  var lock=root.querySelector('[data-etgp-product-lock]');
  if(lock)lock.hidden=paxCount>0;
  renderProgress(root,paxCount,selected.length);
  etgpRefreshPersistedBookingState113153(etgpBookingId11397(),selected);
};

var moveAlerts=function(
  content,
  root
){
  var alerts=Array.prototype.slice.call(
    content.querySelectorAll(
      ':scope > .alert,:scope > [role="alert"]'
    )
  );

  if(alerts.length<1)return;

  var tray=create(
    'div',
    'etgp-alerts'
  );

  alerts.forEach(function(alert){
    tray.appendChild(
      alert
    );
  });

  root.appendChild(
    tray
  );
};

var cleanPanel=function(panel){
  if(!panel)return;

  panel.classList.add(
    'etgp-native-panel'
  );

  [
    'width',
    'max-width',
    'min-width',
    'margin',
    'margin-left',
    'margin-right',
    'grid-column',
    'flex',
    'flex-basis'
  ].forEach(function(property){
    panel.style.removeProperty(
      property
    );
  });
};

var collectPassengerExtras=function(
  content,
  passengerPanel
){
  var extras=[];

  var push=function(node){
    if(
      node
      && node!==passengerPanel
      && !passengerPanel.contains(node)
      && extras.indexOf(node)===-1
    ){
      extras.push(node);
    }
  };

  /* ERP-11.3.102 compatibility retention: keep any host Saved Passenger form
     out of the visible shell if it exists, but do not depend on it for quick-add.
     The approved one-line editor now writes through the ERP-owned JSON quick-add
     endpoint because focused GENERAL production renders may omit this native form. */
  Array.prototype.slice.call(
    content.querySelectorAll('form[action]')
  ).forEach(function(form){
    var raw=form.getAttribute('action')||form.action||'';
    var path='';
    try{path=new URL(raw,window.location.href).pathname;}catch(e){path=String(raw||'');}

    var isSavedRoute=/\/operations\/bookings\/\d+\/passengers\/from-profile\/?$/i.test(path);
    var hasSavedIds=!!form.querySelector('input[name="passenger_ids[]"]');
    var hasSavedSearch=Array.prototype.slice.call(
      form.querySelectorAll('input[type="search"],input[type="text"]')
    ).some(function(input){
      var hint=norm((input.name||'')+' '+(input.id||'')+' '+(input.placeholder||''));
      return hint.indexOf('search')!==-1
        && (hint.indexOf('passport')!==-1||hint.indexOf('cnic')!==-1||hint.indexOf('mobile')!==-1||hint.indexOf('email')!==-1);
    });

    if(isSavedRoute||hasSavedIds||hasSavedSearch){
      push(form);
    }
  });

  Array.prototype.slice.call(
    content.querySelectorAll(
      'button,a,input[type="submit"]'
    )
  ).forEach(function(control){
    var label=norm(
      control.textContent
      || control.value
    );

    if(
      label.indexOf('reuse previous')!==-1
      || label.indexOf('new passenger')!==-1
    ){
      push(
        control.closest('form')
        || control.parentElement
        || control
      );
    }
  });

  Array.prototype.slice.call(
    content.querySelectorAll(
      'table,.passenger-table'
    )
  ).forEach(function(table){
    var value=norm(
      table.textContent
    );

    if(
      table.classList.contains(
        'passenger-table'
      )
      || (
        value.indexOf('passenger')!==-1
        && (
          value.indexOf('passport')!==-1
          || value.indexOf('date of birth')!==-1
        )
      )
    ){
      push(
        table.closest('.table-responsive')
        || table
      );
    }
  });

  var currentHeading=exactNodes(
    content,
    'Current Booking Passengers'
  )[0];

  if(currentHeading){
    var holder=currentHeading.parentElement;

    for(
      var depth=0;
      depth<4&&holder;
      depth++
    ){
      if(
        holder.querySelector(
          'table,.passenger-table'
        )
        || norm(holder.textContent).indexOf(
          'no passengers yet'
        )!==-1
      ){
        push(holder);
        break;
      }

      holder=holder.parentElement;
    }
  }

  return extras;
};

var passengerActionTray=function(
  passengerCard
){
  if(!passengerCard)return;

  var head=passengerCard.querySelector(
    ':scope > .etgp-card-head'
  );
  if(!head)return;

  var existing=head.querySelector(
    ':scope > .etgp-passenger-actions'
  );
  if(existing)existing.remove();

  /*
   * ERP-11.3.96: never move native Passenger forms into the card header.
   * Production has multiple wrapper variants and moving a form can detach the
   * search/editor fragments from the mode container. Render two stable mode
   * controls instead and leave every native form in the hidden source host.
   */
  var tray=create('div','etgp-passenger-actions');

  var reuse=create('button','btn etgp-passenger-mode-button','Reuse Previous');
  reuse.type='button';
  reuse.dataset.etgpPassengerModeControl='reuse';

  var add=create('button','btn btn-primary etgp-passenger-mode-button','+ New Passenger');
  add.type='button';
  add.dataset.etgpPassengerModeControl='new';

  tray.appendChild(reuse);
  tray.appendChild(add);
  head.appendChild(tray);
};

var passengerFieldLabel=function(unit){
  if(!unit)return '';
  var label=unit.querySelector('label,.form-label');
  return norm(label?label.textContent:'');
};

var passengerFormPath=function(form){
  if(!form)return '';
  var raw=form.getAttribute('action')||form.action||'';
  try{
    return new URL(raw,window.location.href).pathname;
  }catch(e){
    return String(raw||'');
  }
};

var passengerFormByKind=function(passengerCard,kind){
  if(!passengerCard)return null;

  var forms=Array.prototype.slice.call(
    passengerCard.querySelectorAll('form')
  );

  var routeMatch=forms.find(function(form){
    var path=passengerFormPath(form);
    if(kind==='saved'){
      return /\/operations\/bookings\/\d+\/passengers\/from-profile\/?$/i.test(path);
    }
    if(kind==='new'){
      return /\/operations\/bookings\/\d+\/passengers\/?$/i.test(path);
    }
    return false;
  });
  if(routeMatch)return routeMatch;

  /* Production GENERAL markup has changed shape across releases, so route
     matching alone is not sufficient. Fall back to actual visible controls. */
  if(kind==='saved'){
    return forms.find(function(form){
      if(form.querySelector('input[name="passenger_ids[]"]'))return true;
      return Array.prototype.slice.call(
        form.querySelectorAll('input[type="search"],input[type="text"]')
      ).some(function(input){
        var hint=norm(input.getAttribute('placeholder'));
        return hint.indexOf('search name')!==-1
          && hint.indexOf('passport')!==-1;
      });
    })||null;
  }

  if(kind==='new'){
    return forms.find(function(form){
      var names=Array.prototype.slice.call(
        form.querySelectorAll('input,select,textarea')
      ).map(function(control){
        return norm([
          control.getAttribute('name')||'',
          control.getAttribute('id')||''
        ].join(' '));
      }).join(' | ');

      var hasName=(names.indexOf('first_name')!==-1||names.indexOf('first name')!==-1)
        && (names.indexOf('last_name')!==-1||names.indexOf('last name')!==-1);
      var hasPassengerFields=names.indexOf('passport')!==-1
        || names.indexOf('date_of_birth')!==-1
        || names.indexOf('date of birth')!==-1;

      if(hasName&&hasPassengerFields)return true;

      return Array.prototype.slice.call(
        form.querySelectorAll('button,input[type="submit"]')
      ).some(function(control){
        var label=norm(control.textContent||control.value);
        return label.indexOf('save & add passenger')!==-1;
      });
    })||null;
  }

  return null;
};

var fieldControlSelector=
  'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),select,textarea';

var labelForControl=function(form,control){
  if(!form||!control)return null;

  var id=String(control.id||'');
  if(id){
    var labels=Array.prototype.slice.call(
      form.querySelectorAll('label[for]')
    );
    var linked=labels.find(function(label){
      return String(label.getAttribute('for')||'')===id;
    });
    if(linked)return linked;
  }

  var wrapping=control.closest('label');
  if(wrapping&&form.contains(wrapping))return wrapping;

  var parent=control.parentElement;
  for(var depth=0;depth<5&&parent&&parent!==form;depth++){
    var nearby=Array.prototype.slice.call(
      parent.querySelectorAll('label,.form-label')
    ).find(function(label){
      return !label.closest('.etgp-passenger-entry-actions');
    });
    if(nearby)return nearby;
    parent=parent.parentElement;
  }

  return null;
};

var fieldUnitForControl=function(form,control){
  if(!form||!control)return null;

  var label=labelForControl(form,control);
  var node=control.parentElement;
  var best=null;

  for(var depth=0;depth<7&&node&&node!==form;depth++){
    var count=node.querySelectorAll(fieldControlSelector).length;
    if(count===1&&(!label||node.contains(label))){
      best=node;
      /*
       * Prefer a recognizable field wrapper, but never climb into a container
       * that owns multiple passenger fields.
       */
      if(
        node.classList.contains('mb-3')
        || node.classList.contains('form-group')
        || /(^|\s)col(?:-|$)/.test(node.className||'')
        || node.classList.contains('field')
      ){
        break;
      }
    }
    node=node.parentElement;
  }

  return best||control.parentElement;
};

var makePassengerSubpanelHeading=function(
  form,
  title,
  note
){
  if(!form||form.querySelector(':scope > .etgp-passenger-subpanel-heading'))return;

  var head=create('div','etgp-passenger-subpanel-heading');
  head.appendChild(create('div','etgp-passenger-subpanel-title',title));
  head.appendChild(create('div','etgp-passenger-subpanel-note',note));
  form.insertBefore(head,form.firstChild);
};

var cleanupEmptyPassengerWrappers=function(form){
  if(!form)return;

  Array.prototype.slice.call(
    form.querySelectorAll('.row,.mb-2,.mb-3,.form-group,[class*="col-"]')
  ).reverse().forEach(function(node){
    if(
      node.closest('.etgp-passenger-field-grid-v2')
      || node.closest('.etgp-saved-traveller-list')
      || node.querySelector('input,select,textarea,button,a')
    ){
      return;
    }

    if(norm(node.textContent)===''){
      node.remove();
    }
  });
};

var passengerControlMeta=function(form,control){
  if(!form||!control)return '';
  var label=labelForControl(form,control);
  return norm([
    label?label.textContent:'',
    control.getAttribute('name')||'',
    control.getAttribute('id')||'',
    control.getAttribute('placeholder')||''
  ].join(' '));
};

var passengerControlFind=function(form,tokens){
  if(!form)return null;
  var list=Array.prototype.slice.call(form.querySelectorAll(fieldControlSelector));
  return list.find(function(control){
    var meta=passengerControlMeta(form,control);
    return tokens.some(function(token){return meta.indexOf(token)!==-1;});
  })||null;
};

var passengerUnitHide=function(form,control){
  if(!form||!control)return;
  var unit=fieldUnitForControl(form,control)||control.parentElement;
  if(unit)unit.classList.add('etgp-passenger-native-hidden-field');
};

var passengerUnitPrepare=function(form,control,labelText,className){
  if(!form||!control)return null;
  var unit=fieldUnitForControl(form,control)||control.parentElement;
  if(!unit)return null;
  unit.classList.add('etgp-passenger-quick-field');
  if(className)unit.classList.add(className);

  var label=labelForControl(form,control);
  if(label){
    label.textContent=labelText;
  }else{
    var created=create('label','form-label',labelText);
    if(control.id)created.setAttribute('for',control.id);
    unit.insertBefore(created,unit.firstChild);
  }
  return unit;
};

var setPassengerNativeName=function(form,fullName){
  if(!form)return true;
  var first=passengerControlFind(form,['first_name','first name']);
  var middle=passengerControlFind(form,['middle_name','middle name']);
  var last=passengerControlFind(form,['last_name','last name']);
  var title=passengerControlFind(form,['title']);

  var raw=plain(fullName);
  if(!raw)return false;

  var words=raw.split(/\s+/).filter(Boolean);
  var honorific='';
  if(words.length>1){
    var head=norm(words[0]).replace(/\./g,'');
    var known=['mr','mrs','ms','miss','master','dr'];
    if(known.indexOf(head)!==-1){
      honorific=words.shift();
    }
  }

  if(title&&honorific){
    var wanted=norm(honorific).replace(/\./g,'');
    Array.prototype.slice.call(title.options||[]).some(function(option){
      var optionValue=norm(option.value||option.textContent).replace(/\./g,'');
      if(optionValue===wanted){
        title.value=option.value;
        return true;
      }
      return false;
    });
  }

  var firstValue='';
  var lastValue='';
  if(words.length===1){
    firstValue=words[0];
    /* Native GENERAL currently requires Last Name. Keep quick entry one-field
       while satisfying that existing contract for single-token names. */
    lastValue='.';
  }else{
    lastValue=words.pop()||'.';
    firstValue=words.join(' ')||lastValue;
  }

  if(first)first.value=firstValue;
  if(middle)middle.value='';
  if(last)last.value=lastValue;
  return !!(firstValue&&lastValue);
};

var organizeCurrentPassengerTable=function(passengerCard){
  if(!passengerCard)return;

  var keyForHeader=function(text){
    text=norm(text);
    if(text==='#'||text==='no.'||text==='no')return 'number';
    if(text.indexOf('lead')!==-1)return 'lead';
    if(text.indexOf('passenger')!==-1||text==='name')return 'name';
    if(text.indexOf('age type')!==-1||text.indexOf('fare as')!==-1)return 'fare';
    if(text.indexOf('passport expiry')!==-1)return 'expiry';
    if(text.indexOf('passport no')!==-1||text.indexOf('passport number')!==-1)return 'passport';
    if(text.indexOf('date of birth')!==-1||text==='dob')return 'dob';
    if(text.indexOf('nationality')!==-1||text==='national')return 'national';
    if(text.indexOf('status')!==-1)return 'status';
    if(text.indexOf('action')!==-1)return 'action';
    return '';
  };

  var desired=['number','name','fare','passport','dob','expiry','national','status','action'];
  var title={
    number:'#', name:'Name', fare:'Adult / Fare Type', passport:'Passport Number',
    dob:'DOB', expiry:'Passport Expiry', national:'National', status:'Status', action:'Action'
  };

  Array.prototype.slice.call(passengerCard.querySelectorAll('table')).forEach(function(table){
    if(table.closest('.etgp-passenger-mode-panel'))return;
    var headers=Array.prototype.slice.call(table.querySelectorAll('thead th'));
    if(!headers.length)return;

    var headerKeys=headers.map(function(th){return keyForHeader(th.textContent);});
    if(headerKeys.indexOf('name')===-1&&headerKeys.indexOf('passport')===-1)return;

    table.classList.add('etgp-current-passenger-table');
    var indexByKey={};
    headerKeys.forEach(function(key,index){
      if(key&&indexByKey[key]===undefined)indexByKey[key]=index;
    });

    Array.prototype.slice.call(table.querySelectorAll('tr')).forEach(function(row){
      var original=Array.prototype.slice.call(row.children);
      var used=[];
      desired.forEach(function(key){
        var index=indexByKey[key];
        if(index===undefined||!original[index])return;
        var cell=original[index];
        cell.classList.remove('etgp-passenger-column-hidden');
        row.appendChild(cell);
        used.push(cell);
      });
      original.forEach(function(cell){
        if(used.indexOf(cell)===-1){
          cell.classList.add('etgp-passenger-column-hidden');
          row.appendChild(cell);
        }
      });
    });

    Array.prototype.slice.call(table.querySelectorAll('thead th')).forEach(function(th){
      var key=keyForHeader(th.textContent);
      if(title[key])th.textContent=title[key];
    });

    /* ERP-11.3.135 — Passenger list shows only the fare-type result.
     * Remove native explanatory labels (Age Type / Auto / Fare As) from the
     * visible booking table while preserving the native Fare As override
     * select + Apply action when that control exists. */
    Array.prototype.slice.call(table.querySelectorAll('tbody tr')).forEach(function(row){
      var cells=Array.prototype.slice.call(row.children).filter(function(cell){
        return !cell.classList.contains('etgp-passenger-column-hidden');
      });
      var fareCell=cells[2]||null;
      if(!fareCell)return;

      var existingWrap=fareCell.querySelector('.etgp-passenger-fare-result-113135');
      if(existingWrap)return;

      var select=fareCell.querySelector('select');
      var applyButton=fareCell.querySelector('button, input[type=submit], .btn');
      var fareTokens=[];
      var tokenWalker=document.createTreeWalker(fareCell,NodeFilter.SHOW_TEXT,null);
      var textNode;
      while((textNode=tokenWalker.nextNode())){
        var parent=textNode.parentElement;
        if(parent&&parent.closest('select,option,button,input'))continue;
        var cleaned=String(textNode.nodeValue||'')
          .replace(/Age\s*Type\s*:/ig,' ')
          .replace(/Fare\s*As\s*:?/ig,' ')
          .replace(/\bAuto\b/ig,' ')
          .replace(/\s+/g,' ');
        textNode.nodeValue=cleaned;
        var hits=cleaned.toUpperCase().match(/\b(ADULT|CHILD|INFANT|ADT|CHD|INF)\b/g)||[];
        hits.forEach(function(v){fareTokens.push(v);});
      }

      var exactNodes=Array.prototype.slice.call(fareCell.querySelectorAll('strong,b,span,small,div')).filter(function(el){
        if(el.closest('select,button'))return false;
        return /^(ADULT|CHILD|INFANT|ADT|CHD|INF)$/i.test(norm(el.textContent));
      });

      if(select){
        /* The select itself is the fare result. Hide duplicate Age Type result
           tokens and keep only the compact native select + Apply action. */
        exactNodes.forEach(function(el){el.classList.add('etgp-fare-native-label-hidden-113135');});
        Array.prototype.slice.call(fareCell.childNodes).forEach(function(node){
          if(node.nodeType===Node.TEXT_NODE&&norm(node.nodeValue)==='')node.nodeValue='';
        });
        var wrap=create('div','etgp-passenger-fare-result-113135 etgp-passenger-fare-result-editable-113135');
        var form=select.closest('form');
        if(form&&fareCell.contains(form)){
          wrap.appendChild(form);
        }else{
          wrap.appendChild(select);
          if(applyButton&&fareCell.contains(applyButton))wrap.appendChild(applyButton);
        }
        fareCell.appendChild(wrap);
      }else{
        var result='';
        exactNodes.some(function(el){
          var v=norm(el.textContent).toUpperCase();
          if(v){result=v;return true;}
          return false;
        });
        if(!result&&fareTokens.length)result=fareTokens[fareTokens.length-1];
        if(!result){
          var raw=norm(fareCell.textContent).toUpperCase();
          var m=raw.match(/\b(ADULT|CHILD|INFANT|ADT|CHD|INF)\b/);
          result=m?m[1]:raw;
        }
        fareCell.innerHTML='';
        var resultWrap=create('div','etgp-passenger-fare-result-113135');
        resultWrap.textContent=result||'—';
        fareCell.appendChild(resultWrap);
      }
      fareCell.dataset.etgpFareResult113135='1';
    });
  });
};

var organizeNewPassengerForm=function(passengerCard){
  var form=passengerFormByKind(passengerCard,'new');
  if(!form)return null;

  form.classList.add(
    'etgp-passenger-entry-form',
    'etgp-passenger-mode-panel',
    'etgp-passenger-mode-new'
  );

  var nativeControls={
    first:passengerControlFind(form,['first_name','first name']),
    middle:passengerControlFind(form,['middle_name','middle name']),
    last:passengerControlFind(form,['last_name','last name']),
    title:passengerControlFind(form,['title']),
    gender:passengerControlFind(form,['gender']),
    dob:passengerControlFind(form,['date_of_birth','date of birth','dob']),
    type:passengerControlFind(form,['passenger_type','passenger type','age type','fare as']),
    nationality:passengerControlFind(form,['nationality']),
    passport:passengerControlFind(form,['passport_no','passport no','passport number']),
    expiry:passengerControlFind(form,['passport_expiry','passport expiry']),
    cnic:passengerControlFind(form,['cnic','national id']),
    mobile:passengerControlFind(form,['mobile']),
    email:passengerControlFind(form,['email']),
    notes:passengerControlFind(form,['notes'])
  };

  /* Never move native grid wrappers. Keep native controls authoritative but
     hidden, and drive them from stable quick-entry proxy controls. */
  Array.prototype.slice.call(form.children).forEach(function(child){
    if(child.matches&&child.matches('input[type="hidden"]'))return;
    if(child.classList&&(
      child.classList.contains('etgp-passenger-subpanel-heading')
      || child.classList.contains('etgp-passenger-quick-row')
    ))return;
    child.classList.add('etgp-passenger-native-source-block');
  });

  Array.prototype.slice.call(form.querySelectorAll(fieldControlSelector)).forEach(function(control){
    control.removeAttribute('required');
  });

  makePassengerSubpanelHeading(
    form,
    'Add Passenger',
    'One-line quick add. Enter the traveller details and press Add.'
  );

  var heading=form.querySelector(':scope > .etgp-passenger-subpanel-heading');
  if(heading)heading.classList.remove('etgp-passenger-native-source-block');

  var quick=form.querySelector(':scope > .etgp-passenger-quick-row');
  if(!quick){
    quick=create('div','etgp-passenger-quick-row');
    if(heading&&heading.nextSibling)form.insertBefore(quick,heading.nextSibling);
    else form.appendChild(quick);
  }
  quick.classList.remove('etgp-passenger-native-source-block');
  quick.innerHTML='';

  var makeProxy=function(key,labelText,nativeControl,className,placeholder){
    var unit=create('div','etgp-passenger-quick-field '+(className||''));
    unit.setAttribute('data-etgp-proxy-field',key);
    unit.appendChild(create('label','form-label',labelText));

    var control;
    if(nativeControl&&nativeControl.tagName&&nativeControl.tagName.toLowerCase()==='select'){
      control=nativeControl.cloneNode(true);
      control.removeAttribute('name');
      control.removeAttribute('id');
      control.removeAttribute('form');
      control.classList.remove('etgp-passenger-native-hidden-field');
    }else{
      control=create('input','form-control');
      var nativeType=nativeControl?norm(nativeControl.getAttribute('type')):'';
      control.type=(nativeType==='date')?'date':'text';
      if(placeholder)control.placeholder=placeholder;
      if(nativeControl&&nativeControl.value)control.value=nativeControl.value;
    }

    control.setAttribute('data-etgp-proxy-control',key);
    control.autocomplete='off';
    unit.appendChild(control);
    quick.appendChild(unit);
    return control;
  };

  var nameUnit=create('div','etgp-passenger-quick-field etgp-passenger-quick-name');
  nameUnit.setAttribute('data-etgp-proxy-field','name');
  nameUnit.appendChild(create('label','form-label','Name'));
  var nameInput=create('input','form-control');
  nameInput.type='text';
  nameInput.autocomplete='off';
  nameInput.placeholder='Enter passenger name';
  nameInput.setAttribute('data-etgp-passenger-full-name','1');
  nameInput.required=true;
  nameUnit.appendChild(nameInput);
  quick.appendChild(nameUnit);

  var typeProxy=makeProxy('type','Adult / Fare Type',nativeControls.type,'etgp-quick-type','Select');
  var passportProxy=makeProxy('passport','Passport Number',nativeControls.passport,'etgp-quick-passport','Enter passport no.');
  var dobProxy=makeProxy('dob','DOB',nativeControls.dob,'etgp-quick-dob','DD MMM YYYY');
  var expiryProxy=makeProxy('expiry','Passport Expiry',nativeControls.expiry,'etgp-quick-expiry','DD MMM YYYY');
  var nationalityProxy=makeProxy('nationality','National',nativeControls.nationality,'etgp-quick-national','Select');

  var statusField=create('div','etgp-passenger-quick-field etgp-passenger-quick-status');
  statusField.appendChild(create('label','form-label','Status'));
  var status=create('select','form-select');
  status.setAttribute('data-etgp-passenger-status','1');
  var option=create('option','','ACTIVE');
  option.value='ACTIVE';
  status.appendChild(option);
  statusField.appendChild(status);
  quick.appendChild(statusField);

  var action=create('div','etgp-passenger-quick-action');
  var add=create('button','btn btn-primary etgp-passenger-quick-add','Add');
  add.type='submit';
  action.appendChild(add);
  quick.appendChild(action);

  Array.prototype.slice.call(form.querySelectorAll('button,input[type="submit"]')).forEach(function(control){
    if(control===add)return;
    var label=norm(control.textContent||control.value);
    if(label.indexOf('save & add passenger')!==-1||label==='add passenger'||label==='save passenger'){
      var holder=control.closest('.d-flex,.text-end,.text-right,.form-actions')||control;
      holder.classList.add('etgp-passenger-native-source-block');
    }
  });

  var copyValue=function(proxy,nativeControl){
    if(!proxy||!nativeControl)return;
    nativeControl.value=proxy.value;
    try{nativeControl.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
  };

  if(!nameInput.value){
    var parts=[];
    [nativeControls.first,nativeControls.middle,nativeControls.last].forEach(function(control){
      if(control&&plain(control.value)&&plain(control.value)!=='.')parts.push(plain(control.value));
    });
    nameInput.value=parts.join(' ');
  }

  if(form.dataset.etgpQuickAddBound!=='96'){
    form.dataset.etgpQuickAddBound='96';
    form.addEventListener('submit',function(event){
      if(!setPassengerNativeName(form,nameInput.value)){
        event.preventDefault();
        nameInput.focus();
        nameInput.setCustomValidity('Enter passenger name.');
        nameInput.reportValidity();
        return;
      }
      nameInput.setCustomValidity('');
      copyValue(typeProxy,nativeControls.type);
      copyValue(passportProxy,nativeControls.passport);
      copyValue(dobProxy,nativeControls.dob);
      copyValue(expiryProxy,nativeControls.expiry);
      copyValue(nationalityProxy,nativeControls.nationality);
    },true);
  }

  nameInput.addEventListener('input',function(){nameInput.setCustomValidity('');});
  return form;
};

var savedTravellerRowForCheckbox=function(form,checkbox){
  var node=checkbox.parentElement;
  var best=null;

  for(var depth=0;depth<8&&node&&node!==form;depth++){
    var count=node.querySelectorAll('input[name="passenger_ids[]"]').length;
    if(count!==1)break;

    best=node;

    if(
      node.classList.contains('row')
      || node.classList.contains('form-check')
      || node.getAttribute('role')==='row'
    ){
      /* keep climbing while the parent still owns exactly one traveller */
    }

    node=node.parentElement;
  }

  return best||checkbox.parentElement;
};

var organizeSavedPassengerForm=function(passengerCard){
  var form=passengerFormByKind(passengerCard,'saved');
  if(!form)return null;

  form.classList.add(
    'etgp-saved-passenger-form',
    'etgp-passenger-mode-panel',
    'etgp-passenger-mode-reuse'
  );

  makePassengerSubpanelHeading(
    form,
    'Reuse Previous Traveller',
    'Search and select saved travellers to add into this booking.'
  );

  var searchInput=Array.prototype.slice.call(
    form.querySelectorAll('input[type="search"],input[type="text"]')
  ).find(function(input){
    var hint=norm(input.getAttribute('placeholder'));
    return hint.indexOf('search name')!==-1
      || (hint.indexOf('passport')!==-1&&hint.indexOf('mobile')!==-1);
  });

  var searchButton=Array.prototype.slice.call(
    form.querySelectorAll('button,input[type="submit"]')
  ).find(function(control){
    return norm(control.textContent||control.value)==='search';
  });

  if(searchInput){
    var oldSearchHolder=searchInput.parentElement;
    var searchRow=form.querySelector(':scope > .etgp-saved-search-row');

    if(!searchRow){
      searchRow=create('div','etgp-saved-search-row');
      if(oldSearchHolder&&oldSearchHolder.parentElement){
        oldSearchHolder.parentElement.insertBefore(searchRow,oldSearchHolder);
      }else{
        form.insertBefore(searchRow,form.firstChild);
      }
    }

    searchRow.appendChild(searchInput);
    if(searchButton)searchRow.appendChild(searchButton);

    if(
      oldSearchHolder
      && oldSearchHolder!==searchRow
      && oldSearchHolder!==form
      && !oldSearchHolder.querySelector('input,select,textarea,button,a')
      && norm(oldSearchHolder.textContent)===''
    ){
      oldSearchHolder.remove();
    }
  }

  /* Remove the broken vertical pseudo-heading generated by native responsive
     markup. The selectable rows below carry their own passenger summary. */
  Array.prototype.slice.call(
    form.querySelectorAll('*')
  ).forEach(function(node){
    if(node.children.length>0)return;
    var value=norm(node.textContent);
    if(
      value==='use'
      || value==='passenger'
      || value==='age'
      || value==='passport'
      || value==='expiry'
    ){
      node.classList.add('etgp-saved-native-column-label');
    }
  });

  var checkboxes=Array.prototype.slice.call(
    form.querySelectorAll('input[name="passenger_ids[]"]')
  );

  var rows=[];
  checkboxes.forEach(function(checkbox){
    var row=savedTravellerRowForCheckbox(form,checkbox);
    if(
      row
      && row!==form
      && rows.indexOf(row)===-1
      && !rows.some(function(existing){return existing.contains(row)||row.contains(existing);})
    ){
      rows.push(row);
    }
  });

  if(rows.length){
    var list=form.querySelector(':scope > .etgp-saved-traveller-list');
    if(!list){
      list=create('div','etgp-saved-traveller-list');
      var first=rows[0];
      first.parentElement.insertBefore(list,first);
    }

    rows.forEach(function(row){
      row.classList.add('etgp-saved-traveller-row');
      var checkbox=row.querySelector('input[name="passenger_ids[]"]');
      var nativeText=plain(row.textContent)
        .replace(/Previous Traveller/ig,' · ')
        .replace(/No additional details/ig,'')
        .replace(/\s*·\s*/g,' · ')
        .replace(/\s+/g,' ')
        .trim();

      if(checkbox){
        checkbox.classList.add('etgp-saved-traveller-checkbox');
        /* Put the real native selector at the left edge of the rebuilt row so
           submission continues to use the existing Passenger Master form. */
        if(checkbox.parentElement!==row){
          row.insertBefore(checkbox,row.firstChild);
        }
      }

      Array.prototype.slice.call(row.children).forEach(function(child){
        if(child===checkbox||child.classList.contains('etgp-saved-traveller-summary'))return;
        child.classList.add('etgp-saved-native-row-content');
      });

      var summary=row.querySelector(':scope > .etgp-saved-traveller-summary');
      if(!summary){
        summary=create('div','etgp-saved-traveller-summary');
        row.appendChild(summary);
      }
      summary.textContent=nativeText||'Saved traveller';

      if(checkbox&&row.dataset.etgpSavedRowBound!=='1'){
        row.dataset.etgpSavedRowBound='1';
        row.addEventListener('click',function(event){
          if(event.target===checkbox||event.target.closest('a,button,input,select,textarea'))return;
          checkbox.checked=!checkbox.checked;
          try{checkbox.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
        });
      }

      list.appendChild(row);
    });

    list.setAttribute(
      'data-etgp-saved-count',
      String(checkboxes.length)
    );
  }

  var addSelected=Array.prototype.slice.call(
    form.querySelectorAll('button,input[type="submit"]')
  ).find(function(control){
    return norm(control.textContent||control.value).indexOf('add selected traveller')!==-1;
  });

  var lead=Array.prototype.slice.call(
    form.querySelectorAll('input[type="checkbox"]')
  ).find(function(box){
    if(box.name==='passenger_ids[]')return false;
    var holder=box.closest('label,.form-check,.mb-2,.mb-3,div')||box.parentElement;
    return holder&&norm(holder.textContent).indexOf('lead / family head')!==-1;
  });

  var actions=form.querySelector(':scope > .etgp-saved-actions');
  if(!actions&&(addSelected||lead)){
    actions=create('div','etgp-saved-actions');
    form.appendChild(actions);
  }

  if(actions){
    if(lead){
      var leadUnit=lead.closest('label,.form-check,.mb-2,.mb-3')||lead.parentElement;
      if(leadUnit&&!actions.contains(leadUnit)){
        leadUnit.classList.add('etgp-saved-lead-option');
        actions.appendChild(leadUnit);
      }
    }

    if(addSelected){
      var addUnit=addSelected.closest('.d-flex,.text-end,.text-right,.form-actions')||addSelected;
      if(!actions.contains(addUnit)){
        addUnit.classList.add('etgp-saved-submit-action');
        actions.appendChild(addUnit);
      }
    }
  }

  cleanupEmptyPassengerWrappers(form);
  return form;
};

var requestReuseAutoLoad=function(passengerCard){
  if(!passengerCard||passengerCard.dataset.etgpReuseAutoLoad==='done')return;
  var form=passengerFormByKind(passengerCard,'saved');
  if(!form)return;

  if(form.querySelector('input[name="passenger_ids[]"]')){
    passengerCard.dataset.etgpReuseAutoLoad='done';
    return;
  }

  var searchButton=Array.prototype.slice.call(
    form.querySelectorAll('button,input[type="submit"]')
  ).find(function(control){
    return norm(control.textContent||control.value)==='search';
  });
  if(!searchButton)return;

  /* The card survives Step-1 AJAX refreshes. Mark it, rather than the native
     form, so the empty-search auto-load can never loop after DOM replacement. */
  passengerCard.dataset.etgpReuseAutoLoad='done';
  passengerCard.dataset.etgpReuseLoading='1';
  window.setTimeout(function(){
    try{searchButton.click();}catch(e){}
  },40);
};

var applyPassengerMode=function(passengerCard,mode){
  if(!passengerCard)return;
  var allowed=['none','reuse','new'];
  if(allowed.indexOf(mode)===-1)mode='none';

  passengerCard.dataset.etgpPassengerMode=mode;

  Array.prototype.slice.call(
    passengerCard.querySelectorAll('[data-etgp-passenger-mode-control]')
  ).forEach(function(control){
    control.classList.toggle(
      'is-active',
      control.dataset.etgpPassengerModeControl===mode
    );
  });

  if(mode==='reuse')requestReuseAutoLoad(passengerCard);
};

var bindPassengerModeControls=function(passengerCard){
  if(!passengerCard)return;

  var controls=Array.prototype.slice.call(
    passengerCard.querySelectorAll('button,a,input[type="submit"]')
  );

  var newControls=[];
  controls.forEach(function(control){
    var label=norm(control.textContent||control.value);

    if(label.indexOf('reuse previous')!==-1){
      control.dataset.etgpPassengerModeControl='reuse';
    }else if(label.indexOf('new passenger')!==-1){
      control.dataset.etgpPassengerModeControl='new';
      newControls.push(control);
    }else if(
      label==='close'
      && control.closest('.etgp-passenger-card')
    ){
      control.dataset.etgpPassengerModeControl='none';
      control.classList.add('etgp-passenger-close-control');
    }

    if(
      control.dataset.etgpPassengerModeControl
      && control.dataset.etgpPassengerModeBound!=='1'
    ){
      control.dataset.etgpPassengerModeBound='1';
      control.addEventListener('click',function(){
        applyPassengerMode(
          passengerCard,
          control.dataset.etgpPassengerModeControl
        );
      });
    }
  });

  /*
   * Native markup currently exposes both "+ New Passenger" and
   * "Create New Passenger". Keep the primary action and remove the duplicate
   * visual button only; the native form/action remains present.
   */
  if(newControls.length>1){
    newControls.forEach(function(control,index){
      if(index===0)return;
      if(norm(control.textContent||control.value).indexOf('create new passenger')!==-1){
        control.classList.add('etgp-passenger-duplicate-action');
      }
    });
  }
};

var passengerLegacySearchInput=function(passengerCard){
  if(!passengerCard)return null;
  return Array.prototype.slice.call(
    passengerCard.querySelectorAll('input[type="search"],input[type="text"]')
  ).find(function(input){
    if(input.closest('.etgp-passenger-quick-row'))return false;
    var hint=norm(input.getAttribute('placeholder'));
    return hint.indexOf('search name')!==-1
      || (hint.indexOf('passport')!==-1&&hint.indexOf('mobile')!==-1);
  })||null;
};

var passengerLegacySearchButton=function(passengerCard,input){
  var scope=(input&&input.closest('form'))||passengerCard;
  if(!scope)return null;
  return Array.prototype.slice.call(
    scope.querySelectorAll('button,input[type="submit"]')
  ).find(function(control){
    return norm(control.textContent||control.value)==='search';
  })||null;
};

var passengerLegacyAddSelected=function(passengerCard){
  if(!passengerCard)return null;
  return Array.prototype.slice.call(
    passengerCard.querySelectorAll('button,input[type="submit"]')
  ).find(function(control){
    var value=norm(control.textContent||control.value);
    return value.indexOf('add selected traveller')!==-1;
  })||null;
};

var savedTravellerProxyRow=function(passengerCard,nativeBox,index){
  if(!nativeBox)return null;
  var nativeRow=savedTravellerRowForCheckbox(
    nativeBox.closest('form')||passengerCard,
    nativeBox
  )||nativeBox.parentElement;

  var summary=plain(
    (nativeRow&&nativeRow.textContent)||''
  )
    .replace(/Previous Traveller/ig,' · ')
    .replace(/Saved Passenger/ig,'')
    .replace(/No additional details/ig,'')
    .replace(/\s*·\s*/g,' · ')
    .replace(/\s+/g,' ')
    .trim();

  if(!summary)summary='Saved traveller '+String(index+1);

  var row=create('label','etgp-reuse-proxy-row');
  var box=create('input','etgp-reuse-proxy-checkbox');
  box.type='checkbox';
  box.checked=!!nativeBox.checked;
  box.setAttribute('aria-label','Select '+summary);

  var text=create('span','etgp-reuse-proxy-summary',summary);
  row.appendChild(box);
  row.appendChild(text);

  box.addEventListener('change',function(){
    nativeBox.checked=box.checked;
    try{nativeBox.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
    var panel=row.closest('.etgp-reuse-proxy');
    if(panel){
      var add=panel.querySelector('[data-etgp-reuse-add-selected]');
      if(add){
        add.disabled=!panel.querySelector('.etgp-reuse-proxy-checkbox:checked');
      }
    }
  });

  return row;
};

var buildReuseProxy=function(passengerCard,editorHost){
  if(!passengerCard||!editorHost)return null;

  var panel=create(
    'section',
    'etgp-passenger-mode-panel etgp-passenger-mode-reuse etgp-reuse-proxy'
  );
  panel.setAttribute('data-etgp-reuse-proxy','1');

  var head=create('div','etgp-passenger-subpanel-heading');
  head.appendChild(create('div','etgp-passenger-subpanel-title','Reuse Previous Traveller'));
  head.appendChild(create('div','etgp-passenger-subpanel-note','Search and select a saved traveller to add into this booking.'));
  panel.appendChild(head);

  var nativeSearch=passengerLegacySearchInput(passengerCard);
  var nativeSearchButton=passengerLegacySearchButton(passengerCard,nativeSearch);
  var nativeSearchForm=nativeSearch&&nativeSearch.closest('form');

  var searchRow=create('div','etgp-reuse-proxy-search');
  var search=create('input','form-control');
  search.type='search';
  search.placeholder='Search name, passport, CNIC / ID, mobile or email...';
  search.value=nativeSearch?nativeSearch.value:'';
  search.autocomplete='off';

  var searchButton=create('button','btn etgp-reuse-proxy-search-button','Search');
  searchButton.type='button';

  var runSearch=function(){
    if(nativeSearch){
      nativeSearch.value=search.value;
      try{nativeSearch.dispatchEvent(new Event('input',{bubbles:true}));}catch(e){}
      try{nativeSearch.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
    }
    passengerCard.dataset.etgpReuseAutoLoad='done';
    if(nativeSearchButton){
      nativeSearchButton.click();
    }else if(nativeSearchForm&&typeof nativeSearchForm.requestSubmit==='function'){
      nativeSearchForm.requestSubmit();
    }
  };

  searchButton.addEventListener('click',runSearch);
  search.addEventListener('keydown',function(event){
    if(event.key==='Enter'){
      event.preventDefault();
      runSearch();
    }
  });

  searchRow.appendChild(search);
  searchRow.appendChild(searchButton);
  panel.appendChild(searchRow);

  var results=create('div','etgp-reuse-proxy-results');
  var nativeBoxes=Array.prototype.slice.call(
    passengerCard.querySelectorAll('input[name="passenger_ids[]"]')
  ).filter(function(box){
    return !box.closest('.etgp-current-passenger-table');
  });

  if(nativeBoxes.length){
    nativeBoxes.forEach(function(nativeBox,index){
      var row=savedTravellerProxyRow(passengerCard,nativeBox,index);
      if(row)results.appendChild(row);
    });
  }else{
    results.appendChild(
      create(
        'div',
        'etgp-reuse-proxy-empty',
        'Saved travellers will load here. Use Search to find a traveller.'
      )
    );
  }
  panel.appendChild(results);

  var footer=create('div','etgp-reuse-proxy-footer');
  var leadWrap=create('label','etgp-reuse-proxy-lead');
  var leadProxy=create('input','');
  leadProxy.type='checkbox';
  leadWrap.appendChild(leadProxy);
  leadWrap.appendChild(create('span','','Make first selected traveller Lead / Family Head if no lead exists'));

  var nativeLead=Array.prototype.slice.call(
    passengerCard.querySelectorAll('input[type="checkbox"]')
  ).find(function(box){
    if(box.name==='passenger_ids[]')return false;
    var holder=box.closest('label,.form-check,.mb-2,.mb-3,div')||box.parentElement;
    return holder&&norm(holder.textContent).indexOf('lead / family head')!==-1;
  });

  if(nativeLead){
    leadProxy.checked=!!nativeLead.checked;
    leadProxy.addEventListener('change',function(){
      nativeLead.checked=leadProxy.checked;
      try{nativeLead.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
    });
  }else{
    leadWrap.hidden=true;
  }

  var nativeAdd=passengerLegacyAddSelected(passengerCard);
  var add=create('button','btn btn-primary etgp-reuse-proxy-add','Add Selected Travellers');
  add.type='button';
  add.setAttribute('data-etgp-reuse-add-selected','1');
  add.disabled=!results.querySelector('.etgp-reuse-proxy-checkbox:checked');
  if(!nativeAdd)add.disabled=true;
  add.addEventListener('click',function(){
    if(!nativeAdd)return;
    nativeAdd.click();
  });

  footer.appendChild(leadWrap);
  footer.appendChild(add);
  panel.appendChild(footer);
  editorHost.appendChild(panel);
  return panel;
};

var stabilizePassengerVisualShell=function(passengerCard){
  if(!passengerCard)return;

  /* Remove only our prior visible shell. Native source is preserved so the
     existing Laravel forms/routes remain authoritative. */
  Array.prototype.slice.call(
    passengerCard.querySelectorAll(':scope > .etgp-passenger-current-host,:scope > .etgp-passenger-editor-host')
  ).forEach(function(node){node.remove();});

  var source=passengerCard.querySelector(
    '[data-et-booking-panel-11375="passengers"]'
  );

  var currentTable=passengerCard.querySelector('.etgp-current-passenger-table');
  if(currentTable){
    var currentHost=create('div','etgp-passenger-current-host');
    var tableUnit=currentTable.closest('.table-responsive')||currentTable;
    currentHost.appendChild(tableUnit);
    var head=passengerCard.querySelector(':scope > .etgp-card-head');
    if(head&&head.nextSibling)passengerCard.insertBefore(currentHost,head.nextSibling);
    else passengerCard.appendChild(currentHost);
  }

  var editorHost=create('div','etgp-passenger-editor-host');
  passengerCard.appendChild(editorHost);

  var newForm=passengerFormByKind(passengerCard,'new');
  if(newForm){
    editorHost.appendChild(newForm);
  }

  buildReuseProxy(passengerCard,editorHost);

  /* The native saved form is a submission/data source only. Remove visual mode
     classes so generic mode CSS can never make it visible beside the proxy. */
  var savedForm=passengerFormByKind(passengerCard,'saved');
  if(savedForm&&savedForm!==newForm){
    savedForm.classList.remove('etgp-passenger-mode-panel','etgp-passenger-mode-reuse');
    savedForm.classList.add('etgp-passenger-native-saved-source');
  }

  if(source)source.classList.add('etgp-passenger-native-source-host');
  Array.prototype.slice.call(passengerCard.querySelectorAll(':scope > .etgp-passenger-extra')).forEach(function(extra){
    if(extra.closest('.etgp-passenger-current-host,.etgp-passenger-editor-host'))return;
    extra.classList.add('etgp-passenger-legacy-extra-hidden');
  });
};

var markPassengerNativeChrome=function(passengerCard){
  if(!passengerCard)return;

  Array.prototype.slice.call(
    passengerCard.querySelectorAll('button,a,input[type="submit"]')
  ).forEach(function(control){
    var value=norm(control.textContent||control.value);
    if(value==='saved / previous travellers'||value==='saved/previous travellers'){
      control.classList.add('etgp-passenger-native-chrome-hidden');
    }
  });

  Array.prototype.slice.call(
    passengerCard.querySelectorAll('h1,h2,h3,h4,h5,h6,p,div,span,strong,small,label')
  ).forEach(function(node){
    if(node.closest('.etgp-passenger-quick-row'))return;
    if(node.closest('.etgp-saved-traveller-row'))return;
    if(node.closest('.etgp-current-passenger-table'))return;

    var value=norm(node.textContent);
    if(!value)return;
    var isLeaf=node.children.length===0;

    var legacyExact=(
      value==='close'
      || value==='use passenger age passport expiry'
      || value==='saved / previous travellers'
      || value==='saved/previous travellers'
    );

    var legacyPrefix=(
      value.indexOf('add passengerselect an existing traveller')===0
      || value.indexOf('create new passengerselect an existing traveller')===0
      || value.indexOf('select an existing traveller or create a new passenger')===0
      || value.indexOf('select a saved traveller below')===0
      || value.indexOf('reusable passenger master')===0
      || value.indexOf('create the traveller once')===0
      || value.indexOf('current booking passengers')===0
    );

    if(legacyExact||(isLeaf&&legacyPrefix)){
      node.classList.add('etgp-passenger-native-chrome-hidden');
      return;
    }

    if(isLeaf&&(
      value.indexOf('previous travellers for')===0
      || value.indexOf('search or open this panel')===0
      || /^\d+\s+active$/.test(value)
    )){
      node.classList.add('etgp-passenger-reuse-copy');
    }
  });
};

var markPassengerFormLayout=function(passengerCard){
  if(!passengerCard)return;

  organizeCurrentPassengerTable(passengerCard);
  organizeSavedPassengerForm(passengerCard);
  organizeNewPassengerForm(passengerCard);
  markPassengerNativeChrome(passengerCard);
  stabilizePassengerVisualShell(passengerCard);
  passengerActionTray(passengerCard);
  bindPassengerModeControls(passengerCard);

  if(!passengerCard.dataset.etgpPassengerMode){
    applyPassengerMode(passengerCard,'new');
  }else{
    applyPassengerMode(
      passengerCard,
      passengerCard.dataset.etgpPassengerMode
    );
  }
};


/* ======================================================================
 * ERP-11.3.100 — GENERAL PASSENGER AUTHORITATIVE SAVED-ATTACH + MASTER-SAFE AUTOFILL
 *
 * Production proved the native GENERAL "Reuse Previous" and native new-
 * passenger editor cannot be used as a reliable visual or submission bridge:
 * their markup and response fragments vary between installed-base releases.
 *
 * Final operational contract:
 * - one compact Add Passenger row is always visible;
 * - no Reuse Previous mode/button;
 * - typing Name or Passport queries an ERP-owned JSON lookup endpoint;
 * - selecting/exact-matching a saved traveller fills the quick row;
 * - Add uses the authoritative native New Passenger / from-profile routes;
 * - successful writes are fetched through the authoritative native routes and
 *   the Passenger table/KPI/product locks refresh in place with no page reload.
 * ====================================================================== */
var etgpBookingId11397=function(){
  var match=String(window.location.pathname||'').match(/\/operations\/bookings\/(\d+)(?:\/edit)?\/?$/i);
  return match?Number(match[1])||0:0;
};

var etgpCsrf11397=function(){
  var meta=document.querySelector('meta[name="csrf-token"]');
  return meta?String(meta.getAttribute('content')||''):'';
};

var etgpDate11397=function(value){
  var raw=plain(value||'');
  if(!raw)return '';
  var match=raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
  return match?match[1]+'-'+match[2]+'-'+match[3]:raw;
};

var etgpCompact11397=function(value){
  return norm(value||'').replace(/[^a-z0-9]+/g,'');
};

var etgpSavedLabel11397=function(row){
  var bits=[plain(row.name||'Saved traveller')];
  if(plain(row.passport_number||''))bits.push(plain(row.passport_number));
  if(plain(row.dob||''))bits.push(plain(row.dob));
  if(plain(row.fare_type||''))bits.push(plain(row.fare_type));
  return bits.join(' · ');
};

var etgpBuildQuickPassenger11397=function(passengerCard,editorHost){
  if(!passengerCard||!editorHost)return null;

  var bookingId=etgpBookingId11397();
  if(!bookingId)return null;

  var panel=create('section','etgp-quick-passenger-11397');
  panel.setAttribute('data-etgp-quick-passenger-11397','1');

  var heading=create('div','etgp-passenger-subpanel-heading');
  heading.appendChild(create('div','etgp-passenger-subpanel-title','Add Passenger'));
  heading.appendChild(create(
    'div',
    'etgp-passenger-subpanel-note',
    'Type a saved traveller name or passport to autofill, or enter a new passenger and press Add.'
  ));
  panel.appendChild(heading);

  var row=create('div','etgp-passenger-quick-row etgp-passenger-quick-row-11397');
  panel.appendChild(row);

  var field=function(key,label,type,placeholder){
    var unit=create('div','etgp-passenger-quick-field etgp-quick-'+key+'-11397');
    unit.setAttribute('data-etgp-quick-field-11397',key);
    unit.appendChild(create('label','form-label',label));
    var control;
    if(type==='select'){
      control=create('select','form-select');
    }else{
      control=create('input','form-control');
      control.type=type||'text';
      if(placeholder)control.placeholder=placeholder;
      control.autocomplete='off';
    }
    control.setAttribute('data-etgp-quick-control-11397',key);
    unit.appendChild(control);
    row.appendChild(unit);
    return {unit:unit,control:control};
  };

  var name=field('name','Name','text','Enter passenger name');
  name.control.required=true;

  var fare=field('fare','Adult / Fare Type','select');
  ['ADULT','CHILD','INFANT'].forEach(function(value){
    var option=create('option','',value.charAt(0)+value.slice(1).toLowerCase());
    option.value=value;
    fare.control.appendChild(option);
  });
  fare.control.value='ADULT';

  var passport=field('passport','Passport Number','text','Enter passport no.');
  var dob=field('dob','DOB','date');
  var expiry=field('expiry','Passport Expiry','date');
  var national=field('national','National','text','Nationality');

  var status=field('status','Status','select');
  var active=create('option','','ACTIVE');
  active.value='ACTIVE';
  status.control.appendChild(active);
  status.control.value='ACTIVE';
  status.control.disabled=true;

  var action=create('div','etgp-passenger-quick-action etgp-quick-action-11397');
  var add=create('button','btn btn-primary etgp-passenger-quick-add','Add');
  add.type='button';
  action.appendChild(add);
  row.appendChild(action);

  var suggestion=create('div','etgp-passenger-suggestions-11397');
  suggestion.hidden=true;
  panel.appendChild(suggestion);

  var feedback=create('div','etgp-passenger-feedback-11397');
  feedback.hidden=true;
  panel.appendChild(feedback);

  var selected={id:0,source:'',name:'',passport:''};
  var timer=0;
  var lookupController=null;
  var suppress=false;

  var clearSavedRef=function(){
    if(suppress)return;
    selected={id:0,source:'',name:'',passport:''};
  };

  var hideSuggestions=function(){
    suggestion.hidden=true;
    suggestion.innerHTML='';
  };

  var showFeedback=function(message,isError){
    feedback.textContent=message||'';
    feedback.classList.toggle('is-error',!!isError);
    feedback.classList.toggle('is-success',!isError&&!!message);
    feedback.hidden=!message;
  };

  var setSelectValue=function(select,value){
    var wanted=String(value||'').toUpperCase();
    var found=false;
    Array.prototype.slice.call(select.options||[]).forEach(function(option){
      if(String(option.value||'').toUpperCase()===wanted){
        select.value=option.value;
        found=true;
      }
    });
    return found;
  };

  var applySaved=function(saved){
    if(!saved)return;
    suppress=true;
    selected={
      id:Number(saved.id)||0,
      source:String(saved.source||''),
      name:plain(saved.name||''),
      passport:plain(saved.passport_number||'')
    };
    name.control.value=plain(saved.name||'');
    passport.control.value=plain(saved.passport_number||'');
    dob.control.value=etgpDate11397(saved.dob||'');
    expiry.control.value=etgpDate11397(saved.passport_expiry||'');
    national.control.value=plain(saved.nationality||'');
    setSelectValue(fare.control,saved.fare_type||'ADULT');
    suppress=false;
    hideSuggestions();
    showFeedback('Saved traveller matched — details autofilled. Review and press Add.',false);
  };

  var renderSuggestions=function(results,query,sourceKey){
    suggestion.innerHTML='';
    if(!results||!results.length){
      suggestion.appendChild(create('div','etgp-passenger-suggestion-empty-11397','No saved traveller match. Continue as a new passenger.'));
      suggestion.hidden=false;
      return;
    }

    var compact=etgpCompact11397(query);
    var exact=results.filter(function(saved){
      if(sourceKey==='passport'){
        return compact!==''&&etgpCompact11397(saved.passport_number||'')===compact;
      }
      return norm(saved.name||'')===norm(query||'');
    });

    /* Exact unique name/passport is safe to autofill immediately. Partial name
       matches remain choices so staff never gets silently matched to the wrong
       person with a common name. */
    if(exact.length===1){
      applySaved(exact[0]);
      return;
    }

    var title=create('div','etgp-passenger-suggestion-title-11397','Saved traveller matches');
    suggestion.appendChild(title);
    results.forEach(function(saved){
      var button=create('button','etgp-passenger-suggestion-row-11397');
      button.type='button';
      button.appendChild(create('strong','',plain(saved.name||'Saved traveller')));
      button.appendChild(create('span','',etgpSavedLabel11397(saved).replace(plain(saved.name||''),'').replace(/^\s*·\s*/,'')));
      button.addEventListener('click',function(){applySaved(saved);});
      suggestion.appendChild(button);
    });
    suggestion.hidden=false;
  };

  var lookup=function(query,sourceKey){
    query=plain(query||'');
    if(query.length<2){
      hideSuggestions();
      return;
    }

    if(lookupController){
      try{lookupController.abort();}catch(e){}
    }
    lookupController=typeof AbortController!=='undefined'?new AbortController():null;

    var url='/system/erp-bookings/'+bookingId+'/passengers/quick-lookup?q='+encodeURIComponent(query);
    fetch(url,{
      method:'GET',
      headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
      credentials:'same-origin',
      signal:lookupController?lookupController.signal:undefined
    })
      .then(function(response){
        if(!response.ok)throw new Error('lookup');
        return response.json();
      })
      .then(function(payload){
        renderSuggestions(Array.isArray(payload.results)?payload.results:[],query,sourceKey);
      })
      .catch(function(error){
        if(error&&error.name==='AbortError')return;
        hideSuggestions();
      });
  };

  var queueLookup=function(control,key){
    window.clearTimeout(timer);
    timer=window.setTimeout(function(){lookup(control.value,key);},260);
  };

  name.control.addEventListener('input',function(){
    /* A changed matched NAME means staff is searching/creating another person.
       Passport/document edits intentionally keep the saved-master reference so
       an expired passport can be renewed for future use. */
    if(selected.id&&norm(name.control.value)!==norm(selected.name)){
      clearSavedRef();
    }
    showFeedback('',false);
    queueLookup(name.control,'name');
  });
  passport.control.addEventListener('input',function(){
    showFeedback('',false);
    if(!selected.id){
      queueLookup(passport.control,'passport');
    }else{
      hideSuggestions();
    }
  });

  [fare.control,dob.control,expiry.control,national.control].forEach(function(control){
    control.addEventListener('change',function(){
      /* Keep the saved Passenger Master reference when staff only corrects a
         non-identity detail. The booking snapshot is intentionally editable. */
      showFeedback('',false);
    });
  });

  document.addEventListener('click',function(event){
    if(!panel.contains(event.target))hideSuggestions();
  });

  var nativeAssign11398=function(control,value){
    if(!control)return;
    var raw=value===null||value===undefined?'':String(value);
    if(control.tagName==='SELECT'){
      if(raw==='')return;
      var wanted=norm(raw);
      var matched=false;
      Array.prototype.slice.call(control.options||[]).some(function(option){
        if(norm(option.value)===wanted||norm(option.textContent)===wanted){
          control.value=option.value;
          matched=true;
          return true;
        }
        return false;
      });
      if(!matched&&(wanted==='pakistani'||wanted==='pakistan'||wanted==='pk')){
        Array.prototype.slice.call(control.options||[]).some(function(option){
          var candidate=norm((option.value||'')+' '+(option.textContent||''));
          if(candidate==='pk'||candidate.indexOf('pakistan')!==-1){
            control.value=option.value;
            matched=true;
            return true;
          }
          return false;
        });
      }
    }else{
      control.value=raw;
    }
    try{control.dispatchEvent(new Event('input',{bubbles:true}));}catch(e){}
    try{control.dispatchEvent(new Event('change',{bubbles:true}));}catch(e){}
  };

  var populateNativeNew11398=function(form,payload){
    if(!form)return false;
    if(!setPassengerNativeName(form,payload.name))return false;
    nativeAssign11398(passengerControlFind(form,['passenger_type','passenger type','age type','fare as']),payload.fare_type);
    nativeAssign11398(passengerControlFind(form,['passport_no','passport no','passport number']),payload.passport_number);
    nativeAssign11398(passengerControlFind(form,['date_of_birth','date of birth','dob']),payload.dob||'');
    nativeAssign11398(passengerControlFind(form,['passport_expiry','passport expiry']),payload.passport_expiry||'');
    nativeAssign11398(passengerControlFind(form,['nationality']),payload.nationality||'');
    return true;
  };

  var nativeSubmitter11399=function(form,kind){
    if(!form)return null;
    var controls=Array.prototype.slice.call(form.querySelectorAll('button,input[type="submit"]'));
    var preferred=kind==='saved'
      ? ['add selected travellers','add selected','add travellers','add passenger','add']
      : ['save & add passenger','save and add passenger','add passenger','create passenger','save'];
    var found=null;
    preferred.some(function(label){
      found=controls.find(function(control){
        return norm(control.textContent||control.value||'')===norm(label);
      })||null;
      return !!found;
    });
    return found;
  };

  var appendSubmitter11399=function(fd,submitter){
    if(!fd||!submitter||!submitter.name)return;
    fd.delete(submitter.name);
    fd.append(submitter.name,String(submitter.value||submitter.textContent||'').trim());
  };

  var parseNativeResponse11399=function(response,text){
    var doc=null;
    try{doc=new DOMParser().parseFromString(String(text||''),'text/html');}catch(e){}
    if(!response.ok||!doc){
      throw new Error('Passenger could not be saved.');
    }

    var messages=Array.prototype.slice.call(doc.querySelectorAll(
      '.alert-danger,.field-error,[role="alert"]'
    )).map(function(node){
      return plain(node.textContent||'');
    }).filter(Boolean);

    var blocking=messages.find(function(message){
      var value=norm(message);
      return value.indexOf('please correct')!==-1
        || value.indexOf('already exists')!==-1
        || value.indexOf('could not')!==-1
        || value.indexOf('validation')!==-1
        || value.indexOf('required')!==-1;
    });
    if(blocking){
      var error=new Error(blocking);
      error.nativeDoc=doc;
      throw error;
    }
    return doc;
  };

  var syncPassengerDoc11399=function(doc,message){
    var synced=false;
    if(doc&&typeof window.etGeneralProgressiveStep1Sync11390==='function'){
      synced=!!window.etGeneralProgressiveStep1Sync11390(doc,['metrics','passengers']);
    }
    if(typeof window.etBookingLiveNotice103169==='function'){
      window.etBookingLiveNotice103169(message||'Passenger added.',false);
    }
    return synced;
  };

  var submitNativeFetch11399=function(form,kind,passengerId){
    if(!form)return Promise.reject(new Error('The existing passenger save form is unavailable.'));
    var fd=new FormData(form);
    if(kind==='saved'){
      fd.delete('passenger_ids[]');
      if(passengerId)fd.append('passenger_ids[]',String(passengerId));
    }
    appendSubmitter11399(fd,nativeSubmitter11399(form,kind));

    var actionUrl=form.getAttribute('action')||form.action||'';
    try{actionUrl=new URL(actionUrl,window.location.href).toString();}catch(e){}

    return fetch(actionUrl,{
      method:String(form.method||'POST').toUpperCase()==='GET'?'POST':String(form.method||'POST').toUpperCase(),
      body:fd,
      credentials:'same-origin',
      redirect:'follow',
      headers:{
        'X-Requested-With':'XMLHttpRequest',
        'Accept':'text/html,application/xhtml+xml'
      }
    }).then(function(response){
      return response.text().then(function(text){
        return parseNativeResponse11399(response,text);
      });
    });
  };

  var submitNewNative11398=function(payload){
    var form=passengerFormByKind(passengerCard,'new');
    if(!form||!populateNativeNew11398(form,payload)){
      return Promise.reject(new Error('The existing passenger save form is unavailable.'));
    }
    return submitNativeFetch11399(form,'new',0);
  };

  var attachSavedNative11398=function(payload){
    var form=passengerFormByKind(passengerCard,'saved');
    if(!form||!payload.master_id){
      return Promise.reject(new Error('Saved passenger could not be attached using the existing booking passenger route.'));
    }
    return submitNativeFetch11399(form,'saved',payload.master_id);
  };

  var sameNativeFormAction113100=function(candidate,form){
    if(!candidate||!form)return false;
    var left='',right='';
    try{left=new URL(candidate.getAttribute('action')||candidate.action,window.location.href).pathname;}catch(e){left=String(candidate.getAttribute('action')||candidate.action||'');}
    try{right=new URL(form.getAttribute('action')||form.action,window.location.href).pathname;}catch(e){right=String(form.getAttribute('action')||form.action||'');}
    return left===right;
  };

  var freshSavedForm113100=function(doc,form){
    if(!doc||!form)return null;
    var forms=Array.prototype.slice.call(doc.querySelectorAll('form[action]')).filter(function(candidate){
      return sameNativeFormAction113100(candidate,form);
    });
    var scored=forms.map(function(candidate){
      var score=0;
      if(candidate.querySelector('input[name="passenger_ids[]"]'))score+=100;
      if(nativeSearchControl11399(candidate))score+=25;
      var copy=norm(candidate.textContent||'');
      if(copy.indexOf('saved')!==-1||copy.indexOf('previous')!==-1)score+=15;
      if(copy.indexOf('select')!==-1)score+=5;
      return {form:candidate,score:score};
    }).sort(function(a,b){return b.score-a.score;});
    return scored.length?scored[0].form:null;
  };

  var nativeSearchControl11399=function(form){
    if(!form)return null;
    var controls=Array.prototype.slice.call(form.querySelectorAll('input[type="search"],input[type="text"]'));
    return controls.find(function(control){
      var key=norm((control.name||'')+' '+(control.id||'')+' '+(control.placeholder||''));
      return key.indexOf('search')!==-1||key.indexOf('passport')!==-1||key.indexOf('cnic')!==-1||key.indexOf('mobile')!==-1||key.indexOf('email')!==-1;
    })||controls[0]||null;
  };

  var nativeSearchSubmitter11399=function(form){
    if(!form)return null;
    return Array.prototype.slice.call(form.querySelectorAll('button,input[type="submit"]')).find(function(control){
      return norm(control.textContent||control.value||'')==='search';
    })||null;
  };

  var bestNativeSavedCheckbox11399=function(form,payload){
    if(!form)return null;
    var passportNeedle=etgpCompact11397(payload.passport_number||'');
    var nameNeedle=norm(payload.name||'');
    var nameParts=nameNeedle.split(' ').filter(function(part){return part.length>1;});
    var boxes=Array.prototype.slice.call(form.querySelectorAll('input[name="passenger_ids[]"]'));
    var scored=boxes.map(function(box){
      var scope=box.closest('tr')||box.closest('label')||box.parentElement||form;
      var text=plain(scope.textContent||'');
      var compactText=etgpCompact11397(text);
      var normalized=norm(text);
      var score=0;
      if(passportNeedle&&compactText.indexOf(passportNeedle)!==-1)score+=100;
      if(nameNeedle&&normalized.indexOf(nameNeedle)!==-1)score+=50;
      nameParts.forEach(function(part){if(normalized.indexOf(part)!==-1)score+=4;});
      return {box:box,score:score};
    }).sort(function(a,b){return b.score-a.score;});
    return scored.length&&scored[0].score>0?scored[0].box:null;
  };

  var attachByNativeSearch11399=function(payload){
    var currentForm=passengerFormByKind(passengerCard,'saved');
    if(!currentForm)return Promise.reject(new Error('Saved Passenger search is unavailable.'));
    var search=nativeSearchControl11399(currentForm);
    if(!search)return Promise.reject(new Error('Saved Passenger search is unavailable.'));

    var query=plain(payload.passport_number||payload.name||'');
    search.value=query;
    var fd=new FormData(currentForm);
    fd.delete('passenger_ids[]');
    if(search.name)fd.set(search.name,query);
    appendSubmitter11399(fd,nativeSearchSubmitter11399(currentForm));

    var searchAction=currentForm.getAttribute('action')||currentForm.action||'';
    try{searchAction=new URL(searchAction,window.location.href).toString();}catch(e){}

    return fetch(searchAction,{
      method:'POST',body:fd,credentials:'same-origin',redirect:'follow',
      headers:{'X-Requested-With':'XMLHttpRequest','Accept':'text/html,application/xhtml+xml'}
    }).then(function(response){
      return response.text().then(function(text){
        var doc=parseNativeResponse11399(response,text);
        var freshForm=freshSavedForm113100(doc,currentForm);
        var match=bestNativeSavedCheckbox11399(freshForm,payload);
        if(!freshForm||!match)throw new Error('Saved passenger was found in Passenger Master but could not be selected automatically.');
        return submitNativeFetch11399(freshForm,'saved',match.value);
      });
    });
  };

  var updateMatchedMaster11398=function(payload){
    return fetch('/system/erp-bookings/'+bookingId+'/passengers/quick-master-update',{
      method:'PATCH',
      headers:{
        'Accept':'application/json',
        'Content-Type':'application/json',
        'X-Requested-With':'XMLHttpRequest',
        'X-CSRF-TOKEN':etgpCsrf11397()
      },
      credentials:'same-origin',
      body:JSON.stringify(payload)
    }).then(function(response){
      return response.json().catch(function(){return {};}).then(function(data){
        if(!response.ok){
          var message='Saved passenger details could not be updated.';
          if(data&&data.errors){
            var key=Object.keys(data.errors)[0];
            if(key&&data.errors[key]&&data.errors[key][0])message=data.errors[key][0];
          }else if(data&&data.message){
            message=data.message;
          }
          throw new Error(message);
        }
        return data;
      });
    });
  };

  var exactLookup11399=function(payload){
    var query=plain(payload.passport_number||payload.name||'');
    if(query.length<2)return Promise.resolve(null);
    return fetch('/system/erp-bookings/'+bookingId+'/passengers/quick-lookup?q='+encodeURIComponent(query),{
      method:'GET',credentials:'same-origin',
      headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
    }).then(function(response){
      if(!response.ok)return null;
      return response.json().then(function(data){
        var rows=Array.isArray(data.results)?data.results:[];
        var passportNeedle=etgpCompact11397(payload.passport_number||'');
        var nameNeedle=norm(payload.name||'');
        return rows.find(function(saved){
          if(passportNeedle&&etgpCompact11397(saved.passport_number||'')===passportNeedle)return true;
          return nameNeedle&&norm(saved.name||'')===nameNeedle;
        })||null;
      });
    }).catch(function(){return null;});
  };

  /* ERP-11.3.100: Passenger Master ids returned by the adaptive lookup are
     NOT assumed to be the ids accepted by the host ERP's native saved-passenger
     attach form. Production proved that a direct passenger_ids[] submission can
     return HTTP 200 while attaching nothing. Always resolve the authoritative
     native checkbox through the native Search Saved Passengers response, then
     submit the checkbox value that the host ERP itself rendered. */
  var addSaved113100=function(payload){
    var source=String(payload.master_source||'');
    var canUpdate=payload.master_id&&source&&source.toLowerCase().indexOf('booking_')!==0;
    var updater=canUpdate?updateMatchedMaster11398(payload):Promise.resolve({ok:true});
    return updater.then(function(){
      return attachByNativeSearch11399(payload);
    });
  };

  var currentPassengerRows113100=function(doc){
    if(!doc)return [];
    var table=doc.querySelector('.passenger-table');
    if(!table){
      var panel=doc.querySelector('[data-et-booking-panel-11375="passengers"]')||doc;
      var scored=Array.prototype.slice.call(panel.querySelectorAll('table')).map(function(candidate){
        var head=norm(candidate.querySelector('thead')&&candidate.querySelector('thead').textContent||'');
        var score=0;
        if(head.indexOf('passenger')!==-1||head.indexOf('name')!==-1)score+=15;
        if(head.indexOf('passport')!==-1)score+=10;
        if(head.indexOf('status')!==-1)score+=20;
        if(head.indexOf('action')!==-1)score+=25;
        if(head.indexOf('use')!==-1||head.indexOf('select')!==-1)score-=20;
        return {table:candidate,score:score};
      }).sort(function(a,b){return b.score-a.score;});
      table=scored.length&&scored[0].score>0?scored[0].table:null;
    }
    return table?Array.prototype.slice.call(table.querySelectorAll('tbody tr')):[];
  };

  /* ERP-11.3.103 — use the ERP-owned JSON quick-add endpoint as the single
     authoritative write path. PATCH is used first because that is the method
     confirmed by the live 11.3.102 route. The server route also accepts POST
     for compatibility with an already-loaded 11.3.102 browser asset. */
  var quickAddRequest113102=function(payload){
    return fetch('/system/erp-bookings/'+bookingId+'/passengers/quick-add',{
      method:'PATCH',
      headers:{
        'Accept':'application/json',
        'Content-Type':'application/json',
        'X-Requested-With':'XMLHttpRequest',
        'X-CSRF-TOKEN':etgpCsrf11397()
      },
      credentials:'same-origin',
      body:JSON.stringify(payload)
    }).then(function(response){
      return response.json().catch(function(){return {};}).then(function(data){
        if(!response.ok){
          var message='Passenger could not be added.';
          if(data&&data.errors){
            var key=Object.keys(data.errors)[0];
            if(key&&data.errors[key]&&data.errors[key][0])message=data.errors[key][0];
          }else if(data&&data.message){
            message=data.message;
          }
          throw new Error(message);
        }
        if(!data||data.ok!==true){
          throw new Error(data&&data.message?data.message:'Passenger could not be added.');
        }
        return data;
      });
    });
  };

  var fetchFreshBookingDoc113102=function(){
    return fetch(window.location.href,{
      method:'GET',
      credentials:'same-origin',
      headers:{
        'X-Requested-With':'XMLHttpRequest',
        'Accept':'text/html,application/xhtml+xml'
      },
      cache:'no-store'
    }).then(function(response){
      return response.text().then(function(text){
        if(!response.ok)throw new Error('Passenger was saved, but the booking view could not be refreshed.');
        return new DOMParser().parseFromString(text,'text/html');
      });
    });
  };

  var clearQuickRow113102=function(){
    suppress=true;
    name.control.value='';
    passport.control.value='';
    dob.control.value='';
    expiry.control.value='';
    national.control.value='';
    setSelectValue(fare.control,'ADULT');
    status.control.value='ACTIVE';
    selected={id:0,source:'',name:'',passport:''};
    suppress=false;
    hideSuggestions();
  };


  /* ERP-11.3.105 — successful quick-add must be visible immediately. The
     write endpoint already returned success, so render the returned booking
     snapshot into the visible passenger table at once. Then reconcile with
     the authoritative server-rendered booking view in the background. This
     removes the old requirement for a manual page refresh. */
  var quickPassengerMatchesRow113105=function(row,passenger){
    if(!row||!passenger)return false;
    var text=norm(row.textContent||'');
    var passportNeedle=etgpCompact11397(passenger.passport_number||'');
    if(passportNeedle&&etgpCompact11397(row.textContent||'').indexOf(passportNeedle)!==-1)return true;
    var nameNeedle=norm(passenger.name||'');
    return !!(nameNeedle&&text.indexOf(nameNeedle)!==-1);
  };

  var updatePassengerKpi113105=function(){
    var card=document.querySelector('.etgp-passenger-card');
    var count=passengerCount(card);
    var grid=document.querySelector('[data-etgp-kpis]');
    if(grid){
      Array.prototype.slice.call(grid.querySelectorAll('.etgp-kpi')).forEach(function(kpi){
        var label=norm(kpi.querySelector('.etgp-kpi-label')&&kpi.querySelector('.etgp-kpi-label').textContent||'');
        if(label!=='passengers')return;
        var value=kpi.querySelector('.etgp-kpi-value');
        if(value)value.textContent=String(count);
        var rows=card?Array.prototype.slice.call(card.querySelectorAll('.etgp-current-passenger-table tbody tr')):[];
        var totals={adult:0,child:0,infant:0};
        rows.forEach(function(row){
          var txt=norm(row.textContent||'');
          if(txt.indexOf('infant')!==-1)totals.infant++;
          else if(txt.indexOf('child')!==-1)totals.child++;
          else if(txt.indexOf('adult')!==-1)totals.adult++;
        });
        var note=kpi.querySelector('.etgp-kpi-note');
        if(note)note.textContent='Adult '+totals.adult+' · Child '+totals.child+' · Infant '+totals.infant;
      });
    }
    var root=document.querySelector('.etgp-step1');
    if(root&&card){
      etgpAirFlushVisibleDraft113126();
      etgpHotelFlushVisibleDraft113127();
      etgpTransportFlushVisibleDraft113139();
      etgpVisaFlushVisibleDraft113142();
      renderProducts(root,root.dataset.bookingReference,count);
    }
  };

  var appendQuickPassenger113105=function(data){
    var passenger=data&&data.passenger?data.passenger:null;
    if(!passenger||data.already_exists)return false;
    var table=passengerCard.querySelector('.etgp-current-passenger-table');
    if(!table)return false;
    var tbody=table.querySelector('tbody');
    if(!tbody)return false;
    var existing=Array.prototype.slice.call(tbody.querySelectorAll('tr')).some(function(row){
      return quickPassengerMatchesRow113105(row,passenger);
    });
    if(existing)return true;

    Array.prototype.slice.call(tbody.querySelectorAll('tr')).forEach(function(row){
      var text=norm(row.textContent||'');
      if(!text||text.indexOf('no passenger')!==-1||text.indexOf('no booking passenger')!==-1){
        row.remove();
      }
    });

    var headers=Array.prototype.slice.call(table.querySelectorAll('thead th')).map(function(th){
      return norm(th.textContent||'');
    });
    var row=document.createElement('tr');
    var rowNumber=tbody.querySelectorAll('tr').length+1;
    headers.forEach(function(header,index){
      var td=document.createElement('td');
      var value='—';
      if(header==='#'||header.indexOf('sr')===0||header.indexOf('no.')!==-1)value=String(rowNumber);
      else if(header.indexOf('name')!==-1)value=passenger.name||'—';
      else if(header.indexOf('fare')!==-1||header.indexOf('adult')!==-1||header.indexOf('type')!==-1)value=passenger.fare_type||'ADULT';
      else if(header.indexOf('passport expiry')!==-1||header.indexOf('expiry')!==-1)value=passenger.passport_expiry||'—';
      else if(header.indexOf('passport')!==-1)value=passenger.passport_number||'—';
      else if(header==='dob'||header.indexOf('birth')!==-1)value=passenger.dob||'—';
      else if(header.indexOf('national')!==-1)value=passenger.nationality||'—';
      else if(header.indexOf('status')!==-1)value=passenger.status||'ACTIVE';
      else if(header.indexOf('action')!==-1)value='—';
      td.textContent=String(value);
      row.appendChild(td);
    });
    if(!headers.length){
      [''+rowNumber,passenger.name||'—',passenger.fare_type||'ADULT',passenger.passport_number||'—',passenger.dob||'—',passenger.passport_expiry||'—',passenger.nationality||'—',passenger.status||'ACTIVE','—'].forEach(function(value){
        var td=document.createElement('td');td.textContent=value;row.appendChild(td);
      });
    }
    row.setAttribute('data-etgp-quick-passenger-pending-sync','1');
    tbody.appendChild(row);
    updatePassengerKpi113105();
    return true;
  };

  var freshDocHasPassenger113105=function(doc,data){
    var passenger=data&&data.passenger?data.passenger:null;
    if(!doc||!passenger)return false;
    return currentPassengerRows113100(doc).some(function(row){
      return quickPassengerMatchesRow113105(row,passenger);
    });
  };

  var reconcileQuickPassenger113105=function(data,attempt){
    var delays=[180,420,900,1600,2600];
    attempt=attempt||0;
    if(attempt>=delays.length)return;
    window.setTimeout(function(){
      fetchFreshBookingDoc113102().then(function(doc){
        if(!freshDocHasPassenger113105(doc,data)){
          reconcileQuickPassenger113105(data,attempt+1);
          return;
        }
        if(typeof window.etGeneralProgressiveStep1Sync11390==='function'){
          window.etGeneralProgressiveStep1Sync11390(doc,['metrics','passengers']);
        }
      }).catch(function(){
        reconcileQuickPassenger113105(data,attempt+1);
      });
    },delays[attempt]);
  };

  var finishAdd113102=function(doc,data){
    add.disabled=false;
    add.textContent='Add';
    if(doc&&typeof window.etGeneralProgressiveStep1Sync11390==='function'){
      window.etGeneralProgressiveStep1Sync11390(doc,['metrics','passengers']);
    }
    if(typeof window.etBookingLiveNotice103169==='function'){
      window.etBookingLiveNotice103169((data&&data.message)||'Passenger added.',false);
    }
    clearQuickRow113102();
    showFeedback((data&&data.already_exists)?'Passenger is already on this booking.':'Passenger added. Add the next passenger below.',false);
    try{name.control.focus();}catch(e){}
  };

  var failAdd113102=function(error){
    add.disabled=false;
    add.textContent='Add';
    showFeedback(error&&error.message?error.message:'Passenger could not be added.',true);
  };

  add.addEventListener('click',function(){
    var passengerName=plain(name.control.value||'');
    if(!passengerName){
      showFeedback('Enter passenger name.',true);
      name.control.focus();
      return;
    }

    var payload={
      name:passengerName,
      fare_type:String(fare.control.value||'ADULT').toUpperCase(),
      passport_number:plain(passport.control.value||''),
      dob:dob.control.value||null,
      passport_expiry:expiry.control.value||null,
      nationality:plain(national.control.value||''),
      master_id:selected.id||null,
      master_source:selected.source||null
    };

    add.disabled=true;
    add.textContent='Adding…';
    hideSuggestions();
    showFeedback('',false);

    /* Resolve a saved master at click time as well, so a fast Add click cannot
       race the autocomplete debounce. Regardless of whether a match exists,
       the final write goes through quick-add and never through native DOM. */
    var resolve=payload.master_id?Promise.resolve(null):exactLookup11399(payload);

    resolve.then(function(saved){
      if(saved){
        payload.master_id=Number(saved.id)||null;
        payload.master_source=String(saved.source||'');
      }

      var source=String(payload.master_source||'');
      var canUpdate=payload.master_id&&source&&source.toLowerCase().indexOf('booking_')!==0;
      var update=canUpdate?updateMatchedMaster11398(payload):Promise.resolve({ok:true});

      return update.then(function(){
        return quickAddRequest113102(payload);
      });
    }).then(function(data){
      /* Show the confirmed save immediately; do not make staff wait for a
         second server-rendered GET before the passenger becomes visible. */
      appendQuickPassenger113105(data);
      finishAdd113102(null,data);
      reconcileQuickPassenger113105(data,0);
    }).catch(failAdd113102);
  });

  editorHost.appendChild(panel);
  return panel;
};

/* ERP-11.3.100: no passenger mode tray. The quick-add row is the only visible editor. */
passengerActionTray=function(passengerCard){
  if(!passengerCard)return;
  Array.prototype.slice.call(
    passengerCard.querySelectorAll(':scope > .etgp-card-head .etgp-passenger-actions')
  ).forEach(function(node){node.remove();});
};

stabilizePassengerVisualShell=function(passengerCard){
  if(!passengerCard)return;

  Array.prototype.slice.call(
    passengerCard.querySelectorAll(':scope > .etgp-passenger-current-host,:scope > .etgp-passenger-editor-host')
  ).forEach(function(node){node.remove();});

  var source=passengerCard.querySelector('[data-et-booking-panel-11375="passengers"]');
  var currentTable=passengerCard.querySelector('.etgp-current-passenger-table');

  if(currentTable){
    var currentHost=create('div','etgp-passenger-current-host');
    var tableUnit=currentTable.closest('.table-responsive')||currentTable;
    currentHost.appendChild(tableUnit);
    var head=passengerCard.querySelector(':scope > .etgp-card-head');
    if(head&&head.nextSibling)passengerCard.insertBefore(currentHost,head.nextSibling);
    else passengerCard.appendChild(currentHost);
  }

  var editorHost=create('div','etgp-passenger-editor-host etgp-passenger-editor-host-11397');
  passengerCard.appendChild(editorHost);
  etgpBuildQuickPassenger11397(passengerCard,editorHost);

  if(source)source.classList.add('etgp-passenger-native-source-host');
  Array.prototype.slice.call(passengerCard.querySelectorAll(':scope > .etgp-passenger-extra')).forEach(function(extra){
    if(extra.closest('.etgp-passenger-current-host,.etgp-passenger-editor-host'))return;
    extra.classList.add('etgp-passenger-legacy-extra-hidden');
  });
};

markPassengerFormLayout=function(passengerCard){
  if(!passengerCard)return;
  organizeCurrentPassengerTable(passengerCard);
  markPassengerNativeChrome(passengerCard);
  stabilizePassengerVisualShell(passengerCard);
  passengerActionTray(passengerCard);
};

var build=function(){
  var content=document.querySelector(
    'section.content'
  );

  if(!content)return;

  if(
    content.querySelector(
      ':scope > .etgp-step1'
    )
  ){
    return;
  }

  var hero=heroCandidate(
    content
  );

  var metrics=content.querySelector(
    '[data-et-booking-panel-11375="metrics"]'
  );

  var header=content.querySelector(
    '[data-et-booking-panel-11375="booking-header"]'
  );

  var passengers=content.querySelector(
    '[data-et-booking-panel-11375="passengers"]'
  );

  if(!header||!passengers){
    return;
  }

  var reference=bookingReference(
    content
  );
  var status=bookingStatus(
    hero
  );

  var root=create(
    'div',
    'etgp-step1'
  );
  root.setAttribute(
    'data-et-general-progressive-step1',
    VERSION
  );
  root.dataset.bookingReference=
    reference;

  moveAlerts(
    content,
    root
  );

  /* Compact top header. */
  var toolbar=create(
    'section',
    'etgp-toolbar'
  );
  var toolbarLeft=create(
    'div',
    'etgp-toolbar-left'
  );

  toolbarLeft.appendChild(
    create(
      'div',
      'etgp-eyebrow',
      'GENERAL / MULTI-SERVICE'
    )
  );
  toolbarLeft.appendChild(
    create(
      'h1',
      'etgp-booking-reference',
      reference
    )
  );

  var customer=summaryValue(
    header,
    'Customer'
  );
  var branch=summaryValue(
    header,
    'Branch'
  );

  toolbarLeft.appendChild(
    create(
      'p',
      'etgp-toolbar-subtitle',
      [
        customer,
        'GENERAL',
        branch
      ].filter(Boolean).join(' · ')
    )
  );

  var toolbarActions=create(
    'div',
    'etgp-toolbar-actions'
  );
  toolbarActions.appendChild(
    create(
      'span',
      'etgp-status',
      status
    )
  );

  actionUnits(
    hero
  ).forEach(function(unit){
    toolbarActions.appendChild(
      unit
    );
  });

  toolbar.appendChild(
    toolbarLeft
  );
  toolbar.appendChild(
    toolbarActions
  );
  root.appendChild(
    toolbar
  );

  /* Step progress */
  var progress=create(
    'div',
    'etgp-progress'
  );

  [
    ['1','Booking Created'],
    ['2','Passengers'],
    ['3','Add Products']
  ].forEach(function(item){
    var step=create(
      'div',
      'etgp-progress-step'
    );
    step.setAttribute(
      'data-etgp-progress-step',
      item[0]
    );
    step.appendChild(
      create(
        'span',
        'etgp-progress-number',
        item[0]
      )
    );
    step.appendChild(
      create(
        'span',
        'etgp-progress-label',
        item[1]
      )
    );
    progress.appendChild(
      step
    );
  });

  root.appendChild(
    progress
  );

  /* Rebuild KPI presentation from authoritative native values. */
  if(metrics){
    root.appendChild(
      buildMetricGrid(
        metrics
      )
    );
    /* Do not allow the native Tickets card to flash a concatenated value
       (for example 31) while the Air product API is still loading. */
    etgpTicketKpiState113126.desired='—';
    etgpTicketKpiState113126.note='';
    etgpTicketKpiGuard113126();
  }

  /* Booking Header */
  cleanPanel(
    header
  );

  var headerCard=create(
    'section',
    'etgp-card etgp-booking-card'
  );
  headerCard.setAttribute(
    'data-et-booking-panel-shell',
    'booking-header'
  );

  var bookingHead=create(
    'div',
    'etgp-booking-head'
  );
  var bookingTitle=create(
    'div',
    'etgp-inline-title'
  );

  bookingTitle.appendChild(
    create(
      'h2',
      'etgp-card-title',
      'Booking Header'
    )
  );
  bookingTitle.appendChild(
    create(
      'span',
      'etgp-card-inline-note',
      'Customer, branch, agent, salesperson and travel context.'
    )
  );

  bookingHead.appendChild(
    bookingTitle
  );
  headerCard.appendChild(
    bookingHead
  );

  var summary=create(
    'div',
    'etgp-header-summary'
  );
  summary.setAttribute(
    'data-etgp-header-summary',
    '1'
  );
  headerCard.appendChild(
    summary
  );

  updateHeaderSummary(
    headerCard,
    header
  );

  /*
   * Preserve the actual native edit form in a hidden source panel. The editor
   * details itself has already been moved into the rebuilt card head.
   */
  header.setAttribute(
    'data-etgp-header-source',
    '1'
  );
  headerCard.appendChild(
    header
  );
  root.appendChild(
    headerCard
  );

  /* Passengers */
  cleanPanel(
    passengers
  );
  hideDuplicateHeading(
    passengers,
    'Passengers'
  );

  var passengerCard=create(
    'section',
    'etgp-card etgp-passenger-card'
  );
  passengerCard.setAttribute(
    'data-etgp-passenger-card',
    '1'
  );

  var passengerHead=create(
    'div',
    'etgp-card-head'
  );
  var passengerCopy=create(
    'div',
    'etgp-card-copy'
  );
  passengerCopy.appendChild(
    create(
      'h2',
      'etgp-card-title',
      'Passengers'
    )
  );
  passengerCopy.appendChild(
    create(
      'p',
      'etgp-card-note',
      'Add a passenger below. Saved Passenger Master records are suggested automatically by name or passport.'
    )
  );
  passengerHead.appendChild(
    passengerCopy
  );
  passengerCard.appendChild(
    passengerHead
  );
  passengerCard.appendChild(
    passengers
  );

  collectPassengerExtras(
    content,
    passengers
  ).forEach(function(extra){
    cleanPanel(extra);
    extra.classList.add(
      'etgp-passenger-extra'
    );
    passengerCard.appendChild(
      extra
    );
  });

  markPassengerFormLayout(
    passengerCard
  );

  root.appendChild(
    passengerCard
  );

  /* Add Product controls */
  var products=create(
    'section',
    'etgp-card etgp-products-card'
  );

  var productsHead=create(
    'div',
    'etgp-card-head'
  );
  var productsCopy=create(
    'div',
    'etgp-card-copy'
  );
  productsCopy.appendChild(
    create(
      'h2',
      'etgp-card-title',
      'Add Products'
    )
  );

  var selectedNote=create(
    'p',
    'etgp-card-note'
  );
  selectedNote.innerHTML=
    'Add only the products required for this booking. '
    +'<strong><span data-etgp-selected-count>0</span> selected.</strong>';
  productsCopy.appendChild(
    selectedNote
  );
  productsHead.appendChild(
    productsCopy
  );
  products.appendChild(
    productsHead
  );

  var lock=create(
    'div',
    'etgp-product-lock',
    'Add at least one passenger to unlock product selection.'
  );
  lock.setAttribute(
    'data-etgp-product-lock',
    '1'
  );
  products.appendChild(
    lock
  );

  var productButtons=create(
    'div',
    'etgp-product-buttons'
  );
  productButtons.setAttribute(
    'data-etgp-product-buttons',
    '1'
  );
  products.appendChild(
    productButtons
  );

  root.appendChild(
    products
  );

  var productShells=create(
    'div',
    'etgp-product-shells'
  );
  productShells.setAttribute(
    'data-etgp-product-shells',
    '1'
  );
  root.appendChild(
    productShells
  );

  content.appendChild(
    root
  );
  /* The controlled KPI grid is now in the live document. Start the Tickets
     single-writer guard before the Air API finishes loading. */
  etgpTicketKpiGuard113126();

  /*
   * Remove every old native GENERAL service/workflow block from the visible
   * Step-1 page. Only the moved Booking Header, Passenger and KPI sources stay.
   */
  Array.prototype.slice.call(
    content.children
  ).forEach(function(child){
    if(child!==root){
      child.remove();
    }
  });

  html.classList.remove(
    'et-booking-unified-canvas-11375'
  );
  html.classList.remove(
    'et-booking-type-general-11375'
  );
  html.classList.add(
    'etgp-step1-live-11390'
  );

  var paxCount=passengerCount(
    passengerCard
  );

  renderProducts(
    root,
    reference,
    paxCount
  );
  etgpBindPassengerFareAirSync113137();

  renderProgress(
    root,
    paxCount,
    loadSelected(reference).length
  );

  window.clearTimeout(
    nativeRevealFallback11390
  );
  html.classList.remove(
    'etgp-step1-fallback-11390'
  );
  html.classList.add(
    'etgp-step1-ready-11390'
  );
};

var refreshPassengerCard=function(
  freshPanel,
  freshDoc
){
  var card=document.querySelector(
    '.etgp-passenger-card'
  );
  var current=card
    ? card.querySelector(
        '[data-et-booking-panel-11375="passengers"]'
      )
    : null;

  if(!card||!current||!freshPanel){
    return;
  }

  Array.prototype.slice.call(
    card.querySelectorAll(':scope > .etgp-passenger-current-host,:scope > .etgp-passenger-editor-host')
  ).forEach(function(node){node.remove();});

  current.classList.remove('etgp-passenger-native-source-host');
  current.innerHTML=
    freshPanel.innerHTML;

  Array.prototype.slice.call(
    card.querySelectorAll(
      '.etgp-passenger-extra'
    )
  ).forEach(function(extra){
    extra.remove();
  });

  collectPassengerExtras(
    freshDoc.querySelector(
      'section.content'
    )||freshDoc.body,
    freshPanel
  ).forEach(function(extra){
    cleanPanel(extra);
    extra.classList.add(
      'etgp-passenger-extra'
    );
    card.appendChild(extra);
  });

  hideDuplicateHeading(
    current,
    'Passengers'
  );

  var oldTray=card.querySelector(
    ':scope > .etgp-card-head '
    +'.etgp-passenger-actions'
  );
  if(oldTray)oldTray.remove();

  markPassengerFormLayout(
    card
  );
};

var refreshMetrics=function(
  freshMetrics
){
  refreshMetricGrid(
    freshMetrics
  );
};

var refreshHeader=function(
  freshHeader
){
  var card=document.querySelector(
    '.etgp-booking-card'
  );

  if(!card||!freshHeader){
    return;
  }

  updateHeaderSummary(
    card,
    freshHeader
  );

  var source=card.querySelector(
    '[data-etgp-header-source]'
  );

  if(source){
    source.innerHTML=
      freshHeader.innerHTML;
  }
};


/* ERP-11.3.137 — authoritative Passenger Fare As -> Air sync.
 * ERP-11.3.136 observed the visible result and reloaded Air, but production
 * proved that a native Apply can leave the controlled Air API reading the old
 * booking-passenger fare field.  Resolve the stable booking passenger ID from
 * the already-rendered Air ticket row and persist the selected effective fare
 * through the ERP-owned booking-scoped endpoint before rebuilding Air. */
var etgpPassengerFareSignature113137=function(){
  var table=document.querySelector('.etgp-current-passenger-table');
  if(!table)return '';
  return Array.prototype.slice.call(table.querySelectorAll('tbody tr')).map(function(row){
    var visible=Array.prototype.slice.call(row.children).filter(function(cell){return !cell.classList.contains('etgp-passenger-column-hidden');});
    if(visible.length<3)return '';
    var name=norm(visible[1]&&visible[1].textContent||'');
    var fareCell=visible[2];
    var select=fareCell&&fareCell.querySelector('select');
    var fare=select?String(select.value||''):String(fareCell&&fareCell.textContent||'');
    fare=String(fare||'').toUpperCase();
    if(fare.indexOf('INF')!==-1)fare='INFANT';
    else if(fare.indexOf('CH')!==-1)fare='CHILD';
    else if(fare.indexOf('AD')!==-1)fare='ADULT';
    else fare='';
    return name+':'+fare;
  }).filter(Boolean).join('|');
};

var etgpNormalizeFare113137=function(value){
  var fare=String(value||'').toUpperCase();
  if(fare.indexOf('INF')!==-1)return 'INFANT';
  if(fare.indexOf('CH')!==-1)return 'CHILD';
  if(fare.indexOf('AD')!==-1)return 'ADULT';
  return '';
};

var etgpPassengerFareOverrides113137={};
var etgpApplyPassengerFareOverrides113137=function(data){
  data=data||{};
  var passengers=Array.isArray(data.passengers)?data.passengers:[];
  if(!passengers.length)return data;
  passengers.forEach(function(passenger){
    var id=Number(passenger&&passenger.id||0);
    var override=id>0?etgpPassengerFareOverrides113137[id]:'';
    if(override)passenger.fare_type=override;
  });
  return data;
};

var etgpVisiblePassengerRows113137=function(){
  var table=document.querySelector('.etgp-current-passenger-table');
  if(!table)return [];
  return Array.prototype.slice.call(table.querySelectorAll('tbody tr')).filter(function(row){
    var visible=Array.prototype.slice.call(row.children).filter(function(cell){return !cell.classList.contains('etgp-passenger-column-hidden');});
    return visible.length>=3&&norm(visible[1]&&visible[1].textContent||'')!=='';
  });
};

var etgpBookingPassengerIdForVisibleRow113137=function(row){
  if(!row)return 0;
  var direct=['bookingPassengerId','booking_passenger_id','passengerId','passenger_id'];
  for(var d=0;d<direct.length;d+=1){
    var raw=row.dataset&&row.dataset[direct[d]];
    if(Number(raw)>0)return Number(raw);
  }

  var form=row.querySelector('form');
  if(form){
    var hidden=Array.prototype.slice.call(form.querySelectorAll('input[type="hidden"]')).find(function(input){
      return /booking[_-]?passenger[_-]?id/i.test(String(input.name||input.id||''))&&Number(input.value)>0;
    });
    if(hidden)return Number(hidden.value)||0;
  }

  /* The controlled Air table already carries the stable booking passenger ID.
     Match by normalized name first; use the same ordinal only as a final bridge
     from native display markup to that stable ID. */
  var visible=Array.prototype.slice.call(row.children).filter(function(cell){return !cell.classList.contains('etgp-passenger-column-hidden');});
  var wantedName=norm(visible[1]&&visible[1].textContent||'');
  var airRows=Array.prototype.slice.call(document.querySelectorAll('[data-etgp-air-ticket-row-113106]'));
  var exact=airRows.filter(function(airRow){
    return norm(airRow.querySelector('.etgp-air-passenger-name-113106')&&airRow.querySelector('.etgp-air-passenger-name-113106').textContent||'')===wantedName;
  });
  if(exact.length===1)return Number(exact[0].getAttribute('data-etgp-air-ticket-row-113106'))||0;

  var rows=etgpVisiblePassengerRows113137();
  var index=rows.indexOf(row);
  if(index>=0&&airRows[index])return Number(airRows[index].getAttribute('data-etgp-air-ticket-row-113106'))||0;
  return 0;
};

var etgpForceAirProductRerender113137=function(){
  var root=document.querySelector('.etgp-step1');
  var passengerCard=document.querySelector('.etgp-passenger-card');
  if(!root||!passengerCard)return;
  var reference=root.dataset.bookingReference;
  var paxCount=passengerCount(passengerCard);
  etgpAirFlushVisibleDraft113126();
  etgpHotelFlushVisibleDraft113127();
  renderProducts(root,reference,paxCount);
};

var etgpPersistPassengerFare113137=function(row,fareType){
  var bookingId=etgpBookingId11397();
  var passengerId=etgpBookingPassengerIdForVisibleRow113137(row);
  fareType=etgpNormalizeFare113137(fareType);
  if(!bookingId||!passengerId||!fareType)return Promise.reject(new Error('Passenger fare synchronization could not resolve the booking passenger.'));

  return fetch('/system/erp-bookings/'+bookingId+'/passengers/fare-type',{
    method:'PATCH',
    credentials:'same-origin',
    keepalive:true,
    headers:{
      'Accept':'application/json',
      'Content-Type':'application/json',
      'X-Requested-With':'XMLHttpRequest',
      'X-CSRF-TOKEN':etgpCsrf11397()
    },
    body:JSON.stringify({booking_passenger_id:passengerId,fare_type:fareType})
  }).then(function(response){
    return response.json().catch(function(){return {};}).then(function(data){
      if(!response.ok||!data||data.ok!==true){
        throw new Error(etgpAirErrorMessage113106(data,'Passenger fare type could not be synchronized.'));
      }
      etgpPassengerFareOverrides113137[passengerId]=fareType;
      return data;
    });
  });
};

var etgpSchedulePassengerFareAirSync113137=(function(){
  var timer=null;
  var last='';
  var running=false;
  return function(forcePoll){
    clearTimeout(timer);
    timer=setTimeout(function(){
      var current=etgpPassengerFareSignature113137();
      if(!current)return;
      if(!last){last=current;return;}
      if(current===last){
        if(forcePoll===true)window.setTimeout(function(){etgpSchedulePassengerFareAirSync113137(false);},220);
        return;
      }
      last=current;
      if(running)return;
      running=true;
      etgpForceAirProductRerender113137();
      window.setTimeout(function(){running=false;},350);
    },80);
  };
})();

var etgpBindPassengerFareAirSync113137=function(){
  var card=document.querySelector('.etgp-passenger-card');
  if(!card||card.dataset.etgpFareAirSync113137==='1')return;
  card.dataset.etgpFareAirSync113137='1';
  etgpSchedulePassengerFareAirSync113137(false);
  var observer=new MutationObserver(function(){etgpSchedulePassengerFareAirSync113137(false);});
  observer.observe(card,{subtree:true,childList:true,characterData:true});

  card.addEventListener('click',function(event){
    var button=event.target&&event.target.closest?event.target.closest('button,input[type="submit"]'):null;
    if(!button||norm(button.textContent||button.value||'')!=='apply')return;
    var row=button.closest('tr');
    if(!row)return;
    var select=row.querySelector('select');
    var fare=etgpNormalizeFare113137(select&&select.value||'');
    if(!fare)return;

    /* Do not block or replace the native Apply action. Persist the same effective
       fare against the stable booking snapshot as a companion write, then reload
       Air from that authoritative state. keepalive also survives a native form
       navigation on installed bases that still submit Apply traditionally. */
    etgpPersistPassengerFare113137(row,fare).then(function(){
      window.setTimeout(etgpForceAirProductRerender113137,60);
      window.setTimeout(etgpForceAirProductRerender113137,450);
    }).catch(function(){
      // Native Apply remains authoritative if this companion bridge cannot run;
      // delayed GETs retain the ERP-11.3.136 fallback behavior.
      [450,1100].forEach(function(delay){window.setTimeout(etgpForceAirProductRerender113137,delay);});
    });
  },true);
};

window.etGeneralProgressiveStep1Sync11390=function(
  freshDoc,
  keys
){
  if(
    !document.documentElement.classList.contains(
      'etgp-step1-live-11390'
    )
  ){
    return false;
  }

  var wanted=keys||[];

  var freshMetrics=freshDoc.querySelector(
    '[data-et-booking-panel-11375="metrics"]'
  );
  var freshHeader=freshDoc.querySelector(
    '[data-et-booking-panel-11375="booking-header"]'
  );
  var freshPassengers=freshDoc.querySelector(
    '[data-et-booking-panel-11375="passengers"]'
  );

  if(
    wanted.indexOf('metrics')!==-1
  ){
    refreshMetrics(
      freshMetrics
    );
  }

  if(
    wanted.indexOf('booking-header')!==-1
  ){
    refreshHeader(
      freshHeader
    );
  }

  if(
    wanted.indexOf('passengers')!==-1
  ){
    refreshPassengerCard(
      freshPassengers,
      freshDoc
    );
  }

  etgpBindPassengerFareAirSync113137();

  var root=document.querySelector(
    '.etgp-step1'
  );
  var currentPassengerCard=document.querySelector(
    '.etgp-passenger-card'
  );

  if(root&&currentPassengerCard){
    var reference=root.dataset.bookingReference;
    var paxCount=passengerCount(
      currentPassengerCard
    );

    etgpAirFlushVisibleDraft113126();
    etgpHotelFlushVisibleDraft113127();
    renderProducts(
      root,
      reference,
      paxCount
    );
  }

  return true;
};

window.etGeneralProgressiveStep1Build11390=
  build;

if(document.readyState==='loading'){
  document.addEventListener(
    'DOMContentLoaded',
    build,
    {once:true}
  );
}else{
  build();
}

})();

/* ERP-11.3.129 — GENERAL Client Preview uses the Accommodation Voucher-style controlled voucher.
 * Native shells can rebuild their action row after same-page updates, so keep
 * the link normalized without replacing the surrounding toolbar DOM.
 */
(function etgpClientVoucherPreview113129(){
  'use strict';
  var bookingMatch=String(window.location.pathname||'').match(/^\/operations\/bookings\/(\d+)\/?$/i);
  if(!bookingMatch)return;
  var bookingId=bookingMatch[1];
  var target=window.location.origin+'/operations/bookings/'+bookingId+'/client-voucher-preview';
  var norm=function(v){return String(v||'').replace(/\s+/g,' ').trim().toLowerCase();};
  var bind=function(){
    Array.prototype.slice.call(document.querySelectorAll('a,button,[role="button"]')).forEach(function(control){
      var label=norm(control.textContent||control.value);
      if(label!=='client preview' && label!=='preview voucher' && label!=='client voucher')return;
      if(control.tagName==='A'){
        control.href=target;
        control.target='_blank';
        control.rel='noopener';
        control.setAttribute('data-etgp-client-voucher','ERP-11.3.129');
        return;
      }
      if(control.getAttribute('data-etgp-client-voucher')==='ERP-11.3.129')return;
      control.setAttribute('data-etgp-client-voucher','ERP-11.3.129');
      control.addEventListener('click',function(event){
        event.preventDefault();
        event.stopPropagation();
        window.open(target,'_blank','noopener');
      },true);
    });
  };
  bind();
  var observer=new MutationObserver(function(){bind();});
  observer.observe(document.documentElement,{childList:true,subtree:true});
})();
