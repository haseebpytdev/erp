<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ERP-11.3.247
 *
 * The installed native SalesInvoiceService is host-owned and is not replaced
 * by the cumulative ERP package. Day-One counters start at 1000, but a host
 * formatter may still render that value as 001000.
 *
 * Normalize only native SI-YEAR-SEQUENCE values and only when sequence >= 1000.
 * No amount, status, workflow, customer, booking or accounting field is touched.
 */
final class NativeSalesInvoiceNumberNormalizer
{
    public function normalize(int $invoiceId): ?string
    {
        if (
            $invoiceId <= 0
            || ! class_exists(\App\Models\SalesInvoice::class)
        ) {
            return null;
        }

        $class = \App\Models\SalesInvoice::class;

        try {
            $model = app($class);
        } catch (Throwable) {
            $model = new $class();
        }

        if (! $model instanceof Model) {
            return null;
        }

        $table = $model->getTable();

        if (! Schema::hasTable($table)) {
            return null;
        }

        $columns = Schema::getColumnListing($table);
        $key = $model->getKeyName();

        if (! in_array($key, $columns, true)) {
            return null;
        }

        $row = (array) (
            DB::table($table)
                ->where($key, $invoiceId)
                ->first()
            ?? []
        );

        $updates = [];
        $normalizedNumber = null;

        foreach ([
            'invoice_number',
            'invoice_no',
            'document_no',
            'number',
        ] as $column) {
            if (! in_array($column, $columns, true)) {
                continue;
            }

            $value = trim((string) ($row[$column] ?? ''));

            if (
                $value === ''
                || ! preg_match(
                    '/^(SI-[0-9]{4}-)([0-9]+)$/i',
                    $value,
                    $matches
                )
            ) {
                continue;
            }

            $sequence = (int) $matches[2];

            if ($sequence < 1000) {
                continue;
            }

            $plain = $matches[1].(string) $sequence;
            $normalizedNumber ??= $plain;

            if ($plain !== $value) {
                $updates[$column] = $plain;
            }
        }

        if ($updates !== []) {
            DB::table($table)
                ->where($key, $invoiceId)
                ->update($updates);
        }

        return $normalizedNumber;
    }
}
