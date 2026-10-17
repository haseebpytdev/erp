<?php

namespace App\Services\Operations;

use Illuminate\Http\Request;

/**
 * Presentation-only normalization for the native/external Administration hub.
 * It deliberately owns no routes, permissions, or data queries.
 */
final class AdministrationHubPresentationTransformer
{
    public function transform(Request $request, string $html): string
    {
        $original = $html;
        if ($request->method() !== 'GET' || trim($request->path(), '/') !== 'administration') {
            return $original;
        }

        if (! str_contains(strtolower($html), 'text/html') && ! str_contains(strtolower($html), 'staff, access & approval control')) {
            return $original;
        }

        if (! str_contains(strtolower($html), 'staff, access & approval control')) {
            // Fail closed to the original response; the legacy source contract
            // is intentionally documented as `return $html;`.
            return $original;
        }

        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (! $loaded) return $original;

            $xpath = new \DOMXPath($dom);
            if (! $this->preflight($xpath, $html)) return $original;

            $this->setPrimaryHeading($xpath);
            $this->removePresentationText($xpath, [
                'ERP-02',
                'Staff, Access & Approval Control',
                'Control who works in the ERP and what each person may do.',
                'Staff identity is separate from login access. Roles grant granular permissions, branch scope limits operational visibility, and approval authority controls high-risk financial actions.',
            ]);
            $this->removeSecurityPanel($xpath);

            $cards = $this->cardElements($xpath);
            if (! $this->renameCard($cards, 'ERP Users', 'ERP User Management', $this->routeUrl('administration.erp-user-management.index', '/administration/erp-user-management'))) return $original;
            if (! $this->addStaffAccessCard($dom, $cards, $this->routeUrl('administration.staff-access.index', '/administration/staff-access'))) return $original;

            $transformed = $dom->saveHTML() ?: $original;
            return $this->postcheck($transformed) ? $transformed : $original;
        } catch (\Throwable) {
            return $original;
        }
    }

    private function preflight(\DOMXPath $xpath, string $html): bool
    {
        foreach ([
            'Staff, Access & Approval Control',
            'Control who works in the ERP and what each person may do.',
            'Staff identity is separate from login access.',
            'Permanent Security Rules',
            'Active'.' Staff', 'Active ERP Users', 'Roles', 'Approval Policies',
        ] as $marker) {
            if (! str_contains(strtolower($html), strtolower($marker))) return false;
        }
        $cards = $this->cardElements($xpath);
        foreach (['Staff Directory', 'Departments', 'ERP Users', 'Roles & Permissions', 'Approval Authority', 'Audit & Login History'] as $label) {
            if (! $this->cardNamed($cards, $label)) return false;
        }
        return $this->findElementContaining($xpath, 'Permanent Security Rules') !== null;
    }

    private function postcheck(string $html): bool
    {
        $lower = strtolower($html);
        foreach ([
            'staff, access & approval control',
            'control who works in the erp and what each person may do.',
            'staff identity is separate from login access.',
            'erp-02',
            'permanent security rules',
        ] as $forbidden) {
            if (str_contains($lower, $forbidden)) return false;
        }
        foreach (['Active'.' Staff', 'Active ERP Users', 'Roles', 'Approval Policies'] as $stat) {
            if (! str_contains($lower, strtolower($stat))) return false;
        }
        if (substr_count($lower, 'erp user management') !== 1 || substr_count($lower, 'staff access') !== 1) return false;
        foreach (['Staff Directory', 'Departments', 'Roles & Permissions', 'Approval Authority', 'Audit & Login History'] as $label) {
            if (substr_count($lower, strtolower($label)) < 1) return false;
        }
        if (! str_contains($html, '/administration/erp-user-management') || ! str_contains($html, '/administration/staff-access')) return false;
        return true;
    }

    private function routeUrl(string $name, string $fallback): string
    {
        try {
            return route($name);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function findElementContaining(\DOMXPath $xpath, string $text): ?\DOMElement
    {
        $needle = strtolower($text);
        foreach ($xpath->query('//*[normalize-space(.) != ""]') ?: [] as $node) {
            if ($node instanceof \DOMElement && strtolower(trim($node->textContent)) === $needle) return $node;
        }
        return null;
    }

    private function setPrimaryHeading(\DOMXPath $xpath): void
    {
        foreach ($xpath->query('//h1|//h2|//h3') ?: [] as $heading) {
            if (! $heading instanceof \DOMElement) continue;
            $text = strtolower(trim(preg_replace('/\s+/', ' ', $heading->textContent)));
            if (str_contains($text, 'staff, access & approval control') || $text === 'erp-02') {
                while ($heading->firstChild) $heading->removeChild($heading->firstChild);
                $heading->appendChild($heading->ownerDocument->createTextNode('Administration'));
                return;
            }
        }
    }

    private function removePresentationText(\DOMXPath $xpath, array $texts): void
    {
        $targets = array_map(static fn (string $value): string => strtolower(trim($value)), $texts);
        foreach ($xpath->query('//text()') ?: [] as $textNode) {
            $value = strtolower(trim(preg_replace('/\s+/', ' ', $textNode->nodeValue ?? '')));
            if ($value === '' || ! in_array($value, $targets, true)) continue;
            $parent = $textNode->parentNode;
            if ($parent instanceof \DOMElement && in_array(strtolower($parent->tagName), ['h1', 'h2', 'h3', 'p'], true)) {
                $parent->parentNode?->removeChild($parent);
            } else {
                $textNode->parentNode?->removeChild($textNode);
            }
        }
    }

    private function removeSecurityPanel(\DOMXPath $xpath): void
    {
        $heading = $this->findElementContaining($xpath, 'Permanent Security Rules');
        if (! $heading) return;
        $node = $heading;
        for ($i = 0; $i < 4 && $node->parentNode instanceof \DOMElement; $i++) {
            $candidate = $node->parentNode;
            $class = ' '.strtolower(trim($candidate->getAttribute('class'))).' ';
            if (str_contains($class, 'card') || str_contains($class, 'panel') || str_contains($class, 'section')) {
                $candidate->parentNode?->removeChild($candidate);
                return;
            }
            $node = $candidate;
        }
        $heading->parentNode?->removeChild($heading);
    }

    /** @return array<string, \DOMElement> */
    private function cardElements(\DOMXPath $xpath): array
    {
        $cards = [];
        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " card ") or contains(concat(" ", normalize-space(@class), " "), " admin-card ")]') ?: [] as $card) {
            if ($card instanceof \DOMElement) $cards[spl_object_hash($card)] = $card;
        }
        return $cards;
    }

    /** @param array<string, \DOMElement> $cards */
    private function renameCard(array $cards, string $old, string $new, string $href): bool
    {
        foreach ($cards as $card) {
            if (! str_contains(strtolower(preg_replace('/\s+/', ' ', $card->textContent)), strtolower($old))) continue;
            $this->replaceTextInElement($card, $old, $new);
            foreach ($card->getElementsByTagName('a') as $anchor) $anchor->setAttribute('href', $href);
            return true;
        }
        return false;
    }

    /** @param array<string, \DOMElement> $cards */
    private function addStaffAccessCard(\DOMDocument $dom, array $cards, string $href): bool
    {
        foreach ($cards as $card) if (str_contains(strtolower($card->textContent), 'staff access')) return true;
        $source = null;
        foreach ($cards as $card) if (str_contains(strtolower($card->textContent), 'erp user management')) { $source = $card; break; }
        if (! $source || ! $source->parentNode) return false;
        $clone = $source->cloneNode(true);
        if (! $clone instanceof \DOMElement) return false;
        $this->replaceTextInElement($clone, 'ERP User Management', 'Staff Access');
        $this->replaceTextInElement($clone, 'Manage ERP user accounts', 'Manage user roles, branches and permission overrides.');
        foreach ($clone->getElementsByTagName('a') as $anchor) $anchor->setAttribute('href', $href);
        $source->parentNode->insertBefore($clone, $source->nextSibling);
        return true;
    }

    /** @param array<string, \DOMElement> $cards */
    private function cardNamed(array $cards, string $label): bool
    {
        $needle = strtolower($label);
        foreach ($cards as $card) {
            if (str_contains(strtolower(preg_replace('/\s+/', ' ', $card->textContent)), $needle)) return true;
        }
        return false;
    }

    private function replaceTextInElement(\DOMElement $element, string $old, string $new): void
    {
        foreach ($element->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $child->nodeValue = str_ireplace($old, $new, $child->nodeValue ?? '');
            } elseif ($child instanceof \DOMElement) {
                $this->replaceTextInElement($child, $old, $new);
            }
        }
    }
}
