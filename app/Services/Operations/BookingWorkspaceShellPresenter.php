<?php

namespace App\Services\Operations;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookingWorkspaceShellPresenter
{
    public function __construct(private readonly BookingEditLockResolver $bookingLocks) {}

    public function transform(Request $request, Response $response): Response
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
        }
        $html = $this->addHtmlClass($html, 'et-booking-unified-canvas-11375');
        $html = $this->addHtmlAttribute($html, 'data-et-booking-focus-shell', 'ERP-11.3.75');

        // Seed the authoritative lifecycle before the progressive client builds
        // passenger/product/Air editors.  This prevents a locked page from
        // briefly rendering editable controls while the summary request loads.
        $initialBookingLock = null;
        if (($isNativeBookingWorkspacePath || $isProductsWorkspacePath) && preg_match('#operations/bookings/(\d+)#', $path, $initialBookingMatch)) {
            $initialBookingLock = $this->bookingLocks->resolve((int) $initialBookingMatch[1]);
            $html = $this->addHtmlAttribute($html, 'data-et-booking-locked', $initialBookingLock['locked'] ? '1' : '0');
            $html = $this->addHtmlAttribute($html, 'data-et-booking-status', (string) ($initialBookingLock['status'] ?? 'DRAFT'));
            $html = $this->addHtmlAttribute($html, 'data-et-booking-lock-reason', (string) ($initialBookingLock['reason'] ?? ''));
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

        $script = '<script src="'
            .e(route('system.erp-assets.booking-focus'))
            .'?v=11.3.98" defer data-et-booking-focus-js="ERP-11.3.98"></script>';

        $stepOneStyle = '<link rel="stylesheet" href="'
            .e(route('system.erp-assets.general-progressive-step1-css'))
            .'?v=11.3.138" data-et-general-progressive-css="ERP-11.3.138">';

        $stepOneScript = '<script src="'
            .e(route('system.erp-assets.general-progressive-step1-js'))
            .'?v=11.3.138" defer data-et-general-progressive-js="ERP-11.3.138"></script>';

        if (
            str_contains($html, 'et-general-progressive-step1-11390')
            && ! str_contains($html, 'data-et-general-progressive-css="ERP-11.3.138"')
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
            ! str_contains($html, 'data-et-booking-focus-js="ERP-11.3.98"')
            && stripos($html, '</body>') !== false
        ) {
            $scripts = $script;

            if (str_contains($html, 'et-general-progressive-step1-11390')) {
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
            $lock=$initialBookingLock ?? $this->bookingLocks->resolve((int)$bookingMatch[1]);
            if($lock['locked']&&!str_contains($html,'data-et-server-booking-lock="1"')){
                $message=e($lock['reason']);
                $locked=<<<HTML
<div data-et-server-booking-lock="1" style="padding:10px 13px;border:1px solid #f0c777;border-radius:8px;background:#fff8e7;color:#704d0e;font:700 11px Arial,sans-serif">{$message}</div>
<script>(function(){function lock(){var root=document.querySelector('.etgp-step1')||document.querySelector('[data-booking-workspace]')||document.querySelector('.page-body');if(!root)return;root.querySelectorAll('input,select,textarea').forEach(function(e){e.disabled=true;e.setAttribute('aria-disabled','true')});root.querySelectorAll('button,[role="button"]').forEach(function(e){if(/\b(add|remove|delete|edit|apply|save|bulk|update|create|toggle)\b/i.test(e.textContent||e.value||'')){e.hidden=true;e.disabled=true}})}if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',lock);else lock();new MutationObserver(lock).observe(document.documentElement,{childList:true,subtree:true})})();</script>
HTML;
                $html=preg_replace('/<\/body>/i',$locked."\n</body>",$html,1)??$html;
            }
            $reviewEntry = '<a href="'.e(url('/operations/bookings/'.(int) $bookingMatch[1].'/review')).'" '
                .'data-et-booking-review-entry="1" style="position:fixed;right:18px;bottom:18px;z-index:1000;padding:10px 15px;border-radius:9px;background:#1769d2;color:#fff;text-decoration:none;font:800 11px Arial,sans-serif;box-shadow:0 5px 16px rgba(23,105,210,.28)">Review Booking</a>';
            if (! str_contains($html, 'data-et-booking-review-entry="1"') && stripos($html, '</body>') !== false) {
                $html = preg_replace('/<\/body>/i', $reviewEntry."\n</body>", $html, 1) ?? $html;
            }
            $productLauncher = '<section data-et-booking-products-launcher="1" style="position:fixed;left:18px;bottom:18px;z-index:999;padding:10px 12px;border:1px solid #dce8f5;border-radius:10px;background:#fff;box-shadow:0 5px 16px rgba(28,67,111,.12);font:800 11px Arial,sans-serif"><strong style="display:block;margin-bottom:6px">Booking Products</strong><div style="display:flex;gap:6px;flex-wrap:wrap">'
                .implode('', array_map(static fn (string $product): string => '<a href="'.e(url('/operations/bookings/'.(int) $bookingMatch[1].'/products/'.$product)).'" style="color:#1769d2;text-decoration:none">'.ucwords(str_replace('-', ' ', $product)).' →</a>', ['air','hotel','transport','visa','other-services']))
                .'</div></section>';
            if (! str_contains($html, 'data-et-booking-products-launcher="1"') && stripos($html, '</body>') !== false) {
                $html = preg_replace('/<\/body>/i', $productLauncher."\n</body>", $html, 1) ?? $html;
            }
        }

        $response->setContent($html);

        return $response;
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
