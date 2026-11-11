<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\TravelVoucher;
use App\Services\Operations\UnifiedGroupPackageDataSource;
use App\Services\Operations\ClientVoucherFooterResolver;
use App\Services\Operations\ClientVoucherPassengerVisaMap;
use App\Services\Operations\CompletePackageOperationalResolver;
use App\Services\Administration\ErpBranchAccessAuthority;
use App\Services\Organization\CompanyProfileSnapshotService;
use App\Support\QrCodeSvg;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Carbon\Carbon;
use Throwable;

/**
 * ERP-11.3.142
 *
 * Booking-level Client Voucher preview for GENERAL / MULTI-SERVICE bookings.
 * Reads the same authoritative Air, Hotel, Transport and Visa product bridges used by Step 3.
 * The voucher is operational/client-facing: no supplier, cost or margin data.
 */
final class GeneralBookingVoucherPreviewController extends Controller
{
    public function __construct(
        private readonly ErpBranchAccessAuthority $branchAuthority,
    ) {
    }

    public function show(
        Request $request,
        int $booking,
        CompanyProfileSnapshotService $company,
        ClientVoucherFooterResolver $footerResolver,
        ClientVoucherPassengerVisaMap $passengerVisaMap,
        UnifiedGroupPackageDataSource $source,
        CompletePackageOperationalResolver $packageOperational,
    ): View {
        abort_unless(Schema::hasTable('bookings'), 404);
        $bookingRow = DB::table('bookings')->where('id', $booking)->first();
        abort_unless($bookingRow, 404);

        /* Employee previews remain inside company/branch authority. Public
         * bearer-token requests are marked only after exact token lookup. */
        if (! (bool) $request->attributes->get('public_voucher', false)) {
            $user = $request->user();
            abort_unless($user, 401);
            if (! $this->branchAuthority->isSuperAdmin($user)) {
                $bookingCompany = (int) ($bookingRow->company_id ?? 0);
                $userCompany = $this->branchAuthority->companyIdForUser($user);
                abort_unless($bookingCompany > 0 && $userCompany !== null && $bookingCompany === $userCompany, 404);
                $this->branchAuthority->assertMayAccessBranch($user, (int) ($bookingRow->branch_id ?? 0));
            }
        }
        // ET-CV revisions are immutable published document snapshots. When an
        // issued snapshot exists, the authenticated preview must render that
        // snapshot instead of falling back to live booking/product projections.
        // The amendment editor marks its internal projection request so draft
        // editing continues to read the native booking data.
        if (! (bool) $request->attributes->get('et_cv_amendment_snapshot', false)
            && Schema::hasTable('et_cv_voucher_revisions')) {
            $issued = DB::table('et_cv_voucher_revisions')
                ->where('booking_id', $booking)
                ->where('status', 'issued')
                ->orderByDesc('revision_no')
                ->first();
            if ($issued) {
                $snapshot = json_decode((string) $issued->snapshot_json, true) ?: [];
                if (isset($snapshot['generatedAt']) && is_string($snapshot['generatedAt'])) {
                    $snapshot['generatedAt'] = Carbon::parse($snapshot['generatedAt']);
                }
                $snapshot['voucherNumber'] = (string) ($issued->voucher_number ?: ($snapshot['voucherNumber'] ?? ''));
                return view('operations.bookings.general-client-voucher-v113142', $snapshot + [
                    'publicMode' => false,
                    'historicalRevision' => (int) $issued->revision_no,
                ]);
            }
        }
        $bookingData = (array) $bookingRow;

        $air = $this->safeProductSnapshot(
            fn (): array => app(GeneralBookingAirProductController::class)
                ->show($request, $booking)
                ->getData(true)
        );
        $hotel = $this->safeProductSnapshot(
            fn (): array => app(GeneralBookingHotelProductController::class)
                ->show($request, $booking)
                ->getData(true)
        );
        $transport = $this->safeProductSnapshot(
            fn (): array => app(GeneralBookingTransportProductController::class)
                ->show($request, $booking)
                ->getData(true)
        );

        $visa = $this->safeProductSnapshot(
            fn (): array => app(GeneralBookingVisaProductController::class)
                ->show($request, $booking)
                ->getData(true)
        );

        // Normalize package-owned operational rows into the existing C91.16
        // data contract. Existing native rows remain authoritative and are
        // de-duplicated by their canonical row id; no voucher Blade changes
        // or additional financial data are introduced.
        try {
            $package = $packageOperational->snapshot($booking);
            foreach (['itinerary', 'tickets'] as $field) {
                $existing = (array) ($air[$field] ?? []);
                $ids = array_map(static fn($row): int => is_array($row) ? (int) ($row['id'] ?? 0) : (int) ($row->id ?? 0), $existing);
                $extra = array_filter((array) ($package['air'][$field] ?? []), static fn($row): bool => !in_array(is_array($row) ? (int) ($row['id'] ?? 0) : (int) ($row->id ?? 0), $ids, true));
                if ($extra !== []) $air[$field] = array_values(array_merge($existing, $extra));
            }
            foreach ([['target'=>'stays','source'=>'hotel'], ['target'=>'transports','source'=>'transport'], ['target'=>'visa_rows','source'=>'visa']] as $merge) {
                $target = $merge['source'] === 'hotel' ? $hotel : ($merge['source'] === 'transport' ? $transport : $visa);
                $existing = (array) ($target[$merge['target']] ?? []);
                $ids = array_map(static fn($row): int => is_array($row) ? (int) ($row['id'] ?? 0) : (int) ($row->id ?? 0), $existing);
                $extra = array_filter((array) ($package[$merge['source']][$merge['target']] ?? []), static fn($row): bool => !in_array(is_array($row) ? (int) ($row['id'] ?? 0) : (int) ($row->id ?? 0), $ids, true));
                if ($extra !== []) {
                    $target[$merge['target']] = array_values(array_merge($existing, $extra));
                    if ($merge['source'] === 'hotel') $hotel = $target;
                    elseif ($merge['source'] === 'transport') $transport = $target;
                    else $visa = $target;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        $passengers = (array) ($air['passengers'] ?? []);
        if (! $passengers) {
            $passengers = $this->passengerRows($booking);
        }

        $customerId = (int) ($bookingData['customer_id'] ?? $bookingData['party_id'] ?? $bookingData['client_id'] ?? 0);
        $branchId = (int) ($bookingData['branch_id'] ?? 0);

        $customerName = $this->firstString($bookingData, [
            'customer_name', 'client_name', 'party_name',
        ]);
        if ($customerName === '' && $customerId > 0) {
            try {
                $customer = $source->customers()->first(
                    static fn (array $row): bool => (int) ($row['id'] ?? 0) === $customerId
                );
                $customerName = trim((string) ($customer['name'] ?? $customer['party_name'] ?? ''));
            } catch (Throwable) {
            }
        }
        if ($customerName === '') $customerName = 'Customer';

        $branchName = $this->firstString($bookingData, ['branch_name', 'office_name']);
        if ($branchName === '' && $branchId > 0) {
            try {
                $branch = $source->branches()->first(
                    static fn (array $row): bool => (int) ($row['id'] ?? 0) === $branchId
                );
                $branchName = trim((string) ($branch['name'] ?? ''));
            } catch (Throwable) {
            }
        }
        if ($branchName === '') $branchName = 'Head Office';

        $bookingReference = $this->firstString($bookingData, [
            'booking_reference', 'booking_ref', 'booking_no', 'booking_number', 'reference_no', 'reference_number',
        ]);
        if ($bookingReference === '') {
            $year = (string) ($this->bookingDate($bookingData)?->format('Y') ?? now()->format('Y'));
            $bookingReference = 'BK-'.$year.'-'.(string) $booking;
        }

        $hasHotelData = count((array) ($hotel['stays'] ?? [])) > 0;
        $hasAirData = count((array) ($air['itinerary'] ?? [])) > 0 || count((array) ($air['tickets'] ?? [])) > 0;
        $hasTransportData = count((array) ($transport['transports'] ?? [])) > 0;
        $hasVisaData = count((array) ($visa['visa_rows'] ?? [])) > 0;

        $voucherNumber = $this->firstString($bookingData, [
            'travel_voucher_no', 'client_voucher_no', 'voucher_no', 'voucher_number',
        ]);
        if ($voucherNumber === '') {
            $year = (string) ($this->bookingDate($bookingData)?->format('Y') ?? now()->format('Y'));
            $prefix = $hasHotelData && ! $hasAirData && ! $hasTransportData && ! $hasVisaData ? 'ET-AV-' : 'ET-CV-';
            $voucherNumber = $prefix.$year.'-'.(string) $booking;
        }

        $currency = strtoupper($this->firstString($bookingData, ['currency_code', 'currency', 'booking_currency']));
        if ($currency === '') $currency = 'PKR';

        $fareType = static function (array $row): string {
            $value = strtoupper(trim((string) ($row['fare_type'] ?? $row['passenger_type'] ?? $row['age_type'] ?? 'ADULT')));
            return match (true) {
                str_contains($value, 'INF') => 'INFANT',
                str_contains($value, 'CHD'), str_contains($value, 'CHILD') => 'CHILD',
                default => 'ADULT',
            };
        };
        $adultCount = 0; $childCount = 0; $infantCount = 0;
        foreach ($passengers as &$passenger) {
            if (! is_array($passenger)) $passenger = (array) $passenger;
            $type = $fareType($passenger);
            $passenger['fare_type'] = $type;
            if ($type === 'INFANT') $infantCount++;
            elseif ($type === 'CHILD') $childCount++;
            else $adultCount++;
        }
        unset($passenger);

        $familyHead = '';
        foreach ($passengers as $passenger) {
            $isLead = (bool) ($passenger['is_lead_passenger'] ?? $passenger['is_lead'] ?? $passenger['lead'] ?? false);
            if (! $isLead) continue;
            $familyHead = trim((string) ($passenger['name'] ?? $passenger['passenger_name'] ?? ''));
            if ($familyHead !== '') break;
        }
        if ($familyHead === '' && isset($passengers[0])) {
            $familyHead = trim((string) ($passengers[0]['name'] ?? $passengers[0]['passenger_name'] ?? ''));
        }
        if ($familyHead === '') $familyHead = $customerName;
        $familyHead = strtoupper($familyHead);

        $manualNumber = $this->firstString($bookingData, [
            'manual_voucher_no', 'manual_voucher_number', 'manual_no', 'manual_number', 'voucher_manual_no',
        ]);
        $specialInstructions = $this->firstString($bookingData, [
            'special_instructions', 'voucher_instructions', 'client_instructions', 'notes', 'remarks', 'description',
        ]);
        $totalHotelNights = array_sum(array_map(
            static fn ($row): int => max(0, (int) ((is_array($row) ? $row : (array) $row)['nights'] ?? 0)),
            (array) ($hotel['stays'] ?? [])
        ));

        $ticketByPassenger = [];
        foreach ((array) ($air['tickets'] ?? []) as $ticket) {
            $ticket = is_array($ticket) ? $ticket : (array) $ticket;
            $passengerId = (int) ($ticket['passenger_id'] ?? $ticket['booking_passenger_id'] ?? 0);
            if ($passengerId > 0) $ticketByPassenger[$passengerId] = $ticket;
        }

        $visaRows = (array) ($visa['visa_rows'] ?? []);
        $visaByPassenger = $passengerVisaMap->build($visaRows);

        $outboundSegments = [];
        $returnSegments = [];
        foreach ((array) ($air['itinerary'] ?? []) as $segment) {
            $segment = is_array($segment) ? $segment : (array) $segment;
            $type = strtolower(trim((string) ($segment['segment_type'] ?? $segment['type'] ?? 'outbound')));
            if (in_array($type, ['return', 'inbound', 'arrival'], true)) $returnSegments[] = $segment;
            else $outboundSegments[] = $segment;
        }
        $accommodationOnly = $hasHotelData && ! $hasAirData && ! $hasTransportData && ! $hasVisaData;
        $companyProfile = $company->get($bookingData);
        $companyName = trim((string) ($companyProfile['name'] ?? ''));
        $companyInitials = collect(preg_split('/\s+/', $companyName) ?: [])
            ->filter()->take(2)->map(static fn (string $word): string => strtoupper(substr($word, 0, 1)))->implode('');
        if ($companyInitials === '') $companyInitials = 'CO';

        $visaRelationships = [];
        foreach ($visaRows as $visaRow) {
            $visaRow = (array) $visaRow;
            $saudi = trim((string) ($visaRow['saudi_company_name'] ?? ''));
            $iata = trim((string) ($visaRow['pakistani_iata_name'] ?? ''));
            if ($saudi === '' && $iata === '') continue;
            $visaRelationships[strtolower($saudi).'|'.strtolower($iata)] = [
                'saudi_company_name' => $saudi,
                'pakistani_iata_name' => $iata,
            ];
        }
        $resolvedVoucherFooter = $footerResolver->resolve($visaRows, (string) ($companyProfile['footer'] ?? ''));
        $publicToken = $this->publicVoucherToken($booking, $bookingData);
        /* Do not use a named route here. Installed hosts can already contain
         * /voucher/{voucher}; its equivalent URI shape may own a different
         * placeholder name and make route(..., ['token'=>...]) throw during
         * internal preview. The public contract is one fixed opaque path. */
        $publicVoucherUrl = $publicToken !== ''
            ? url('/voucher/'.$publicToken)
            : '';
        $voucherQrImage = '';
        if ($publicVoucherUrl !== '') {
            try {
                $voucherQrImage = QrCodeSvg::dataUri($publicVoucherUrl);
            } catch (Throwable) {
                // Keep the existing safe placeholder when the local encoder
                // cannot represent the URL.
                $voucherQrImage = '';
            }
        }

        $bookingStatusKey = preg_replace('/[^A-Z]/', '', strtoupper($this->firstString($bookingData, [
            'status', 'approval_status', 'workflow_status', 'booking_status',
        ]))) ?: '';
        $travelStatusKey = preg_replace('/[^A-Z]/', '', strtoupper($this->firstString($bookingData, [
            'travel_status', 'readiness_status', 'travel_readiness_status',
        ]))) ?: '';
        $voucherWorkflowStatus = match (true) {
            $bookingStatusKey === 'DRAFT' && $travelStatusKey === 'PENDINGTRAVEL' => 'DRAFT',
            in_array($bookingStatusKey, ['PENDING', 'PENDINGAPPROVAL', 'SUBMITTED'], true)
                && $travelStatusKey === 'PENDINGTRAVEL' => 'PENDING APPROVAL',
            $bookingStatusKey === 'CONFIRMED'
                && in_array($travelStatusKey, ['READY', 'TRAVELREADY'], true) => 'TRAVEL READY',
            default => 'NOT TRAVEL READY',
        };
        $voucherWatermark = match ($voucherWorkflowStatus) {
            'DRAFT' => 'DRAFT',
            'PENDING APPROVAL' => 'PENDING APPROVAL',
            'NOT TRAVEL READY', 'TRAVEL READY' => 'APPROVED',
            default => null,
        };
        $voucherWatermarkTone = match ($voucherWorkflowStatus) {
            'DRAFT' => 'draft',
            'PENDING APPROVAL' => 'pending',
            'NOT TRAVEL READY', 'TRAVEL READY' => 'approved',
            default => null,
        };

        return view('operations.bookings.general-client-voucher-v113142', [
            'company' => $companyProfile,
            'companyInitials' => $companyInitials,
            'visaRelationships' => array_values($visaRelationships),
            'bookingId' => $booking,
            'booking' => $bookingData,
            'bookingReference' => $bookingReference,
            'voucherNumber' => $voucherNumber,
            'voucherWorkflowStatus' => $voucherWorkflowStatus,
            'voucherWatermark' => $voucherWatermark,
            'voucherWatermarkTone' => $voucherWatermarkTone,
            'customerName' => $customerName,
            'branchName' => $branchName,
            'currency' => $currency,
            'passengers' => $passengers,
            'airCommon' => (array) ($air['common'] ?? []),
            'airSegments' => (array) ($air['itinerary'] ?? []),
            'airTickets' => (array) ($air['tickets'] ?? []),
            'hotelStays' => (array) ($hotel['stays'] ?? []),
            'transportRows' => (array) ($transport['transports'] ?? []),
            'visaRows' => $visaRows,
            'visaByPassenger' => $visaByPassenger,
            'resolvedVoucherFooter' => $resolvedVoucherFooter,
            'voucherQrImage' => $voucherQrImage,
            'publicVoucherUrl' => $publicVoucherUrl,
            'generatedAt' => now(),
            'adultCount' => $adultCount,
            'childCount' => $childCount,
            'infantCount' => $infantCount,
            'paxTotal' => count($passengers),
            'familyHead' => $familyHead,
            'manualNumber' => $manualNumber,
            'specialInstructions' => $specialInstructions,
            'totalHotelNights' => $totalHotelNights,
            'ticketByPassenger' => $ticketByPassenger,
            'outboundSegments' => $outboundSegments,
            'returnSegments' => $returnSegments,
            'accommodationOnly' => $accommodationOnly,
            'publicMode' => (bool) $request->attributes->get('public_voucher', false),
        ]);
    }

    public function publicShow(
        Request $request,
        string $token,
        CompanyProfileSnapshotService $company,
        ClientVoucherFooterResolver $footerResolver,
        ClientVoucherPassengerVisaMap $passengerVisaMap,
        UnifiedGroupPackageDataSource $source,
        CompletePackageOperationalResolver $packageOperational,
    ): View {
        if (Schema::hasTable('et_cv_voucher_tokens')) {
            $tokenRow = DB::table('et_cv_voucher_tokens')->where('token', $token)->first();
            if ($tokenRow) {
                abort_unless($tokenRow->status === 'current' && $tokenRow->revision_id, 404, 'Voucher not found.');
                $revision = DB::table('et_cv_voucher_revisions')
                    ->where('id', $tokenRow->revision_id)
                    ->where('status', 'issued')
                    ->first();
                abort_unless($revision, 404, 'Voucher not found.');
                $snapshot = json_decode((string) $revision->snapshot_json, true) ?: [];
                if (isset($snapshot['generatedAt']) && is_string($snapshot['generatedAt'])) {
                    $snapshot['generatedAt'] = Carbon::parse($snapshot['generatedAt']);
                }
                return view('operations.bookings.general-client-voucher-v113142', $snapshot + ['publicMode' => true]);
            }
        }
        abort_unless(
            preg_match('/^[a-f0-9]{48}$/', $token) === 1
            && Schema::hasTable('bookings')
            && Schema::hasColumn('bookings', 'public_voucher_token'),
            404,
            'Voucher not found.'
        );
        $booking = (int) DB::table('bookings')->where('public_voucher_token', $token)->value('id');
        abort_unless($booking > 0, 404, 'Voucher not found.');
        $request->attributes->set('public_voucher', true);

        return $this->show($request, $booking, $company, $footerResolver, $passengerVisaMap, $source, $packageOperational);
    }

    /** Collision-safe adapter for an installed host route using another parameter name. */
    public function publicRoute(
        Request $request,
        CompanyProfileSnapshotService $company,
        ClientVoucherFooterResolver $footerResolver,
        ClientVoucherPassengerVisaMap $passengerVisaMap,
        UnifiedGroupPackageDataSource $source,
        CompletePackageOperationalResolver $packageOperational,
    ): View|Response {
        $parameters=$request->route()?->parameters() ?? [];
        $token=trim((string)reset($parameters));
        if (Schema::hasTable('travel_vouchers')) {
            $nativeExists = TravelVoucher::query()
                ->where('public_token', $token)
                ->orWhere('voucher_no', $token)
                ->exists();
            if ($nativeExists) return app(TravelVoucherController::class)->publicShow($token);
        }
        return $this->publicShow($request,$token,$company,$footerResolver,$passengerVisaMap,$source,$packageOperational);
    }

    private function publicVoucherToken(int $booking, array $bookingData): string
    {
        try {
            if (! Schema::hasColumn('bookings', 'public_voucher_token')) return '';
            $existing = strtolower(trim((string) ($bookingData['public_voucher_token'] ?? '')));
            if (preg_match('/^[a-f0-9]{48}$/', $existing) === 1) return $existing;

            for ($attempt = 0; $attempt < 3; $attempt++) {
                $token = bin2hex(random_bytes(24));
                DB::table('bookings')->where('id', $booking)->whereNull('public_voucher_token')
                    ->update(['public_voucher_token'=>$token]);
                $stored = strtolower(trim((string) DB::table('bookings')->where('id', $booking)->value('public_voucher_token')));
                if (preg_match('/^[a-f0-9]{48}$/', $stored) === 1) return $stored;
            }
        } catch (Throwable $error) {
            report($error);
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function safeProductSnapshot(callable $resolver): array
    {
        try {
            $data = $resolver();
            return is_array($data) ? $data : [];
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function passengerRows(int $booking): array
    {
        if (! Schema::hasTable('booking_passengers')) return [];
        $columns = Schema::getColumnListing('booking_passengers');
        if (! in_array('booking_id', $columns, true)) return [];

        $sort = in_array('passenger_no', $columns, true)
            ? 'passenger_no'
            : (in_array('passenger_index', $columns, true) ? 'passenger_index' : 'id');

        return DB::table('booking_passengers')
            ->where('booking_id', $booking)
            ->orderBy($sort)
            ->get()
            ->map(function (object $row) use ($columns): array {
                $data = (array) $row;
                $name = $this->firstString($data, ['passenger_name', 'full_name', 'name']);
                if ($name === '') {
                    $name = trim(implode(' ', array_filter([
                        $this->firstString($data, ['title']),
                        $this->firstString($data, ['first_name', 'given_name']),
                        $this->firstString($data, ['last_name', 'surname']),
                    ])));
                }
                return [
                    'id' => (int) ($data['id'] ?? 0),
                    'name' => $name ?: 'Passenger',
                    'fare_type' => strtoupper($this->firstString($data, ['fare_type', 'passenger_type', 'age_type']) ?: 'ADULT'),
                    'passport_number' => $this->firstString($data, ['passport_number', 'passport_no']),
                ];
            })->values()->all();
    }

    private function firstString(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row)) continue;
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function bookingDate(array $row): ?\DateTimeImmutable
    {
        foreach (['booking_date', 'date', 'created_at'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value === '') continue;
            try { return new \DateTimeImmutable($value); } catch (Throwable) {}
        }
        return null;
    }

    private function safeVoucherUrl(string $value, bool $allowData): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if ($allowData && str_starts_with($value, 'data:image/')) return $value;
        if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) return $value;
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) return url($value);
        if (preg_match('/^[A-Za-z0-9_\.\-\/]+(?:\?[A-Za-z0-9_\.\-&=%]+)?$/', $value) === 1) return url('/'.ltrim($value, '/'));
        return '';
    }
}
