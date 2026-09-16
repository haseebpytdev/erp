<?php

namespace App\Services\Operations;

/** Presentation-only marker for the already-authorized native sidebar. */
final class ServerSidebarComposer
{
    public function compose(string $html): string
    {
        return preg_replace_callback(
            '/(<(?:nav|ul)\b[^>]*class=["\'][^"\']*(?:sidebar|side-nav|navbar-vertical|sidebar-menu)[^"\']*["\'][^>]*>)/i',
            static fn (array $m): string => str_contains($m[1], 'data-et-server-sidebar')
                ? $m[1]
                : rtrim($m[1], '>').' data-et-server-sidebar="1">',
            $html,
            1
        ) ?? $html;
    }
}
