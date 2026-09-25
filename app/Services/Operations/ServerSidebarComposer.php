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
            'TRAVEL REPORTS' => ['travel reports', 'movement reports'],
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
        $root = $sidebar->tagName === 'ul' ? $sidebar : $xpath->query('.//ul[li]', $sidebar)->item(0);
        if (!$root) $root = $sidebar->tagName === 'nav' ? $xpath->query('.//ul[li]', $sidebar)->item(0) : $xpath->query('.//nav[ul]', $sidebar)->item(0);
        if (!$root) {
            // Some native shells use NAV/DIV/A rather than UL/LI. Preserve
            // that host structure and add the authorized report section once.
            $container = $xpath->query('.//nav[.//a]', $sidebar)->item(0) ?: $sidebar;
            $hasTravelReports = false;
            foreach ($xpath->query('.//a', $container) as $anchor) {
                if (strtolower(trim(preg_replace('/\s+/', ' ', $anchor->textContent))) === 'travel reports') {
                    $hasTravelReports = true;
                    break;
                }
            }
            if ($hasTravelReports) return $html;
            $section = $dom->createElement('div');
            $section->setAttribute('class', 'et-sidebar-report-section');
            $heading = $dom->createElement('span', 'REPORTS');
            $heading->setAttribute('class', 'et-sidebar-section-heading');
            $section->appendChild($heading);
            $anchor = $dom->createElement('a', 'Travel Reports');
            $anchor->setAttribute('href', '/travel-reports');
            $section->appendChild($anchor);
            // Identify complete semantic section wrappers, not individual links.
            // If the native shell cannot be proven safe, preserve it unchanged;
            // Reports must never fall through to the physical end of the menu.
            $normalize = static fn(string $value): string => strtolower(trim(preg_replace('/\s+/', ' ', $value)));
            $findHeading = static function (\DOMElement $block, string $label) use ($normalize): ?\DOMElement {
                foreach ($block->getElementsByTagName('*') as $candidate) {
                    if ($normalize($candidate->textContent) === strtolower($label)) return $candidate;
                }
                return null;
            };
            $sectionParent = null; $accounting = null; $system = null; $footer = null;
            $candidateParents = [$container];
            foreach ($xpath->query('.//*', $container) as $candidate) $candidateParents[] = $candidate;
            foreach ($candidateParents as $candidateParent) {
                $children = [];
                foreach ($candidateParent->childNodes as $child) if ($child instanceof \DOMElement) $children[] = $child;
                if (count($children) < 3) continue;
                $a = null; $s = null; $f = null;
                foreach ($children as $child) {
                    if (!$a && $findHeading($child, 'ACCOUNTING')) $a = $child;
                    if (!$s && $findHeading($child, 'SYSTEM')) $s = $child;
                    $class = strtolower($child->getAttribute('class').' '.$child->getAttribute('id'));
                    if (!$f && preg_match('/(?:release|footer)/', $class)) $f = $child;
                }
                if ($a && $s && $f && array_search($a, $children, true) < array_search($s, $children, true) && array_search($s, $children, true) < array_search($f, $children, true)) {
                    $sectionParent = $candidateParent; $accounting = $a; $system = $s; $footer = $f; break;
                }
            }
            if (!$sectionParent || !$accounting || !$system || !$footer) return $html;
            $sectionParent->insertBefore($section, $system);
            return $this->replaceFragment($html, $container, $dom->saveHTML($container));
        }
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
        // Report Center remains an internal/direct route, not a visible sidebar item.
        // Deferred route labels (Booking Report, Passenger Report, Air / Ticketing Report,
        // Hotel Report, Visa Report, Transport Report, Group Umrah Report,
        // Customer-wise Report, Supplier / Vendor-wise Report, Branch-wise Report,
        // Agent / Salesperson Report, Airline-wise Report, Sector / Destination Report)
        // remain route-only and are intentionally not synthesized here.
        // Travel Reports are repo-owned operational links. Authorization is
        // still enforced by EnforceErpRoleScopedAccess; this layer only adds
        // presentation links so direct URLs and sidebar share the same policy.
        foreach ([['Travel Reports','/travel-reports'],['Movement Reports','/travel-reports/group-umrah/arrival']] as [$label,$href]) {
            $key = strtolower($label);
            if (isset($known[$key])) continue;
            $row = $dom->createElement('li');
            $anchor = $dom->createElement('a', $label);
            $anchor->setAttribute('href', $href);
            $row->appendChild($anchor);
            $known[$key] = $row;
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
        $fragment = $dom->saveHTML($root);
        $opening = [];
        preg_match('/<'.preg_quote($root->tagName, '/').'\b[^>]*class=["\'][^"\']*(?:sidebar|side-nav|navbar-vertical|sidebar-menu)[^"\']*["\'][^>]*>/i', $html, $opening, PREG_OFFSET_CAPTURE);
        if (!$opening) {
            // Correlate an inner root by its own stable id/class identity;
            // never choose the first same-tag list in the outer container.
            $id = $root->getAttribute('id');
            $classes = trim($root->getAttribute('class'));
            if ($id !== '') {
                if (substr_count($html, 'id="'.$id.'"') + substr_count($html, "id='".$id."'") !== 1) return $html;
                preg_match('/<'.preg_quote($root->tagName, '/').'\b[^>]*\bid=["\']'.preg_quote($id, '/').'["\'][^>]*>/i', $html, $opening, PREG_OFFSET_CAPTURE);
            } elseif ($classes !== '') {
                $classPattern = preg_quote($classes, '/');
                preg_match_all('/<'.preg_quote($root->tagName, '/').'\\b[^>]*\\bclass=["\\\']'.$classPattern.'["\\\'][^>]*>/i', $html, $classMatches);
                if (count($classMatches[0]) !== 1) return $html;
                $opening = [[$classMatches[0][0], strpos($html, $classMatches[0][0])]];
            }
        }
        if (!$fragment || !$opening) {
            return $html;
        }
        $start = $opening[0][1];
        $tag = $root->tagName;
        preg_match_all('/<\/?'.preg_quote($tag, '/').'\b[^>]*>/i', $html, $tokens, PREG_OFFSET_CAPTURE, $start);
        $depth = 0; $end = null;
        foreach ($tokens[0] as $token) {
            if (str_starts_with($token[0], '</')) { $depth--; if ($depth === 0) { $end = $token[1] + strlen($token[0]); break; } }
            elseif (!str_ends_with(trim($token[0]), '/>')) $depth++;
        }
        if ($end === null) return $html;
        return substr($html, 0, $start).$fragment.substr($html, $end);
    }

    /** Replace only a uniquely identified sidebar fragment in the original response. */
    private function replaceFragment(string $html, \DOMElement $node, string $fragment): string
    {
        $tag = preg_quote($node->tagName, '/');
        $class = trim($node->getAttribute('class'));
        $pattern = '/<'.$tag.'\b[^>]*class=["\'][^"\']*'.preg_quote($class, '/').'[^"\']*["\'][^>]*>/i';
        if ($class === '' || ! preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE)) return $html;
        $start = $match[0][1];
        preg_match_all('/<\/?'.$tag.'\b[^>]*>/i', $html, $tokens, PREG_OFFSET_CAPTURE, $start);
        $depth = 0;
        foreach ($tokens[0] as $token) {
            if (str_starts_with($token[0], '</')) {
                $depth--;
                if ($depth === 0) {
                    $end = $token[1] + strlen($token[0]);
                    return substr($html, 0, $start).$fragment.substr($html, $end);
                }
            } elseif (! str_ends_with(trim($token[0]), '/>')) {
                $depth++;
            }
        }
        return $html;
    }
}
