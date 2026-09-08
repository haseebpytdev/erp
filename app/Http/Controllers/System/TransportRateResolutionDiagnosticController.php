<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Http\Controllers\Operations\GeneralBookingTransportProductController;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Temporary production UAT diagnostic. It contains no write call and exposes
 * only booking/rate-card commercial data to a positively identified Super Admin.
 */
final class TransportRateResolutionDiagnosticController extends Controller
{
    public function __invoke(Request $request, int $booking, ErpPermissionMatrixService $permissions): JsonResponse
    {
        abort_unless($permissions->isSuperAdmin($request->user()), 403);

        $errors = [];
        $bookingColumns = $this->columns('bookings', $errors);
        $bookingRow = $this->row('bookings', 'id', $booking, $errors);
        abort_unless($bookingRow !== null, 404);

        $service = $this->transportService($booking, $errors);
        $detail = $this->transportDetail($booking, (int) ($service['id'] ?? 0), $errors);
        $detailRow = (array) ($detail['row'] ?? []);
        $detailColumns = (array) ($detail['columns'] ?? []);
        $company = trim((string) $this->value($detailRow, $detailColumns, ['company_name', 'provider_name', 'transport_company', 'vendor_name', 'supplier_name']));
        $lookupDate = $this->lookupDate($bookingRow);
        $route = trim((string) $this->value($detailRow, $detailColumns, ['route_label', 'route_name', 'route']));
        if ($route === '') $route = $this->routePair($detailRow, $detailColumns);
        $vehicle = trim((string) $this->value($detailRow, $detailColumns, ['vehicle_type', 'vehicle_name', 'vehicle']));
        $rateCardId = (int) $this->value($detailRow, $detailColumns, ['rate_card_id', 'route_master_id', 'route_id'], 0);

        $cardColumns = $this->columns('transport_rate_cards', $errors);
        $candidateCards = $this->rateCardRows($cardColumns, $company, $lookupDate, $errors);
        $activeCardIds = $this->activeCardIds($candidateCards);
        $matrixTableCandidates = $this->matrixTableCandidates(
            $activeCardIds,
            $errors
        );
        $liveSchemaDiscovery = $this->liveSchemaDiscovery($activeCardIds, [$route, (string) $this->value($detailRow, $detailColumns, ['pickup_location', 'from_location', 'origin', 'from_city']), (string) $this->value($detailRow, $detailColumns, ['dropoff_location', 'to_location', 'destination', 'to_city'])], $errors);
        $effectiveMatrix = app(UnifiedGroupPackageDataSource::class)
            ->effectiveTransportRateRoutes($company, $lookupDate)
            ->values()
            ->all();
        $selected = $this->selectedMatrixRow($effectiveMatrix, $rateCardId, $route, $vehicle);
        $selectedRaw = $this->selectedRawRow($candidateCards, $selected, $rateCardId, $route, $vehicle, $cardColumns);
        [$field, $rawRate, $genericField, $genericRate] = $this->matrixRateTrace($selectedRaw, $cardColumns, $vehicle);
        $resolverRate = is_array($selected) && is_numeric($selected['rate_amount'] ?? null)
            ? (float) $selected['rate_amount']
            : (is_numeric($rawRate) ? (float) $rawRate : 0.0);
        $savedCost = (float) $this->value($detailRow, $detailColumns, ['supplier_rate', 'vendor_rate', 'cost_rate', 'unit_cost', 'supplier_unit_cost', 'vendor_unit_cost'], 0);
        $hydratedCost = $savedCost > 0 ? $savedCost : ($resolverRate > 0 ? $resolverRate : 0.0);
        $serviceRow = (array) ($service['row'] ?? []);
        $workspace = $this->workspacePayload($request, $booking, $errors);
        $workspaceRow = (array) (($workspace['transports'] ?? [])[0] ?? []);
        $bookingVendorId = (int) $this->value($serviceRow, array_keys($serviceRow), ['vendor_id', 'supplier_id', 'service_provider_id'], 0);
        $bridge = $this->transportCompanyBridge($bookingVendorId, $errors);
        $companyColumns = $this->columns('transport_companies', $errors);
        $companyOne = $this->row('transport_companies', 'id', 1, $errors);
        $vendorRecord = $this->vendorRecord($bookingVendorId, $errors);
        $productionLinks = $this->productionLinks($bookingVendorId, 1, $errors);
        $cardOne = $this->row('transport_rate_cards', 'id', 1, $errors);
        $cardSeven = $this->row('transport_rate_cards', 'id', 7, $errors);
        $bookingServiceColumns = $this->columns('booking_services', $errors);
        $bookingServiceRow = $this->row('booking_services', 'id', (int) ($service['id'] ?? 0), $errors);
        $voucherPartnerColumns = $this->columns('travel_voucher_partners', $errors);
        $voucherPartnerOne = $this->row('travel_voucher_partners', 'id', 1, $errors);
        $voucherPartnerFive = $this->row('travel_voucher_partners', 'id', 5, $errors);
        $basmaMatches = $this->basmaMatches($errors);
        $partyRoleFive = $this->row('party_roles', 'id', 5, $errors);
        $partyRoleTwo = $this->row('party_roles', 'id', 2, $errors);
        $vendorProfileOne = $this->row('vendor_profiles', 'id', 1, $errors);
        $bookingServiceMap = $this->bookingServiceMap($booking, $errors);
        $transportSegmentRows = $this->bookingRows('booking_transport_segments', $booking, $errors);
        $nativeTransportRows = $this->bookingRows('transport_booking_details', $booking, $errors);
        $transportNotesOwners = array_values(array_filter($bookingServiceMap, static fn (array $row): bool => in_array('ETERP_TRANSPORT_ROWS', (array) ($row['notes_payload_types'] ?? []), true)));
        $transportService = collect($bookingServiceMap)->first(static fn (array $row): bool => str_contains(strtolower((string) ($row['service_name'] ?? '')), 'transport') || str_contains(strtolower((string) ($row['service_name'] ?? '')), 'transfer'));
        $hotelService = collect($bookingServiceMap)->first(static fn (array $row): bool => str_contains(strtolower((string) ($row['service_name'] ?? '')), 'hotel') || str_contains(strtolower((string) ($row['service_name'] ?? '')), 'accommodation'));
        $workspaceMatrix = $this->workspaceMatrixRow((array) ($workspace['routes'] ?? []), (string) ($workspaceRow['route_name'] ?? $route), (string) ($workspaceRow['vehicle_type'] ?? $vehicle));
        $nativeDetail = $this->detailFromTable('transport_booking_details', $booking, (int) ($service['id'] ?? 0), $errors);
        $workspaceCompanyFields = $this->fields($workspaceRow, array_keys($workspaceRow), [
            'company_id', 'company_name', 'transport_company_id', 'transport_company_name',
            'vendor_id', 'vendor_name', 'supplier_party_id', 'partner_id', 'partner_name', 'rate_card_id',
        ]);
        $selectedRateCardId = (int) ($workspaceRow['rate_card_id'] ?? $workspaceMatrix['rate_card_id'] ?? $selected['rate_card_id'] ?? 0);
        $selectedRateCard = $this->row('transport_rate_cards', 'id', $selectedRateCardId, $errors);
        $resolvedPartnerId = (int) $this->value((array) ($selectedRateCard ?? []), array_keys((array) ($selectedRateCard ?? [])), ['transport_company_id', 'company_id'], 0);
        $resolvedPartner = $resolvedPartnerId > 0 ? $this->row('travel_voucher_partners', 'id', $resolvedPartnerId, $errors) : null;
        $resolvedPartnerName = trim((string) $this->value((array) ($resolvedPartner ?? []), array_keys((array) ($resolvedPartner ?? [])), ['name', 'partner_name', 'company_name', 'display_name']));
        $companyDisplayTrace = [
            'transport_service_id' => (int) ($service['id'] ?? 0),
            'transport_segment_id' => (int) ($detailRow['id'] ?? 0),
            'server_resolved_company_id' => $resolvedPartnerId ?: null,
            'server_resolved_company_name' => $resolvedPartnerName,
            'workspace_company_fields' => $workspaceCompanyFields,
            'client_company_property' => 'company_name',
            'client_company_fallback' => "saved.company_name || '' then input placeholder 'Transport company'",
            'client_company_value_from_json' => $workspaceRow['company_name'] ?? null,
            'save_company_field' => 'company_name',
            'save_company_value' => $workspaceRow['company_name'] ?? null,
        ];
        $pipeline = [
            'MASTER_RATE' => [
                'rate_card_id' => $selected['rate_card_id'] ?? null,
                'route_id' => $selected['transport_route_id'] ?? null,
                'vehicle_id' => $selected['transport_vehicle_type_id'] ?? null,
                'transport_rates_row_id' => $selected['id'] ?? null,
                'cost_amount' => $resolverRate,
                'currency' => $selected['rate_currency'] ?? null,
            ],
            'RESOLVER' => ['vendor_cost' => $resolverRate, 'source_currency' => $selected['rate_currency'] ?? null],
            'SAVED_BOOKING_SEGMENT' => $this->fields($detailRow, $detailColumns, ['id', 'rate_card_id', 'supplier_currency_code', 'supplier_amount', 'fx_rate_to_pkr', 'exchange_rate', 'supplier_amount_pkr', 'rate_snapshot']),
            'NATIVE_TRANSPORT_DETAIL' => $nativeDetail,
            // This is the exact JSON row returned by the same read-only
            // controller endpoint consumed by the booking workspace.
            'WORKSPACE_CONTROLLER_ROW' => $this->costFields($workspaceRow),
            'PRESENTER' => ['applicable' => false, 'reason' => 'Transport workspace is JSON plus client-side rendering; no PHP presenter transforms its cost row.'],
            'BLADE' => ['applicable' => false, 'reason' => 'Transport Cost Rate, FX and Vendor Total are client-rendered; no Blade input value is used.'],
            'JAVASCRIPT_CONTRACT' => [
                'cost_rate_property' => 'resolved_cost_rate, then cost_rate only when no positive resolved value exists',
                'exchange_rate_property' => 'exchange_rate',
                'vendor_total_expression' => 'cost_rate × quantity × exchange_rate',
                'draft_restore_rule' => 'Browser draft is discarded whenever the server returns an existing transport row.',
                'live_browser_draft_value' => 'NOT_OBSERVABLE_SERVER_SIDE',
            ],
        ];

        return response()->json([
            'READ_ONLY' => true,
            'BOOKING' => [
                'id' => $booking,
                'reference' => $this->value((array) $bookingRow, $bookingColumns, ['booking_number', 'reference', 'booking_reference', 'code']),
                'booking_date' => $this->value((array) $bookingRow, $bookingColumns, ['booking_date', 'date']),
                'travel_date' => $this->value((array) $bookingRow, $bookingColumns, ['travel_date', 'departure_date', 'start_date']),
                'lookup_date_used' => $lookupDate,
            ],
            'TRANSPORT_ROW' => [
                'booking_service_id' => (int) ($service['id'] ?? 0),
                'detail_table' => (string) ($detail['table'] ?? ''),
                'detail_row_id' => (int) ($detailRow['id'] ?? 0),
                'company_vendor_id' => $this->value($detailRow, $detailColumns, ['vendor_id', 'supplier_id', 'service_provider_id']),
                'company_name' => $company,
                'route_master_id' => $this->value($detailRow, $detailColumns, ['route_master_id', 'route_id']),
                'rate_card_id' => $rateCardId,
                'route_saved_name' => $route,
                'origin' => $this->value($detailRow, $detailColumns, ['pickup_location', 'from_location', 'origin', 'from_city']),
                'destination' => $this->value($detailRow, $detailColumns, ['dropoff_location', 'to_location', 'destination', 'to_city']),
                'vehicle_master_id' => $this->value($detailRow, $detailColumns, ['vehicle_master_id', 'vehicle_id', 'vehicle_type_id']),
                'vehicle_saved_type' => $vehicle,
                'qty' => $this->value($detailRow, $detailColumns, ['vehicle_qty', 'vehicle_quantity', 'quantity', 'qty'], 1),
                'saved_sale' => $this->value($detailRow, $detailColumns, ['sale_amount', 'selling_total', 'customer_total', 'sale_total']),
                'saved_cost_rate' => $savedCost,
                'saved_currency' => $this->value($detailRow, $detailColumns, ['cost_rate_currency_code', 'rate_currency', 'source_currency_code', 'cost_currency']),
                'saved_exchange_rate' => $this->value($detailRow, $detailColumns, ['exchange_rate', 'supplier_exchange_rate', 'vendor_exchange_rate']),
            ],
            'RATE_CARD_CANDIDATES' => $candidateCards,
            'RATE_CARD_CANDIDATES_ALL' => $candidateCards,
            'ACTIVE_RATE_CARD_CANDIDATES' => array_values(array_filter($candidateCards, static fn (array $card): bool => strtolower((string) ($card['status'] ?? '')) === 'active' && ! empty($card['is_active']))),
            'SELECTED_RATE_CARD' => $this->safeRow((array) ($selectedRateCard ?? [])),
            'MATRIX_TABLE_CANDIDATES' => $matrixTableCandidates,
            'LIVE_SCHEMA_DISCOVERY' => $liveSchemaDiscovery,
            'EFFECTIVE_MATRIX_ROWS' => $effectiveMatrix,
            'SELECTED_MATRIX_ROW' => $selected,
            'SELECTED_MATRIX_RAW_ROW' => $this->safeRow($selectedRaw),
            'RESOLVER' => [
                'normalized_selected_vehicle_key' => $this->vehicleKey($vehicle),
                'exact_matrix_field_selected' => $field,
                'raw_selected_field_value' => $rawRate,
                'generic_rate_field' => $genericField,
                'generic_rate_value' => $genericRate,
                'resolver_result' => $resolverRate,
                'saved_booking_cost' => $savedCost,
                'final_hydrated_cost' => $hydratedCost,
                'reason_when_zero' => $hydratedCost > 0 ? null : $this->zeroReason($selected, $field, $rawRate, $savedCost),
            ],
            'VENDOR_COST_PIPELINE' => $pipeline,
            'COMPANY_DISPLAY_TRACE' => $companyDisplayTrace,
            'BOOKING_SERVICE_VENDOR_ID' => $this->value($serviceRow, array_keys($serviceRow), ['vendor_id', 'supplier_id', 'service_provider_id', 'transport_company_id', 'company_id']),
            'WORKSPACE_CALLS_MASTER_RESOLVER' => true,
            'LIVE_VENDOR_BRIDGE' => [
                'live_version' => (string) config('et_erp_release.version', ''),
                'booking_service' => [
                    'id' => (int) ($service['id'] ?? 0),
                    'vendor_id' => $bookingVendorId,
                    'vendor_name' => $this->value($serviceRow, array_keys($serviceRow), ['vendor_name', 'supplier_name', 'provider_name']),
                ],
                'transport_company_bridge' => $bridge,
                'workspace_runtime' => [
                    'bridge_method' => 'GeneralBookingTransportProductController::transportCompanyId',
                    'rate_resolver_method' => 'UnifiedGroupPackageDataSource::effectiveTransportRateRoutes',
                    'transport_company_id' => $workspaceMatrix['company_id'] ?? null,
                    'rate_card_id' => $workspaceMatrix['rate_card_id'] ?? null,
                    'rate_card_name' => $workspaceMatrix['rate_card_name'] ?? null,
                    'route_id' => $workspaceMatrix['transport_route_id'] ?? null,
                    'vehicle_id' => $workspaceMatrix['transport_vehicle_type_id'] ?? null,
                    'master_vendor_cost' => $workspaceMatrix['rate_amount'] ?? null,
                    'master_vendor_currency' => $workspaceMatrix['rate_currency'] ?? null,
                    'resolved_cost_rate' => $workspaceRow['resolved_cost_rate'] ?? null,
                    'cost_rate' => $workspaceRow['cost_rate'] ?? null,
                    'cost_currency' => $workspaceRow['cost_currency'] ?? null,
                    'exchange_rate' => $workspaceRow['exchange_rate'] ?? null,
                    'vendor_total' => $workspaceRow['cost_amount'] ?? null,
                    'raw_controller_row' => $this->costFields($workspaceRow),
                ],
            ],
            'LIVE_VENDOR_LINK_DISCOVERY' => [
                'transport_companies_columns' => $companyColumns,
                'transport_company_id_1_raw_row' => $this->safeRow((array) ($companyOne ?? [])),
                'booking_vendor_source_table' => $vendorRecord['table'] ?? null,
                'booking_vendor_id_5_raw_row' => $vendorRecord['row'] ?? null,
                'existing_link_rows' => $productionLinks,
                'transport_company_1_linked_vendor_id' => $this->value((array) ($companyOne ?? []), $companyColumns, ['vendor_id', 'supplier_id', 'party_id', 'vendor_party_id', 'supplier_party_id', 'linked_vendor_id', 'linked_supplier_id', 'vendor_account_id', 'supplier_account_id']),
                'name_match_vendor_to_company' => $this->sameName((array) ($vendorRecord['row'] ?? []), (array) ($companyOne ?? [])),
                'card_1_status' => $this->value((array) ($cardOne ?? []), array_keys((array) ($cardOne ?? [])), ['status']),
                'card_1_is_active' => $this->value((array) ($cardOne ?? []), array_keys((array) ($cardOne ?? [])), ['is_active', 'active']),
                'card_7_status' => $this->value((array) ($cardSeven ?? []), array_keys((array) ($cardSeven ?? [])), ['status']),
                'card_7_is_active' => $this->value((array) ($cardSeven ?? []), array_keys((array) ($cardSeven ?? [])), ['is_active', 'active']),
                'workspace_selected_card_id' => $workspaceMatrix['rate_card_id'] ?? null,
            ],
            'FINAL_TRANSPORT_AUTHORITY_DISCOVERY' => [
                'booking_services_columns' => $bookingServiceColumns,
                'booking_services_foreign_keys' => $this->foreignKeys('booking_services'),
                'booking_service_20_raw_row' => $this->safeRow((array) ($bookingServiceRow ?? [])),
                'booking_services_vendor_id_namespace' => $this->foreignTarget('booking_services', 'vendor_id'),
                'booking_services_vendor_fk_table' => $this->foreignTable('booking_services', 'vendor_id'),
                'booking_services_vendor_row_5' => $this->vendorRecord($bookingVendorId, $errors),
                'travel_voucher_partners_columns' => $voucherPartnerColumns,
                'travel_voucher_partners_foreign_keys' => $this->foreignKeys('travel_voucher_partners'),
                'travel_voucher_partner_1' => $this->safeRow((array) ($voucherPartnerOne ?? [])),
                'travel_voucher_partner_5' => $this->safeRow((array) ($voucherPartnerFive ?? [])),
                'rate_card_transport_company_namespace' => $this->foreignTarget('transport_rate_cards', 'transport_company_id'),
                'rate_card_7_transport_company_id' => $this->value((array) ($cardSeven ?? []), array_keys((array) ($cardSeven ?? [])), ['transport_company_id']),
                'basma_matching_rows' => $basmaMatches,
                'party_role_5' => $this->safeRow((array) ($partyRoleFive ?? [])),
                'party_role_2' => $this->safeRow((array) ($partyRoleTwo ?? [])),
                'vendor_profile_1' => $this->safeRow((array) ($vendorProfileOne ?? [])),
                'party_role_5_relevant' => $this->foreignTable('booking_services', 'vendor_id') === 'party_roles',
                'party_role_2_relevant' => $this->foreignTable('booking_services', 'vendor_id') === 'party_roles',
                'vendor_profile_1_relevant' => $this->foreignTable('booking_services', 'vendor_id') === 'vendor_profiles',
                'inactive_card_actually_used' => (int) ($workspaceMatrix['rate_card_id'] ?? 0) === 1,
                'active_card_7_confirmed' => strtolower((string) ($cardSeven['status'] ?? '')) === 'active' && ! empty($cardSeven['is_active']),
            ],
            'WRONG_TRANSPORT_SERVICE_LINK_AUDIT' => [
                'booking_services_map' => $bookingServiceMap,
                'transport_segment_rows' => $transportSegmentRows,
                'transport_booking_detail_rows' => $nativeTransportRows,
                'air_service_id' => $this->serviceIdMatching($bookingServiceMap, 'air'),
                'hotel_service_id' => $hotelService['booking_service_id'] ?? null,
                'transport_service_id' => $transportService['booking_service_id'] ?? null,
                'visa_service_id' => $this->serviceIdMatching($bookingServiceMap, 'visa'),
                'transport_notes_owner_service_ids' => array_values(array_map(static fn (array $row): int => (int) ($row['booking_service_id'] ?? 0), $transportNotesOwners)),
                'transport_notes_wrong_owner' => $hotelService && collect($transportNotesOwners)->contains(static fn (array $row): bool => (int) ($row['booking_service_id'] ?? 0) === (int) ($hotelService['booking_service_id'] ?? 0)),
                'wrong_owner_writer' => 'GeneralBookingTransportProductController::syncServiceSnapshot',
                'transport_company_master_table' => 'travel_voucher_partners',
                'transport_company_master_id' => 1,
                'transport_company_default_vendor_party_id' => $this->value((array) ($voucherPartnerOne ?? []), $voucherPartnerColumns, ['default_vendor_party_id']),
                'master_ui_supports_vendor_link' => in_array('default_vendor_party_id', $voucherPartnerColumns, true),
            ],
            'DIAGNOSTIC_ERRORS' => $errors,
        ]);
    }

    /** @param list<string> $errors @return list<array<string,mixed>> */
    private function bookingServiceMap(int $booking, array &$errors): array
    {
        $columns = $this->columns('booking_services', $errors);
        if (! in_array('booking_id', $columns, true)) return [];
        $products = $this->productServiceNames($errors);
        try {
            return DB::table('booking_services')->where('booking_id', $booking)->orderBy('id')->get()->map(function (object $object) use ($products): array {
                $row = (array) $object;
                $productId = (int) ($row['product_service_id'] ?? 0);
                $notes = (string) ($row['notes'] ?? $row['remarks'] ?? '');
                $types = [];
                foreach (['ETERP_TRANSPORT_ROWS', 'ETERP_HOTEL_STAYS', 'ETERP_AIR', 'ETERP_VISA'] as $tag) if (str_contains($notes, $tag)) $types[] = $tag;
                return [
                    'booking_service_id' => (int) ($row['id'] ?? 0),
                    'product_service_id' => $productId,
                    'service_name' => $products[$productId] ?? $this->firstText($row, ['service_name', 'name', 'title', 'description']),
                    'description' => $this->firstText($row, ['description', 'service_name', 'name', 'title']),
                    'vendor_id' => $row['vendor_id'] ?? $row['supplier_id'] ?? null,
                    'status' => $row['status'] ?? null,
                    'line_total' => $row['line_total'] ?? $row['selling_total'] ?? $row['customer_total'] ?? null,
                    'notes_payload_types' => $types,
                    'raw_row' => $this->safeRow($row),
                ];
            })->all();
        } catch (\Throwable $e) { $errors[] = 'booking services map: '.$e->getMessage(); return []; }
    }

    /** @param list<string> $errors @return array<int,string> */
    private function productServiceNames(array &$errors): array
    {
        foreach (['products_services', 'product_services', 'product_service_master', 'product_service_masters', 'travel_product_services', 'service_products'] as $table) {
            $columns = $this->columns($table, $errors);
            $id = $this->first($columns, ['id', 'product_service_id']);
            $name = $this->first($columns, ['name', 'service_name', 'title', 'description', 'label']);
            if (! $id || ! $name) continue;
            try { return DB::table($table)->get()->mapWithKeys(fn (object $row): array => [(int) ($row->{$id} ?? 0) => trim((string) ($row->{$name} ?? ''))])->all(); }
            catch (\Throwable $e) { $errors[] = $table.': product-service read '.$e->getMessage(); }
        }
        return [];
    }

    /** @param list<string> $errors @return list<array<string,mixed>> */
    private function bookingRows(string $table, int $booking, array &$errors): array
    {
        $columns = $this->columns($table, $errors);
        if (! in_array('booking_id', $columns, true)) return [];
        try { return DB::table($table)->where('booking_id', $booking)->orderBy('id')->get()->map(fn (object $row): array => $this->safeRow((array) $row))->all(); }
        catch (\Throwable $e) { $errors[] = $table.': booking rows '.$e->getMessage(); return []; }
    }

    /** @param list<array<string,mixed>> $rows */
    private function serviceIdMatching(array $rows, string $term): ?int
    {
        foreach ($rows as $row) if (str_contains(strtolower((string) ($row['service_name'] ?? '')), $term)) return (int) ($row['booking_service_id'] ?? 0) ?: null;
        return null;
    }

    private function firstText(array $row, array $fields): string
    {
        foreach ($fields as $field) if (trim((string) ($row[$field] ?? '')) !== '') return trim((string) $row[$field]);
        return '';
    }

    /** @return list<array<string,mixed>> */
    private function foreignKeys(string $table): array
    {
        try { return Schema::hasTable($table) ? Schema::getForeignKeys($table) : []; }
        catch (\Throwable) { return []; }
    }

    private function foreignTable(string $table, string $column): ?string
    {
        foreach ($this->foreignKeys($table) as $foreign) {
            $columns = (array) ($foreign['columns'] ?? $foreign['local_columns'] ?? []);
            if (! in_array($column, $columns, true)) continue;
            $target = (string) ($foreign['foreign_table'] ?? $foreign['foreign_table_name'] ?? $foreign['table'] ?? '');
            if ($target !== '') return $target;
        }
        return null;
    }

    private function foreignTarget(string $table, string $column): string
    {
        return $this->foreignTable($table, $column) ?? 'NO_DECLARED_FOREIGN_KEY';
    }

    /** @param list<string> $errors @return list<array<string,mixed>> */
    private function basmaMatches(array &$errors): array
    {
        $terms = ['Basma Al Mustaqbal Transport', 'Basma Al Mustaqbal', 'BASMAT ALMUSTAQBAL'];
        $wanted = ['travel_voucher_partners', 'parties', 'party_roles', 'vendor_profiles', 'booking_services'];
        $matches = [];
        foreach ($wanted as $table) {
            $columns = $this->columns($table, $errors);
            if ($columns === []) continue;
            $texts = array_values(array_filter($columns, static fn (string $column): bool => (bool) preg_match('/name|title|label|company|vendor|supplier|party/i', $column)));
            if ($texts === []) continue;
            try {
                $query = DB::table($table)->where(function ($query) use ($texts, $terms): void {
                    foreach ($texts as $column) foreach ($terms as $term) $query->orWhere($column, 'like', '%'.$term.'%');
                });
                $rows = $query->limit(50)->get()->map(fn (object $row): array => $this->safeRow((array) $row))->all();
                if ($rows !== []) $matches[] = ['table' => $table, 'searched_columns' => $texts, 'rows' => $rows];
            } catch (\Throwable $e) { $errors[] = $table.': Basma search '.$e->getMessage(); }
        }
        return $matches;
    }

    /** @param list<string> $errors @return array{table:string,row:array<string,mixed>}|null */
    private function vendorRecord(int $vendorId, array &$errors): ?array
    {
        if ($vendorId <= 0) return null;
        $tables = [];
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                $lower = strtolower($table);
                if ($table !== '' && (str_contains($lower, 'party') || str_contains($lower, 'vendor') || str_contains($lower, 'supplier'))) $tables[] = $table;
            }
        } catch (\Throwable $e) { $errors[] = 'vendor table inventory: '.$e->getMessage(); }
        foreach (array_values(array_unique($tables)) as $table) {
            $columns = $this->columns($table, $errors);
            $id = $this->first($columns, ['id', 'party_id', 'vendor_id', 'supplier_id']);
            if (! $id) continue;
            try {
                $row = DB::table($table)->where($id, $vendorId)->first();
                if ($row) return ['table' => $table, 'row' => $this->safeRow((array) $row)];
            } catch (\Throwable $e) { $errors[] = $table.': vendor read '.$e->getMessage(); }
        }
        return null;
    }

    /** @param list<string> $errors @return list<array<string,mixed>> */
    private function productionLinks(int $vendorId, int $companyId, array &$errors): array
    {
        $result = [];
        $aliases = ['vendor_id', 'party_id', 'supplier_id', 'account_id', 'transport_company_id', 'travel_voucher_partner_id', 'master_id', 'source_id', 'linked_id', 'vendor_party_id', 'supplier_party_id', 'linked_vendor_id', 'linked_supplier_id'];
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                $lower = strtolower($table);
                if ($table === '' || (!str_contains($lower, 'transport') && !str_contains($lower, 'vendor') && !str_contains($lower, 'party') && !str_contains($lower, 'link'))) continue;
                $columns = $this->columns($table, $errors);
                $matches = array_values(array_intersect($columns, $aliases));
                if ($matches === []) continue;
                $query = DB::table($table)->where(function ($query) use ($matches, $vendorId, $companyId): void {
                    foreach ($matches as $column) $query->orWhere($column, $vendorId)->orWhere($column, $companyId);
                });
                $rows = $query->limit(20)->get()->map(fn (object $row): array => $this->safeRow((array) $row))->all();
                if ($rows !== []) $result[] = ['table' => $table, 'matching_columns' => $matches, 'rows' => $rows];
            }
        } catch (\Throwable $e) { $errors[] = 'production link discovery: '.$e->getMessage(); }
        return $result;
    }

    private function sameName(array $vendor, array $company): bool
    {
        $vendorName = strtolower(trim((string) $this->value($vendor, array_keys($vendor), ['name', 'party_name', 'vendor_name', 'supplier_name', 'display_name', 'company_name'])));
        $companyName = strtolower(trim((string) $this->value($company, array_keys($company), ['name', 'company_name', 'transport_company', 'title'])));
        return $vendorName !== '' && $vendorName === $companyName;
    }

    /** @param list<string> $errors @return array<string,mixed> */
    private function transportCompanyBridge(int $vendorId, array &$errors): array
    {
        $matches = [];
        $tables = ['transport_companies', 'transport_company_masters', 'transport_company_master'];
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                if ($table !== '' && str_contains(strtolower($table), 'transport') && str_contains(strtolower($table), 'compan')) $tables[] = $table;
            }
        } catch (\Throwable $e) { $errors[] = 'transport company inventory: '.$e->getMessage(); }
        foreach (array_values(array_unique($tables)) as $table) {
            $columns = $this->columns($table, $errors);
            $id = $this->first($columns, ['id', 'transport_company_id', 'company_id']);
            $link = $this->first($columns, ['vendor_id', 'supplier_id', 'party_id', 'vendor_party_id', 'supplier_party_id', 'linked_vendor_id', 'linked_supplier_id', 'vendor_account_id', 'supplier_account_id']);
            if ($vendorId <= 0 || ! $id || ! $link) continue;
            try {
                foreach (DB::table($table)->where($link, $vendorId)->limit(20)->get() as $row) {
                    $raw = (array) $row;
                    $matches[] = ['table' => $table, 'id_column' => $id, 'link_field' => $link, 'row' => $this->safeRow($raw)];
                }
            } catch (\Throwable $e) { $errors[] = $table.': bridge read '.$e->getMessage(); }
        }
        $first = $matches[0] ?? null;
        $row = (array) ($first['row'] ?? []);
        return [
            'query_vendor_id' => $vendorId,
            'match_count' => count($matches),
            'matches' => $matches,
            'transport_company_id' => $first ? ($row[(string) $first['id_column']] ?? null) : null,
            'transport_company_name' => $this->value($row, array_keys($row), ['name', 'company_name', 'transport_company', 'title']),
            'transport_companies_vendor_id' => $first ? ($row[(string) $first['link_field']] ?? null) : null,
        ];
    }

    /** @param list<array<string,mixed>> $routes @return array<string,mixed> */
    private function workspaceMatrixRow(array $routes, string $route, string $vehicle): array
    {
        foreach ($routes as $routeRow) {
            foreach ((array) ($routeRow['rate_matrix'] ?? [$routeRow]) as $matrix) {
                if (strtolower(trim((string) ($matrix['name'] ?? ''))) === strtolower(trim($route))
                    && strtolower(trim((string) ($matrix['vehicle_type'] ?? ''))) === strtolower(trim($vehicle))) return (array) $matrix;
            }
        }
        return [];
    }

    /** @param list<string> $errors @return array<string,mixed> */
    private function workspacePayload(Request $request, int $booking, array &$errors): array
    {
        try {
            $response = app(GeneralBookingTransportProductController::class)->show($request, $booking);
            $payload = $response->getData(true);
            return is_array($payload) ? $payload : [];
        } catch (\Throwable $e) {
            $errors[] = 'workspace controller: '.$e->getMessage();
            return [];
        }
    }

    /** @param list<string> $errors @return array<string,mixed>|null */
    private function detailFromTable(string $table, int $booking, int $serviceId, array &$errors): ?array
    {
        $columns = $this->columns($table, $errors);
        if (! in_array('booking_id', $columns, true)) return null;
        try {
            $query = DB::table($table)->where('booking_id', $booking);
            if ($serviceId > 0 && in_array('booking_service_id', $columns, true)) $query->where('booking_service_id', $serviceId);
            $row = $query->orderByDesc(in_array('id', $columns, true) ? 'id' : 'booking_id')->first();
            return $row ? $this->safeRow((array) $row) : null;
        } catch (\Throwable $e) {
            $errors[] = $table.': read '.$e->getMessage();
            return null;
        }
    }

    /** @param list<string> $columns @param list<string> $wanted @return array<string,mixed> */
    private function fields(array $row, array $columns, array $wanted): array
    {
        $result = [];
        foreach ($wanted as $field) if (in_array($field, $columns, true)) $result[$field] = $row[$field] ?? null;
        return $result;
    }

    /** @return array<string,mixed> */
    private function costFields(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) if (preg_match('/cost|supplier|vendor|rate|currency|exchange|amount|margin/i', (string) $key)) $result[(string) $key] = $value;
        return $result;
    }

    /** @param list<string> $errors @return list<string> */
    private function columns(string $table, array &$errors): array
    {
        try { return Schema::hasTable($table) ? Schema::getColumnListing($table) : []; }
        catch (\Throwable $e) { $errors[] = $table.': schema '.$e->getMessage(); return []; }
    }

    /** @param list<string> $errors @return array<string,mixed>|null */
    private function row(string $table, string $column, int $value, array &$errors): ?array
    {
        try { return Schema::hasTable($table) ? ((array) DB::table($table)->where($column, $value)->first()) : null; }
        catch (\Throwable $e) { $errors[] = $table.': read '.$e->getMessage(); return null; }
    }

    /** @param list<string> $errors @return array<string,mixed> */
    private function transportService(int $booking, array &$errors): array
    {
        $columns = $this->columns('booking_services', $errors);
        if (! in_array('booking_id', $columns, true)) return [];
        try {
            foreach (DB::table('booking_services')->where('booking_id', $booking)->orderByDesc('id')->get() as $object) {
                $row = (array) $object;
                if ((int) ($row['product_service_id'] ?? 0) === 4) return ['id' => (int) ($row['id'] ?? 0), 'row' => $this->safeRow($row)];
            }
        } catch (\Throwable $e) { $errors[] = 'booking_services: read '.$e->getMessage(); }
        return [];
    }

    /** @param list<string> $errors @return array<string,mixed> */
    private function transportDetail(int $booking, int $serviceId, array &$errors): array
    {
        foreach (['booking_transport_segments', 'booking_transports', 'transport_booking_details', 'booking_transport_details'] as $table) {
            $columns = $this->columns($table, $errors);
            if (! in_array('booking_id', $columns, true)) continue;
            try {
                $query = DB::table($table)->where('booking_id', $booking);
                if ($serviceId > 0 && in_array('booking_service_id', $columns, true)) $query->where('booking_service_id', $serviceId);
                $row = $query->orderByDesc(in_array('id', $columns, true) ? 'id' : 'booking_id')->first();
                if ($row) return ['table' => $table, 'columns' => $columns, 'row' => (array) $row];
            } catch (\Throwable $e) { $errors[] = $table.': read '.$e->getMessage(); }
        }
        return [];
    }

    /** @param list<string> $columns @param list<string> $errors @return list<array<string,mixed>> */
    private function rateCardRows(array $columns, string $company, ?string $asOf, array &$errors): array
    {
        if (! $columns) return [];
        $companyColumn = $this->first($columns, ['company_name', 'provider_name', 'transport_company', 'vendor_name', 'supplier_name']);
        $fromColumn = $this->first($columns, ['effective_from', 'valid_from', 'start_date', 'effective_date']);
        $toColumn = $this->first($columns, ['effective_to', 'valid_to', 'end_date', 'expiry_date']);
        try {
            $rows = [];
            foreach (DB::table('transport_rate_cards')->limit(4000)->get() as $object) {
                $row = (array) $object;
                $rowCompany = trim((string) ($companyColumn ? ($row[$companyColumn] ?? '') : ''));
                if ($company !== '' && strtolower($rowCompany) !== strtolower($company)) continue;
                $from = trim((string) ($fromColumn ? ($row[$fromColumn] ?? '') : ''));
                $to = trim((string) ($toColumn ? ($row[$toColumn] ?? '') : ''));
                if ($asOf && (($from !== '' && $from > $asOf) || ($to !== '' && $to < $asOf))) continue;
                $rows[] = $this->safeRow($row);
            }
            return $rows;
        } catch (\Throwable $e) { $errors[] = 'transport_rate_cards: read '.$e->getMessage(); return []; }
    }

    /**
     * Inventory physical matrix candidates without guessing their host table
     * name. The active-card headers are known; this finds every schema table
     * that actually carries one of those card IDs and exposes only its safe
     * columns/rows to the Super-Admin diagnostic.
     *
     * @param list<int> $cardIds
     * @param list<string> $errors
     * @return list<array<string,mixed>>
     */
    private function matrixTableCandidates(array $cardIds, array &$errors): array
    {
        if ($cardIds === []) return [];
        $tables = [];
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                if ($table !== '' && $table !== 'transport_rate_cards') $tables[] = $table;
            }
        } catch (\Throwable $e) { $errors[] = 'matrix table inventory: '.$e->getMessage(); return []; }

        $result = [];
        foreach ($tables as $table) {
            $columns = $this->columns($table, $errors);
            $linkColumns = array_values(array_intersect($columns, [
                'transport_rate_card_id', 'rate_card_id', 'source_rate_card_id',
                'transport_vendor_rate_card_id', 'transport_card_id', 'card_id',
            ]));
            if ($linkColumns === []) continue;
            foreach ($linkColumns as $linkColumn) {
                try {
                    $rows = DB::table($table)->whereIn($linkColumn, $cardIds)->limit(100)->get()
                        ->map(fn (object $row): array => $this->safeRow((array) $row))->all();
                    if ($rows !== []) {
                        $result[] = [
                            'table' => $table,
                            'link_column' => $linkColumn,
                            'columns' => $columns,
                            'rows' => $rows,
                        ];
                    }
                } catch (\Throwable $e) { $errors[] = $table.'.'.$linkColumn.': read '.$e->getMessage(); }
            }
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $cards @return list<int> */
    private function activeCardIds(array $cards): array
    {
        $ids = [];
        foreach ($cards as $card) {
            $status = strtolower(trim((string) ($card['status'] ?? '')));
            if (! empty($card['is_active']) || $status === 'active') {
                $id = (int) ($card['id'] ?? 0);
                if ($id > 0) $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Inspect the host's actual schema and rows. This avoids depending on the
     * overlay's candidate table names when the native master UI stores matrix
     * cells in an unexpected child, pivot, JSON, or wide-column structure.
     *
     * @param list<int> $cardIds
     * @param list<string> $routeTerms
     * @param list<string> $errors
     * @return array<string,mixed>
     */
    private function liveSchemaDiscovery(array $cardIds, array $routeTerms, array &$errors): array
    {
        $terms = array_values(array_unique(array_filter(array_map('trim', $routeTerms))));
        $candidateTables = [];
        try {
            foreach (Schema::getTables() as $meta) {
                $table = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                $lower = strtolower($table);
                if ($table !== '' && (str_contains($lower, 'transport') || str_contains($lower, 'rate') || str_contains($lower, 'route') || str_contains($lower, 'vehicle'))) $candidateTables[] = $table;
            }
        } catch (\Throwable $e) { $errors[] = 'live schema inventory: '.$e->getMessage(); }

        $tables = [];
        $cardMatches = [];
        $routeMatches = [];
        $matrixCandidates = [];
        $linkAliases = ['transport_rate_card_id', 'rate_card_id', 'source_rate_card_id', 'transport_vendor_rate_card_id', 'transport_card_id', 'card_id'];
        foreach (array_values(array_unique($candidateTables)) as $table) {
            $columns = $this->columns($table, $errors);
            if ($columns === []) continue;
            $primary = null;
            $foreign = [];
            try {
                foreach (Schema::getIndexes($table) as $index) {
                    $isPrimary = (bool) ($index['primary'] ?? $index['is_primary'] ?? false);
                    if ($isPrimary) { $primary = (array) ($index['columns'] ?? []); break; }
                }
            } catch (\Throwable) {}
            try { $foreign = Schema::getForeignKeys($table); } catch (\Throwable) {}
            $linkColumns = array_values(array_intersect($columns, $linkAliases));
            $textColumns = array_values(array_filter($columns, static fn (string $column): bool => (bool) preg_match('/(^|_)(route|origin|destination|pickup|dropoff|from|to|location|sector)(_|$)|json|matrix|rates/i', $column)));
            $shape = in_array('car', $columns, true) || in_array('coaster', $columns, true) || in_array('gmc', $columns, true) || in_array('hiace', $columns, true) || in_array('starex', $columns, true)
                ? 'wide_vehicle_columns'
                : (($this->first($columns, ['vehicle_type', 'vehicle_name', 'vehicle', 'vehicle_type_id']) && $this->first($columns, ['rate', 'rate_amount', 'amount', 'price', 'cost'])) ? 'vehicle_rate_rows' : 'unknown');
            $entry = ['table' => $table, 'columns' => $columns, 'primary_key' => $primary, 'foreign_keys' => $foreign, 'card_link_columns' => $linkColumns, 'schema_type' => $shape];
            $tables[] = $entry;
            if ($linkColumns !== [] || $shape !== 'unknown') $matrixCandidates[] = $entry;

            foreach ($linkColumns as $linkColumn) {
                if ($cardIds === []) continue;
                try {
                    $rows = DB::table($table)->whereIn($linkColumn, $cardIds)->limit(20)->get()->map(fn (object $row): array => $this->safeRow((array) $row))->all();
                    if ($rows !== []) $cardMatches[] = ['table' => $table, 'link_column' => $linkColumn, 'rows' => $rows];
                } catch (\Throwable $e) { $errors[] = $table.'.'.$linkColumn.': card read '.$e->getMessage(); }
            }
            if ($terms !== [] && $textColumns !== []) {
                try {
                    $query = DB::table($table)->where(function ($query) use ($textColumns, $terms): void {
                        foreach ($textColumns as $column) foreach ($terms as $term) $query->orWhere($column, 'like', '%'.$term.'%');
                    });
                    $rows = $query->limit(20)->get()->map(fn (object $row): array => $this->safeRow((array) $row))->all();
                    if ($rows !== []) $routeMatches[] = ['table' => $table, 'searched_columns' => $textColumns, 'rows' => $rows];
                } catch (\Throwable $e) { $errors[] = $table.': route text read '.$e->getMessage(); }
            }
        }
        return [
            'candidate_tables' => $tables,
            'candidate_columns' => array_map(static fn (array $row): array => ['table' => $row['table'], 'columns' => $row['columns']], $tables),
            'card_matching_tables' => $cardMatches,
            'route_text_matching_tables' => $routeMatches,
            'physical_matrix_candidates' => $matrixCandidates,
        ];
    }

    /** @param list<array<string,mixed>> $matrix @return array<string,mixed>|null */
    private function selectedMatrixRow(array $matrix, int $rateCardId, string $route, string $vehicle): ?array
    {
        foreach ($matrix as $row) if ($rateCardId > 0 && (int) ($row['id'] ?? 0) === $rateCardId) return $row;
        foreach ($matrix as $row) {
            if (strtolower(trim((string) ($row['name'] ?? ''))) === strtolower($route)
                && strtolower(trim((string) ($row['vehicle_type'] ?? ''))) === strtolower($vehicle)) return $row;
        }
        return null;
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $columns @return array<string,mixed> */
    private function selectedRawRow(array $rows, ?array $selected, int $rateCardId, string $route, string $vehicle, array $columns): array
    {
        $idColumn = $this->first($columns, ['id', 'rate_detail_id', 'transport_rate_card_detail_id']);
        $fromColumn = $this->first($columns, ['from_location', 'pickup_location', 'origin', 'origin_name', 'from_city', 'from']);
        $toColumn = $this->first($columns, ['to_location', 'dropoff_location', 'destination', 'destination_name', 'to_city', 'to']);
        $vehicleColumn = $this->first($columns, ['vehicle_type', 'vehicle_name', 'vehicle', 'transport_type']);
        foreach ($rows as $row) {
            if ($rateCardId > 0 && $idColumn && (int) ($row[$idColumn] ?? 0) === $rateCardId) return $row;
            $pair = trim((string) ($fromColumn ? ($row[$fromColumn] ?? '') : '').' → '.(string) ($toColumn ? ($row[$toColumn] ?? '') : ''));
            if ($pair === $route && strtolower(trim((string) ($vehicleColumn ? ($row[$vehicleColumn] ?? '') : ''))) === strtolower($vehicle)) return $row;
        }
        return is_array($selected) ? $selected : [];
    }

    /** @param list<string> $columns @return array{0:?string,1:mixed,2:?string,3:mixed} */
    private function matrixRateTrace(array $row, array $columns, string $vehicle): array
    {
        if (($row['rate_field'] ?? '') !== '') {
            return [(string) $row['rate_field'], $row['rate_raw_value'] ?? ($row['rate_amount'] ?? null), null, null];
        }
        $key = $this->vehicleKey($vehicle);
        $vehicleFields = $key === '' ? [] : [$key.'_rate', $key.'_rate_sar', $key.'_sar_rate', 'rate_'.$key, 'rate_'.$key.'_sar', $key.'_cost', $key.'_cost_sar', $key.'_vendor_rate', $key.'_supplier_rate', $key.'_price', $key.'_amount', $key];
        $genericFields = ['rate', 'rate_amount', 'amount', 'price', 'transport_rate', 'supplier_rate', 'vendor_rate', 'cost_rate', 'supplier_amount', 'supplier_cost', 'vendor_cost', 'cost_amount', 'cost_price', 'supplier_price', 'vendor_price', 'purchase_price', 'cost', 'fare', 'net_rate', 'sar_rate', 'sr_rate', 'rate_sar', 'cost_sar'];
        $genericField = $this->first($columns, $genericFields);
        $genericRate = $genericField ? ($row[$genericField] ?? null) : null;
        foreach (array_merge($vehicleFields, $genericFields) as $field) if (in_array($field, $columns, true) && is_numeric($row[$field] ?? null) && (float) $row[$field] > 0) return [$field, $row[$field], $genericField, $genericRate];
        foreach (array_merge($vehicleFields, $genericFields) as $field) if (in_array($field, $columns, true) && is_numeric($row[$field] ?? null)) return [$field, $row[$field], $genericField, $genericRate];
        return [null, null, $genericField, $genericRate];
    }

    /** @param list<string> $columns */
    private function value(array $row, array $columns, array $fields, mixed $default = null): mixed
    {
        foreach ($fields as $field) if (in_array($field, $columns, true) && array_key_exists($field, $row) && $row[$field] !== null) return $row[$field];
        return $default;
    }

    /** @param list<string> $columns */
    private function first(array $columns, array $fields): ?string
    {
        foreach ($fields as $field) if (in_array($field, $columns, true)) return $field;
        return null;
    }

    private function lookupDate(array $booking): ?string
    {
        foreach (['travel_date', 'departure_date', 'start_date', 'booking_date', 'date'] as $field) if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($booking[$field] ?? ''))) return substr((string) $booking[$field], 0, 10);
        return null;
    }

    /** @param list<string> $columns */
    private function routePair(array $row, array $columns): string
    {
        $from = trim((string) $this->value($row, $columns, ['pickup_location', 'from_location', 'origin', 'from_city']));
        $to = trim((string) $this->value($row, $columns, ['dropoff_location', 'to_location', 'destination', 'to_city']));
        return trim($from.(($from !== '' && $to !== '') ? ' → ' : '').$to);
    }

    private function vehicleKey(string $vehicle): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($vehicle))), '_');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function safeRow(array $row): array
    {
        foreach (array_keys($row) as $key) if (preg_match('/password|secret|token|api[_-]?key/i', (string) $key)) unset($row[$key]);
        return $row;
    }

    private function zeroReason(?array $selected, ?string $field, mixed $rawRate, float $savedCost): string
    {
        if (! $selected) return 'No effective active matrix row matched the saved rate-card, route and vehicle.';
        if (! $field) return 'No supported numeric rate field exists on the selected native matrix row.';
        if (! is_numeric($rawRate)) return 'The selected native matrix rate field is not numeric.';
        if ((float) $rawRate <= 0 && $savedCost <= 0) return 'Both the selected native matrix rate and saved booking cost are zero.';
        return 'No positive master rate was available for zero-cost hydration.';
    }
}
