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
        if (!preg_match('/(<(?:nav|ul)\b[^>]*class=["\'][^"\']*(?:sidebar|side-nav|navbar-vertical|sidebar-menu)[^"\']*["\'][^>]*>)(.*?)(<\/(?:nav|ul)>)/is', $html, $match, PREG_OFFSET_CAPTURE)) return $html;
        $body = $match[2][0];
        preg_match_all('/<li\b[^>]*>.*?<\/li>/is', $body, $rows);
        if (!$rows[0]) return $html;
        $known = [];
        $unknown = [];
        foreach ($rows[0] as $row) {
            if (!preg_match('/<a\b[^>]*href=["\'][^"\']+["\'][^>]*>(.*?)<\/a>/is', $row, $a)) { $unknown[] = $row; continue; }
            $label = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($a[1]))));
            $known[$label] ??= $row;
        }
        if (!isset($known['dashboard'])) return $html;
        $out = [$known['dashboard']];
        foreach ($groups as $heading => $labels) {
            $section = [];
            foreach ($labels as $label) if (isset($known[$label])) $section[] = $known[$label];
            if ($section) $out[] = '<li class="et-sidebar-section-heading" aria-hidden="true">'.$heading.'</li>'.implode('', $section);
        }
        $used = array_merge(['dashboard'], array_merge(...array_values($groups)));
        foreach ($known as $label => $row) if (!in_array($label, $used, true)) $unknown[] = $row;
        $out = implode('', $out).implode('', $unknown);
        if (count($known) !== count($rows[0])) return $html;
        $replacement = rtrim($match[1][0], '>').' data-et-server-sidebar="1">'.$out.$match[3][0];
        return substr_replace($html, $replacement, $match[0][1], strlen($match[0][0]));
    }
}
