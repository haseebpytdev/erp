<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\View;

class NativeErpLayoutResolver
{
    public function resolve(): array
    {
        $fromBookingView = $this->fromExistingBookingViews();
        if ($fromBookingView) {
            return $fromBookingView;
        }

        foreach ([
            'layouts.app',
            'layouts.admin',
            'layouts.erp',
            'layouts.dashboard',
            'layouts.master',
            'admin.layouts.app',
            'admin.layouts.master',
            'layout.app',
            'layout.master',
        ] as $layout) {
            if (! View::exists($layout)) {
                continue;
            }

            return [
                'layout' => $layout,
                'content_section' => $this->detectYield($layout) ?: 'content',
                'title_section' => 'title',
            ];
        }

        // Existing Easy Ticket builds use layouts.app in the overwhelming majority
        // of installations. Keeping this as a final fallback is safer than shipping
        // another standalone HTML shell/sidebar.
        return [
            'layout' => 'layouts.app',
            'content_section' => 'content',
            'title_section' => 'title',
        ];
    }

    private function fromExistingBookingViews(): ?array
    {
        foreach ([
            'operations.bookings.show',
            'operations.bookings.index',
            'operations.bookings.create',
            'operations.bookings.edit',
        ] as $viewName) {
            if (! View::exists($viewName)) {
                continue;
            }

            try {
                $path = View::getFinder()->find($viewName)->getPath();
                $source = (string) file_get_contents($path);
            } catch (\Throwable) {
                continue;
            }

            if (! preg_match('/@extends\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $source, $match)) {
                continue;
            }

            $layout = $match[1];
            $contentSection = $this->detectPrimarySection($source) ?: $this->detectYield($layout) ?: 'content';

            return [
                'layout' => $layout,
                'content_section' => $contentSection,
                'title_section' => 'title',
            ];
        }

        return null;
    }

    private function detectPrimarySection(string $source): ?string
    {
        if (! preg_match_all('/@section\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches)) {
            return null;
        }

        foreach (['content', 'main', 'body', 'page-content', 'page_content', 'page'] as $preferred) {
            if (in_array($preferred, $matches[1], true)) {
                return $preferred;
            }
        }

        $ignore = ['title', 'page-title', 'page_title', 'header', 'page-header', 'page_header', 'styles', 'style', 'scripts', 'script', 'head', 'meta', 'breadcrumbs'];
        foreach ($matches[1] as $section) {
            if (! in_array(strtolower($section), $ignore, true)) {
                return $section;
            }
        }

        return null;
    }

    private function detectYield(string $layout): ?string
    {
        if (! View::exists($layout)) {
            return null;
        }

        try {
            $path = View::getFinder()->find($layout)->getPath();
            $source = (string) file_get_contents($path);
        } catch (\Throwable) {
            return null;
        }

        if (! preg_match_all('/@yield\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches)) {
            return null;
        }

        foreach (['content', 'main', 'body', 'page-content', 'page_content', 'page'] as $preferred) {
            if (in_array($preferred, $matches[1], true)) {
                return $preferred;
            }
        }

        return $matches[1][0] ?? null;
    }
}
