<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\AdaptiveBookingWriter;
use App\Services\Operations\AdaptivePassengerMasterWriter;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use App\Services\Operations\UnifiedGroupPackageLegacyBridge;
use App\Services\Operations\GroupUmrahWorkflowService;
use App\Services\Operations\ExistingErpActionResolver;
use App\Services\Operations\GroupUmrahEditAuthority;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Operations\GroupUmrahCommercialAmendmentService;
use App\Services\Operations\NativeBookingCustomerResolver;
use App\Services\Operations\BookingProfitabilityAuthority;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ERP-10.31.72 Group Umrah commercial-first focused workspace controller.
 *
 * One page / one transaction:
 * Booking + package commercial + passenger snapshots + flights + hotels +
 * transport + other services are persisted together only when staff presses
 * Save Draft or Save All Booking.
 */
class UnifiedGroupPackageBookingController extends Controller
{
    public function __construct(
        private readonly UnifiedGroupPackageDataSource $source,
        private readonly AdaptiveBookingWriter $bookingWriter,
        private readonly AdaptivePassengerMasterWriter $passengerWriter,
        private readonly UnifiedGroupPackageLegacyBridge $legacyBridge,
        private readonly NativeErpLayoutResolver $layoutResolver,
        private readonly GroupUmrahWorkflowService $workflow,
        private readonly ExistingErpActionResolver $actionResolver,
        private readonly GroupUmrahEditAuthority $editAuthority,
        private readonly NativeSalesInvoiceInspector $salesInvoices,
        private readonly GroupUmrahCommercialAmendmentService $amendments,
        private readonly NativeBookingCustomerResolver $customerResolver,
    ) {}

    public function create(Request $request): View
    {
        return $this->page(null, [
            'customer_id' => $request->integer('customer_id') ?: null,
            'branch_id' => $request->integer('branch_id') ?: null,
            'currency_code' => $request->query('currency_code'),
            'vendor_id' => $request->integer('vendor_id') ?: null,
        ]);
    }

    public function edit(int $booking): View
    {
        abort_unless(Schema::hasTable('bookings') && DB::table('bookings')->where('id', $booking)->exists(), 404);

        return $this->page($booking);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $stage = 'booking header';

        try {
            $data = $this->validated($request);
            $warnings = [];

            $result = DB::transaction(function () use ($data, &$warnings, &$stage): array {
                $commercial = $this->commercial($data);

                $stage = 'booking header';
                $bookingId = $this->bookingWriter->create([...$data, ...$commercial]);

                $stage = 'booking customer authority';
                $this->assertPersistedCustomer($bookingId, (int) $data['customer_id']);

                $stage = 'package reference';
                $packageCode = $this->packageCode($bookingId, $data['booking_date']);

                $stage = 'commercial package';
                $this->saveCommercialRecord(
                    $bookingId,
                    $data,
                    $commercial,
                    $packageCode
                );

                $stage = 'post-save workflow';
                $this->workflow->syncAfterSave(
                    $bookingId,
                    $data['save_mode'],
                    '',
                    'commercial'
                );

                return compact('bookingId', 'packageCode');
            });

            return $this->savedResponse(
                $request,
                $data,
                $result['bookingId'],
                $result['packageCode'],
                $warnings,
                false
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return $this->saveFailureResponse($request, $stage);
        }
    }

    public function update(Request $request, int $booking): RedirectResponse|JsonResponse
    {
        abort_unless(
            Schema::hasTable('bookings')
            && DB::table('bookings')->where('id', $booking)->exists(),
            404
        );

        $stage = 'edit-lock validation';

        try {
            $this->workflow->assertEditable($booking);

            $stage = 'form validation';
            $data = $this->validated($request);
            $data = $this->applyNativeCustomerIdentity($booking, $data);
            $scope = (string) ($data['save_scope'] ?? 'operational');
            $warnings = [];

            $packageCode = DB::transaction(function () use (
                $booking,
                $data,
                $scope,
                &$warnings,
                &$stage
            ): string {
                $packageCode = $this->existingPackageCode($booking)
                    ?: $this->packageCode($booking, $data['booking_date']);

                if ($scope === 'commercial') {
                    $this->assertCommercialBookedPaxCapacity($booking, $data);

                    $invoice = $this->salesInvoices->find($booking);
                    $invoiceExists = $invoice !== null;
                    $invoiceStatus = strtolower(trim((string) ($invoice['status'] ?? '')));
                    $commercialLocked = $invoiceExists
                        && $invoiceStatus !== 'draft';

                    /*
                     * Capacity has one post-invoice write path:
                     * + Add More Pax / Commercial Amendment.
                     * A Draft invoice does not reopen the base pax counters.
                     */
                    if ($invoiceExists) {
                        $this->assertBookedPaxMixUnchangedAfterInvoice(
                            $booking,
                            $data
                        );
                    }

                    if ($commercialLocked) {
                        $this->assertCommercialFieldsUnchangedAfterInvoice(
                            $booking,
                            $data
                        );
                    }

                    $commercial = $commercialLocked
                        ? $this->preservedCommercial($booking)
                        : $this->commercial($data);

                    $stage = 'booking commercial header';
                    $this->bookingWriter->update(
                        $booking,
                        [...$data, ...$commercial]
                    );

                    $stage = 'booking customer authority';
                    $this->assertPersistedCustomer($booking, (int) $data['customer_id']);

                    $stage = 'commercial package';
                    $this->saveCommercialRecord(
                        $booking,
                        $data,
                        $commercial,
                        $packageCode
                    );

                    $stage = 'post-commercial workflow';
                    $this->workflow->syncAfterSave(
                        $booking,
                        $data['save_mode'],
                        '',
                        'commercial'
                    );

                    return $packageCode;
                }

                $this->assertCommercialPackageSaved($booking);

                $stage = 'passenger capacity';
                $this->assertOperationalPassengerCapacity($booking, $data);

                $stage = 'passengers';
                $data['passengers'] = $this->resolvePassengers(
                    $data['passengers'] ?? [],
                    $booking,
                    $warnings
                );

                $stage = 'operational rows';
                $this->clearOperationalRows($booking);
                $this->saveOperationalRows($booking, $data);

                $stage = 'post-operational workflow';
                $this->workflow->syncAfterSave(
                    $booking,
                    $data['save_mode'],
                    $this->operationalHash($data),
                    'operational'
                );

                return $packageCode;
            });

            return $this->savedResponse(
                $request,
                $data,
                $booking,
                $packageCode,
                $warnings,
                true
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return $this->saveFailureResponse($request, $stage);
        }
    }

    private function saveFailureResponse(Request $request, string $stage): RedirectResponse|JsonResponse
    {
        $message='Group Umrah could not be saved while processing '.$stage.'. No partial booking details were committed.';
        if ($request->expectsJson()) return response()->json(['ok'=>false,'message'=>$message,'save_stage'=>$stage,'error_reference'=>now()->format('Ymd-His')],500);
        return back()->withInput()->with('error',$message);
    }

    private function savedResponse(
        Request $request,
        array $data,
        int $bookingId,
        string $packageCode,
        array $warnings,
        bool $updated,
    ): RedirectResponse|JsonResponse {
        $scope = (string) ($data['save_scope'] ?? 'operational');

        $message = match ($scope) {
            'commercial' => 'Group Umrah commercial booking saved. Accounting and travel operations may now continue in parallel.',
            'operational' => ($data['save_mode'] ?? 'complete') === 'draft'
                ? 'Group Umrah operational draft saved.'
                : 'Group Umrah operational changes saved.',
            default => 'Group Umrah booking saved.',
        };

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'booking_id' => $bookingId,
                'booking_reference' => $this->bookingReference($bookingId),
                'package_code' => $packageCode,
                'save_status' => $data['save_mode'],
                'message' => $message,
                'warnings' => array_values(array_unique(array_filter($warnings))),
                'edit_url' => route('operations.bookings.group-package-unified.edit', $bookingId),
                'update_url' => route('operations.bookings.group-package-unified.update', $bookingId),
                'workflow_url' => route('operations.bookings.group-umrah-workflow.transition', ['booking' => $bookingId, 'action' => '__ACTION__']),
                'workflow' => $this->workflowSnapshotForUser($bookingId, $request->user()),
                'actions' => $this->actionResolver->links($bookingId),
            ]);
        }

        return redirect()
            ->route('operations.bookings.group-package-unified.edit', $bookingId)
            ->with('success', $message);
    }

    private function page(?int $bookingId = null, array $seed = []): View
    {
        $this->assertUnifiedTables();

        $existing = [
            'booking' => [],
            'commercial' => [],
            'passengers' => [],
            'flights' => [],
            'hotels' => [],
            'transports' => [],
            'services' => [],
            'booking_reference' => null,
            'workflow' => null,
            'actions' => [],
            'amendments' => [],
            'customer_identity' => ['id' => null, 'name' => '', 'resolved' => false],
        ];

        if (! $bookingId && $seed) {
            $existing['booking'] = array_filter([
                'customer_id' => $seed['customer_id'] ?? null,
                'branch_id' => $seed['branch_id'] ?? null,
                'currency_code' => $seed['currency_code'] ?? null,
                'vendor_id' => $seed['vendor_id'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== '');
        }

        if ($bookingId) {
            $this->workflow->reconcileNativeBookingConfirmation($bookingId);

            $existing = [
                'booking' => (array) (DB::table('bookings')->where('id', $bookingId)->first() ?? []),
                'commercial' => (array) (DB::table('booking_group_package_unified')->where('booking_id', $bookingId)->first() ?? []),
                'passengers' => $this->rows('booking_group_package_passengers', $bookingId),
                'flights' => $this->rows('booking_group_package_flights', $bookingId),
                'hotels' => $this->rows('booking_group_package_hotels', $bookingId),
                'transports' => $this->rows('booking_group_package_transports', $bookingId),
                'services' => $this->rows('booking_group_package_services', $bookingId),
                'booking_reference' => $this->bookingReference($bookingId),
                'workflow' => $this->workflowSnapshotForUser($bookingId, request()->user()),
                'actions' => $this->actionResolver->links($bookingId),
                'amendments' => $this->amendments->state($bookingId),
                'customer_identity' => $this->customerResolver->resolve($bookingId),
            ];
        }

        $layout = $this->layoutResolver->resolve();

        return view('operations.bookings.group-package-unified-v103172', [
            'customers' => $this->source->customers(),
            'vendors' => $this->source->vendors(),
            'branches' => $this->source->branches(),
            'currencies' => $this->source->currencies(),
            'passengerOptions' => $this->source->passengers(),
            'hotelOptions' => $this->source->hotels(),
            'airlineOptions' => $this->source->airlines(),
            'transportRouteOptions' => $this->source->transportRoutes(),
            'transportVehicleOptions' => $this->source->transportVehicles(),
            'existing' => $existing,
            'bookingId' => $bookingId,
            'workflow' => $existing['workflow'] ?? null,
            'workflowActions' => $existing['actions'] ?? [],
            'canViewProfitability' => app(BookingProfitabilityAuthority::class)
                ->canView(request()->user()),
            'commercialAmendments' => data_get($existing, 'amendments.items', []),
            'commercialAmendmentState' => $existing['amendments'] ?? [],
            'commercialAmendmentUrl' => $bookingId ? route('operations.bookings.group-umrah-commercial-amendments.store', ['booking' => $bookingId]) : null,
            'workflowUrl' => $bookingId ? route('operations.bookings.group-umrah-workflow.transition', ['booking' => $bookingId, 'action' => '__ACTION__']) : null,
            'erpLayout' => $layout['layout'],
            'erpContentSection' => $layout['content_section'],
            'erpTitleSection' => $layout['title_section'],
        ]);
    }

    private function workflowSnapshotForUser(int $bookingId, mixed $user): array
    {
        $snapshot = $this->workflow->snapshot($bookingId);
        $snapshot['can_reopen'] = (bool) ($snapshot['editing_locked'] ?? false)
            && $this->editAuthority->canReopen($user);

        return $snapshot;
    }

    /**
     * Customer / Party is selected on the native Create Booking page before
     * staff enters the Group Umrah workspace. It is booking identity and must
     * not be re-selected here.
     *
     * Vendor / Supplier is deliberately NOT locked here. Group Umrah commercial
     * entry may select or change the supplier while the package is being built.
     */
    private function applyNativeCustomerIdentity(int $bookingId, array $data): array
    {
        $identity = $this->customerResolver->resolve($bookingId);
        $customerId = (int) ($identity['id'] ?? 0);

        if ($customerId > 0) {
            $data['customer_id'] = $customerId;
        }

        return $data;
    }

    private function assertPersistedCustomer(int $bookingId, int $selectedCustomerId): void
    {
        $identity = $this->customerResolver->resolve($bookingId);

        if (
            $selectedCustomerId <= 0
            || (int) ($identity['id'] ?? 0) !== $selectedCustomerId
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'customer_id' => 'The selected Customer / Party could not be persisted to the native booking authority.',
            ]);
        }
    }

    private function firstPositiveId(array $row, array $keys): int
    {
        foreach ($keys as $key) {
            $value = (int) ($row[$key] ?? 0);

            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    private function assertBookedPaxMixUnchangedAfterInvoice(
        int $bookingId,
        array $data
    ): void {
        $row = DB::table('booking_group_package_unified')
            ->where('booking_id', $bookingId)
            ->first();

        if (! $row) {
            return;
        }

        $before = [
            'Adult' => max(0, (int) ($row->booked_adult_pax ?? 0)),
            'Child' => max(0, (int) ($row->booked_child_pax ?? 0)),
            'Infant' => max(0, (int) ($row->booked_infant_pax ?? 0)),
        ];

        $after = [
            'Adult' => max(0, (int) ($data['booked_adult_pax'] ?? 0)),
            'Child' => max(0, (int) ($data['booked_child_pax'] ?? 0)),
            'Infant' => max(0, (int) ($data['booked_infant_pax'] ?? 0)),
        ];

        foreach ($before as $fare => $count) {
            if ($count !== $after[$fare]) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'booked_pax' => "{$fare} Booked Pax is locked after the first Sales Invoice is created. Use + Add More Pax in Accounting · Invoice First.",
                ]);
            }
        }
    }

    private function assertCommercialFieldsUnchangedAfterInvoice(
        int $bookingId,
        array $data
    ): void {
        $row = DB::table('booking_group_package_unified')
            ->where('booking_id', $bookingId)
            ->first();

        if (! $row) {
            return;
        }

        $checks = [
            [(float) ($row->adult_sale_price ?? 0), (float) ($data['adult_sale_price'] ?? 0), 'Adult Customer Price'],
            [(float) ($row->child_sale_price ?? 0), (float) ($data['child_sale_price'] ?? 0), 'Child Customer Price'],
            [(float) ($row->infant_sale_price ?? 0), (float) ($data['infant_sale_price'] ?? 0), 'Infant Customer Price'],

            [(float) ($row->adult_supplier_cost ?? 0), (float) ($data['adult_supplier_cost'] ?? 0), 'Adult Vendor Cost'],
            [(float) ($row->child_supplier_cost ?? 0), (float) ($data['child_supplier_cost'] ?? 0), 'Child Vendor Cost'],
            [(float) ($row->infant_supplier_cost ?? 0), (float) ($data['infant_supplier_cost'] ?? 0), 'Infant Vendor Cost'],

            [(float) ($row->agent_commission ?? 0), (float) ($data['agent_commission'] ?? 0), 'Agent Commission'],
            [(float) ($row->salesperson_commission ?? 0), (float) ($data['salesperson_commission'] ?? 0), 'Salesperson Commission'],
            [(float) ($row->discount_value ?? 0), (float) ($data['discount_value'] ?? 0), 'Discount Value'],
        ];

        foreach ($checks as [$before, $after, $label]) {
            if (abs((float) $before - (float) $after) > 0.009) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'commercial' => $label.' is accounting-controlled after the Sales Invoice leaves Draft status. Use + Add More Pax / Commercial Amendment instead.',
                ]);
            }
        }

        if (
            (string) ($row->discount_type ?? 'none')
            !== (string) ($data['discount_type'] ?? 'none')
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'commercial' => 'Discount Type is accounting-controlled after the Sales Invoice leaves Draft status. Use a Commercial Amendment.',
            ]);
        }

        $existingCustomer = (int) (
            $this->customerResolver->resolve($bookingId)['id']
            ?? 0
        );

        if (
            $existingCustomer > 0
            && $existingCustomer !== (int) ($data['customer_id'] ?? 0)
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'customer_id' => 'Customer cannot be changed after a Sales Invoice exists.',
            ]);
        }

        if (
            strtoupper((string) ($row->currency_code ?? 'PKR'))
            !== strtoupper((string) ($data['currency_code'] ?? 'PKR'))
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'currency_code' => 'Currency cannot be changed after a Sales Invoice exists.',
            ]);
        }
    }

    private function preservedCommercial(int $bookingId): array
    {
        $row=DB::table('booking_group_package_unified')->where('booking_id',$bookingId)->first();
        return [
            'package_sale_price' => (float) ($row->package_sale_price ?? 0),
            'supplier_cost' => (float) ($row->supplier_cost ?? 0),
            'discount_amount' => (float) ($row->discount_amount_total ?? 0),
            'final_sale_price' => (float) ($row->final_sale_price ?? 0),
            'net_margin' => (float) ($row->net_margin ?? 0),
            'gross_sale_total' => (float) ($row->gross_sale_total ?? $row->package_sale_price ?? 0),
            'supplier_cost_total' => (float) ($row->supplier_cost_total ?? $row->supplier_cost ?? 0),
            'discount_amount_total' => (float) ($row->discount_amount_total ?? 0),
            'final_sale_total' => (float) ($row->final_sale_total ?? $row->final_sale_price ?? 0),
            'net_margin_total' => (float) ($row->net_margin_total ?? $row->net_margin ?? 0),
        ];
    }

    private function validated(Request $request): array
    {
        $input = $request->all();
        $input['save_mode'] = in_array(($input['save_mode'] ?? ''), ['draft', 'complete'], true)
            ? $input['save_mode']
            : 'complete';
        $input['save_scope'] = in_array(($input['save_scope'] ?? ''), ['commercial', 'operational'], true)
            ? $input['save_scope']
            : 'operational';
        $input['booking_type'] = 'UMRAH';
        $commercialScope = $input['save_scope'] === 'commercial';

        foreach (['passengers', 'flights', 'hotels', 'transports', 'services'] as $group) {
            if ($commercialScope) {
                /*
                 * Save Commercial must be completely independent from unfinished
                 * operational rows currently visible in the browser.
                 */
                $input[$group] = [];
                continue;
            }

            $input[$group] = array_values(array_filter(
                is_array($input[$group] ?? null) ? $input[$group] : [],
                fn ($row): bool => is_array($row) && $this->meaningfulRow($group, $row)
            ));
        }

        $complete = $input['save_mode'] === 'complete';
        $completeCommercial = $commercialScope && $complete;

        if (
            array_key_exists('booked_adult_pax', $input)
            || array_key_exists('booked_child_pax', $input)
            || array_key_exists('booked_infant_pax', $input)
        ) {
            $input['booked_pax'] =
                max(0, (int) ($input['booked_adult_pax'] ?? 0))
                + max(0, (int) ($input['booked_child_pax'] ?? 0))
                + max(0, (int) ($input['booked_infant_pax'] ?? 0));
        }

        $rules = [
            'save_mode' => ['required', Rule::in(['draft', 'complete'])],
            'save_scope' => ['required', Rule::in(['commercial', 'operational'])],
            'booking_date' => ['required', 'date'],
            'booking_type' => ['required', 'string', 'max:80'],
            'customer_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'currency_code' => ['required', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:3000'],

            'package_name' => [$completeCommercial ? 'required' : 'nullable', 'string', 'max:180'],
            'booked_pax' => [$completeCommercial ? 'required' : 'nullable', 'integer', 'min:1', 'max:9999'],
            'booked_adult_pax' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'booked_child_pax' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'booked_infant_pax' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'vendor_id' => [$completeCommercial ? 'required' : 'nullable', 'integer', 'min:1'],
            'vendor_package_code' => ['nullable', 'string', 'max:120'],
            'vendor_voucher_no' => ['nullable', 'string', 'max:120'],

            // Customer selling prices are PER PAX by fare band.
            'adult_sale_price' => ['nullable', 'numeric', 'min:0'],
            'child_sale_price' => ['nullable', 'numeric', 'min:0'],
            'infant_sale_price' => ['nullable', 'numeric', 'min:0'],

            // Vendor / Supplier costs are PER PAX by fare band.
            'adult_supplier_cost' => ['nullable', 'numeric', 'min:0'],
            'child_supplier_cost' => ['nullable', 'numeric', 'min:0'],
            'infant_supplier_cost' => ['nullable', 'numeric', 'min:0'],

            // Legacy aggregate fields remain compatibility-only.
            'package_sale_price' => ['nullable', 'numeric', 'min:0'],
            'supplier_cost' => ['nullable', 'numeric', 'min:0'],

            'discount_type' => ['required', Rule::in(['none', 'fixed', 'percent'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'agent_commission' => ['nullable', 'numeric', 'min:0'],
            'salesperson_commission' => ['nullable', 'numeric', 'min:0'],

            'passengers' => ['nullable', 'array', 'min:0'],
            'passengers.*.passenger_id' => ['nullable', 'integer', 'min:1'],
            'passengers.*.passenger_source' => ['nullable', 'string', 'max:80'],
            'passengers.*.title' => ['nullable', 'string', 'max:20'],
            'passengers.*.first_name' => ['nullable', 'string', 'max:120'],
            'passengers.*.last_name' => ['nullable', 'string', 'max:120'],
            'passengers.*.date_of_birth' => ['nullable', 'date'],
            'passengers.*.passport_no' => ['nullable', 'string', 'max:80'],
            'passengers.*.passport_expiry' => ['nullable', 'date'],
            'passengers.*.nationality' => ['nullable', 'string', 'max:100'],
            'passengers.*.fare_as' => ['nullable', Rule::in(['ADULT', 'CHILD', 'INFANT', 'adult', 'child', 'infant'])],
            'passengers.*.ticket_number' => ['nullable', 'string', 'max:100'],

            'flights' => ['nullable', 'array', 'min:0'],
            'flights.*.segment_type' => ['required_with:flights', Rule::in(['outbound', 'inbound', 'connection', 'other'])],
            'flights.*.from_code' => ['nullable', 'string', 'max:50'],
            'flights.*.to_code' => ['nullable', 'string', 'max:50'],
            'flights.*.airline_name' => ['nullable', 'string', 'max:120'],
            'flights.*.flight_number' => ['nullable', 'string', 'max:40'],
            'flights.*.departure_date' => ['nullable', 'date'],
            'flights.*.departure_time' => ['nullable', 'date_format:H:i'],
            'flights.*.arrival_date' => ['nullable', 'date'],
            'flights.*.arrival_time' => ['nullable', 'date_format:H:i'],
            'flights.*.cabin_class' => ['nullable', 'string', 'max:50'],
            'flights.*.baggage' => ['nullable', 'string', 'max:80'],
            'flights.*.pnr' => ['nullable', 'string', 'max:80'],
            'flights.*.status' => ['nullable', Rule::in(['requested', 'booked', 'confirmed', 'issued'])],

            'hotels' => ['nullable', 'array'],
            'hotels.*.hotel_id' => ['nullable', 'integer', 'min:1'],
            'hotels.*.city' => ['nullable', 'string', 'max:120'],
            'hotels.*.hotel_name' => ['nullable', 'string', 'max:180'],
            'hotels.*.check_in' => ['nullable', 'date'],
            'hotels.*.check_out' => ['nullable', 'date'],
            'hotels.*.nights' => ['nullable', 'integer', 'min:0', 'max:999'],
            'hotels.*.room_type' => ['nullable', 'string', 'max:80'],
            'hotels.*.meal_plan' => ['nullable', 'string', 'max:80'],
            'hotels.*.rooms' => ['nullable', 'integer', 'min:1', 'max:999'],
            'hotels.*.confirmation_no' => ['nullable', 'string', 'max:120'],
            'hotels.*.status' => ['nullable', Rule::in(['requested', 'booked', 'confirmed'])],
            'hotels.*.notes' => ['nullable', 'string', 'max:2000'],

            'transports' => ['nullable', 'array'],
            'transports.*.route_name' => ['nullable', 'string', 'max:255'],
            'transports.*.route_master_id' => ['nullable', 'integer', 'min:0'],
            'transports.*.route_source_table' => ['nullable', 'string', 'max:120'],
            'transports.*.vehicle_master_id' => ['nullable', 'integer', 'min:0'],
            'transports.*.vehicle_source_table' => ['nullable', 'string', 'max:120'],
            'transports.*.from_location' => ['nullable', 'string', 'max:180'],
            'transports.*.to_location' => ['nullable', 'string', 'max:180'],
            'transports.*.vehicle_type' => ['nullable', 'string', 'max:100'],
            'transports.*.pickup_date' => ['nullable', 'date'],
            'transports.*.pickup_time' => ['nullable', 'date_format:H:i'],
            'transports.*.company_name' => ['nullable', 'string', 'max:180'],
            'transports.*.contact_number' => ['nullable', 'string', 'max:80'],
            'transports.*.brn_number' => ['nullable', 'string', 'max:120'],
            'transports.*.provider_reference' => ['nullable', 'string', 'max:120'],
            'transports.*.status' => ['nullable', Rule::in(['requested', 'booked', 'confirmed', 'completed'])],
            'transports.*.notes' => ['nullable', 'string', 'max:2000'],

            'services' => ['nullable', 'array'],
            'services.*.service_name' => ['nullable', 'string', 'max:180'],
            'services.*.details' => ['nullable', 'string', 'max:255'],
            'services.*.quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'services.*.status' => ['nullable', Rule::in(['included', 'requested', 'booked', 'confirmed'])],
            'services.*.notes' => ['nullable', 'string', 'max:2000'],
        ];

        $validator=Validator::make($input,$rules);
        $validator->after(function ($validator) use ($input, $commercialScope): void {
            foreach (($input['hotels'] ?? []) as $index=>$hotel) {
                if (empty($hotel['check_in']) || empty($hotel['check_out'])) continue;
                try { if (Carbon::parse($hotel['check_out'])->startOfDay()->lt(Carbon::parse($hotel['check_in'])->startOfDay())) $validator->errors()->add("hotels.{$index}.check_out",'Hotel check-out cannot be before check-in.'); } catch (\Throwable) {}
            }
            if ($commercialScope) {
                $adultPax = max(0, (int) ($input['booked_adult_pax'] ?? 0));
                $childPax = max(0, (int) ($input['booked_child_pax'] ?? 0));
                $infantPax = max(0, (int) ($input['booked_infant_pax'] ?? 0));
                $bookedPax = $adultPax + $childPax + $infantPax;
                $assignedNames = count($input['passengers'] ?? []);

                if ($bookedPax < 1) {
                    $validator->errors()->add(
                        'booked_pax',
                        'At least one Adult, Child or Infant must be booked.'
                    );
                }

                foreach ([
                    ['ADULT', $adultPax, (float) ($input['adult_sale_price'] ?? 0), 'adult_sale_price'],
                    ['CHILD', $childPax, (float) ($input['child_sale_price'] ?? 0), 'child_sale_price'],
                    ['INFANT', $infantPax, (float) ($input['infant_sale_price'] ?? 0), 'infant_sale_price'],
                ] as [$label, $count, $price, $field]) {
                    if ($count > 0 && $price <= 0) {
                        $validator->errors()->add(
                            $field,
                            "{$label} Customer Price / Pax must be greater than zero when {$count} {$label} pax are booked."
                        );
                    }
                }

                if ($bookedPax > 0 && $assignedNames > $bookedPax) {
                    $validator->errors()->add(
                        'passengers',
                        "Passenger names assigned ({$assignedNames}) cannot exceed booked package pax ({$bookedPax})."
                    );
                }
            }
        });
        $data=$validator->validate();
        $totalHotelNights=0;
        foreach (($data['hotels'] ?? []) as $index=>$hotel) {
            $nights=0;
            if (!empty($hotel['check_in']) && !empty($hotel['check_out'])) {
                try { $nights=max(0,(int)Carbon::parse($hotel['check_in'])->startOfDay()->diffInDays(Carbon::parse($hotel['check_out'])->startOfDay())); } catch (\Throwable) {}
            }
            $data['hotels'][$index]['nights']=$nights; $totalHotelNights += $nights;
        }
        $data['total_hotel_nights']=$totalHotelNights;
        foreach (($data['transports'] ?? []) as $index => $transport) {
            $from = trim((string) ($transport['from_location'] ?? ''));
            $to = trim((string) ($transport['to_location'] ?? ''));
            if (trim((string) ($transport['route_name'] ?? '')) === '' && ($from !== '' || $to !== '')) {
                $data['transports'][$index]['route_name'] = trim($from . (($from !== '' && $to !== '') ? ' → ' : '') . $to);
            }
            if (trim((string) ($transport['brn_number'] ?? '')) !== '') {
                $data['transports'][$index]['provider_reference'] = $transport['brn_number'];
            }
            $data['transports'][$index]['pickup_date'] = null;
            $data['transports'][$index]['pickup_time'] = null;
            $data['transports'][$index]['status'] = 'requested';
        }

        $data['package_name'] = trim((string) ($data['package_name'] ?? ''));

        $data['booked_adult_pax'] = max(0, (int) ($data['booked_adult_pax'] ?? 0));
        $data['booked_child_pax'] = max(0, (int) ($data['booked_child_pax'] ?? 0));
        $data['booked_infant_pax'] = max(0, (int) ($data['booked_infant_pax'] ?? 0));
        $data['booked_pax'] = max(
            1,
            $data['booked_adult_pax']
                + $data['booked_child_pax']
                + $data['booked_infant_pax']
        );

        $data['adult_sale_price'] = max(0, (float) ($data['adult_sale_price'] ?? 0));
        $data['child_sale_price'] = max(0, (float) ($data['child_sale_price'] ?? 0));
        $data['infant_sale_price'] = max(0, (float) ($data['infant_sale_price'] ?? 0));
        $data['adult_supplier_cost'] = max(0, (float) ($data['adult_supplier_cost'] ?? 0));
        $data['child_supplier_cost'] = max(0, (float) ($data['child_supplier_cost'] ?? 0));
        $data['infant_supplier_cost'] = max(0, (float) ($data['infant_supplier_cost'] ?? 0));

        $data['vendor_id'] = $data['vendor_id'] ?? null;
        $data['discount_value'] = (float) ($data['discount_value'] ?? 0);
        $data['agent_commission'] = (float) ($data['agent_commission'] ?? 0);
        $data['salesperson_commission'] = (float) ($data['salesperson_commission'] ?? 0);
        $data['passengers'] = $data['passengers'] ?? [];
        $data['flights'] = $data['flights'] ?? [];
        $data['hotels'] = $data['hotels'] ?? [];
        $data['transports'] = $data['transports'] ?? [];
        $data['services'] = $data['services'] ?? [];

        return $data;
    }

    private function meaningfulRow(string $group, array $row): bool
    {
        $keys = match ($group) {
            'passengers' => ['passenger_id', 'first_name', 'last_name', 'passport_no', 'date_of_birth', 'ticket_number'],
            'flights' => ['from_code', 'to_code', 'airline_name', 'flight_number', 'departure_date', 'pnr'],
            'hotels' => ['hotel_id', 'city', 'hotel_name', 'check_in', 'check_out', 'confirmation_no'],
            'transports' => ['route_name', 'route_master_id', 'vehicle_type', 'vehicle_master_id', 'company_name', 'contact_number', 'brn_number', 'provider_reference'],
            'services' => ['service_name', 'details', 'notes'],
            default => array_keys($row),
        };

        foreach ($keys as $key) {
            $value = $row[$key] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function commercial(array $data): array
    {
        $adultPax = max(0, (int) ($data['booked_adult_pax'] ?? 0));
        $childPax = max(0, (int) ($data['booked_child_pax'] ?? 0));
        $infantPax = max(0, (int) ($data['booked_infant_pax'] ?? 0));

        $adultSale = max(0, (float) ($data['adult_sale_price'] ?? 0));
        $childSale = max(0, (float) ($data['child_sale_price'] ?? 0));
        $infantSale = max(0, (float) ($data['infant_sale_price'] ?? 0));

        $adultCost = max(0, (float) ($data['adult_supplier_cost'] ?? 0));
        $childCost = max(0, (float) ($data['child_supplier_cost'] ?? 0));
        $infantCost = max(0, (float) ($data['infant_supplier_cost'] ?? 0));

        $gross = round(
            ($adultPax * $adultSale)
            + ($childPax * $childSale)
            + ($infantPax * $infantSale),
            2
        );

        $supplierTotal = round(
            ($adultPax * $adultCost)
            + ($childPax * $childCost)
            + ($infantPax * $infantCost),
            2
        );

        $discountValue = max(0, (float) ($data['discount_value'] ?? 0));
        $discountType = (string) ($data['discount_type'] ?? 'none');

        $discountAmount = match ($discountType) {
            'percent' => round(
                $gross * min($discountValue, 100) / 100,
                2
            ),
            'fixed' => min($discountValue, $gross),
            default => 0,
        };

        $final = round(max(0, $gross - $discountAmount), 2);
        $agent = max(0, (float) ($data['agent_commission'] ?? 0));
        $salesperson = max(0, (float) ($data['salesperson_commission'] ?? 0));
        $margin = round(
            $final - $supplierTotal - $agent - $salesperson,
            2
        );

        return [
            // Legacy aggregate compatibility.
            'package_sale_price' => $gross,
            'supplier_cost' => $supplierTotal,
            'discount_amount' => $discountAmount,
            'final_sale_price' => $final,
            'net_margin' => $margin,

            // Explicit fare-matrix totals.
            'gross_sale_total' => $gross,
            'supplier_cost_total' => $supplierTotal,
            'discount_amount_total' => $discountAmount,
            'final_sale_total' => $final,
            'net_margin_total' => $margin,
        ];
    }

    private function resolvePassengers(array $passengers, int $bookingId, array &$warnings): array
    {
        foreach ($passengers as $index => $row) {
            if (trim((string) ($row['first_name'] ?? '')) === '' && empty($row['passenger_id'])) {
                continue;
            }

            $resolved = $this->passengerWriter->resolve($row, $bookingId);
            $passengers[$index]['passenger_id'] = $resolved['id'];
            $passengers[$index]['passenger_source'] = $resolved['source'];

            if ($resolved['warning']) {
                $warnings[] = $resolved['warning'];
            }
        }

        return array_values($passengers);
    }

    private function saveCommercialRecord(
        int $bookingId,
        array $data,
        array $commercial,
        string $packageCode
    ): void {
        $this->assertUnifiedTables();
        $now = now();

        $existing = DB::table('booking_group_package_unified')
            ->where('booking_id', $bookingId)
            ->first();

        DB::table('booking_group_package_unified')->updateOrInsert(
            ['booking_id' => $bookingId],
            [
                'package_id' => null,
                'package_name' => $data['package_name'] ?: null,
                'package_code' => $packageCode,
                'booked_pax' => $data['booked_pax'] ?? max(
                    1,
                    (int) ($existing->booked_pax ?? 1)
                ),
                'booked_adult_pax' => $data['booked_adult_pax'] ?? 0,
                'booked_child_pax' => $data['booked_child_pax'] ?? 0,
                'booked_infant_pax' => $data['booked_infant_pax'] ?? 0,

                'adult_sale_price' => $data['adult_sale_price'] ?? 0,
                'child_sale_price' => $data['child_sale_price'] ?? 0,
                'infant_sale_price' => $data['infant_sale_price'] ?? 0,

                'adult_supplier_cost' => $data['adult_supplier_cost'] ?? 0,
                'child_supplier_cost' => $data['child_supplier_cost'] ?? 0,
                'infant_supplier_cost' => $data['infant_supplier_cost'] ?? 0,

                'vendor_id' => $data['vendor_id'] ?? null,
                'vendor_package_code' => $data['vendor_package_code'] ?? null,
                'vendor_voucher_no' => $data['vendor_voucher_no']
                    ?? ($existing->vendor_voucher_no ?? null),
                'save_status' => $data['save_mode'],

                // Legacy aggregate columns now store booking totals.
                'package_sale_price' => $commercial['package_sale_price'],
                'supplier_cost' => $commercial['supplier_cost'],
                'discount_type' => $data['discount_type'] ?? 'none',
                'discount_value' => $data['discount_value'] ?? 0,
                'final_sale_price' => $commercial['final_sale_price'],
                'agent_commission' => $data['agent_commission'] ?? 0,
                'salesperson_commission' => $data['salesperson_commission'] ?? 0,
                'net_margin' => $commercial['net_margin'],

                'gross_sale_total' => $commercial['gross_sale_total'],
                'supplier_cost_total' => $commercial['supplier_cost_total'],
                'discount_amount_total' => $commercial['discount_amount_total'],
                'final_sale_total' => $commercial['final_sale_total'],
                'net_margin_total' => $commercial['net_margin_total'],
                'currency_code' => strtoupper(
                    (string) ($data['currency_code'] ?? 'PKR')
                ),
                'notes' => $data['notes'] ?? null,
                'total_hotel_nights' => (int) (
                    $existing->total_hotel_nights ?? 0
                ),
                'commercial_saved_at' => $now,
                'updated_at' => $now,
                'created_at' => $existing->created_at ?? $now,
            ]
        );
    }

    private function saveOperationalRows(int $bookingId, array $data): void
    {
        $this->assertUnifiedTables();
        $now = now();

        foreach ($data['passengers'] as $i => $row) {
            DB::table('booking_group_package_passengers')->insert([
                'booking_id' => $bookingId,
                'passenger_id' => $row['passenger_id'] ?? null,
                'passenger_source' => $row['passenger_source'] ?? null,
                'title' => $row['title'] ?? null,
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? null,
                'date_of_birth' => $row['date_of_birth'] ?? null,
                'passport_no' => $row['passport_no'] ?? null,
                'passport_expiry' => $row['passport_expiry'] ?? null,
                'nationality' => $row['nationality'] ?? null,
                'fare_as' => strtoupper(
                    (string) ($row['fare_as'] ?? '')
                ) ?: null,
                'ticket_number' => $row['ticket_number'] ?? null,
                'sort_order' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($data['flights'] as $i => $row) {
            DB::table('booking_group_package_flights')->insert([
                'booking_id' => $bookingId,
                'segment_type' => $row['segment_type'] ?? 'outbound',
                'from_code' => strtoupper((string) ($row['from_code'] ?? '')),
                'to_code' => strtoupper((string) ($row['to_code'] ?? '')),
                'airline_name' => $row['airline_name'] ?? null,
                'flight_number' => $row['flight_number'] ?? null,
                'departure_date' => $row['departure_date'] ?? null,
                'departure_time' => $row['departure_time'] ?? null,
                'arrival_date' => $row['arrival_date'] ?? null,
                'arrival_time' => $row['arrival_time'] ?? null,
                'cabin_class' => $row['cabin_class'] ?? null,
                'baggage' => $row['baggage'] ?? null,
                'pnr' => $row['pnr'] ?? null,
                'status' => $row['status'] ?? 'booked',
                'sort_order' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($data['hotels'] as $i => $row) {
            DB::table('booking_group_package_hotels')->insert([
                'booking_id' => $bookingId,
                'hotel_id' => $row['hotel_id'] ?? null,
                'city' => $row['city'] ?? null,
                'hotel_name' => $row['hotel_name'] ?? '',
                'check_in' => $row['check_in'] ?? null,
                'check_out' => $row['check_out'] ?? null,
                'nights' => $row['nights'] ?? 0,
                'room_type' => $row['room_type'] ?? null,
                'meal_plan' => $row['meal_plan'] ?? null,
                'rooms' => $row['rooms'] ?? 1,
                'confirmation_no' => $row['confirmation_no'] ?? null,
                'status' => $row['status'] ?? 'requested',
                'notes' => $row['notes'] ?? null,
                'sort_order' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($data['transports'] as $i => $row) {
            DB::table('booking_group_package_transports')->insert([
                'booking_id' => $bookingId,
                'route_name' => $row['route_name'] ?? null,
                'route_master_id' => ! empty($row['route_master_id'])
                    ? (int) $row['route_master_id']
                    : null,
                'route_source_table' => $row['route_source_table'] ?? null,
                'vehicle_master_id' => ! empty($row['vehicle_master_id'])
                    ? (int) $row['vehicle_master_id']
                    : null,
                'vehicle_source_table' => $row['vehicle_source_table'] ?? null,
                'from_location' => $row['from_location'] ?? '',
                'to_location' => $row['to_location'] ?? '',
                'vehicle_type' => $row['vehicle_type'] ?? null,
                'pickup_date' => null,
                'pickup_time' => null,
                'company_name' => $row['company_name'] ?? null,
                'contact_number' => $row['contact_number'] ?? null,
                'brn_number' => $row['brn_number'] ?? null,
                'provider_reference' => $row['provider_reference']
                    ?? ($row['brn_number'] ?? null),
                'status' => 'requested',
                'notes' => $row['notes'] ?? null,
                'sort_order' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($data['services'] as $i => $row) {
            DB::table('booking_group_package_services')->insert([
                'booking_id' => $bookingId,
                'service_name' => $row['service_name'] ?? '',
                'details' => $row['details'] ?? null,
                'quantity' => $row['quantity'] ?? 1,
                'status' => $row['status'] ?? 'included',
                'notes' => $row['notes'] ?? null,
                'sort_order' => ($i + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('booking_group_package_unified')
            ->where('booking_id', $bookingId)
            ->update([
                'vendor_voucher_no' => $data['vendor_voucher_no'] ?? null,
                'total_hotel_nights' => $data['total_hotel_nights'] ?? 0,
                'updated_at' => $now,
            ]);

        $this->legacyBridge->sync($bookingId, $data, $now);
    }

    private function assertCommercialPackageSaved(int $bookingId): void
    {
        $row = DB::table('booking_group_package_unified')
            ->where('booking_id', $bookingId)
            ->first();

        if (! $row || empty($row->commercial_saved_at)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'commercial' => 'Save Commercial first. Passenger and travel operations unlock immediately after the commercial package is saved.',
            ]);
        }
    }

    private function assertCommercialBookedPaxCapacity(
        int $bookingId,
        array $data
    ): void {
        $requestedBookedPax = max(1, (int) ($data['booked_pax'] ?? 1));

        $assignedNames = Schema::hasTable('booking_group_package_passengers')
            ? (int) DB::table('booking_group_package_passengers')
                ->where('booking_id', $bookingId)
                ->count()
            : 0;

        if ($assignedNames > $requestedBookedPax) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'booked_pax' => "Booked Pax cannot be reduced to {$requestedBookedPax} because {$assignedNames} passenger name(s) are already assigned.",
            ]);
        }
    }

    private function assertOperationalPassengerCapacity(
        int $bookingId,
        array $data
    ): void {
        $bookedPax = (int) (
            DB::table('booking_group_package_unified')
                ->where('booking_id', $bookingId)
                ->value('booked_pax')
            ?? 0
        );

        $assignedNames = count($data['passengers'] ?? []);

        if ($bookedPax > 0 && $assignedNames > $bookedPax) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'passengers' => "Passenger names assigned ({$assignedNames}) cannot exceed booked package pax ({$bookedPax}). Increase Booked Pax first.",
            ]);
        }

        $commercial = (array) (
            DB::table('booking_group_package_unified')
                ->where('booking_id', $bookingId)
                ->first()
            ?? []
        );

        $limits = [
            'ADULT' => max(0, (int) ($commercial['booked_adult_pax'] ?? $bookedPax)),
            'CHILD' => max(0, (int) ($commercial['booked_child_pax'] ?? 0)),
            'INFANT' => max(0, (int) ($commercial['booked_infant_pax'] ?? 0)),
        ];

        $assignedByFare = ['ADULT' => 0, 'CHILD' => 0, 'INFANT' => 0];

        foreach (($data['passengers'] ?? []) as $passenger) {
            $fare = strtoupper(trim((string) ($passenger['fare_as'] ?? 'ADULT')));

            if (array_key_exists($fare, $assignedByFare)) {
                $assignedByFare[$fare]++;
            }
        }

        foreach ($assignedByFare as $fare => $count) {
            if ($count > ($limits[$fare] ?? 0)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'passengers' => "{$fare} passenger names assigned ({$count}) exceed booked {$fare} pax ({$limits[$fare]}). Correct the commercial pax mix first.",
                ]);
            }
        }
    }

    private function clearOperationalRows(int $bookingId): void
    {
        foreach ([
            'booking_group_package_passengers',
            'booking_group_package_flights',
            'booking_group_package_hotels',
            'booking_group_package_transports',
            'booking_group_package_services',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('booking_id', $bookingId)->delete();
            }
        }
    }

    private function rows(string $table, int $bookingId): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->where('booking_id', $bookingId)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function packageCode(int $bookingId, string $bookingDate): string
    {
        try {
            $year = Carbon::parse($bookingDate)->format('Y');
        } catch (\Throwable) {
            $year = now()->format('Y');
        }

        return 'ET-PKG-' . $year . '-' . (string) $bookingId;
    }

    private function existingPackageCode(int $bookingId): ?string
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return null;
        }

        $value = DB::table('booking_group_package_unified')->where('booking_id', $bookingId)->value('package_code');

        return $value ? (string) $value : null;
    }

    private function bookingReference(int $bookingId): string
    {
        if (Schema::hasTable('bookings')) {
            $columns = Schema::getColumnListing('bookings');
            foreach (['booking_no', 'booking_number', 'booking_reference', 'reference', 'code'] as $column) {
                if (! in_array($column, $columns, true)) {
                    continue;
                }

                $value = DB::table('bookings')->where('id', $bookingId)->value($column);
                if ($value) {
                    return (string) $value;
                }
            }
        }

        return 'BK-' . (string) $bookingId;
    }

    private function operationalHash(array $data): string
    {
        $payload = [
            'package_name' => $data['package_name'] ?? null,
            'vendor_voucher_no' => $data['vendor_voucher_no'] ?? null,
            'passengers' => $data['passengers'] ?? [],
            'flights' => $data['flights'] ?? [],
            'hotels' => $data['hotels'] ?? [],
            'transports' => $data['transports'] ?? [],
            'services' => $data['services'] ?? [],
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function assertUnifiedTables(): void
    {
        foreach ([
            'booking_group_package_unified',
            'booking_group_package_passengers',
            'booking_group_package_flights',
            'booking_group_package_hotels',
            'booking_group_package_transports',
            'booking_group_package_services',
        ] as $table) {
            abort_unless(Schema::hasTable($table), 503, 'Group Package booking database tables are not ready. Run System Health & Updates → Safe Database Upgrade.');
        }
    }
}
