<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * ERP-11.3.152
 *
 * Read adapter over the EXISTING native Travel Masters data used by the
 * "Pakistan Visa / IATA" and "Saudi Visa Companies" tabs. This deliberately
 * does not create a parallel Saudi/IATA master. Visa Rates consume the native
 * master identities and keep only effective-dated commercial snapshots.
 */
final class LegacyVisaTravelMasterRepository
{
    /** @var array{iatas:list<array<string,mixed>>,saudis:list<array<string,mixed>>}|null */
    private ?array $cache = null;

    public function __construct(
        private readonly UnifiedGroupPackageDataSource $dataSource,
        private readonly VisaMasterRelationshipResolver $relationshipResolver,
    )
    {
    }

    /** @return list<array<string,mixed>> */
    public function pakistaniIatas(): array
    {
        return $this->discover()['iatas'];
    }

    /** @return list<array<string,mixed>> */
    public function saudiCompanies(): array
    {
        return $this->discover()['saudis'];
    }

    /** @return array<string,mixed>|null */
    public function findSaudiByKey(string $key): ?array
    {
        foreach ($this->saudiCompanies() as $row) {
            if ((string) ($row['master_key'] ?? '') === $key) {
                return $row;
            }
        }
        return null;
    }

    /** @return array{iatas:list<array<string,mixed>>,saudis:list<array<string,mixed>>} */
    private function discover(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $iatas = [];
        $saudis = [];

        foreach ($this->candidateTables() as $table) {
            if (! Schema::hasTable($table) || str_starts_with($table, 'visa_') || $table === 'booking_visa_services') {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);
            } catch (Throwable) {
                continue;
            }

            $idColumn = $this->firstColumn($columns, ['id', 'master_id', 'record_id']);
            $nameColumn = $this->firstColumn($columns, ['name', 'company_name', 'operator_name', 'display_name', 'legal_name', 'title']);
            if (! $idColumn || ! $nameColumn) {
                continue;
            }

            try {
                $rows = DB::table($table)->orderBy($idColumn)->limit(2500)->get();
            } catch (Throwable) {
                continue;
            }

            foreach ($rows as $object) {
                $row = (array) $object;
                $extra = $this->decodedExtra($row, $columns);
                $kind = $this->classify($table, $row, $extra, $columns);
                if ($kind === null) {
                    continue;
                }

                $id = (int) ($row[$idColumn] ?? 0);
                $name = trim((string) ($row[$nameColumn] ?? ''));
                if ($id <= 0 || $name === '') {
                    continue;
                }

                $base = [
                    'id' => $id,
                    'source_table' => $table,
                    'master_key' => $table . ':' . $id,
                    'name' => $name,
                    'code' => trim((string) $this->value($row, $extra, $columns, ['code', 'master_code', 'company_code', 'operator_code'], '')),
                    'country' => strtoupper(trim((string) $this->value($row, $extra, $columns, ['country', 'country_code', 'country_iso', 'iso_country'], ''))),
                    'phone' => trim((string) $this->value($row, $extra, $columns, ['phone', 'mobile', 'contact_number', 'telephone', 'cell'], '')),
                    'is_active' => $this->activeValue($row, $extra, $columns),
                ];

                if ($kind === 'iata') {
                    $vendorId = $this->relationId($row, $extra, $columns, [
                        'vendor_id', 'default_vendor_id', 'supplier_id', 'default_supplier_id',
                        'default_vendor_party_id',
                        'vendor_party_id', 'supplier_party_id', 'party_id', 'linked_vendor_id', 'linked_supplier_id',
                        'default_supplier_vendor_id', 'default_vendor_account_id', 'default_supplier_account_id',
                        'supplier_vendor_id', 'linked_vendor_account_id', 'payable_vendor_id', 'payable_party_id',
                        'accounting_vendor_id', 'default_party_id', 'defaultSupplierVendorId', 'defaultVendorId',
                        'defaultSupplierId', 'vendorAccountId', 'supplierAccountId',
                        'default_supplier_vendor', 'default_supplier', 'default_vendor', 'vendor', 'supplier',
                        'vendor_account', 'supplier_account',
                    ]);
                    $base['vendor_id'] = $vendorId;
                    $base['vendor_reference'] = trim((string) $this->relationReference($row, $extra, $columns, [
                        'vendor_name', 'supplier_name', 'default_vendor_name', 'default_supplier_name',
                        'default_supplier_vendor_name', 'vendor_account_name', 'supplier_account_name',
                        'linked_vendor_name', 'linked_supplier_name', 'defaultSupplierVendorName',
                        'defaultVendorName', 'defaultSupplierName', 'vendorAccountName', 'supplierAccountName',
                        'default_supplier_vendor',
                        'default_supplier', 'default_vendor', 'vendor', 'supplier', 'vendor_account', 'supplier_account',
                    ]));
                    $base['iata_number'] = trim((string) $this->value($row, $extra, $columns, ['iata_number', 'iata_no', 'iata_code', 'iata'], ''));
                    // Native Travel Masters authority: the Pakistan Visa / IATA
                    // form persists its voucher contact block in this exact field.
                    $base['voucher_footer'] = trim((string) $this->value(
                        $row,
                        $extra,
                        $columns,
                        ['voucher_footer_html'],
                        ''
                    ));
                    $iatas[] = $base;
                    continue;
                }

                $base['linked_iata_id'] = $this->relationId($row, $extra, $columns, [
                    'pakistani_iata_id', 'pakistan_iata_id', 'pakistan_visa_iata_id', 'pakistan_visa_operator_id',
                    'linked_pakistan_iata_operator_id',
                    'linked_pakistani_iata_id', 'linked_pakistan_iata_id', 'linked_operator_id', 'visa_operator_id',
                    'operator_id', 'parent_operator_id', 'linked_master_id', 'parent_master_id',
                    'pakistani_iata_partner_id', 'pakistan_iata_partner_id', 'default_iata_id', 'iata_partner_id',
                    'linked_partner_id', 'parent_id', 'pakistaniIataId', 'linkedIataId', 'iataPartnerId',
                    'pakistani_iata', 'pakistan_iata', 'linked_iata', 'iata_partner', 'visa_operator', 'linked_operator',
                ]);
                $base['linked_iata_master_key'] = trim((string) $this->value($row, $extra, $columns, [
                    'pakistani_iata_master_key', 'pakistan_iata_master_key', 'linked_iata_master_key',
                ], ''));
                $base['linked_iata_name'] = trim((string) $this->value($row, $extra, $columns, [
                    'pakistani_iata_name', 'pakistan_iata_name', 'pakistan_visa_operator_name', 'linked_operator_name',
                    'linked_iata_name', 'iata_partner_name', 'default_iata_name', 'pakistaniIataName',
                    'linkedIataName', 'iataPartnerName',
                ], $this->relationReference($row, $extra, $columns, [
                    'pakistani_iata', 'pakistan_iata', 'linked_iata', 'iata_partner', 'visa_operator', 'linked_operator',
                ])));
                $saudis[] = $base;
            }
        }

        $iatas = collect($iatas)->unique('master_key')->values()->all();
        $saudis = collect($saudis)->unique('master_key')->values()->all();

        return $this->cache = $this->relationshipResolver->resolve($iatas, $saudis, $this->vendorOptions());
    }

    /** @return list<string> */
    private function candidateTables(): array
    {
        $tables = [
            'master_data', 'travel_master_data', 'travel_masters', 'travel_master_entities',
            'travel_master_partners', 'travel_partners', 'travel_companies', 'visa_companies', 'visa_operators',
        ];

        try {
            foreach (Schema::getTables() as $meta) {
                $name = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                if ($name === '') {
                    continue;
                }
                $lower = strtolower($name);
                if (
                    str_contains($lower, 'master') || str_contains($lower, 'visa') || str_contains($lower, 'iata')
                    || str_contains($lower, 'travel') || str_contains($lower, 'partner') || str_contains($lower, 'company')
                ) {
                    $tables[] = $name;
                }
            }
        } catch (Throwable) {
        }

        return array_values(array_unique(array_filter($tables, static fn (string $name): bool => preg_match('/^[A-Za-z0-9_]+$/', $name) === 1)));
    }

    /** @return array<string,mixed> */
    private function decodedExtra(array $row, array $columns): array
    {
        $extra = [];
        foreach (['metadata', 'meta', 'data', 'attributes', 'details', 'extra', 'payload', 'settings'] as $column) {
            if (! in_array($column, $columns, true) || ! isset($row[$column]) || ! is_string($row[$column])) {
                continue;
            }
            $raw = trim($row[$column]);
            if ($raw === '' || (! str_starts_with($raw, '{') && ! str_starts_with($raw, '['))) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $extra = array_replace_recursive($extra, $decoded);
            }
        }
        return $extra;
    }

    private function classify(string $table, array $row, array $extra, array $columns): ?string
    {
        $descriptorParts = [$table];
        foreach ([
            'type', 'category', 'master_type', 'master_category', 'entity_type', 'record_type', 'partner_type',
            'company_type', 'service_type', 'kind', 'group', 'master_group', 'module', 'source_type', 'slug', 'key',
        ] as $key) {
            $value = $this->value($row, $extra, $columns, [$key], '');
            if (is_scalar($value)) {
                $descriptorParts[] = (string) $value;
            }
        }
        $descriptor = $this->norm(implode(' ', $descriptorParts));

        foreach (['saudi visa', 'saudi_visa', 'saudi visa company', 'saudi company visa', 'ksa visa company'] as $needle) {
            if (str_contains($descriptor, $this->norm($needle))) {
                return 'saudi';
            }
        }
        foreach (['pakistan visa iata', 'pakistan visa / iata', 'pakistani iata', 'pakistan iata', 'visa iata operator', 'umrah operator'] as $needle) {
            if (str_contains($descriptor, $this->norm($needle))) {
                return 'iata';
            }
        }

        $lowerTable = strtolower($table);
        if (str_contains($lowerTable, 'saudi') && str_contains($lowerTable, 'visa')) {
            return 'saudi';
        }
        if ((str_contains($lowerTable, 'pakistan') || str_contains($lowerTable, 'iata')) && (str_contains($lowerTable, 'visa') || str_contains($lowerTable, 'iata'))) {
            return 'iata';
        }

        // Native travel_voucher_partners is a SHARED partner table. Never infer
        // Visa/IATA identity merely because the TABLE has IATA/link columns or
        // because the row country is PK/SA. That previously pulled Transport
        // companies (e.g. TRN-* records) into Pakistani IATA reporting.
        //
        // Fallback classification therefore requires ROW-SPECIFIC evidence:
        // an established Visa master code prefix, or a populated Saudi->IATA
        // relationship on that exact row. Explicit type/category descriptors
        // above remain the first and preferred authority.
        $country = strtoupper(trim((string) $this->value($row, $extra, $columns, ['country', 'country_code'], '')));
        $code = strtoupper(trim((string) $this->value($row, $extra, $columns, ['code', 'master_code', 'company_code', 'operator_code'], '')));

        if (preg_match('/^PKI[-_]/', $code) === 1) {
            return 'iata';
        }
        if (preg_match('/^(SVC|SVI|KSV|SAUVISA|SAUDI[-_]?VISA)[-_]/', $code) === 1) {
            return 'saudi';
        }

        $linkedIataId = (int) $this->value($row, $extra, $columns, [
            'pakistani_iata_id', 'pakistan_iata_id', 'pakistan_visa_iata_id', 'pakistan_visa_operator_id',
            'linked_pakistan_iata_operator_id',
            'linked_pakistani_iata_id', 'linked_pakistan_iata_id', 'linked_operator_id', 'visa_operator_id',
        ], 0);
        $linkedIataName = trim((string) $this->value($row, $extra, $columns, [
            'pakistani_iata_name', 'pakistan_iata_name', 'pakistan_visa_operator_name', 'linked_operator_name',
        ], ''));

        if (
            in_array($country, ['SA', 'SAU', 'KSA', 'SAUDI ARABIA'], true)
            && ($linkedIataId > 0 || $linkedIataName !== '')
        ) {
            return 'saudi';
        }

        return null;
    }

    private function activeValue(array $row, array $extra, array $columns): bool
    {
        $value = $this->value($row, $extra, $columns, ['is_active', 'active', 'enabled', 'status'], true);
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int) $value !== 0;
        $text = $this->norm((string) $value);
        return ! in_array($text, ['inactive', 'disabled', 'deleted', '0', 'false', 'no'], true);
    }

    private function value(array $row, array $extra, array $columns, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (in_array($key, $columns, true) && array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
            $nested = $this->recursiveValue($extra, $key);
            if ($nested !== null && $nested !== '') {
                return $nested;
            }
        }
        return $default;
    }

    private function recursiveValue(array $data, string $key): mixed
    {
        if (array_key_exists($key, $data)) return $data[$key];
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->recursiveValue($value, $key);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function relationReference(array $row, array $extra, array $columns, array $keys): string
    {
        $value = $this->value($row, $extra, $columns, $keys, '');
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (! is_array($value)) {
            return '';
        }
        foreach (['name', 'display_name', 'legal_name', 'title', 'label', 'text', 'account_name', 'supplier_name', 'vendor_name'] as $key) {
            $candidate = $this->recursiveValue($value, $key);
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }
        return '';
    }

    private function relationId(array $row, array $extra, array $columns, array $keys): int
    {
        $value = $this->value($row, $extra, $columns, $keys, 0);
        if (is_scalar($value) && is_numeric($value)) {
            return max(0, (int) $value);
        }
        if (! is_array($value)) {
            return 0;
        }
        foreach (['id', 'value', 'party_id', 'vendor_id', 'supplier_id', 'master_id', 'record_id'] as $key) {
            $candidate = $this->recursiveValue($value, $key);
            if (is_scalar($candidate) && is_numeric($candidate)) {
                return max(0, (int) $candidate);
            }
        }
        return 0;
    }

    private function nestedHasAny(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->recursiveValue($data, $key) !== null) return true;
        }
        return false;
    }

    private function hasAny(array $columns, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) return true;
        }
        return false;
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) return $candidate;
        }
        return null;
    }

    /** @return list<array{id:int,name:string}> */
    private function vendorOptions(): array
    {
        try {
            return $this->dataSource->vendors()->map(static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'name' => trim((string) ($row['name'] ?? '')),
            ])->filter(static fn (array $row): bool => $row['id'] > 0 && $row['name'] !== '')->unique('id')->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function norm(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower($value)));
    }
}
