(function(window,document){'use strict';var core=window.etDedicatedProductCore;if(!core)return;var create=core.create;var plain=core.plain;var norm=core.norm;
var airLifecycle={root:null,bookingId:0,saveInFlight:false,dirty:false,draftPending:false};
var resetAirLifecycle=function(root,bookingId){
  var draft=etgpAirDraftRead113119(bookingId);
  airLifecycle={root:root,bookingId:Number(bookingId||0),saveInFlight:false,dirty:!!draft,draftPending:!!draft};
};
var isActiveAirLifecycle=function(bookingId){return airLifecycle.root===core.getBookingRoot()&&Number(airLifecycle.bookingId)===Number(bookingId||0);};

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
  var head=create('div','etgp-air-subhead-113106 etgp-product-subsection-161');
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
      'X-CSRF-TOKEN':core.getCsrfToken()
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
  var merged=Object.assign({},data||{});
  /* A browser draft is recovery-only. It must never replace persisted
     passenger-ticket or commercial rows, because those native rows are the
     authority used by Sales Invoice passenger-link validation. */
  merged._etgpDraftAvailable113119=true;
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


var etgpAirDedicatedIntegration113314={getBookingId:function(){return core.getBookingId();},applyPassengerFareOverrides:function(data){return data;},updateMetrics:function(){},updateSummaryMetrics:function(){},refreshBookingState:function(){return Promise.resolve(null);},getLockState:function(){return core.getLockState();},applyLock:function(workspaceRoot){return core.applyReadOnly(workspaceRoot);},setResponse:function(data){return core.setProductResponse('air',core.getBookingId(),data);},markMounted:function(){return core.markMounted(core.getBookingRoot());},markFailed:function(workspaceHost,message){return core.markFailed(core.getBookingRoot(),message);}};
var etgpAirHostIntegration113314=function(){return etgpAirDedicatedIntegration113314;};
var etgpAirData113314={load:function(bookingId){var cached=core.getProductResponse('air',bookingId);if(cached)return Promise.resolve(cached);var pending=core.getProductPromise('air',bookingId);if(pending)return pending;pending=etgpAirLoad113106(bookingId).then(function(data){core.setProductResponse('air',bookingId,data);core.setPassengerData(Array.isArray(data.passengers)?data.passengers:[]);return data;}).catch(function(error){core.setProductPromise('air',bookingId,null);throw error;});core.setProductPromise('air',bookingId,pending);return pending;}};
var etgpAirDraft113314={apply:function(data,bookingId){return etgpAirApplyDraft113119(data,bookingId);},write:function(bookingId,payload){return etgpAirDraftWrite113119(bookingId,payload);},clear:function(bookingId){return etgpAirDraftClear113119(bookingId);}};
var etgpAirSave113314=function(bookingId,payload){return etgpAirRequest113106(bookingId,'PUT',payload).then(function(result){etgpAirDraft113314.clear(bookingId);return result;});};
var etgpAirRender113106=function(host,data,bookingId){
  var integration=etgpAirHostIntegration113314();
  data=etgpAirDraft113314.apply(data||{},bookingId);
  data=integration.applyPassengerFareOverrides(data);
  if(!data._etgpRenderGroupOnly)integration.setResponse(data);
  host.innerHTML='';
  host.classList.remove('is-loading');
  host.classList.remove('is-ready');

  var capabilities=data.capabilities||{};
  /* ERP-11.3.124 — the Air product API is authoritative for saved ticket count
     and saved customer sale. Do not leave the top KPI row dependent on the
     legacy native metric DOM, which can concatenate ticket/service counts or
     omit Booking Value after the controlled GENERAL workspace is rendered. */
  integration.updateMetrics(data,(data.booking&&data.booking.currency)||'PKR');
  if(capabilities.booking_services===false||capabilities.air_ticket_details===false||capabilities.booking_itinerary_segments===false){
    host.appendChild(create('div','etgp-air-feedback-113106 is-error','Required native Air Ticket stores are not available on this installation.'));
    return;
  }

  var passengers=Array.isArray(data.passengers)?data.passengers:[];
  /* ERP-11.3.324+: ticket_groups is authoritative when more than one native
     Air booking_service exists. The existing editor remains the first-group
     editor for legacy markup, while untouched groups are carried through the
     save payload so a stale single-group client can never collapse them. */
  var ticketGroups=Array.isArray(data.ticket_groups)?data.ticket_groups:[];
  var deletedGroupServiceIds=[];
  var firstGroup=ticketGroups[0]||null;
  if(firstGroup){
    if(firstGroup.common)data.common=firstGroup.common;
    if(Array.isArray(firstGroup.tickets))data.tickets=firstGroup.tickets;
    if(firstGroup.fare_commercials)data.fare_commercials=firstGroup.fare_commercials;
  }
  var renderGroupOnly=data._etgpRenderGroupOnly===true;
  var deletedGroupServiceIds=Array.isArray(data._etgpDeletedGroupServiceIds)?data._etgpDeletedGroupServiceIds:[];
  if(ticketGroups.length&&!renderGroupOnly){
    var groupsBlock=create('section','etgp-air-ticket-groups-113324');
    groupsBlock.setAttribute('data-etgp-air-ticket-groups','1');
    var groupsHead=etgpAirSubhead113106('Air Ticket Groups','Add one or more ticket groups (PNR / Vendor). Each group can be linked to one or more flight segments and has its own passenger tickets and commercials.');
    var addGroup=create('button','btn btn-outline-primary etgp-air-mini-button-113106','+ Add Ticket Group');addGroup.type='button';
    groupsHead.appendChild(addGroup);groupsBlock.appendChild(groupsHead);
    ticketGroups.forEach(function(group,index){
      var card=create('article','etgp-air-ticket-group-card-113324');card.setAttribute('data-etgp-air-ticket-group',String(group.client_key||('group-'+index)));card.setAttribute('data-etgp-air-group-editor','1');
      var title=create('h4','etgp-air-subtitle-113106','Ticket Group #'+(index+1));card.appendChild(title);
      var groupActions=create('div','etgp-air-ticket-group-actions-113324');
      var duplicateGroup=create('button','btn btn-outline-secondary etgp-air-mini-button-113106','Duplicate Group');duplicateGroup.type='button';
      duplicateGroup.addEventListener('click',function(){var copy=JSON.parse(JSON.stringify(group));copy.service_id=null;copy.client_key='group-'+Date.now();copy.segment_ids=[];copy.segment_keys=[];copy.common=Object.assign({},copy.common,{pnr:'',airline_pnr:'',issue_date:''});copy.tickets=(copy.tickets||[]).map(function(t){return Object.assign({},t,{ticket_number:''});});ticketGroups.push(copy);etgpAirDraft113314.write(bookingId,{ticket_groups:ticketGroups,segments:data.itinerary||[],deleted_group_service_ids:deletedGroupServiceIds.slice()});});
      var deleteGroup=create('button','btn btn-outline-danger etgp-air-mini-button-113106','Delete');deleteGroup.type='button';
      deleteGroup.addEventListener('click',function(){if(group.service_id&&deletedGroupServiceIds.indexOf(Number(group.service_id))===-1)deletedGroupServiceIds.push(Number(group.service_id));ticketGroups.splice(index,1);card.remove();etgpAirDraft113314.write(bookingId,{ticket_groups:ticketGroups,segments:data.itinerary||[],deleted_group_service_ids:deletedGroupServiceIds.slice()});});
      groupActions.appendChild(duplicateGroup);groupActions.appendChild(deleteGroup);card.appendChild(groupActions);
      var applies=create('div','etgp-air-group-segments-113324');var appliesLabel=create('strong','', 'Applies To Segments');applies.appendChild(appliesLabel);
      (data.itinerary||[]).forEach(function(segment){var key=String(segment.client_key||('segment-'+segment.id));var wrap=create('label','etgp-air-group-segment-option-113324');var check=create('input','');check.type='checkbox';check.value=key;check.checked=(group.segment_keys||[]).indexOf(key)!==-1;check.setAttribute('data-etgp-air-segment-owner',key);check.addEventListener('change',function(){var keys=Array.isArray(group.segment_keys)?group.segment_keys.slice():[];if(check.checked){var owners=groupsBlock.querySelectorAll('input[data-etgp-air-segment-owner="'+key+'"]');owners.forEach(function(other){if(other!==check)other.checked=false;});if(keys.indexOf(key)===-1)keys.push(key);}else{keys=keys.filter(function(item){return item!==key;});}group.segment_keys=keys;});wrap.appendChild(check);wrap.appendChild(create('span','',String(segment.from||'')+' → '+String(segment.to||'')));applies.appendChild(wrap);});
      card.appendChild(applies);
      var groupHost=create('div','etgp-air-ticket-group-editor-113324');card.appendChild(groupHost);groupsBlock.appendChild(card);
      var groupSegments=(data.itinerary||[]).filter(function(segment){var keys=Array.isArray(group.segment_keys)?group.segment_keys:[];return keys.indexOf(String(segment.client_key||('segment-'+segment.id)))!==-1||((group.segment_ids||[]).map(Number).indexOf(Number(segment.id))!==-1);});
      var groupData=Object.assign({},data,{ticket_groups:[group],itinerary:groupSegments,_etgpRenderGroupOnly:true,_etgpGroupIndex:index,_etgpAllGroups:ticketGroups,_etgpAllSegments:data.itinerary||[],_etgpDeletedGroupServiceIds:deletedGroupServiceIds});
      etgpAirRender113106(groupHost,groupData,bookingId);
    });
    addGroup.addEventListener('click',function(){var copy={service_id:null,client_key:'group-'+Date.now(),common:{},segment_ids:[],segment_keys:[],tickets:[],fare_commercials:{}};ticketGroups.push(copy);etgpAirDraft113314.write(bookingId,{ticket_groups:ticketGroups,segments:data.itinerary||[],deleted_group_service_ids:deletedGroupServiceIds.slice()});});
    host.appendChild(groupsBlock);
    return;
  }  var savedTickets=etgpAirTicketMap113106(Array.isArray(data.tickets)?data.tickets:[]);
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
  var passengerTicketsHeading=etgpAirSubhead113106('Passenger Tickets','Enter the first ticket number and the following blank ticket numbers auto-increment. Staff can edit any generated number. Passenger status follows the PNR Ticket Status.');passengerTicketsHeading.classList.add('etgp-product-subsection-161');ticketsBlock.appendChild(passengerTicketsHeading);
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
  var pnrCommercialHeading=etgpAirSubhead113106('PNR Fare Commercials','One row per fare type. Taxes = Cost Price - Basic Rate. Vendor Minus and Customer Minus are always calculated against Basic Rate.');pnrCommercialHeading.classList.add('etgp-product-subsection-161');commercial.appendChild(pnrCommercialHeading);

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
    var payload={
      common:{pnr:plain(commonPnr.input.value),airline_pnr:plain(airlinePnr.input.value),booking_source:plain(bookingSource.input.value),ticket_status:ticketStatus.select.value,issue_date:issueDate.input.value||null,supplier_id:supplierData.supplier_id,supplier_name:supplierData.supplier_name},
      segments:segments,tickets:tickets,fare_commercials:farePayload
    };
    if(ticketGroups.length>1||renderGroupOnly){
      var sourceGroups=renderGroupOnly&&Array.isArray(data._etgpAllGroups)?data._etgpAllGroups:ticketGroups;
      var preserved=sourceGroups.map(function(group){return Object.assign({},group,{segment_ids:Array.isArray(group.segment_ids)?group.segment_ids.slice():[],segment_keys:Array.isArray(group.segment_keys)?group.segment_keys.slice():[],tickets:Array.isArray(group.tickets)?group.tickets.slice():[],fare_commercials:group.fare_commercials||{}});});
      var currentIndex=renderGroupOnly?Number(data._etgpGroupIndex||0):0;
      preserved[currentIndex]=Object.assign({},preserved[currentIndex]||{service_id:null,client_key:'group-1'},payload);
      payload.segments=renderGroupOnly&&Array.isArray(data._etgpAllSegments)?data._etgpAllSegments:segments;
      payload.ticket_groups=preserved;
    }
    payload.deleted_group_service_ids=deletedGroupServiceIds.slice();
    return payload;
  };

  host._etgpBuildPayload113126=buildPayload113119;

  var draftTimer113119=null;
  var persistDraft113119=function(){
    if(isActiveAirLifecycle(bookingId)){airLifecycle.dirty=true;airLifecycle.draftPending=true;}
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
      etgpAirDraft113314.write(bookingId,payload);

    save.disabled=true;save.textContent='Saving…';if(isActiveAirLifecycle(bookingId))airLifecycle.saveInFlight=true;
    etgpAirSave113314(bookingId,payload).then(function(result){
      feedback.hidden=false;feedback.className='etgp-air-feedback-113106 is-success';feedback.textContent=result.message||'Tickets / Flight Data saved.';
      var summaryResult=result.summary||{};
      integration.updateSummaryMetrics(summaryResult,currency);
      if(result.common&&Number(result.common.supplier_id||0)>0&&suppliers.length)supplierControl.select.value=String(result.common.supplier_id);
      if(isActiveAirLifecycle(bookingId)){airLifecycle.dirty=false;airLifecycle.draftPending=false;}integration.refreshBookingState(bookingId);
    }).catch(function(error){
      feedback.hidden=false;feedback.classList.add('is-error');feedback.textContent=error&&error.message?error.message:'Tickets / Flight Data could not be saved.';
    }).finally(function(){if(isActiveAirLifecycle(bookingId))airLifecycle.saveInFlight=false;save.disabled=false;save.textContent='Save Tickets / Flight Data';});
  });
  requestAnimationFrame(function(){host.classList.add('is-ready');var lock=integration.getLockState();if(lock&&lock.locked)integration.applyLock(host,lock);integration.markMounted(host);});
};

var renderAirProductWorkspace113106=function(shell){
  var integration=etgpAirHostIntegration113314();
  var bookingId=integration.getBookingId();
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
  etgpAirData113314.load(bookingId).then(function(data){etgpAirRender113106(host,data,bookingId);}).catch(function(error){
    var failedRoot=core.getBookingRoot();if(failedRoot)failedRoot.removeAttribute('data-etgp-air-mounted');
    integration.markFailed(host,error&&error.message?error.message:'Tickets / Flight Data could not be loaded.');
    host.classList.remove('is-loading');host.appendChild(create('div','etgp-air-feedback-113106 is-error',error&&error.message?error.message:'Tickets / Flight Data could not be loaded.'));
  });
};


var mountAir=function(candidate){
  var dedicatedRoot=candidate&&candidate.querySelector?candidate:core.getBookingRoot&&core.getBookingRoot();
  if(!dedicatedRoot||String(dedicatedRoot.getAttribute('data-etgp-product-key')||'').toLowerCase()!=='air')return false;
  if(dedicatedRoot.getAttribute('data-etgp-air-mounted')==='1')return false;
  if(airLifecycle.saveInFlight)return false;
  var dedicatedHost=dedicatedRoot.querySelector('[data-etgp-dedicated-product-body]');
  if(!dedicatedHost)return false;
  core.setActiveRoot&&core.setActiveRoot(dedicatedRoot);
  resetAirLifecycle(dedicatedRoot,core.getBookingId());
  dedicatedRoot.setAttribute('data-etgp-air-mounted','1');
  renderAirProductWorkspace113106(dedicatedHost);
  return true;
};
window.etDedicatedAirProduct={mount:mountAir,getState:function(){return {saveInFlight:airLifecycle.saveInFlight,dirty:airLifecycle.dirty,draftPending:airLifecycle.draftPending,bookingId:airLifecycle.bookingId};}};
/* Keep the established initial page-load behavior while allowing C2 to mount a replacement root. */
var initialRoot=core.getBookingRoot&&core.getBookingRoot();
if(initialRoot&&String(initialRoot.getAttribute('data-etgp-product-key')||'').toLowerCase()==='air')mountAir(initialRoot);
})(window,document);
