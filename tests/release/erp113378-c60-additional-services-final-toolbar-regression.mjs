import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const focus = read('public/erp11335/booking-focus.js');
const progressive = read('public/erp11390/general-progressive-step1.js');
const release = read('config/et_erp_release.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const c59 = read('tests/release/erp113378-c59-additional-services-route-key-regression.mjs');

ok(focus.includes('var etBookingFocusReconcileAdditionalServices=function(){'), 'reusable Additional Services reconciliation exists');
ok(focus.includes('window.etBookingFocusReconcileAdditionalServices=etBookingFocusReconcileAdditionalServices;'), 'reconciliation is exposed for lifecycle reuse');
ok(focus.includes("/^\\/operations\\/bookings\\/([^\\/]+)\\/?$/i"), 'reconciliation is gated to the exact dashboard route');
ok(focus.includes("link.href='/operations/bookings/'+match[1]+'/additional-services'"), 'canonical route segment is retained');
ok(focus.includes("link.setAttribute('data-et-additional-services-entry','1')"), 'canonical action marker is retained');
ok(focus.includes("document.querySelectorAll('[data-et-additional-services-entry=\"1\"]')"), 'existing canonical action is reused');
ok(focus.includes('links.forEach(function(duplicate){if(duplicate&&duplicate!==link)duplicate.remove();});'), 'duplicate Additional Services actions are removed');
ok(focus.includes("document.querySelector('.etgp-toolbar-actions')"), 'final progressive toolbar is the destination authority');
ok(focus.includes("normalise(action.textContent)==='client preview'"), 'Client Preview remains an ordering anchor');
ok(focus.includes("label==='menu'||label.indexOf('menu')!==-1"), 'Menu remains an ordering anchor');
ok(focus.includes('finalToolbar.insertBefore(link,before)'), 'canonical action is moved into the final toolbar');
ok(focus.includes('etBookingFocusWatchAdditionalServices();'), 'mount lifecycle invokes reconciliation');
ok(focus.includes('new MutationObserver(function(){'), 'toolbar establishment is observed without a timer workaround');
ok(focus.includes("observe(document.body,{childList:true,subtree:true})"), 'observer watches final toolbar insertion');
ok(focus.includes('additionalObserver.disconnect();'), 'observer disconnects after successful reconciliation');
ok(!focus.includes('setTimeout(function(){etBookingFocusReconcileAdditionalServices'), 'no arbitrary delayed reconciliation is used');
ok(progressive.includes("'etgp-toolbar-actions'"), 'progressive asset creates the final toolbar');
ok(progressive.includes('toolbarActions.appendChild(unit)'), 'progressive action units remain canonical');
ok(/'asset_version' => 'ERP-11\.3\.378-C(?:68|69)'/.test(release), 'current asset revision is authoritative');
ok(presenter.includes('system.erp-assets.booking-focus'), 'booking-focus remains served by the existing presenter');
ok(c59.includes('etBookingFocusReconcileAdditionalServices'), 'C59 route contract remains protected');
ok(!focus.includes("/^\\/operations\\/bookings\\/\\d+\\/?$/i"), 'route scope is not narrowed to numeric IDs');
ok(!focus.includes("window.location.pathname.replace(/BK-[^/]+/"), 'no global booking-reference replacement is introduced');

console.log('PASS ' + pass + ' ERP-11.3.378 C60 final toolbar assertions');
