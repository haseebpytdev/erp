<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * ERP-11.3.62
 *
 * Air Ticket invoice commercial source-of-truth:
 *
 * Booking Saved Passenger Tickets
 *   -> Adult / Child / Infant ticket commercial values
 *   -> grouped customer-facing Draft Sales Invoice lines
 *   -> passenger/ticket snapshot links
 *
 * The invoice is NOT a second pricing editor.
 */
class AirTicketInvoiceCommercialSyncService
{
    private const LINK_TABLE = 'sales_invoice_air_ticket_line_links';

    /**
     * @return array<string,mixed>
     */
    public function snapshot(mixed $routeInvoice): array
    {
        $invoice = $this->resolveInvoice($routeInvoice);
        $booking = $this->resolveBookingContext($invoice);

        if (! $booking) {
            return $this->unsupported($invoice, 'No source booking could be resolved.');
        }

        $ticketSource = $this->resolveTicketSource((int) $booking['id']);

        if (! $ticketSource) {
            return $this->unsupported($invoice, 'No saved passenger-ticket source could be resolved.');
        }

        $ticketRows = $this->ticketRows(
            $ticketSource,
            (int) $booking['id']
        );

        if ($ticketRows->isEmpty()) {
            return $this->unsupported($invoice, 'No saved passenger tickets exist for this booking.');
        }

        $airSignal = $this->isAirBooking($booking)
            || $this->invoiceLooksLikeAirTicket($invoice)
            || str_contains(strtolower((string) $ticketSource['table']), 'ticket');

        if (! $airSignal) {
            return $this->unsupported($invoice, 'Invoice is not an Air Ticket invoice.');
        }

        $tickets = [];

        foreach ($ticketRows as $row) {
            $ticket = $this->ticketSnapshot(
                $ticketSource,
                $row,
                (int) $booking['id']
            );

            if ($ticket['ticket_number'] === '' && (float) $ticket['customer_sale'] <= 0) {
                continue;
            }

            $tickets[] = $ticket;
        }

        if ($tickets === []) {
            return $this->unsupported($invoice, 'Saved ticket rows contain no invoiceable ticket data.');
        }

        $groups = $this->groupTickets($tickets);
        $total = round(array_sum(array_column($groups, 'total')), 2);
        $supplierTotal = round(array_sum(array_column($tickets, 'supplier_cost')), 2);

        $currentAirLines = $this->currentAirLines($invoice);
        $needsSync = $this->needsSync(
            $invoice,
            $groups,
            $currentAirLines,
            $tickets
        );

        /*
         * ERP-11.3.81:
         * Passenger-ticket link rows are snapshot/presentation metadata.
         * Their count must NOT make a commercially correct invoice fail the
         * accounting workflow. Track them separately so Draft sync can repair
         * them without classifying the invoice amount/lines as mismatched.
         */
        $linkSyncNeeded = $this->linkSyncNeeded(
            $invoice,
            $tickets
        );

        $currentAirTotal = round(
            array_sum(
                array_map(
                    fn (Model $line): float =>
                        $this->lineTotal($line),
                    $currentAirLines
                )
            ),
            2
        );

        return [
            'supported' => true,
            'reason' => null,
            'invoice' => $invoice,
            'booking' => $booking,
            'booking_id' => (int) $booking['id'],
            'ticket_source' => $ticketSource,
            'tickets' => $tickets,
            'groups' => $groups,
            'ticket_count' => count($tickets),
            'total' => $total,
            'supplier_total' => $supplierTotal,
            'needs_sync' => $needsSync,
            'link_sync_needed' => $linkSyncNeeded,
            'current_air_line_count' => count($currentAirLines),
            'current_air_line_total' => $currentAirTotal,
        ];
    }

    public function supports(mixed $routeInvoice): bool
    {
        try {
            return (bool) ($this->snapshot($routeInvoice)['supported'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function sync(
        mixed $routeInvoice,
        ?Request $request = null
    ): array {
        $snapshot = $this->snapshot($routeInvoice);

        if (! ($snapshot['supported'] ?? false)) {
            throw ValidationException::withMessages([
                'invoice' => (string) (
                    $snapshot['reason']
                    ?? 'Air Ticket invoice commercial source could not be resolved.'
                ),
            ]);
        }

        /** @var Model $invoice */
        $invoice = $snapshot['invoice'];

        $status = strtolower(trim((string) (
            $invoice->getAttribute('status')
            ?? $invoice->getAttribute('invoice_status')
            ?? ''
        )));

        if ($status !== 'draft') {
            throw ValidationException::withMessages([
                'invoice' => 'Air Ticket commercial synchronization is allowed only while the Sales Invoice is Draft.',
            ]);
        }

        if (! Schema::hasTable(self::LINK_TABLE)) {
            throw ValidationException::withMessages([
                'invoice' => 'Air Ticket invoice snapshot table is missing. Run Safe Database Upgrade for ERP-10.31.72.',
            ]);
        }

        $lineRelation = $this->resolveInvoiceLineRelation($invoice);

        if (! $lineRelation) {
            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice line relation could not be resolved.',
            ]);
        }

        return DB::transaction(function () use (
            $snapshot,
            $invoice,
            $request,
            $lineRelation
        ): array {
            $invoice->refresh();

            $status = strtolower(trim((string) (
                $invoice->getAttribute('status')
                ?? $invoice->getAttribute('invoice_status')
                ?? ''
            )));

            if ($status !== 'draft') {
                throw ValidationException::withMessages([
                    'invoice' => 'The Sales Invoice is no longer Draft.',
                ]);
            }

            if ($request) {
                $this->applyHeaderDetails(
                    $request,
                    $invoice
                );
            }

            /** @var Relation $relation */
            $relation = $lineRelation['relation'];
            $related = $relation->getRelated();
            $lineTable = $related->getTable();
            $columns = Schema::getColumnListing($lineTable);
            $metadata = $this->columnMetadata($lineTable);
            $existing = $this->orderInvoiceLines(
                $relation->lockForUpdate()->get()->values(),
                $columns
            );
            $lineNumberField = $this->invoiceLineNumberField($columns);
            $occupiedLineNumbers = $this->occupiedInvoiceLineNumbers(
                $existing,
                $lineNumberField
            );

            $airLines = $existing
                ->filter(fn (Model $line): bool => $this->lineLooksLikeAirTicket($line))
                ->values();

            /*
             * Air-only invoices normally begin with one generic Air Ticket line.
             * If there is no explicit AIR_TICKET marker but the source booking is
             * definitely Air Ticket, treat the only existing line as the template.
             */
            if ($airLines->isEmpty() && $existing->count() === 1) {
                $airLines = $existing;
            }

            $template = $airLines->first()
                ?? $existing->first();

            if (! $template instanceof Model) {
                $template = $relation->make();
            }

            $usedLineIds = [];
            $groupLineMap = [];

            foreach ($snapshot['groups'] as $index => $group) {
                $line = $airLines->get($index);
                $lineNo = $line instanceof Model
                    ? $this->existingInvoiceLineNumber(
                        $line,
                        $lineNumberField
                    )
                    : null;

                if (! $line instanceof Model) {
                    $line = $relation->make();
                    $this->copyMappingFields(
                        $template,
                        $line,
                        $columns
                    );
                }

                if ($lineNo === null) {
                    $lineNo = $this->nextInvoiceLineNumber(
                        $occupiedLineNumbers
                    );
                } else {
                    $occupiedLineNumbers[$lineNo] = true;
                }

                $payload = $this->invoiceLinePayload(
                    $group,
                    $columns,
                    $lineNo
                );
                $payload = $this->preserveExistingInvoiceLineNumbers(
                    $line,
                    $payload,
                    $columns
                );

                /*
                 * Preserve native accounting/product mapping from the original
                 * Air Ticket line. Only commercial presentation changes.
                 */
                foreach ($this->mappingFields() as $field) {
                    if (
                        in_array($field, $columns, true)
                        && ! array_key_exists($field, $payload)
                        && $template->getAttribute($field) !== null
                    ) {
                        $payload[$field] =
                            $template->getAttribute($field);
                    }
                }

                $payload = $this->preserveNativeStructuralFields(
                    $template,
                    $line,
                    $invoice,
                    $payload,
                    $columns,
                    $metadata,
                    $lineTable
                );

                $line->forceFill($payload);
                $line->saveQuietly();

                $lineId = (int) $line->getKey();

                if ($lineId > 0) {
                    $usedLineIds[] = $lineId;
                    $groupLineMap[$group['key']] = $lineId;
                }
            }

            /*
             * Remove stale extra Air Ticket Draft lines only.
             * Non-Air service lines remain untouched.
             */
            foreach ($airLines as $line) {
                $lineId = (int) $line->getKey();

                if (
                    $lineId > 0
                    && ! in_array($lineId, $usedLineIds, true)
                ) {
                    $line->delete();
                }
            }

            $this->writeTicketLinks(
                $snapshot,
                $groupLineMap
            );

            $allLines = $relation->get();
            $invoiceTotal = round(
                $allLines->sum(
                    fn (Model $line): float =>
                        $this->lineTotal($line)
                ),
                2
            );

            $this->synchronizeHeaderTotals(
                $invoice,
                $invoiceTotal,
                $request?->user()?->id
            );

            $invoice->refresh();

            return [
                'invoice' => $invoice,
                'booking' => $snapshot['booking'],
                'groups' => $snapshot['groups'],
                'tickets' => $snapshot['tickets'],
                'lines' => count($snapshot['groups']),
                'ticket_count' => count($snapshot['tickets']),
                'total' => $invoiceTotal,
                'customer_total' => $snapshot['total'],
                'supplier_total' => $snapshot['supplier_total'],
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function unsupported(Model $invoice, string $reason): array
    {
        return [
            'supported' => false,
            'reason' => $reason,
            'invoice' => $invoice,
            'booking' => null,
            'booking_id' => 0,
            'tickets' => [],
            'groups' => [],
            'ticket_count' => 0,
            'total' => 0.0,
            'supplier_total' => 0.0,
            'needs_sync' => false,
            'current_air_line_count' => 0,
        ];
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
        $id = is_scalar($routeInvoice)
            ? (int) $routeInvoice
            : 0;

        $invoice = $id > 0
            ? $prototype->newQuery()
                ->whereKey($id)
                ->first()
            : null;

        if (! $invoice instanceof Model) {
            throw ValidationException::withMessages([
                'invoice' => 'The Sales Invoice could not be resolved.',
            ]);
        }

        return $invoice;
    }

    /**
     * @return array{id:int,reference:string,type:string,model:?Model}|null
     */
    private function resolveBookingContext(Model $invoice): ?array
    {
        foreach ([
            'booking',
            'sourceBooking',
            'travelBooking',
        ] as $method) {
            if (! method_exists($invoice, $method)) {
                continue;
            }

            try {
                $relation = $invoice->{$method}();

                if (! $relation instanceof Relation) {
                    continue;
                }

                $model = $relation->getResults();

                if ($model instanceof Model) {
                    return $this->bookingContextFromModel($model);
                }
            } catch (Throwable) {
            }
        }

        $attributes = $invoice->getAttributes();

        foreach ([
            'booking_id',
            'source_booking_id',
            'travel_booking_id',
            'booking_header_id',
        ] as $field) {
            $id = (int) ($attributes[$field] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $model = $this->bookingModelById($id);

            if ($model) {
                return $this->bookingContextFromModel($model);
            }

            return [
                'id' => $id,
                'reference' => '',
                'type' => '',
                'model' => null,
            ];
        }

        $reference = '';

        foreach ([
            'booking_reference',
            'booking_no',
            'booking_number',
            'source_reference',
            'reference',
        ] as $field) {
            $value = trim((string) ($attributes[$field] ?? ''));

            if (
                $value !== ''
                && (
                    str_starts_with(strtoupper($value), 'BK-')
                    || str_contains(strtolower($field), 'booking')
                )
            ) {
                $reference = $value;
                break;
            }
        }

        if ($reference !== '') {
            $resolved = $this->bookingByReference($reference);

            if ($resolved) {
                return $resolved;
            }

            $bySuffix = $this->bookingByReferenceSuffix(
                $reference
            );

            if ($bySuffix) {
                return $bySuffix;
            }
        }

        /*
         * ERP-10.31.72:
         * Older native Sales Invoice rows do not always persist booking_id or a
         * dedicated booking_reference column. The live invoice still contains
         * "Created from confirmed booking BK-2026-000041" in notes/line
         * snapshots. Search ALL scalar invoice + line attributes for BK-... and
         * resolve the source booking from that durable reference.
         */
        $embeddedReference =
            $this->embeddedBookingReference(
                $invoice
            );

        if ($embeddedReference !== '') {
            $resolved =
                $this->bookingByReference(
                    $embeddedReference
                );

            if ($resolved) {
                return $resolved;
            }

            $bySuffix =
                $this->bookingByReferenceSuffix(
                    $embeddedReference
                );

            if ($bySuffix) {
                return $bySuffix;
            }
        }

        return null;
    }

    private function embeddedBookingReference(
        Model $invoice
    ): string {
        $chunks = [];

        foreach ($invoice->getAttributes() as $value) {
            if (
                is_string($value)
                || is_numeric($value)
            ) {
                $chunks[] = (string) $value;
            }
        }

        $lineRelation =
            $this->resolveInvoiceLineRelation(
                $invoice
            );

        if ($lineRelation) {
            try {
                /** @var Relation $relation */
                $relation = $lineRelation['relation'];

                foreach ($relation->get() as $line) {
                    if (! $line instanceof Model) {
                        continue;
                    }

                    foreach ($line->getAttributes() as $value) {
                        if (
                            is_string($value)
                            || is_numeric($value)
                        ) {
                            $chunks[] = (string) $value;
                        }
                    }
                }
            } catch (Throwable) {
            }
        }

        $text = implode(
            "\n",
            $chunks
        );

        if (
            preg_match(
                '/\bBK-\d{4}-\d{4,12}\b/i',
                $text,
                $matches
            ) === 1
        ) {
            return strtoupper(
                (string) ($matches[0] ?? '')
            );
        }

        return '';
    }

    /**
     * Fallback for the current native booking format:
     * BK-2026-000041 -> booking primary key 41.
     *
     * The candidate is verified through the actual Booking model when one is
     * available, so this is not a blind ID assumption.
     *
     * @return array{id:int,reference:string,type:string,model:?Model}|null
     */
    private function bookingByReferenceSuffix(
        string $reference
    ): ?array {
        if (
            preg_match(
                '/(\d+)$/',
                trim($reference),
                $matches
            ) !== 1
        ) {
            return null;
        }

        $id = (int) ltrim(
            (string) ($matches[1] ?? ''),
            '0'
        );

        if ($id <= 0) {
            return null;
        }

        $model =
            $this->bookingModelById(
                $id
            );

        if ($model) {
            $context =
                $this->bookingContextFromModel(
                    $model
                );

            /*
             * Prefer exact reference verification where the model exposes it.
             */
            if (
                $context['reference'] === ''
                || strtoupper($context['reference'])
                    === strtoupper($reference)
            ) {
                return $context;
            }
        }

        /*
         * Schema-only installations: verify the row by PK and inspect its
         * reference column where available.
         */
        foreach ($this->allTables() as $table) {
            $lower = strtolower($table);

            if (
                ! str_contains($lower, 'booking')
                || str_contains($lower, 'invoice')
                || str_contains($lower, 'passenger')
                || str_contains($lower, 'ticket')
            ) {
                continue;
            }

            try {
                $columns =
                    Schema::getColumnListing(
                        $table
                    );

                if (! in_array('id', $columns, true)) {
                    continue;
                }

                $row = DB::table($table)
                    ->where('id', $id)
                    ->first();

                if (! $row) {
                    continue;
                }

                $referenceColumn =
                    $this->firstColumn(
                        $columns,
                        [
                            'booking_reference',
                            'booking_no',
                            'booking_number',
                            'reference',
                            'public_id',
                            'code',
                        ]
                    );

                $rowReference =
                    $referenceColumn
                        ? trim((string) data_get(
                            $row,
                            $referenceColumn,
                            ''
                        ))
                        : '';

                if (
                    $rowReference !== ''
                    && strtoupper($rowReference)
                        !== strtoupper($reference)
                ) {
                    continue;
                }

                $typeColumn =
                    $this->firstColumn(
                        $columns,
                        [
                            'booking_type',
                            'type',
                            'service_type',
                            'product_type',
                            'travel_type',
                        ]
                    );

                return [
                    'id' => $id,
                    'reference' =>
                        $rowReference !== ''
                            ? $rowReference
                            : $reference,
                    'type' =>
                        $typeColumn
                            ? (string) data_get(
                                $row,
                                $typeColumn,
                                ''
                            )
                            : '',
                    'model' => null,
                ];
            } catch (Throwable) {
            }
        }

        return null;
    }

    /**
     * @return array{id:int,reference:string,type:string,model:?Model}
     */
    private function bookingContextFromModel(Model $model): array
    {
        $attributes = $model->getAttributes();

        $reference = '';

        foreach ([
            'booking_reference',
            'booking_no',
            'booking_number',
            'reference',
            'public_id',
            'code',
        ] as $field) {
            $value = trim((string) ($attributes[$field] ?? ''));

            if ($value !== '') {
                $reference = $value;
                break;
            }
        }

        $type = '';

        foreach ([
            'booking_type',
            'type',
            'service_type',
            'product_type',
            'travel_type',
        ] as $field) {
            $value = trim((string) ($attributes[$field] ?? ''));

            if ($value !== '') {
                $type = $value;
                break;
            }
        }

        return [
            'id' => (int) $model->getKey(),
            'reference' => $reference,
            'type' => $type,
            'model' => $model,
        ];
    }

    private function bookingModelById(int $id): ?Model
    {
        foreach ([
            \App\Models\Booking::class,
            'App\\Models\\Operations\\Booking',
            'App\\Models\\Travel\\Booking',
        ] as $class) {
            if (! class_exists($class)) {
                continue;
            }

            try {
                $model = app($class)
                    ->newQuery()
                    ->whereKey($id)
                    ->first();

                if ($model instanceof Model) {
                    return $model;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    /**
     * @return array{id:int,reference:string,type:string,model:?Model}|null
     */
    private function bookingByReference(string $reference): ?array
    {
        $tables = $this->allTables();

        foreach ($tables as $table) {
            $lower = strtolower($table);

            if (
                ! str_contains($lower, 'booking')
                || str_contains($lower, 'invoice')
            ) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);

                $idColumn = $this->firstColumn(
                    $columns,
                    ['id', 'booking_id']
                );

                $referenceColumn = $this->firstColumn(
                    $columns,
                    [
                        'booking_reference',
                        'booking_no',
                        'booking_number',
                        'reference',
                        'public_id',
                        'code',
                    ]
                );

                if (! $idColumn || ! $referenceColumn) {
                    continue;
                }

                $row = DB::table($table)
                    ->where($referenceColumn, $reference)
                    ->first();

                if (! $row) {
                    continue;
                }

                $typeColumn = $this->firstColumn(
                    $columns,
                    [
                        'booking_type',
                        'type',
                        'service_type',
                        'product_type',
                        'travel_type',
                    ]
                );

                return [
                    'id' => (int) data_get($row, $idColumn, 0),
                    'reference' => (string) data_get($row, $referenceColumn, ''),
                    'type' => $typeColumn
                        ? (string) data_get($row, $typeColumn, '')
                        : '',
                    'model' => null,
                ];
            } catch (Throwable) {
            }
        }

        return null;
    }

    private function isAirBooking(array $booking): bool
    {
        $type = strtolower(
            preg_replace(
                '/[^a-z0-9]+/',
                ' ',
                (string) ($booking['type'] ?? '')
            ) ?? ''
        );

        if ($type === '') {
            return false;
        }

        return (
            str_contains($type, 'air')
            || str_contains($type, 'ticket')
            || str_contains($type, 'flight')
        );
    }

    private function invoiceLooksLikeAirTicket(Model $invoice): bool
    {
        foreach ($this->currentAirLines($invoice) as $line) {
            if ($this->lineLooksLikeAirTicket($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    /**
     * Resolve the canonical native Air Ticket rows for one Booking.
     *
     * @return array<string,mixed>|null
     */
    private function resolveNativeAirTicketSource(
        int $bookingId
    ): ?array {
        if (
            $bookingId <= 0
            || ! Schema::hasTable('air_ticket_details')
            || ! Schema::hasTable('booking_services')
        ) {
            return null;
        }

        try {
            $ticketColumns = Schema::getColumnListing(
                'air_ticket_details'
            );

            $serviceColumns = Schema::getColumnListing(
                'booking_services'
            );

            if (
                ! in_array('booking_service_id', $ticketColumns, true)
                || ! in_array('id', $serviceColumns, true)
                || ! in_array('booking_id', $serviceColumns, true)
            ) {
                return null;
            }

            $ticketColumn = $this->firstColumn(
                $ticketColumns,
                [
                    'ticket_number',
                    'ticket_no',
                    'e_ticket_number',
                    'eticket_number',
                    'document_number',
                    'document_no',
                ]
            );

            if (! $ticketColumn) {
                return null;
            }

            $serviceIds = DB::table('booking_services')
                ->where('booking_id', $bookingId)
                ->pluck('id')
                ->map(
                    static fn (mixed $value): int =>
                        (int) $value
                )
                ->filter(
                    static fn (int $value): bool =>
                        $value > 0
                )
                ->values()
                ->all();

            if ($serviceIds === []) {
                return null;
            }

            $query = DB::table('air_ticket_details')
                ->whereIn(
                    'booking_service_id',
                    $serviceIds
                );

            if (in_array('deleted_at', $ticketColumns, true)) {
                $query->whereNull('deleted_at');
            }

            $rows = (clone $query)
                ->orderBy(
                    in_array('id', $ticketColumns, true)
                        ? 'id'
                        : $ticketColumn
                )
                ->get();

            if ($rows->isEmpty()) {
                return null;
            }

            $customerSaleTotal = round(
                $rows->sum(
                    fn (object $row): float =>
                        $this->customerSale(
                            $row,
                            $ticketColumns
                        )
                ),
                2
            );

            $supplierCostTotal = round(
                $rows->sum(
                    fn (object $row): float =>
                        $this->supplierCost(
                            $row,
                            $ticketColumns
                        )
                ),
                2
            );

            return [
                'table' => 'air_ticket_details',
                'columns' => $ticketColumns,
                'booking_column' => null,
                'booking_service_column' => 'booking_service_id',
                'booking_service_table' => 'booking_services',
                'booking_service_booking_column' => 'booking_id',
                'ticket_column' => $ticketColumn,
                'passenger_id_column' => $this->passengerIdColumn(
                    $ticketColumns
                ),
                'fare_type_column' => $this->fareTypeColumn(
                    $ticketColumns
                ),
                'sale_column' => $this->saleColumn(
                    $ticketColumns
                ),
                'supplier_cost_column' => $this->supplierCostColumn(
                    $ticketColumns
                ),
                'passenger_fk_table' => $this->passengerForeignTable(
                    'air_ticket_details',
                    $this->passengerIdColumn(
                        $ticketColumns
                    )
                ),
                'candidate_score' => PHP_INT_MAX,
                'candidate_customer_sale_total' => $customerSaleTotal,
                'candidate_supplier_cost_total' => $supplierCostTotal,
                'candidate_ticket_count' => $rows->count(),
                'native_authoritative' => true,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the real operational Saved Passenger Ticket source.
     *
     * ERP-11.3.61:
     * Schema/name scoring alone is not sufficient. Production proved a table
     * can look more "ticket-like" while its commercial values are zero, even
     * though another booking-linked ticket table contains the actual Customer
     * Sale used by Booking Workspace.
     *
     * Candidate ranking now uses the VALUES for this booking:
     * - non-zero customer sale receives the strongest priority
     * - non-zero supplier cost is a secondary signal
     * - saved/passenger/booking/ticket naming remains a structural signal
     * - snapshot/history/link/journal/ledger-like tables are penalized
     *
     * This keeps Booking Saved Passenger Tickets as the commercial source of
     * truth instead of allowing a zero-value technical table to win.
     */
    private function resolveTicketSource(int $bookingId): ?array
    {
        /*
         * ERP-11.3.62 — exact native Air Ticket source first.
         *
         * Native ERP schema (confirmed from the application model/workspace):
         *   air_ticket_details.booking_service_id
         *       -> booking_services.id
         *       -> booking_services.booking_id
         *
         * Native commercial columns:
         *   selling_total       = customer sale
         *   net_supplier_cost   = supplier cost
         *
         * This is the same row rendered by Booking Workspace under
         * "Saved Passenger Tickets". It must win before heuristic table
         * discovery.
         */
        $native = $this->resolveNativeAirTicketSource($bookingId);

        if ($native !== null) {
            return $native;
        }

        $best = null;
        $bestScore = -PHP_INT_MAX;

        foreach ($this->allTables() as $table) {
            $lower = strtolower($table);

            if (
                str_contains($lower, 'invoice')
                || str_contains($lower, 'itinerary')
                || str_contains($lower, 'segment')
                || str_contains($lower, 'journal')
                || str_contains($lower, 'ledger')
                || $table === self::LINK_TABLE
            ) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);

                $bookingColumn = $this->firstColumn(
                    $columns,
                    [
                        'booking_id',
                        'travel_booking_id',
                        'booking_header_id',
                        'source_booking_id',
                    ]
                );

                $ticketColumn = $this->firstColumn(
                    $columns,
                    [
                        'ticket_number',
                        'ticket_no',
                        'e_ticket_number',
                        'eticket_number',
                        'document_number',
                        'document_no',
                    ]
                );

                if (! $bookingColumn || ! $ticketColumn) {
                    continue;
                }

                $query = DB::table($table)
                    ->where($bookingColumn, $bookingId);

                if (in_array('deleted_at', $columns, true)) {
                    $query->whereNull('deleted_at');
                }

                $rows = (clone $query)
                    ->whereNotNull($ticketColumn)
                    ->where($ticketColumn, '<>', '')
                    ->orderBy(
                        in_array('id', $columns, true)
                            ? 'id'
                            : $ticketColumn
                    )
                    ->limit(100)
                    ->get();

                $count = $rows->count();

                if ($count <= 0) {
                    continue;
                }

                $customerSaleTotal = 0.0;
                $supplierCostTotal = 0.0;
                $nonZeroSaleRows = 0;
                $nonZeroCostRows = 0;

                foreach ($rows as $row) {
                    $sale = round(
                        $this->customerSale(
                            $row,
                            $columns
                        ),
                        2
                    );

                    $cost = round(
                        $this->supplierCost(
                            $row,
                            $columns
                        ),
                        2
                    );

                    $customerSaleTotal += $sale;
                    $supplierCostTotal += $cost;

                    if ($sale > 0.0) {
                        $nonZeroSaleRows++;
                    }

                    if ($cost > 0.0) {
                        $nonZeroCostRows++;
                    }
                }

                $customerSaleTotal = round(
                    $customerSaleTotal,
                    2
                );

                $supplierCostTotal = round(
                    $supplierCostTotal,
                    2
                );

                $score = 0;

                /*
                 * Commercial VALUE is authoritative. This is deliberately much
                 * stronger than naming/schema signals.
                 */
                if ($customerSaleTotal > 0.0) {
                    $score += 5000;
                    $score += min(
                        1000,
                        $nonZeroSaleRows * 100
                    );
                }

                if (
                    $nonZeroSaleRows > 0
                    && $nonZeroSaleRows === $count
                ) {
                    $score += 500;
                }

                if ($supplierCostTotal > 0.0) {
                    $score += 500;
                    $score += min(
                        300,
                        $nonZeroCostRows * 30
                    );
                }

                /*
                 * Structural/name signals.
                 */
                if (str_contains($lower, 'saved')) $score += 500;
                if (str_contains($lower, 'passenger')) $score += 400;
                if (str_contains($lower, 'ticket')) $score += 350;
                if (str_contains($lower, 'booking')) $score += 250;
                if (str_contains($lower, 'air')) $score += 150;

                if ($this->saleColumn($columns)) $score += 180;
                if ($this->supplierCostColumn($columns)) $score += 100;
                if ($this->fareTypeColumn($columns)) $score += 80;
                if ($this->passengerIdColumn($columns)) $score += 60;

                /*
                 * Technical/archive tables must not outrank the live booking
                 * ticket source merely because they expose many matching names.
                 */
                foreach ([
                    'snapshot',
                    'history',
                    'archive',
                    'audit',
                    'link',
                    'map',
                    'log',
                ] as $penalty) {
                    if (str_contains($lower, $penalty)) {
                        $score -= 1000;
                    }
                }

                $score += min(
                    100,
                    (int) $count
                );

                /*
                 * Stable tie-breakers:
                 * 1) score
                 * 2) larger real customer sale
                 * 3) larger supplier cost
                 * 4) more ticket rows
                 */
                $candidate = [
                    'table' => $table,
                    'columns' => $columns,
                    'booking_column' => $bookingColumn,
                    'ticket_column' => $ticketColumn,
                    'passenger_id_column' => $this->passengerIdColumn($columns),
                    'fare_type_column' => $this->fareTypeColumn($columns),
                    'sale_column' => $this->saleColumn($columns),
                    'supplier_cost_column' => $this->supplierCostColumn($columns),
                    'passenger_fk_table' => $this->passengerForeignTable(
                        $table,
                        $this->passengerIdColumn($columns)
                    ),
                    'candidate_score' => $score,
                    'candidate_customer_sale_total' => $customerSaleTotal,
                    'candidate_supplier_cost_total' => $supplierCostTotal,
                    'candidate_ticket_count' => $count,
                ];

                $replace = false;

                if ($score > $bestScore) {
                    $replace = true;
                } elseif (
                    $score === $bestScore
                    && $best !== null
                ) {
                    $bestSale = (float) (
                        $best['candidate_customer_sale_total']
                        ?? 0
                    );

                    $bestCost = (float) (
                        $best['candidate_supplier_cost_total']
                        ?? 0
                    );

                    $bestCount = (int) (
                        $best['candidate_ticket_count']
                        ?? 0
                    );

                    if ($customerSaleTotal > $bestSale) {
                        $replace = true;
                    } elseif (
                        $customerSaleTotal === $bestSale
                        && $supplierCostTotal > $bestCost
                    ) {
                        $replace = true;
                    } elseif (
                        $customerSaleTotal === $bestSale
                        && $supplierCostTotal === $bestCost
                        && $count > $bestCount
                    ) {
                        $replace = true;
                    }
                }

                if (! $replace) {
                    continue;
                }

                $bestScore = $score;
                $best = $candidate;
            } catch (Throwable) {
            }
        }

        return $best;
    }

    private function ticketRows(array $source, int $bookingId): Collection
    {
        $query = DB::table($source['table']);

        $bookingColumn = $source['booking_column']
            ?? null;

        if (is_string($bookingColumn) && $bookingColumn !== '') {
            $query->where(
                $bookingColumn,
                $bookingId
            );
        } else {
            /*
             * Canonical native path:
             * air_ticket_details.booking_service_id
             *   -> booking_services.id / booking_id
             */
            $serviceColumn = (string) (
                $source['booking_service_column']
                ?? ''
            );

            $serviceTable = (string) (
                $source['booking_service_table']
                ?? ''
            );

            $serviceBookingColumn = (string) (
                $source['booking_service_booking_column']
                ?? ''
            );

            if (
                $serviceColumn === ''
                || $serviceTable === ''
                || $serviceBookingColumn === ''
                || ! Schema::hasTable($serviceTable)
            ) {
                return collect();
            }

            $serviceIds = DB::table($serviceTable)
                ->where(
                    $serviceBookingColumn,
                    $bookingId
                )
                ->pluck('id')
                ->map(
                    static fn (mixed $value): int =>
                        (int) $value
                )
                ->filter(
                    static fn (int $value): bool =>
                        $value > 0
                )
                ->values()
                ->all();

            if ($serviceIds === []) {
                return collect();
            }

            $query->whereIn(
                $serviceColumn,
                $serviceIds
            );
        }

        if (in_array('deleted_at', $source['columns'], true)) {
            $query->whereNull('deleted_at');
        }

        return $query
            ->orderBy(
                in_array('id', $source['columns'], true)
                    ? 'id'
                    : $source['ticket_column']
            )
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function ticketSnapshot(
        array $source,
        object $row,
        int $bookingId
    ): array {
        $columns = $source['columns'];

        $ticketNumber = trim((string) data_get(
            $row,
            $source['ticket_column'],
            ''
        ));

        $passengerId = $source['passenger_id_column']
            ? data_get($row, $source['passenger_id_column'])
            : null;

        $passengerData = $this->passengerData(
            $source,
            $row,
            $passengerId
        );

        $fareType = $this->normalizeFareType(
            $source['fare_type_column']
                ? (string) data_get($row, $source['fare_type_column'], '')
                : (string) ($passengerData['fare_type'] ?? '')
        );

        $customerSale = $this->customerSale(
            $row,
            $columns
        );

        $supplierCost = $this->supplierCost(
            $row,
            $columns
        );

        $pnr = '';

        foreach ([
            'pnr',
            'gds_pnr',
            'airline_pnr',
            'supplier_pnr',
            'record_locator',
        ] as $field) {
            if (! in_array($field, $columns, true)) {
                continue;
            }

            $value = trim((string) data_get($row, $field, ''));

            if ($value !== '') {
                $pnr = $value;
                break;
            }
        }

        $rowId = in_array('id', $columns, true)
            ? (string) data_get($row, 'id', '')
            : '';

        return [
            'source_table' => $source['table'],
            'source_id' => $rowId,
            'booking_id' => $bookingId,
            'passenger_id' => $passengerId !== null
                ? (string) $passengerId
                : '',
            'passenger_name' => (string) ($passengerData['name'] ?? ''),
            'fare_type' => $fareType,
            'ticket_number' => $ticketNumber,
            'pnr' => $pnr,
            'customer_sale' => round($customerSale, 2),
            'supplier_cost' => round($supplierCost, 2),
        ];
    }

    /**
     * @return array{name:string,fare_type:string}
     */
    private function passengerData(
        array $source,
        object $row,
        mixed $passengerId
    ): array {
        foreach ([
            'passenger_name',
            'traveller_name',
            'traveler_name',
            'full_name',
            'name',
        ] as $field) {
            if (! in_array($field, $source['columns'], true)) {
                continue;
            }

            $name = trim((string) data_get($row, $field, ''));

            if ($name !== '') {
                return [
                    'name' => $name,
                    'fare_type' => '',
                ];
            }
        }

        if ($passengerId === null || $passengerId === '') {
            return [
                'name' => '',
                'fare_type' => '',
            ];
        }

        $candidateTables = array_values(
            array_unique(
                array_filter([
                    $source['passenger_fk_table'] ?? null,
                    'booking_passengers',
                    'passengers',
                    'travel_passengers',
                    'travellers',
                    'travelers',
                ])
            )
        );

        foreach ($candidateTables as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $columns = Schema::getColumnListing($table);

                $idColumn = $this->firstColumn(
                    $columns,
                    ['id', 'passenger_id', 'traveller_id', 'traveler_id']
                );

                if (! $idColumn) {
                    continue;
                }

                $passenger = DB::table($table)
                    ->where($idColumn, $passengerId)
                    ->first();

                if (! $passenger) {
                    continue;
                }

                $name = '';

                foreach ([
                    'full_name',
                    'passenger_name',
                    'name',
                ] as $field) {
                    if (! in_array($field, $columns, true)) {
                        continue;
                    }

                    $name = trim((string) data_get($passenger, $field, ''));

                    if ($name !== '') {
                        break;
                    }
                }

                if ($name === '') {
                    $parts = [];

                    foreach ([
                        'title',
                        'first_name',
                        'middle_name',
                        'last_name',
                        'surname',
                    ] as $field) {
                        if (! in_array($field, $columns, true)) {
                            continue;
                        }

                        $part = trim((string) data_get($passenger, $field, ''));

                        if ($part !== '') {
                            $parts[] = $part;
                        }
                    }

                    $name = trim(implode(' ', $parts));
                }

                $fareType = '';

                foreach ([
                    'fare_type',
                    'passenger_type',
                    'pax_type',
                    'age_type',
                    'type',
                ] as $field) {
                    if (! in_array($field, $columns, true)) {
                        continue;
                    }

                    $fareType = trim((string) data_get($passenger, $field, ''));

                    if ($fareType !== '') {
                        break;
                    }
                }

                return [
                    'name' => $name,
                    'fare_type' => $fareType,
                ];
            } catch (Throwable) {
            }
        }

        return [
            'name' => '',
            'fare_type' => '',
        ];
    }

    private function customerSale(object $row, array $columns): float
    {
        foreach ([
            'selling_total',
            'customer_sale',
            'customer_sell',
            'customer_sale_amount',
            'customer_sell_amount',
            'customer_amount',
            'sale_amount',
            'sell_amount',
            'selling_price',
            'sale_price',
            'customer_price',
            'customer_total',
            'receivable_amount',
            'receivable',
            'sell_total',
            'net_sale',
            'net_receivable',
            'gross_sale',
        ] as $field) {
            if (! in_array($field, $columns, true)) {
                continue;
            }

            $value = $this->number(
                data_get($row, $field)
            );

            /*
             * A direct zero is a legitimate saved price. Keep searching only
             * when other formula fields exist so old schemas can be derived.
             */
            if ($value != 0.0) {
                return $value;
            }
        }

        $fareTotal = $this->firstNumber(
            $row,
            $columns,
            [
                'fare_total',
                'total_fare',
                'gross_fare',
                'ticket_fare',
                'fare_amount',
            ]
        );

        $baseFare = $this->firstNumber(
            $row,
            $columns,
            ['base_fare', 'basic_fare']
        );

        /*
         * Native air_ticket_details stores fare components separately:
         * base_fare + airline_taxes.
         */
        if ($fareTotal == 0.0 && $baseFare > 0.0) {
            $fareTotal = $baseFare + $this->firstNumber(
                $row,
                $columns,
                [
                    'airline_taxes',
                    'taxes',
                    'tax_amount',
                ]
            );
        }

        $service = $this->firstNumber(
            $row,
            $columns,
            [
                'customer_service_fee',
                'service_markup',
                'service_charge',
                'markup',
                'customer_markup',
                'customer_service_charge',
            ]
        );

        $discountAmount = $this->firstNumber(
            $row,
            $columns,
            [
                'customer_discount_amount',
                'discount_amount',
            ]
        );

        if ($discountAmount == 0.0) {
            $discountValue = $this->firstNumber(
                $row,
                $columns,
                [
                    'customer_discount_value',
                    'discount_value',
                ]
            );

            $discountType = $this->firstString(
                $row,
                $columns,
                [
                    'customer_discount_type',
                    'discount_type',
                ]
            );

            $discountAmount = $this->discountAmount(
                $baseFare,
                $discountType,
                $discountValue
            );
        }

        return max(
            0.0,
            $fareTotal + $service - $discountAmount
        );
    }

    private function supplierCost(object $row, array $columns): float
    {
        foreach ([
            'net_supplier_cost',
            'supplier_cost',
            'supplier_cost_amount',
            'net_cost',
            'net_cost_per_ticket',
            'purchase_cost',
            'purchase_price',
            'supplier_total',
            'cost_amount',
            'cost_price',
            'supplier_amount',
        ] as $field) {
            if (! in_array($field, $columns, true)) {
                continue;
            }

            $value = $this->number(
                data_get($row, $field)
            );

            if ($value != 0.0) {
                return $value;
            }
        }

        $fareTotal = $this->firstNumber(
            $row,
            $columns,
            [
                'fare_total',
                'total_fare',
                'gross_fare',
                'ticket_fare',
                'fare_amount',
            ]
        );

        $baseFare = $this->firstNumber(
            $row,
            $columns,
            [
                'supplier_base_fare',
                'base_fare',
                'basic_fare',
            ]
        );

        if ($fareTotal == 0.0 && $baseFare > 0.0) {
            $fareTotal = $baseFare + $this->firstNumber(
                $row,
                $columns,
                [
                    'supplier_taxes',
                    'airline_taxes',
                    'taxes',
                ]
            );
        }

        $supplierCharges = $this->firstNumber(
            $row,
            $columns,
            [
                'supplier_charges',
                'supplier_charge',
                'supplier_markup',
            ]
        );

        $discountAmount = $this->firstNumber(
            $row,
            $columns,
            [
                'supplier_discount_amount',
            ]
        );

        if ($discountAmount == 0.0) {
            $discountValue = $this->firstNumber(
                $row,
                $columns,
                ['supplier_discount_value']
            );

            $discountType = $this->firstString(
                $row,
                $columns,
                ['supplier_discount_type']
            );

            $discountAmount = $this->discountAmount(
                $baseFare,
                $discountType,
                $discountValue
            );
        }

        return max(
            0.0,
            $fareTotal + $supplierCharges - $discountAmount
        );
    }

    /**
     * @param list<array<string,mixed>> $tickets
     * @return list<array<string,mixed>>
     */
    private function groupTickets(array $tickets): array
    {
        $groups = [];

        foreach ($tickets as $ticket) {
            $fareType = $this->normalizeFareType(
                (string) ($ticket['fare_type'] ?? '')
            );

            $rate = round(
                (float) ($ticket['customer_sale'] ?? 0),
                2
            );

            $key = $fareType.'|'.number_format(
                $rate,
                2,
                '.',
                ''
            );

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'fare_type' => $fareType,
                    'rate' => $rate,
                    'quantity' => 0,
                    'total' => 0.0,
                    'supplier_total' => 0.0,
                    'tickets' => [],
                ];
            }

            $groups[$key]['quantity']++;
            $groups[$key]['total'] += $rate;
            $groups[$key]['supplier_total'] +=
                (float) ($ticket['supplier_cost'] ?? 0);
            $groups[$key]['tickets'][] = $ticket;
        }

        $order = [
            'ADULT' => 1,
            'CHILD' => 2,
            'INFANT' => 3,
        ];

        $groups = array_values($groups);

        usort(
            $groups,
            static function (array $a, array $b) use ($order): int {
                $fare = ($order[$a['fare_type']] ?? 99)
                    <=> ($order[$b['fare_type']] ?? 99);

                if ($fare !== 0) {
                    return $fare;
                }

                return $a['rate'] <=> $b['rate'];
            }
        );

        foreach ($groups as &$group) {
            $group['total'] = round(
                (float) $group['total'],
                2
            );

            $group['supplier_total'] = round(
                (float) $group['supplier_total'],
                2
            );
        }

        return $groups;
    }

    private function normalizeFareType(string $value): string
    {
        $value = strtoupper(trim($value));

        if (
            str_contains($value, 'INF')
            || str_contains($value, 'BABY')
        ) {
            return 'INFANT';
        }

        if (
            str_contains($value, 'CHD')
            || str_contains($value, 'CHILD')
            || str_contains($value, 'CNN')
        ) {
            return 'CHILD';
        }

        return 'ADULT';
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @param list<Model> $currentAirLines
     * @param list<array<string,mixed>> $tickets
     */
    private function needsSync(
        Model $invoice,
        array $groups,
        array $currentAirLines,
        array $tickets
    ): bool {
        /*
         * ERP-11.3.61:
         * Header total is part of commercial integrity, not only line qty/rate.
         * A Draft with zero header total must never appear "in sync" when the
         * Booking Saved Passenger Tickets total is non-zero.
         */
        $expectedTotal = round(
            array_sum(
                array_map(
                    static fn (array $group): float =>
                        (float) (
                            $group['total']
                            ?? 0
                        ),
                    $groups
                )
            ),
            2
        );

        $headerTotal =
            $this->invoiceCommercialHeaderTotal(
                $invoice
            );

        if (
            $headerTotal !== null
            && abs(
                $headerTotal
                - $expectedTotal
            ) > 0.01
        ) {
            return true;
        }

        /*
         * ERP-11.3.82 — accounting workflow monetary authority.
         *
         * The native invoice is allowed to represent the same commercial sale
         * using a different internal row grouping. Example:
         *   booking source: 2 x 160,000
         *   native invoice: equivalent row structure totaling 320,000.
         *
         * If BOTH:
         *   - invoice header total equals the authoritative saved-ticket total
         *   - native Air Ticket invoice-line aggregate equals that same total
         *
         * then customer commercial value is synchronized and workflow must not
         * be blocked by row grouping / quantity-rate representation.
         */
        $currentAirTotal = round(
            array_sum(
                array_map(
                    fn (Model $line): float =>
                        $this->lineTotal($line),
                    $currentAirLines
                )
            ),
            2
        );

        if (
            $headerTotal !== null
            && $currentAirLines !== []
            && abs(
                $headerTotal
                - $expectedTotal
            ) <= 0.01
            && abs(
                $currentAirTotal
                - $expectedTotal
            ) <= 0.01
        ) {
            return false;
        }

        if (count($groups) !== count($currentAirLines)) {
            return true;
        }

        /*
         * ERP-11.3.81:
         * Compare commercial lines as an unordered multiset.
         *
         * Eloquent relation ordering is not a commercial contract. The same
         * three Air Ticket lines can be returned in a different row order and
         * the old index-by-index comparison falsely marked the invoice stale.
         */
        $expectedSignatures = array_map(
            static fn (array $group): string =>
                number_format(
                    (float) ($group['quantity'] ?? 0),
                    3,
                    '.',
                    ''
                )
                .'|'
                .number_format(
                    (float) ($group['rate'] ?? 0),
                    2,
                    '.',
                    ''
                ),
            $groups
        );

        $actualSignatures = array_map(
            function (Model $line): string {
                $qty = $this->firstAttributeNumber(
                    $line,
                    ['quantity', 'qty'],
                    0
                );

                $rate = $this->firstAttributeNumber(
                    $line,
                    [
                        'unit_price',
                        'rate',
                        'price',
                        'sale_price',
                        'selling_price',
                    ],
                    0
                );

                return number_format(
                    $qty,
                    3,
                    '.',
                    ''
                )
                .'|'
                .number_format(
                    $rate,
                    2,
                    '.',
                    ''
                );
            },
            $currentAirLines
        );

        sort($expectedSignatures);
        sort($actualSignatures);

        if ($expectedSignatures !== $actualSignatures) {
            return true;
        }

        /*
         * Do NOT include sales_invoice_air_ticket_line_links in commercial
         * integrity. Those rows are passenger/ticket snapshot metadata, not
         * customer revenue, quantity, rate or invoice-header amount.
         */
        return false;
    }


    /**
     * @param list<array<string,mixed>> $tickets
     */
    private function linkSyncNeeded(
        Model $invoice,
        array $tickets
    ): bool {
        if (! Schema::hasTable(self::LINK_TABLE)) {
            return false;
        }

        try {
            return DB::table(self::LINK_TABLE)
                ->where(
                    'sales_invoice_id',
                    (int) $invoice->getKey()
                )
                ->count() !== count($tickets);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<Model>
     */
    private function currentAirLines(Model $invoice): array
    {
        $resolved = $this->resolveInvoiceLineRelation($invoice);

        if (! $resolved) {
            return [];
        }

        /** @var Relation $relation */
        $relation = $resolved['relation'];

        return $relation->get()
            ->filter(
                fn (Model $line): bool =>
                    $this->lineLooksLikeAirTicket($line)
            )
            ->values()
            ->all();
    }

    private function lineLooksLikeAirTicket(Model $line): bool
    {
        $attributes = $line->getAttributes();
        $signals = [];

        foreach ([
            'description',
            'item_description',
            'details',
            'particulars',
            'name',
            'service_name',
            'product_name',
            'service_code',
            'product_code',
            'revenue_mapping_key',
            'mapping_key',
            'service_type',
            'product_type',
        ] as $field) {
            if (array_key_exists($field, $attributes)) {
                $signals[] = (string) $attributes[$field];
            }
        }

        $text = strtolower(
            implode(' ', $signals)
        );

        return (
            str_contains($text, 'air ticket')
            || str_contains($text, 'air_ticket')
            || str_contains($text, 'ticket revenue')
        );
    }

    /**
     * Keep native invoice ordering deterministic without renumbering any row.
     * The physical line_no column is the first authority when it exists; the
     * model key is only a stable tie-breaker for legacy or incomplete rows.
     *
     * @param Collection<int,Model> $lines
     * @param list<string> $columns
     * @return Collection<int,Model>
     */
    private function orderInvoiceLines(
        Collection $lines,
        array $columns
    ): Collection {
        $field = $this->invoiceLineNumberField($columns);

        return $lines->sort(
            function (Model $left, Model $right) use ($field): int {
                $leftNo = $this->existingInvoiceLineNumber($left, $field);
                $rightNo = $this->existingInvoiceLineNumber($right, $field);

                if ($leftNo !== $rightNo) {
                    if ($leftNo === null) {
                        return 1;
                    }

                    if ($rightNo === null) {
                        return -1;
                    }

                    return $leftNo <=> $rightNo;
                }

                return (int) $left->getKey() <=> (int) $right->getKey();
            }
        )->values();
    }

    /** @param list<string> $columns */
    private function invoiceLineNumberField(array $columns): ?string
    {
        foreach ([
            'line_no',
            'line_number',
            'sequence_no',
            'sequence',
            'sort_order',
        ] as $field) {
            if (in_array($field, $columns, true)) {
                return $field;
            }
        }

        return null;
    }

    private function existingInvoiceLineNumber(
        Model $line,
        ?string $field
    ): ?int {
        if ($field === null) {
            return null;
        }

        $lineNo = (int) $line->getAttribute($field);

        return $lineNo > 0 ? $lineNo : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $columns
     * @return array<string,mixed>
     */
    private function preserveExistingInvoiceLineNumbers(
        Model $line,
        array $payload,
        array $columns
    ): array {
        if (! $line->exists) {
            return $payload;
        }

        foreach ([
            'line_no',
            'line_number',
            'sequence_no',
            'sequence',
            'sort_order',
        ] as $field) {
            if (! in_array($field, $columns, true)) {
                continue;
            }

            $value = $line->getAttribute($field);

            if ($value !== null && $value !== '') {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param Collection<int,Model> $lines
     * @return array<int,true>
     */
    private function occupiedInvoiceLineNumbers(
        Collection $lines,
        ?string $field
    ): array {
        $occupied = [];

        foreach ($lines as $line) {
            $lineNo = $this->existingInvoiceLineNumber($line, $field);

            if ($lineNo !== null) {
                $occupied[$lineNo] = true;
            }
        }

        return $occupied;
    }

    /** @param array<int,true> $occupied */
    private function nextInvoiceLineNumber(array &$occupied): int
    {
        $lineNo = $occupied === []
            ? 1
            : max(array_keys($occupied)) + 1;

        while (isset($occupied[$lineNo])) {
            $lineNo++;
        }

        $occupied[$lineNo] = true;

        return $lineNo;
    }

    /**
     * @param list<string> $columns
     * @return array<string,mixed>
     */
    private function invoiceLinePayload(
        array $group,
        array $columns,
        int $lineNo
    ): array {
        $payload = [];

        $fareTitle = match ($group['fare_type']) {
            'CHILD' => 'Child',
            'INFANT' => 'Infant',
            default => 'Adult',
        };

        $description =
            $fareTitle.' Air Ticket';

        $quantity = (int) $group['quantity'];
        $unitPrice = round(
            (float) $group['rate'],
            2
        );
        $total = round(
            $quantity * $unitPrice,
            2
        );

        $this->putAll(
            $payload,
            $columns,
            [
                'line_no',
                'line_number',
                'sequence_no',
                'sequence',
                'sort_order',
            ],
            $lineNo
        );

        $this->putAll(
            $payload,
            $columns,
            [
                'description',
                'item_description',
                'particulars',
                'name',
                'service_name',
                'product_name',
            ],
            $description
        );

        $this->putAll(
            $payload,
            $columns,
            ['quantity', 'qty'],
            $quantity
        );

        $this->putAll(
            $payload,
            $columns,
            [
                'unit_price',
                'rate',
                'price',
                'sale_price',
                'selling_price',
            ],
            $unitPrice
        );

        $this->putAll(
            $payload,
            $columns,
            [
                'discount_amount',
                'discount',
            ],
            0
        );

        $this->putAll(
            $payload,
            $columns,
            [
                'amount',
                'line_total',
                'net_amount',
                'total_amount',
                'total',
            ],
            $total
        );

        $this->putAll(
            $payload,
            $columns,
            [
                'passenger_count',
                'pax_count',
            ],
            $quantity
        );

        $ticketSummary = implode(
            "\n",
            array_map(
                static function (array $ticket): string {
                    $parts = array_filter([
                        trim((string) ($ticket['passenger_name'] ?? '')),
                        trim((string) ($ticket['ticket_number'] ?? '')) !== ''
                            ? 'Ticket '.trim((string) $ticket['ticket_number'])
                            : '',
                        trim((string) ($ticket['pnr'] ?? '')) !== ''
                            ? 'PNR '.trim((string) $ticket['pnr'])
                            : '',
                    ]);

                    return implode(' · ', $parts);
                },
                $group['tickets']
            )
        );

        foreach ([
            'notes',
            'remarks',
            'line_notes',
            'memo',
        ] as $field) {
            if (in_array($field, $columns, true)) {
                $payload[$field] = $ticketSummary;
                break;
            }
        }

        foreach ([
            'metadata',
            'meta',
            'snapshot_json',
            'service_snapshot',
            'passenger_snapshot',
        ] as $field) {
            if (! in_array($field, $columns, true)) {
                continue;
            }

            $payload[$field] = json_encode(
                [
                    'source' => 'AIR_TICKET_SAVED_PASSENGERS',
                    'fare_type' => $group['fare_type'],
                    'rate' => $unitPrice,
                    'tickets' => $group['tickets'],
                ],
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            );

            break;
        }

        return $payload;
    }

    private function copyMappingFields(
        Model $from,
        Model $to,
        array $columns
    ): void {
        $payload = [];

        foreach ($this->mappingFields() as $field) {
            if (! in_array($field, $columns, true)) {
                continue;
            }

            $value = $from->getAttribute($field);

            if ($value !== null) {
                $payload[$field] = $value;
            }
        }

        if ($payload !== []) {
            $to->forceFill($payload);
        }
    }

    /**
     * Preserve invoice-level native structure independently from accounting /
     * product mappings, then stop before save if any physical non-null,
     * no-default field still has no native authority.
     *
     * @param array<string,mixed> $payload
     * @param list<string> $columns
     * @param array<string,array<string,mixed>> $metadata
     * @return array<string,mixed>
     */
    private function preserveNativeStructuralFields(
        Model $template,
        Model $line,
        Model $invoice,
        array $payload,
        array $columns,
        array $metadata,
        string $table
    ): array {
        $currency = $this->nativeInvoiceCurrency(
            $template,
            $invoice
        );

        foreach (['currency_code', 'currency'] as $field) {
            if (in_array($field, $columns, true) && $currency !== null) {
                $payload[$field] = $currency;
            }
        }

        if (
            in_array('currency_code', $columns, true)
            && $currency === null
        ) {
            throw ValidationException::withMessages([
                'invoice' => 'Sales Invoice line currency could not be resolved from the native invoice.',
            ]);
        }

        $unresolved = [];

        foreach ($metadata as $field => $meta) {
            if (
                ! in_array($field, $columns, true)
                || array_key_exists($field, $payload)
                || $this->columnCanBeOmitted($field, $meta)
                || $this->hasNativeValue($line->getAttribute($field))
            ) {
                continue;
            }

            $value = $template->getAttribute($field);

            if (
                ! $this->hasNativeValue($value)
                && in_array(
                    $field,
                    [
                        'currency_id',
                        'company_id',
                        'branch_id',
                        'office_id',
                        'customer_id',
                        'party_id',
                    ],
                    true
                )
            ) {
                $value = $invoice->getAttribute($field);
            }

            if ($this->hasNativeValue($value)) {
                $payload[$field] = $value;
                continue;
            }

            $unresolved[] = $field;
        }

        if ($unresolved !== []) {
            throw ValidationException::withMessages([
                'invoice' => 'Required native Sales Invoice line field(s) could not be resolved from the template or invoice: '
                    .implode(', ', $unresolved)
                    .'. Native table: '.$table.'.',
            ]);
        }

        return $payload;
    }

    private function nativeInvoiceCurrency(
        Model $template,
        Model $invoice
    ): ?string {
        foreach ([$template, $invoice] as $authority) {
            foreach (['currency_code', 'currency'] as $field) {
                $value = strtoupper(trim((string) (
                    $authority->getAttribute($field)
                    ?? ''
                )));

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @return array<string,array<string,mixed>> */
    private function columnMetadata(string $table): array
    {
        $result = [];

        try {
            foreach (Schema::getColumns($table) as $column) {
                if (! is_array($column)) {
                    continue;
                }

                $name = (string) (
                    $column['name']
                    ?? $column['column_name']
                    ?? ''
                );

                if ($name !== '') {
                    $result[$name] = $column;
                }
            }
        } catch (Throwable) {
        }

        return $result;
    }

    /** @param array<string,mixed> $meta */
    private function columnCanBeOmitted(
        string $field,
        array $meta
    ): bool {
        if (
            in_array(
                $field,
                ['id', 'created_at', 'updated_at', 'deleted_at'],
                true
            )
        ) {
            return true;
        }

        $nullable = $meta['nullable']
            ?? $meta['is_nullable']
            ?? false;
        $nullable = is_bool($nullable)
            ? $nullable
            : in_array(
                strtoupper(trim((string) $nullable)),
                ['1', 'TRUE', 'YES'],
                true
            );
        $default = array_key_exists('default', $meta)
            && $meta['default'] !== null;
        $auto = (bool) (
            $meta['auto_increment']
            ?? $meta['autoincrement']
            ?? false
        );
        $generated = trim((string) (
            $meta['generation_expression']
            ?? $meta['expression']
            ?? ''
        )) !== '';

        return $nullable || $default || $auto || $generated;
    }

    private function hasNativeValue(mixed $value): bool
    {
        return $value !== null
            && (! is_string($value) || trim($value) !== '');
    }

    /**
     * @return list<string>
     */
    private function mappingFields(): array
    {
        return [
            'product_service_id',
            'booking_service_id',
            'revenue_mapping_key',
            'mapping_key',
            'tax_code_id',
            'tax_id',
            'account_id',
            'revenue_account_id',
            'service_code',
            'product_code',
            'service_type',
            'product_type',
            'unit_type',
        ];
    }

    private function writeTicketLinks(
        array $snapshot,
        array $groupLineMap
    ): void {
        DB::table(self::LINK_TABLE)
            ->where(
                'sales_invoice_id',
                (int) $snapshot['invoice']->getKey()
            )
            ->delete();

        $now = now();
        $rows = [];

        foreach ($snapshot['groups'] as $group) {
            $lineId = $groupLineMap[$group['key']]
                ?? null;

            foreach ($group['tickets'] as $ticket) {
                $rows[] = [
                    'sales_invoice_id' =>
                        (int) $snapshot['invoice']->getKey(),
                    'sales_invoice_line_id' =>
                        $lineId ? (int) $lineId : null,
                    'booking_id' =>
                        (int) $snapshot['booking_id'],
                    'source_ticket_table' =>
                        (string) $ticket['source_table'],
                    'source_ticket_id' =>
                        (string) ($ticket['source_id'] ?? ''),
                    'passenger_id' =>
                        (string) ($ticket['passenger_id'] ?? ''),
                    'passenger_name' =>
                        (string) ($ticket['passenger_name'] ?? ''),
                    'fare_type' =>
                        (string) $ticket['fare_type'],
                    'ticket_number' =>
                        (string) $ticket['ticket_number'],
                    'pnr' =>
                        (string) $ticket['pnr'],
                    'customer_sale' =>
                        round((float) $ticket['customer_sale'], 2),
                    'supplier_cost' =>
                        round((float) $ticket['supplier_cost'], 2),
                    'rate_group_key' =>
                        (string) $group['key'],
                    'source_snapshot_json' =>
                        json_encode(
                            $ticket,
                            JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                        ),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            DB::table(self::LINK_TABLE)
                ->insert($rows);
        }
    }

    private function applyHeaderDetails(
        Request $request,
        Model $invoice
    ): void {
        $columns = Schema::getColumnListing(
            $invoice->getTable()
        );

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

        if (
            in_array('updated_by', $columns, true)
            && $request->user()?->id
        ) {
            $payload['updated_by'] =
                (int) $request->user()->id;
        }

        if ($payload !== []) {
            $invoice->forceFill($payload);
            $invoice->saveQuietly();
        }
    }

    private function invoiceCommercialHeaderTotal(
        Model $invoice
    ): ?float {
        $attributes = $invoice->getAttributes();

        foreach ([
            'grand_total',
            'total_amount',
            'net_total',
            'invoice_total',
            'total',
            'amount',
            'subtotal',
        ] as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];

            if ($value === null || $value === '') {
                continue;
            }

            return round(
                $this->number($value),
                2
            );
        }

        return null;
    }

    private function synchronizeHeaderTotals(
        Model $invoice,
        float $total,
        ?int $userId
    ): void {
        $columns = Schema::getColumnListing(
            $invoice->getTable()
        );

        $payload = [];

        foreach ([
            'subtotal',
            'net_total',
            'grand_total',
            'total_amount',
            'total',
            'amount',
        ] as $field) {
            if (in_array($field, $columns, true)) {
                $payload[$field] =
                    round($total, 2);
            }
        }

        if (
            in_array('updated_by', $columns, true)
            && $userId
        ) {
            $payload['updated_by'] = $userId;
        }

        if ($payload !== []) {
            $invoice->forceFill($payload);
            $invoice->saveQuietly();
        }
    }

    private function lineTotal(Model $line): float
    {
        foreach ([
            'line_total',
            'net_amount',
            'total_amount',
            'total',
            'amount',
        ] as $field) {
            $value = $line->getAttribute($field);

            if ($value !== null && $value !== '') {
                return $this->number($value);
            }
        }

        $qty = $this->firstAttributeNumber(
            $line,
            ['quantity', 'qty'],
            1
        );

        $rate = $this->firstAttributeNumber(
            $line,
            [
                'unit_price',
                'rate',
                'price',
                'sale_price',
                'selling_price',
            ],
            0
        );

        $discount = $this->firstAttributeNumber(
            $line,
            ['discount_amount', 'discount'],
            0
        );

        return max(
            0.0,
            ($qty * $rate) - $discount
        );
    }

    private function firstAttributeNumber(
        Model $model,
        array $fields,
        float $default
    ): float {
        foreach ($fields as $field) {
            $value = $model->getAttribute($field);

            if ($value === null || $value === '') {
                continue;
            }

            return $this->number($value);
        }

        return $default;
    }

    private function firstNumber(
        object $row,
        array $columns,
        array $fields
    ): float {
        foreach ($fields as $field) {
            if (! in_array($field, $columns, true)) {
                continue;
            }

            $value = data_get($row, $field);

            if ($value === null || $value === '') {
                continue;
            }

            return $this->number($value);
        }

        return 0.0;
    }

    private function firstString(
        object $row,
        array $columns,
        array $fields
    ): string {
        foreach ($fields as $field) {
            if (! in_array($field, $columns, true)) {
                continue;
            }

            $value = trim((string) data_get($row, $field, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function discountAmount(
        float $base,
        string $type,
        float $value
    ): float {
        $type = strtolower($type);

        if (
            str_contains($type, 'percent')
            || str_contains($type, '%')
        ) {
            return max(
                0.0,
                $base * $value / 100
            );
        }

        return max(0.0, $value);
    }

    private function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = preg_replace(
            '/[^0-9.\-]/',
            '',
            (string) $value
        ) ?? '';

        return is_numeric($clean)
            ? (float) $clean
            : 0.0;
    }

    private function saleColumn(array $columns): ?string
    {
        return $this->firstColumn(
            $columns,
            [
                'selling_total',
                'customer_sale',
                'customer_sell',
                'customer_sale_amount',
                'customer_sell_amount',
                'sale_amount',
                'sell_amount',
                'selling_price',
                'sale_price',
                'customer_price',
                'receivable_amount',
                'fare_total',
            ]
        );
    }

    private function supplierCostColumn(array $columns): ?string
    {
        return $this->firstColumn(
            $columns,
            [
                'net_supplier_cost',
                'supplier_cost',
                'supplier_cost_amount',
                'net_cost',
                'purchase_cost',
                'purchase_price',
                'supplier_total',
                'supplier_charges',
            ]
        );
    }

    private function fareTypeColumn(array $columns): ?string
    {
        return $this->firstColumn(
            $columns,
            [
                'fare_type',
                'passenger_type',
                'pax_type',
                'age_type',
                'ticket_type',
            ]
        );
    }

    private function passengerIdColumn(array $columns): ?string
    {
        return $this->firstColumn(
            $columns,
            [
                'passenger_id',
                'booking_passenger_id',
                'traveller_id',
                'traveler_id',
            ]
        );
    }

    private function firstColumn(
        array $columns,
        array $candidates
    ): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function passengerForeignTable(
        string $table,
        ?string $passengerColumn
    ): ?string {
        if (! $passengerColumn) {
            return null;
        }

        try {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (! is_array($foreign)) {
                    continue;
                }

                $locals = (array) (
                    $foreign['columns']
                    ?? $foreign['local_columns']
                    ?? []
                );

                if (! in_array($passengerColumn, $locals, true)) {
                    continue;
                }

                $foreignTable = (string) (
                    $foreign['foreign_table']
                    ?? $foreign['foreign_table_name']
                    ?? $foreign['table']
                    ?? ''
                );

                return $foreignTable !== ''
                    ? $foreignTable
                    : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function allTables(): array
    {
        $tables = [];

        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta)
                    ? (string) (
                        $meta['name']
                        ?? $meta['table_name']
                        ?? ''
                    )
                    : '';

                if ($table !== '') {
                    $tables[] = $table;
                }
            }
        } catch (Throwable) {
        }

        return array_values(
            array_unique($tables)
        );
    }

    private function putAll(
        array &$payload,
        array $columns,
        array $fields,
        mixed $value
    ): void {
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

            foreach (
                $reflection->getMethods(
                    ReflectionMethod::IS_PUBLIC
                )
                as $method
            ) {
                if ($method->getNumberOfRequiredParameters() > 0) {
                    continue;
                }

                $name = $method->getName();
                $lower = strtolower($name);
                $score = 0;

                if ($lower === 'invoiceitems') $score += 3200;
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
        } catch (Throwable) {
        }

        arsort($scores);

        foreach ($scores as $name => $score) {
            try {
                $relation = $invoice->{$name}();

                if (! $relation instanceof Relation) {
                    continue;
                }

                $table = strtolower(
                    $relation->getRelated()->getTable()
                );

                if (
                    ! str_contains($table, 'invoice')
                    && ! str_contains($table, 'line')
                    && ! str_contains($table, 'item')
                    && ! str_contains($table, 'detail')
                ) {
                    continue;
                }

                return [
                    'name' => $name,
                    'relation' => $relation,
                ];
            } catch (Throwable) {
            }
        }

        return $this->resolveRelationByForeignKey(
            $invoice
        );
    }

    private function resolveRelationByForeignKey(
        Model $invoice
    ): ?array {
        $invoiceTable = $invoice->getTable();
        $invoiceKey = $invoice->getKeyName();

        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta)
                    ? (string) (
                        $meta['name']
                        ?? $meta['table_name']
                        ?? ''
                    )
                    : '';

                if ($table === '') {
                    continue;
                }

                $lower = strtolower($table);

                if (
                    ! str_contains($lower, 'invoice')
                    || (
                        ! str_contains($lower, 'item')
                        && ! str_contains($lower, 'line')
                        && ! str_contains($lower, 'detail')
                    )
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

                    $locals = (array) (
                        $foreignKey['columns']
                        ?? $foreignKey['local_columns']
                        ?? []
                    );

                    $foreignKeyColumn =
                        (string) ($locals[0] ?? '');

                    if ($foreignKeyColumn === '') {
                        continue;
                    }

                    $model = $this->modelForTable($table);

                    if (! $model) {
                        continue;
                    }

                    $relation = $invoice->hasMany(
                        get_class($model),
                        $foreignKeyColumn,
                        $invoiceKey
                    );

                    return [
                        'name' => '[dynamic '.$table.']',
                        'relation' => $relation,
                    ];
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    private function modelForTable(string $table): ?Model
    {
        $studly = Str::studly(
            Str::singular($table)
        );

        foreach ([
            'App\\Models\\'.$studly,
            'App\\Models\\Sales\\'.$studly,
            'App\\Models\\Accounting\\'.$studly,
            'App\\Models\\Travel\\'.$studly,
        ] as $class) {
            if (! class_exists($class)) {
                continue;
            }

            try {
                $model = app($class);

                if (
                    $model instanceof Model
                    && $model->getTable() === $table
                ) {
                    return $model;
                }
            } catch (Throwable) {
            }
        }

        return null;
    }
}
