import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const ok = (condition, label) => { if (!condition) throw new Error(label); console.log(`PASS ${label}`); };

const progressive = read('public/erp11390/general-progressive-step1.js');
const bookingCss = read('public/erp-theme/modules/booking.css');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');

ok(progressive.includes('adoptC36ProductSummary11390'), 'runtime adoption helper is present');
ok(progressive.includes("document.querySelector('main [data-et-c36-product-summary=\"1\"]')"), 'runtime locates the server-rendered summary node');
ok(progressive.includes('root.appendChild(summary)'), 'runtime moves the exact summary node into etgp-step1');
ok(progressive.includes("summary.setAttribute('data-et-c36-canvas-adopted','1')"), 'runtime records canonical canvas adoption');
ok(progressive.indexOf('adoptC36ProductSummary11390(') < progressive.indexOf('Remove every old native GENERAL service/workflow block'), 'adoption runs before obsolete native content cleanup');
ok(presenter.includes('data-et-c36-product-summary="1"'), 'server provides one canonical summary node');
ok(!presenter.includes('$productLauncher ='), 'independent outer Products launcher remains absent');
ok(bookingCss.includes('main > .et-c36-product-summary'), 'prepaint summary has explicit outer-canvas authority');
ok(bookingCss.includes('width:calc(100% - (2 * var(--et-shell-gutter-x)))'), 'prepaint summary uses shell gutter geometry');
ok(bookingCss.includes('max-width:1280px') && bookingCss.includes('margin:16px auto'), 'prepaint summary uses the shared responsive width and centering');
ok(!/\.et-c36-product-summary[^}]*overflow-x\s*:\s*(?:auto|scroll)/.test(bookingCss), 'summary has no horizontal overflow');
ok(progressive.includes("'etgp-step1'") && progressive.includes('root.appendChild(summary)'), 'progressive workspace owns one etgp-step1 root');

console.log('C36_CORRECTIVE3_REGRESSION=PASS (12 assertions)');
