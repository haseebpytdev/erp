<?php

namespace App\Services\Organization;

use ReflectionClass;
use Throwable;

/**
 * Adapts the native Company model's proven report-logo presentation value.
 *
 * The Company Profile already renders its stored upload as a data URI. Some
 * installations expose that through a computed model helper/accessor rather
 * than the raw upload request attribute. Prefer that native presentation API,
 * then fall back to the exact report_logo attribute/storage member.
 */
final class CompanyReportLogoValueResolver
{
    public function resolve(object|array $company): mixed
    {
        if (is_object($company)) {
            $native = $this->nativePresentationValue($company);
            if ($this->usable($native)) {
                return $native;
            }
        }

        $direct = $this->attribute($company, 'report_logo');
        if ($this->usable($direct)) {
            return $direct;
        }

        if (is_object($company)) {
            if (method_exists($company, 'getAttributes')) {
                try {
                    $attributes = (array) $company->getAttributes();
                    $stored = $this->reportLogoMember($attributes) ?? $this->embeddedImageValue($attributes);
                    if ($this->usable($stored)) {
                        return $stored;
                    }
                } catch (Throwable) {
                }
            }
        }

        $attributes = is_array($company) ? $company : (array) $company;
        return $this->reportLogoMember($attributes) ?? $this->embeddedImageValue($attributes);
    }

    private function nativePresentationValue(object $company): mixed
    {
        try {
            $methods = (new ReflectionClass($company))->getMethods(\ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                if (
                    $method->isStatic()
                    || $method->getNumberOfRequiredParameters() !== 0
                    || preg_match('/^(?:get)?reportLogo(?:DataUri|Url|Src|Source)?$/i', $method->getName()) !== 1
                ) {
                    continue;
                }

                try {
                    $value = $method->invoke($company);
                    if ($this->usable($value)) {
                        return $value;
                    }
                } catch (Throwable) {
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    /** @param array<string,mixed> $attributes */
    private function reportLogoMember(array $attributes): mixed
    {
        foreach ($attributes as $name => $value) {
            $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', (string) $name));
            if (str_contains($normalized, 'report') && str_contains($normalized, 'logo') && $this->usable($value)) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $attributes */
    private function embeddedImageValue(array $attributes): ?string
    {
        foreach ($attributes as $value) {
            if (! $this->usable($value)) {
                continue;
            }

            if (
                str_starts_with($value, 'data:image/')
                || $this->hasImageSignature($value)
            ) {
                return $value;
            }

            $candidate = preg_replace('/\s+/', '', $value) ?? '';
            if ($candidate === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $candidate) !== 1) {
                continue;
            }
            $decoded = base64_decode($candidate, true);
            if (is_string($decoded) && $this->hasImageSignature($decoded)) {
                return $candidate;
            }
        }

        return null;
    }

    private function hasImageSignature(string $value): bool
    {
        return str_starts_with($value, "\xFF\xD8\xFF")
            || str_starts_with($value, "\x89PNG\x0D\x0A\x1A\x0A")
            || (str_starts_with($value, 'RIFF') && substr($value, 8, 4) === 'WEBP');
    }

    private function attribute(object|array $company, string $name): mixed
    {
        if (is_array($company)) {
            return $company[$name] ?? null;
        }

        try {
            if (method_exists($company, 'getAttribute')) {
                return $company->getAttribute($name);
            }
            return $company->{$name} ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function usable(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
