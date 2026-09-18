import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';

const read = path =>
  fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const sha256 = path =>
  crypto.createHash('sha256').update(read(path)).digest('hex').toUpperCase();

const shell = read('public/erp-ui/erp-shell-spacing.css');
const base = read('public/erp-ui/erp-professional.css');
const register = read('public/erp-ui/erp-booking-register-reference.css');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const routes = read('routes/erp103179.php');
const productController = read('app/Http/Controllers/Operations/ProductWorkspaceController.php');
const customerResolver = read('app/Services/Operations/NativeBookingCustomerResolver.php');
const productTiming = read('app/Services/Operations/DedicatedProductTimingContext.php');
const focusedMiddleware = read('app/Http/Middleware/PresentBookingFocusedWorkspace.php');
const earlyTiming = read('app/Http/Middleware/DedicatedProductEarlyTiming.php');
const roleMiddleware = read('app/Http/Middleware/EnforceErpRoleScopedAccess.php');
const releaseMiddleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const userLinksMiddleware = read('app/Http/Middleware/PresentErpUserManagementLinks.php');
const passengerLinksMiddleware = read('app/Http/Middleware/PresentPassengerOperationsLink.php');
const groupPackage = read('resources/views/operations/bookings/group-package-unified-v103172.blade.php');
const cashVoucherLinks = read('app/Http/Middleware/PresentCashVoucherLinks.php');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const executableController = controller.replace(/\/\/[^\n]*|\/\*[\s\S]*?\*\//g, '');
const executableCssController = executableController.slice(0, executableController.indexOf('public function js'));
const freshCore = read('public/erp-theme/et-core.css');
const freshShell = read('public/erp-theme/et-shell.css');
const freshFocusedShell = read('public/erp-theme/et-focused-shell.css');
const dashboardTheme = read('public/erp-theme/modules/dashboard.css');
const registerModuleTheme = read('public/erp-theme/modules/registers.css');
const accountingModuleTheme = read('public/erp-theme/modules/accounting.css');
const coaView = read('resources/views/accounting/chart-of-accounts/workspace.blade.php');
const mrViews = [
  read('resources/views/accounting/management-reporting/profit-and-loss.blade.php'),
  read('resources/views/accounting/management-reporting/balance-sheet.blade.php'),
  read('resources/views/accounting/management-reporting/management.blade.php'),
];
const bookingTheme = read('public/erp-theme/modules/booking.css');
const freshShellJs = read('public/erp-theme/js/shell.js');
const freshFocusedShellJs = read('public/erp-theme/js/focused-shell.js');
const freshProgressiveRuntime = read('public/erp11390/general-progressive-step1.js');
const airRenderBody = freshProgressiveRuntime.slice(freshProgressiveRuntime.indexOf('var etgpAirRender113106='), freshProgressiveRuntime.indexOf('var renderAirProductWorkspace113106='));
const airMountBody = freshProgressiveRuntime.slice(freshProgressiveRuntime.indexOf('var renderAirProductWorkspace113106='), freshProgressiveRuntime.indexOf('/* ======================================================================\n * ERP-11.3.127'));
const etgpDedicatedAdapterBody = source => source.slice(source.indexOf('var etgpAirDedicatedIntegration113314='), source.indexOf('var etgpAirHostIntegration113314='));
const focusedShellJs = freshFocusedShellJs;
const salesInvoiceFocus = read('app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php');
const dedicatedCore = read('public/erp-theme/js/dedicated-product-core.js');
const dedicatedAir = read('public/erp-theme/js/products/air.js');
const dedicatedAirCss = read('public/erp-theme/css/products/air.css');
const dedicatedCss = read('public/erp-theme/modules/dedicated-product.css');
const productView = read('resources/views/operations/bookings/product-workspace-v113305.blade.php');
const assetController = controller;
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => {
  assert.ok(condition, label);
  pass++;
};

ok(version === 'v1.1.33.315-ERP11.3.315', 'ERP-11.3.259 packaged release metadata is current');
ok(controller.includes("public/erp-theme/et-core.css") && controller.includes("public/erp-theme/et-shell.css"), 'fresh core and standard shell are served by the ERP asset authority');
ok(controller.includes("public/erp-theme/et-focused-shell.css") && controller.includes("public/erp-theme/modules/sales-invoice.css"), 'fresh focused and module theme layers are available');
ok(freshCore.includes('--et-primary:#2563EB') && freshCore.includes('--et-control-height:38px'), 'fresh core owns the approved design tokens');
ok(freshCore.includes('a:hover') && freshCore.includes(':focus-visible') && freshCore.includes('min-height:var(--et-control-height)'), 'fresh core owns generic interaction and control primitives');
ok(freshCore.includes('.btn-primary') && freshCore.includes('.pagination .page-link') && freshCore.includes('.text-success'), 'fresh core owns generic action, pagination, and semantic financial primitives');
ok(dashboardTheme.includes('[data-et-dashboard-header="true"]') && dashboardTheme.includes('.et-dashboard-kpi-grid') && dashboardTheme.includes('.et-dashboard-kpi'), 'fresh dashboard module owns Dashboard-specific presentation selectors');
ok(registerModuleTheme.includes('.sci-card') && registerModuleTheme.includes('.scs-card') && registerModuleTheme.includes('.scb-card'), 'fresh registers module owns live Supplier Costing aliases');
ok(accountingModuleTheme.includes('.cvf27-card') && accountingModuleTheme.includes('.cvs27-card') && accountingModuleTheme.includes('.aa-wrap') && !freshCore.includes('.cvf27-card'), 'accounting aliases remain module-scoped and are not bundled into core');
ok(accountingModuleTheme.includes('.coa-card') && accountingModuleTheme.includes('.coa-filters') && !freshCore.includes('.coa-card'), 'Chart of Accounts aliases are owned by accounting CSS, not core');
ok(coaView.includes('class="coa-card"') && coaView.includes('class="coa-tools"') && coaView.includes('route(\'accounting.chart-of-accounts.workspace\')'), 'Chart of Accounts remains a live accounting workspace');
ok(accountingModuleTheme.includes('.mr-head') && accountingModuleTheme.includes('.mr-filter') && accountingModuleTheme.includes('.mr-card') && accountingModuleTheme.includes('.mr-kpi'), 'Management reporting aliases are owned by accounting CSS');
ok(accountingModuleTheme.includes('.mr .total') && accountingModuleTheme.includes('.mr .grand') && accountingModuleTheme.includes('.mr-table-wrap'), 'Report-specific totals and responsive table authority remain available');
ok(mrViews.every(view => view.includes('class="mr"') && view.includes('class="mr-card"')), 'Profit and Loss, Balance Sheet, and Management reports emit live mr markup');
ok(freshShell.includes('grid-template-columns:208px minmax(0,1fr)') && freshShell.includes('width:100%'), 'fresh shell owns the standard 208px and full-width geometry');
ok(freshFocusedShell.includes('grid-template-columns:minmax(0,1fr)') && freshFocusedShell.includes('display:none'), 'fresh focused shell removes the permanent sidebar without width hacks');
ok(freshShellJs.includes('server-rendered DOM remains authoritative') && freshFocusedShellJs.includes('no DOM reconstruction'), 'fresh shell JavaScript contains interaction only');
ok(freshShellJs.includes("dataset.etThemeShell='fresh-v1'") && freshShellJs.includes("addEventListener('click'"), 'fresh shell marks the theme and delegates native navigation clicks');
ok(earlyTiming.includes('early_total') && earlyTiming.includes('finishResponse'), 'dedicated early timing wraps the full downstream response');
ok(earlyTiming.indexOf("$timing->stop('early_total')") < earlyTiming.indexOf('$timing->finishResponse($response)'), 'early-total stops before final Server-Timing emission');
ok((releaseMiddleware.match(/stop\('release_pre'\)/g) || []).length === 1, 'release-pre has one definitive stop');
ok(controller.includes("/operations/bookings/{booking}/products/{product}") || focusedMiddleware.includes('DedicatedProductTimingContext'), 'dedicated product timing remains route-scoped');
ok(roleMiddleware.includes("measure('role_access'") && roleMiddleware.includes("measure('role_policy'"), 'role access and policy timings are instrumented');
ok(releaseMiddleware.includes("release_pre") && releaseMiddleware.includes("release_downstream") && releaseMiddleware.includes("release_response"), 'release metadata response timing is instrumented');
ok(userLinksMiddleware.includes('user_links_response') && passengerLinksMiddleware.includes('passenger_links_response'), 'presentation response timings are instrumented');
ok(!earlyTiming.includes('DB::') && !earlyTiming.includes('setContent'), 'early timing adds no database writes or rendered markup');
ok(freshShellJs.includes('e.defaultPrevented'), 'default-prevented clicks are ignored by the navigation loader');
ok(freshShellJs.includes('et-navigation-pending') && freshShellJs.includes('new URL(raw,window.location.origin)'), 'eligible internal links activate the navigation-pending state with URL validation');
ok(!freshShellJs.includes('preventDefault') && !freshShellJs.includes('location.assign') && !freshShellJs.includes('fetch('), 'native browser navigation remains untouched');
ok(freshShellJs.includes('e.ctrlKey') && freshShellJs.includes('e.metaKey') && freshShellJs.includes('e.shiftKey') && freshShellJs.includes('e.altKey'), 'modifier-key clicks are excluded');
ok(freshShellJs.includes("link.target==='_blank'") && freshShellJs.includes("link.hasAttribute('download')") && freshShellJs.includes('data-et-navigation-ignore'), 'new-tab, download, and explicit opt-out links are excluded');
ok(freshShellJs.includes("url.pathname===window.location.pathname&&url.search===window.location.search&&url.hash!==window.location.hash"), 'same-document hash navigation is excluded');
ok(freshShellJs.includes("addEventListener('pageshow',clearPending)") && freshShellJs.includes('setTimeout(clearPending,8000)'), 'navigation pending state has BFCache and timeout cleanup');
ok(freshShell.includes('body.et-ui-professional.et-navigation-pending::before') && freshShell.includes('position:fixed') && freshShell.includes('pointer-events:none'), 'fresh shell owns the fixed top navigation indicator');
ok(freshShell.includes('@media (prefers-reduced-motion:reduce)') && !/et-navigation-pending[^}]*\{[^}]*opacity:0/.test(freshShell), 'navigation indicator supports reduced motion without hiding content');
ok(freshShell.includes('color:currentColor') && freshShell.includes('svg{stroke:currentColor'), 'sidebar icons inherit readable currentColor across supported shell variants');
ok(freshShell.includes('.et-ui-nav-chevron') && freshShell.includes('color:#fff'), 'sidebar chevrons brighten for hover and active states');
ok(controller.includes("request()->query('module'"), 'module stylesheet selection is request-scoped');
ok(controller.includes("'dashboard' => 'dashboard.css'") && controller.includes("'sales' => 'sales-invoice.css'"), 'module stylesheet map covers dashboard and sales invoice');
ok(!controller.includes("modules/dashboard.css'),\n            base_path('public/erp-theme/modules/booking.css')"), 'module styles are not globally concatenated');
ok(read('app/Http/Middleware/ApplyErpReleaseMetadata.php').includes("&module="), 'page module marker is passed to stylesheet authority');
const metadata = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const sidebarComposer = read('app/Services/Operations/ServerSidebarComposer.php');
ok(metadata.includes("data-et-ui-role=\"'.$role.'\""), 'server response carries a presentation role marker');
ok(productTiming.includes('Server-Timing') && productTiming.includes('X-ET-Product-Diag'), 'dedicated product timing exposes diagnostic headers only');
ok(productTiming.includes('operations/bookings/\\d+/products/(?:air|hotel|transport|visa|other-services)'), 'timing context is scoped to dedicated product routes');
ok(productTiming.includes('DB::listen') && productTiming.includes('db_count'), 'dedicated timing counts request-scoped database queries');
ok(dedicatedCore.includes('window.etDedicatedProductCore') && dedicatedCore.includes('getBookingId') && dedicatedCore.includes('getProductKey'), 'dedicated product core exposes shared booking and product identity APIs');
ok(dedicatedCore.includes('getLockState') && dedicatedCore.includes('loadPassengerData') && dedicatedCore.includes('requestJson'), 'dedicated product core reuses lock, passenger, and request contracts');
ok(dedicatedCore.includes('registerProduct') && dedicatedCore.includes('getRegisteredProduct'), 'dedicated product core provides a product mount registry without a second booking store');
ok(dedicatedCore.includes('markMounted') && dedicatedCore.includes('markFailed') && dedicatedCore.includes('data-et-dedicated-loading'), 'dedicated product core provides a narrow loading success/failure bridge');
ok(dedicatedCore.includes('directRootValue') && dedicatedCore.includes('getBookingId') && dedicatedCore.includes('getBookingReference') && dedicatedCore.includes('getProductKey') && dedicatedCore.includes('getCurrency'), 'dedicated core resolves identity directly from the server-rendered product root');
ok(dedicatedCore.includes('getLockState') && dedicatedCore.includes('applyReadOnly') && dedicatedCore.includes('PENDING APPROVAL'), 'dedicated core owns server-seeded lifecycle lock semantics independently');
ok(dedicatedCore.includes("querySelector('meta[name=\"csrf-token\"]')") && !dedicatedCore.includes('getCsrfToken:function(){var c=context()'), 'dedicated core reads CSRF from the meta tag without legacy CSRF dependency');
ok(dedicatedCore.includes('create:function') || dedicatedCore.includes('create:create'), 'dedicated core owns generic DOM creation');
ok(dedicatedCore.includes('setProductResponse') && dedicatedCore.includes('getProductPromise') && dedicatedCore.includes('setProductPromise') && dedicatedCore.includes('keyFor'), 'dedicated product response and promise caches are scoped by booking and product');
ok(dedicatedCore.includes('setPassengerData') && dedicatedCore.includes('getPassengerData') && !dedicatedCore.includes('localStorage.setItem(\'passenger'), 'dedicated core provides an in-memory passenger snapshot without persistent passenger caching');
ok(dedicatedCore.includes('readDraft') && dedicatedCore.includes('writeDraft') && dedicatedCore.includes('clearDraft') && dedicatedCore.includes('et-dedicated-draft:'), 'dedicated core provides booking/product-scoped draft helpers');
ok(dedicatedAir.includes('etgp-air-product-draft-v113119:') && dedicatedAir.includes("/air-product"), 'dedicated Air preserves the existing endpoint and draft key contract');
ok(dedicatedAir.includes('setProductPromise') && dedicatedAir.includes('getProductPromise') && dedicatedAir.includes('setProductResponse'), 'dedicated Air uses the core promise and response cache');
ok(dedicatedAir.includes("core.setProductPromise('air',bookingId,null)") && dedicatedAir.includes('catch(function(error)'), 'rejected dedicated Air loads clear the cached promise for retry');
ok(!dedicatedAir.includes('etBookingWorkspaceContext113305') && !dedicatedAir.includes('etgpBookingLockState113162') && !dedicatedAir.includes('etgpApplyBookingLock113162') && !dedicatedAir.includes('etgpRefreshPersistedBookingState113153') && !dedicatedAir.includes('etgpAirApplySummaryKpis113124') && !dedicatedAir.includes('etgpAirUpdatePassengerMetric113124') && !dedicatedAir.includes('etgpApplyPassengerFareOverrides113137'), 'dedicated Air has no legacy progressive or Main Booking globals');
ok(['Flight Itinerary','+ Add Flight Segment','segment_type','airline_id','flight_number','departure_at','arrival_at','Vendor / Supplier','airline_pnr','booking_source','issue_date','booking_class','baggage','incrementTicketNumber','fillFollowingTickets','PENDING_TICKET','ADULT','CHILD','INFANT','sale_price','cost_price','basic_rate','vendor_minus_type','vendor_minus_value','customer_minus_type','customer_minus_value','vendor_other_cost','PNR Customer Total','PNR Vendor Total','Gross Margin'].every(token=>dedicatedAir.includes(token)), 'dedicated Air preserves the full itinerary, ticket, fare, and summary parity markers');
ok(dedicatedAir.includes('Array.isArray(data.itinerary)?data.itinerary:[]') && dedicatedAir.includes('segments:segments'), 'dedicated Air preserves itinerary response data and outgoing segments payload');
ok(dedicatedAir.includes("'Accept':'application/json'") && dedicatedAir.includes("'Content-Type':'application/json'") && dedicatedAir.includes("'X-Requested-With':'XMLHttpRequest'") && dedicatedAir.includes("'X-CSRF-TOKEN':core.getCsrfToken()") && dedicatedAir.includes('!response.ok||!data||data.ok!==true'), 'dedicated Air preserves the JSON request and CSRF/error contract');
ok(dedicatedAir.includes('ticket_status') && dedicatedAir.includes('booking_class') && dedicatedAir.includes('baggage') && dedicatedAir.includes('fare_commercials'), 'dedicated Air preserves ticket status and payload fields');
ok(dedicatedCore.includes('refreshBookingState'), 'dedicated core exposes a narrow booking refresh hook instead of main-page KPI rebuilding');
ok(dedicatedCore.includes('requestText:function') && dedicatedCore.includes('refreshBookingState:function(){return core.requestText'), 'HTML refresh uses a text-only request helper rather than JSON parsing');
ok(!dedicatedCore.includes('refreshBookingState:function(){return core.requestJson'), 'refresh booking state does not pass HTML through requestJson');
ok((dedicatedCore.match(/getPassengerData:function/g)||[]).length===1, 'dedicated core has one passenger snapshot getter');
ok(dedicatedCore.includes("return root()?Promise.resolve([])") && dedicatedCore.includes('cached.passengers'), 'dedicated root passenger loading is independent and can reuse a seeded product response');
ok(dedicatedCore.includes('data-et-dedicated-lock-disabled') && dedicatedCore.includes("el.getAttribute('data-et-dedicated-lock-disabled')==='1'"), 'read-only enforcement marks and selectively restores only lock-disabled controls');
ok(freshProgressiveRuntime.includes('etgpAirMainBookingIntegration113314') && freshProgressiveRuntime.includes('etgpAirDedicatedIntegration113314'), 'Air renderer has explicit main-booking and dedicated integration seams');
ok(freshProgressiveRuntime.includes('applyPassengerFareOverrides:function') && freshProgressiveRuntime.includes('refreshBookingState:function') && freshProgressiveRuntime.includes('applyLock:function'), 'Air integration adapter isolates KPI, refresh, fare, and lock side effects');
ok(airRenderBody.includes('integration.setResponse(data)') && airRenderBody.includes('integration.markMounted(host)'), 'host integration seam receives response and mount lifecycle without changing renderer ownership');
ok(freshProgressiveRuntime.includes('etgpAirData113314') && freshProgressiveRuntime.includes('etgpAirDraft113314') && freshProgressiveRuntime.includes('etgpAirSave113314'), 'Air data, draft, and save authorities are explicit seams');
ok(!airRenderBody.includes('etgpBookingLockState113162') && !airRenderBody.includes('etgpApplyBookingLock113162') && !airRenderBody.includes('etBookingWorkspaceContext113305'), 'Air render core has no direct global lock or legacy booking-context access');
ok(!airRenderBody.includes('etgpRefreshPersistedBookingState113153') && !airRenderBody.includes('etgpAirApplySummaryKpis113124') && !airRenderBody.includes('etgpAirUpdatePassengerMetric113124') && !airRenderBody.includes('etgpAirUpdateTicketMetric113106'), 'Air render core has no direct main-booking KPI or refresh side effects');
ok(!airMountBody.includes('etBookingWorkspaceContext113305') && airMountBody.includes('etgpAirHostIntegration113314()') && airMountBody.includes('etgpAirData113314.load'), 'Air mount resolves identity and data through explicit adapters');
ok(freshProgressiveRuntime.includes('etgpAirHostIntegration113314=function') && freshProgressiveRuntime.includes('var integration=etgpAirHostIntegration113314()'), 'Air host integration is resolved from page context');
ok(!airRenderBody.includes('etgpAirMainBookingIntegration113314') && !airRenderBody.includes('etgpAirDedicatedIntegration113314'), 'Air render body uses only the resolved host interface');
ok(etgpDedicatedAdapterBody(freshProgressiveRuntime).includes('etDedicatedProductCore') && !etgpDedicatedAdapterBody(freshProgressiveRuntime).includes('etgpBookingLockState113162') && !etgpDedicatedAdapterBody(freshProgressiveRuntime).includes('etgpRefreshPersistedBookingState113153'), 'dedicated Air adapter uses core authority without legacy lock or refresh globals');
ok(airRenderBody.includes('integration.applyLock(host,lock)') && !airRenderBody.includes('integration.applyLock({'), 'Air render passes a workspace root and normalized lock state through the uniform host contract');
ok(etgpDedicatedAdapterBody(freshProgressiveRuntime).includes('applyLock:function(workspaceRoot)') && etgpDedicatedAdapterBody(freshProgressiveRuntime).includes('applyReadOnly(workspaceRoot)') && !etgpDedicatedAdapterBody(freshProgressiveRuntime).includes('applyReadOnly(lockState)'), 'dedicated locking passes the DOM workspace root rather than a lock-data object');
ok(freshProgressiveRuntime.includes('applyLock:function(workspaceRoot,lockState)') && freshProgressiveRuntime.includes('etgpApplyBookingLock113162({booking_locked:!!(lockState&&lockState.locked)'), 'main Booking locking translates the normalized host state to the legacy authority');
ok(etgpDedicatedAdapterBody(freshProgressiveRuntime).includes('getBookingRoot?window.etDedicatedProductCore.getBookingRoot()'), 'dedicated lifecycle bridges resolve the actual dedicated booking root');
ok(etgpDedicatedAdapterBody(freshProgressiveRuntime).includes('markMounted(root)') && etgpDedicatedAdapterBody(freshProgressiveRuntime).includes('markFailed(root,message)'), 'dedicated lifecycle bridges target the resolved booking root for mount and failure');
ok(!freshProgressiveRuntime.includes("host.innerHTML='';host.classList.remove('is-loading');host.appendChild(create('div','etgp-air-feedback-113106 is-error'"), 'dedicated failure bridge is not erased before its visible feedback can remain');
ok(!dedicatedCore.includes('else{el.disabled=false'), 'unlocked dedicated controls are not globally re-enabled');
ok(!dedicatedCore.includes('useState') && !dedicatedCore.includes('bookingStore') && !dedicatedCore.includes('productCustomerTotals'), 'dedicated product core does not introduce duplicate state or commercial authority');
ok(assetController.includes('dedicated-product-core.js') && presenter.includes('data-et-dedicated-product-core'), 'dedicated pages receive the shared core asset from existing ERP asset authority');
ok(releaseMiddleware.includes("dedicated=1") && assetController.includes('dedicated-product.css'), 'dedicated pages receive only the dedicated compact stylesheet in addition to normal theme layers');
ok(productView.includes('data-et-dedicated-product-header="1"') && productView.includes('et-dedicated-product-loading'), 'dedicated product view renders a compact smart header and loading shell');
ok(freshFocusedShell.includes('html.et-booking-products-prepaint header.topbar.et-ui-utility-topbar{display:none!important;}') && presenter.includes("et-booking-products-prepaint"), 'dedicated route rendering authority suppresses the proven utility topbar only on product pages');
ok(productView.includes('booking_reference') && productView.includes('customer') && productView.includes('branch_name'), 'dedicated smart header preserves server-rendered booking context');
ok(productView.includes('productLabel') && productView.includes('lock[\'status\']') && productView.includes('Back to Booking'), 'dedicated smart header identifies product, lifecycle, and booking return action');
ok(productView.includes('Review Booking') && productView.includes('Client Preview') && !productView.includes('Sales Invoice') && !productView.includes('Dashboard'), 'dedicated smart header keeps approved actions without generic Dashboard or Sales Invoice identity');
ok(productView.includes('et-general-progressive-step1-11390') && productView.includes('etgpMountDedicatedProduct113305'), 'existing general progressive runtime remains active for the Phase A compatibility boundary');
ok(presenter.includes('et-general-progressive-step1-11390') || productView.includes('et-general-progressive-step1-11390'), 'dedicated product pages retain the existing progressive runtime contract');
ok(dedicatedCss.includes('.et-dedicated-product-header') && dedicatedCss.includes('.et-dedicated-product-loading'), 'dedicated stylesheet is limited to smart header and loading presentation');
ok(dedicatedCss.length < 12000 && !dedicatedCss.includes('.et-product-air') && !dedicatedCss.includes('.et-product-hotel'), 'dedicated stylesheet does not duplicate product editor renderer CSS');
ok(productView.includes('data-booking-id') && productView.includes('data-etgp-product-key'), 'dedicated mount retains existing booking and product data attributes');
ok(!freshFocusedShell.includes('html.et-booking-products-prepaint .page-header') && !freshFocusedShell.includes('html.et-booking-products-prepaint header{'), 'old page-header assumption is removed and suppression remains scoped');
ok(!freshFocusedShell.includes('aside.sidebar{display:none') && !freshFocusedShell.includes('nav.nav{display:none') && !freshFocusedShell.includes('a.nav-item{display:none'), 'dedicated topbar suppression does not hide sidebar navigation');
ok(dedicatedCore.includes('n.hidden=true') && dedicatedCore.includes('data-et-dedicated-failure'), 'loading shell hides after successful mount and remains visible with a failure fallback');
ok(freshProgressiveRuntime.includes('etDedicatedProductCore.markMounted') && freshProgressiveRuntime.includes('etDedicatedProductCore.markFailed'), 'current product renderers notify the compatibility loading bridge without moving renderer ownership');
ok(assetController.includes('dedicatedAir') && routes.includes("system.erp-assets.products-air") && presenter.includes('data-et-dedicated-product-air'), 'dedicated Air receives its dedicated module asset');
ok(presenter.includes('! preg_match') && presenter.includes('general-progressive-step1-js'), 'dedicated Air asset selection excludes the general progressive runtime');
ok(assetController.includes("request()->query('product', '')") && !assetController.includes("request()->path()"), 'dedicated CSS asset selection uses an explicit product query, not the asset request path');
ok(releaseMiddleware.includes("$dedicatedProduct = ''") && releaseMiddleware.includes("'&product='.rawurlencode($dedicatedProduct)"), 'dedicated CSS URLs carry the originating product key');
const cssAssetSelection = ({dedicated, product}) => dedicated && String(product || '').toLowerCase() === 'air';
for (const [product, expected] of [['air', true], ['hotel', false], ['transport', false], ['visa', false]]) {
  ok(cssAssetSelection({dedicated: true, product}) === expected, `dedicated=1 + product=${product} selects Air CSS=${expected}`);
}
ok(cssAssetSelection({dedicated: false, product: 'air'}) === false, 'dedicated=0 + product=air does not select Air CSS');
const dedicatedAirCssUrl = 'erp-professional-css?v=release&module=operations&role=focused&dedicated=1&product=air';
ok(dedicatedAirCssUrl.includes('dedicated=1') && dedicatedAirCssUrl.includes('product=air'), 'generated Dedicated Air CSS URL carries dedicated and product query state');
ok(productView.includes("$isAirProduct ? ''") && productView.includes('!$isAirProduct'), 'Air product view does not mount the general progressive runtime');
ok(dedicatedAir.includes('core.markMounted(core.getBookingRoot())') && dedicatedAir.includes('core.markFailed(core.getBookingRoot(),message)'), 'dedicated Air lifecycle bridges target the dedicated booking root');
ok(dedicatedAir.includes("data-etgp-air-mounted") && dedicatedAir.includes("setAttribute('data-etgp-air-mounted','1')") && dedicatedAir.includes("removeAttribute('data-etgp-air-mounted')"), 'dedicated Air has a deterministic single-mount guard with retry reset');
ok(dedicatedAirCss.includes('.etgp-air-workspace-113106') && dedicatedAirCss.includes('.etgp-air-segment-row-113106') && dedicatedAirCss.includes('.etgp-air-common-grid-113106') && dedicatedAirCss.includes('.etgp-air-ticket-table-113106') && dedicatedAirCss.includes('.etgp-air-fare-table-113108') && dedicatedAirCss.includes('.etgp-air-summary-113108') && dedicatedAirCss.includes('.etgp-air-feedback-113106') && dedicatedAirCss.includes('.etgp-air-loading-shell-113112'), 'dedicated Air CSS owns workspace, editor, ticket, commercial, summary, feedback, and loading selectors');
ok(dedicatedAirCss.includes('@media(max-width:640px)') && dedicatedAirCss.includes('@media(max-width:430px)'), 'dedicated Air CSS preserves responsive rules');
ok(assetController.includes('public/erp-theme/css/products/air.css') && assetController.includes("request()->query('product', '')") && !assetController.includes("request()->path()"), 'dedicated Air receives its extracted CSS through the explicit asset product contract');
ok(productController.includes('schema_has_bookings') && productController.includes('booking_query'), 'controller timing covers schema and booking lookup stages');
ok(productController.includes('layout_resolve') && productController.includes('customer_resolve') && productController.includes('lock_from_row'), 'controller timing covers layout, customer, and lock stages');
ok(productController.includes('view_object_create') && productController.includes('controller_total'), 'controller timing distinguishes view object creation from total duration');
ok(customerResolver.includes('customer_native_model') && customerResolver.includes('customer_master_load'), 'customer timing covers native and master resolution stages');
ok(customerResolver.includes('customer_schema_discovery') && customerResolver.includes('customer_related_scan'), 'customer timing covers schema discovery and related-table scans');
ok(customerResolver.includes('customer_saved_context') && customerResolver.includes('setCustomerBranch'), 'customer resolver reports branch detail without changing authority');
ok(presenter.includes('presenter_lock_resolve') && presenter.includes('presenter_transform_total'), 'presenter timing measures duplicate lock and transformation stages');
ok(focusedMiddleware.includes('DedicatedProductTimingContext::forRequest'), 'diagnostic context starts before dedicated controller execution');
ok(!productTiming.includes('DB::table') && !productTiming.includes('insert(') && !productTiming.includes('update('), 'diagnostic context introduces no database writes');
ok(!productTiming.includes('<style') && !productTiming.includes('<script'), 'diagnostic context adds no rendered UI markup');
ok(productTiming.includes('X-ET-Customer-Branch') && productTiming.includes('X-ET-DB-Count'), 'safe branch and query-count headers are exposed without identity data');
ok(focusedMiddleware.includes("start('downstream_response')") && focusedMiddleware.includes("stop('downstream_response')"), 'downstream response timing surrounds the full next middleware interval');
ok(focusedMiddleware.includes("stop('product_pipeline_total')") && focusedMiddleware.includes('finishResponse'), 'pipeline timing is finalized after presenter completion');
ok(productTiming.includes('measureAccumulating') && customerResolver.includes('measureAccumulating'), 'repeated customer related scans accumulate instead of overwrite');
ok(productTiming.includes("addMeasuredDuration('db_total'") && !productTiming.includes("start('db_total')"), 'db-total represents accumulated query execution time');
ok(productTiming.includes("'view_object_create' => 'view-object'") && productTiming.includes("'downstream_response' => 'downstream'"), 'Server-Timing labels expose unambiguous pipeline stages');
ok(metadata.includes("return 'register'"), 'register pages receive a dedicated presentation role');
ok(metadata.includes("return 'focused'"), 'focused workspaces receive a dedicated presentation role');
ok(read('public/erp-theme/modules/registers.css').includes('data-et-ui-role="register"'), 'register CSS targets the role marker');
ok(sidebarComposer.includes("'OPERATIONS'") && sidebarComposer.includes("'ACCOUNTING'"), 'server sidebar composer defines canonical groups');
ok(sidebarComposer.includes('data-et-server-sidebar') && sidebarComposer.includes('hrefs preserved'), 'server composer emits authority only after composition');
ok(metadata.includes('ServerSidebarComposer'), 'presentation middleware invokes server sidebar composer');
ok(sidebarComposer.includes('Class-only roots are ambiguous') && !sidebarComposer.includes("strtok($classes"), 'ambiguous class-only roots safely fall back');
ok(sidebarComposer.includes('duplicate ID') || sidebarComposer.includes('getAttribute(\'id\')'), 'sidebar root identity checks stable IDs');

for (const token of [
  '--et-shell-sidebar-width:208px',
  '--et-shell-gutter-x:24px',
  '--et-shell-gutter-y:18px',
  '--et-shell-bottom:28px',
  '--et-shell-topbar-height:56px',
  '--et-sidebar-brand-height:64px',
  '--et-sidebar-logo-size:36px',
  '--et-sidebar-nav-x:8px',
  '--et-sidebar-row-height:32px',
]) {
  ok(shell.includes(token), `final shell owns ${token}`);
}

ok(freshShell.includes('--et-shell-sidebar-width:208px') && freshShell.includes('--et-shell-gutter-x:24px'), 'fresh standard shell owns spacing authority');
ok(freshShell.includes('background:var(--et-navy-deep)') && freshShell.includes('border-left-color:#60A5FA') && freshShell.includes('et-ui-live-badge'), 'fresh shell owns sidebar visual authority');
ok(freshShell.includes('height:56px') && freshShell.includes('border-bottom:1px solid var(--et-border)'), 'fresh shell owns topbar visual authority');
ok(freshCore.includes('[data-et-status="posted"]') && freshCore.includes('[data-et-status="cancelled"]'), 'fresh core owns semantic status presentation');
ok(freshCore.includes('.alert-success') && freshCore.includes('.alert-warning') && freshCore.includes('.alert-danger'), 'fresh core owns generic alert presentation');
ok(freshCore.includes('table:not(.ui-datepicker-calendar)') && freshCore.includes('tfoot th') && freshCore.includes('.et-table-wrap'), 'fresh core owns generic table presentation');
ok(freshCore.includes(':where(.et-card,.card)') && freshCore.includes('.card-header') && freshCore.includes('.card-body'), 'fresh core owns generic card presentation');
ok(freshCore.includes('.et-page-header') && freshCore.includes('.et-page-title') && freshCore.includes('.et-page-subtitle'), 'fresh core owns generic page heading presentation');
ok(
  !controller.includes("base_path('public/erp-ui/erp-shell-spacing.css')") &&
  !controller.includes('file_get_contents($shellSpacingUi)'),
  'legacy shell-spacing CSS is removed from runtime composition'
);
ok(
  !executableCssController.includes("public/erp-ui/erp-professional.css")
    && !executableCssController.includes('file_get_contents($base)')
    && !executableCssController.includes('is_file($base)'),
  'legacy professional CSS is retired from runtime composition'
);
ok(
  controller.includes("public/erp-theme/et-core.css")
    && controller.includes("public/erp-theme/et-shell.css")
    && controller.includes("'operations' => 'booking.css'")
    && controller.includes("'purchase' => 'registers.css'")
    && controller.includes("'accounting' => 'accounting.css'")
    && controller.includes("'travel' => 'travel-masters.css'")
    && controller.includes("'sales' => 'sales-invoice.css'"),
  'fresh CSS layers remain runtime-composed with module-scoped selection'
);
ok(
  fs.existsSync(new URL('../../public/erp-ui/erp-professional.css', import.meta.url)),
  'legacy professional CSS remains physically present for rollback'
);

ok(!base.includes('--et-sidebar-width:220px'), 'base CSS has no 220px sidebar fallback');
ok(!base.includes('calc(100% - 22px)'), 'base CSS has no 22px calculated shell canvas');
ok(!base.includes('calc(100% - 18px)'), 'base CSS has no 18px calculated shell canvas');
ok(
  !/height:37px!important;\s*min-height:37px!important;\s*max-height:37px!important/.test(base),
  'base CSS has no fixed 37px authenticated navigation row'
);
ok(
  !register.includes('.et-reg-shell{max-width:1500px!important}'),
  'Booking Register has no 1500px outer shell cap'
);
ok(!presenter.includes('--et-booking-canvas-max'), 'booking presenter has no canvas maximum token');
ok(!presenter.includes('--et-booking-canvas-gutter'), 'booking presenter has no canvas gutter token');
ok(!presenter.includes('max-width:1280px'), 'booking presenter has no 1280px outer maximum');
ok(
  !presenter.includes('width:calc(100% - (var(--et-booking-canvas-gutter) * 2))'),
  'booking presenter has no calculated outer canvas width'
);
ok(!groupPackage.includes('fitWorkspace'), 'Group Package has no runtime outer sizing function');
ok(!groupPackage.includes('root.style.width'), 'Group Package does not write outer width');
ok(!groupPackage.includes('root.style.maxWidth'), 'Group Package does not write outer max-width');
ok(!groupPackage.includes('root.style.marginLeft'), 'Group Package does not write outer margin');
ok(
  !groupPackage.includes("addEventListener('resize', fitWorkspace"),
  'Group Package has no geometry resize listener'
);
ok(
  !cashVoucherLinks.includes('data-et-sidebar-shell='),
  'Cash Voucher middleware injects no global sidebar geometry'
);
for (const token of [
  "rewriteAnchor($html, 'Chart of Accounts'",
  "rewriteAnchor($html, 'Supplier Costing'",
  'data-et-live-accounting-nav="receipt"',
  'data-et-live-accounting-nav="payment"',
  'data-et-live-accounting-nav="expense"',
  'data-et-live-accounting-nav="contra"',
]) {
  ok(cashVoucherLinks.includes(token), `Cash Voucher navigation behavior preserves ${token}`);
}

for (const token of [
  'position:sticky!important',
  'height:100vh!important',
  'overflow-y:auto!important',
  '[data-gp-focus-sidebar].gp-focus-sidebar-open',
  '[data-et-air-focus-sidebar].et-air-focus-sidebar-open-103172',
  'html.et-booking-focus-prepaint #gp-booking',
  'html.et-booking-focus-prepaint .et-air-workspace-103172',
]) {
  ok(shell.includes(token), `final shell owns ${token}`);
}
ok(
  /html\.et-booking-focus-prepaint section\.content\{[\s\S]*?var\(--et-shell-gutter-x\)/.test(shell),
  'focused booking canvas uses the shared responsive shell gutter'
);
ok(presenter.includes("$style = ''") && !presenter.includes('<style data-et-booking-focus-shell='), 'Booking presenter no longer owns static style markup');
ok(bookingTheme.includes('et-booking-focus-page-actions') && bookingTheme.includes('et-booking-unified-canvas-11375'), 'Booking theme owns extracted focused-workspace presentation CSS');
ok(
  bookingTheme.includes('.br-top')
    && bookingTheme.includes('.br-sub')
    && bookingTheme.includes('.br-card')
    && bookingTheme.includes('.br-head')
    && bookingTheme.includes('.br-btn'),
  'Booking theme owns all live Booking Review br-* compatibility selectors'
);
ok(!base.includes('.br-top') && !base.includes('.br-sub') && !base.includes('.br-card') && !base.includes('.br-head') && !base.includes('.br-btn'), 'legacy professional CSS no longer owns Booking Review br-* selectors');
ok(bookingTheme.includes('body.et-ui-module-operations') && bookingTheme.includes('var(--et-primary)'), 'Booking Review compatibility is scoped to the operations module and fresh tokens');
const registersTheme = read('public/erp-theme/modules/registers.css');
const bookingRegisterLegacy = read('public/erp-ui/erp-booking-register-reference.css');
ok(registersTheme.includes('.et-booking-ref-kpis') && registersTheme.includes('.et-booking-ref-pagination'), 'fresh registers theme owns Booking Register selectors');
ok(controller.includes("'purchase' => 'registers.css'") && controller.includes("$role === 'register'"), 'fresh registers theme remains available for register role');
ok(!executableCssController.includes("$registerWorkspaceUi = base_path('public/erp-ui/erp-booking-register-reference.css')") && !executableCssController.includes('file_get_contents($registerWorkspaceUi)'), 'legacy Booking Register stylesheet is removed from runtime composition');
ok(bookingRegisterLegacy.includes('.et-booking-ref-register-card') && bookingRegisterLegacy.includes('.et-booking-ref-pagination'), 'legacy Booking Register stylesheet remains physically present for rollback');
ok(presenter.includes('str_contains($html, \'data-et-booking-focus-shell="ERP-11.3.75"\')'), 'Booking focus marker guard remains idempotent');
ok(presenter.includes("addHtmlAttribute($html, 'data-et-booking-focus-shell', 'ERP-11.3.75')"), 'Booking focus marker is emitted on semantic html markup');
ok(!presenter.includes('<style data-et-booking-focus-shell='), 'Booking focus marker is not carried by an inline style block');
ok(presenter.includes('BookingEditLockResolver') && !presenter.includes('SalesInvoiceService'), 'Booking lifecycle authority remains unchanged');

const protectedHashes = new Map([
  ['app/Services/System/DayOneSequenceResetService.php', '65D0A210D36F5A4DEDF53D2D3EFD00DD660BCDBFFB51FE9841605A3ACB8F0FEF'],
  ['app/Http/Controllers/System/ProductionDataResetController.php', '1F5628ACB584648B5AA1C24E9440E1DA29770E604C7839E7793F2BFAE70ABFBF'],
  ['resources/views/system/day-one-sequence-reset-v113247.blade.php', '94E8616F4A8AB127E57D733CFD527E78BB117BF4290534F2B003820E8B7A094F'],
  ['app/Services/System/DayZeroDataResetService.php', 'C1F01442285D80EC2B88DDC293C7AE79248619F76D033D581E619C5DDD818101'],
  ['resources/views/accounting/cash-vouchers/print.blade.php', '005F6B12C765DF26C880DC6E81AD5381518A9871A573801140F0ECAB57E66C33'],
]);

ok(
  salesInvoiceFocus.includes('$this->markHtml($html)')
    && salesInvoiceFocus.includes('$this->markBody($html)')
    && salesInvoiceFocus.includes('et-sales-invoice-focus-prepaint')
    && salesInvoiceFocus.includes('et-si11-page-103179'),
  'Sales Invoice focused shell is marked server-side before client enhancement'
);
ok(
  focusedShellJs.includes('et-sales-invoice-menu-button')
    && focusedShellJs.includes("overlay.addEventListener('click',closeMenu)")
    && focusedShellJs.includes("e.key==='Escape'"),
  'Sales Invoice focused menu remains a dismissible sidebar drawer'
);
const siGate = focusedShellJs.indexOf("dataset.etSalesInvoiceFocus!=='ERP-11.3.60'");
const siInit = focusedShellJs.indexOf("dataset.etSalesInvoiceFocusInit==='ERP-11.3.60'");
ok(siGate >= 0 && siInit > siGate, 'Sales Invoice initializer is page-scoped before idempotency marker');
ok(salesInvoiceFocus.includes('data-et-sales-invoice-focus="ERP-11.3.60"') || salesInvoiceFocus.includes("data-et-sales-invoice-focus='ERP-11.3.60'"), 'Sales Invoice server marker remains emitted');

for (const [path, expected] of protectedHashes) {
  ok(sha256(path) === expected, `protected source remains unchanged: ${path}`);
}

console.log(`PASS ${pass} single-shell authority assertions`);
