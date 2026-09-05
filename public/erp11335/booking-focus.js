(function(){
'use strict';

var html=document.documentElement;
if(!html.classList.contains('et-booking-focus-prepaint'))return;

document.body.classList.add('et-booking-focus-mode');

/*
 * Mark the first practical booking content root. CSS does not depend on this
 * marker, but it gives all focused booking products one stable hook for future
 * UI work and avoids route-specific DOM guessing.
 */
var focusRoot=document.querySelector(
  '#gp-booking,.et-air-workspace-103172,[data-booking-workspace],'
  +'.page-wrapper > .page-body > .container-xl,'
  +'.page-wrapper > .page-body > .container,'
  +'main > .container-xl,main > .container'
);
if(focusRoot)focusRoot.setAttribute('data-et-booking-focus-canvas','ERP-11.3.55');

var norm=function(v){return String(v||'').replace(/\s+/g,' ').trim().toLowerCase();};
var visible=function(el){
  if(!el)return false;
  try{
    var s=window.getComputedStyle(el),r=el.getBoundingClientRect();
    return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0;
  }catch(e){return false;}
};

var candidates=Array.prototype.slice.call(document.querySelectorAll(
  '.sidebar,aside.sidebar,.navbar-vertical,.side-nav,[class*="sidebar"],[class*="navbar-vertical"]'
));

var sidebar=candidates.find(function(el){
  var t=norm(el.textContent);
  return t.indexOf('dashboard')!==-1 && (
    t.indexOf('easy ticket')!==-1 ||
    t.indexOf('administration')!==-1 ||
    t.indexOf('master data')!==-1
  );
}) || null;

if(sidebar){
  sidebar.setAttribute('data-et-booking-focus-sidebar','1');
  sidebar.setAttribute(
    'data-et-booking-focus-width',
    Math.max(210,Math.round(sidebar.getBoundingClientRect().width||240))+'px'
  );
  sidebar.style.setProperty('display','none','important');
}

var existingMenu=document.querySelector('#gp-focus-menu');
if(existingMenu){
  /*
   * Unified Group Umrah still owns its drawer behavior.
   * Air now uses this shared Menu exactly like native bookings.
   */
  return;
}

if(!sidebar)return;

var overlay=document.createElement('div');
overlay.className='et-booking-focus-overlay';
overlay.setAttribute('aria-hidden','true');
document.body.appendChild(overlay);

var menu=null;
var menuIsOpen=function(){
  return sidebar.classList.contains('et-booking-focus-sidebar-open');
};

var closeMenu=function(){
  sidebar.classList.remove('et-booking-focus-sidebar-open');
  sidebar.style.setProperty('display','none','important');
  overlay.classList.remove('open');
  overlay.setAttribute('aria-hidden','true');
  document.body.classList.remove('et-booking-focus-menu-open');
  if(menu)menu.setAttribute('aria-expanded','false');
};

var openMenu=function(){
  sidebar.style.width=sidebar.getAttribute('data-et-booking-focus-width')||'240px';
  sidebar.style.setProperty('display','block','important');
  sidebar.classList.add('et-booking-focus-sidebar-open');
  overlay.classList.add('open');
  overlay.setAttribute('aria-hidden','false');
  document.body.classList.add('et-booking-focus-menu-open');
  if(menu)menu.setAttribute('aria-expanded','true');
};

var toggleMenu=function(event){
  if(event){
    event.preventDefault();
    event.stopPropagation();
  }
  if(menuIsOpen())closeMenu();
  else openMenu();
};

var toolbar=document.createElement('div');
toolbar.className='et-booking-focus-fallback';
toolbar.setAttribute('data-et-booking-focus-fallback','ERP-11.3.55');

menu=document.createElement('button');
menu.type='button';
menu.className='et-booking-focus-btn';
menu.textContent='☰ Menu';
menu.setAttribute('aria-expanded','false');
menu.setAttribute('aria-label','Open booking menu');
menu.addEventListener('click',toggleMenu);
toolbar.appendChild(menu);

var registerLink=Array.prototype.slice.call(sidebar.querySelectorAll('a[href]')).find(function(a){
  var t=norm(a.textContent);
  return t==='bookings'||t==='booking register'||t.indexOf('bookings ')===0;
});

/*
 * ERP-11.3.48:
 * The main Booking Workspace already has its own Booking Register action beside
 * Draft / Client Preview. Reuse that action row and inject ONLY Menu there.
 * This avoids a duplicate Booking Register button and removes the standalone
 * toolbar above the booking header.
 */
var pageRegisterLink=Array.prototype.slice.call(
  document.querySelectorAll('a[href],button,[role="button"]')
).find(function(a){
  if(sidebar && sidebar.contains(a))return false;
  var t=norm(a.textContent);
  return t==='booking register'||t==='back to booking register';
});

var reviewHeaderActions=document.querySelector('[data-et-booking-review-header-actions="1"]');
if(reviewHeaderActions){
  /* Move the existing shared Menu and native Booking Register destination into
   * the Review title row; do not recreate menu contents or route authority. */
  toolbar.classList.add('et-booking-focus-fallback-inline');
  var reviewRegister=document.createElement('a');
  reviewRegister.className='et-booking-focus-btn';
  reviewRegister.textContent='Booking Register';
  reviewRegister.href=registerLink&&registerLink.href
    ? registerLink.href
    : (window.location.origin+'/operations/bookings');
  toolbar.appendChild(reviewRegister);
  reviewHeaderActions.appendChild(toolbar);
}else if(pageRegisterLink && pageRegisterLink.parentNode){
  /*
   * Main native Booking Workspace: there is already a Booking Register action.
   * Reuse its action row and add Menu only. This guarantees the short-menu
   * treatment instead of creating a second toolbar.
   */
  toolbar.classList.add('et-booking-focus-fallback-inline');
  pageRegisterLink.parentNode.insertBefore(toolbar,pageRegisterLink);
}else{
  var back=document.createElement('a');
  back.className='et-booking-focus-btn';
  back.textContent='Booking Register';
  back.href=registerLink&&registerLink.href
    ? registerLink.href
    : (window.location.origin+'/operations/bookings');
  toolbar.appendChild(back);

  /*
   * Native multi-step booking pages expose a "Booking Workspace" action in
   * their page-heading row. Put Menu + Booking Register beside it.
   */
  var workspaceLink=Array.prototype.slice.call(
    document.querySelectorAll('a[href],button,[role="button"]')
  ).find(function(a){
    if(sidebar && sidebar.contains(a))return false;
    var t=norm(a.textContent);
    return t==='booking workspace'
      || t==='← booking workspace'
      || t.indexOf('booking workspace')!==-1;
  });

  if(workspaceLink && workspaceLink.parentNode){
    var actions=document.createElement('div');
    actions.className='et-booking-focus-page-actions';
    workspaceLink.parentNode.replaceChild(actions,workspaceLink);
    actions.appendChild(toolbar);
    actions.appendChild(workspaceLink);
  }else{
    var target=document.querySelector(
      '#gp-booking,.et-air-workspace-103172,[data-booking-workspace],main,.main-content,.page-content'
    );

    toolbar.classList.add('et-booking-focus-fallback-standalone');

    if(target){
      target.insertBefore(toolbar,target.firstChild);
    }else{
      document.body.insertBefore(toolbar,document.body.firstChild);
    }
  }
}


/*
 * ERP-11.3.55 — nested Group Package shell alignment.
 *
 * Route:
 *   /operations/bookings/{id}/group-package
 *
 * The native page used a separate product shell:
 *   Group Travel Package
 *   Step 3 · Create Group Package
 *
 * Standard Umrah/Air/native booking pages use:
 *   [native top header] BK-...
 *   BOOKING WORKSPACE · ERP-10.20
 *   BK-...
 *   Customer · Booking Type · Head Office
 *
 * This function changes only visible shell text/geometry. Package form fields,
 * route, values, submit actions and workflow logic remain untouched.
 */
var normalizeNestedGroupPackageShell=function(){
  var path=String(window.location.pathname||'').replace(/\/+$/,'');
  if(!/^\/operations\/bookings\/\d+\/group-package$/i.test(path)){
    return false;
  }

  if(document.documentElement.hasAttribute('data-et-group-package-shell')){
    return true;
  }

  var text=function(el){
    return String(el&&el.textContent||'').replace(/\s+/g,' ').trim();
  };
  var low=function(el){return text(el).toLowerCase();};

  var all=Array.prototype.slice.call(document.querySelectorAll(
    'h1,h2,h3,h4,h5,h6,div,span,p,small,strong,b'
  ));

  /*
   * Source metadata line on the native page:
   *   BK-2026-000046 · Kashif Waqeel Sb · UMRAH
   */
  var metaEl=all
    .filter(function(el){
      var t=text(el);
      return /^BK-\d{4}-\d{4,}\s*[·|]/i.test(t);
    })
    .sort(function(a,b){
      return text(a).length-text(b).length;
    })[0] || null;

  if(!metaEl)return false;

  var raw=text(metaEl);
  var parts=raw.split(/\s*[·|]\s*/).filter(Boolean);
  var bookingRef=(parts[0]||'').trim();
  var customer=(parts[1]||'').trim();
  var bookingType=(parts[2]||'').trim();

  if(!/^BK-\d{4}-\d{4,}$/i.test(bookingRef))return false;

  /*
   * Native top header:
   *   Group Travel Package
   *   Easy Group Of Travels · Head Office
   *
   * Replace only the left title, keeping company/branch and user controls.
   */
  var topTitle=all
    .filter(function(el){
      return low(el)==='group travel package';
    })
    .sort(function(a,b){
      return text(a).length-text(b).length;
    })[0] || null;

  if(topTitle){
    topTitle.textContent=bookingRef;
    topTitle.setAttribute(
      'data-et-group-package-top-title',
      'ERP-11.3.55'
    );
  }

  /*
   * Main page heading:
   *   Step 3 · Create Group Package
   *
   * Convert it to the same Booking Workspace hero used by the main booking.
   */
  var stepTitle=all
    .filter(function(el){
      var t=low(el);
      return t==='step 3 · create group package'
        || t==='step 3 - create group package'
        || t.indexOf('step 3')===0 && t.indexOf('create group package')!==-1;
    })
    .sort(function(a,b){
      return text(a).length-text(b).length;
    })[0] || null;

  if(!stepTitle)return false;

  var hero=stepTitle.parentElement;
  if(!hero)return false;

  /*
   * Add Booking Workspace kicker directly above the booking number.
   */
  if(!hero.querySelector('[data-et-group-package-kicker]')){
    var kicker=document.createElement('div');
    kicker.setAttribute(
      'data-et-group-package-kicker',
      'ERP-11.3.55'
    );
    kicker.textContent='BOOKING WORKSPACE · ERP-10.20';
    hero.insertBefore(kicker,stepTitle);
  }

  stepTitle.textContent=bookingRef;
  stepTitle.setAttribute(
    'data-et-group-package-booking-title',
    'ERP-11.3.55'
  );

  /*
   * Reuse the existing metadata line as the standard booking subtitle.
   */
  var subtitle=metaEl;
  var subtitleParts=[];
  if(customer)subtitleParts.push(customer);
  if(bookingType)subtitleParts.push(bookingType);
  subtitleParts.push('Head Office');
  subtitle.textContent=subtitleParts.join(' · ');
  subtitle.setAttribute(
    'data-et-group-package-booking-subtitle',
    'ERP-11.3.55'
  );

  hero.setAttribute(
    'data-et-group-package-hero',
    'ERP-11.3.55'
  );
  document.documentElement.setAttribute(
    'data-et-group-package-shell',
    'ERP-11.3.55'
  );

  return true;
};

normalizeNestedGroupPackageShell();
requestAnimationFrame(normalizeNestedGroupPackageShell);


/*
 * ERP-11.3.75:
 * Legacy ERP-11.3.54 Transport / Other Services DOM surgery removed.
 * The unified semantic booking core at the end of this asset owns these panels
 * for AIR/GENERAL/VISA/HOTEL/native product workspaces.
 */
overlay.addEventListener('click',function(event){
  event.preventDefault();
  closeMenu();
});
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'&&menuIsOpen())closeMenu();
});
window.addEventListener('pageshow',function(){
  closeMenu();
});
})();


/* ========================================================================
 * ERP-11.3.69 — BOOKING LIVE ENTRY FOUNDATION
 *
 * Keep Passenger creation / saved-passenger attachment inside the current
 * Booking Workspace. Native Laravel routes, validation and permissions remain
 * authoritative; only browser transport changes from full navigation to fetch.
 * ======================================================================== */
(function(){
'use strict';

if(
  !document.documentElement.classList.contains(
    'et-booking-focus-prepaint'
  )
){
  return;
}

var norm103169=function(value){
  return String(value||'')
    .replace(/\s+/g,' ')
    .trim()
    .toLowerCase();
};

window.etBookingLiveNotice103169=function(
  message,
  isError
){
  document.querySelectorAll(
    '.et-booking-live-notice-103169'
  ).forEach(function(node){
    node.remove();
  });

  var notice=document.createElement('div');
  notice.className=
    'et-booking-live-notice-103169'
    +(isError?' is-error':'');
  notice.textContent=String(message||'Saved.');
  document.body.appendChild(notice);

  window.setTimeout(function(){
    notice.remove();
  },3200);
};

var parseHtml103169=function(text){
  try{
    return new DOMParser().parseFromString(
      String(text||''),
      'text/html'
    );
  }catch(e){
    return null;
  }
};

var freshFormByAction103169=function(
  doc,
  action
){
  var wanted='';

  try{
    wanted=new URL(
      action,
      window.location.href
    ).pathname;
  }catch(e){
    wanted=String(action||'');
  }

  return Array.prototype.slice.call(
    doc.querySelectorAll(
      'form[action]'
    )
  ).find(function(form){
    try{
      return new URL(
        form.action,
        window.location.href
      ).pathname===wanted;
    }catch(e){
      return String(form.action||'')===String(action||'');
    }
  }) || null;
};

var submitHtmlForm103169=async function(
  form,
  forceAllPassengers
){
  var fd=new FormData(form);

  if(forceAllPassengers){
    fd.delete('passenger_ids[]');

    Array.prototype.slice.call(
      form.querySelectorAll(
        'input[name="passenger_ids[]"]'
      )
    ).forEach(function(input){
      fd.append(
        'passenger_ids[]',
        input.value
      );
    });
  }

  return fetch(
    form.action,
    {
      method:'POST',
      body:fd,
      credentials:'same-origin',
      redirect:'follow',
      headers:{
        'X-Requested-With':'XMLHttpRequest',
        'Accept':'text/html,application/xhtml+xml'
      }
    }
  );
};

var replacePassengerUi103169=function(doc){
  var currentBody=document.querySelector(
    '.passenger-table tbody'
  );

  var freshBody=doc.querySelector(
    '.passenger-table tbody'
  );

  if(currentBody&&freshBody){
    currentBody.innerHTML=
      freshBody.innerHTML;
  }

  var currentLabel=document.querySelector(
    '.passenger-current-label'
  );

  var freshLabel=doc.querySelector(
    '.passenger-current-label'
  );

  if(currentLabel&&freshLabel){
    currentLabel.innerHTML=
      freshLabel.innerHTML;
  }

  /*
   * Keep all service passenger pickers aware of newly-added Booking
   * Passengers without refreshing the page.
   */
  Array.prototype.slice.call(
    document.querySelectorAll(
      'form[action*="/services/"]'
    )
  ).forEach(function(currentForm){
    var freshForm=freshFormByAction103169(
      doc,
      currentForm.action
    );

    if(!freshForm)return;

    var currentPicker=currentForm.querySelector(
      '.service-create-passengers'
    );

    var freshPicker=freshForm.querySelector(
      '.service-create-passengers'
    );

    if(currentPicker&&freshPicker){
      currentPicker.innerHTML=
        freshPicker.innerHTML;
    }
  });

  var currentTicketRows=document.querySelector(
    '[data-ticket-number-rows]'
  );

  var freshTicketRows=doc.querySelector(
    '[data-ticket-number-rows]'
  );

  if(
    currentTicketRows
    && freshTicketRows
  ){
    currentTicketRows.innerHTML=
      freshTicketRows.innerHTML;
  }

  if(
    typeof window.etAirBookingLiveRebind103169==='function'
  ){
    window.etAirBookingLiveRebind103169();
  }
};

var autoExtendAirOnlyLinks103169=async function(
  beforeState,
  doc
){
  if(!beforeState.length){
    return doc;
  }

  var latestDoc=doc;

  for(
    var index=0;
    index<beforeState.length;
    index++
  ){
    var state=beforeState[index];

    if(!state.allLinked){
      continue;
    }

    var freshForm=freshFormByAction103169(
      latestDoc,
      state.action
    );

    if(
      !freshForm
      || !freshForm.closest(
        '.air-service-shell'
      )
    ){
      continue;
    }

    var freshInputs=Array.prototype.slice.call(
      freshForm.querySelectorAll(
        'input[name="passenger_ids[]"]'
      )
    );

    if(
      freshInputs.length<=state.count
    ){
      continue;
    }

    try{
      var response=await submitHtmlForm103169(
        freshForm,
        true
      );

      var text=await response.text();
      var nextDoc=parseHtml103169(
        text
      );

      if(
        response.ok
        && nextDoc
      ){
        latestDoc=nextDoc;
      }
    }catch(e){
      /*
       * Passenger itself is already safely saved. If service auto-extension
       * fails, staff can still use Manage service passenger links.
       */
    }
  }

  return latestDoc;
};

var passengerMutationState103169=function(){
  return Array.prototype.slice.call(
    document.querySelectorAll(
      '.air-service-shell form[action*="/services/"]'
    )
  ).map(function(form){
    var inputs=Array.prototype.slice.call(
      form.querySelectorAll(
        'input[name="passenger_ids[]"]'
      )
    );

    return {
      action:form.action,
      count:inputs.length,
      allLinked:
        inputs.length>0
        && inputs.every(function(input){
          return input.checked;
        })
    };
  });
};

var passengerFormKind103169=function(form){
  var path='';

  try{
    path=new URL(
      form.action,
      window.location.href
    ).pathname;
  }catch(e){
    path=String(form.action||'');
  }

  if(
    /\/operations\/bookings\/\d+\/passengers$/i.test(path)
  ){
    return 'new';
  }

  if(
    /\/operations\/bookings\/\d+\/passengers\/from-profile$/i.test(path)
  ){
    return 'saved';
  }

  return '';
};

document.addEventListener(
  'submit',
  async function(event){
    if(
      document.documentElement.dataset.etBookingLiveCore==='ERP-11.3.75'
    ){
      return;
    }

    var form=event.target;

    if(
      !(form instanceof HTMLFormElement)
      || form.dataset.etLiveSubmitting==='1'
    ){
      return;
    }

    var kind=passengerFormKind103169(
      form
    );

    var isAirLinkForm=
      !!form.closest('.air-service-shell')
      && !!form.querySelector(
        '.service-create-passengers'
      )
      && /\/operations\/bookings\/\d+\/services\/\d+$/i.test(
        (function(){
          try{
            return new URL(
              form.action,
              window.location.href
            ).pathname;
          }catch(e){
            return '';
          }
        })()
      );

    if(
      !kind
      && !isAirLinkForm
    ){
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    form.dataset.etLiveSubmitting='1';

    var submitter=event.submitter;
    var originalText=submitter
      ? String(
          submitter.textContent
          || submitter.value
          || ''
        )
      : '';

    if(submitter){
      submitter.disabled=true;

      if(submitter.tagName==='INPUT'){
        submitter.value='Saving…';
      }else{
        submitter.textContent='Saving…';
      }
    }

    var beforeAirLinks=
      kind
        ? passengerMutationState103169()
        : [];

    try{
      var response=await submitHtmlForm103169(
        form,
        false
      );

      var text=await response.text();
      var doc=parseHtml103169(
        text
      );

      if(
        !response.ok
        || !doc
      ){
        throw new Error(
          'Booking data could not be saved.'
        );
      }

      var errorNode=doc.querySelector(
        '.alert-danger,.field-error,[role="alert"]'
      );

      if(
        errorNode
        && (
          norm103169(errorNode.textContent)
            .indexOf('please correct')!==-1
          || norm103169(errorNode.textContent)
            .indexOf('error')!==-1
        )
      ){
        throw new Error(
          String(
            errorNode.textContent
            || 'Please correct the highlighted fields.'
          )
            .replace(/\s+/g,' ')
            .trim()
        );
      }

      if(kind){
        doc=await autoExtendAirOnlyLinks103169(
          beforeAirLinks,
          doc
        );

        var stepOnePassengerSynced=false;
        if(
          document.documentElement.classList.contains('etgp-step1-live-11390')
          && typeof window.etGeneralProgressiveStep1Sync11390==='function'
        ){
          stepOnePassengerSynced=!!window.etGeneralProgressiveStep1Sync11390(
            doc,
            ['metrics','passengers']
          );
        }

        if(!stepOnePassengerSynced){
          replacePassengerUi103169(
            doc
          );
        }

        if(kind==='new'){
          form.reset();
        }

        var savedSearch=(
          kind==='saved'
          && norm103169(originalText)==='search'
        );

        window.etBookingLiveNotice103169(
          kind==='new'
            ? 'Passenger saved and added without reloading the booking.'
            : (
                savedSearch
                  ? 'Saved travellers loaded.'
                  : 'Selected passenger(s) added without reloading the booking.'
              ),
          false
        );
      }else{
        replacePassengerUi103169(
          doc
        );

        window.etBookingLiveNotice103169(
          'Air Ticket passenger links saved.',
          false
        );
      }
    }catch(error){
      window.etBookingLiveNotice103169(
        error&&error.message
          ? error.message
          : 'Booking data could not be saved.',
        true
      );
    }finally{
      form.dataset.etLiveSubmitting='0';

      if(submitter){
        submitter.disabled=false;

        if(submitter.tagName==='INPUT'){
          submitter.value=originalText;
        }else{
          submitter.textContent=originalText;
        }
      }
    }
  },
  true
);

})();


/* ========================================================================
 * ERP-11.3.75 — UNIFIED BOOKING PRODUCT UI + SAME-PAGE SAVE CORE
 *
 * Applies to AIR ONLY, GENERAL, VISA ONLY, HOTEL/other native product workspaces
 * and nested native Booking steps rendered in the focused shell.
 *
 * Workflow state actions (Submit/Approve/Confirm/Post), voucher generation and
 * Sales Invoice creation are deliberately NOT converted to generic AJAX here.
 * ======================================================================== */
(function(){
'use strict';

var html=document.documentElement;

if(
  !html.classList.contains('et-booking-focus-prepaint')
){
  return;
}

html.dataset.etBookingLiveCore='ERP-11.3.90';


/*
 * ERP-11.3.90 Step-1 paint safety.
 * The native GENERAL body is display:none before Step-1 is ready. If the
 * dedicated Step-1 asset fails to initialize, reveal the native body after
 * six seconds instead of leaving a blank workspace.
 */
if(
  html.classList.contains(
    'et-general-progressive-step1-11390'
  )
){
  window.setTimeout(
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
}

var norm=function(value){
  return String(value||'')
    .replace(/\s+/g,' ')
    .trim()
    .toLowerCase();
};

var text=function(el){
  return norm(el&&el.textContent);
};

var headingNodes=function(doc){
  return Array.prototype.slice.call(
    doc.querySelectorAll(
      'h1,h2,h3,h4,h5,h6,legend,strong,b'
    )
  );
};

var smallestHeading=function(doc,matcher){
  return headingNodes(doc)
    .filter(function(el){
      return matcher(text(el),el);
    })
    .sort(function(a,b){
      return text(a).length-text(b).length;
    })[0]||null;
};

var climbPanel=function(start,predicate){
  var current=start;

  while(
    current
    && current!==document.documentElement
    && current.tagName!=='BODY'
  ){
    if(predicate(current)){
      return current;
    }
    current=current.parentElement;
  }

  return start?start.parentElement:null;
};

var markPanel=function(panel,key){
  if(!panel)return null;
  panel.setAttribute(
    'data-et-booking-panel-11375',
    key
  );
  return panel;
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

var commonAncestor=function(a,b){
  if(!a||!b)return null;
  var ancestors=[];
  var current=a.parentElement;
  while(current){
    ancestors.push(current);
    current=current.parentElement;
  }
  return ancestors.find(function(candidate){
    return candidate.contains(b);
  })||null;
};

var findMetricBlock=function(doc){
  var labels=['passengers','tickets','booking value','travel status'];
  var nodes=Array.prototype.slice.call(
    doc.querySelectorAll('div,section,article')
  ).filter(function(el){
    var t=text(el);
    return labels.every(function(label){
      return t.indexOf(label)!==-1;
    });
  });

  return nodes.sort(function(a,b){
    return text(a).length-text(b).length;
  })[0]||null;
};

var semanticPanels=function(doc){
  var out={};

  var bookingHeader=smallestHeading(
    doc,
    function(t){return t==='booking header';}
  );
  if(bookingHeader){
    out['booking-header']=markPanel(
      climbPanel(
        bookingHeader,
        function(el){
          var t=text(el);
          return t.indexOf('booking type')!==-1
            && t.indexOf('booking date')!==-1
            && (
              t.indexOf('edit booking header')!==-1
              || !!el.querySelector('form')
            );
        }
      ),
      'booking-header'
    );
  }

  var passengers=smallestHeading(
    doc,
    function(t){return t==='passengers';}
  );
  if(passengers){
    out.passengers=markPanel(
      climbPanel(
        passengers,
        function(el){
          var t=text(el);
          return !!el.querySelector('.passenger-table')
            || t.indexOf('current booking passengers')!==-1;
        }
      ),
      'passengers'
    );
  }

  var air=smallestHeading(
    doc,
    function(t){
      return t==='air ticket batch entry';
    }
  );
  if(air){
    out['air-ticket']=markPanel(
      climbPanel(
        air,
        function(el){
          var t=text(el);
          return t.indexOf('common flight itinerary')!==-1
            && t.indexOf('passenger-type fare entry')!==-1;
        }
      ),
      'air-ticket'
    );
  }

  var saved=smallestHeading(
    doc,
    function(t){
      return t==='saved passenger tickets';
    }
  );
  if(saved){
    out['saved-tickets']=markPanel(
      climbPanel(
        saved,
        function(el){
          var t=text(el);
          return t.indexOf('saved passenger tickets')!==-1
            && (
              t.indexOf('save ticket updates')!==-1
              || !!el.querySelector('table')
            );
        }
      ),
      'saved-tickets'
    );
  }

  var transport=smallestHeading(
    doc,
    function(t){
      return t==='transport';
    }
  );
  if(transport){
    out.transport=markPanel(
      climbPanel(
        transport,
        function(el){
          var t=text(el);
          return t.indexOf('transport')!==-1
            && (
              t.indexOf('pickup')!==-1
              || t.indexOf('route')!==-1
            )
            && (
              t.indexOf('save transport')!==-1
              || !!el.querySelector('form')
            );
        }
      ),
      'transport'
    );
  }

  var other=smallestHeading(
    doc,
    function(t){
      return t==='other services / products'
        || t.indexOf('other services / products')===0;
    }
  );
  if(other){
    out['other-services']=markPanel(
      climbPanel(
        other,
        function(el){
          var t=text(el);
          return t.indexOf('other services / products')!==-1
            && (
              t.indexOf('add service / product')!==-1
              || t.indexOf('no other services')!==-1
              || !!el.querySelector('form')
            );
        }
      ),
      'other-services'
    );
  }

  var workflow=smallestHeading(
    doc,
    function(t){
      return t==='booking workflow';
    }
  );
  if(workflow){
    out.workflow=markPanel(
      climbPanel(
        workflow,
        function(el){
          var t=text(el);
          return t.indexOf('booking workflow')!==-1
            && (
              t.indexOf('submit for approval')!==-1
              || t.indexOf('confirm booking')!==-1
              || t.indexOf('more actions')!==-1
            );
        }
      ),
      'workflow'
    );
  }

  var voucher=smallestHeading(
    doc,
    function(t){
      return t==='client voucher / operational documents';
    }
  );
  if(voucher){
    out.voucher=markPanel(
      climbPanel(
        voucher,
        function(el){
          return text(el).indexOf(
            'client voucher / operational documents'
          )!==-1;
        }
      ),
      'voucher'
    );
  }

  var accounting=smallestHeading(
    doc,
    function(t){
      return t==='commercial & accounting bridge';
    }
  );
  if(accounting){
    out.accounting=markPanel(
      climbPanel(
        accounting,
        function(el){
          return text(el).indexOf(
            'commercial & accounting bridge'
          )!==-1;
        }
      ),
      'accounting'
    );
  }

  var metrics=findMetricBlock(doc);
  if(metrics){
    out.metrics=markPanel(
      metrics,
      'metrics'
    );
  }

  if(
    out.transport
    && out['other-services']
  ){
    var outer=commonAncestor(
      out.transport,
      out['other-services']
    );

    if(outer){
      var transportColumn=directUnder(
        out.transport,
        outer
      );
      var otherColumn=directUnder(
        out['other-services'],
        outer
      );

      if(
        transportColumn
        && otherColumn
        && transportColumn!==otherColumn
      ){
        outer.setAttribute(
          'data-et-booking-operational-stack-11375',
          '1'
        );

        outer.style.setProperty(
          'display',
          'flex',
          'important'
        );
        outer.style.setProperty(
          'flex-direction',
          'column',
          'important'
        );
        outer.style.setProperty(
          'grid-template-columns',
          'none',
          'important'
        );

        [transportColumn,otherColumn]
          .forEach(function(column){
            column.style.setProperty(
              'width',
              '100%',
              'important'
            );
            column.style.setProperty(
              'max-width',
              'none',
              'important'
            );
            column.style.setProperty(
              'grid-column',
              '1 / -1',
              'important'
            );
          });
      }
    }
  }

  return out;
};

var liveState=function(message,state){
  document.querySelectorAll(
    '.et-booking-live-state-11375'
  ).forEach(function(node){
    node.remove();
  });

  var box=document.createElement('div');
  box.className=
    'et-booking-live-state-11375 '
    +(state||'');
  box.textContent=String(message||'Saved.');
  document.body.appendChild(box);

  window.setTimeout(function(){
    box.remove();
  },3000);
};

var parseHtml=function(source){
  try{
    return new DOMParser().parseFromString(
      String(source||''),
      'text/html'
    );
  }catch(e){
    return null;
  }
};

var formMethod=function(form){
  var spoof=form.querySelector(
    'input[name="_method"]'
  );
  return String(
    spoof&&spoof.value
      ? spoof.value
      : form.method||'GET'
  ).toUpperCase();
};

var formPath=function(form){
  try{
    return new URL(
      form.action,
      window.location.href
    ).pathname;
  }catch(e){
    return '';
  }
};

var liveFormKey=function(form){
  var panel=form.closest(
    '[data-et-booking-panel-11375]'
  );

  return panel
    ? panel.getAttribute(
        'data-et-booking-panel-11375'
      )
    : '';
};

var excludedAction=function(form){
  var path=norm(
    formPath(form)
  );
  var label=norm(
    form.textContent
  );

  if(
    path.indexOf('/sales/invoices')!==-1
    || path.indexOf('/voucher')!==-1
    || path.indexOf('/approval')!==-1
    || path.indexOf('/approve')!==-1
    || path.indexOf('/confirm')!==-1
    || path.indexOf('/post')!==-1
    || path.indexOf('/reopen')!==-1
    || path.indexOf('/reverse')!==-1
  ){
    return true;
  }

  return label.indexOf('submit for approval')!==-1
    || label.indexOf('confirm booking')!==-1
    || label.indexOf('post to accounting')!==-1;
};

var isLiveBookingForm=function(form){
  if(
    !(form instanceof HTMLFormElement)
    || form.dataset.etUnifiedLiveOff==='1'
    || excludedAction(form)
  ){
    return false;
  }

  var path=formPath(form);

  if(formMethod(form)==='GET'){
    return false;
  }

  if(!/^\/operations\/bookings\/\d+(?:\/|$)/i.test(path)){
    return false;
  }

  if(
    /\/itinerary-segments(?:\/|$)/i.test(path)
  ){
    /*
     * Air itinerary has its own row-level POST/PUT/DELETE live engine.
     */
    return false;
  }

  /*
   * Any write form already located inside a known Booking Workspace panel is
   * safe for same-page transport, regardless of the host ERP's exact route
   * suffix. This is important for GENERAL/Other Services builds where route
   * names changed across native versions.
   */
  if(liveFormKey(form)){
    return true;
  }

  if(
    /\/passengers(?:\/|$)/i.test(path)
    || /\/transport-segments(?:\/|$)/i.test(path)
    || /\/services(?:\/|$)/i.test(path)
    || /\/other-services(?:\/|$)/i.test(path)
    || /\/products(?:\/|$)/i.test(path)
    || /\/group-package(?:\/|$)/i.test(path)
    || /\/hotel(?:\/|$)/i.test(path)
    || /\/visa(?:\/|$)/i.test(path)
    || /\/transport(?:\/|$)/i.test(path)
  ){
    return true;
  }

  /*
   * Booking Header edit/save may post to /operations/bookings/{id}.
   */
  if(
    /^\/operations\/bookings\/\d+$/i.test(path)
    && liveFormKey(form)==='booking-header'
  ){
    return true;
  }

  return false;
};

var submitForm=async function(form){
  var rawAction=
    form.getAttribute('action')
    || form.action
    || window.location.href;

  var action;

  try{
    action=new URL(
      rawAction,
      window.location.href
    ).toString();
  }catch(e){
    action=String(rawAction||window.location.href);
  }

  return fetch(
    action,
    {
      method:'POST',
      body:new FormData(form),
      credentials:'same-origin',
      redirect:'follow',
      headers:{
        'X-Requested-With':'XMLHttpRequest',
        'Accept':'text/html,application/xhtml+xml'
      }
    }
  );
};

var validationText=function(doc){
  if(!doc)return '';

  var node=doc.querySelector(
    '.alert-danger,.alert-error,.field-error,.invalid-feedback,[role="alert"]'
  );

  if(!node)return '';

  var value=String(
    node.textContent||''
  ).replace(/\s+/g,' ').trim();

  return value;
};

var replacePanel=function(
  currentPanels,
  freshPanels,
  key
){
  var current=currentPanels[key];
  var fresh=freshPanels[key];

  if(
    !current
    || !fresh
    || current===document.body
  ){
    return false;
  }

  current.innerHTML=fresh.innerHTML;
  return true;
};

var syncPanels=function(doc,keys){
  var current=semanticPanels(document);
  var fresh=semanticPanels(doc);

  if(
    document.documentElement.classList.contains(
      'etgp-step1-live-11390'
    )
    && typeof window.etGeneralProgressiveStep1Sync11390==='function'
    && window.etGeneralProgressiveStep1Sync11390(
      doc,
      keys||[]
    )
  ){
    semanticPanels(document);

    if(
      typeof window.etAirBookingLiveRebind103169==='function'
    ){
      window.etAirBookingLiveRebind103169();
    }

    return;
  }

  (keys||[]).forEach(function(key){
    replacePanel(
      current,
      fresh,
      key
    );
  });

  /*
   * Re-run Air and semantic bindings after replacing inner HTML.
   */
  semanticPanels(document);

  if(
    typeof window.etAirBookingLiveRebind103169==='function'
  ){
    window.etAirBookingLiveRebind103169();
  }

  if(
    typeof bindOperationalAutosave==='function'
  ){
    bindOperationalAutosave();
  }
};

var reconcileAirServiceLinksAfterPassengerRemoval=async function(doc){
  var forms=Array.prototype.slice.call(
    doc.querySelectorAll(
      '.air-service-shell form[action*="/services/"]'
    )
  ).filter(function(form){
    return !!form.querySelector(
      'input[name="passenger_ids[]"]'
    );
  });

  var latest=doc;

  for(
    var i=0;
    i<forms.length;
    i++
  ){
    var form=forms[i];

    try{
      var response=await submitForm(form);
      var source=await response.text();
      var next=parseHtml(source);

      if(
        response.ok
        && next
      ){
        latest=next;
      }
    }catch(e){
      /*
       * Do not turn a successful passenger removal into a browser failure.
       * The workflow warning remains visible if the native service sync itself
       * rejected the reconciliation.
       */
    }
  }

  return latest;
};

var isPassengerRemoval=function(form){
  return /\/passengers\/\d+$/i.test(
    formPath(form)
  ) && formMethod(form)==='DELETE';
};

var replaceFocusedContentFromFresh=function(doc,responseUrl){
  if(!doc)return false;

  var current=document.querySelector(
    'section.content'
  );
  var fresh=doc.querySelector(
    'section.content'
  );

  if(
    !current
    || !fresh
  ){
    return false;
  }

  current.innerHTML=fresh.innerHTML;

  if(
    responseUrl
    && responseUrl!==window.location.href
  ){
    try{
      window.history.replaceState(
        {},
        '',
        responseUrl
      );
    }catch(e){}
  }

  semanticPanels(document);

  if(
    typeof window.etAirBookingLiveRebind103169==='function'
  ){
    window.etAirBookingLiveRebind103169();
  }

  if(
    typeof bindOperationalAutosave==='function'
  ){
    bindOperationalAutosave();
  }

  return true;
};

var panelRefreshKeys=function(key){
  if(key==='passengers'){
    return [
      'metrics',
      'passengers',
      'air-ticket',
      'transport',
      'other-services',
      'workflow'
    ];
  }

  if(key==='transport'){
    return [
      'metrics',
      'transport',
      'other-services',
      'workflow'
    ];
  }

  if(key==='other-services'){
    return [
      'metrics',
      'other-services',
      'transport',
      'workflow'
    ];
  }

  if(key==='booking-header'){
    return [
      'metrics',
      'booking-header',
      'workflow'
    ];
  }

  return [
    'metrics',
    key,
    'workflow'
  ].filter(Boolean);
};

document.addEventListener(
  'submit',
  async function(event){
    var form=event.target;

    if(!isLiveBookingForm(form)){
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    if(form.dataset.etUnifiedLiveBusy==='1'){
      return;
    }

    form.dataset.etUnifiedLiveBusy='1';

    var key=liveFormKey(form);
    var panel=form.closest(
      '[data-et-booking-panel-11375]'
    );

    if(panel){
      panel.classList.add(
        'et-booking-live-saving-11375'
      );
    }

    var submitter=event.submitter;
    var originalText=submitter
      ? String(
          submitter.textContent
          || submitter.value
          || ''
        )
      : '';

    if(submitter){
      submitter.disabled=true;
      if(submitter.tagName==='INPUT'){
        submitter.value='Saving…';
      }else{
        submitter.textContent='Saving…';
      }
    }

    try{
      var response=await submitForm(form);
      var source=await response.text();
      var doc=parseHtml(source);

      if(
        !response.ok
        || !doc
      ){
        throw new Error(
          'Booking data could not be saved.'
        );
      }

      var error=validationText(doc);

      if(
        error
        && (
          norm(error).indexOf('please correct')!==-1
          || norm(error).indexOf('error')!==-1
          || norm(error).indexOf('required')!==-1
        )
      ){
        syncPanels(
          doc,
          panelRefreshKeys(key)
        );

        throw new Error(error);
      }

      if(isPassengerRemoval(form)){
        doc=await reconcileAirServiceLinksAfterPassengerRemoval(
          doc
        );
      }

      if(
        !key
        && (
          /\/group-package(?:\/|$)/i.test(
            formPath(form)
          )
          || /\/hotel(?:\/|$)/i.test(
            formPath(form)
          )
          || /\/visa(?:\/|$)/i.test(
            formPath(form)
          )
          || /\/transport(?:\/|$)/i.test(
            formPath(form)
          )
        )
      ){
        replaceFocusedContentFromFresh(
          doc,
          response.url
        );
      }else{
        syncPanels(
          doc,
          panelRefreshKeys(key)
        );
      }

      if(
        /\/passengers$/i.test(
          formPath(form)
        )
        && formMethod(form)==='POST'
      ){
        try{
          form.reset();
        }catch(e){}
      }

      liveState(
        isPassengerRemoval(form)
          ? 'Passenger removed and active service links synchronized.'
          : 'Booking data saved automatically.',
        'ok'
      );
    }catch(error){
      liveState(
        error&&error.message
          ? error.message
          : 'Booking data could not be saved.',
        'error'
      );
    }finally{
      form.dataset.etUnifiedLiveBusy='0';

      if(panel){
        panel.classList.remove(
          'et-booking-live-saving-11375'
        );
      }

      if(submitter){
        submitter.disabled=false;
        if(submitter.tagName==='INPUT'){
          submitter.value=originalText;
        }else{
          submitter.textContent=originalText;
        }
      }
    }
  },
  true
);

var bindOperationalAutosave=function(){
  [
    'transport',
    'other-services'
  ].forEach(function(key){
    var panel=document.querySelector(
      '[data-et-booking-panel-11375="'+key+'"]'
    );

    if(!panel)return;

    Array.prototype.slice.call(
      panel.querySelectorAll('form')
    ).forEach(function(form){
      if(
        form.dataset.etOperationalAutosave11375==='1'
        || !isLiveBookingForm(form)
        || formMethod(form)==='DELETE'
      ){
        return;
      }

      var submitter=Array.prototype.slice.call(
        form.querySelectorAll(
          'button[type="submit"],input[type="submit"]'
        )
      ).find(function(button){
        var label=norm(
          button.textContent
          || button.value
        );
        return label.indexOf('save')!==-1
          || label.indexOf('add')!==-1
          || label.indexOf('update')!==-1;
      });

      if(!submitter)return;

      var fields=Array.prototype.slice.call(
        form.querySelectorAll(
          'input:not([type="hidden"]):not([type="submit"]),select,textarea'
        )
      ).filter(function(field){
        return !field.disabled
          && field.type!=='button'
          && field.type!=='reset';
      });

      if(fields.length<1)return;

      form.dataset.etOperationalAutosave11375='1';

      var timer=null;
      var schedule=function(){
        window.clearTimeout(timer);

        timer=window.setTimeout(function(){
          if(
            form.dataset.etUnifiedLiveBusy==='1'
            || (
              typeof form.checkValidity==='function'
              && !form.checkValidity()
            )
          ){
            return;
          }

          if(typeof form.requestSubmit==='function'){
            form.requestSubmit(submitter);
          }else{
            submitter.click();
          }
        },700);
      };

      fields.forEach(function(field){
        field.addEventListener(
          'change',
          schedule
        );

        if(
          field.tagName==='INPUT'
          || field.tagName==='TEXTAREA'
        ){
          field.addEventListener(
            'blur',
            schedule
          );
        }
      });
    });
  });
};

/*
 * Initial UI normalization and dynamic product/service normalization.
 */
semanticPanels(document);
bindOperationalAutosave();

if(window.MutationObserver){
  var observer=new MutationObserver(function(){
    semanticPanels(document);
    bindOperationalAutosave();
  });

  observer.observe(
    document.body,
    {
      childList:true,
      subtree:true
    }
  );

  window.setTimeout(function(){
    observer.disconnect();
  },10000);
}

})();
