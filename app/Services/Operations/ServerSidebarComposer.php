<?php

namespace App\Services\Operations;

/** Presentation-only canonicalizer for the already-authorized native sidebar. */
final class ServerSidebarComposer
{
    // Existing native hrefs preserved; configuration never grants authorization.
    public function compose(string $html): string
    {
        $groups = [
            'OPERATIONS' => ['bookings', 'passengers', 'sales invoices', 'supplier costing'],
            'ACCOUNTING' => ['receipts', 'payments', 'expense vouchers', 'contra vouchers', 'chart of accounts', 'account mappings', 'journals', 'ledgers', 'reports'],
            'MASTER DATA' => ['party master', 'travel masters', 'products & services'],
            'ADMINISTRATION' => ['organization', 'currency rates', 'financial years', 'health & updates', 'administration', 'foundation'],
        ];
        if (!class_exists(\DOMDocument::class)) return $html;
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$loaded) return $html;
        $xpath = new \DOMXPath($dom);
        $sidebar = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " sidebar ") or contains(concat(" ", normalize-space(@class), " "), " sidebar-menu ") or contains(concat(" ", normalize-space(@class), " "), " side-nav ") or contains(concat(" ", normalize-space(@class), " "), " navbar-vertical ")]')->item(0);
        if (!$sidebar) return $html;
        $root = $sidebar->tagName === 'ul' ? $sidebar : $xpath->query('.//ul[li] | .//nav[ul]', $sidebar)->item(0);
        if (!$root) return $html;
        $rows = [];
        foreach ($root->childNodes as $node) if ($node instanceof \DOMElement && strtolower($node->tagName) === 'li') $rows[] = $node;
        if (!$rows) return $html;
        $known = [];
        $unknown = [];
        foreach ($rows as $row) {
            $a = null;
            foreach ($row->childNodes as $child) if ($child instanceof \DOMElement && strtolower($child->tagName) === 'a') { $a = $child; break; }
            if (!$a) { $unknown[] = $row; continue; }
            $label = strtolower(trim(preg_replace('/\s+/', ' ', $a->textContent)));
            $known[$label] ??= $row;
        }
        if (!isset($known['dashboard'])) return $html;
        $out = [$known['dashboard']];
        foreach ($groups as $heading => $labels) {
            $section = [];
            foreach ($labels as $label) if (isset($known[$label])) $section[] = $known[$label];
            if ($section) {
                $headingNode = $dom->createElement('li', $heading);
                $headingNode->setAttribute('class', 'et-sidebar-section-heading');
                $headingNode->setAttribute('aria-hidden', 'true');
                $out[] = $headingNode;
                foreach ($section as $node) $out[] = $node;
            }
        }
        $used = array_merge(['dashboard'], array_merge(...array_values($groups)));
        foreach ($known as $label => $row) if (!in_array($label, $used, true)) $unknown[] = $row;
        foreach ($rows as $node) $root->removeChild($node);
        foreach (array_merge($out, $unknown) as $node) $root->appendChild($node);
        $root->setAttribute('data-et-server-sidebar', '1');
        return $dom->saveHTML();
    }
}
