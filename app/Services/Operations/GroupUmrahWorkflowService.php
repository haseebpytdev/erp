<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * ERP-10.31.72 Group Umrah commercial-first workflow.
 *
 * Keeps booking confirmation and voucher approval state separate from
 * accounting/payment state. No GL entry is created by this service.
 */
class GroupUmrahWorkflowService
{
    public function __construct(
        private readonly NativeSalesInvoiceInspector $salesInvoices,
        private readonly GroupUmrahDocumentNumberService $documentNumbers,
        private readonly GroupUmrahCommercialAmendmentService $amendments,
    ) {}
    public function syncAfterSave(
        int $bookingId,
        string $saveMode,
        string $operationalHash,
        string $saveScope = 'operational'
    ): void {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return;
        }

        $row = DB::table('booking_group_package_unified')
            ->where('booking_id', $bookingId)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            return;
        }

        $currentBooking = (string) ($row->booking_workflow_status ?? 'draft');
        $currentVoucher = (string) ($row->voucher_status ?? 'not_prepared');
        $approvedHash = (string) ($row->voucher_approved_hash ?? '');

        $updates = ['updated_at' => now()];

        if ($saveScope === 'commercial') {
            if (! in_array($currentBooking, ['confirmed', 'closed', 'cancelled'], true)) {
                $updates['booking_workflow_status'] = $saveMode === 'complete'
                    ? 'ready'
                    : 'draft';
            }

            DB::table('booking_group_package_unified')
                ->where('booking_id', $bookingId)
                ->update($updates);

            if ($currentBooking === 'confirmed') {
                $this->reconcileNativeBookingConfirmation($bookingId);
            }

            return;
        }

        $voucherStatus = $currentVoucher;

        if (
            in_array($currentVoucher, ['approved', 'issued'], true)
            && $approvedHash !== ''
            && ! hash_equals($approvedHash, $operationalHash)
        ) {
            $voucherStatus = 'reapproval_required';
        }

        $updates['voucher_status'] = $voucherStatus;
        $updates['operational_hash'] = $operationalHash;

        DB::table('booking_group_package_unified')
            ->where('booking_id', $bookingId)
            ->update($updates);
    }

    public function reconcileNativeBookingConfirmation(int $bookingId): void
    {
        if (
            ! Schema::hasTable('booking_group_package_unified')
            || ! Schema::hasTable('bookings')
        ) {
            return;
        }

        try {
            $unified=DB::table('booking_group_package_unified')
                ->where('booking_id',$bookingId)
                ->first();

            if (
                ! $unified
                || strtolower(trim((string)($unified->booking_workflow_status ?? ''))) !== 'confirmed'
            ) {
                return;
            }

            $native=DB::table('bookings')->where('id',$bookingId)->first();
            if (! $native) return;

            $nativeRow=(array)$native;
            $columns=Schema::getColumnListing('bookings');

            $confirmedAt=$nativeRow['confirmed_at']
                ?? $unified->confirmed_at
                ?? now();

            $confirmedBy=$nativeRow['confirmed_by']
                ?? $nativeRow['confirmed_by_id']
                ?? $nativeRow['confirmed_user_id']
                ?? $unified->confirmed_by
                ?? null;

            $values=[
                'status'=>'confirmed',
                'booking_status'=>'confirmed',
                'workflow_status'=>'confirmed',
                'confirmation_status'=>'confirmed',
                'is_confirmed'=>1,
                'confirmed'=>1,
                'confirmed_at'=>$confirmedAt,
                'confirmed_by'=>$confirmedBy,
                'confirmed_by_id'=>$confirmedBy,
                'confirmed_user_id'=>$confirmedBy,
            ];

            if (in_array('approval_status',$columns,true)) {
                $values['approval_status']='confirmed';
            }

            foreach ($values as $column=>$value) {
                if (!in_array($column,$columns,true) || $value===null) continue;

                try {
                    DB::table('bookings')
                        ->where('id',$bookingId)
                        ->update([
                            $column=>$value,
                            ...(in_array('updated_at',$columns,true)
                                ? ['updated_at'=>now()]
                                : []),
                        ]);
                } catch (\Throwable) {
                    // A legacy enum may reject one alias; keep repairing the others.
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function transition(int $bookingId, string $action, ?int $userId): array
    {
        return DB::transaction(function () use ($bookingId, $action, $userId): array {
            $row = DB::table('booking_group_package_unified')
                ->where('booking_id', $bookingId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                throw ValidationException::withMessages([
                    'workflow' => 'Group Umrah package details have not been saved yet.',
                ]);
            }

            $bookingStatus = (string) ($row->booking_workflow_status ?? 'draft');
            $voucherStatus = (string) ($row->voucher_status ?? 'not_prepared');
            $saveStatus = (string) ($row->save_status ?? 'draft');
            $now = now();

            $updates = ['updated_at' => $now];

            switch ($action) {
                case 'confirm':
                    if ($saveStatus !== 'complete') {
                        throw ValidationException::withMessages([
                            'workflow' => 'Save the Group Umrah commercial package before confirming it.',
                        ]);
                    }

                    /*
                     * The native SalesInvoiceController@fromBooking requires a
                     * confirmed booking BEFORE invoice creation. Therefore
                     * Group Umrah confirmation must precede accounting.
                     */
                    $updates += [
                        'booking_workflow_status' => 'confirmed',
                        'accounting_status' => $this->salesInvoices->find($bookingId)
                            ? 'ready'
                            : 'pending',
                        'confirmed_at' => $now,
                        'confirmed_by' => $userId,
                    ];

                    $this->trySyncNativeBookingStatus(
                        $bookingId,
                        'confirmed',
                        $userId
                    );
                    break;

                case 'voucher-submit':
                    if ($bookingStatus !== 'confirmed') {
                        throw ValidationException::withMessages([
                            'workflow' => 'Confirm the booking before submitting its voucher for approval.',
                        ]);
                    }
                    if (! $this->salesInvoices->find($bookingId)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'Create the Customer Sales Invoice before submitting this voucher for approval.',
                        ]);
                    }
                    if (! ($this->amendments->state($bookingId, (float) ($row->final_sale_price ?? 0))['accounting_current'] ?? true)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'A commercial amendment is still pending accounting. Revise/create its Sales Invoice before submitting the voucher.',
                        ]);
                    }
                    $readiness = $this->travelReadiness($bookingId, $row);
                    if (! ($readiness['travel_ready'] ?? false)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'Travel data is not ready for voucher approval. Complete all booked passenger names, outbound/inbound flights, hotel stay and transport first.',
                        ]);
                    }
                    if (in_array($voucherStatus, ['approved', 'issued'], true)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'This voucher is already approved or issued.',
                        ]);
                    }
                    $updates += [
                        'voucher_status' => 'pending_approval',
                        'voucher_submitted_at' => $now,
                        'voucher_submitted_by' => $userId,
                        'editing_locked_at' => $now,
                        'editing_locked_by' => $userId,
                        'editing_lock_reason' => 'voucher_pending_approval',
                    ];
                    break;

                case 'voucher-approve':
                    if (! $this->salesInvoices->find($bookingId)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'Create the Customer Sales Invoice before approving this voucher.',
                        ]);
                    }
                    if (! ($this->amendments->state($bookingId, (float) ($row->final_sale_price ?? 0))['accounting_current'] ?? true)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'A commercial amendment is still pending accounting. Complete it before approving the voucher.',
                        ]);
                    }
                    if (! in_array($voucherStatus, ['pending_approval', 'reapproval_required'], true)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'Submit the voucher for approval before approving it.',
                        ]);
                    }
                    $updates += [
                        'voucher_status' => 'approved',
                        'voucher_approved_at' => $now,
                        'voucher_approved_by' => $userId,
                        'voucher_approved_hash' => (string) ($row->operational_hash ?? ''),
                        'editing_locked_at' => $row->editing_locked_at ?? $now,
                        'editing_locked_by' => $row->editing_locked_by ?? $userId,
                        'editing_lock_reason' => 'voucher_approved',
                    ];
                    break;

                case 'voucher-issue':
                    if (! $this->salesInvoices->find($bookingId)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'Create the Customer Sales Invoice before issuing this voucher.',
                        ]);
                    }
                    if (! ($this->amendments->state($bookingId, (float) ($row->final_sale_price ?? 0))['accounting_current'] ?? true)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'A commercial amendment is still pending accounting. Complete it before issuing the voucher.',
                        ]);
                    }
                    if ($voucherStatus !== 'approved') {
                        throw ValidationException::withMessages([
                            'workflow' => 'Only an approved voucher can be marked as issued.',
                        ]);
                    }
                    $updates += [
                        'voucher_status' => 'issued',
                        'voucher_issued_at' => $now,
                        'voucher_issued_by' => $userId,
                        'editing_locked_at' => $row->editing_locked_at ?? $now,
                        'editing_locked_by' => $row->editing_locked_by ?? $userId,
                        'editing_lock_reason' => 'voucher_issued',
                    ];
                    break;

                case 'reopen-editing':
                    if (! $this->editingLockedFromRow($row)) {
                        throw ValidationException::withMessages([
                            'workflow' => 'This Group Umrah booking is already open for editing.',
                        ]);
                    }

                    $updates += [
                        'voucher_status' => 'reapproval_required',
                        'voucher_approved_hash' => null,
                        'editing_locked_at' => null,
                        'editing_locked_by' => null,
                        'editing_lock_reason' => null,
                        'editing_reopened_at' => $now,
                        'editing_reopened_by' => $userId,
                    ];
                    break;

                default:
                    throw ValidationException::withMessages([
                        'workflow' => 'Unsupported Group Umrah workflow action.',
                    ]);
            }

            DB::table('booking_group_package_unified')
                ->where('booking_id', $bookingId)
                ->update($updates);

            return $this->snapshot($bookingId);
        });
    }

    public function snapshot(int $bookingId): array
    {
        $row = Schema::hasTable('booking_group_package_unified')
            ? DB::table('booking_group_package_unified')->where('booking_id', $bookingId)->first()
            : null;

        // A native Group Umrah draft can exist before the unified package row
        // is saved. The edit workspace must still open so staff can enter the
        // commercial package. Treat that state as an empty editable workflow.
        $packageSaved = $row !== null;

        $bookingStatus = (string) ($row->booking_workflow_status ?? 'draft');
        $voucherStatus = (string) ($row->voucher_status ?? 'not_prepared');
        $paymentStatus = $this->nativePaymentStatus($bookingId) ?: (string) ($row->payment_status ?? 'pending');

        $nativeInvoice = $this->salesInvoices->find($bookingId);
        $invoiceStatus = $nativeInvoice ? (string) ($nativeInvoice['status'] ?? 'draft') : null;
        $invoiceNumber = $nativeInvoice ? trim((string) ($nativeInvoice['number'] ?? '')) : '';
        $amendmentState = $this->amendments->state($bookingId, (float) ($row->final_sale_price ?? 0));
        $accountingCurrent = (bool) ($amendmentState['accounting_current'] ?? true);
        $pendingAmendment = $amendmentState['latest_pending'] ?? null;

        $legacyAccountingGap = ! $nativeInvoice
            && in_array($voucherStatus, ['pending_approval', 'approved', 'issued'], true);

        $accountingStatus = $pendingAmendment
            ? ((string) ($pendingAmendment['accounting_action'] ?? '') === 'supplementary_invoice'
                ? 'supplementary_invoice_required'
                : 'invoice_revision_required')
            : ($invoiceStatus
                ? 'invoice_'.$invoiceStatus
                : ($legacyAccountingGap
                    ? 'invoice_required'
                    : ($bookingStatus === 'confirmed'
                        ? 'invoice_pending'
                        : (string) ($row->accounting_status ?? 'pending'))));

        $editingLocked = $this->editingLockedFromRow($row);
        $editingLockReason = (string) ($row->editing_lock_reason ?? '');
        $readiness = $this->travelReadiness($bookingId, $row);
        $invoiceExists = $nativeInvoice !== null;
        $commercialPricingLocked = $invoiceExists
            && strtolower(trim((string) $invoiceStatus)) !== 'draft';

        return [
            'booking_status' => $bookingStatus,
            'booking_label' => $this->label($bookingStatus),
            'voucher_status' => $voucherStatus,
            'voucher_label' => $this->label($voucherStatus),
            'voucher_number' => $this->documentNumbers->voucherNumber($bookingId),
            'accounting_status' => $accountingStatus,
            'accounting_label' => $this->label($accountingStatus),
            'sales_invoice_number' => $invoiceNumber,
            'sales_invoice_status' => $invoiceStatus,
            'package_saved' => $packageSaved,
            'invoice_exists' => $invoiceExists,
            'commercial_pricing_locked' => $commercialPricingLocked,
            'legacy_accounting_gap' => $legacyAccountingGap,
            'accounting_current' => $accountingCurrent,
            'commercial_amendment_pending' => (bool) $pendingAmendment,
            'commercial_amendment_pending_count' => (int) ($amendmentState['pending_count'] ?? 0),
            'commercial_amendment_action' => $pendingAmendment['accounting_action'] ?? null,
            'operations_unlocked' => $packageSaved,
            'booked_pax' => $readiness['booked_pax'],
            'passenger_names_received' => $readiness['passenger_names_received'],
            'passenger_names_pending' => $readiness['passenger_names_pending'],
            'manifest_complete' => $readiness['manifest_complete'],
            'flight_ready' => $readiness['flight_ready'],
            'hotel_ready' => $readiness['hotel_ready'],
            'transport_ready' => $readiness['transport_ready'],
            'travel_ready' => $readiness['travel_ready'],
            'operations_label' => $readiness['operations_label'],
            'payment_status' => $paymentStatus,
            'payment_label' => $this->label($paymentStatus),
            'editing_locked' => $editingLocked,
            'editing_lock_label' => $editingLocked
                ? $this->editingLockLabel($voucherStatus, $editingLockReason)
                : 'Open for Editing',
            'editing_locked_at' => $row->editing_locked_at ?? null,
            'editing_reopened_at' => $row->editing_reopened_at ?? null,
            'can_confirm' => (string) ($row->save_status ?? 'draft') === 'complete'
                && ! in_array($bookingStatus, ['confirmed', 'closed', 'cancelled'], true),
            'can_submit_voucher' => $invoiceExists
                && $accountingCurrent
                && $bookingStatus === 'confirmed'
                && ($readiness['travel_ready'] ?? false)
                && in_array($voucherStatus, ['not_prepared', 'draft', 'reapproval_required'], true),
            'can_approve_voucher' => $invoiceExists
                && $accountingCurrent
                && in_array($voucherStatus, ['pending_approval', 'reapproval_required'], true),
            'can_issue_voucher' => $invoiceExists
                && $accountingCurrent
                && $voucherStatus === 'approved',
            'confirmed_at' => $row->confirmed_at ?? null,
            'voucher_approved_at' => $row->voucher_approved_at ?? null,
            'voucher_issued_at' => $row->voucher_issued_at ?? null,
        ];
    }

    public function assertEditable(int $bookingId): void
    {
        if (! Schema::hasTable('booking_group_package_unified')) {
            return;
        }

        $row = DB::table('booking_group_package_unified')
            ->where('booking_id', $bookingId)
            ->first();

        if ($row && $this->editingLockedFromRow($row)) {
            throw ValidationException::withMessages([
                'workflow' => 'Editing is locked because this voucher has been submitted for approval or approved/issued. An Admin or Super Admin must use Reopen for Editing first.',
            ]);
        }
    }

    private function editingLockedFromRow(?object $row): bool
    {
        if (! $row) {
            return false;
        }

        $voucherStatus = (string) ($row->voucher_status ?? 'not_prepared');

        return ! empty($row->editing_locked_at)
            || in_array($voucherStatus, ['pending_approval', 'approved', 'issued'], true);
    }

    private function editingLockLabel(string $voucherStatus, string $reason): string
    {
        return match ($voucherStatus) {
            'pending_approval' => 'Locked · Voucher Pending Approval',
            'approved' => 'Locked · Voucher Approved',
            'issued' => 'Locked · Voucher Issued',
            default => $reason !== ''
                ? 'Locked · '.ucwords(str_replace('_', ' ', $reason))
                : 'Locked',
        };
    }

    private function trySyncNativeBookingStatus(
        int $bookingId,
        string $status,
        ?int $userId = null
    ): void {
        try {
            if (! Schema::hasTable('bookings')) {
                return;
            }

            $columns = Schema::getColumnListing('bookings');

            $values = [
                'status' => $status,
                'booking_status' => $status,
                'workflow_status' => $status,
                'confirmation_status' => $status,
            ];

            if ($status === 'confirmed') {
                $values += [
                    'is_confirmed' => 1,
                    'confirmed' => 1,
                    'confirmed_at' => now(),
                    'confirmed_by' => $userId,
                    'confirmed_by_id' => $userId,
                    'confirmed_user_id' => $userId,
                ];
            }

            foreach ($values as $column => $value) {
                if (
                    ! in_array($column, $columns, true)
                    || $value === null
                ) {
                    continue;
                }

                try {
                    DB::table('bookings')
                        ->where('id', $bookingId)
                        ->update([
                            $column => $value,
                            ...(in_array('updated_at', $columns, true)
                                ? ['updated_at' => now()]
                                : []),
                        ]);
                } catch (\Throwable) {
                    // Legacy schema may reject an individual field/value.
                }
            }

            if (
                $status === 'confirmed'
                && in_array('approval_status', $columns, true)
            ) {
                foreach (['confirmed', 'approved'] as $approval) {
                    try {
                        DB::table('bookings')
                            ->where('id', $bookingId)
                            ->update([
                                'approval_status' => $approval,
                                ...(in_array('updated_at', $columns, true)
                                    ? ['updated_at' => now()]
                                    : []),
                            ]);
                        break;
                    } catch (\Throwable) {
                    }
                }
            }
        } catch (\Throwable) {
            // Unified workflow remains authoritative.
        }
    }

    private function travelReadiness(int $bookingId, ?object $row = null): array
    {
        $row ??= Schema::hasTable('booking_group_package_unified')
            ? DB::table('booking_group_package_unified')->where('booking_id', $bookingId)->first()
            : null;

        $bookedPax = max(1, (int) ($row->booked_pax ?? 1));

        $passengerNames = Schema::hasTable('booking_group_package_passengers')
            ? (int) DB::table('booking_group_package_passengers')->where('booking_id', $bookingId)->count()
            : 0;

        $passengerPending = max(0, $bookedPax - $passengerNames);
        $manifestComplete = $passengerNames >= $bookedPax;

        $flightRows = Schema::hasTable('booking_group_package_flights')
            ? DB::table('booking_group_package_flights')->where('booking_id', $bookingId)->get()
            : collect();

        $hasOutbound = $flightRows->contains(fn ($flight): bool => in_array(
            strtolower((string) ($flight->segment_type ?? '')),
            ['outbound', 'oneway', 'one-way'],
            true
        ));

        $hasInbound = $flightRows->contains(fn ($flight): bool => in_array(
            strtolower((string) ($flight->segment_type ?? '')),
            ['inbound', 'return'],
            true
        ));

        $flightReady = $hasOutbound && $hasInbound;

        $hotelReady = Schema::hasTable('booking_group_package_hotels')
            && DB::table('booking_group_package_hotels')
                ->where('booking_id', $bookingId)
                ->whereNotNull('check_in')
                ->whereNotNull('check_out')
                ->exists();

        $transportReady = Schema::hasTable('booking_group_package_transports')
            && DB::table('booking_group_package_transports')
                ->where('booking_id', $bookingId)
                ->whereNotNull('route_name')
                ->where('route_name', '<>', '')
                ->whereNotNull('vehicle_type')
                ->where('vehicle_type', '<>', '')
                ->exists();

        $travelReady = $manifestComplete && $flightReady && $hotelReady && $transportReady;

        $parts = [];
        if (! $manifestComplete) $parts[] = $passengerPending.' passenger name(s) pending';
        if (! $flightReady) $parts[] = 'flight itinerary incomplete';
        if (! $hotelReady) $parts[] = 'hotel pending';
        if (! $transportReady) $parts[] = 'transport pending';

        return [
            'booked_pax' => $bookedPax,
            'passenger_names_received' => $passengerNames,
            'passenger_names_pending' => $passengerPending,
            'manifest_complete' => $manifestComplete,
            'flight_ready' => $flightReady,
            'hotel_ready' => $hotelReady,
            'transport_ready' => $transportReady,
            'travel_ready' => $travelReady,
            'operations_label' => $travelReady ? 'Travel Ready' : implode(' · ', $parts),
        ];
    }

    private function nativePaymentStatus(int $bookingId): ?string
    {
        try {
            if (! Schema::hasTable('bookings')) {
                return null;
            }
            $columns = Schema::getColumnListing('bookings');
            foreach (['payment_status', 'collection_status', 'paid_status'] as $column) {
                if (! in_array($column, $columns, true)) {
                    continue;
                }
                $value = DB::table('bookings')->where('id', $bookingId)->value($column);
                if ($value !== null && $value !== '') {
                    return strtolower((string) $value);
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private function label(string $value): string
    {
        return match ($value) {
            'ready' => 'Commercial Saved',
            'not_prepared' => 'Not Prepared',
            'pending_approval' => 'Pending Approval',
            'reapproval_required' => 'Reapproval Required',
            'ready_for_accounting' => 'Ready for Accounting',
            'invoice_pending' => 'Invoice Pending',
            'invoice_required' => 'Invoice Required',
            'invoice_revision_required' => 'Invoice Revision Required',
            'supplementary_invoice_required' => 'Supplementary Invoice Required',
            'invoice_draft' => 'Invoice Draft',
            'invoice_pending_approval' => 'Invoice Pending Approval',
            'invoice_approved' => 'Invoice Approved',
            'invoice_posted' => 'Invoice Posted',
            default => ucwords(str_replace('_', ' ', $value ?: 'pending')),
        };
    }
}
