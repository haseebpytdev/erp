import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const controller = read('app/Http/Controllers/Operations/BookingProductsHubController.php');
const view = read('resources/views/operations/bookings/products-hub-v113304.blade.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const runtime = read('public/erp11390/general-progressive-step1.js');
const routes = read('routes/erp103179.php');
let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };

ok(controller.includes("'passengerCount' => $passengerCount"), 'Hub receives an explicit passenger count');
ok(controller.includes("$snapshots['air']['passengers']"), 'Passenger count reuses the existing Air snapshot authority');
ok(view.includes('$passengerReady') && view.includes("$passengerReady ? $passengerCount : 'Required'"), 'Passenger workflow text reflects the authoritative count');
ok(view.includes("$passengerReady ? 'done' : 'pending'"), 'Passenger workflow class is dynamic');
ok(view.includes("$productsReady ? 'done' : 'pending'"), 'Product workflow class is dynamic');
ok(view.includes('$approvalDone') && view.includes('$approvalCurrent'), 'Approval workflow state derives from persisted status');
ok(!view.includes('class="done">Passengers') && !view.includes('class="done">Products'), 'Required workflow states are not falsely green');
ok(runtime.includes('etgpMainBookingOverview11390') && runtime.includes('if(etgpMainBookingOverview11390(root))return;'), 'Main Booking skips detailed product renderProducts calls');
ok(runtime.includes('etgp-passenger-card') && runtime.includes('quick-add'), 'Main Booking passenger runtime remains present');
ok(presenter.includes('data-et-smart-products-entry="1"') && !presenter.includes('data-et-booking-products-launcher="1"'), 'Smart Products entry remains the sole normal product entry');
ok(view.includes("route('bookings.products.workspace'") && view.includes('data-et-products-hub="1"'), 'Hub retains canonical cards and route authority');
ok(routes.includes("->name('bookings.products.workspace')"), 'Dedicated product workspace route remains named');
ok(view.includes('Other Services workspace is not configured yet.') && view.includes('Not Configured'), 'Other Services remains truthful');
console.log(`ERP113378_BOOKING_RUNTIME_SEPARATION=PASS (${pass} assertions)`);
