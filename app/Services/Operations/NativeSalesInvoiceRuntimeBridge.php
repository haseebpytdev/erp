<?php

namespace App\Services\Operations;

use App\Services\Sales\NativeBookingSalesInvoiceCreator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class NativeSalesInvoiceRuntimeBridge
{
    public function __construct(
        private readonly NativeSalesInvoiceInspector $invoices,
        private readonly NativeBookingSalesInvoiceCreator $creator,
        private readonly NativeSalesInvoiceCreationVerifier $verifier,
        private readonly NativeBookingCustomerResolver $customerAuthority,
        private readonly GenericServicePassengerLinkSynchronizer $passengerLinks,
    ) {}

    public function create(
        Request $request,
        int $bookingId,
        float $expectedTotal,
        int $minimumLines,
    ): array
    {
        return DB::transaction(function () use (
            $request,
            $bookingId,
            $expectedTotal,
            $minimumLines,
        ): array {
            if (! Schema::hasTable('bookings')) {
                $this->fail('The native bookings table is unavailable.');
            }

            $booking = (array) (DB::table('bookings')
                ->where('id', $bookingId)
                ->lockForUpdate()
                ->first() ?? []);

            if (! $booking) {
                $this->fail('The booking could not be resolved.');
            }

            // This check is inside the locked transaction so two POSTs cannot
            // both pass the earlier controller/UI check.
            $invoiceSummary = $this->invoices->summary($bookingId);
            $existing = $invoiceSummary['latest'] ?? null;

            if ((int) ($invoiceSummary['all_count'] ?? 0) > 0) {
                if (! $existing) {
                    $this->fail('A historical Sales Invoice already exists for this booking. No duplicate invoice was created.');
                }

                return ['created' => false, 'invoice' => $existing];
            }

            $customerId = (int) ($this->customerAuthority->resolve($bookingId)['id'] ?? 0);

            if ($customerId <= 0) {
                $this->fail('The booking Customer / Party is required.');
            }

            // Approved legacy bookings can have complete Air-native ticket
            // rows from before generic BookingService passenger pivots were
            // enforced. Reconcile only the exact validated native Air set in
            // this locked transaction, before the unchanged host validator
            // evaluates service->passengers.
            try {
                $this->passengerLinks
                    ->reconcileCompleteAirServicesForInvoice($bookingId);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first()
                    ?? 'Air passenger links could not be reconciled safely for invoicing.';
                throw ValidationException::withMessages(['invoice' => $message]);
            }

            // The overlay never inserts invoice, line, journal or ledger rows.
            // Only the host-native service is authorized to mutate accounting.
            $this->creator->create($request, $bookingId);

            // Validation runs before DB::transaction commits. Any exception
            // therefore rolls the native mutation back as one unit.
            return ['created' => true] + $this->verifier->verify(
                $bookingId,
                $customerId,
                $expectedTotal,
                $minimumLines,
            );
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['invoice' => $message]);
    }
}
