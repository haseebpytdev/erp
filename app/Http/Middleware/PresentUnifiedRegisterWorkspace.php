<?php

namespace App\Http\Middleware;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ERP-11.3.239
 *
 * Server-side register presentation for the three primary operational/commercial
 * registers. The native controller + middleware stack remains authoritative for
 * permissions, filtering, pagination and row data. This presenter reads the
 * rendered native table and replaces only the register <main> canvas before the
 * response reaches the browser, eliminating post-paint DOM reconstruction.
 */
final class PresentUnifiedRegisterWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($request->method() !== 'GET') {
            return $response;
        }

        $path = strtolower(trim($request->path(), '/'));
        $config = $this->config($path);

        if ($config === null) {
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
        if ($html === '' || str_contains($html, 'data-et-register-workspace="ERP-11.3.239"')) {
            return $response;
        }

        try {
            $workspace = $this->extractWorkspace($html, $config);
            if ($workspace === null) {
                return $response;
            }

            $fragment = view('system.register-workspace-v113239', $workspace)->render();
            $presented = $this->replaceMain($html, $fragment);

            if ($presented === null) {
                return $response;
            }

            $presented = $this->markHtml($presented, (string) $config['key']);
            $response->setContent($presented);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $response;
    }

    private function config(string $path): ?array
    {
        return match ($path) {
            'operations/bookings' => [
                'key' => 'bookings',
                'kicker' => 'OPERATIONS · ERP-09',
                'title' => 'Booking Register',
                'subtitle' => 'Manage, track and review all travel bookings.',
                'table_title' => 'Bookings',
                'id_header' => 'booking',
                'status_header' => 'status',
                'date_header' => 'date',
                'group_header' => 'customer',
                'secondary_header' => 'type',
                'total_label' => 'Total Bookings',
                'pending_label' => 'Pending Confirmation',
                'approved_label' => 'Confirmed',
                'fourth_label' => 'Cancelled',
                'pending_status' => 'pending',
                'approved_status' => 'confirmed',
                'fourth_status' => 'cancelled',
                'field2_label' => 'Customer',
                'field2_all' => 'All Customers',
                'field3_label' => 'Travel Type',
                'field3_all' => 'All Types',
                'breakdown_title' => 'Bookings by Type',
                'workflow' => [
                    ['Draft', 'Prepare booking'],
                    ['Pending', 'Await confirmation'],
                    ['Confirmed', 'Travel processing'],
                    ['Travel Ready', 'Operationally ready'],
                ],
                'action_text' => 'Open Booking',
                'create_tokens' => ['new booking'],
                'quick' => [
                    ['all', 'All'],
                    ['pending', 'Pending'],
                    ['confirmed', 'Confirmed'],
                    ['cancelled', 'Cancelled'],
                ],
            ],
            'sales/invoices' => [
                'key' => 'sales-invoices',
                'kicker' => 'COMMERCIAL DOCUMENTS · ERP-09',
                'title' => 'Sales Invoice Register',
                'subtitle' => 'Confirmed bookings become customer receivables only after an approved Sales Invoice is posted.',
                'table_title' => 'Sales Invoices',
                'id_header' => 'invoice',
                'status_header' => 'status',
                'date_header' => 'date',
                'group_header' => 'customer',
                'secondary_header' => 'booking',
                'total_label' => 'Total Invoices',
                'pending_label' => 'Pending Approval',
                'approved_label' => 'Approved',
                'fourth_label' => 'Posted',
                'pending_status' => 'pending_approval',
                'approved_status' => 'approved',
                'fourth_status' => 'posted',
                'field2_label' => 'Customer',
                'field2_all' => 'All Customers',
                'field3_label' => 'Booking',
                'field3_all' => 'All Bookings',
                'breakdown_title' => 'Invoices by Status',
                'workflow' => [
                    ['Draft', 'Commercial review'],
                    ['Pending', 'Maker / checker'],
                    ['Approved', 'Ready to post'],
                    ['Posted', 'Customer receivable'],
                ],
                'action_text' => 'Open Invoice',
                'create_tokens' => [],
                'quick' => [
                    ['all', 'All'],
                    ['draft', 'Draft'],
                    ['pending_approval', 'Pending'],
                    ['approved', 'Approved'],
                    ['posted', 'Posted'],
                ],
            ],
            'supplier-costing' => [
                'key' => 'supplier-costing',
                'kicker' => 'PURCHASE & COSTING',
                'title' => 'Supplier Costing',
                'subtitle' => 'Supplier costs and payable posting.',
                'table_title' => 'Supplier Costings',
                'id_header' => 'costing',
                'status_header' => 'status',
                'date_header' => 'date',
                'group_header' => 'supplier',
                'secondary_header' => 'products',
                'total_label' => 'Total Costings',
                'pending_label' => 'Pending Approval',
                'approved_label' => 'Approved',
                'fourth_label' => 'Posted',
                'pending_status' => 'pending_approval',
                'approved_status' => 'approved',
                'fourth_status' => 'posted',
                'field2_label' => 'Supplier',
                'field2_all' => 'All Suppliers',
                'field3_label' => 'Product',
                'field3_all' => 'All Products',
                'breakdown_title' => 'Costings by Product',
                'workflow' => [
                    ['Draft', 'Prepare supplier cost'],
                    ['Pending', 'Await approval'],
                    ['Approved', 'Ready to post'],
                    ['Posted', 'Supplier payable'],
                ],
                'action_text' => 'Open Costing',
                'create_tokens' => ['new supplier cost'],
                'quick' => [
                    ['all', 'All'],
                    ['draft', 'Draft'],
                    ['pending_approval', 'Pending'],
                    ['approved', 'Approved'],
                    ['posted', 'Posted'],
                ],
            ],
            default => null,
        };
    }

    private function extractWorkspace(string $html, array $config): ?array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $table = $this->findRegisterTable($xpath, $config);

        if (! $table) {
            return null;
        }

        $headerNodes = $xpath->query('.//thead//th', $table);
        if (! $headerNodes || $headerNodes->length === 0) {
            return null;
        }

        $headers = [];
        foreach ($headerNodes as $index => $node) {
            $headers[$index] = $this->clean($node->textContent);
        }

        $indexes = $this->indexes($headers, $config);
        if ($indexes['id'] < 0 || $indexes['status'] < 0) {
            return null;
        }

        $actionIndex = -1;
        foreach ($headers as $index => $label) {
            $normalized = $this->lower($label);
            if ($normalized === '' || str_contains($normalized, 'action')) {
                $actionIndex = $index;
            }
        }

        $visibleIndexes = array_values(array_filter(
            array_keys($headers),
            static fn (int $index): bool => $index !== $actionIndex
        ));

        $rowNodes = $xpath->query('.//tbody/tr', $table);
        $rows = [];

        if ($rowNodes) {
            foreach ($rowNodes as $rowNode) {
                if (! $rowNode instanceof DOMElement) {
                    continue;
                }

                $cellNodes = $xpath->query('./td', $rowNode);
                if (! $cellNodes || $cellNodes->length === 0) {
                    continue;
                }

                if ($cellNodes->length === 1 && str_contains($this->lower($rowNode->textContent), 'no ')) {
                    continue;
                }

                $cells = [];
                foreach ($cellNodes as $cellIndex => $cellNode) {
                    $cells[$cellIndex] = [
                        'text' => $this->clean($cellNode->textContent),
                        'html' => $this->innerHtml($cellNode),
                    ];
                }

                $id = $cells[$indexes['id']]['text'] ?? '';
                $statusLabel = $cells[$indexes['status']]['text'] ?? '';
                $dateLabel = $indexes['date'] >= 0 ? ($cells[$indexes['date']]['text'] ?? '') : '';
                $group = $indexes['group'] >= 0 ? ($cells[$indexes['group']]['text'] ?? '') : '';
                $secondary = $indexes['secondary'] >= 0 ? ($cells[$indexes['secondary']]['text'] ?? '') : '';
                $status = $this->statusKey($statusLabel);
                $date = $this->dateKey($dateLabel);
                $href = $this->actionHref($xpath, $rowNode, $indexes['id']);

                $displayCells = [];
                foreach ($visibleIndexes as $index) {
                    $displayCells[] = $cells[$index] ?? ['text' => '', 'html' => ''];
                }

                $rows[] = [
                    'id' => $id,
                    'status' => $status,
                    'status_label' => $statusLabel !== '' ? $statusLabel : ucfirst(str_replace('_', ' ', $status)),
                    'date' => $date,
                    'date_label' => $dateLabel,
                    'group' => $group,
                    'secondary' => $secondary,
                    'search' => $this->lower($rowNode->textContent),
                    'href' => $href,
                    'cells' => $displayCells,
                ];
            }
        }

        $createHref = $this->findCreateHref($xpath, (array) $config['create_tokens']);
        $groups = $this->uniqueValues(array_column($rows, 'group'));
        $secondary = $this->uniqueValues(array_column($rows, 'secondary'));

        $counts = [
            'total' => count($rows),
            'pending' => $this->countStatus($rows, (string) $config['pending_status']),
            'approved' => $this->countStatus($rows, (string) $config['approved_status']),
            'fourth' => $this->countStatus($rows, (string) $config['fourth_status']),
        ];

        $metricCaptions = $this->metricCaptions($rows, $config, $counts);
        $breakdown = $this->breakdown($rows, $config);

        return [
            'config' => $config,
            'headers' => array_values(array_map(
                static fn (int $index): string => $headers[$index] ?? '',
                $visibleIndexes
            )),
            'rows' => $rows,
            'groups' => $groups,
            'secondaryOptions' => $secondary,
            'counts' => $counts,
            'metricCaptions' => $metricCaptions,
            'breakdown' => $breakdown,
            'createHref' => $createHref,
        ];
    }

    private function findRegisterTable(DOMXPath $xpath, array $config): ?DOMElement
    {
        $tables = $xpath->query('//table');
        if (! $tables) {
            return null;
        }

        foreach ($tables as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }

            $headers = $xpath->query('.//thead//th', $table);
            if (! $headers || $headers->length === 0) {
                continue;
            }

            $labels = [];
            foreach ($headers as $header) {
                $labels[] = $this->lower($header->textContent);
            }

            $hasId = $this->labelsContain($labels, (string) $config['id_header']);
            $hasStatus = $this->labelsContain($labels, (string) $config['status_header']);

            if ($hasId && $hasStatus) {
                return $table;
            }
        }

        return null;
    }

    private function indexes(array $headers, array $config): array
    {
        $indexes = ['id' => -1, 'date' => -1, 'group' => -1, 'secondary' => -1, 'status' => -1];

        foreach ($headers as $index => $header) {
            $label = $this->lower($header);

            if ($indexes['id'] < 0 && str_contains($label, (string) $config['id_header'])) {
                $indexes['id'] = $index;
            }
            if ($indexes['date'] < 0 && str_contains($label, (string) $config['date_header'])) {
                $indexes['date'] = $index;
            }
            if ($indexes['group'] < 0 && str_contains($label, (string) $config['group_header'])) {
                $indexes['group'] = $index;
            }
            if ($indexes['secondary'] < 0 && str_contains($label, (string) $config['secondary_header'])) {
                $indexes['secondary'] = $index;
            }
            if ($indexes['status'] < 0 && str_contains($label, (string) $config['status_header'])) {
                $indexes['status'] = $index;
            }
        }

        return $indexes;
    }

    private function labelsContain(array $labels, string $needle): bool
    {
        foreach ($labels as $label) {
            if (str_contains($label, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function actionHref(DOMXPath $xpath, DOMElement $row, int $idIndex): string
    {
        $links = $xpath->query('.//a[@href]', $row);
        $fallback = '';

        if ($links) {
            foreach ($links as $link) {
                if (! $link instanceof DOMElement) {
                    continue;
                }

                $href = trim((string) $link->getAttribute('href'));
                if ($href === '') {
                    continue;
                }

                if ($fallback === '') {
                    $fallback = $href;
                }

                $label = $this->lower($link->textContent);
                if (
                    $label === 'open'
                    || $label === 'view'
                    || $label === 'details'
                    || str_contains($label, 'open')
                ) {
                    return $href;
                }
            }
        }

        if ($idIndex >= 0) {
            $cells = $xpath->query('./td', $row);
            if ($cells && $cells->length > $idIndex) {
                $idLinks = $xpath->query('.//a[@href]', $cells->item($idIndex));
                if ($idLinks && $idLinks->length > 0 && $idLinks->item(0) instanceof DOMElement) {
                    return trim((string) $idLinks->item(0)->getAttribute('href'));
                }
            }
        }

        return $fallback;
    }

    private function findCreateHref(DOMXPath $xpath, array $tokens): string
    {
        if ($tokens === []) {
            return '';
        }

        $links = $xpath->query('//a[@href]');
        if (! $links) {
            return '';
        }

        foreach ($links as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $label = $this->lower($link->textContent);
            foreach ($tokens as $token) {
                if (str_contains($label, $this->lower((string) $token))) {
                    return trim((string) $link->getAttribute('href'));
                }
            }
        }

        return '';
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }

    private function uniqueValues(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $label = $this->clean((string) $value);
            if ($label !== '') {
                $result[$this->lower($label)] = $label;
            }
        }
        ksort($result, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($result);
    }

    private function countStatus(array $rows, string $status): int
    {
        return count(array_filter(
            $rows,
            static fn (array $row): bool => ($row['status'] ?? '') === $status
        ));
    }

    private function metricCaptions(array $rows, array $config, array $counts): array
    {
        if (($config['key'] ?? '') !== 'bookings') {
            $total = max(1, (int) $counts['total']);
            return [
                'total' => ['badge' => '100%', 'caption' => 'Current register', 'tone' => 'flat'],
                'pending' => ['badge' => round(((int) $counts['pending'] / $total) * 100).'%', 'caption' => 'of total', 'tone' => 'flat'],
                'approved' => ['badge' => round(((int) $counts['approved'] / $total) * 100).'%', 'caption' => 'of total', 'tone' => 'flat'],
                'fourth' => ['badge' => round(((int) $counts['fourth'] / $total) * 100).'%', 'caption' => 'of total', 'tone' => 'flat'],
            ];
        }

        $currentStart = now()->copy()->subDays(29)->startOfDay();
        $previousStart = now()->copy()->subDays(59)->startOfDay();
        $previousEnd = now()->copy()->subDays(30)->endOfDay();

        $trend = function (?string $status) use ($rows, $currentStart, $previousStart, $previousEnd): array {
            $current = 0;
            $previous = 0;

            foreach ($rows as $row) {
                if ($status !== null && ($row['status'] ?? '') !== $status) {
                    continue;
                }

                $date = $row['date'] ?? '';
                if ($date === '') {
                    continue;
                }

                try {
                    $value = \Carbon\Carbon::parse($date);
                } catch (\Throwable) {
                    continue;
                }

                if ($value->greaterThanOrEqualTo($currentStart)) {
                    $current++;
                } elseif ($value->betweenIncluded($previousStart, $previousEnd)) {
                    $previous++;
                }
            }

            if ($previous === 0) {
                return [
                    'badge' => $current === 0 ? '0%' : 'New',
                    'caption' => 'vs last 30 days',
                    'tone' => $current === 0 ? 'flat' : 'up',
                ];
            }

            $pct = (int) round((($current - $previous) / $previous) * 100);
            return [
                'badge' => ($pct >= 0 ? '↑ ' : '↓ ').abs($pct).'%',
                'caption' => 'vs last 30 days',
                'tone' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
            ];
        };

        return [
            'total' => $trend(null),
            'pending' => $trend((string) $config['pending_status']),
            'approved' => $trend((string) $config['approved_status']),
            'fourth' => $trend((string) $config['fourth_status']),
        ];
    }

    private function breakdown(array $rows, array $config): array
    {
        $counts = [];

        foreach ($rows as $row) {
            if (($config['key'] ?? '') === 'sales-invoices') {
                $label = $row['status_label'] ?: ucfirst(str_replace('_', ' ', (string) $row['status']));
            } else {
                $label = $row['secondary'] ?: 'Other';
            }

            $key = $this->lower($label);
            if (! isset($counts[$key])) {
                $counts[$key] = ['label' => $label, 'count' => 0];
            }
            $counts[$key]['count']++;
        }

        usort($counts, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($counts, 0, 5);
    }

    private function statusKey(string $value): string
    {
        $text = $this->lower(str_replace(['_', '-'], ' ', $value));

        if ($text === '') return 'draft';
        if (str_contains($text, 'pending') || str_contains($text, 'awaiting')) return 'pending_approval';
        if (str_contains($text, 'cancel') || str_contains($text, 'void') || str_contains($text, 'revers')) return 'cancelled';
        if (str_contains($text, 'travel ready') || str_contains($text, 'closed') || str_contains($text, 'complete')) return 'closed';
        if (str_contains($text, 'posted') || str_contains($text, 'paid')) return 'posted';
        if (str_contains($text, 'approved')) return 'approved';
        if (str_contains($text, 'confirm')) return 'confirmed';
        if (str_contains($text, 'draft')) return 'draft';

        return str_replace(' ', '_', $text);
    }

    private function dateKey(string $value): string
    {
        $value = $this->clean($value);
        if ($value === '') return '';

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd M Y', 'd F Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date instanceof \DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function clean(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function lower(?string $value): string
    {
        return mb_strtolower($this->clean($value));
    }

    private function replaceMain(string $html, string $fragment): ?string
    {
        $count = 0;
        $result = preg_replace_callback(
            '/<main\b([^>]*)>[\s\S]*?<\/main>/i',
            static function (array $match) use ($fragment): string {
                $attributes = $match[1] ?? '';
                if (! str_contains($attributes, 'data-et-register-workspace')) {
                    $attributes .= ' data-et-register-workspace="ERP-11.3.239"';
                }
                return '<main'.$attributes.'>'.$fragment.'</main>';
            },
            $html,
            1,
            $count
        );

        return $count === 1 && is_string($result) ? $result : null;
    }

    private function markHtml(string $html, string $key): string
    {
        return preg_replace_callback(
            '/<html\b([^>]*)>/i',
            static function (array $match) use ($key): string {
                $attributes = $match[1] ?? '';
                $classes = 'et-booking-register-reference et-register-workspace-server et-register-'.$key;

                if (preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attributes, $classMatch)) {
                    $replacement = 'class='.$classMatch[1].trim($classMatch[2].' '.$classes).$classMatch[1];
                    $attributes = preg_replace('/\bclass\s*=\s*(["\'])(.*?)\1/i', $replacement, $attributes, 1) ?? $attributes;
                } else {
                    $attributes .= ' class="'.$classes.'"';
                }

                return '<html'.$attributes.' data-et-register-server="ERP-11.3.239">';
            },
            $html,
            1
        ) ?? $html;
    }
}
