<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionMethod;

/**
 * ERP-10.31.72
 *
 * Recovery path for the native SalesInvoiceService::updateDraft() historical
 * `$fy` closure-scope defect. It is invoked only AFTER the native controller
 * and request validation have already run and that exact service exception is
 * thrown.
 *
 * The recovery updates the existing Draft invoice and its existing native line
 * rows. It does not submit/approve/post and it never creates accounting.
 */
class SafeSalesInvoiceDraftUpdateRecovery
{
    /**
     * @return array{invoice: Model, total: float, lines: int}
     */
    public function recover(Request $request, mixed $routeInvoice): array
    {
        $invoice = $this->resolveInvoice($routeInvoice);

        $status = strtolower(trim((string) (
            $invoice->getAttribute('status')
            ?? $invoice->getAttribute('invoice_status')
            ?? ''
        )));

        if ($status !== 'draft') {
            throw ValidationException::withMessages([
                'invoice' => 'Only a Draft Sales Invoice can be edited.',
            ]);
        }

        $lineRelation = $this->resolveInvoiceLineRelation($invoice);

        if (! $lineRelation) {
            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice line relation could not be resolved for Draft recovery.',
            ]);
        }

        $linePayloads = $this->extractLinePayloads($request->all());

        if ($linePayloads === []) {
            throw ValidationException::withMessages([
                'lines' => 'No Sales Invoice line data was received by the Draft update.',
            ]);
        }

        return DB::transaction(function () use ($request, $invoice, $lineRelation, $linePayloads): array {
            $invoice->refresh();

            $status = strtolower(trim((string) (
                $invoice->getAttribute('status')
                ?? $invoice->getAttribute('invoice_status')
                ?? ''
            )));

            if ($status !== 'draft') {
                throw ValidationException::withMessages([
                    'invoice' => 'The Sales Invoice is no longer Draft and cannot be edited.',
                ]);
            }

            $header = $this->headerPayload($request, $invoice);

            if ($header !== []) {
                $invoice->forceFill($header);
                $invoice->saveQuietly();
            }

            /** @var Relation $relation */
            $relation = $lineRelation['relation'];
            $existing = $relation->get()->values();
            $related = $relation->getRelated();
            $lineTable = $related->getTable();
            $columns = Schema::getColumnListing($lineTable);

            $usedIds = [];
            $total = 0.0;
            $updatedCount = 0;

            foreach ($linePayloads as $index => $rawLine) {
                if (! is_array($rawLine)) {
                    continue;
                }

                $line = $this->matchExistingLine($existing, $rawLine, $index);

                if (! $line instanceof Model) {
                    /*
                     * Draft pages normally edit existing line snapshots. If an
                     * additional line is genuinely supplied, create it through
                     * the EXISTING native relation so the invoice foreign key is
                     * populated authoritatively.
                     */
                    $line = $relation->make();
                }

                $payload = $this->linePayload($rawLine, $columns, $index + 1, $line);

                if ($payload === []) {
                    continue;
                }

                $line->forceFill($payload);
                $line->saveQuietly();

                if ($line->getKey() !== null) {
                    $usedIds[] = (string) $line->getKey();
                }

                $total += $this->lineTotalFromModel($line, $payload);
                $updatedCount++;
            }

            if ($updatedCount <= 0) {
                throw ValidationException::withMessages([
                    'lines' => 'The Draft invoice lines could not be updated.',
                ]);
            }

            $this->synchronizeHeaderTotals($invoice, $total, $request->user()?->id);
            $invoice->refresh();

            return [
                'invoice' => $invoice,
                'total' => round($total, 2),
                'lines' => $updatedCount,
            ];
        });
    }

    private function resolveInvoice(mixed $routeInvoice): Model
    {
        if ($routeInvoice instanceof Model) {
            return $routeInvoice;
        }

        $class = \App\Models\SalesInvoice::class;

        if (! class_exists($class)) {
            throw ValidationException::withMessages([
                'invoice' => 'The native SalesInvoice model is unavailable.',
            ]);
        }

        /** @var Model $prototype */
        $prototype = app($class);
        $id = is_scalar($routeInvoice) ? (int) $routeInvoice : 0;

        $invoice = $id > 0
            ? $prototype->newQuery()->whereKey($id)->first()
            : null;

        if (! $invoice instanceof Model) {
            throw ValidationException::withMessages([
                'invoice' => 'The Sales Invoice could not be resolved for Draft recovery.',
            ]);
        }

        return $invoice;
    }

    /**
     * @return array<string,mixed>
     */
    private function headerPayload(Request $request, Model $invoice): array
    {
        $table = $invoice->getTable();
        $columns = Schema::getColumnListing($table);
        $input = $request->all();
        $payload = [];

        $aliases = [
            'invoice_date' => ['invoice_date', 'document_date'],
            'document_date' => ['document_date', 'invoice_date'],
            'due_date' => ['due_date'],
            'customer_reference' => ['customer_reference', 'customer_ref'],
            'customer_ref' => ['customer_ref', 'customer_reference'],
            'reference' => ['reference', 'reference_no'],
            'reference_no' => ['reference_no', 'reference'],
            'exchange_rate' => ['exchange_rate', 'fx_rate'],
            'fx_rate' => ['fx_rate', 'exchange_rate'],
            'notes' => ['notes', 'invoice_notes'],
            'invoice_notes' => ['invoice_notes', 'notes'],
            'currency_id' => ['currency_id'],
            'currency_code' => ['currency_code', 'currency'],
            'currency' => ['currency', 'currency_code'],
        ];

        foreach ($aliases as $column => $keys) {
            if (! in_array($column, $columns, true)) {
                continue;
            }

            foreach ($keys as $key) {
                if (! array_key_exists($key, $input)) {
                    continue;
                }

                $value = $input[$key];

                if (is_array($value)) {
                    continue;
                }

                $payload[$column] = $value;
                break;
            }
        }

        if (in_array('updated_by', $columns, true) && $request->user()?->id) {
            $payload['updated_by'] = (int) $request->user()->id;
        }

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function extractLinePayloads(array $input): array
    {
        $preferred = [
            'lines',
            'items',
            'invoice_lines',
            'invoice_items',
            'details',
            'services',
            'service_lines',
        ];

        foreach ($preferred as $key) {
            $candidate = $input[$key] ?? null;

            if ($this->looksLikeLineList($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        /*
         * Native forms sometimes use another wrapper name. Locate one nested
         * list that clearly contains quantity/rate/description line fields.
         */
        foreach ($input as $candidate) {
            if ($this->looksLikeLineList($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        return [];
    }

    private function looksLikeLineList(mixed $candidate): bool
    {
        if (! is_array($candidate) || $candidate === []) {
            return false;
        }

        $rows = array_values(array_filter($candidate, 'is_array'));

        if ($rows === []) {
            return false;
        }

        $signals = [
            'quantity', 'qty', 'unit_price', 'rate', 'price',
            'description', 'service_name', 'line_total', 'amount',
        ];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($signals as $signal) {
                if (array_key_exists($signal, $row)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matchExistingLine($existing, array $raw, int $index): ?Model
    {
        $candidateId = (int) (
            $raw['id']
            ?? $raw['line_id']
            ?? $raw['invoice_line_id']
            ?? $raw['invoice_item_id']
            ?? 0
        );

        if ($candidateId > 0) {
            $match = $existing->first(fn ($line) => (int) $line->getKey() === $candidateId);

            if ($match instanceof Model) {
                return $match;
            }
        }

        $lineNo = (int) (
            $raw['line_no']
            ?? $raw['line_number']
            ?? $raw['sequence_no']
            ?? $raw['sort_order']
            ?? 0
        );

        if ($lineNo > 0) {
            $match = $existing->first(function ($line) use ($lineNo): bool {
                foreach (['line_no', 'line_number', 'sequence_no', 'sort_order'] as $field) {
                    if ((int) $line->getAttribute($field) === $lineNo) {
                        return true;
                    }
                }

                return false;
            });

            if ($match instanceof Model) {
                return $match;
            }
        }

        $fallback = $existing->get($index);

        return $fallback instanceof Model ? $fallback : null;
    }

    /**
     * @param list<string> $columns
     * @return array<string,mixed>
     */
    private function linePayload(array $raw, array $columns, int $lineNo, Model $line): array
    {
        $payload = [];

        $description = trim((string) (
            $raw['description']
            ?? $raw['item_description']
            ?? $raw['service_name']
            ?? $raw['name']
            ?? $line->getAttribute('description')
            ?? $line->getAttribute('service_name')
            ?? ''
        ));

        $quantity = $this->number(
            $raw['quantity']
            ?? $raw['qty']
            ?? $line->getAttribute('quantity')
            ?? $line->getAttribute('qty')
            ?? 1
        );

        if ($quantity <= 0) {
            $quantity = 1.0;
        }

        $unitPrice = $this->number(
            $raw['unit_price']
            ?? $raw['rate']
            ?? $raw['price']
            ?? $raw['sale_price']
            ?? $line->getAttribute('unit_price')
            ?? $line->getAttribute('rate')
            ?? 0
        );

        $discountValue = $this->number(
            $raw['discount']
            ?? $raw['discount_amount']
            ?? $raw['discount_value']
            ?? 0
        );

        $discountType = strtolower(trim((string) (
            $raw['discount_type']
            ?? $raw['discount_mode']
            ?? ''
        )));

        $gross = $quantity * $unitPrice;
        $discountAmount = str_contains($discountType, 'percent') || str_contains($discountType, '%')
            ? ($gross * $discountValue / 100)
            : $discountValue;

        $explicitTotal = $this->firstNumeric($raw, [
            'line_total', 'net_amount', 'total_amount', 'total', 'amount',
        ]);

        $lineTotal = $explicitTotal !== null && abs($explicitTotal - $gross) < 0.01 && $discountAmount == 0.0
            ? $explicitTotal
            : max(0.0, $gross - $discountAmount);

        $this->putAll($payload, $columns, [
            'line_no', 'line_number', 'sequence_no', 'sequence', 'sort_order',
        ], $lineNo);

        if ($description !== '') {
            $this->putAll($payload, $columns, [
                'description', 'item_description', 'details', 'particulars',
                'name', 'service_name', 'product_name',
            ], $description);
        }

        $this->putAll($payload, $columns, ['quantity', 'qty'], $quantity);
        $this->putAll($payload, $columns, [
            'unit_price', 'rate', 'price', 'sale_price', 'selling_price',
        ], $unitPrice);
        $this->putAll($payload, $columns, ['discount_amount'], $discountAmount);
        $this->putAll($payload, $columns, [
            'amount', 'line_total', 'net_amount', 'total_amount', 'total',
        ], $lineTotal);

        /* Preserve user-editable IDs/mappings only when those columns exist. */
        foreach ([
            'product_service_id', 'booking_service_id', 'revenue_mapping_key',
            'tax_code_id', 'tax_id', 'account_id', 'revenue_account_id',
        ] as $field) {
            if (in_array($field, $columns, true) && array_key_exists($field, $raw)) {
                $payload[$field] = $raw[$field];
            }
        }

        return $payload;
    }

    private function synchronizeHeaderTotals(Model $invoice, float $total, ?int $userId): void
    {
        $columns = Schema::getColumnListing($invoice->getTable());
        $payload = [];

        foreach ([
            'subtotal', 'net_total', 'grand_total', 'total_amount', 'total', 'amount',
        ] as $field) {
            if (in_array($field, $columns, true)) {
                $payload[$field] = round($total, 2);
            }
        }

        if (in_array('updated_by', $columns, true) && $userId) {
            $payload['updated_by'] = $userId;
        }

        if ($payload !== []) {
            $invoice->forceFill($payload);
            $invoice->saveQuietly();
        }
    }

    private function lineTotalFromModel(Model $line, array $payload): float
    {
        foreach (['line_total', 'net_amount', 'total_amount', 'total', 'amount'] as $field) {
            $value = $payload[$field] ?? $line->getAttribute($field);

            if ($value !== null && $value !== '') {
                return $this->number($value);
            }
        }

        $qty = $this->number($payload['quantity'] ?? $payload['qty'] ?? 1);
        $rate = $this->number($payload['unit_price'] ?? $payload['rate'] ?? 0);
        $discount = $this->number($payload['discount_amount'] ?? 0);

        return max(0.0, ($qty * $rate) - $discount);
    }

    private function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value) ?? '';

        return is_numeric($clean) ? (float) $clean : 0.0;
    }

    private function firstNumeric(array $raw, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $value = $raw[$key];

            if ($value === null || $value === '') {
                continue;
            }

            return $this->number($value);
        }

        return null;
    }

    private function putAll(array &$payload, array $columns, array $fields, mixed $value): void
    {
        foreach ($fields as $field) {
            if (in_array($field, $columns, true)) {
                $payload[$field] = $value;
            }
        }
    }

    /** @return array{name:string,relation:Relation}|null */
    private function resolveInvoiceLineRelation(Model $invoice): ?array
    {
        $scores = [];

        try {
            $reflection = new ReflectionClass($invoice);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                $name = $method->getName();
                $lower = strtolower($name);
                $score = 0;

                if ($lower === 'invoiceitems' || $lower === 'invoice_items') $score += 3200;
                if ($lower === 'items') $score += 3000;
                if ($lower === 'invoicelines') $score += 3000;
                if ($lower === 'lines') $score += 2800;
                if ($lower === 'details') $score += 2200;
                if (str_contains($lower, 'item')) $score += 1000;
                if (str_contains($lower, 'line')) $score += 900;
                if (str_contains($lower, 'detail')) $score += 600;

                if ($score > 0) {
                    $scores[$name] = $score;
                }
            }
        } catch (\Throwable) {
        }

        arsort($scores);

        foreach ($scores as $name => $score) {
            try {
                $relation = $invoice->{$name}();

                if (! $relation instanceof Relation) {
                    continue;
                }

                $table = strtolower($relation->getRelated()->getTable());

                if (
                    ! str_contains($table, 'invoice')
                    && ! str_contains($table, 'line')
                    && ! str_contains($table, 'item')
                    && ! str_contains($table, 'detail')
                ) {
                    continue;
                }

                return ['name' => $name, 'relation' => $relation];
            } catch (\Throwable) {
            }
        }

        return $this->resolveRelationByForeignKey($invoice);
    }

    private function resolveRelationByForeignKey(Model $invoice): ?array
    {
        $invoiceTable = $invoice->getTable();
        $invoiceKey = $invoice->getKeyName();

        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta)
                    ? (string) ($meta['name'] ?? $meta['table_name'] ?? '')
                    : '';

                if ($table === '') {
                    continue;
                }

                $lower = strtolower($table);

                if (
                    ! str_contains($lower, 'invoice')
                    || (! str_contains($lower, 'item') && ! str_contains($lower, 'line') && ! str_contains($lower, 'detail'))
                ) {
                    continue;
                }

                foreach (Schema::getForeignKeys($table) as $foreignKey) {
                    if (! is_array($foreignKey)) {
                        continue;
                    }

                    $foreignTable = (string) (
                        $foreignKey['foreign_table']
                        ?? $foreignKey['foreign_table_name']
                        ?? $foreignKey['table']
                        ?? ''
                    );

                    if ($foreignTable !== $invoiceTable) {
                        continue;
                    }

                    $locals = (array) ($foreignKey['columns'] ?? $foreignKey['local_columns'] ?? []);
                    $foreignKeyColumn = (string) ($locals[0] ?? '');

                    if ($foreignKeyColumn === '') {
                        continue;
                    }

                    $model = $this->modelForTable($table);

                    if (! $model) {
                        continue;
                    }

                    $relation = $invoice->hasMany(get_class($model), $foreignKeyColumn, $invoiceKey);

                    return ['name' => '[dynamic '.$table.']', 'relation' => $relation];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function modelForTable(string $table): ?Model
    {
        $studly = Str::studly(Str::singular($table));
        $candidates = [
            'App\\Models\\'.$studly,
            'App\\Models\\Sales\\'.$studly,
            'App\\Models\\Accounting\\'.$studly,
            'App\\Models\\Travel\\'.$studly,
        ];

        foreach ($candidates as $class) {
            if (! class_exists($class)) {
                continue;
            }

            try {
                $model = app($class);

                if ($model instanceof Model && $model->getTable() === $table) {
                    return $model;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
