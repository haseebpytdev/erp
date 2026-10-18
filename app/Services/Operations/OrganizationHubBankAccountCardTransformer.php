<?php

namespace App\Services\Operations;

use App\Services\Administration\ErpRoleAccessPolicy;
use Illuminate\Http\Request;

/** Adds the bank-account destination to the native Organization hub cards. */
final class OrganizationHubBankAccountCardTransformer
{
    public function __construct(private readonly ErpRoleAccessPolicy $policy)
    {
    }

    public function transform(Request $request, string $html): string
    {
        if ($request->method() !== 'GET' || trim($request->path(), '/') !== 'organization') {
            return $html;
        }

        if (! $this->visibleTo($request->user())) {
            return $html;
        }

        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (! $loaded) return $html;

            $xpath = new \DOMXPath($dom);
            $heading = false;
            foreach ($xpath->query('//*[normalize-space(.) != ""]') ?: [] as $node) {
                if ($node instanceof \DOMElement && strtolower(trim($node->textContent)) === 'organization & fiscal context') {
                    $heading = true;
                    break;
                }
            }
            if (! $heading) return $html;

            $grid = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " settings-grid ")]')->item(0);
            if (! $grid instanceof \DOMElement) return $html;
            foreach ($xpath->query('.//a[contains(concat(" ", normalize-space(@href), " "), "/accounting/our-bank-accounts")]', $grid) ?: [] as $existing) {
                return $html;
            }

            $card = $dom->createElement('a');
            $card->setAttribute('class', 'settings-card');
            $card->setAttribute('href', $this->routeUrl());
            $title = $dom->createElement('strong', 'Our Bank Accounts');
            $description = $dom->createElement('span', 'Manage company bank account metadata linked to existing cash / bank ledgers.');
            $action = $dom->createElement('b', 'Open →');
            $card->appendChild($title);
            $card->appendChild($description);
            $card->appendChild($action);

            $insertBefore = null;
            foreach ($grid->childNodes as $child) {
                if (! $child instanceof \DOMElement) continue;
                if (str_contains(strtolower($child->textContent), 'fiscal years & periods')) {
                    $insertBefore = $child;
                    break;
                }
            }
            if ($insertBefore) $grid->insertBefore($card, $insertBefore);
            else $grid->appendChild($card);

            return $dom->saveHTML() ?: $html;
        } catch (\Throwable) {
            return $html;
        }
    }

    private function visibleTo(mixed $user): bool
    {
        return $this->policy->isSuperAdmin($user) || $this->policy->moduleAllowed($user, 'foundation');
    }

    private function routeUrl(): string
    {
        try {
            return route('accounting.our-bank-accounts.index');
        } catch (\Throwable) {
            return '/accounting/our-bank-accounts';
        }
    }
}
