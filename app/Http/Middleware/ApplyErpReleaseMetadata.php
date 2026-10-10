<?php

namespace App\Http\Middleware;

use App\Support\Release\Erp11310ObsoleteFileCleaner;
use App\Support\Release\Erp11330StabilizationCleaner;
use App\Services\Operations\ServerSidebarComposer;
use App\Services\Operations\NativeErpLayoutResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Operations\DedicatedProductTimingContext;

/**
 * ERP-11.3.30
 *
 * Keeps legacy hard-coded release labels from reporting ERP-10.28.4 forever.
 *
 * This middleware does not alter business data. It only normalizes rendered
 * HTML metadata/status labels to the currently deployed release.
 *
 * Database status is only upgraded visually when the required tables/columns
 * really exist.
 */
class ApplyErpReleaseMetadata
{
    public function handle(Request $request, Closure $next): Response
    {
        $timing = DedicatedProductTimingContext::fromRequest($request);
        $timing?->start('release_metadata');
        $timing?->start('release_pre');
        $timing?->stop('release_pre');
        $timing?->start('release_downstream');
        /** @var Response $response */
        $response = $next($request);
        $timing?->stop('release_downstream');
        $timing?->start('release_response');

        // ERP-11.3.10: remove only superseded overlay files after the
        // canonical Chart workspace has been deployed successfully.
        app(Erp11310ObsoleteFileCleaner::class)->run();
        app(Erp11330StabilizationCleaner::class)->run();

        /*
         * ERP-11.3.30 — Dashboard native-finance synchronization.
         *
         * Operational dashboard widgets remain on their existing native data
         * sources. Only accounting-derived values are synchronized from posted
         * journal_entries / journal_lines after the native dashboard renders.
         */
        try {
            $response = app(PresentDashboardFinancialSnapshot::class)->handle(
                $request,
                static fn () => $response
            );
        } catch (\Throwable $e) {
            report($e);
        }

        /*
         * ERP-11.3.239 — Final register markup is produced server-side before
         * professional CSS/JS is injected. Native controllers still own the
         * rows, permissions and workflow; only the rendered register canvas is
         * normalized here. This removes the old-page -> new-page paint jump.
         */
        try {
            $response = app(PresentUnifiedRegisterWorkspace::class)->present(
                $request,
                $response
            );
        } catch (\Throwable $e) {
            report($e);
        }

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            $timing?->stop('release_response');
            $timing?->stop('release_metadata');
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));

        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            $timing?->stop('release_response');
            $timing?->stop('release_metadata');
            return $response;
        }

        $html = (string) $response->getContent();

        if ($html === '') {
            $timing?->stop('release_response');
            $timing?->stop('release_metadata');
            return $response;
        }

        $release = config('et_erp_release', []);
        $version = (string) ($release['version'] ?? 'v1.1.33.0-ERP11.3');
        $assetVersion = (string) ($release['asset_version'] ?? $version);
        $releaseName = (string) ($release['release'] ?? 'ERP');
        $correctiveBuild = (string) ($release['corrective_build'] ?? '');
        $correctiveName = (string) ($release['corrective_name'] ?? '');
        $package = (string) ($release['package'] ?? 'ERP-11.3 Unified Travel ERP');
        $packageDetail = (string) ($release['package_detail'] ?? 'Native one-page Group Package flow + dynamic release health');

        /*
         * Exact legacy labels currently visible in the live ERP shell and
         * System Health page.
         */
        $replacements = [
            'v1.1.28.4-ERP10.28.4' => $version,
            'ERP-10.28.4 Group Package Operational-Only Service Pricing' => $package,
            'ERP-10.28.4 Group Package' => $package,
            'Operational-Only Service Pricing' => $packageDetail,
        ];

        $html = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $html
        );

        // C42: the native /system/update host can render its sidebar and
        // Health content as sibling BODY nodes instead of the authenticated
        // ERP shell. Normalize that response server-side before the canonical
        // sidebar composer processes the final sidebar.
        $html = $this->normalizeSystemHealthShell($request, $html);
        $html = $this->normalizeCorrectiveBuildIdentity(
            $request,
            $html,
            $version,
            $assetVersion,
            $package,
            $releaseName,
            $correctiveBuild,
            $correctiveName
        );

        try {
            $html = app(ServerSidebarComposer::class)->compose($html);
        } catch (\Throwable $e) {
            report($e);
        }

        // ERP-11.3.378: compact only the native sidebar footer block.
        $html = $this->compactSidebarReleaseBlock($html, $package, $version, $releaseName, $correctiveBuild, $correctiveName);

        $html = $this->normalizeTravelReportHostTitle($request, $html);
        $html = $this->injectProfessionalUi($request, $html, $version, $assetVersion);

        /*
         * Only calculate DB readiness on the actual System Health page.
         */
        if (
            str_contains($html, 'System Health &amp; Updates')
            || str_contains($html, 'System Health & Updates')
            || str_contains($html, 'Safe web-based application maintenance')
        ) {
            $migrationStatus = $this->migrationStatus($release);
            $statusMessage = match ($migrationStatus['status']) {
                'current' => 'Database schema is current — no pending migrations.',
                'pending' => 'Database upgrade pending — '.(int) ($migrationStatus['pending_count'] ?? 0).' migration(s) require execution.',
                default => 'Database migration status could not be verified.',
            };

            // C36: make the migration action state server-authoritative before
            // the enhancement bundle paints. Current and unknown states fail
            // closed; pending remains actionable. The C69 calculation above
            // is intentionally untouched.
            $html = $this->normalizeMigrationPresentation($html, $migrationStatus['status'], $statusMessage);

            foreach ([
                'Database is current through ERP-10.1.',
                'Database is current through ERP-10.1',
                'Database is current through ERP-11.3.',
                'Database is current through ERP-11.3',
                'Database schema is up to date.',
                'Database schema is up to date',
            ] as $legacyStatus) {
                $html = str_replace($legacyStatus, $statusMessage, $html);
            }

            /*
             * ERP-10.31.72
             * Add a safe maintenance entry point without replacing the native
             * System Health controller/view.
             */
            try {
                if (
                    ! str_contains(
                        $html,
                        'data-et-production-reset="ERP-10.31.72"'
                    )
                    && app('router')->has(
                        'system.production-data-reset.index'
                    )
                ) {
                    $resetUrl = route(
                        'system.production-data-reset.index'
                    );

                    $panel = '<section data-et-production-reset="ERP-10.31.72" data-et-dangerous-actions="true">'
                        .'<div class="et-dangerous-kicker">Advanced</div>'
                        .'<div class="et-dangerous-title">Dangerous Actions</div>'
                        .'<p class="et-dangerous-copy">Restricted production reset and financial reconciliation tools. Use only through an approved maintenance procedure.</p>'
                        .'<div class="et-dangerous-actions"><a href="'.e($resetUrl).'">Production Transaction Reset</a>'
                        .'<a href="'.e(route('system.post-reset-financial-cleanup.index')).'">Post-Reset Financial Cleanup</a></div>'
                        .'</section>';

                    if (str_contains($html, '</main>')) {
                        $html = str_replace(
                            '</main>',
                            $panel.'</main>',
                            $html
                        );
                    } else {
                        $html = str_replace(
                            '</body>',
                            $panel.'</body>',
                            $html
                        );
                    }
                }
            } catch (\Throwable) {
            }
        }

        $response->setContent($html);

        $timing?->stop('release_response');
        $timing?->stop('release_metadata');
        return $response;
    }

    /** Keep native System Health inside the same server-rendered ERP shell. */
    private function normalizeSystemHealthShell(Request $request, string $html): string
    {
        $path = strtolower(trim($request->path(), '/'));
        if ($path !== 'system/update' && $path !== 'system/health') return $html;
        if (! class_exists(\DOMDocument::class)) return $html;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) return $html;

        $xpath = new \DOMXPath($dom);
        $body = $xpath->query('//body')->item(0);
        if (! $body instanceof \DOMElement) return $html;

        $classHas = static fn (\DOMElement $node, string $class): bool => in_array(
            $class,
            preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [],
            true
        );
        $appShell = null;
        foreach ($xpath->query('.//*', $body) as $node) {
            if ($node instanceof \DOMElement && $classHas($node, 'app-shell')) {
                $appShell = $node;
                break;
            }
        }
        if (! $appShell instanceof \DOMElement) return $html;

        $sidebar = null;
        foreach ($appShell->childNodes as $child) {
            if ($child instanceof \DOMElement && ($classHas($child, 'sidebar') || $classHas($child, 'sidebar-menu') || $classHas($child, 'side-nav') || $classHas($child, 'navbar-vertical'))) {
                $sidebar = $child;
                break;
            }
        }
        if (! $sidebar instanceof \DOMElement) return $html;

        $main = null;
        foreach ($appShell->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'main' && $classHas($child, 'main')) {
                $main = $child;
                break;
            }
        }
        if (! $main instanceof \DOMElement) {
            $main = $dom->createElement('main');
            $main->setAttribute('class', 'main');
            $appShell->appendChild($main);
        }
        // If the native sidebar shell is empty, render the authenticated native
        // ERP layout authority and adopt its existing sidebar. Never manufacture
        // hard-coded URLs or mine unrelated Health content for navigation.
        $hasSidebarLink = $xpath->query('.//a[@href]', $sidebar)->length > 0;
        if (! $hasSidebarLink) {
            $nativeSidebar = $this->renderNativeSidebar($dom);
            if ($nativeSidebar instanceof \DOMNode) {
                $nativeIsFrame = $nativeSidebar instanceof \DOMElement && $this->isSidebarFrame($nativeSidebar);
                $nativeIsSurface = $nativeSidebar instanceof \DOMElement && strtolower($nativeSidebar->tagName) === 'nav';
                if ($nativeIsFrame && $sidebar->parentNode === $appShell) {
                    $appShell->replaceChild($nativeSidebar, $sidebar);
                    $sidebar = $nativeSidebar;
                    $hasSidebarLink = true;
                } elseif ($nativeIsSurface) {
                    $nav = null;
                    foreach ($sidebar->childNodes as $child) {
                        if ($child instanceof \DOMElement && strtolower($child->tagName) === 'nav') {
                            $nav = $child;
                            break;
                        }
                    }
                    if ($nav instanceof \DOMElement && $nav->parentNode === $sidebar) {
                        $sidebar->replaceChild($nativeSidebar, $nav);
                        $hasSidebarLink = true;
                    }
                }
            }
        }

        // Move only positively identified Health presentation nodes into the
        // shell main canvas; framework overlays and unrelated body containers
        // retain their original authority.
        $outside = [];
        foreach (iterator_to_array($body->childNodes) as $child) {
            if (! $child instanceof \DOMElement || $child === $appShell || in_array(strtolower($child->tagName), ['script', 'style', 'link'], true)) continue;
            if ($this->isSystemHealthNode($child)) $outside[] = $child;
        }
        foreach ($outside as $child) $main->appendChild($child);

        $normalized = $dom->saveHTML();
        return $normalized;
    }

    /** Render and extract the authenticated sidebar from the canonical native layout. */
    private function renderNativeSidebar(\DOMDocument $target): ?\DOMNode
    {
        try {
            $layoutMeta = app(NativeErpLayoutResolver::class)->resolve();
            $layout = (string) ($layoutMeta['layout'] ?? '');
            if ($layout === '' || ! view()->exists($layout)) return null;
            $rendered = view($layout, ['layoutMeta' => $layoutMeta, 'content' => '', 'slot' => ''])->render();
            $source = new \DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $loaded = $source->loadHTML('<?xml encoding="UTF-8">'.$rendered, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (! $loaded) return null;
            $sourceXpath = new \DOMXPath($source);
            foreach ($sourceXpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " sidebar ") or contains(concat(" ", normalize-space(@class), " "), " sidebar-menu ") or contains(concat(" ", normalize-space(@class), " "), " side-nav ") or contains(concat(" ", normalize-space(@class), " "), " navbar-vertical ")]') as $candidate) {
                if ($candidate instanceof \DOMElement && $sourceXpath->query('.//a[@href]', $candidate)->length > 0) return $target->importNode($candidate, true);
            }
        } catch (\Throwable) {
            return null;
        }
        return null;
    }

    private function isSidebarFrame(\DOMElement $node): bool
    {
        $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
        return (bool) array_intersect(['sidebar', 'sidebar-menu', 'side-nav', 'navbar-vertical'], $classes);
    }

    /** Keep only known System Health presentation nodes in the shell canvas. */
    private function isSystemHealthNode(\DOMElement $node): bool
    {
        foreach (['data-et-health-section', 'data-et-dangerous-actions', 'data-et-production-reset'] as $attribute) if ($node->hasAttribute($attribute)) return (bool) $node->hasAttribute($attribute);
        $text = strtolower(trim(preg_replace('/\s+/', ' ', $node->textContent)));
        foreach (['application cache', 'database maintenance', 'safe web-based application maintenance', 'dangerous actions', 'migration status', 'system health', 'erp-10.1 ticket commercial boundary', 'ticket-level sale, purchase and commissions are visible'] as $marker) if (str_contains($text, $marker)) return str_contains($text, $marker);
        $classes = strtolower(' '.trim($node->getAttribute('class')).' ');
        foreach ([' health ', ' system-health ', ' maintenance '] as $class) if (str_contains($classes, $class)) return str_contains($classes, $class);
        return false;
    }

    /** Keep the native shell title route-aware without rewriting report headings. */
    private function normalizeTravelReportHostTitle(Request $request, string $html): string
    {
        if (! str_starts_with(strtolower(trim($request->path(), '/')), 'travel-reports')) {
            return $html;
        }
        // The native title is the Dashboard heading in the unique header that
        // also carries the current company identity. Do not infer it from a
        // guessed class or replace every Dashboard string in the document.
        $headerPattern = '/<header\b[^>]*>.*?<\/header>/is';
        $matches = [];
        preg_match_all($headerPattern, $html, $matches, PREG_OFFSET_CAPTURE);
        $targets = [];
        foreach ($matches[0] ?? [] as $match) {
            if (stripos($match[0], 'Easy Group Of Travels') === false) {
                continue;
            }
            if (preg_match('/(>)[\s]*Dashboard[\s]*(<)/i', $match[0])) {
                $targets[] = $match;
            }
        }
        if (count($targets) !== 1) {
            return $html;
        }
        [$fragment, $offset] = $targets[0];
        $updated = preg_replace('/(>)[\s]*Dashboard[\s]*(<)/i', '$1Travel Reports$2', $fragment, 1, $count);
        if ($count !== 1 || $updated === null) {
            return $html;
        }
        return substr($html, 0, $offset).$updated.substr($html, $offset + strlen($fragment));
    }

    /**
     * Place corrective identity only inside the uniquely identified Health
     * Application card.  The native Health page repeats the release/version
     * elsewhere (sidebar, page chrome and other cards), so a first-occurrence
     * insertion is not a safe authority.
     */
    private function normalizeCorrectiveBuildIdentity(
        Request $request,
        string $html,
        string $version,
        string $assetVersion,
        string $package,
        string $releaseName,
        string $correctiveBuild,
        string $correctiveName
    ): string {
        $path = strtolower(trim($request->path(), '/'));
        if (($path !== 'system/update' && $path !== 'system/health') || $correctiveBuild === '') {
            return $html;
        }

        if (! class_exists(\DOMDocument::class)) {
            return $html;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($dom);
        $applicationCards = [];
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " metric-card ")]') as $card) {
            if (! $card instanceof \DOMElement) {
                continue;
            }
            $labels = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " metric-label ")]', $card);
            $values = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " metric-value ")]', $card);
            $isApplication = false;
            foreach ($labels as $label) {
                if (strtoupper(trim(preg_replace('/\s+/', ' ', $label->textContent))) === 'APPLICATION') {
                    $isApplication = true;
                    break;
                }
            }
            $hasVersion = false;
            foreach ($values as $value) {
                if (stripos((string) $value->textContent, $version) !== false) {
                    $hasVersion = true;
                    break;
                }
            }
            if ($isApplication && $hasVersion) {
                $applicationCards[spl_object_hash($card)] = $card;
            }
        }

        if (count($applicationCards) !== 1) {
            return $html;
        }
        $applicationCard = array_values($applicationCards)[0];
        $versionNode = null;
        foreach ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " metric-value ")]', $applicationCard) as $value) {
            if (stripos((string) $value->textContent, $version) !== false) {
                $versionNode = $value;
                break;
            }
        }
        if (! $versionNode instanceof \DOMElement) {
            return $html;
        }

        foreach ($xpath->query('.//*[@data-et-corrective-build]', $applicationCard) as $existing) {
            if ($existing instanceof \DOMElement && $existing->parentNode) {
                $existing->parentNode->removeChild($existing);
            }
        }

        foreach ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " metric-note ")]', $applicationCard) as $note) {
            while ($note->firstChild) {
                $note->removeChild($note->firstChild);
            }
            $note->appendChild($dom->createTextNode($releaseName));
            break;
        }

        $assetRevision = preg_match('/(C\d+)$/i', $assetVersion, $match) === 1
            ? strtoupper($match[1])
            : $assetVersion;
        $identity = $dom->createElement('div');
        $identity->setAttribute('data-et-corrective-build', $correctiveBuild);
        $identity->setAttribute('data-et-corrective-name', $correctiveName);
        $identity->setAttribute('data-et-asset-revision', $assetRevision);
        $identity->setAttribute('class', 'et-corrective-build-identity');
        $identity->appendChild($dom->createElement('div', $releaseName));
        $buildLine = $dom->createElement('div');
        $buildLine->appendChild($dom->createElement('strong', 'Build '.$correctiveBuild));
        if ($correctiveName !== '') {
            $buildLine->appendChild($dom->createTextNode(' · '.$correctiveName));
        }
        $identity->appendChild($buildLine);
        $identity->appendChild($dom->createElement('div', 'Asset '.$assetRevision));

        if ($versionNode->parentNode) {
            if ($versionNode->nextSibling) {
                $versionNode->parentNode->insertBefore($identity, $versionNode->nextSibling);
            } else {
                $versionNode->parentNode->appendChild($identity);
            }
        } else {
            return $html;
        }

        $normalized = $dom->saveHTML();
        return $normalized === false ? $html : $normalized;
    }

    private function compactSidebarReleaseBlock(
        string $html,
        string $package,
        string $version,
        string $releaseName,
        string $correctiveBuild,
        string $correctiveName
    ): string
    {
        $pattern = '/<aside\b[^>]*class=("|\')[^"\']*\bsidebar\b[^"\']*\1[^>]*>.*?<\/aside>/is';
        $matches = [];
        preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);
        if (count($matches[0] ?? []) !== 1) {
            return $html;
        }
        [$fragment, $offset] = $matches[0][0];
        if ($releaseName === '' || $version === '') {
            return $html;
        }

        // The historical "Party Balances" label is intentionally replaced
        // by the configured release/build identity below.
        // C65's former Live-anchor insertion is deliberately not required:
        // native sidebar-foot markup may contain only .version elements.
        if ($correctiveBuild !== '' && ! str_contains($fragment, 'data-et-sidebar-corrective-build=')) {
            // Marker insertion is now structurally bounded to sidebar-foot.
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><div id="et-sidebar-scope">'.$fragment.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return $html;
        }
        $xpath = new \DOMXPath($dom);
        $feet = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " sidebar-foot ")]');
        if ($feet->length !== 1) {
            return $html;
        }
        $foot = $feet->item(0);
        foreach ($xpath->query('.//*[@data-et-sidebar-corrective-build]', $foot) as $existing) {
            if ($existing instanceof \DOMElement && $existing->parentNode) {
                $existing->parentNode->removeChild($existing);
            }
        }
        $versions = [];
        foreach ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " version ")]', $foot) as $versionNode) {
            if ($versionNode instanceof \DOMElement) {
                $versions[] = $versionNode;
            }
        }
        if (count($versions) < 2) {
            return $html;
        }
        while (count($versions) > 2) {
            $extra = array_pop($versions);
            $extra->parentNode?->removeChild($extra);
        }
        while ($versions[0]->firstChild) {
            $versions[0]->removeChild($versions[0]->firstChild);
        }
        $versions[0]->appendChild($dom->createTextNode($releaseName));
        while ($versions[1]->firstChild) {
            $versions[1]->removeChild($versions[1]->firstChild);
        }
        $versions[1]->setAttribute('data-et-sidebar-corrective-build', $correctiveBuild);
        $versions[1]->appendChild($dom->createTextNode('Build '.$correctiveBuild));

        $scope = $dom->getElementById('et-sidebar-scope');
        if (! $scope) {
            return $html;
        }
        $updated = '';
        foreach ($scope->childNodes as $child) {
            $updated .= $dom->saveHTML($child);
        }
        if ($updated === '') {
            return $html;
        }
        return substr($html, 0, $offset).$updated.substr($html, $offset + strlen($fragment));
    }

    private function injectProfessionalUi(Request $request, string $html, string $version, string $assetVersion): string
    {
        if (str_contains($html, 'data-et-professional-ui=')) {
            return $html;
        }

        // The professional UI assets are served by authenticated ERP routes.
        // Never inject those authenticated asset URLs into guest/login pages,
        // otherwise Laravel can store the asset URL as the intended login
        // destination and redirect the user to raw JavaScript after sign-in.
        if (! $request->user()) {
            return $html;
        }

        $path = strtolower(trim($request->path(), '/'));
        $routeName = strtolower((string) optional($request->route())->getName());
        $isVoucherDocument = str_starts_with($path, 'voucher/')
            || preg_match('#^operations/bookings/[^/]+/(?:client-voucher-preview|travel-voucher|voucher)$#', $path) === 1
            || str_contains($routeName, 'client-voucher')
            || str_contains($routeName, 'travel-voucher')
            || str_contains($routeName, 'umrah-voucher')
            || str_contains($routeName, '.print')
            || str_contains($routeName, '.pdf');
        if (
            $isVoucherDocument
            || str_contains($path, '/print')
        ) {
            return $html;
        }

        $module = $this->uiModule($path);
        $role = $this->uiRole($path, $routeName);
        $marker = e($version);
        $dedicated = preg_match('#^operations/bookings/\d+/products/(?:air|hotel|transport|visa|other-services)$#', $path) === 1;
        $dedicatedProduct = '';
        if ($dedicated && preg_match('#^operations/bookings/\d+/products/([^/]+)$#', $path, $productMatch) === 1) {
            $dedicatedProduct = strtolower($productMatch[1]);
        }
        $dedicatedQuery = $dedicated ? '&dedicated=1'.($dedicatedProduct !== '' ? '&product='.rawurlencode($dedicatedProduct) : '') : '';
        $styleUrl = e(route('system.erp-assets.erp-professional-css').'?v='.rawurlencode($assetVersion).'&module='.rawurlencode($module).'&role='.rawurlencode($role).$dedicatedQuery);
        $scriptUrl = e(route('system.erp-assets.erp-professional-js').'?v='.rawurlencode($assetVersion).'&module='.rawurlencode($module).'&role='.rawurlencode($role));
        $assets = '<link rel="stylesheet" href="'.$styleUrl.'" data-et-professional-ui="'.$marker.'">'
            .'<script src="'.$scriptUrl.'" defer data-et-professional-ui-script="'.$marker.'"></script>';

        $html = preg_replace_callback(
            '/<body\b([^>]*)>/i',
            static function (array $match) use ($module, $role): string {
                $attributes = $match[1];
                $classes = 'et-ui-professional et-ui-module-'.$module;
                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attributes, $classMatch)) {
                    $replacement = 'class='.$classMatch[1].trim($classMatch[2].' '.$classes).$classMatch[1];
                    $attributes = preg_replace('/\bclass\s*=\s*(["\'])(.*?)\1/i', $replacement, $attributes, 1) ?? $attributes;
                } else {
                    $attributes .= ' class="'.$classes.'"';
                }
                return '<body'.$attributes.' data-et-ui-module="'.$module.'" data-et-ui-role="'.$role.'">';
            },
            $html,
            1
        ) ?? $html;

        if (stripos($html, '</head>') !== false) {
            return preg_replace('/<\/head>/i', $assets.'</head>', $html, 1) ?? $html;
        }

        return $assets.$html;
    }

    private function uiModule(string $path): string
    {
        return match (true) {
            $path === '', $path === 'dashboard', str_starts_with($path, 'dashboard/') => 'dashboard',
            str_starts_with($path, 'administration/') => 'administration',
            str_starts_with($path, 'organization/') => 'organization',
            str_starts_with($path, 'master-data/travel'), str_contains($path, 'visa-management') => 'travel',
            str_starts_with($path, 'master-data/') => 'master-data',
            str_starts_with($path, 'operations/'), str_starts_with($path, 'bookings/') => 'operations',
            str_starts_with($path, 'sales/') => 'sales',
            str_starts_with($path, 'purchase/'), str_starts_with($path, 'purchases/') => 'purchase',
            str_starts_with($path, 'accounting/reports') => 'reports',
            str_starts_with($path, 'travel-reports') => 'reports',
            str_starts_with($path, 'accounting/') => 'accounting',
            str_starts_with($path, 'system/') => 'system',
            default => 'foundation',
        };
    }

    private function uiRole(string $path, string $routeName): string
    {
        if ($path === '' || $path === 'dashboard' || str_starts_with($path, 'dashboard/')) return 'dashboard';
        if (
            str_starts_with($path, 'operations/bookings/')
            && (
                preg_match('#^operations/bookings/[^/]+(?:/(?:edit|review|show|products|products/(?:air|hotel|transport|visa|other-services)))?$#', $path)
                || preg_match('#^operations/bookings/[^/]+/additional-services/[^/]+/products/(?:air|hotel|transport|visa)$#', $path)
            )
        ) return 'focused';
        if (preg_match('#^(?:sales/invoices)(?:/[^/]+)?$#', $path)) return str_contains($path, '/invoices/') ? 'focused' : 'register';
        if (preg_match('#^(?:operations/bookings|purchase/supplier-costing)(?:/index)?/?$#', $path) || str_contains($routeName, '.index')) return 'register';
        return 'standard';
    }

    private function migrationStatus(array $release): array
    {
        try {
            $migrationFiles = glob(database_path('migrations/*.php'));
            if ($migrationFiles === false) {
                return ['status' => 'unknown', 'pending_count' => null];
            }

            $discovered = array_values(array_filter(array_map(
                static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
                $migrationFiles
            )));

            if ($discovered === [] || ! Schema::hasTable('migrations')) {
                return ['status' => 'unknown', 'pending_count' => null];
            }

            $recorded = DB::table('migrations')
                ->pluck('migration')
                ->map(static fn ($migration): string => (string) $migration)
                ->all();

            $pending = array_values(array_diff($discovered, $recorded));

            return [
                'status' => $pending === [] ? 'current' : 'pending',
                'pending_count' => count($pending),
            ];
        } catch (\Throwable) {
            /*
             * Do not break the ERP page merely because schema introspection
             * failed. Unknown migration state must never be presented as
             * current.
             */
            return ['status' => 'unknown', 'pending_count' => null];
        }
    }

    private function normalizeMigrationPresentation(string $html, string $status, string $statusMessage): string
    {
        $marker = '<div data-et-migration-presentation="'.e($status).'" data-et-migration-message="'.e($statusMessage).'" hidden></div>';
        if (! str_contains($html, 'data-et-migration-presentation=')) {
            $html = str_contains($html, '</main>')
                ? str_replace('</main>', $marker.'</main>', $html)
                : str_replace('</body>', $marker.'</body>', $html);
        }
        if ($status !== 'pending') {
            $html = $this->suppressMigrationActionBounded($html);
        }
        foreach ([
            'Air tickets are now atomic commercial records with customer sale, supplier purchase and forecast commission links.',
            'Ticket-level commercial details are available from the booking workspace.',
        ] as $legacyCopy) {
            $html = str_replace($legacyCopy, '', $html);
        }
        return $html;
    }

    /** Suppress only the exact Safe Database Upgrade action; preserve its panel. */
    private function suppressMigrationActionBounded(string $html): string
    {
        if (! class_exists(\DOMDocument::class)) return $html;
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) return $html;
        $xpath = new \DOMXPath($dom);
        $normalize = static fn (string $value): string => strtolower(trim((string) preg_replace('/\s+/', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $target = null;
        foreach ($xpath->query('//a | //button | //input[translate(@type, "SUBMITBUTTON", "submitbutton")="submit" or translate(@type, "SUBMITBUTTON", "submitbutton")="button"]') as $node) {
            if (! $node instanceof \DOMElement) continue;
            $label = strtolower($node->tagName) === 'input' ? (string) $node->getAttribute('value') : (string) $node->textContent;
            if ($normalize($label) === 'run safe database upgrade') {
                $target = $node;
                break;
            }
        }
        if (! $target instanceof \DOMElement) return $html;
        $target->setAttribute('hidden', 'hidden');
        $target->setAttribute('aria-hidden', 'true');
        $target->setAttribute('aria-disabled', 'true');
        $target->setAttribute('tabindex', '-1');
        if (in_array(strtolower($target->tagName), ['button', 'input'], true)) {
            $target->setAttribute('disabled', 'disabled');
        }
        $form = $target->parentNode;
        if ($form instanceof \DOMElement && strtolower($form->tagName) === 'form') {
            $form->setAttribute('aria-hidden', 'true');
            $form->setAttribute('aria-disabled', 'true');
        }
        $normalized = $dom->saveHTML();
        return $normalized;
    }
}
