<?php

namespace App\Services\Organization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only adapter for the native /organization/company authority.
 *
 * Native authority (verified against the live Company Profile form):
 * - model: App\Models\Company
 * - table: companies
 * - identity: name (legal_name is the secondary identity)
 * - report logo: report_logo
 * - voucher fallback: voucher_footer_html
 *
 * The booking company is authoritative. A branch or signed-in user may supply
 * that company only when the booking itself has no company_id. We never choose
 * the first company from a multi-company installation.
 */
final class CompanyProfileSnapshotService
{
    private CompanyReportLogoValueResolver $reportLogo;

    public function __construct(?CompanyReportLogoValueResolver $reportLogo = null)
    {
        $this->reportLogo = $reportLogo ?? new CompanyReportLogoValueResolver();
    }

    /** @param array<string,mixed> $bookingContext */
    public function get(array $bookingContext = []): array
    {
        $defaults = [
            'id' => null,
            'name' => '',
            'subtitle' => '',
            'address' => '',
            'phone' => '',
            'email' => '',
            'website' => '',
            'logo' => null,
            'footer' => '',
        ];

        if (! Schema::hasTable('companies')) {
            return $defaults;
        }

        try {
            $companyId = $this->companyId($bookingContext);
            $company = $this->companyRecord($companyId);
            if ($company === null) {
                return $defaults;
            }

            return [
                'id' => $this->integer($company, 'id'),
                'name' => $this->text($company, 'name'),
                'subtitle' => $this->text($company, 'legal_name'),
                'address' => $this->text($company, 'address'),
                'phone' => $this->text($company, 'phone'),
                'email' => $this->text($company, 'email'),
                'website' => $this->text($company, 'website'),
                'logo' => $this->logoUrl($this->reportLogo->resolve($company)),
                'footer' => $this->text($company, 'voucher_footer_html'),
            ];
        } catch (Throwable $e) {
            report($e);
            return $defaults;
        }
    }

    /** @param array<string,mixed> $bookingContext */
    private function companyId(array $bookingContext): ?int
    {
        $bookingCompanyId = (int) ($bookingContext['company_id'] ?? 0);
        if ($bookingCompanyId > 0) {
            return $bookingCompanyId;
        }

        $branchId = (int) ($bookingContext['branch_id'] ?? 0);
        if (
            $branchId > 0
            && Schema::hasTable('branches')
            && Schema::hasColumn('branches', 'company_id')
        ) {
            $branchCompanyId = (int) (DB::table('branches')->where('id', $branchId)->value('company_id') ?? 0);
            if ($branchCompanyId > 0) {
                return $branchCompanyId;
            }
        }

        try {
            $userCompanyId = (int) (auth()->user()?->getAttribute('company_id') ?? 0);
            if ($userCompanyId > 0) {
                return $userCompanyId;
            }
        } catch (Throwable) {
        }

        return null;
    }

    private function companyRecord(?int $companyId): object|array|null
    {
        $modelClass = 'App\\Models\\Company';
        if (class_exists($modelClass)) {
            $query = (new $modelClass())->newQuery();
            if ($companyId !== null) {
                return $query->find($companyId);
            }

            $records = $query->limit(2)->get();
            return $records->count() === 1 ? $records->first() : null;
        }

        $query = DB::table('companies');
        if ($companyId !== null) {
            return $query->where('id', $companyId)->first();
        }

        $records = $query->limit(2)->get();
        return $records->count() === 1 ? $records->first() : null;
    }

    private function raw(object|array $record, string $field): mixed
    {
        if (is_array($record)) {
            return $record[$field] ?? null;
        }
        if (method_exists($record, 'getAttribute')) {
            return $record->getAttribute($field);
        }
        return $record->{$field} ?? null;
    }

    private function text(object|array $record, string $field): string
    {
        $value = $this->raw($record, $field);
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function integer(object|array $record, string $field): ?int
    {
        $value = (int) ($this->raw($record, $field) ?? 0);
        return $value > 0 ? $value : null;
    }

    private function logoUrl(mixed $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        if (str_starts_with($stored, 'data:image/')) {
            return $stored;
        }

        $mime = $this->imageMime($stored);
        if ($mime !== null) {
            return 'data:'.$mime.';base64,'.base64_encode($stored);
        }

        $candidate = preg_replace('/\s+/', '', $stored) ?? '';
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $candidate) === 1) {
            $decoded = base64_decode($candidate, true);
            $mime = is_string($decoded) ? $this->imageMime($decoded) : null;
            if ($mime !== null) {
                return 'data:'.$mime.';base64,'.$candidate;
            }
        }

        $path = trim($stored);
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return null;
        }
        if (str_starts_with($path, 'https://') || str_starts_with($path, 'http://')) {
            return $path;
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($relative, 'public/')) {
            $relative = substr($relative, 7);
        }
        if (str_starts_with($relative, 'storage/')) {
            return url('/'.$relative);
        }

        try {
            if (Storage::disk('public')->exists($relative)) {
                return url(Storage::disk('public')->url($relative));
            }
        } catch (Throwable) {
        }

        return asset($relative);
    }

    private function imageMime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A") => 'image/png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }
}
