<?php

namespace App\Services\Operations;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookingWorkspaceShellPresenter
{
    public function __construct(
        private readonly BookingEditLockResolver $bookingLocks,
        private readonly BookingBillingEditLockResolver $billingLocks,
        private readonly BookingProductSummaryResolver $productSummaries,
    ) {}

    public function transform(Request $request, Response $response): Response
    {
        $timing = DedicatedProductTimingContext::fromRequest($request);
        $timing?->start('presenter_transform_total');

        try {
            return $this->transformResponse($request, $response, $timing);
        } finally {
            $timing?->stop('presenter_transform_total');
        }
    }

    private function transformResponse(Request $request, Response $response, ?DedicatedProductTimingContext $timing): Response
    {
        if (
            ! method_exists($response, 'getContent')
            || ! method_exists($response, 'setContent')
        ) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('content-type', ''));

        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();

        if (
            $html === ''
            || str_contains($html, 'data-et-booking-focus-shell="ERP-11.3.75"')
        ) {
            return $response;
        }

        $path = trim(strtolower($request->path()), '/');

        $isGroupWorkspace = (
            str_contains($html, 'id="gp-booking"')
            || str_contains($html, "id='gp-booking'")
            || str_contains($html, 'Create Group Umrah Booking')
            || str_contains($html, 'Edit Group Umrah Booking')
        );

        $isAirWorkspace = (
            str_contains($html, 'Air Ticket Batch Entry')
            && str_contains($html, 'Booking Workspace')
        );

        $isNativeBookingWorkspacePath = (
            preg_match('#^operations/bookings/\d+(?:/edit)?$#', $path) === 1
        );

        $isProductsWorkspacePath = (
            preg_match('#^operations/bookings/\d+/products(?:/(?:air|hotel|transport|visa|other-services))?$#', $path) === 1
        );

        // The native booking view owns the identity card reference, while its
        // outer layout title is rendered by the installed ERP page header.
        // Normalize only that outer heading on the exact dashboard route;
        // product/review/invoice routes must retain their own titles.
        $isMainBookingDashboardPath = (
            preg_match('#^operations/bookings/\d+/?$#', $path) === 1
        );

        if ($isMainBookingDashboardPath) {
            $html = $this->normalizeMainBookingOuterHeading($html);
        }

        /*
         * ERP-11.3.46: native booking wizard steps are actual booking
         * workspaces too. They must not fall back to the permanent ERP sidebar
         * simply because their URL is nested below /operations/bookings/{id}.
         *
         * Confirmed current flow:
         *   Booking -> Passengers -> Group Package -> Services -> Review -> Confirm
         */
        $isNestedBookingWorkflowPath = (
            preg_match(
                '#^operations/bookings/\d+/.+$#',
                $path
            ) === 1
            && ! preg_match('#^operations/bookings/\d+/services/\d+/details$#', $path)
            && ! str_ends_with(
                $path,
                '/sales-invoice'
            )
        );

        $isDirectGroupWorkspacePath = (
            $path === 'operations/group-package-bookings/create'
            || preg_match('#^operations/group-package-bookings/\d+/edit$#', $path) === 1
        );

        /*
         * Keep the normal New Booking product selector on the standard ERP shell.
         * The same /operations/bookings/create URL becomes focused only when the
         * Group Umrah unified workspace has actually been rendered.
         */
        $shouldFocus = (
            $isGroupWorkspace
            || $isAirWorkspace
            || $isNativeBookingWorkspacePath
            || $isNestedBookingWorkflowPath
            || $isDirectGroupWorkspacePath
        );

        if (! $shouldFocus) {
            return $response;
        }

        /*
         * ERP-11.3.50: the standard/native Booking Workspace is the only
         * focused product still using a wider legacy outer canvas. Air and
         * unified Group Umrah already render at the desired focused width, so
         * do not alter those products again.
         */
        if (
            $isNativeBookingWorkspacePath
            && ! $isAirWorkspace
            && ! $isGroupWorkspace
        ) {
            $html = $this->addHtmlClass(
                $html,
                'et-booking-native-standard-canvas'
            );
        }

        $html = $this->addHtmlClass($html, 'et-booking-focus-prepaint');
        if ($isProductsWorkspacePath) {
            $html = $this->addHtmlClass($html, 'et-booking-products-prepaint');
            // Dedicated Visa owns its runtime; Air and legacy products retain
            // their established progressive behavior.
            if (! preg_match('#^operations/bookings/\d+/products/visa$#', $path)) {
                $html = $this->addHtmlClass($html, 'et-general-progressive-step1-11390');
            }
        }
        $html = $this->addHtmlClass($html, 'et-booking-unified-canvas-11375');
        $html = $this->addHtmlAttribute($html, 'data-et-booking-focus-shell', 'ERP-11.3.75');
        // Seed the authoritative lifecycle before the progressive client builds
        // passenger/product/Air editors.  This prevents a locked page from
        // briefly rendering editable controls while the summary request loads.
        $initialBookingLock = null;
        if (($isNativeBookingWorkspacePath || $isProductsWorkspacePath) && preg_match('#operations/bookings/(\d+)#', $path, $initialBookingMatch)) {
            $bookingId = (int) $initialBookingMatch[1];
            $initialBookingLock = $timing?->measure('presenter_lock_resolve', function () use ($bookingId): array {
                return $this->presentationLock($bookingId);
            }) ?? $this->presentationLock($bookingId);
            $html = $this->addHtmlAttribute($html, 'data-et-booking-locked', $initialBookingLock['locked'] ? '1' : '0');
            $html = $this->addHtmlAttribute($html, 'data-et-booking-status', (string) ($initialBookingLock['status'] ?? 'DRAFT'));
            $html = $this->addHtmlAttribute($html, 'data-et-booking-lock-reason', (string) ($initialBookingLock['reason'] ?? ''));
            $html = $this->addHtmlAttribute($html, 'data-et-booking-billing-locked', ! empty($initialBookingLock['billing_locked']) ? '1' : '0');
        }
        if ($isNativeBookingWorkspacePath && preg_match('#operations/bookings/(\d+)#', $path, $summaryMatch)
            && ! str_contains($html, 'data-et-c36-product-summary="1"')) {
            $summary = $this->productSummaryMarkup((int) $summaryMatch[1], $initialBookingLock);
            $html = preg_replace('/<\/main>/i', $summary."\n</main>", $html, 1) ?? $html;
        }

        /*
         * ERP-11.3.75: GENERAL and every native product Booking Workspace share
         * the same shell. Product type no longer changes outer content width.
         */
        if (
            str_contains(strtoupper($html), '>GENERAL<')
            || str_contains(strtoupper($html), '· GENERAL ·')
        ) {
            $html = $this->addHtmlClass(
                $html,
                'et-booking-type-general-11375'
            );
            $html = $this->addHtmlClass(
                $html,
                'et-general-progressive-step1-11390'
            );
        }

        /*
         * Old from-booking links are intentionally rewritten to the stable
         * booking-scoped Sales Invoice entry. The backward-compatible old URL
         * is also overridden in routes, but new rendered pages should stop
         * generating it entirely.
         */
        $html = preg_replace(
            '#(["\'])/sales/invoices/from-booking/(\d+)\1#i',
            '$1/operations/bookings/$2/sales-invoice$1',
            $html
        ) ?? $html;
        $html = preg_replace_callback(
            '#(["\'])https?://[^"\']+/sales/invoices/from-booking/(\d+)\1#i',
            static fn (array $match): string =>
                $match[1]
                .url(
                    '/operations/bookings/'
                    .(int) $match[2]
                    .'/sales-invoice'
                )
                .$match[1],
            $html
        ) ?? $html;

        // Static focused-workspace CSS is served by the fresh Booking theme.
        $style = '';
        if (! empty($initialBookingLock['billing_locked'])) {
            // The passenger editor is progressively generated. Keep the
            // server-seeded billing lock effective for late-created nodes;
            // the existing JS observer still handles text-only mutation
            // buttons, while this rule covers stable editor containers.
            $style = '<style data-et-booking-billing-lock-presentation="1">'
                .'html[data-et-booking-billing-locked="1"] .etgp-passenger-editor-host,'
                .'html[data-et-booking-billing-locked="1"] .etgp-passenger-mode-panel,'
                .'html[data-et-booking-billing-locked="1"] .etgp-passenger-quick-row,'
                .'html[data-et-booking-billing-locked="1"] .etgp-passenger-actions,'
                .'html[data-et-booking-billing-locked="1"] .etgp-quick-passenger-11397,'
                .'html[data-et-booking-billing-locked="1"] [data-etgp-quick-passenger-11397],'
                .'html[data-et-booking-billing-locked="1"] [data-etgp-passenger-mode-control]'
                .'{display:none!important;visibility:hidden!important;}'
                .'</style>';
        }

        if (stripos($html, '</head>') !== false) {
            $html = preg_replace(
                '/<\/head>/i',
                $style."\n</head>",
                $html,
                1
            ) ?? $html;
        } else {
            $html = $style.$html;
        }

        $assetVersion = rawurlencode((string) config('et_erp_release.asset_version', config('et_erp_release.version', 'ERP-11.3')));
        if (preg_match('#^operations/bookings/\d+/products/visa$#', $path) === 1
            && ! str_contains($html, 'data-et-dedicated-visa-css="')
            && stripos($html, '</head>') !== false
        ) {
            $visaDedicatedStyle = '<link rel="stylesheet" href="'
                .e(route('system.erp-assets.erp-professional-css'))
                .'?module=operations&role=focused&dedicated=1&product=visa&v='.$assetVersion
                .'" data-et-dedicated-visa-css="'.$assetVersion.'">';
            $html = preg_replace('/<\/head>/i', $visaDedicatedStyle."\n</head>", $html, 1) ?? $html;
        }
        $script = '<script src="'
            .e(route('system.erp-assets.booking-focus'))
            .'?v='.$assetVersion.'" defer data-et-booking-focus-js="'.$assetVersion.'"></script>';

        if ($isProductsWorkspacePath) {
            $script = '<script src="'.e(route('system.erp-assets.dedicated-product-core')).'?v='.rawurlencode($assetVersion).'" data-et-dedicated-product-core="'.$assetVersion.'"></script>'.$script;
            if (preg_match('#^operations/bookings/\d+/products/air$#', $path) === 1) {
                $script .= '<script src="'.e(route('system.erp-assets.products-air')).'?v='.rawurlencode($assetVersion).'" data-et-dedicated-product-air="'.$assetVersion.'"></script>';
            } elseif (preg_match('#^operations/bookings/\d+/products/visa$#', $path) === 1) {
                $script .= '<script src="'.e(route('system.erp-assets.products-visa-core')).'?v='.rawurlencode($assetVersion).'" data-et-dedicated-product-visa-core="'.$assetVersion.'"></script>';
                $script .= '<script src="'.e(route('system.erp-assets.products-visa')).'?v='.rawurlencode($assetVersion).'" data-et-dedicated-product-visa="'.$assetVersion.'"></script>';
            }
        }

        $stepOneStyle = '<link rel="stylesheet" href="'
            .e(route('system.erp-assets.general-progressive-step1-css'))
            .'?v='.$assetVersion.'" data-et-general-progressive-css="'.$assetVersion.'">';

        $stepOneScript = '<script src="'
            .e(route('system.erp-assets.general-progressive-step1-js'))
            .'?v='.$assetVersion.'" defer data-et-general-progressive-js="'.$assetVersion.'"></script>';
        $visaCoreScript = '<script src="'.e(route('system.erp-assets.products-visa-core')).'?v='.$assetVersion.'" data-et-visa-core="'.$assetVersion.'"></script>';

        if (
            str_contains($html, 'et-general-progressive-step1-11390')
            && ! preg_match('#^operations/bookings/\d+/products/(?:air|visa)$#', $path)
            && ! str_contains($html, 'data-et-general-progressive-css="'.$assetVersion.'"')
            && stripos($html, '</head>') !== false
        ) {
            $html = preg_replace(
                '/<\/head>/i',
                $stepOneStyle."\n</head>",
                $html,
                1
            ) ?? $html;
        }

        if (
            ! str_contains($html, 'data-et-booking-focus-js="'.$assetVersion.'"')
            && stripos($html, '</body>') !== false
        ) {
            $scripts = $script;

            if (str_contains($html, 'et-general-progressive-step1-11390') && ! preg_match('#^operations/bookings/\d+/products/(?:air|visa)$#', $path)) {
                if (! str_contains($html, 'data-et-visa-core=')) {
                    $scripts .= "\n".$visaCoreScript;
                }
                $scripts .= "\n".$stepOneScript;
            }

            $html = preg_replace(
                '/<\/body>/i',
                $scripts."\n</body>",
                $html,
                1
            ) ?? $html;
        }

        // Booking Review entry stays inside the existing focused booking shell.
        // It is injected only on the native GENERAL booking view/edit page.
        if ($isNativeBookingWorkspacePath && preg_match('#operations/bookings/(\d+)#', $path, $bookingMatch)) {
            $lock=$initialBookingLock ?? $this->presentationLock((int)$bookingMatch[1]);
            if($lock['locked']&&!str_contains($html,'data-et-server-booking-lock="1"')){
                $message=e($this->lockPresentationMessage($lock));
                $locked=<<<HTML
<div data-et-server-booking-lock="1" data-et-lock-status="{$message}" style="padding:10px 13px;border:1px solid #f0c777;border-radius:8px;background:#fff8e7;color:#704d0e;font:700 11px Arial,sans-serif">{$message}</div>
<script>(function(){function lock(){var root=document.querySelector('.etgp-step1')||document.querySelector('[data-booking-workspace]')||document.querySelector('.page-body');if(!root)return;root.querySelectorAll('input,select,textarea').forEach(function(e){e.disabled=true;e.setAttribute('aria-disabled','true')});root.querySelectorAll('button,[role="button"]').forEach(function(e){if(/\b(add|remove|delete|edit|apply|save|bulk|update|create|toggle)\b/i.test(e.textContent||e.value||'')){e.hidden=true;e.disabled=true}})}if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',lock);else lock();new MutationObserver(lock).observe(document.documentElement,{childList:true,subtree:true})})();</script>
HTML;
                $html=$this->insertNearBookingHeader($html, $locked);
            }
            // C36-C2: the server-rendered summary inside the controlled
            // booking canvas is the sole Products authority. Do not append a
            // second footer launcher outside section.content.
        }

        if (($isNativeBookingWorkspacePath || preg_match('#^operations/bookings/\d+/products/air$#', $path) === 1)
            && str_contains($html, 'data-et-smart-products-entry="1"')
        ) {
            $navigationScript = '<script src="'.e(route('system.erp-assets.dedicated-product-navigation')).'?v='.rawurlencode($assetVersion). '" defer data-et-dedicated-product-navigation="'.$assetVersion.'"></script>';
            $html = preg_replace('/<\/body>/i', $navigationScript."\n</body>", $html, 1) ?? $html;
            $visaNavigation = '<script src="'.e(route('system.erp-assets.dedicated-visa-navigation')).'?v='.rawurlencode($assetVersion).'" defer data-et-dedicated-visa-navigation="'.$assetVersion.'"></script>';
            $html = preg_replace('/<\/body>/i', $visaNavigation."\n</body>", $html, 1) ?? $html;
        }

        $response->setContent($html);

        return $response;
    }

    private function lockPresentationMessage(array $lock): string
    {
        $reason = trim((string) ($lock['reason'] ?? ''));
        if ($reason !== '') return $reason;
        $status = (string) ($lock['status'] ?? 'CONFIRMED');
        $label = match (strtoupper(trim($status))) {
            'TRAVEL_READY', 'TRAVEL READY' => 'Travel Ready',
            'APPROVED' => 'Approved',
            default => 'Confirmed',
        };
        return $label.' booking — editing is locked. Reopen the booking to make changes.';
    }

    private function presentationLock(int $bookingId): array
    {
        $workflow = $this->bookingLocks->resolve($bookingId);
        $billing = $this->billingLocks->resolve($bookingId);
        if ($billing['locked'] ?? false) {
            return [
                'locked' => true,
                'status' => (string) ($workflow['status'] ?? 'Draft'),
                'reason' => (string) ($billing['reason'] ?? ''),
                'billing_locked' => true,
                'billing_code' => (string) ($billing['code'] ?? ''),
            ];
        }
        return $this->bookingLocks->resolve($bookingId);
    }

    private function normalizeMainBookingOuterHeading(string $html): string
    {
        $pattern = '/(<header\b[^>]*class=(?:"[^"]*\btopbar\b[^"]*"|\'[^\']*\btopbar\b[^\']*\')[^>]*>.*?<([a-z][a-z0-9:-]*)\b[^>]*class=(?:"[^"]*\btop-title\b[^"]*"|\'[^\']*\btop-title\b[^\']*\')[^>]*>).*?(<\/\2>)/is';

        return preg_replace($pattern, '$1Booking Dashboard$3', $html, 1) ?? $html;
    }

    private function productSummaryMarkup(int $bookingId, ?array $lock): string
    {
        $summary = $this->productSummaries->resolve($bookingId);
        $locked = (bool) ($lock['locked'] ?? false);
        $labels = ['air' => 'Air / Tickets', 'hotel' => 'Hotel', 'transport' => 'Transport', 'visa' => 'Visa'];
        $html = '<section class="et-c36-product-summary" data-et-c36-product-summary="1" data-et-smart-products-entry="1"><div class="et-c36-product-summary-head"><h2>Products</h2><span>Dedicated workspaces own product editing</span></div><div class="et-c36-product-summary-grid">';
        foreach ($labels as $key => $label) {
            $row = $summary[$key] ?? [];
            $count = (int) ($row['count'] ?? 0);
            $state = $count > 0 ? ($locked ? 'Read Only' : 'Added') : 'Not Added';
            $supplementOnly = (bool) ($row['supplement_only'] ?? false);
            $action = $locked ? 'View' : ($count > 0 ? 'Edit' : 'Open');
            if ($supplementOnly) $action = 'View';
            $url = ($locked || $supplementOnly)
                ? url('/operations/bookings/'.$bookingId.'/review')
                : url('/operations/bookings/'.$bookingId.'/products/'.$key);
            $html .= '<article class="et-c36-product-summary-card" data-product="'.e($key).'">'
                .'<div><strong>'.e($label).'</strong><span>'.e($state).'</span></div>'
                .'<dl><div><dt>Items</dt><dd>'.$count.'</dd></div><div><dt>Booking Value</dt><dd>'.number_format((float) ($row['customer_total'] ?? 0), 2).'</dd></div><div><dt>Supplier Cost</dt><dd>'.number_format((float) ($row['supplier_total'] ?? 0), 2).'</dd></div><div><dt>Margin</dt><dd>'.number_format((float) ($row['margin'] ?? 0), 2).'</dd></div></dl>'
                .'<a class="et-booking-focus-btn primary" data-primary="1" href="'.e($url).'">'.e($action).'</a></article>';
        }
        $html .= '</div><div class="et-c36-product-summary-actions"><a class="et-booking-focus-btn primary" data-primary="1" data-et-booking-review-entry="1" href="'.e(url('/operations/bookings/'.$bookingId.'/review')).'">Review Booking</a></div></section>';
        return $html;
    }

    private function insertNearBookingHeader(string $html, string $notice): string
    {
        foreach (['.et-booking-workspace-header', '[data-et-booking-workspace-header="1"]', '.etgp-booking-card', '.page-body'] as $selector) {
            $pattern = match ($selector) {
                '.et-booking-workspace-header' => '/(<(?:header|section|div)\\b[^>]*class=(?:"[^"]*\\bet-booking-workspace-header\\b[^"]*"|\'[^\']*\\bet-booking-workspace-header\\b[^\']*\')[^>]*>)/i',
                '[data-et-booking-workspace-header="1"]' => '/(<(?:header|section|div)\\b[^>]*data-et-booking-workspace-header="1"[^>]*>)/i',
                '.etgp-booking-card' => '/(<(?:section|div)\\b[^>]*class=(?:"[^"]*\\betgp-booking-card\\b[^"]*"|\'[^\']*\\betgp-booking-card\\b[^\']*\')[^>]*>)/i',
                default => '/(<(?:main|section|div)\\b[^>]*class=(?:"[^"]*\\bpage-body\\b[^"]*"|\'[^\']*\\bpage-body\\b[^\']*\')[^>]*>)/i',
            };
            $updated = preg_replace($pattern, '$1'.$notice, $html, 1, $count);
            if ($count === 1 && $updated !== null) return $updated;
        }
        return preg_replace('/<body\\b[^>]*>/i', '$0'.$notice, $html, 1) ?? $html;
    }

    private function addHtmlClass(string $html, string $class): string
    {
        return preg_replace_callback(
            '/<html\b([^>]*)>/i',
            static function (array $match) use ($class): string {
                $attrs = $match[1];

                if (preg_match('/\bclass=(["\'])(.*?)\1/i', $attrs, $classMatch)) {
                    $classes = trim($classMatch[2].' '.$class);
                    $replacement = 'class='.$classMatch[1].$classes.$classMatch[1];

                    return '<html'.preg_replace(
                        '/\bclass=(["\'])(.*?)\1/i',
                        $replacement,
                        $attrs,
                        1
                    ).'>';
                }

                return '<html'.$attrs.' class="'.$class.'">';
            },
            $html,
            1
        ) ?? $html;
    }

    private function addHtmlAttribute(string $html, string $name, string $value): string
    {
        return preg_replace_callback(
            '/<html\b([^>]*)>/i',
            static function (array $match) use ($name, $value): string {
                $attrs = $match[1];
                if (preg_match('/\b'.preg_quote($name, '/').'=(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', $attrs)) {
                    return $match[0];
                }
                return '<html'.$attrs.' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'">';
            },
            $html,
            1
        ) ?? $html;
    }
}
