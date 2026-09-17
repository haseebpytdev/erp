import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const view = read('resources/views/operations/bookings/products-hub-v113304.blade.php');
const runtime = read('public/erp11390/general-progressive-step1.js');
let pass = 0;
const ok = (value, message) => { assert.ok(value, message); pass++; };

ok(view.includes('data-etgp-products-runtime="1"'), 'Products page owns a compatible runtime root');
ok(view.includes('data-etgp-product-buttons') && view.includes('data-etgp-product-shells'), 'Products page owns selector and product-shell hosts');
ok(view.includes('general-progressive-step1.js') && view.includes('general-progressive-step1.css'), 'Products page loads the existing product runtime');
ok(view.includes("window.etgpRenderProducts113305(root,root.dataset.bookingReference,0)"), 'Products page invokes the existing renderProducts function');
ok(runtime.includes('window.etgpRenderProducts113305=renderProducts'), 'renderProducts is exposed as a reusable runtime entry point');
ok(runtime.includes("step1Products=root.querySelector('.etgp-products-card')") && runtime.includes('step1Shells'), 'Step 1 product presentation is removed after the independent Products document is available');
ok(view.includes("window.location.pathname+hash"), 'Product actions remain on the Products document');
ok(view.includes("var hash=link.hash;if(hash)link.href=window.location.pathname+hash"), 'Products actions are normalized to local Products-document anchors');
ok(!view.includes('etgp-passenger-card') && !view.includes('passenger-table'), 'Products page does not copy a hidden passenger visual DOM');
ok(runtime.includes("loadPassengerData:function()") && runtime.includes("'/air-product'"), 'Products runtime passenger data uses the structured Air authority');
ok(runtime.includes('etBookingWorkspaceContext113305.getProductSelection(reference)') && runtime.includes('etBookingWorkspaceContext113305.saveProductSelection(reference,next)'), 'Products selection reads and writes use the existing adapter authority');
ok(view.includes('data-etgp-booking-locked') && view.includes('data-booking-reference'), 'Products root exposes existing lock and booking context');
console.log(`erp113306-products-page-owner-regression: ${pass} assertions passed`);
