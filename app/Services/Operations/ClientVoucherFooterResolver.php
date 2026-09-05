<?php

namespace App\Services\Operations;

final class ClientVoucherFooterResolver
{
    public function resolve(array $visaRows, ?string $companyProfileFooter): string
    {
        foreach ($visaRows as $row) {
            $row = (array) $row;
            $footer = trim((string) ($row['saudi_company_footer'] ?? ''));
            if ($footer !== '') return $this->safeHtml($footer);
        }

        return $this->safeHtml(trim((string) $companyProfileFooter));
    }

    private function safeHtml(string $value): string
    {
        $value = strip_tags($value, '<br><p><div><span><strong><b><em><i><u>');
        return trim((string) preg_replace_callback(
            '/<(\/?)\s*(br|p|div|span|strong|b|em|i|u)\b[^>]*>/i',
            static fn (array $match): string => '<'.($match[1] === '/' ? '/' : '').strtolower($match[2]).'>',
            $value
        ));
    }
}
