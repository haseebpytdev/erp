<?php

namespace App\Services\Operations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * ERP-10.31.72
 *
 * Fresh Group Umrah accounting bridge.
 *
 * IMPORTANT ARCHITECTURE
 * ----------------------
 * Native SalesInvoiceService::createFromBooking() is intentionally NOT used
 * for Group Umrah. Production proved that createFromBooking is coupled to
 * booking_services passenger_link_mode MULTIPLE / REQUIRED, while Group Umrah
 * commercial accounting must be allowed before passenger names are received.
 *
 * This writer creates a real native App\Models\SalesInvoice Draft directly,
 * keeps one package-level invoice line, and then uses the existing native
 * SalesInvoiceService::updateDraft() when that method can safely normalize the
 * Draft. If updateDraft cannot complete, the native invoice line relation is
 * populated directly with schema-verified Product Service/account mappings.
 *
 * No parallel customer-invoice table is introduced.
 * No passenger placeholder is created.
 * Flight / Hotel / Transport / Other Service rows remain voucher-only.
 */
class NativeSalesInvoiceDraftCreator
{
    public function __construct(
        private readonly NativeBookingCustomerResolver $customers,
    ) {}

    public function create(
        Request $request,
        int $bookingId,
        string $mode = 'base',
        ?int $amendmentId = null
    ): array {
        $commercial = $this->commercial($bookingId);
        $booking = $this->booking($bookingId);
        $identity = $this->customers->resolve($bookingId);

        if (! $commercial) {
            throw ValidationException::withMessages([
                'invoice' => 'Save the Group Umrah commercial package before creating its Sales Invoice.',
            ]);
        }

        if (
            strtolower(trim((string) ($commercial['save_status'] ?? 'draft')))
            !== 'complete'
        ) {
            throw ValidationException::withMessages([
                'invoice' => 'Save Commercial before creating the Customer Sales Invoice.',
            ]);
        }

        if ((int) ($identity['id'] ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'invoice' => 'The booking Customer / Party could not be resolved for the Sales Invoice.',
            ]);
        }

        $amount = $mode === 'supplementary'
            ? $this->amendmentAmount($bookingId, $amendmentId)
            : $this->commercialInvoiceAmount($commercial);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'invoice' => 'The Customer Sales Invoice amount must be greater than zero.',
            ]);
        }

        /*
         * Idempotency first. Never create a second base invoice just because a
         * previous browser redirect or diagnostic attempt was interrupted.
         */
        $existing = $this->linkedInvoice(
            $bookingId,
            $mode,
            $amendmentId
        );

        if ($existing) {
            if ($this->mappedInvoiceIsDraft($existing)) {
                return $this->synchronizeExistingDraftInvoice(
                    $request,
                    $existing,
                    $bookingId,
                    $mode,
                    $amendmentId,
                    $booking,
                    $commercial,
                    $identity,
                    $amount
                );
            }

            return $existing;
        }

        $recovered = $this->recoverExistingNativeInvoice(
            $bookingId,
            $booking,
            $commercial,
            (int) $identity['id'],
            $amount,
            $mode,
            $amendmentId
        );

        if ($recovered) {
            $this->link(
                $bookingId,
                $mode,
                $amendmentId,
                $recovered,
                $request->user()?->id
            );

            if ($this->mappedInvoiceIsDraft($recovered)) {
                return $this->synchronizeExistingDraftInvoice(
                    $request,
                    $recovered,
                    $bookingId,
                    $mode,
                    $amendmentId,
                    $booking,
                    $commercial,
                    $identity,
                    $amount
                );
            }

            return $recovered;
        }

        $productService = $this->resolveGroupUmrahProductService(
            $bookingId,
            $commercial
        );

        $bookingService = $this->existingGroupUmrahBookingService(
            $bookingId,
            $productService
        );

        $invoice = DB::transaction(function () use (
            $request,
            $bookingId,
            $mode,
            $amendmentId,
            $booking,
            $commercial,
            $identity,
            $amount,
            $productService,
            $bookingService
        ): array {
            $invoiceModel = $this->newSalesInvoiceModel();

            $header = $this->nativeHeaderPayload(
                $invoiceModel,
                $request,
                $bookingId,
                $mode,
                $amendmentId,
                $booking,
                $commercial,
                $identity,
                $amount
            );

            try {
                $invoiceModel->forceFill($header);
                $invoiceModel->saveQuietly();
                $invoiceModel->refresh();
            } catch (\Throwable $e) {
                report($e);

                throw ValidationException::withMessages([
                    'invoice' => 'The native Sales Invoice Draft header could not be created. '
                        .$this->safeDatabaseFailure(
                            $e,
                            $invoiceModel->getTable()
                        ),
                ]);
            }

            /*
             * Native updateDraft is the preferred normalizer because it is the
             * ERP's existing edit-Draft path and is not passenger-linked.
             */
            $nativeUpdateError = null;

            try {
                $this->applyNativeUpdateDraft(
                    $request,
                    $invoiceModel,
                    $bookingId,
                    $mode,
                    $amendmentId,
                    $booking,
                    $commercial,
                    $identity,
                    $amount,
                    $productService,
                    $bookingService
                );
            } catch (\Throwable $e) {
                report($e);
                $nativeUpdateError = $e;
            }

            $invoiceModel->refresh();

            /*
             * updateDraft may update a Draft header but not understand the
             * Group Umrah package line. Verify a line is really present.
             */
            if (! $this->invoiceHasCommercialLine($invoiceModel)) {
                try {
                    $this->upsertNativePackageInvoiceLine(
                        $request,
                        $invoiceModel,
                        $bookingId,
                        $mode,
                        $amendmentId,
                        $booking,
                        $commercial,
                        $identity,
                        $amount,
                        $productService,
                        $bookingService
                    );
                } catch (\Throwable $lineError) {
                    report($lineError);

                    /*
                     * Delete only the brand-new, still-unlinked Draft that this
                     * transaction just created. Nothing posted/approved exists.
                     */
                    try {
                        $invoiceModel->deleteQuietly();
                    } catch (\Throwable) {
                    }

                    $detail = $this->validationMessage($lineError);

                    if (
                        $detail === ''
                        && $nativeUpdateError
                    ) {
                        $detail = $this->validationMessage(
                            $nativeUpdateError
                        );
                    }

                    throw ValidationException::withMessages([
                        'invoice' => 'The native Sales Invoice Draft header was available, but its Group Umrah package line could not be created.'
                            .($detail !== '' ? ' '.$detail : ''),
                    ]);
                }
            }

            $this->synchronizeInvoiceHeaderTotals(
                $invoiceModel,
                $amount,
                $request->user()?->id
            );

            $invoiceModel->refresh();

            return $this->mapNativeInvoiceModel(
                $invoiceModel
            );
        });

        $this->link(
            $bookingId,
            $mode,
            $amendmentId,
            $invoice,
            $request->user()?->id
        );

        return $invoice;
    }

    private function newSalesInvoiceModel(): Model
    {
        $class = \App\Models\SalesInvoice::class;

        if (! class_exists($class)) {
            throw ValidationException::withMessages([
                'invoice' => 'The native App\\Models\\SalesInvoice model is unavailable.',
            ]);
        }

        try {
            $model = app($class);
        } catch (\Throwable) {
            $model = new $class();
        }

        if (! $model instanceof Model) {
            throw ValidationException::withMessages([
                'invoice' => 'The native SalesInvoice class is not an Eloquent model.',
            ]);
        }

        if (! Schema::hasTable($model->getTable())) {
            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice table is unavailable: '
                    .$model->getTable().'.',
            ]);
        }

        return $model->newInstance();
    }

    private function nativeHeaderPayload(
        Model $invoice,
        Request $request,
        int $bookingId,
        string $mode,
        ?int $amendmentId,
        array $booking,
        array $commercial,
        array $identity,
        float $amount
    ): array {
        $table = $invoice->getTable();
        $columns = Schema::getColumnListing($table);
        $metadata = $this->columnMetadata($table);

        $bookingDate = substr(
            (string) (
                $booking['booking_date']
                ?? $commercial['commercial_saved_at']
                ?? now()->toDateString()
            ),
            0,
            10
        );

        $currencyCode = strtoupper(trim((string) (
            $commercial['currency_code']
            ?? $booking['currency_code']
            ?? $booking['currency']
            ?? 'PKR'
        ))) ?: 'PKR';

        $customerId = (int) $identity['id'];
        $branchId = (int) (
            $booking['branch_id']
            ?? $booking['office_id']
            ?? 0
        );

        /*
         * Native Sales Invoice belongs to an ERP Company/legal entity.
         * Resolve it from authoritative existing context; never hard-code 1.
         */
        $companyId = $this->resolveNativeCompanyId(
            $booking,
            $branchId,
            $request
        );

        $reference = $this->bookingReference(
            $bookingId,
            $booking
        );

        $description = $mode === 'supplementary'
            ? 'Group Umrah Additional Pax - '.$reference
            : 'Group Umrah Package - '
                .trim((string) (
                    $commercial['package_name']
                    ?? $commercial['package_code']
                    ?? $reference
                ));

        $invoiceNumber = $this->groupUmrahInvoiceNumber(
            $bookingId,
            $mode,
            $amendmentId,
            $bookingDate
        );

        $payload = [];

        $this->putAll($payload, $columns, [
            'booking_id',
            'travel_booking_id',
            'source_booking_id',
        ], $bookingId);

        $this->putAll($payload, $columns, [
            'customer_id',
            'party_id',
            'client_id',
            'customer_party_id',
            'bill_to_party_id',
        ], $customerId);

        $this->putAll($payload, $columns, [
            'branch_id',
            'office_id',
        ], $branchId ?: null);

        $this->putAll($payload, $columns, [
            'company_id',
        ], $companyId);

        $currencyId = $this->currencyId(
            $currencyCode
        );

        $this->putAll($payload, $columns, [
            'currency_id',
        ], $currencyId);

        $this->putAll($payload, $columns, [
            'currency_code',
            'currency',
        ], $currencyCode);

        $fiscalYearId = $this->fiscalYearId(
            $bookingDate
        );

        $this->putAll($payload, $columns, [
            'fiscal_year_id',
            'financial_year_id',
            'fy_id',
        ], $fiscalYearId);

        $this->putAll($payload, $columns, [
            'invoice_date',
            'document_date',
            'date',
        ], $bookingDate);

        $this->putAll($payload, $columns, [
            'due_date',
        ], $bookingDate);

        $this->putAll($payload, $columns, [
            'invoice_number',
            'invoice_no',
            'document_no',
            'number',
        ], $invoiceNumber);

        $this->putAll($payload, $columns, [
            'reference',
            'reference_no',
            'external_reference',
            'invoice_reference',
            'booking_reference',
        ], $reference);

        $this->putAll($payload, $columns, [
            'description',
            'notes',
            'narration',
            'memo',
        ], $description);

        $this->putAll($payload, $columns, [
            'status',
            'invoice_status',
            'document_status',
            'workflow_status',
        ], 'draft');

        $this->putAll($payload, $columns, [
            'payment_status',
        ], 'unpaid');

        $this->putAll($payload, $columns, [
            'invoice_type',
            'document_type',
            'type',
        ], 'sales');

        $this->putAll($payload, $columns, [
            'source',
            'source_type',
        ], 'group_umrah');

        $this->putAll($payload, $columns, [
            'subtotal',
            'net_total',
            'grand_total',
            'invoice_total',
            'total_amount',
            'total',
            'amount',
            'balance_due',
            'outstanding_amount',
        ], $amount);

        $this->putAll($payload, $columns, [
            'discount_amount',
            'tax_amount',
            'paid_amount',
            'received_amount',
        ], 0);

        $userId = (int) ($request->user()?->id ?? 0);

        $this->putAll($payload, $columns, [
            'created_by',
            'created_by_id',
            'user_id',
        ], $userId ?: null);

        $this->putAll($payload, $columns, [
            'updated_by',
            'updated_by_id',
        ], $userId ?: null);

        /*
         * Required native fields not already mapped are filled only when their
         * semantics are high-confidence. No revenue/account ID is guessed.
         */
        $payload = $this->fillRequiredHeaderFields(
            $table,
            $columns,
            $metadata,
            $payload,
            $bookingId,
            $bookingDate,
            $invoiceNumber,
            $reference,
            $description,
            $amount,
            $customerId,
            $branchId,
            $companyId,
            $currencyId,
            $fiscalYearId,
            $userId
        );

        return $payload;
    }

    private function fillRequiredHeaderFields(
        string $table,
        array $columns,
        array $metadata,
        array $payload,
        int $bookingId,
        string $bookingDate,
        string $invoiceNumber,
        string $reference,
        string $description,
        float $amount,
        int $customerId,
        int $branchId,
        ?int $companyId,
        ?int $currencyId,
        ?int $fiscalYearId,
        int $userId
    ): array {
        $unresolved = [];

        foreach ($metadata as $field => $meta) {
            if (
                array_key_exists($field, $payload)
                || $this->columnCanBeOmitted($field, $meta)
            ) {
                continue;
            }

            $lower = strtolower($field);
            $valueResolved = true;
            $value = null;

            if (
                in_array(
                    $lower,
                    [
                        'booking_id',
                        'travel_booking_id',
                        'source_booking_id',
                    ],
                    true
                )
            ) {
                $value = $bookingId;
            } elseif (
                str_contains($lower, 'customer')
                || str_contains($lower, 'party')
                || str_contains($lower, 'client')
            ) {
                $value = $customerId;
            } elseif (
                str_contains($lower, 'branch')
                || str_contains($lower, 'office')
            ) {
                $value = $branchId ?: null;
            } elseif (
                $lower === 'company_id'
            ) {
                $value = $companyId;
            } elseif (
                str_contains($lower, 'fiscal')
                || str_contains($lower, 'financial_year')
                || $lower === 'fy_id'
            ) {
                $value = $fiscalYearId;
            } elseif (
                str_contains($lower, 'currency')
                && str_ends_with($lower, '_id')
            ) {
                $value = $currencyId;
            } elseif (
                str_contains($lower, 'currency')
            ) {
                $value = 'PKR';
            } elseif (
                str_contains($lower, 'invoice_date')
                || $lower === 'date'
                || str_contains($lower, 'document_date')
                || str_contains($lower, 'due_date')
            ) {
                $value = $bookingDate;
            } elseif (
                str_contains($lower, 'number')
                || str_ends_with($lower, '_no')
                || $lower === 'document_no'
            ) {
                $value = $invoiceNumber;
            } elseif (
                str_contains($lower, 'reference')
            ) {
                $value = $reference;
            } elseif (
                str_contains($lower, 'description')
                || str_contains($lower, 'notes')
                || str_contains($lower, 'narration')
                || str_contains($lower, 'memo')
            ) {
                $value = $description;
            } elseif (
                $lower === 'status'
                || str_ends_with($lower, '_status')
            ) {
                $value = str_contains($lower, 'payment')
                    ? 'unpaid'
                    : 'draft';
            } elseif (
                str_contains($lower, 'type')
            ) {
                $value = 'sales';
            } elseif (
                str_contains($lower, 'total')
                || str_contains($lower, 'amount')
                || str_contains($lower, 'balance')
            ) {
                $value = str_contains($lower, 'tax')
                    || str_contains($lower, 'discount')
                    || str_contains($lower, 'paid')
                    || str_contains($lower, 'received')
                    ? 0
                    : $amount;
            } elseif (
                str_contains($lower, 'created_by')
                || str_contains($lower, 'updated_by')
                || $lower === 'user_id'
            ) {
                $value = $userId ?: null;
            } elseif (
                str_starts_with($lower, 'is_')
                || in_array(
                    $this->metaType($meta),
                    ['boolean', 'bool'],
                    true
                )
            ) {
                $value = 0;
            } else {
                $valueResolved = false;
            }

            if ($valueResolved && $value !== null) {
                $payload[$field] = $value;
                continue;
            }

            if (! $valueResolved || $value === null) {
                $unresolved[] = $field;
            }
        }

        if ($unresolved) {
            $companyHelp = in_array('company_id', $unresolved, true)
                ? ' The booking, branch/office, authenticated staff and native invoice history did not expose one unambiguous company. Configure the native booking/branch company context rather than hard-coding a company ID.'
                : '';

            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice header has required field(s) that Group Umrah cannot safely infer: '
                    .implode(', ', array_slice($unresolved, 0, 10))
                    .'. Native table: '.$table.'.'
                    .$companyHelp,
            ]);
        }

        return $payload;
    }

    private function applyNativeUpdateDraft(
        Request $outerRequest,
        Model $invoice,
        int $bookingId,
        string $mode,
        ?int $amendmentId,
        array $booking,
        array $commercial,
        array $identity,
        float $amount,
        ?array $productService,
        ?array $bookingService
    ): void {
        $service = $this->nativeSalesInvoiceService();

        if (! $service || ! method_exists($service, 'updateDraft')) {
            return;
        }

        $method = new ReflectionMethod(
            $service,
            'updateDraft'
        );

        $payload = $this->nativeUpdatePayload(
            $bookingId,
            $mode,
            $amendmentId,
            $booking,
            $commercial,
            $identity,
            $amount,
            $productService,
            $bookingService
        );

        $subRequest = Request::create(
            '/internal/group-umrah/native-sales-invoice-update',
            'POST',
            $payload,
            $outerRequest->cookies->all(),
            [],
            $outerRequest->server->all()
        );

        if ($outerRequest->hasSession()) {
            $subRequest->setLaravelSession(
                $outerRequest->session()
            );
        }

        $subRequest->setUserResolver(
            fn () => $outerRequest->user()
        );

        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = strtolower(
                $parameter->getName()
            );

            if (
                $type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
            ) {
                $className = $type->getName();

                if (
                    is_a(
                        $className,
                        Request::class,
                        true
                    )
                ) {
                    $arguments[] = $subRequest;
                    continue;
                }

                if ($invoice instanceof $className) {
                    $arguments[] = $invoice;
                    continue;
                }

                if ($parameter->allowsNull()) {
                    $arguments[] = null;
                    continue;
                }

                throw new \RuntimeException(
                    'Unsupported native updateDraft parameter: '
                    .$className.' $'.$parameter->getName()
                );
            }

            if (
                $type instanceof ReflectionNamedType
                && $type->isBuiltin()
                && $type->getName() === 'array'
            ) {
                $arguments[] = $payload;
                continue;
            }

            if (
                in_array(
                    $name,
                    ['data', 'payload', 'attributes'],
                    true
                )
            ) {
                $arguments[] = $payload;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] =
                    $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new \RuntimeException(
                'Unsupported native updateDraft parameter: $'
                .$parameter->getName()
            );
        }

        $method->invokeArgs(
            $service,
            $arguments
        );
    }

    private function nativeUpdatePayload(
        int $bookingId,
        string $mode,
        ?int $amendmentId,
        array $booking,
        array $commercial,
        array $identity,
        float $amount,
        ?array $productService,
        ?array $bookingService
    ): array {
        $date = substr(
            (string) (
                $booking['booking_date']
                ?? now()->toDateString()
            ),
            0,
            10
        );

        $currency = strtoupper(trim((string) (
            $commercial['currency_code']
            ?? $booking['currency_code']
            ?? 'PKR'
        ))) ?: 'PKR';

        $reference = $this->bookingReference(
            $bookingId,
            $booking
        );

        $description = $mode === 'supplementary'
            ? 'Group Umrah Additional Pax - '.$reference
            : 'Group Umrah Package - '
                .trim((string) (
                    $commercial['package_name']
                    ?? $commercial['package_code']
                    ?? $reference
                ));

        $lines = [];

        foreach (
            $this->commercialInvoiceFareBands(
                $commercial,
                $mode,
                $amendmentId
            )
            as $index => $band
        ) {
            $lineDescription = $description
                .' - '.$band['label'];

            $line = [
                'line_no' => $index + 1,
                'description' => $lineDescription,
                'details' => $lineDescription,
                'service_name' => 'Group Umrah Package - '.$band['label'],
                'fare_type' => $band['fare_type'],
                'quantity' => $band['quantity'],
                'qty' => $band['quantity'],
                'unit_price' => $band['unit_price'],
                'rate' => $band['unit_price'],
                'price' => $band['unit_price'],
                'amount' => $band['net_total'],
                'line_total' => $band['net_total'],
                'net_amount' => $band['net_total'],
                'total_amount' => $band['net_total'],
                'total' => $band['net_total'],
                'discount_amount' => $band['discount_amount'],
                'tax_amount' => 0,
            ];

            if ($productService) {
                $line['product_service_id'] =
                    (int) $productService['id'];

                foreach (
                    $this->productServiceAccountMap(
                        $productService['row'] ?? []
                    )
                    as $field => $value
                ) {
                    $line[$field] = $value;
                }

                $revenueMappingKey =
                    $this->resolveRevenueMappingKey(
                        $productService,
                        $bookingService
                    );

                if ($revenueMappingKey !== null) {
                    $line['revenue_mapping_key'] =
                        $revenueMappingKey;
                }
            }

            if ($bookingService) {
                $line['booking_service_id'] =
                    (int) ($bookingService['id'] ?? 0);
            }

            $lines[] = $line;
        }

        $customerId = (int) $identity['id'];

        $payload = [
            'booking_id' => $bookingId,
            'source_booking_id' => $bookingId,
            'customer_id' => $customerId,
            'party_id' => $customerId,
            'invoice_date' => $date,
            'document_date' => $date,
            'due_date' => $date,
            'currency' => $currency,
            'currency_code' => $currency,
            'status' => 'draft',
            'invoice_status' => 'draft',
            'reference' => $reference,
            'reference_no' => $reference,
            'description' => $description,
            'notes' => $description,
            'subtotal' => $amount,
            'net_total' => $amount,
            'grand_total' => $amount,
            'total_amount' => $amount,
            'total' => $amount,
            'amount' => $amount,
            'discount_amount' => (float) (
                $commercial['discount_amount_total']
                ?? 0
            ),
            'tax_amount' => 0,
            'items' => $lines,
            'lines' => $lines,
            'details' => $lines,
            'invoice_items' => $lines,
            'invoice_lines' => $lines,
        ];

        if ($mode === 'supplementary') {
            $payload['invoice_mode'] =
                'supplementary';
            $payload['group_umrah_amendment_id'] =
                $amendmentId;
        }

        return $payload;
    }

    private function nativeSalesInvoiceService(): ?object
    {
        $class = \App\Services\Sales\SalesInvoiceService::class;

        if (! class_exists($class)) {
            return null;
        }

        try {
            return app($class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function upsertNativePackageInvoiceLine(
        Request $request,
        Model $invoice,
        int $bookingId,
        string $mode,
        ?int $amendmentId,
        array $booking,
        array $commercial,
        array $identity,
        float $amount,
        ?array $productService,
        ?array $bookingService
    ): void {
        $relationInfo = $this->resolveInvoiceLineRelation(
            $invoice
        );

        if (! $relationInfo) {
            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice line relation/table could not be resolved from '
                    .get_class($invoice).'.',
            ]);
        }

        /** @var Relation $relation */
        $relation = $relationInfo['relation'];
        $lineModel = $relation->getRelated();
        $table = $lineModel->getTable();

        if (! Schema::hasTable($table)) {
            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice line table is unavailable: '
                    .$table.'.',
            ]);
        }

        $columns = Schema::getColumnListing($table);
        $metadata = $this->columnMetadata($table);

        $reference = $this->bookingReference(
            $bookingId,
            $booking
        );

        $baseDescription = $mode === 'supplementary'
            ? 'Group Umrah Additional Pax - '.$reference
            : 'Group Umrah Package - '
                .trim((string) (
                    $commercial['package_name']
                    ?? $commercial['package_code']
                    ?? $reference
                ));

        $bands = $this->commercialInvoiceFareBands(
            $commercial,
            $mode,
            $amendmentId
        );

        if (! $bands) {
            throw ValidationException::withMessages([
                'invoice' => 'No Adult, Child or Infant commercial quantity is available for the Sales Invoice.',
            ]);
        }

        $expectedDescriptions = [];
        $userId = (int) ($request->user()?->id ?? 0);

        foreach ($bands as $index => $band) {
            $description = $baseDescription
                .' - '.$band['label'];

            $expectedDescriptions[] = $description;
            $payload = [];

            $this->putAll($payload, $columns, [
                'description',
                'item_description',
                'details',
                'particulars',
                'name',
                'service_name',
                'product_name',
            ], $description);

            $this->putAll($payload, $columns, [
                'fare_type',
                'fare_as',
                'passenger_type',
                'pax_type',
            ], $band['fare_type']);

            $this->putAll($payload, $columns, [
                'line_no',
                'line_number',
                'sequence_no',
                'sequence',
                'sort_order',
            ], $index + 1);

            $this->putAll($payload, $columns, [
                'quantity',
                'qty',
            ], $band['quantity']);

            $this->putAll($payload, $columns, [
                'unit_price',
                'rate',
                'price',
                'sale_price',
                'selling_price',
            ], $band['unit_price']);

            $this->putAll($payload, $columns, [
                'amount',
                'line_total',
                'net_amount',
                'total_amount',
                'total',
            ], $band['net_total']);

            $this->putAll($payload, $columns, [
                'discount_amount',
            ], $band['discount_amount']);

            $this->putAll($payload, $columns, [
                'tax_amount',
            ], 0);

            $this->putAll($payload, $columns, [
                'booking_id',
                'source_booking_id',
            ], $bookingId);

            $this->putAll($payload, $columns, [
                'customer_id',
                'party_id',
            ], (int) $identity['id']);

            $this->putAll($payload, $columns, [
                'company_id',
            ], (int) (
                $invoice->getAttribute('company_id')
                ?? $booking['company_id']
                ?? 0
            ) ?: null);

            if ($productService) {
                $this->putAll($payload, $columns, [
                    'product_service_id',
                ], (int) $productService['id']);

                foreach (
                    $this->productServiceAccountMap(
                        $productService['row'] ?? []
                    )
                    as $field => $value
                ) {
                    if (
                        in_array($field, $columns, true)
                        && ! array_key_exists($field, $payload)
                    ) {
                        $payload[$field] = $value;
                    }
                }

                $payload = $this->copyProductServiceSnapshots(
                    $columns,
                    $payload,
                    $productService['row'] ?? []
                );

                if (
                    in_array(
                        'revenue_mapping_key',
                        $columns,
                        true
                    )
                    && ! array_key_exists(
                        'revenue_mapping_key',
                        $payload
                    )
                ) {
                    $revenueMappingKey =
                        $this->resolveRevenueMappingKey(
                            $productService,
                            $bookingService
                        );

                    if ($revenueMappingKey !== null) {
                        $payload['revenue_mapping_key'] =
                            $revenueMappingKey;
                    }
                }
            }

            if ($bookingService) {
                $this->putAll($payload, $columns, [
                    'booking_service_id',
                ], (int) ($bookingService['id'] ?? 0));
            }

            $currencyCode = strtoupper(trim((string) (
                $commercial['currency_code']
                ?? $booking['currency_code']
                ?? 'PKR'
            ))) ?: 'PKR';

            $this->putAll($payload, $columns, [
                'currency_code',
                'currency',
            ], $currencyCode);

            $this->putAll($payload, $columns, [
                'currency_id',
            ], $this->currencyId($currencyCode));

            $this->putAll($payload, $columns, [
                'status',
                'line_status',
            ], 'active');

            $this->putAll($payload, $columns, [
                'created_by',
                'updated_by',
            ], $userId ?: null);

            $payload = $this->fillRequiredLineFields(
                $table,
                $columns,
                $metadata,
                $payload,
                $invoice,
                $bookingId,
                $band['quantity'],
                $band['unit_price'],
                $band['net_total'],
                $index + 1,
                $description,
                $productService,
                $bookingService,
                $userId
            );

            $existing = $this->findExistingFareInvoiceLine(
                $relation,
                $columns,
                $description,
                $band['fare_type'],
                $index + 1,
                $index === 0
            );

            try {
                if ($existing) {
                    $existing->forceFill($payload);
                    $existing->saveQuietly();
                    continue;
                }

                $line = $lineModel->newInstance();
                $line->forceFill($payload);

                if (method_exists($relation, 'save')) {
                    $relation->save($line);
                    continue;
                }

                throw new \RuntimeException(
                    'Native invoice line relation does not support save().'
                );
            } catch (\Throwable $e) {
                report($e);

                throw ValidationException::withMessages([
                    'invoice' => 'The native Group Umrah '.$band['label']
                        .' invoice line could not be saved. '
                        .$this->safeDatabaseFailure(
                            $e,
                            $table
                        ),
                ]);
            }
        }

        /*
         * A Draft may have been created by ERP-10.31.28–10.31.32 with one
         * legacy generic Qty=1 line. Remove only stale Group Umrah lines from
         * THIS Draft after the expected fare-band lines have been saved.
         */
        try {
            foreach ($relation->get() as $row) {
                if (! $row instanceof Model) {
                    continue;
                }

                $attributes = $row->getAttributes();
                $savedDescription = trim((string) (
                    $attributes['description']
                    ?? $attributes['item_description']
                    ?? $attributes['details']
                    ?? $attributes['name']
                    ?? ''
                ));

                $lower = strtolower($savedDescription);

                if (
                    str_contains($lower, 'group umrah')
                    && ! in_array(
                        $savedDescription,
                        $expectedDescriptions,
                        true
                    )
                ) {
                    $row->deleteQuietly();
                }
            }
        } catch (\Throwable) {
        }
    }

    private function findExistingFareInvoiceLine(
        Relation $relation,
        array $columns,
        string $description,
        string $fareType,
        int $lineNo,
        bool $allowLegacyAdoption = false
    ): ?Model {
        try {
            $baseQuery = clone $relation->getQuery();

            // Description is the primary identity; multiple Adult tranches may have different rates.
            foreach (['description','item_description','details','name','service_name'] as $field) {
                if (!in_array($field,$columns,true)) continue;
                $found=(clone $baseQuery)->where($field,$description)->first();
                if ($found instanceof Model) return $found;
            }

            foreach (['line_no','line_number','sequence_no','sequence','sort_order'] as $field) {
                if (!in_array($field,$columns,true)) continue;
                $found=(clone $baseQuery)->where($field,$lineNo)->first();
                if (!$found instanceof Model) continue;
                if ($allowLegacyAdoption || $this->isGroupUmrahInvoiceLine($found)) return $found;
            }

            // Fare type alone is not a safe identity except for adopting the legacy first line.
            if ($allowLegacyAdoption) {
                foreach (['fare_type','fare_as','passenger_type','pax_type'] as $field) {
                    if (!in_array($field,$columns,true)) continue;
                    $found=(clone $baseQuery)->where($field,$fareType)->first();
                    if ($found instanceof Model) return $found;
                }
                foreach ($baseQuery->get() as $row) {
                    if ($row instanceof Model && $this->isGroupUmrahInvoiceLine($row)) return $row;
                }
            }
        } catch (\Throwable) {}
        return null;
    }

    private function isGroupUmrahInvoiceLine(Model $line): bool
    {
        $attributes = $line->getAttributes();

        foreach ([
            'description',
            'item_description',
            'details',
            'particulars',
            'name',
            'service_name',
            'product_name',
        ] as $field) {
            $value = strtolower(trim((string) (
                $attributes[$field]
                ?? ''
            )));

            if (
                $value !== ''
                && str_contains($value, 'group umrah')
            ) {
                return true;
            }
        }

        return false;
    }

    private function fillRequiredLineFields(
        string $table,
        array $columns,
        array $metadata,
        array $payload,
        Model $invoice,
        int $bookingId,
        int $quantity,
        float $unitPrice,
        float $lineTotal,
        int $lineNo,
        string $description,
        ?array $productService,
        ?array $bookingService,
        int $userId
    ): array {
        $unresolved = [];

        foreach ($metadata as $field => $meta) {
            if (
                array_key_exists($field, $payload)
                || $this->columnCanBeOmitted($field, $meta)
            ) {
                continue;
            }

            $lower = strtolower($field);
            $resolved = true;
            $value = null;

            if (
                str_contains($lower, 'invoice')
                && str_ends_with($lower, '_id')
            ) {
                /*
                 * HasMany::save supplies the FK. Leave it to the relation.
                 */
                continue;
            } elseif (
                in_array(
                    $lower,
                    [
                        'line_no',
                        'line_number',
                        'sequence_no',
                        'sequence',
                        'sort_order',
                    ],
                    true
                )
            ) {
                /*
                 * Group Umrah has exactly one commercial customer line.
                 */
                $value = $lineNo;
            } elseif (
                in_array(
                    $lower,
                    ['booking_id', 'source_booking_id'],
                    true
                )
            ) {
                $value = $bookingId;
            } elseif (
                $lower === 'company_id'
            ) {
                $value = (int) (
                    $invoice->getAttribute('company_id')
                    ?? 0
                ) ?: null;
            } elseif (
                $lower === 'product_service_id'
            ) {
                $value = $productService['id']
                    ?? null;
            } elseif (
                $lower === 'booking_service_id'
            ) {
                $value = $bookingService['id']
                    ?? null;
            } elseif (
                str_contains($lower, 'description')
                || str_contains($lower, 'details')
                || str_contains($lower, 'particular')
                || $lower === 'name'
            ) {
                $value = $description;
            } elseif (
                in_array(
                    $lower,
                    ['quantity', 'qty'],
                    true
                )
            ) {
                $value = $quantity;
            } elseif (
                str_contains($lower, 'price')
                || str_contains($lower, 'rate')
            ) {
                $value = $unitPrice;
            } elseif (
                str_contains($lower, 'total')
                || str_contains($lower, 'amount')
            ) {
                $value = str_contains($lower, 'tax')
                    || str_contains($lower, 'discount')
                    ? 0
                    : $lineTotal;
            } elseif (
                $lower === 'status'
                || str_ends_with($lower, '_status')
            ) {
                $value = 'active';
            } elseif (
                str_contains($lower, 'created_by')
                || str_contains($lower, 'updated_by')
            ) {
                $value = $userId ?: null;
            } elseif (
                str_starts_with($lower, 'is_')
                || in_array(
                    $this->metaType($meta),
                    ['boolean', 'bool'],
                    true
                )
            ) {
                $value = 1;
            } elseif (
                str_ends_with($lower, '_snapshot')
                && $productService
            ) {
                $source = substr(
                    $field,
                    0,
                    -strlen('_snapshot')
                );

                if (
                    array_key_exists(
                        $source,
                        $productService['row'] ?? []
                    )
                ) {
                    $value =
                        $productService['row'][$source];
                } else {
                    $resolved = false;
                }
            } elseif (
                $lower === 'revenue_mapping_key'
            ) {
                $value = $this->resolveRevenueMappingKey(
                    $productService,
                    $bookingService
                );
            } elseif (
                str_contains($lower, 'account')
                && str_ends_with($lower, '_id')
            ) {
                $accounts =
                    $this->productServiceAccountMap(
                        $productService['row'] ?? []
                    );

                $value = $accounts[$field] ?? null;
            } else {
                $resolved = false;
            }

            if ($resolved && $value !== null) {
                $payload[$field] = $value;
                continue;
            }

            $unresolved[] = $field;
        }

        if ($unresolved) {
            $mappingHelp = '';

            if (
                in_array(
                    'revenue_mapping_key',
                    $unresolved,
                    true
                )
            ) {
                $productServiceId = (int) (
                    $productService['id']
                    ?? 0
                );

                $mappingDiagnostic =
                    $this->nativeRevenueMappingDiagnostic(
                        $productService,
                        $bookingService
                    );

                $mappingHelp =
                    ' The resolved Group Umrah Product Service'
                    .($productServiceId > 0
                        ? ' ID '.$productServiceId
                        : '')
                    .' has no single authoritative revenue_mapping_key after checking its Product Service row, booking-service snapshot, native ProductService relations/accessors, accounting mapping tables, historical native invoice lines, and guarded native mapping creation.'
                    .($mappingDiagnostic !== ''
                        ? ' ['.$mappingDiagnostic.']'
                        : '');
            }

            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice line has required field(s) that Group Umrah cannot safely infer: '
                    .implode(', ', array_slice($unresolved, 0, 10))
                    .'. Native line table: '.$table.'.'
                    .$mappingHelp
                    .' Configure the revenue mapping on the existing Product Service/accounting master; passenger data is not involved.',
            ]);
        }

        return $payload;
    }

    private function resolveInvoiceLineRelation(
        Model $invoice
    ): ?array {
        $scores = [];

        try {
            $reflection = new ReflectionClass(
                $invoice
            );

            foreach (
                $reflection->getMethods(
                    \ReflectionMethod::IS_PUBLIC
                )
                as $method
            ) {
                if (
                    $method->getNumberOfRequiredParameters() > 0
                ) {
                    continue;
                }

                $name = $method->getName();
                $lower = strtolower($name);
                $score = 0;

                if ($lower === 'items') $score += 3000;
                if ($lower === 'lines') $score += 2800;
                if ($lower === 'details') $score += 2200;
                if ($lower === 'invoiceitems') $score += 3200;
                if ($lower === 'invoice_items') $score += 3200;
                if ($lower === 'invoicelines') $score += 3000;
                if (str_contains($lower, 'item')) $score += 1000;
                if (str_contains($lower, 'line')) $score += 900;
                if (str_contains($lower, 'detail')) $score += 600;

                if ($score <= 0) {
                    continue;
                }

                $scores[$name] = $score;
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
                    'score' => $score,
                    'relation' => $relation,
                ];
            } catch (\Throwable) {
            }
        }

        return $this->resolveInvoiceLineRelationByForeignKey(
            $invoice
        );
    }

    private function resolveInvoiceLineRelationByForeignKey(
        Model $invoice
    ): ?array {
        /*
         * If the model does not expose a conventional relation, dynamically
         * define a lightweight HasMany to the best native child model/table.
         * Prefer existing App\Models classes inferred from table names.
         */
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

                foreach (
                    Schema::getForeignKeys($table)
                    as $foreignKey
                ) {
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

                    $model = $this->modelForTable(
                        $table
                    );

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
                        'score' => 100,
                        'relation' => $relation,
                    ];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function modelForTable(
        string $table
    ): ?Model {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $studly = Str::studly(
            Str::singular($table)
        );

        $candidates = [
            'App\\Models\\'.$studly,
            'App\\Models\\Sales\\'.$studly,
            'App\\Models\\Travel\\'.$studly,
            'App\\Models\\Operations\\'.$studly,
            'App\\Models\\Master\\'.$studly,
            'App\\Models\\Masters\\'.$studly,
            'App\\Models\\MasterData\\'.$studly,
            'App\\Models\\Accounting\\'.$studly,
        ];

        foreach (
            array_values(array_unique($candidates))
            as $class
        ) {
            $model = $this->modelInstanceForClass(
                $class
            );

            if (
                $model
                && $model->getTable() === $table
            ) {
                return $model;
            }
        }

        /*
         * Robust final resolver: Composer's authoritative class map. This is
         * NOT a recursive application scan. It only evaluates classes already
         * known to the installed autoloader, restricted to App\Models.
         */
        $classMapPath = base_path(
            'vendor/composer/autoload_classmap.php'
        );

        if (is_readable($classMapPath)) {
            try {
                $classMap = require $classMapPath;

                if (is_array($classMap)) {
                    $matches = [];

                    foreach ($classMap as $class => $file) {
                        if (
                            ! is_string($class)
                            || ! str_starts_with(
                                $class,
                                'App\\Models\\'
                            )
                        ) {
                            continue;
                        }

                        $model = $this->modelInstanceForClass(
                            $class
                        );

                        if (
                            ! $model
                            || $model->getTable() !== $table
                        ) {
                            continue;
                        }

                        $score = 0;
                        $lower = strtolower($class);

                        if (
                            str_contains($lower, 'productservice')
                        ) {
                            $score += 2000;
                        }

                        if (
                            str_contains($lower, 'bookingservice')
                        ) {
                            $score += 1500;
                        }

                        if (
                            str_contains($lower, 'salesinvoice')
                        ) {
                            $score += 1500;
                        }

                        if (
                            strtolower(class_basename($class))
                            === strtolower($studly)
                        ) {
                            $score += 1000;
                        }

                        $matches[] = [
                            'score' => $score,
                            'class' => $class,
                            'model' => $model,
                        ];
                    }

                    if ($matches) {
                        usort(
                            $matches,
                            fn (array $a, array $b): int =>
                                $b['score'] <=> $a['score']
                                ?: strcmp(
                                    $a['class'],
                                    $b['class']
                                )
                        );

                        return $matches[0]['model'];
                    }
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function modelInstanceForClass(
        string $class
    ): ?Model {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($class);

            if (
                $reflection->isAbstract()
                || ! $reflection->isSubclassOf(Model::class)
            ) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        try {
            $model = app($class);

            if ($model instanceof Model) {
                return $model;
            }
        } catch (\Throwable) {
        }

        try {
            $model = new $class();

            return $model instanceof Model
                ? $model
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function invoiceHasCommercialLine(
        Model $invoice
    ): bool {
        $relation = $this->resolveInvoiceLineRelation(
            $invoice
        );

        if (! $relation) {
            return false;
        }

        try {
            return (clone $relation['relation']->getQuery())
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function findExistingPackageInvoiceLine(
        Relation $relation,
        array $columns,
        int $bookingId,
        ?array $productService,
        ?array $bookingService,
        string $description
    ): ?Model {
        try {
            $query = clone $relation->getQuery();

            if (
                $bookingService
                && in_array(
                    'booking_service_id',
                    $columns,
                    true
                )
            ) {
                $row = (clone $query)
                    ->where(
                        'booking_service_id',
                        (int) $bookingService['id']
                    )
                    ->first();

                if ($row instanceof Model) {
                    return $row;
                }
            }

            if (
                $productService
                && in_array(
                    'product_service_id',
                    $columns,
                    true
                )
            ) {
                $row = (clone $query)
                    ->where(
                        'product_service_id',
                        (int) $productService['id']
                    )
                    ->first();

                if ($row instanceof Model) {
                    return $row;
                }
            }

            if (
                in_array(
                    'booking_id',
                    $columns,
                    true
                )
            ) {
                $row = (clone $query)
                    ->where(
                        'booking_id',
                        $bookingId
                    )
                    ->first();

                if ($row instanceof Model) {
                    return $row;
                }
            }

            foreach ([
                'description',
                'details',
                'name',
                'service_name',
            ] as $field) {
                if (! in_array($field, $columns, true)) {
                    continue;
                }

                $row = (clone $query)
                    ->where(
                        $field,
                        $description
                    )
                    ->first();

                if ($row instanceof Model) {
                    return $row;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function synchronizeInvoiceHeaderTotals(
        Model $invoice,
        float $amount,
        ?int $userId
    ): void {
        $columns = Schema::getColumnListing(
            $invoice->getTable()
        );

        $updates = [];

        $this->putAll($updates, $columns, [
            'subtotal',
            'net_total',
            'grand_total',
            'invoice_total',
            'total_amount',
            'total',
            'amount',
            'balance_due',
            'outstanding_amount',
        ], $amount);

        $this->putAll($updates, $columns, [
            'discount_amount',
            'tax_amount',
            'paid_amount',
            'received_amount',
        ], 0);

        $this->putAll($updates, $columns, [
            'status',
            'invoice_status',
            'document_status',
        ], 'draft');

        $this->putAll($updates, $columns, [
            'payment_status',
        ], 'unpaid');

        $this->putAll($updates, $columns, [
            'updated_by',
            'updated_by_id',
        ], $userId);

        try {
            $invoice->forceFill($updates);
            $invoice->saveQuietly();
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice totals/status could not be finalized. '
                    .$this->safeDatabaseFailure(
                        $e,
                        $invoice->getTable()
                    ),
            ]);
        }
    }

    private function resolveGroupUmrahProductService(
        int $bookingId,
        array $commercial
    ): ?array {
        $packageName = trim((string) (
            $commercial['package_name']
            ?? $commercial['package_code']
            ?? ''
        ));

        /*
         * ERP-10.31.72 restores the stronger resolver originally proven during
         * ERP-10.31.25: product_service_id is resolved through the ACTUAL
         * foreign-key target, not by assuming the master table is named
         * product_services.
         */
        $masterTables = $this->productServiceMasterTables();

        /*
         * Strongest source: the existing native Group Umrah booking_services
         * row. Production already proved this row carries product_service_id
         * and passenger_link_mode_snapshot=MULTIPLE.
         */
        if (Schema::hasTable('booking_services')) {
            try {
                $columns = Schema::getColumnListing(
                    'booking_services'
                );

                if (
                    in_array('booking_id', $columns, true)
                    && in_array('product_service_id', $columns, true)
                ) {
                    $rows = DB::table('booking_services')
                        ->where('booking_id', $bookingId)
                        ->whereNotNull('product_service_id')
                        ->limit(100)
                        ->get();

                    $rankedServices = [];

                    foreach ($rows as $rowObject) {
                        $row = (array) $rowObject;
                        $productServiceId = (int) (
                            $row['product_service_id']
                            ?? 0
                        );

                        if ($productServiceId <= 0) {
                            continue;
                        }

                        $score = 0;

                        foreach ([
                            'service_name',
                            'name',
                            'title',
                            'description',
                            'details',
                        ] as $field) {
                            $value = strtolower(trim((string) (
                                $row[$field]
                                ?? ''
                            )));

                            if ($value === '') {
                                continue;
                            }

                            if (
                                str_contains($value, 'group')
                                && str_contains($value, 'umrah')
                            ) {
                                $score += 5000;
                            } elseif (
                                str_contains($value, 'umrah')
                                && str_contains($value, 'package')
                            ) {
                                $score += 4500;
                            } elseif (
                                str_contains($value, 'umrah')
                            ) {
                                $score += 2500;
                            }
                        }

                        if (
                            array_key_exists(
                                'passenger_link_mode_snapshot',
                                $row
                            )
                            && trim((string) (
                                $row['passenger_link_mode_snapshot']
                                ?? ''
                            )) !== ''
                        ) {
                            /*
                             * This is a strong signal that the row was built
                             * from a real Product Service master.
                             */
                            $score += 250;
                        }

                        $rankedServices[] = [
                            'score' => $score,
                            'product_service_id' =>
                                $productServiceId,
                            'row' => $row,
                        ];
                    }

                    usort(
                        $rankedServices,
                        fn (array $a, array $b): int =>
                            $b['score'] <=> $a['score']
                            ?: $a['product_service_id']
                                <=> $b['product_service_id']
                    );

                    $chosen = null;

                    if ($rankedServices) {
                        $topScore = (int) $rankedServices[0]['score'];
                        $top = array_values(array_filter(
                            $rankedServices,
                            fn (array $candidate): bool =>
                                (int) $candidate['score']
                                    === $topScore
                        ));

                        /*
                         * If one row is clearly the Umrah service, use it.
                         * Otherwise if the booking only references one distinct
                         * product_service_id, that native identity is still
                         * unambiguous.
                         */
                        if (
                            count($top) === 1
                            && $topScore >= 2000
                        ) {
                            $chosen = $top[0];
                        } else {
                            $ids = array_values(array_unique(
                                array_map(
                                    fn (array $candidate): int =>
                                        (int) $candidate[
                                            'product_service_id'
                                        ],
                                    $rankedServices
                                )
                            ));

                            if (count($ids) === 1) {
                                $chosen = $rankedServices[0];
                            }
                        }
                    }

                    if ($chosen) {
                        $id = (int) $chosen[
                            'product_service_id'
                        ];

                        foreach ($masterTables as $table) {
                            $master = $this->productServiceRowById(
                                $table,
                                $id
                            );

                            if ($master) {
                                return [
                                    'id' => $id,
                                    'table' => $table,
                                    'row' => $master,
                                    'source' =>
                                        'booking_services.product_service_id',
                                    'booking_service_row' =>
                                        $chosen['row'],
                                ];
                            }
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        /*
         * Second source: scan each actual FK target/master table and select one
         * strong, unambiguous Umrah/Product Service row.
         */
        foreach ($masterTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);
                $idColumn = $this->first(
                    $columns,
                    ['id', 'product_service_id']
                );

                if (! $idColumn) {
                    continue;
                }

                $rows = DB::table($table)
                    ->limit(1500)
                    ->get();

                $ranked = [];

                foreach ($rows as $rowObject) {
                    $row = (array) $rowObject;
                    $id = (int) (
                        $row[$idColumn]
                        ?? 0
                    );

                    if ($id <= 0) {
                        continue;
                    }

                    $score = $this->productServiceScore(
                        $row,
                        $packageName
                    );

                    if ($score <= 0) {
                        continue;
                    }

                    $ranked[] = [
                        'id' => $id,
                        'score' => $score,
                        'table' => $table,
                        'row' => $row,
                        'source' =>
                            'master table semantic match',
                    ];
                }

                if (! $ranked) {
                    continue;
                }

                usort(
                    $ranked,
                    fn (array $a, array $b): int =>
                        $b['score'] <=> $a['score']
                        ?: $a['id'] <=> $b['id']
                );

                $topScore = (int) $ranked[0]['score'];
                $top = array_values(array_filter(
                    $ranked,
                    fn (array $candidate): bool =>
                        (int) $candidate['score']
                            === $topScore
                ));

                if (
                    count($top) === 1
                    && $topScore >= 1000
                ) {
                    return $top[0];
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function productServiceMasterTables(): array
    {
        $tables = [];

        foreach ([
            ['booking_services', 'product_service_id'],
            ['sales_invoice_lines', 'product_service_id'],
            ['sales_invoice_items', 'product_service_id'],
            ['sales_invoice_details', 'product_service_id'],
        ] as [$childTable, $column]) {
            $table = $this->foreignTableForColumn(
                $childTable,
                $column
            );

            if ($table !== null) {
                $tables[] = $table;
            }
        }

        /*
         * Conventional names are fallback candidates only when they actually
         * exist. They are no longer treated as authoritative.
         */
        foreach ([
            'product_services',
            'product_service_master',
            'product_service_masters',
            'travel_product_services',
            'service_products',
        ] as $table) {
            if (Schema::hasTable($table)) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    private function foreignTableForColumn(
        string $childTable,
        string $column
    ): ?string {
        if (! Schema::hasTable($childTable)) {
            return null;
        }

        try {
            foreach (
                Schema::getForeignKeys($childTable)
                as $foreignKey
            ) {
                if (! is_array($foreignKey)) {
                    continue;
                }

                $localColumns = (array) (
                    $foreignKey['columns']
                    ?? $foreignKey['local_columns']
                    ?? []
                );

                if (
                    ! in_array(
                        $column,
                        $localColumns,
                        true
                    )
                ) {
                    continue;
                }

                $foreignTable = (string) (
                    $foreignKey['foreign_table']
                    ?? $foreignKey['foreign_table_name']
                    ?? $foreignKey['table']
                    ?? ''
                );

                if (
                    $foreignTable !== ''
                    && Schema::hasTable($foreignTable)
                ) {
                    return $foreignTable;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function productServiceRowById(
        string $table,
        int $id
    ): ?array {
        if (
            $id <= 0
            || ! Schema::hasTable($table)
        ) {
            return null;
        }

        try {
            $columns = Schema::getColumnListing($table);
            $idColumn = $this->first(
                $columns,
                ['id', 'product_service_id']
            );

            if (! $idColumn) {
                return null;
            }

            $row = DB::table($table)
                ->where($idColumn, $id)
                ->first();

            return $row
                ? (array) $row
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function productServiceScore(
        array $row,
        string $packageName
    ): int {
        $score = 0;
        $packageName = strtolower(
            trim($packageName)
        );

        foreach ([
            'name',
            'title',
            'service_name',
            'product_name',
            'label',
            'code',
            'slug',
            'service_type',
            'product_type',
            'category',
            'description',
        ] as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }

            $value = strtolower(
                trim((string) $row[$field])
            );

            if ($value === '') {
                continue;
            }

            if (
                in_array(
                    $value,
                    [
                        'group umrah',
                        'group umrah package',
                        'umrah package',
                        'group_umrah',
                        'group_umrah_package',
                        'umrah_package',
                    ],
                    true
                )
            ) {
                $score += 5000;
            } elseif (
                str_contains($value, 'umrah')
                && str_contains($value, 'package')
            ) {
                $score += 3000;
            } elseif (
                str_contains($value, 'group')
                && str_contains($value, 'umrah')
            ) {
                $score += 2500;
            } elseif (
                $packageName !== ''
                && $value === $packageName
            ) {
                $score += 2000;
            } elseif (
                str_contains($value, 'umrah')
            ) {
                $score += 600;
            }
        }

        foreach ([
            'is_active',
            'active',
            'enabled',
        ] as $field) {
            if (
                array_key_exists($field, $row)
                && in_array(
                    strtolower(
                        (string) $row[$field]
                    ),
                    ['1', 'true', 'yes'],
                    true
                )
            ) {
                $score += 100;
            }
        }

        if (
            array_key_exists('status', $row)
            && in_array(
                strtolower(
                    trim((string) $row['status'])
                ),
                ['active', 'enabled', 'published'],
                true
            )
        ) {
            $score += 100;
        }

        return $score;
    }

    private function existingGroupUmrahBookingService(
        int $bookingId,
        ?array $productService
    ): ?array {
        if (! Schema::hasTable('booking_services')) {
            return null;
        }

        try {
            $columns = Schema::getColumnListing(
                'booking_services'
            );

            if (! in_array('booking_id', $columns, true)) {
                return null;
            }

            $query = DB::table(
                'booking_services'
            )
                ->where(
                    'booking_id',
                    $bookingId
                );

            if (
                $productService
                && in_array(
                    'product_service_id',
                    $columns,
                    true
                )
            ) {
                $row = (clone $query)
                    ->where(
                        'product_service_id',
                        (int) $productService['id']
                    )
                    ->first();

                if ($row) {
                    return [
                        'id' => (int) $row->id,
                        'table' => 'booking_services',
                        'row' => (array) $row,
                    ];
                }
            }

            foreach ([
                'service_name',
                'name',
                'description',
            ] as $field) {
                if (! in_array($field, $columns, true)) {
                    continue;
                }

                $row = (clone $query)
                    ->whereRaw(
                        'LOWER(COALESCE('.$field.",'')) LIKE ?",
                        ['%umrah%']
                    )
                    ->first();

                if ($row) {
                    return [
                        'id' => (int) $row->id,
                        'table' => 'booking_services',
                        'row' => (array) $row,
                    ];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function productServiceAccountMap(
        array $row
    ): array {
        $result = [];

        $families = [
            'revenue_account_id',
            'income_account_id',
            'sales_account_id',
            'service_revenue_account_id',
            'account_id',
            'ledger_account_id',
        ];

        foreach ($families as $field) {
            $value = (int) ($row[$field] ?? 0);

            if ($value > 0) {
                $result[$field] = $value;
            }
        }

        foreach ([
            'revenue_mapping_key',
            'accounting_mapping_key',
            'mapping_key',
            'revenue_key',
            'income_mapping_key',
            'sales_mapping_key',
        ] as $field) {
            $value = trim((string) (
                $row[$field]
                ?? ''
            ));

            if ($value !== '') {
                $result[$field] = $value;
            }
        }

        /*
         * When the invoice-line schema uses a generic account_id but the
         * Product Service stores a specific revenue/income account, mirror the
         * configured native account into account_id as well.
         */
        if (! isset($result['account_id'])) {
            foreach ([
                'revenue_account_id',
                'income_account_id',
                'sales_account_id',
                'service_revenue_account_id',
                'ledger_account_id',
            ] as $source) {
                if (! empty($result[$source])) {
                    $result['account_id'] =
                        $result[$source];
                    break;
                }
            }
        }

        return $result;
    }

    private function resolveRevenueMappingKey(
        ?array $productService,
        ?array $bookingService
    ): ?string {
        if (! $productService) {
            return null;
        }

        $productServiceId = (int) (
            $productService['id']
            ?? 0
        );

        $productRow = (array) (
            $productService['row']
            ?? []
        );

        /*
         * 1) Exact Product Service accounting configuration.
         */
        foreach ([
            'revenue_mapping_key',
            'accounting_mapping_key',
            'mapping_key',
            'revenue_key',
            'income_mapping_key',
            'sales_mapping_key',
        ] as $field) {
            $value = trim((string) (
                $productRow[$field]
                ?? ''
            ));

            if ($value !== '') {
                return $value;
            }
        }

        /*
         * 2) Existing booking_services snapshot/configuration for this exact
         * Group Umrah Product Service.
         */
        $bookingRow = (array) (
            $bookingService['row']
            ?? []
        );

        foreach ([
            'revenue_mapping_key',
            'revenue_mapping_key_snapshot',
            'accounting_mapping_key',
            'accounting_mapping_key_snapshot',
            'mapping_key',
            'mapping_key_snapshot',
        ] as $field) {
            $value = trim((string) (
                $bookingRow[$field]
                ?? ''
            ));

            if ($value !== '') {
                return $value;
            }
        }

        if ($productServiceId <= 0) {
            return null;
        }

        /*
         * 3) Ask the REAL native ProductService model for an existing mapping
         * through its own revenue/account/mapping relations or scalar accessors.
         */
        $nativeModelKey =
            $this->revenueMappingKeyFromNativeProductServiceModel(
                $productService
            );

        if ($nativeModelKey !== null) {
            return $nativeModelKey;
        }

        /*
         * 4) Dedicated native Product Service accounting/mapping tables.
         * Search only tables whose name clearly belongs to Product Service
         * accounting/revenue mapping.
         */
        $mappingKey = $this->revenueMappingKeyFromNativeMappingTables(
            $productServiceId
        );

        if ($mappingKey !== null) {
            return $mappingKey;
        }

        /*
         * 5) Native historical sales_invoice_lines for the exact Product
         * Service. Reuse only when all existing non-empty keys agree.
         */
        $historical = $this->revenueMappingKeyFromInvoiceHistory(
            $productServiceId
        );

        if ($historical !== null) {
            return $historical;
        }

        /*
         * 6) Guarded native auto-provision:
         * If ProductService exposes ONE clear mapping relation and the existing
         * Product Service already contains ONE unambiguous revenue account,
         * create the missing mapping through the native related model/relation.
         *
         * The mapping key itself is NOT invented. It must be generated by the
         * native model/database lifecycle and read back after save.
         */
        $provisioned =
            $this->ensureNativeRevenueMappingFromProductService(
                $productService
            );

        if ($provisioned !== null) {
            return $provisioned;
        }

        return null;
    }

    private function revenueMappingKeyFromNativeProductServiceModel(
        array $productService
    ): ?string {
        $model = $this->nativeProductServiceModel(
            (int) ($productService['id'] ?? 0),
            (string) ($productService['table'] ?? '')
        );

        if (! $model) {
            return null;
        }

        /*
         * First inspect safe scalar accessors/methods on the real native
         * ProductService model.
         */
        foreach ([
            'revenueMappingKey',
            'accountingMappingKey',
            'mappingKey',
            'revenueKey',
            'incomeMappingKey',
            'salesMappingKey',
        ] as $methodName) {
            if (! method_exists($model, $methodName)) {
                continue;
            }

            try {
                $method = new ReflectionMethod(
                    $model,
                    $methodName
                );

                if (
                    ! $method->isPublic()
                    || $method->getNumberOfRequiredParameters() > 0
                ) {
                    continue;
                }

                $value = $model->{$methodName}();

                if (
                    is_scalar($value)
                    && trim((string) $value) !== ''
                ) {
                    return trim((string) $value);
                }
            } catch (\Throwable) {
            }
        }

        $relations = $this->nativeRevenueMappingRelations(
            $model
        );

        $values = [];

        foreach ($relations as $relationInfo) {
            try {
                /** @var Relation $relation */
                $relation = $relationInfo['relation'];
                $related = $relation->getRelated();
                $columns = Schema::getColumnListing(
                    $related->getTable()
                );

                $keyField = $this->first(
                    $columns,
                    [
                        'revenue_mapping_key',
                        'accounting_mapping_key',
                        'mapping_key',
                        'revenue_key',
                        'key',
                        'code',
                    ]
                );

                if (! $keyField) {
                    continue;
                }

                foreach ($relation->get() as $row) {
                    if (! $row instanceof Model) {
                        continue;
                    }

                    $value = trim((string) (
                        $row->getAttribute($keyField)
                        ?? ''
                    ));

                    if ($value !== '') {
                        $values[] = $value;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $values = array_values(array_unique($values));

        return count($values) === 1
            ? $values[0]
            : null;
    }

    private function nativeProductServiceModel(
        int $productServiceId,
        ?string $masterTable = null
    ): ?Model {
        if ($productServiceId <= 0) {
            return null;
        }

        $tables = [];

        if (
            $masterTable
            && Schema::hasTable($masterTable)
        ) {
            $tables[] = $masterTable;
        }

        foreach ($this->productServiceMasterTables() as $table) {
            $tables[] = $table;
        }

        foreach (array_values(array_unique($tables)) as $table) {
            $prototype = $this->modelForTable($table);

            if (! $prototype) {
                continue;
            }

            try {
                $model = $prototype->newQuery()
                    ->whereKey($productServiceId)
                    ->first();

                if ($model instanceof Model) {
                    return $model;
                }
            } catch (\Throwable) {
            }
        }

        /*
         * Relation-driven fallback:
         * if booking_services has a native Eloquent model, inspect its
         * product/service relations and accept the one whose related table is
         * the real Product Service master.
         */
        $bookingServicePrototype =
            $this->modelForTable('booking_services');

        if ($bookingServicePrototype) {
            try {
                $columns = Schema::getColumnListing(
                    'booking_services'
                );

                if (
                    in_array(
                        'product_service_id',
                        $columns,
                        true
                    )
                ) {
                    $bookingService =
                        $bookingServicePrototype
                            ->newQuery()
                            ->where(
                                'product_service_id',
                                $productServiceId
                            )
                            ->first();

                    if ($bookingService instanceof Model) {
                        $reflection = new ReflectionClass(
                            $bookingService
                        );

                        foreach (
                            $reflection->getMethods(
                                \ReflectionMethod::IS_PUBLIC
                            )
                            as $method
                        ) {
                            if (
                                $method->isStatic()
                                || $method
                                    ->getNumberOfRequiredParameters()
                                    > 0
                            ) {
                                continue;
                            }

                            $name = $method->getName();
                            $lower = strtolower($name);

                            if (
                                ! str_contains($lower, 'product')
                                && ! str_contains($lower, 'service')
                            ) {
                                continue;
                            }

                            try {
                                $relation =
                                    $bookingService->{$name}();

                                if (! $relation instanceof Relation) {
                                    continue;
                                }

                                $related =
                                    $relation->getRelated();

                                if (
                                    $masterTable
                                    && $related->getTable()
                                        !== $masterTable
                                ) {
                                    continue;
                                }

                                $model = $related
                                    ->newQuery()
                                    ->whereKey(
                                        $productServiceId
                                    )
                                    ->first();

                                if ($model instanceof Model) {
                                    return $model;
                                }
                            } catch (\Throwable) {
                            }
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function nativeRevenueMappingRelations(
        Model $productService
    ): array {
        $candidates = [];

        try {
            $reflection = new ReflectionClass(
                $productService
            );

            foreach (
                $reflection->getMethods(
                    \ReflectionMethod::IS_PUBLIC
                )
                as $method
            ) {
                if (
                    $method->isStatic()
                    || $method->getNumberOfRequiredParameters() > 0
                ) {
                    continue;
                }

                $name = $method->getName();
                $lower = strtolower($name);
                $score = 0;

                if (str_contains($lower, 'revenue')) $score += 2500;
                if (str_contains($lower, 'income')) $score += 1800;
                if (str_contains($lower, 'sales')) $score += 1500;
                if (str_contains($lower, 'mapping')) $score += 1800;
                if (str_contains($lower, 'account')) $score += 900;

                if ($score <= 0) {
                    continue;
                }

                try {
                    $relation = $productService->{$name}();

                    if (! $relation instanceof Relation) {
                        continue;
                    }

                    $related = $relation->getRelated();

                    if (! Schema::hasTable($related->getTable())) {
                        continue;
                    }

                    $columns = Schema::getColumnListing(
                        $related->getTable()
                    );

                    $hasKey = (bool) $this->first(
                        $columns,
                        [
                            'revenue_mapping_key',
                            'accounting_mapping_key',
                            'mapping_key',
                            'revenue_key',
                            'key',
                            'code',
                        ]
                    );

                    $hasAccount = (bool) $this->first(
                        $columns,
                        [
                            'revenue_account_id',
                            'income_account_id',
                            'sales_account_id',
                            'account_id',
                            'ledger_account_id',
                        ]
                    );

                    if ($hasKey) $score += 2500;
                    if ($hasAccount) $score += 1500;

                    $candidates[] = [
                        'score' => $score,
                        'name' => $name,
                        'relation' => $relation,
                        'table' => $related->getTable(),
                    ];
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }

        usort(
            $candidates,
            fn (array $a, array $b): int =>
                $b['score'] <=> $a['score']
        );

        return $candidates;
    }

    private function ensureNativeRevenueMappingFromProductService(
        array $productService
    ): ?string {
        $model = $this->nativeProductServiceModel(
            (int) ($productService['id'] ?? 0),
            (string) ($productService['table'] ?? '')
        );

        if (! $model) {
            return null;
        }

        $accountId = $this->singleProductServiceRevenueAccountId(
            (array) ($productService['row'] ?? [])
        );

        if (! $accountId) {
            return null;
        }

        $relations = $this->nativeRevenueMappingRelations(
            $model
        );

        if (! $relations) {
            return null;
        }

        /*
         * Provision only when one relation is decisively best. Avoid writing
         * into an ambiguous accounting relationship.
         */
        $bestScore = (int) $relations[0]['score'];
        $best = array_values(array_filter(
            $relations,
            fn (array $relation): bool =>
                (int) $relation['score'] === $bestScore
        ));

        if (
            count($best) !== 1
            || $bestScore < 4000
        ) {
            return null;
        }

        /** @var Relation $relation */
        $relation = $best[0]['relation'];
        $related = $relation->getRelated();
        $table = $related->getTable();

        try {
            $columns = Schema::getColumnListing($table);
            $metadata = $this->columnMetadata($table);
        } catch (\Throwable) {
            return null;
        }

        $keyField = $this->first(
            $columns,
            [
                'revenue_mapping_key',
                'accounting_mapping_key',
                'mapping_key',
                'revenue_key',
                'key',
                'code',
            ]
        );

        $accountField = $this->first(
            $columns,
            [
                'revenue_account_id',
                'income_account_id',
                'sales_account_id',
                'account_id',
                'ledger_account_id',
            ]
        );

        if (! $keyField || ! $accountField) {
            return null;
        }

        /*
         * Re-check existing relation rows before creating anything.
         */
        try {
            $existingKeys = [];

            foreach ($relation->get() as $row) {
                if (! $row instanceof Model) {
                    continue;
                }

                $value = trim((string) (
                    $row->getAttribute($keyField)
                    ?? ''
                ));

                if ($value !== '') {
                    $existingKeys[] = $value;
                }
            }

            $existingKeys =
                array_values(array_unique($existingKeys));

            if (count($existingKeys) === 1) {
                return $existingKeys[0];
            }

            if (count($existingKeys) > 1) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $payload = [
            $accountField => $accountId,
        ];

        foreach ([
            'status' => 'active',
            'is_active' => 1,
            'active' => 1,
            'enabled' => 1,
        ] as $field => $value) {
            if (in_array($field, $columns, true)) {
                $payload[$field] = $value;
            }
        }

        foreach ([
            'name',
            'title',
            'label',
            'description',
        ] as $field) {
            if (
                in_array($field, $columns, true)
                && ! array_key_exists($field, $payload)
            ) {
                $payload[$field] =
                    'Group Umrah Package Revenue';
            }
        }

        /*
         * Do NOT assign $keyField. The native model/database must generate it.
         * If the key is itself a required manual field, save will fail and no
         * guessed mapping is created.
         */
        $unresolved = [];

        foreach ($metadata as $field => $meta) {
            if (
                array_key_exists($field, $payload)
                || $field === $keyField
                || $this->columnCanBeOmitted($field, $meta)
            ) {
                continue;
            }

            $lower = strtolower($field);

            /*
             * Product-service FK is supplied by HasMany/MorphMany save().
             */
            if (
                str_contains($lower, 'product_service')
                && str_ends_with($lower, '_id')
            ) {
                continue;
            }

            /*
             * Generic parent FK can also be relation-owned.
             */
            if (
                str_ends_with($lower, '_id')
                && (
                    str_contains($lower, 'service')
                    || str_contains($lower, 'product')
                )
            ) {
                continue;
            }

            $unresolved[] = $field;
        }

        /*
         * If there are other required business fields besides the generated
         * key/relation FK, do not auto-provision.
         */
        if ($unresolved) {
            return null;
        }

        try {
            $mapping = $related->newInstance();
            $mapping->forceFill($payload);

            if (method_exists($relation, 'save')) {
                $relation->save($mapping);
            } else {
                return null;
            }

            $mapping->refresh();

            $generated = trim((string) (
                $mapping->getAttribute($keyField)
                ?? ''
            ));

            if ($generated === '') {
                /*
                 * Roll back this unconfigured mapping row if native lifecycle
                 * did not generate its key.
                 */
                try {
                    $mapping->deleteQuietly();
                } catch (\Throwable) {
                }

                return null;
            }

            return $generated;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    private function singleProductServiceRevenueAccountId(
        array $row
    ): ?int {
        $specific = [];

        foreach ([
            'revenue_account_id',
            'income_account_id',
            'sales_account_id',
            'service_revenue_account_id',
        ] as $field) {
            $value = (int) ($row[$field] ?? 0);

            if ($value > 0) {
                $specific[] = $value;
            }
        }

        $specific =
            array_values(array_unique($specific));

        if (count($specific) === 1) {
            return $specific[0];
        }

        if (count($specific) > 1) {
            return null;
        }

        /*
         * Generic account is acceptable only when no specific revenue account
         * fields are populated.
         */
        foreach ([
            'account_id',
            'ledger_account_id',
        ] as $field) {
            $value = (int) ($row[$field] ?? 0);

            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }

    private function nativeRevenueMappingDiagnostic(
        ?array $productService,
        ?array $bookingService
    ): string {
        if (! $productService) {
            $parts = [
                'Product Service unresolved',
                'master tables='
                    .implode(
                        '|',
                        $this->productServiceMasterTables()
                    ),
            ];

            $source =
                $this->nativeRevenueMappingSourceDiagnostic();

            if ($source !== '') {
                $parts[] = $source;
            }

            return mb_substr(
                implode('; ', $parts),
                0,
                1700
            );
        }

        $id = (int) ($productService['id'] ?? 0);
        $row = (array) ($productService['row'] ?? []);
        $masterTable = (string) (
            $productService['table']
            ?? ''
        );

        $parts = [
            'ProductService#'.$id
                .($masterTable !== ''
                    ? '@'.$masterTable
                    : ''),
        ];

        if (! empty($productService['source'])) {
            $parts[] =
                'source='.(string) $productService['source'];
        }

        foreach ([
            'name',
            'title',
            'service_name',
            'code',
            'revenue_mapping_key',
            'accounting_mapping_key',
            'mapping_key',
            'passenger_link_mode',
        ] as $field) {
            $value = trim((string) (
                $row[$field]
                ?? ''
            ));

            if ($value !== '') {
                $parts[] = $field.'='.$value;
            }
        }

        $accounts = $this->productServiceAccountMap($row);
        $accountBits = [];

        foreach ($accounts as $field => $value) {
            if (
                str_ends_with($field, '_id')
                || $field === 'account_id'
            ) {
                $accountBits[] = $field.'='.$value;
            }
        }

        if ($accountBits) {
            $parts[] = 'accounts '.implode(',', $accountBits);
        } else {
            $parts[] = 'no revenue account field populated';
        }

        $model = $this->nativeProductServiceModel(
            $id,
            (string) ($productService['table'] ?? '')
        );

        if ($model) {
            $relations = $this->nativeRevenueMappingRelations(
                $model
            );

            if ($relations) {
                $relationBits = [];

                foreach (array_slice($relations, 0, 4) as $relation) {
                    $relationBits[] =
                        $relation['name'].'->'.$relation['table']
                        .' score='.$relation['score'];
                }

                $parts[] =
                    'native relations '.implode(',', $relationBits);
            } else {
                $parts[] = 'no native revenue/account mapping relation';
            }
        } else {
            $parts[] = 'native ProductService model unresolved';
        }

        $source = $this->nativeRevenueMappingSourceDiagnostic();

        if ($source !== '') {
            $parts[] = $source;
        }

        return mb_substr(
            implode('; ', $parts),
            0,
            1700
        );
    }

    private function nativeRevenueMappingSourceDiagnostic(): string
    {
        /*
         * Read only the real SalesInvoiceService updateDraft implementation and
         * ProductService model source. Do not recursively scan app/, so this
         * cannot self-match NativeSalesInvoiceDraftCreator.
         */
        $parts = [];

        $serviceClass = '\\App\\Services\\Sales\\SalesInvoiceService';

        if (
            class_exists($serviceClass)
            && method_exists($serviceClass, 'updateDraft')
        ) {
            try {
                $source = $this->methodSource(
                    new ReflectionMethod(
                        $serviceClass,
                        'updateDraft'
                    )
                );

                $snippet = $this->sourceSnippetAround(
                    $source,
                    'revenue_mapping_key'
                );

                if ($snippet !== '') {
                    $parts[] = 'SalesInvoiceService::updateDraft '.$snippet;
                }
            } catch (\Throwable) {
            }
        }

        foreach ($this->productServiceMasterTables() as $table) {
            $prototype = $this->modelForTable($table);

            if (! $prototype) {
                continue;
            }

            try {
                $reflection = new ReflectionClass(
                    $prototype
                );
                $file = $reflection->getFileName();

                if ($file && is_readable($file)) {
                    $source = (string) file_get_contents(
                        $file
                    );

                    $snippet = $this->sourceSnippetAround(
                        $source,
                        'revenue_mapping'
                    );

                    if ($snippet !== '') {
                        $parts[] =
                            $reflection->getShortName()
                            .'('.$table.') '
                            .$snippet;
                    }
                }
            } catch (\Throwable) {
            }

            break;
        }

        return mb_substr(
            implode(' | ', $parts),
            0,
            1000
        );
    }

    private function sourceSnippetAround(
        string $source,
        string $needle
    ): string {
        $position = stripos($source, $needle);

        if ($position === false) {
            return '';
        }

        $snippet = substr(
            $source,
            max(0, $position - 350),
            900
        );

        $snippet = preg_replace(
            '/\/\*.*?\*\//s',
            ' ',
            $snippet
        ) ?: $snippet;

        $snippet = preg_replace(
            '/\/\/[^\r\n]*/',
            ' ',
            $snippet
        ) ?: $snippet;

        $snippet = preg_replace(
            '/\s+/',
            ' ',
            $snippet
        ) ?: $snippet;

        return trim(
            mb_substr($snippet, 0, 800)
        );
    }

    private function revenueMappingKeyFromNativeMappingTables(
        int $productServiceId
    ): ?string {
        $tables = [];

        try {
            if (method_exists(Schema::getFacadeRoot(), 'getTables')) {
                foreach (Schema::getTables() as $tableMeta) {
                    if (! is_array($tableMeta)) {
                        continue;
                    }

                    $name = (string) (
                        $tableMeta['name']
                        ?? $tableMeta['table']
                        ?? ''
                    );

                    if ($name !== '') {
                        $tables[] = $name;
                    }
                }
            }
        } catch (\Throwable) {
        }

        /*
         * Conventional native mapping table names are added as safe fallback
         * candidates, but only queried if they actually exist and contain
         * product_service_id.
         */
        $tables = array_values(array_unique(array_merge(
            $tables,
            [
                'product_service_account_mappings',
                'product_service_accounting_mappings',
                'product_service_mappings',
                'product_service_accounts',
                'service_account_mappings',
                'service_revenue_mappings',
                'product_service_revenue_mappings',
            ]
        )));

        $values = [];

        foreach ($tables as $table) {
            $lowerTable = strtolower($table);

            if (
                ! str_contains($lowerTable, 'service')
                || (
                    ! str_contains($lowerTable, 'mapping')
                    && ! str_contains($lowerTable, 'account')
                    && ! str_contains($lowerTable, 'revenue')
                )
            ) {
                continue;
            }

            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);

                if (
                    ! in_array('product_service_id', $columns, true)
                ) {
                    continue;
                }

                $keyField = $this->first(
                    $columns,
                    [
                        'revenue_mapping_key',
                        'accounting_mapping_key',
                        'mapping_key',
                        'revenue_key',
                        'key',
                        'code',
                    ]
                );

                if (! $keyField) {
                    continue;
                }

                $query = DB::table($table)
                    ->where(
                        'product_service_id',
                        $productServiceId
                    );

                foreach ([
                    'deleted_at',
                    'cancelled_at',
                    'inactive_at',
                ] as $nullField) {
                    if (in_array($nullField, $columns, true)) {
                        $query->whereNull($nullField);
                    }
                }

                if (in_array('is_active', $columns, true)) {
                    $query->where('is_active', 1);
                } elseif (in_array('active', $columns, true)) {
                    $query->where('active', 1);
                }

                foreach (
                    $query
                        ->whereNotNull($keyField)
                        ->limit(20)
                        ->pluck($keyField)
                    as $value
                ) {
                    $value = trim((string) $value);

                    if ($value !== '') {
                        $values[] = $value;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $values = array_values(array_unique($values));

        return count($values) === 1
            ? $values[0]
            : null;
    }

    private function revenueMappingKeyFromInvoiceHistory(
        int $productServiceId
    ): ?string {
        if (! Schema::hasTable('sales_invoice_lines')) {
            return null;
        }

        try {
            $columns = Schema::getColumnListing(
                'sales_invoice_lines'
            );

            if (
                ! in_array('product_service_id', $columns, true)
                || ! in_array(
                    'revenue_mapping_key',
                    $columns,
                    true
                )
            ) {
                return null;
            }

            $values = DB::table(
                'sales_invoice_lines'
            )
                ->where(
                    'product_service_id',
                    $productServiceId
                )
                ->whereNotNull(
                    'revenue_mapping_key'
                )
                ->limit(100)
                ->pluck(
                    'revenue_mapping_key'
                )
                ->map(
                    fn ($value): string =>
                        trim((string) $value)
                )
                ->filter(
                    fn (string $value): bool =>
                        $value !== ''
                )
                ->unique()
                ->values();

            if ($values->count() === 1) {
                return (string) $values->first();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function copyProductServiceSnapshots(
        array $columns,
        array $payload,
        array $productService
    ): array {
        foreach ($columns as $field) {
            if (
                ! str_ends_with(
                    $field,
                    '_snapshot'
                )
                || array_key_exists(
                    $field,
                    $payload
                )
            ) {
                continue;
            }

            $source = substr(
                $field,
                0,
                -strlen('_snapshot')
            );

            if (
                array_key_exists(
                    $source,
                    $productService
                )
                && $productService[$source] !== null
                && $productService[$source] !== ''
            ) {
                $payload[$field] =
                    $productService[$source];
            }
        }

        return $payload;
    }

    private function linkedInvoice(
        int $bookingId,
        string $mode,
        ?int $amendmentId
    ): ?array {
        if (! Schema::hasTable(
            'booking_group_umrah_invoice_links'
        )) {
            throw ValidationException::withMessages([
                'invoice' => 'Run Safe Database Upgrade through ERP-10.31.15 before creating the Sales Invoice.',
            ]);
        }

        try {
            $query = DB::table(
                'booking_group_umrah_invoice_links'
            )
                ->where(
                    'booking_id',
                    $bookingId
                )
                ->where(
                    'link_type',
                    $mode === 'supplementary'
                        ? 'supplementary'
                        : 'base'
                );

            if ($mode === 'supplementary') {
                $query->where(
                    'amendment_id',
                    $amendmentId
                );
            }

            $link = $query
                ->orderByDesc('id')
                ->first();

            if (! $link) {
                return null;
            }

            $table = trim(
                (string) $link->invoice_table
            );

            $invoiceId = (int) $link->invoice_id;

            if (
                $table === ''
                || $invoiceId <= 0
                || ! Schema::hasTable($table)
            ) {
                return null;
            }

            $columns = Schema::getColumnListing(
                $table
            );
            $idColumn = $this->first(
                $columns,
                [
                    'id',
                    'sales_invoice_id',
                    'invoice_id',
                ]
            );

            if (! $idColumn) {
                return null;
            }

            $row = DB::table($table)
                ->where(
                    $idColumn,
                    $invoiceId
                )
                ->first();

            if (! $row) {
                return null;
            }

            return $this->mapInvoiceRow(
                $table,
                $columns,
                (array) $row
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function recoverExistingNativeInvoice(
        int $bookingId,
        array $booking,
        array $commercial,
        int $customerId,
        float $amount,
        string $mode,
        ?int $amendmentId
    ): ?array {
        $invoice = $this->newSalesInvoiceModel();
        $table = $invoice->getTable();
        $columns = Schema::getColumnListing(
            $table
        );

        $idColumn = $this->first(
            $columns,
            ['id', 'sales_invoice_id', 'invoice_id']
        );
        $customerColumn = $this->first(
            $columns,
            [
                'customer_id',
                'party_id',
                'client_id',
                'customer_party_id',
                'bill_to_party_id',
            ]
        );
        $amountColumn = $this->first(
            $columns,
            [
                'grand_total',
                'total_amount',
                'net_total',
                'invoice_total',
                'total',
                'amount',
            ]
        );

        if (
            ! $idColumn
            || ! $customerColumn
            || ! $amountColumn
        ) {
            return null;
        }

        try {
            $rows = DB::table($table)
                ->where(
                    $customerColumn,
                    $customerId
                )
                ->whereBetween(
                    $amountColumn,
                    [
                        $amount - 0.01,
                        $amount + 0.01,
                    ]
                )
                ->orderByDesc($idColumn)
                ->limit(30)
                ->get();

            $reference = strtolower(
                $this->bookingReference(
                    $bookingId,
                    $booking
                )
            );

            foreach ($rows as $rowObject) {
                $row = (array) $rowObject;

                foreach ([
                    'booking_id',
                    'travel_booking_id',
                    'source_booking_id',
                ] as $field) {
                    if (
                        in_array($field, $columns, true)
                        && (int) ($row[$field] ?? 0)
                            === $bookingId
                    ) {
                        return $this->mapInvoiceRow(
                            $table,
                            $columns,
                            $row
                        );
                    }
                }

                $haystack = '';

                foreach ([
                    'reference',
                    'reference_no',
                    'external_reference',
                    'invoice_reference',
                    'description',
                    'notes',
                    'narration',
                    'memo',
                ] as $field) {
                    if (in_array($field, $columns, true)) {
                        $haystack .= ' '.strtolower(
                            trim(
                                (string) (
                                    $row[$field]
                                    ?? ''
                                )
                            )
                        );
                    }
                }

                if (
                    $reference !== ''
                    && str_contains(
                        $haystack,
                        $reference
                    )
                ) {
                    return $this->mapInvoiceRow(
                        $table,
                        $columns,
                        $row
                    );
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function link(
        int $bookingId,
        string $mode,
        ?int $amendmentId,
        array $invoice,
        ?int $userId
    ): void {
        if (! Schema::hasTable(
            'booking_group_umrah_invoice_links'
        )) {
            throw ValidationException::withMessages([
                'invoice' => 'Run Safe Database Upgrade through ERP-10.31.15 before creating the Sales Invoice.',
            ]);
        }

        DB::table(
            'booking_group_umrah_invoice_links'
        )->updateOrInsert(
            [
                'booking_id' => $bookingId,
                'link_type' =>
                    $mode === 'supplementary'
                        ? 'supplementary'
                        : 'base',
                'invoice_id' =>
                    (int) $invoice['id'],
            ],
            [
                'amendment_id' =>
                    $amendmentId,
                'invoice_table' =>
                    (string) $invoice['table'],
                'invoice_number' =>
                    trim(
                        (string) (
                            $invoice['number']
                            ?? ''
                        )
                    ) ?: null,
                'created_by' => $userId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function mapNativeInvoiceModel(
        Model $invoice
    ): array {
        return $this->mapInvoiceRow(
            $invoice->getTable(),
            Schema::getColumnListing(
                $invoice->getTable()
            ),
            $invoice->getAttributes()
        );
    }

    private function mapInvoiceRow(
        string $table,
        array $columns,
        array $row
    ): array {
        $idColumn = $this->first(
            $columns,
            [
                'id',
                'sales_invoice_id',
                'invoice_id',
            ]
        );

        $numberColumn = $this->first(
            $columns,
            [
                'invoice_number',
                'invoice_no',
                'invoice_reference',
                'reference_no',
                'document_no',
                'reference',
                'number',
            ]
        );

        $statusColumn = $this->first(
            $columns,
            [
                'status',
                'invoice_status',
                'document_status',
            ]
        );

        $amountColumn = $this->first(
            $columns,
            [
                'grand_total',
                'total_amount',
                'net_total',
                'invoice_total',
                'total',
                'amount',
            ]
        );

        $customerColumn = $this->first(
            $columns,
            [
                'customer_id',
                'party_id',
                'client_id',
                'customer_party_id',
            ]
        );

        return [
            'table' => $table,
            'id' => $idColumn
                ? (int) ($row[$idColumn] ?? 0)
                : 0,
            'number' => $numberColumn
                ? trim(
                    (string) (
                        $row[$numberColumn]
                        ?? ''
                    )
                )
                : '',
            'status' => $statusColumn
                ? strtolower(
                    trim(
                        (string) (
                            $row[$statusColumn]
                            ?? 'draft'
                        )
                    )
                )
                : 'draft',
            'amount' => $amountColumn
                && array_key_exists(
                    $amountColumn,
                    $row
                )
                ? (float) (
                    $row[$amountColumn]
                    ?? 0
                )
                : null,
            'customer_id' => $customerColumn
                ? (int) (
                    $row[$customerColumn]
                    ?? 0
                )
                : null,
        ];
    }

    private function groupUmrahInvoiceNumber(
        int $bookingId,
        string $mode,
        ?int $amendmentId,
        string $date
    ): string {
        $year = substr(
            $date,
            0,
            4
        );

        if (! preg_match('/^[0-9]{4}$/', $year)) {
            $year = now()->format('Y');
        }

        $number = 'ET-SI-'.$year.'-'
            .str_pad(
                (string) $bookingId,
                6,
                '0',
                STR_PAD_LEFT
            );

        if (
            $mode === 'supplementary'
            && $amendmentId
        ) {
            $number .= '-A'
                .str_pad(
                    (string) $amendmentId,
                    2,
                    '0',
                    STR_PAD_LEFT
                );
        }

        return $number;
    }

    private function bookingReference(
        int $bookingId,
        array $booking
    ): string {
        return trim((string) (
            $booking['booking_reference']
            ?? $booking['reference']
            ?? $booking['booking_no']
            ?? ('BK-'.$bookingId)
        ));
    }

    private function commercialInvoiceAmount(
        array $commercial
    ): float {
        if (
            array_key_exists(
                'final_sale_total',
                $commercial
            )
        ) {
            return round(
                max(
                    0,
                    (float) $commercial['final_sale_total']
                ),
                2
            );
        }

        /*
         * Pre-ERP-10.31.72 fallback: old records stored one per-pax Final Sale.
         */
        return round(
            max(
                0,
                (float) ($commercial['final_sale_price'] ?? 0)
            )
            * max(
                1,
                (int) ($commercial['booked_pax'] ?? 1)
            ),
            2
        );
    }

    private function commercialInvoiceFareBands(
        array $commercial,
        string $mode = 'base',
        ?int $amendmentId = null
    ): array {
        if ($mode === 'supplementary') {
            $amendment = $this->groupUmrahAmendmentRow(
                (int) ($commercial['booking_id'] ?? 0),
                $amendmentId
            );

            if (! $amendment) return [];

            $hasFareMix =
                array_key_exists('additional_adult_pax', $amendment)
                || array_key_exists('additional_child_pax', $amendment)
                || array_key_exists('additional_infant_pax', $amendment);

            if ($hasFareMix) {
                return $this->amendmentFareBands($amendment, true);
            }

            $quantity = max(1,(int)($amendment['additional_pax'] ?? 1));
            $total = max(0,(float)($amendment['additional_final_sale'] ?? 0));
            if ($total <= 0) return [];

            return [[
                'fare_type'=>'ADDITIONAL',
                'label'=>'Additional Pax',
                'quantity'=>$quantity,
                'unit_price'=>round($total/$quantity,2),
                'gross_total'=>$total,
                'discount_amount'=>0,
                'net_total'=>$total,
                'supplier_unit_cost'=>0,
                'supplier_total'=>max(0,(float)($amendment['additional_supplier_cost'] ?? 0)),
            ]];
        }

        $bookingId = (int)($commercial['booking_id'] ?? 0);
        $revisionAmendments = $this->groupUmrahRevisionAmendments($bookingId);

        $addedAdult = array_sum(array_map(fn(array $r): int => max(0,(int)($r['additional_adult_pax'] ?? 0)), $revisionAmendments));
        $addedChild = array_sum(array_map(fn(array $r): int => max(0,(int)($r['additional_child_pax'] ?? 0)), $revisionAmendments));
        $addedInfant = array_sum(array_map(fn(array $r): int => max(0,(int)($r['additional_infant_pax'] ?? 0)), $revisionAmendments));

        /* Original package tranche only. Added pax are appended below at their own snapshotted rates. */
        $raw = [
            [
                'fare_type'=>'ADULT','label'=>'Adult',
                'quantity'=>max(0,(int)($commercial['booked_adult_pax'] ?? $commercial['booked_pax'] ?? 0)-$addedAdult),
                'unit_price'=>max(0,(float)($commercial['adult_sale_price'] ?? 0)),
                'supplier_unit_cost'=>max(0,(float)($commercial['adult_supplier_cost'] ?? 0)),
            ],
            [
                'fare_type'=>'CHILD','label'=>'Child',
                'quantity'=>max(0,(int)($commercial['booked_child_pax'] ?? 0)-$addedChild),
                'unit_price'=>max(0,(float)($commercial['child_sale_price'] ?? 0)),
                'supplier_unit_cost'=>max(0,(float)($commercial['child_supplier_cost'] ?? 0)),
            ],
            [
                'fare_type'=>'INFANT','label'=>'Infant',
                'quantity'=>max(0,(int)($commercial['booked_infant_pax'] ?? 0)-$addedInfant),
                'unit_price'=>max(0,(float)($commercial['infant_sale_price'] ?? 0)),
                'supplier_unit_cost'=>max(0,(float)($commercial['infant_supplier_cost'] ?? 0)),
            ],
        ];

        $bands = array_values(array_filter($raw, fn(array $b): bool => $b['quantity'] > 0));
        foreach ($bands as &$band) {
            $band['gross_total']=round($band['quantity']*$band['unit_price'],2);
            $band['supplier_total']=round($band['quantity']*$band['supplier_unit_cost'],2);
            $band['discount_amount']=0;
        }
        unset($band);

        $gross = round(array_sum(array_column($bands,'gross_total')),2);
        $discountType = strtolower(trim((string)($commercial['discount_type'] ?? 'none')));
        $discountValue = max(0,(float)($commercial['discount_value'] ?? 0));

        if ($discountType==='percent' && $discountValue>0) {
            foreach ($bands as &$band) {
                $band['discount_amount']=round($band['gross_total']*min($discountValue,100)/100,2);
            }
            unset($band);
        } elseif ($discountType==='fixed' && $discountValue>0 && $gross>0) {
            $remaining=min($discountValue,$gross); $last=array_key_last($bands);
            foreach ($bands as $i=>&$band) {
                $allocation=$i===$last ? $remaining : round(min($discountValue,$gross)*$band['gross_total']/$gross,2);
                $allocation=min($allocation,$remaining);
                $band['discount_amount']=$allocation;
                $remaining=round(max(0,$remaining-$allocation),2);
            }
            unset($band);
        }

        foreach ($bands as &$band) {
            $band['net_total']=round(max(0,$band['gross_total']-$band['discount_amount']),2);
        }
        unset($band);

        foreach ($revisionAmendments as $amendment) {
            foreach ($this->amendmentFareBands($amendment, false) as $amendmentBand) {
                $bands[]=$amendmentBand;
            }
        }

        return $bands;
    }

    private function groupUmrahRevisionAmendments(int $bookingId): array
    {
        if ($bookingId<=0 || !Schema::hasTable('booking_group_package_commercial_amendments')) return [];
        try {
            return DB::table('booking_group_package_commercial_amendments')
                ->where('booking_id',$bookingId)
                ->where('accounting_action','revise_existing_invoice')
                ->orderBy('amendment_no')
                ->get()->map(fn($row): array => (array)$row)->all();
        } catch (\Throwable) { return []; }
    }

    private function amendmentFareBands(array $amendment, bool $supplementary): array
    {
        $number=max(1,(int)($amendment['amendment_no'] ?? 1));
        $prefix=$supplementary ? 'Additional ' : '';
        $suffix=$supplementary ? '' : ' · Add Pax #'.$number;

        $raw=[
            ['fare_type'=>'ADULT','label'=>$prefix.'Adult'.$suffix,'quantity'=>max(0,(int)($amendment['additional_adult_pax'] ?? 0)),'unit_price'=>max(0,(float)($amendment['adult_sale_price_snapshot'] ?? 0)),'supplier_unit_cost'=>max(0,(float)($amendment['adult_supplier_cost_snapshot'] ?? 0))],
            ['fare_type'=>'CHILD','label'=>$prefix.'Child'.$suffix,'quantity'=>max(0,(int)($amendment['additional_child_pax'] ?? 0)),'unit_price'=>max(0,(float)($amendment['child_sale_price_snapshot'] ?? 0)),'supplier_unit_cost'=>max(0,(float)($amendment['child_supplier_cost_snapshot'] ?? 0))],
            ['fare_type'=>'INFANT','label'=>$prefix.'Infant'.$suffix,'quantity'=>max(0,(int)($amendment['additional_infant_pax'] ?? 0)),'unit_price'=>max(0,(float)($amendment['infant_sale_price_snapshot'] ?? 0)),'supplier_unit_cost'=>max(0,(float)($amendment['infant_supplier_cost_snapshot'] ?? 0))],
        ];
        $bands=array_values(array_filter($raw,fn(array $b): bool => $b['quantity']>0));
        if (!$bands) return [];

        foreach ($bands as &$band) {
            $band['gross_total']=round($band['quantity']*$band['unit_price'],2);
            $band['supplier_total']=round($band['quantity']*$band['supplier_unit_cost'],2);
            $band['discount_amount']=0;
        }
        unset($band);

        $gross=round(array_sum(array_column($bands,'gross_total')),2);
        $discount=max(0,(float)($amendment['discount_amount'] ?? 0));
        if ($discount>0 && $gross>0) {
            $remaining=min($discount,$gross); $last=array_key_last($bands);
            foreach ($bands as $i=>&$band) {
                $allocation=$i===$last ? $remaining : round($discount*$band['gross_total']/$gross,2);
                $allocation=min($allocation,$remaining);
                $band['discount_amount']=$allocation;
                $remaining=round(max(0,$remaining-$allocation),2);
            }
            unset($band);
        }
        foreach ($bands as &$band) {
            $band['net_total']=round(max(0,$band['gross_total']-$band['discount_amount']),2);
        }
        unset($band);
        return $bands;
    }

    private function prepareExistingDraftFareLines(
        Model $invoice,
        array $commercial,
        string $mode,
        ?int $amendmentId
    ): void {
        if ($mode === 'supplementary') {
            return;
        }

        $relationInfo = $this->resolveInvoiceLineRelation(
            $invoice
        );

        if (! $relationInfo) {
            return;
        }

        /** @var Relation $relation */
        $relation = $relationInfo['relation'];
        $lineModel = $relation->getRelated();
        $table = $lineModel->getTable();

        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            $columns = Schema::getColumnListing($table);
            $bands = $this->commercialInvoiceFareBands(
                $commercial,
                $mode,
                $amendmentId
            );

            if (! $bands) {
                return;
            }

            $groupLines = [];

            foreach ($relation->get() as $row) {
                if (
                    $row instanceof Model
                    && $this->isGroupUmrahInvoiceLine($row)
                ) {
                    $groupLines[] = $row;
                }
            }

            if (! $groupLines) {
                return;
            }

            $first = $groupLines[0];
            $firstBand = $bands[0];

            foreach ([
                'line_no',
                'line_number',
                'sequence_no',
                'sequence',
                'sort_order',
            ] as $field) {
                if (in_array($field, $columns, true)) {
                    $first->setAttribute($field, 1);
                }
            }

            foreach ([
                'fare_type',
                'fare_as',
                'passenger_type',
                'pax_type',
            ] as $field) {
                if (in_array($field, $columns, true)) {
                    $first->setAttribute(
                        $field,
                        $firstBand['fare_type']
                    );
                }
            }

            foreach (['quantity','qty'] as $field) {
                if (in_array($field, $columns, true)) {
                    $first->setAttribute(
                        $field,
                        $firstBand['quantity']
                    );
                }
            }

            foreach ([
                'unit_price',
                'rate',
                'price',
                'sale_price',
                'selling_price',
            ] as $field) {
                if (in_array($field, $columns, true)) {
                    $first->setAttribute(
                        $field,
                        $firstBand['unit_price']
                    );
                }
            }

            foreach ([
                'amount',
                'line_total',
                'net_amount',
                'total_amount',
                'total',
            ] as $field) {
                if (in_array($field, $columns, true)) {
                    $first->setAttribute(
                        $field,
                        $firstBand['net_total']
                    );
                }
            }

            $first->saveQuietly();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function mappedInvoiceIsDraft(
        array $invoice
    ): bool {
        return strtolower(trim((string) (
            $invoice['status']
            ?? ''
        ))) === 'draft';
    }

    private function synchronizeExistingDraftInvoice(
        Request $request,
        array $mappedInvoice,
        int $bookingId,
        string $mode,
        ?int $amendmentId,
        array $booking,
        array $commercial,
        array $identity,
        float $amount
    ): array {
        $invoiceId = (int) (
            $mappedInvoice['id']
            ?? 0
        );

        if ($invoiceId <= 0) {
            return $mappedInvoice;
        }

        $prototype = $this->newSalesInvoiceModel();

        try {
            $invoice = $prototype
                ->newQuery()
                ->whereKey($invoiceId)
                ->first();
        } catch (\Throwable) {
            return $mappedInvoice;
        }

        if (
            ! $invoice instanceof Model
            || strtolower(trim((string) (
                $invoice->getAttribute('status')
                ?? $invoice->getAttribute('invoice_status')
                ?? 'draft'
            ))) !== 'draft'
        ) {
            return $mappedInvoice;
        }

        $productService =
            $this->resolveGroupUmrahProductService(
                $bookingId,
                $commercial
            );

        $bookingService =
            $this->existingGroupUmrahBookingService(
                $bookingId,
                $productService
            );

        return DB::transaction(function () use (
            $request,
            $invoice,
            $bookingId,
            $mode,
            $amendmentId,
            $booking,
            $commercial,
            $identity,
            $amount,
            $productService,
            $bookingService
        ): array {
            $this->prepareExistingDraftFareLines(
                $invoice,
                $commercial,
                $mode,
                $amendmentId
            );

            try {
                $this->applyNativeUpdateDraft(
                    $request,
                    $invoice,
                    $bookingId,
                    $mode,
                    $amendmentId,
                    $booking,
                    $commercial,
                    $identity,
                    $amount,
                    $productService,
                    $bookingService
                );
            } catch (\Throwable $e) {
                report($e);
            }

            $invoice->refresh();

            $this->upsertNativePackageInvoiceLine(
                $request,
                $invoice,
                $bookingId,
                $mode,
                $amendmentId,
                $booking,
                $commercial,
                $identity,
                $amount,
                $productService,
                $bookingService
            );

            $this->synchronizeInvoiceHeaderTotals(
                $invoice,
                $amount,
                $request->user()?->id
            );

            $invoice->refresh();

            return $this->mapNativeInvoiceModel(
                $invoice
            );
        });
    }

    private function commercial(
        int $bookingId
    ): array {
        if (! Schema::hasTable(
            'booking_group_package_unified'
        )) {
            return [];
        }

        return (array) (
            DB::table(
                'booking_group_package_unified'
            )
                ->where(
                    'booking_id',
                    $bookingId
                )
                ->first()
            ?? []
        );
    }

    private function booking(
        int $bookingId
    ): array {
        if (! Schema::hasTable('bookings')) {
            return [];
        }

        return (array) (
            DB::table('bookings')
                ->where('id', $bookingId)
                ->first()
            ?? []
        );
    }

    private function groupUmrahAmendmentRow(
        int $bookingId,
        ?int $amendmentId
    ): ?array {
        if (
            ! $amendmentId
            || ! Schema::hasTable(
                'booking_group_package_commercial_amendments'
            )
        ) {
            return null;
        }

        try {
            $row = DB::table(
                'booking_group_package_commercial_amendments'
            )
                ->where('booking_id',$bookingId)
                ->where('id',$amendmentId)
                ->first();

            return $row ? (array)$row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function amendmentAmount(
        int $bookingId,
        ?int $amendmentId
    ): float {
        if (
            ! $amendmentId
            || ! Schema::hasTable(
                'booking_group_package_commercial_amendments'
            )
        ) {
            return 0;
        }

        return (float) (
            DB::table(
                'booking_group_package_commercial_amendments'
            )
                ->where(
                    'booking_id',
                    $bookingId
                )
                ->where(
                    'id',
                    $amendmentId
                )
                ->value(
                    'additional_final_sale'
                )
            ?? 0
        );
    }

    private function resolveNativeCompanyId(
        array $booking,
        int $branchId,
        Request $request
    ): ?int {
        /*
         * 1) Authoritative booking company.
         * AdaptiveBookingWriter already carries company_id on native bookings
         * when that column exists.
         */
        $bookingCompanyId = (int) (
            $booking['company_id']
            ?? 0
        );

        if ($bookingCompanyId > 0) {
            return $bookingCompanyId;
        }

        /*
         * 2) Resolve the company from the exact branch/office selected on the
         * booking. Prefer the FK-target table, then conventional native tables.
         */
        if ($branchId > 0) {
            $branchCompanyId = $this->companyIdFromBookingBranch(
                $booking,
                $branchId
            );

            if ($branchCompanyId) {
                return $branchCompanyId;
            }
        }

        /*
         * 3) Authenticated staff company context, when the native User model
         * exposes it.
         */
        $user = $request->user();

        if ($user) {
            try {
                $userCompanyId = (int) (
                    $user->getAttribute('company_id')
                    ?? 0
                );

                if ($userCompanyId > 0) {
                    return $userCompanyId;
                }
            } catch (\Throwable) {
            }

            try {
                if (isset($user->company_id)) {
                    $userCompanyId = (int) $user->company_id;

                    if ($userCompanyId > 0) {
                        return $userCompanyId;
                    }
                }
            } catch (\Throwable) {
            }
        }

        /*
         * 4) Existing native Sales Invoice history for this exact branch.
         * Use it only when all historical invoices for that branch agree on
         * one company. This is inference from native accounting itself, not a
         * hard-coded company ID.
         */
        if ($branchId > 0) {
            $historyCompanyId =
                $this->companyIdFromNativeInvoiceBranchHistory(
                    $branchId
                );

            if ($historyCompanyId) {
                return $historyCompanyId;
            }
        }

        /*
         * 5) Single-company installation fallback.
         * If the native company master contains exactly one usable company,
         * that is unambiguous. Never choose among multiple companies.
         */
        $singleCompanyId = $this->singleNativeCompanyId();

        if ($singleCompanyId) {
            return $singleCompanyId;
        }

        return null;
    }

    private function companyIdFromBookingBranch(
        array $booking,
        int $branchId
    ): ?int {
        $branchField = array_key_exists('branch_id', $booking)
            ? 'branch_id'
            : (
                array_key_exists('office_id', $booking)
                    ? 'office_id'
                    : null
            );

        $tables = [];

        if ($branchField && Schema::hasTable('bookings')) {
            try {
                foreach (Schema::getForeignKeys('bookings') as $foreignKey) {
                    if (! is_array($foreignKey)) {
                        continue;
                    }

                    $localColumns = (array) (
                        $foreignKey['columns']
                        ?? $foreignKey['local_columns']
                        ?? []
                    );

                    if (! in_array($branchField, $localColumns, true)) {
                        continue;
                    }

                    $foreignTable = (string) (
                        $foreignKey['foreign_table']
                        ?? $foreignKey['foreign_table_name']
                        ?? $foreignKey['table']
                        ?? ''
                    );

                    if ($foreignTable !== '') {
                        $tables[] = $foreignTable;
                    }
                }
            } catch (\Throwable) {
            }
        }

        foreach ([
            'branches',
            'branch_master',
            'branch_masters',
            'offices',
            'office_master',
            'office_masters',
        ] as $table) {
            $tables[] = $table;
        }

        foreach (array_values(array_unique($tables)) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);

                $idColumn = $this->first(
                    $columns,
                    ['id', 'branch_id', 'office_id']
                );

                if (
                    ! $idColumn
                    || ! in_array('company_id', $columns, true)
                ) {
                    continue;
                }

                $companyId = (int) (
                    DB::table($table)
                        ->where($idColumn, $branchId)
                        ->value('company_id')
                    ?? 0
                );

                if ($companyId > 0) {
                    return $companyId;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function companyIdFromNativeInvoiceBranchHistory(
        int $branchId
    ): ?int {
        try {
            $model = $this->newSalesInvoiceModel();
            $table = $model->getTable();
            $columns = Schema::getColumnListing($table);

            if (! in_array('company_id', $columns, true)) {
                return null;
            }

            $branchField = $this->first(
                $columns,
                ['branch_id', 'office_id']
            );

            if (! $branchField) {
                return null;
            }

            $companyIds = DB::table($table)
                ->where($branchField, $branchId)
                ->whereNotNull('company_id')
                ->distinct()
                ->limit(3)
                ->pluck('company_id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->unique()
                ->values();

            if ($companyIds->count() === 1) {
                return (int) $companyIds->first();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function singleNativeCompanyId(): ?int
    {
        foreach ([
            'companies',
            'company_master',
            'company_masters',
            'legal_entities',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);

                $idColumn = $this->first(
                    $columns,
                    ['id', 'company_id']
                );

                if (! $idColumn) {
                    continue;
                }

                $query = DB::table($table)
                    ->select($idColumn)
                    ->limit(3);

                if (in_array('deleted_at', $columns, true)) {
                    $query->whereNull('deleted_at');
                }

                if (in_array('is_active', $columns, true)) {
                    $query->where('is_active', 1);
                } elseif (in_array('active', $columns, true)) {
                    $query->where('active', 1);
                }

                $ids = $query
                    ->pluck($idColumn)
                    ->map(fn ($value): int => (int) $value)
                    ->filter(fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values();

                if ($ids->count() === 1) {
                    return (int) $ids->first();
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function currencyId(
        string $currency
    ): ?int {
        foreach ([
            'currencies',
            'currency_master',
            'currency_masters',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing(
                    $table
                );

                $id = $this->first(
                    $columns,
                    ['id', 'currency_id']
                );

                $code = $this->first(
                    $columns,
                    [
                        'code',
                        'currency_code',
                        'iso_code',
                        'short_code',
                    ]
                );

                if (! $id || ! $code) {
                    continue;
                }

                $value = DB::table($table)
                    ->whereRaw(
                        'UPPER('.$code.') = ?',
                        [
                            strtoupper(
                                trim($currency)
                            ),
                        ]
                    )
                    ->value($id);

                if ((int) $value > 0) {
                    return (int) $value;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function fiscalYearId(
        string $date
    ): ?int {
        foreach ([
            'fiscal_years',
            'financial_years',
            'fiscal_year',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing(
                    $table
                );

                $id = $this->first(
                    $columns,
                    [
                        'id',
                        'fiscal_year_id',
                        'financial_year_id',
                    ]
                );

                if (! $id) {
                    continue;
                }

                $query = DB::table($table);

                $start = $this->first(
                    $columns,
                    [
                        'start_date',
                        'date_from',
                        'from_date',
                    ]
                );

                $end = $this->first(
                    $columns,
                    [
                        'end_date',
                        'date_to',
                        'to_date',
                    ]
                );

                if ($start && $end) {
                    $value = (clone $query)
                        ->where(
                            $start,
                            '<=',
                            $date
                        )
                        ->where(
                            $end,
                            '>=',
                            $date
                        )
                        ->value($id);

                    if ((int) $value > 0) {
                        return (int) $value;
                    }
                }

                foreach ([
                    'is_current',
                    'current',
                    'active',
                    'is_active',
                ] as $field) {
                    if (
                        in_array(
                            $field,
                            $columns,
                            true
                        )
                    ) {
                        $value = (clone $query)
                            ->where(
                                $field,
                                1
                            )
                            ->value($id);

                        if ((int) $value > 0) {
                            return (int) $value;
                        }
                    }
                }

                $value = (clone $query)
                    ->orderByDesc($id)
                    ->value($id);

                if ((int) $value > 0) {
                    return (int) $value;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function columnMetadata(
        string $table
    ): array {
        $result = [];

        try {
            foreach (
                Schema::getColumns($table)
                as $column
            ) {
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
        } catch (\Throwable) {
        }

        return $result;
    }

    private function columnCanBeOmitted(
        string $field,
        array $meta
    ): bool {
        if (
            in_array(
                $field,
                [
                    'id',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ],
                true
            )
        ) {
            return true;
        }

        $nullable = (bool) (
            $meta['nullable']
            ?? $meta['is_nullable']
            ?? false
        );

        $default = array_key_exists(
            'default',
            $meta
        )
            && $meta['default'] !== null;

        $auto = (bool) (
            $meta['auto_increment']
            ?? $meta['autoincrement']
            ?? false
        );

        return $nullable || $default || $auto;
    }

    private function metaType(
        array $meta
    ): string {
        return strtolower(
            trim(
                (string) (
                    $meta['type_name']
                    ?? $meta['type']
                    ?? ''
                )
            )
        );
    }

    private function putAll(
        array &$payload,
        array $columns,
        array $fields,
        mixed $value
    ): void {
        if ($value === null || $value === '') {
            return;
        }

        foreach ($fields as $field) {
            if (in_array($field, $columns, true)) {
                $payload[$field] = $value;
            }
        }
    }

    private function first(
        array $columns,
        array $fields
    ): ?string {
        foreach ($fields as $field) {
            if (in_array($field, $columns, true)) {
                return $field;
            }
        }

        return null;
    }

    private function validationMessage(
        \Throwable $e
    ): string {
        if ($e instanceof ValidationException) {
            $messages = [];

            foreach ($e->errors() as $errors) {
                foreach ((array) $errors as $error) {
                    if (
                        is_string($error)
                        && trim($error) !== ''
                    ) {
                        $messages[] = trim($error);
                    }
                }
            }

            if ($messages) {
                return implode(
                    ' | ',
                    array_slice(
                        $messages,
                        0,
                        8
                    )
                );
            }
        }

        $message = trim(
            (string) $e->getMessage()
        );

        if (
            $message === ''
            || str_contains(
                strtoupper($message),
                'SQLSTATE'
            )
        ) {
            return '';
        }

        return mb_substr(
            preg_replace(
                '/\s+/',
                ' ',
                $message
            ) ?: $message,
            0,
            800
        );
    }

    private function nativeTableConstraintDiagnostic(
        string $table
    ): string {
        if (! Schema::hasTable($table)) {
            return '';
        }

        $parts = [];

        try {
            if (method_exists(Schema::getFacadeRoot(), 'getIndexes')) {
                foreach (Schema::getIndexes($table) as $index) {
                    if (! is_array($index)) {
                        continue;
                    }

                    if (! (bool) ($index['unique'] ?? false)) {
                        continue;
                    }

                    $name = (string) (
                        $index['name']
                        ?? 'unique'
                    );

                    $columns = (array) (
                        $index['columns']
                        ?? []
                    );

                    $parts[] = $name.'('
                        .implode(',', $columns)
                        .')';
                }
            }
        } catch (\Throwable) {
        }

        return $parts
            ? ' Native unique indexes: '.implode('; ', $parts).'.'
            : '';
    }

    private function safeDatabaseFailure(
        \Throwable $e,
        string $table
    ): string {
        $message = trim(
            (string) $e->getMessage()
        );

        if (
            preg_match(
                '/NOT NULL constraint failed:\s*([A-Za-z0-9_\.]+)/i',
                $message,
                $match
            )
        ) {
            return 'Required native field is missing: '
                .$match[1].'. Native table: '.$table.'.';
        }

        if (
            preg_match(
                '/Field [\'"`]?([A-Za-z0-9_]+)[\'"`]? doesn\'t have a default value/i',
                $message,
                $match
            )
        ) {
            return 'Required native field is missing: '
                .$match[1].'. Native table: '.$table.'.';
        }

        if (
            preg_match(
                '/Column [\'"`]?([A-Za-z0-9_]+)[\'"`]? cannot be null/i',
                $message,
                $match
            )
        ) {
            return 'Required native field is missing: '
                .$match[1].'. Native table: '.$table.'.';
        }

        if (
            str_contains(
                strtoupper($message),
                'SQLSTATE'
            )
        ) {
            $specific = '';

            if (
                preg_match(
                    '/(?:Duplicate entry|UNIQUE constraint failed:|for key)[^\\r\\n]*/i',
                    $message,
                    $match
                )
            ) {
                $specific = ' '.trim(
                    mb_substr($match[0], 0, 420)
                );
            }

            return 'The native table '.$table
                .' rejected one or more required/unique fields.'
                .$specific
                .$this->nativeTableConstraintDiagnostic($table);
        }

        return $this->validationMessage($e)
            ?: 'The native table '.$table
                .' rejected the Draft data.';
    }
}
