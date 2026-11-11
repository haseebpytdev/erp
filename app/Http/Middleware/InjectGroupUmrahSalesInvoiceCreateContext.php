<?php

namespace App\Http\Middleware;

use App\Services\Operations\NativeBookingCustomerResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-10.31.14
 *
 * Activates only when Group Umrah intentionally opens the native Sales Invoice
 * register in create mode. It never replaces the native accounting screen.
 */
class InjectGroupUmrahSalesInvoiceCreateContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->boolean('group_umrah_create')) {
            return $response;
        }

        $bookingId = max(0, (int) $request->query('booking_id', 0));
        if ($bookingId <= 0 || $response->getStatusCode() >= 400) {
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
        if ($html === '' || str_contains($html, 'id="gp-native-invoice-create-context"')) {
            return $response;
        }

        $identity = app(NativeBookingCustomerResolver::class)->resolve($bookingId);
        $commercial = Schema::hasTable('booking_group_package_unified')
            ? (array) (DB::table('booking_group_package_unified')->where('booking_id', $bookingId)->first() ?? [])
            : [];
        $booking = Schema::hasTable('bookings')
            ? (array) (DB::table('bookings')->where('id', $bookingId)->first() ?? [])
            : [];

        $payload = [
            'bookingId' => $bookingId,
            'customerId' => (int) ($identity['id'] ?? 0),
            'customerName' => (string) ($identity['name'] ?? ''),
            'currency' => strtoupper((string) (
                $commercial['currency_code']
                ?? $booking['currency_code']
                ?? $booking['currency']
                ?? 'PKR'
            )),
            'amount' => (float) (
                $commercial['final_sale_price']
                ?? $booking['booking_value']
                ?? $booking['total_amount']
                ?? 0
            ),
            'invoiceMode' => (string) $request->query('invoice_mode', 'create'),
            'amendmentId' => max(0, (int) $request->query('group_umrah_amendment_id', 0)),
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );

        $script = <<<'HTML'
<script id="gp-native-invoice-create-context">
(function(){
    'use strict';
    const ctx = __GP_CONTEXT__;
    const norm = value => String(value || '').replace(/\s+/g,' ').trim().toLowerCase();

    function meaning(el){
        if (!el) return '';
        let text = [
            el.name || '', el.id || '',
            el.getAttribute('placeholder') || '',
            el.getAttribute('aria-label') || ''
        ].join(' ');
        const holder = el.closest('.form-group,.mb-3,.field,.row,[class*="field"]');
        const label = holder ? holder.querySelector('label') : null;
        if (label) text += ' ' + label.textContent;
        return norm(text);
    }

    function setSelect(select, value){
        if (!select || value === null || value === undefined || value === '') return false;
        const wanted = String(value);
        const option = Array.from(select.options || []).find(opt => String(opt.value) === wanted);
        if (!option) return false;
        select.value = wanted;
        select.dispatchEvent(new Event('input', {bubbles:true}));
        select.dispatchEvent(new Event('change', {bubbles:true}));
        return true;
    }

    function prefill(scope){
        const root = scope || document;
        Array.from(root.querySelectorAll('input,select,textarea')).forEach(control => {
            const key = meaning(control);

            if (ctx.bookingId && (
                key.includes('booking_id') || key.includes('booking id')
                || key.includes('travel booking') || key === 'booking'
            )) {
                if (control.tagName === 'SELECT') setSelect(control, ctx.bookingId);
                else if (!control.value) {
                    control.value = ctx.bookingId;
                    control.dispatchEvent(new Event('input', {bubbles:true}));
                    control.dispatchEvent(new Event('change', {bubbles:true}));
                }
                return;
            }

            if (ctx.customerId && (
                key.includes('customer') || key.includes('party') || key.includes('client')
            ) && !key.includes('supplier') && !key.includes('vendor')) {
                if (control.tagName === 'SELECT') setSelect(control, ctx.customerId);
                else if (!control.value && /(_id| id|party|customer|client)/.test(key)) {
                    control.value = ctx.customerId;
                    control.dispatchEvent(new Event('input', {bubbles:true}));
                    control.dispatchEvent(new Event('change', {bubbles:true}));
                }
                return;
            }

            if (ctx.currency && key.includes('currency') && control.tagName === 'SELECT') {
                const wanted = String(ctx.currency).toUpperCase();
                const option = Array.from(control.options || []).find(opt =>
                    String(opt.value || '').toUpperCase() === wanted
                    || norm(opt.textContent).startsWith(wanted.toLowerCase())
                );
                if (option) {
                    control.value = option.value;
                    control.dispatchEvent(new Event('change', {bubbles:true}));
                }
            }
        });
    }

    function createTrigger(){
        const candidates = Array.from(document.querySelectorAll(
            'a,button,[role="button"],input[type="button"]'
        ));
        return candidates.find(el => {
            const text = norm(el.textContent || el.value);
            if (!text.includes('invoice') || text.includes('vendor') || text.includes('purchase')) return false;
            return text.includes('create') || text.includes('new') || text.includes('add');
        }) || null;
    }

    function appendContext(url){
        try {
            const parsed = new URL(url, window.location.origin);
            parsed.searchParams.set('booking_id', ctx.bookingId);
            parsed.searchParams.set('source', 'group_umrah');
            if (ctx.customerId) parsed.searchParams.set('customer_id', ctx.customerId);
            if (ctx.currency) parsed.searchParams.set('currency_code', ctx.currency);
            if (ctx.invoiceMode) parsed.searchParams.set('invoice_mode', ctx.invoiceMode);
            if (ctx.amendmentId) parsed.searchParams.set('group_umrah_amendment_id', ctx.amendmentId);
            return parsed.toString();
        } catch (_) {
            return url;
        }
    }

    let opened = false;

    function attemptOpen(){
        prefill(document);
        const trigger = createTrigger();
        if (!trigger) return false;

        const href = trigger.getAttribute && trigger.getAttribute('href');
        if (href && href !== '#' && !href.toLowerCase().startsWith('javascript:')) {
            const target = appendContext(trigger.href || href);
            if (target !== window.location.href) {
                window.location.assign(target);
                return true;
            }
        }

        /*
         * Only click non-submit controls automatically. A submit control could
         * create an empty invoice before Customer/Booking context is visible.
         */
        const type = norm(trigger.getAttribute && trigger.getAttribute('type'));
        if (trigger.tagName === 'BUTTON' && type === 'submit') return false;
        if (trigger.tagName === 'INPUT' && type === 'submit') return false;

        trigger.click();
        window.setTimeout(() => prefill(document), 80);
        window.setTimeout(() => prefill(document), 250);
        return true;
    }

    function run(){
        prefill(document);
        if (!opened) opened = attemptOpen();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, {once:true});
    } else {
        run();
    }

    const observer = new MutationObserver(() => {
        prefill(document);
        if (!opened) opened = attemptOpen();
    });
    observer.observe(document.documentElement, {childList:true,subtree:true});

    window.setTimeout(run, 120);
    window.setTimeout(run, 450);
})();
</script>
HTML;

        $script = str_replace('__GP_CONTEXT__', $json ?: '{}', $script);

        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $script.'</body>', $html, 1) ?? $html;
        } else {
            $html .= $script;
        }

        $response->setContent($html);
        return $response;
    }
}
