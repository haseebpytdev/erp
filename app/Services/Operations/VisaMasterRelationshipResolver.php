<?php

namespace App\Services\Operations;

/**
 * Resolves the only supported Visa accounting chain:
 * Saudi Visa Company -> Pakistani IATA -> legitimate ERP Vendor Party.
 *
 * This class is deliberately storage-agnostic so the relationship rules can
 * be regression-tested without booting Laravel or creating parallel masters.
 */
final class VisaMasterRelationshipResolver
{
    /**
     * @param list<array<string,mixed>> $iatas
     * @param list<array<string,mixed>> $saudis
     * @param list<array{id:int,name:string}> $vendors
     * @return array{iatas:list<array<string,mixed>>,saudis:list<array<string,mixed>>}
     */
    public function resolve(array $iatas, array $saudis, array $vendors): array
    {
        $vendorById = [];
        $vendorByName = [];
        foreach ($vendors as $vendor) {
            $id = (int) ($vendor['id'] ?? 0);
            $name = trim((string) ($vendor['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $vendorById[$id] = ['id' => $id, 'name' => $name];
            $vendorByName[$this->norm($name)][] = ['id' => $id, 'name' => $name];
        }

        $resolvedIatas = [];
        foreach ($iatas as $iata) {
            $vendor = null;
            $vendorId = (int) ($iata['vendor_id'] ?? 0);
            if ($vendorId > 0) {
                $vendor = $vendorById[$vendorId] ?? null;
            }
            if ($vendor === null) {
                $reference = $this->norm((string) ($iata['vendor_reference'] ?? ''));
                $matches = $reference !== '' ? ($vendorByName[$reference] ?? []) : [];
                $vendor = count($matches) === 1 ? $matches[0] : null;
            }

            $iata['vendor_id'] = (int) ($vendor['id'] ?? 0);
            $iata['vendor_name'] = (string) (($vendor['name'] ?? '') ?: '—');
            $iata['status'] = $iata['vendor_id'] > 0 ? 'READY' : 'VENDOR LINK REQUIRED';
            $resolvedIatas[] = $iata;
        }

        usort($resolvedIatas, fn (array $a, array $b): int => strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        $iataByKey = [];
        $iataByTableAndId = [];
        $iataById = [];
        $iataByName = [];
        foreach ($resolvedIatas as $iata) {
            $key = (string) ($iata['master_key'] ?? '');
            if ($key !== '') {
                $iataByKey[$key] = $iata;
            }
            $tableId = (string) ($iata['source_table'] ?? '') . ':' . (int) ($iata['id'] ?? 0);
            $iataByTableAndId[$tableId] = $iata;
            $iataById[(int) ($iata['id'] ?? 0)][] = $iata;
            $iataByName[$this->norm((string) ($iata['name'] ?? ''))][] = $iata;
        }

        $resolvedSaudis = [];
        foreach ($saudis as $saudi) {
            $iata = null;
            $linkedKey = trim((string) ($saudi['linked_iata_master_key'] ?? ''));
            $linkedId = (int) ($saudi['linked_iata_id'] ?? 0);

            if ($linkedKey !== '') {
                $iata = $iataByKey[$linkedKey] ?? null;
            }
            if ($iata === null && $linkedId > 0) {
                $sameTableKey = (string) ($saudi['source_table'] ?? '') . ':' . $linkedId;
                $iata = $iataByTableAndId[$sameTableKey] ?? null;
                if ($iata === null) {
                    $matches = $iataById[$linkedId] ?? [];
                    $iata = count($matches) === 1 ? $matches[0] : null;
                }
            }
            if ($iata === null) {
                $name = $this->norm((string) ($saudi['linked_iata_name'] ?? ''));
                $matches = $name !== '' ? ($iataByName[$name] ?? []) : [];
                $iata = count($matches) === 1 ? $matches[0] : null;
            }

            $saudi['pakistani_iata_id'] = (int) ($iata['id'] ?? 0);
            $saudi['pakistani_iata_master_key'] = (string) ($iata['master_key'] ?? '');
            $saudi['pakistani_iata_source_table'] = (string) ($iata['source_table'] ?? '');
            $saudi['pakistani_iata_name'] = (string) (($iata['name'] ?? '') ?: '—');
            $saudi['vendor_id'] = (int) ($iata['vendor_id'] ?? 0);
            $saudi['vendor_name'] = (string) (($iata['vendor_name'] ?? '') ?: '—');
            // Surface the linked native Pakistan Visa / IATA contact footer on
            // the resolved Saudi relationship consumed by Visa booking rows.
            $saudi['voucher_footer'] = trim((string) ($iata['voucher_footer'] ?? ''));
            $saudi['status'] = $iata === null
                ? 'IATA LINK REQUIRED'
                : ($saudi['vendor_id'] > 0 ? 'READY' : 'VENDOR LINK REQUIRED');
            $saudi['link_complete'] = $saudi['status'] === 'READY';
            $resolvedSaudis[] = $saudi;
        }

        usort($resolvedSaudis, fn (array $a, array $b): int => strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return ['iatas' => array_values($resolvedIatas), 'saudis' => array_values($resolvedSaudis)];
    }

    private function norm(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }
}
