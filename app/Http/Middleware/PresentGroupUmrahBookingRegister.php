<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-10.31.0
 *
 * The native Booking Register predates the unified Group Umrah tables.
 * This presenter keeps the native register page/design, but replaces only
 * Group Umrah row presentation with the unified source-of-truth values.
 */
class PresentGroupUmrahBookingRegister
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->method() !== 'GET' || trim($request->path(), '/') !== 'operations/bookings') {
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

        if (
            $html === ''
            || str_contains($html, 'id="et-group-umrah-register-presenter"')
            || ! Schema::hasTable('bookings')
            || ! Schema::hasTable('booking_group_package_unified')
        ) {
            return $response;
        }

        $rows = $this->registerRows();
        if ($rows === []) {
            return $response;
        }

        $json = json_encode(
            $rows,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );

        $script = <<<HTML
<script id="et-group-umrah-register-presenter">
(function () {
    'use strict';

    const groupUmrahRows = {$json};
    if (!Array.isArray(groupUmrahRows) || !groupUmrahRows.length) return;

    const byReference = new Map(
        groupUmrahRows
            .filter(item => item && item.reference)
            .map(item => [String(item.reference).trim().toUpperCase(), item])
    );

    function clean(text) {
        return String(text || '').replace(/\\s+/g, ' ').trim().toLowerCase();
    }

    function headerIndexes(table) {
        const headers = Array.from(table.querySelectorAll('thead th'));
        const indexes = { booking: -1, type: -1, passengers: -1, services: -1, status: -1 };

        headers.forEach((th, index) => {
            const label = clean(th.textContent);
            if (label === 'booking' || label.indexOf('booking') !== -1) indexes.booking = index;
            if (label === 'type') indexes.type = index;
            if (label.indexOf('passenger') !== -1) indexes.passengers = index;
            if (label.indexOf('service') !== -1) indexes.services = index;
            if (label === 'status') indexes.status = index;
        });

        return indexes;
    }

    function referenceInRow(row) {
        const match = String(row.textContent || '').match(/BK-\\d{4}-\\d{6}/i);
        return match ? match[0].toUpperCase() : null;
    }

    function apply() {
        document.querySelectorAll('table').forEach(table => {
            const indexes = headerIndexes(table);
            if (indexes.booking < 0) return;

            Array.from(table.querySelectorAll('tbody tr')).forEach(row => {
                const reference = referenceInRow(row);
                if (!reference || !byReference.has(reference)) return;

                const item = byReference.get(reference);
                const cells = Array.from(row.children);

                if (indexes.type >= 0 && cells[indexes.type]) {
                    cells[indexes.type].textContent = 'GROUP UMRAH';
                    cells[indexes.type].style.fontWeight = '700';
                    cells[indexes.type].style.color = '#1769d2';
                }

                if (indexes.passengers >= 0 && cells[indexes.passengers]) {
                    cells[indexes.passengers].textContent = `${item.passenger_count || 0}/${item.booked_pax || 1}`;
                }

                if (indexes.services >= 0 && cells[indexes.services]) {
                    cells[indexes.services].textContent = String(item.service_count || 0);
                }

                const bookingCell = cells[indexes.booking];
                if (bookingCell) {
                    const links = Array.from(bookingCell.querySelectorAll('a'));
                    const referenceLink = links.find(a =>
                        String(a.textContent || '').toUpperCase().indexOf(reference) !== -1
                    );

                    if (referenceLink && item.edit_url) {
                        referenceLink.href = item.edit_url;
                        referenceLink.title = 'Open Group Umrah booking';
                    }

                    if (!bookingCell.querySelector('[data-group-umrah-register-label]')) {
                        const label = document.createElement('div');
                        label.setAttribute('data-group-umrah-register-label', '1');
                        label.textContent = item.package_name ? 'Group Umrah · ' + item.package_name : 'Group Umrah';
                        label.style.fontSize = '9px';
                        label.style.marginTop = '2px';
                        label.style.color = '#1769d2';
                        label.style.fontWeight = '700';
                        bookingCell.appendChild(label);
                    }
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply, { once: true });
    } else {
        apply();
    }

    requestAnimationFrame(apply);
    setTimeout(apply, 120);
})();
</script>
HTML;

        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\\/body>/i', $script . '</body>', $html, 1) ?? $html;
        } else {
            $html .= $script;
        }

        $response->setContent($html);
        return $response;
    }

    private function registerRows(): array
    {
        try {
            $bookingColumns = Schema::getColumnListing('bookings');
            $referenceColumn = null;

            foreach (['booking_no', 'booking_number', 'booking_reference', 'reference', 'code'] as $column) {
                if (in_array($column, $bookingColumns, true)) {
                    $referenceColumn = $column;
                    break;
                }
            }

            if (! $referenceColumn) {
                return [];
            }

            $groupRows = DB::table('booking_group_package_unified')
                ->orderByDesc('booking_id')
                ->limit(1000)
                ->get(['booking_id', 'package_name', 'booked_pax', 'booking_workflow_status']);

            if ($groupRows->isEmpty()) {
                return [];
            }

            $bookingIds = $groupRows->pluck('booking_id')->map(fn ($id) => (int) $id)->all();
            $references = DB::table('bookings')->whereIn('id', $bookingIds)->pluck($referenceColumn, 'id');

            $passengerCounts = $this->counts('booking_group_package_passengers', $bookingIds);
            $flightCounts = $this->counts('booking_group_package_flights', $bookingIds);
            $hotelCounts = $this->counts('booking_group_package_hotels', $bookingIds);
            $transportCounts = $this->counts('booking_group_package_transports', $bookingIds);
            $otherCounts = $this->counts('booking_group_package_services', $bookingIds);

            $result = [];

            foreach ($groupRows as $row) {
                $bookingId = (int) $row->booking_id;
                $reference = trim((string) ($references[$bookingId] ?? ''));
                if ($reference === '') continue;

                // Native register Services = service categories + explicit extras.
                $serviceCount =
                    (($flightCounts[$bookingId] ?? 0) > 0 ? 1 : 0)
                    + (($hotelCounts[$bookingId] ?? 0) > 0 ? 1 : 0)
                    + (($transportCounts[$bookingId] ?? 0) > 0 ? 1 : 0)
                    + (int) ($otherCounts[$bookingId] ?? 0);

                $result[] = [
                    'booking_id' => $bookingId,
                    'reference' => $reference,
                    'package_name' => trim((string) ($row->package_name ?? '')),
                    'passenger_count' => (int) ($passengerCounts[$bookingId] ?? 0),
                    'booked_pax' => max(1, (int) ($row->booked_pax ?? 1)),
                    'service_count' => $serviceCount,
                    'workflow_status' => (string) ($row->booking_workflow_status ?? 'draft'),
                    'edit_url' => route(
                        'operations.bookings.group-package-unified.edit',
                        ['booking' => $bookingId]
                    ),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            report($e);
            return [];
        }
    }

    private function counts(string $table, array $bookingIds): array
    {
        if (! Schema::hasTable($table) || $bookingIds === []) return [];

        try {
            return DB::table($table)
                ->whereIn('booking_id', $bookingIds)
                ->select('booking_id', DB::raw('COUNT(*) AS aggregate_count'))
                ->groupBy('booking_id')
                ->pluck('aggregate_count', 'booking_id')
                ->map(fn ($count) => (int) $count)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
