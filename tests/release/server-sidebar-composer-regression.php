<?php
require dirname(__DIR__, 2).'/app/Services/Operations/ServerSidebarComposer.php';

function check(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$name}\n");
        exit(1);
    }
    echo "PASS {$name}\n";
}

$composer = new App\Services\Operations\ServerSidebarComposer();

// Supported UL/LI native shape remains covered.
$ul = '<aside class="sidebar"><ul class="sidebar-menu"><li><a href="/dashboard">Dashboard</a></li><li><a href="/accounting">Reports</a></li><li><a href="/system/health">Health &amp; Updates</a></li></ul></aside>';
$ulOut = $composer->compose($ul);
check(substr_count($ulOut, 'href="/travel-reports">Travel Reports</a>') === 1, 'supported UL Travel Reports count');
check(strpos($ulOut, 'Movement Reports') === false, 'supported UL Movement Reports absent');

// Exact production DOM fixture: direct nav-section headings and sibling links.
$productionFixture = '<aside class="sidebar"><div class="brand">Easy Ticket</div><nav class="nav">'
    .'<a class="nav-item" href="/">Dashboard</a><div class="nav-section">MASTER DATA</div>'
    .'<a class="nav-item" href="/master-data/parties">Party Master</a><div class="nav-section">OPERATIONS</div>'
    .'<a class="nav-item" href="/operations/bookings">Bookings</a><div class="nav-section">ACCOUNTING</div>'
    .'<a class="nav-item" href="/accounting/chart-of-accounts-workspace">Chart of Accounts</a>'
    .'<a class="nav-item" href="/accounting/reports">Reports</a><div class="nav-section">SYSTEM</div>'
    .'<a class="nav-item" href="/system/update">Health &amp; Updates</a></nav>'
    .'<div class="sidebar-foot">ERP-11.3.350</div></aside>';
$productionOut = $composer->compose($productionFixture);
check(substr_count($productionOut, 'href="/travel-reports"') === 1, 'production Travel Reports count');
check(substr_count($productionOut, 'class="nav-section">REPORTS</div>') === 1, 'production Reports heading count');
check(substr_count($productionOut, 'Movement Reports') === 0, 'production Movement Reports count');
$pAccounting = strpos($productionOut, '>ACCOUNTING<'); $pReports = strpos($productionOut, '>REPORTS<');
$pTravel = strpos($productionOut, 'Travel Reports'); $pSystem = strpos($productionOut, '>SYSTEM<');
check($pAccounting !== false && $pReports !== false && $pTravel !== false && $pSystem !== false && $pAccounting < $pReports && $pReports < $pTravel && $pTravel < $pSystem, 'production order');
check($composer->compose($productionOut) === $productionOut, 'production idempotence');
check(strpos($productionOut, '/accounting/chart-of-accounts-workspace') !== false, 'production accounting links preserved');
check(strpos($productionOut, '/system/update') !== false && strpos($productionOut, 'sidebar-foot') !== false, 'production system and footer preserved');

// Unsupported historical wrapper shapes fail closed.
$semantic = '<aside class="sidebar"><div class="native-menu"><div class="section"><span>ACCOUNTING</span></div><div class="section"><span>SYSTEM</span></div></div></aside>';
check($composer->compose($semantic) === $semantic, 'legacy semantic fixture fail closed');
$unsafe = '<aside class="sidebar"><nav class="native-menu"><div class="section"><span>ACCOUNTING</span></div><div class="section"><span>SYSTEM</span></div></nav></aside>';
check($composer->compose($unsafe) === $unsafe, 'legacy native-menu fixture fail closed');
$footerOutsideNav = '<aside class="sidebar"><nav class="native-menu"><div class="section"><span>ACCOUNTING</span></div><div class="section"><span>SYSTEM</span></div></nav><div class="release-footer">ERP-11.3.350</div></aside>';
check($composer->compose($footerOutsideNav) === $footerOutsideNav, 'legacy footer fixture fail closed');
$noSystem = '<aside class="sidebar"><nav class="nav"><div class="nav-section">ACCOUNTING</div></nav></aside>';
check($composer->compose($noSystem) === $noSystem, 'missing System fail closed');

// Raw target adversaries.
$outsideNavbar = '<nav class="navbar"><a href="/outside">Outside Navbar</a></nav>'.$productionFixture;
$outsideNavbarOut = $composer->compose($outsideNavbar);
check(strpos($outsideNavbarOut, '<nav class="navbar"><a href="/outside">Outside Navbar</a></nav>') !== false, 'outside navbar preserved');
check(substr_count($outsideNavbarOut, 'href="/travel-reports"') === 1, 'outside navbar sidebar mutated');
$outsideNavigation = '<nav class="navigation"><a href="/outside-two">Outside Navigation</a></nav>'.$productionFixture;
$outsideNavigationOut = $composer->compose($outsideNavigation);
check(strpos($outsideNavigationOut, '<nav class="navigation"><a href="/outside-two">Outside Navigation</a></nav>') !== false, 'outside navigation preserved');
check(substr_count($outsideNavigationOut, 'href="/travel-reports"') === 1, 'outside navigation sidebar mutated');
$outsideExactNav = '<nav class="nav"><a href="/outside-exact">Outside Exact Nav</a></nav>'.$productionFixture;
$outsideExactNavOut = $composer->compose($outsideExactNav);
check(strpos($outsideExactNavOut, '<nav class="nav"><a href="/outside-exact">Outside Exact Nav</a></nav>') !== false, 'outside exact nav preserved');
check(substr_count($outsideExactNavOut, 'href="/travel-reports"') === 1, 'inside sidebar nav selected');
$multiClassFixture = str_replace('<aside class="sidebar">', '<aside class="shell sidebar collapsed">', str_replace('<nav class="nav">', '<nav class="primary nav flex-column">', $productionFixture));
$multiClassOut = $composer->compose($multiClassFixture);
check(substr_count($multiClassOut, 'href="/travel-reports"') === 1, 'multi-class exact token');
$sidebarExtra = str_replace('<aside class="sidebar">', '<aside class="sidebar-extra">', $productionFixture);
check($composer->compose($sidebarExtra) === $sidebarExtra, 'sidebar-extra rejected');
$ambiguousSidebarNav = str_replace('</nav><div class="sidebar-foot">', '<nav class="nav"><div class="nav-section">ACCOUNTING</div><div class="nav-section">SYSTEM</div></nav></nav><div class="sidebar-foot">', $productionFixture);
check($composer->compose($ambiguousSidebarNav) === $ambiguousSidebarNav, 'ambiguous sidebar nav fail closed');

$rootLinks = ['/travel-reports', '/travel-reports/', 'https://erp.easyticket.pk/travel-reports', 'https://erp.easyticket.pk/travel-reports/', '/travel-reports?source=sidebar'];
foreach ($rootLinks as $href) {
    $fixture = str_replace('href="/accounting/reports"', 'href="'.$href.'"', $productionFixture);
    $out = $composer->compose($fixture);
    check(substr_count($out, 'Travel Reports') === 1, 'canonical root href recognized: '.$href);
}
$movementFixture = str_replace('href="/accounting/reports"', 'href="/travel-reports/group-umrah/arrival"', $productionFixture);
$movementOut = $composer->compose($movementFixture);
check(substr_count($movementOut, 'href="/travel-reports"') === 1, 'movement route is not root Travel Reports');

echo "PASS server sidebar composer regression\n";
