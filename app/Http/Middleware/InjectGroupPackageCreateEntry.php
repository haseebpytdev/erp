<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Operations\UnifiedGroupPackageBookingController;
use App\Services\Operations\BookingWorkspaceShellPresenter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-10.31.32
 *
 * Uses the EXISTING native /operations/bookings/create route for both:
 *
 * 1. Normal booking-start page:
 *      /operations/bookings/create
 *
 * 2. Unified Group Package page:
 *      /operations/bookings/create?group_package=1&customer_id=...
 *
 * This deliberately avoids /operations/bookings/create/group-package because
 * that URL can collide with existing dynamic booking routes.
 */
class InjectGroupPackageCreateEntry
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * When the native Umrah / Package card sends us back to the SAME
         * existing create route with group_package=1, bypass the normal
         * booking-start controller and render the unified page directly.
         */
        if (
            $request->is('operations/bookings/create')
            && $request->boolean('group_package')
        ) {
            $view = app(UnifiedGroupPackageBookingController::class)->create($request);
            $response = response($view->render());

            return app(BookingWorkspaceShellPresenter::class)
                ->transform($request, $response);
        }

        $response = $next($request);

        if (! $request->is('operations/bookings/create')) {
            return $response;
        }

        if (! method_exists($response, 'getContent') || ! method_exists($response, 'setContent')) {
            return $response;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();

        // ERP-11.3.35: normalize the native New Booking card before first paint.
        // The existing JavaScript remains only as a compatibility fallback.
        $html = str_replace(
            [
                'Umrah / Package',
                'A package booking that may combine hotels, flights, visas, transport and other services.',
            ],
            [
                'Group Umrah',
                'Group Umrah package booking that may combine flights, hotels, visa, transport and other services.',
            ],
            $html
        );

        if ($html === '' || str_contains($html, 'id="et-group-package-native-card-bridge"')) {
            return $response;
        }

        $script = <<<'HTML'
<script id="et-group-package-native-card-bridge">
(function () {
    'use strict';

    var selectedGroupPackage = false;

    function clean(text) {
        return String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function applyGroupUmrahLabels() {
        var walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
            null
        );

        var nodes = [];

        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }

        nodes.forEach(function (node) {
            var raw = String(node.nodeValue || '');
            var trimmed = raw.replace(/\s+/g, ' ').trim();

            if (trimmed === 'Umrah / Package') {
                node.nodeValue = raw.replace(/Umrah\s*\/\s*Package/g, 'Group Umrah');
                return;
            }

            if (
                trimmed === 'A package booking that may combine hotels, flights, visas, transport and other services.'
            ) {
                node.nodeValue = raw.replace(
                    /A package booking that may combine hotels, flights, visas, transport and other services\./g,
                    'Group Umrah package booking that may combine flights, hotels, visa, transport and other services.'
                );
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyGroupUmrahLabels, { once: true });
    } else {
        applyGroupUmrahLabels();
    }

    requestAnimationFrame(applyGroupUmrahLabels);
    setTimeout(applyGroupUmrahLabels, 100);

    function bookingTypeFromElement(element) {
        if (!element) return '';

        var node = element;

        for (var depth = 0; depth < 7 && node; depth++, node = node.parentElement) {
            var text = clean(node.textContent);

            if (
                text.indexOf('umrah / package') !== -1
                || text.indexOf('umrah/package') !== -1
                || text.indexOf('group umrah') !== -1
            ) {
                return 'group-package';
            }

            var otherTypes = [
                'general / multi-service',
                'air ticket only',
                'hotel / accommodation only',
                'visa only',
                'transport only',
                'group',
                'corporate'
            ];

            for (var i = 0; i < otherTypes.length; i++) {
                if (text.indexOf(otherTypes[i]) !== -1 && text.length < 600) {
                    return 'other';
                }
            }
        }

        return '';
    }

    function summarySaysGroupPackage() {
        var nodes = Array.prototype.slice.call(
            document.querySelectorAll('div,span,strong,p')
        );

        for (var i = 0; i < nodes.length; i++) {
            var text = clean(nodes[i].textContent);

            if (
                text.indexOf('booking will start as') !== -1
                && (
                    text.indexOf('umrah / package') !== -1
                    || text.indexOf('umrah/package') !== -1
                    || text.indexOf('group umrah') !== -1
                )
                && text.length < 350
            ) {
                return true;
            }
        }

        return false;
    }

    function checkedBookingType() {
        var checked = document.querySelector(
            'input[type="radio"]:checked, input[type="checkbox"][name*="booking"]:checked'
        );

        if (checked) {
            var checkedType = bookingTypeFromElement(checked);
            if (checkedType) return checkedType;
        }

        if (summarySaysGroupPackage()) {
            return 'group-package';
        }

        return selectedGroupPackage ? 'group-package' : '';
    }

    function findCustomerSelect() {
        var selects = Array.prototype.slice.call(document.querySelectorAll('select'));

        for (var i = 0; i < selects.length; i++) {
            var select = selects[i];
            var key = clean(
                (select.getAttribute('name') || '') + ' ' + (select.id || '')
            );

            if (key.indexOf('customer') !== -1) {
                return select;
            }

            var optionText = '';

            for (var j = 0; j < select.options.length; j++) {
                optionText += ' ' + clean(select.options[j].text);
            }

            if (optionText.indexOf('select customer') !== -1) {
                return select;
            }
        }

        return null;
    }

    function findSelectByMeaning(words) {
        var selects = Array.prototype.slice.call(document.querySelectorAll('select'));

        for (var i = 0; i < selects.length; i++) {
            var key = clean(
                (selects[i].getAttribute('name') || '') + ' ' + (selects[i].id || '')
            );

            for (var j = 0; j < words.length; j++) {
                if (key.indexOf(words[j]) !== -1) {
                    return selects[i];
                }
            }
        }

        return null;
    }

    function continueButton(element) {
        if (!element) return null;

        var button = element.closest('button, input[type="submit"], a');

        if (!button) return null;

        var text = clean(button.textContent || button.value);

        return (
            text.indexOf('create booking') !== -1
            && text.indexOf('continue') !== -1
        ) ? button : null;
    }

    document.addEventListener('click', function (event) {
        var clickedType = bookingTypeFromElement(event.target);

        if (clickedType === 'group-package') {
            selectedGroupPackage = true;
        } else if (clickedType === 'other') {
            selectedGroupPackage = false;
        }

        var button = continueButton(event.target);

        if (!button || checkedBookingType() !== 'group-package') {
            return;
        }

        var customer = findCustomerSelect();

        if (!customer || !customer.value) {
            event.preventDefault();
            event.stopPropagation();

            if (customer) {
                customer.focus();
                customer.style.borderColor = '#d63939';

                if (typeof customer.reportValidity === 'function') {
                    customer.setCustomValidity('Please select customer first.');
                    customer.reportValidity();

                    setTimeout(function () {
                        customer.setCustomValidity('');
                    }, 100);
                }
            }

            return;
        }

        var nativeForm = button.closest('form') || customer.closest('form') || document.querySelector('form');

        if (!nativeForm) {
            event.preventDefault();
            event.stopPropagation();
            var fallback = new URLSearchParams();
            fallback.set('group_package', '1');
            fallback.set('customer_id', customer.value);
            window.location.assign(window.location.origin + '/operations/bookings/create?' + fallback.toString());
            return;
        }

        var marker = nativeForm.querySelector('input[name="group_umrah_unified"]');
        if (!marker) {
            marker = document.createElement('input');
            marker.type = 'hidden';
            marker.name = 'group_umrah_unified';
            nativeForm.appendChild(marker);
        }
        marker.value = '1';

        /*
         * Preserve the Customer already selected on the native booking screen.
         * The native form already tolerates the Group Umrah marker; these two
         * normalized handoff fields are consumed only by our redirect middleware.
         */
        var customerMarker = nativeForm.querySelector('input[name="group_umrah_customer_id"]');
        if (!customerMarker) {
            customerMarker = document.createElement('input');
            customerMarker.type = 'hidden';
            customerMarker.name = 'group_umrah_customer_id';
            nativeForm.appendChild(customerMarker);
        }
        customerMarker.value = customer.value;

        var customerNameMarker = nativeForm.querySelector('input[name="group_umrah_customer_name"]');
        if (!customerNameMarker) {
            customerNameMarker = document.createElement('input');
            customerNameMarker.type = 'hidden';
            customerNameMarker.name = 'group_umrah_customer_name';
            nativeForm.appendChild(customerNameMarker);
        }
        customerNameMarker.value = customer.options[customer.selectedIndex]
            ? customer.options[customer.selectedIndex].text
            : '';

        /* Keep the native submit. Easy Ticket creates its normal booking draft first. */
    }, true);
})();
</script>
HTML;

        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $script.'</body>', $html, 1) ?? $html;
        } else {
            $html .= $script;
        }

        $response->setContent($html);

        return $response;
    }
}
