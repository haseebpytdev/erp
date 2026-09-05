<?php

namespace App\Services\Operations;

final class BookingTravelReadinessResolver
{
    /** @return array{status:string,ready:bool,blockers:list<string>} */
    public function resolve(array $booking, array $selectedProducts, array $air, array $hotel, array $transport, array $visa): array
    {
        $selected = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $selectedProducts
        ))));
        $blockers = [];

        if (! $this->bookingApproved($booking)) {
            $blockers[] = 'Booking approval/confirmation is pending.';
        }

        if (in_array('air', $selected, true)) {
            $segments = (array) ($air['itinerary'] ?? []);
            if (! $segments || ! $this->allRowsHave($segments, [['from'], ['to'], ['departure_at'], ['flight_number', 'airline_code', 'airline']])) {
                $blockers[] = 'Flight itinerary is incomplete.';
            }
            $common = (array) ($air['common'] ?? []);
            if ($this->firstString($common, ['pnr', 'airline_pnr', 'reference', 'booking_reference']) === '') {
                $blockers[] = 'Flight PNR/reference is missing.';
            }
            $passengers = (array) ($air['passengers'] ?? []);
            $tickets = (array) ($air['tickets'] ?? []);
            if (($passengers && count($tickets) < count($passengers)) || ! $tickets || ! $this->ticketsIssued($tickets)) {
                $blockers[] = 'Required passenger tickets are not issued.';
            }
        }

        if (in_array('hotel', $selected, true)) {
            $stays = (array) ($hotel['stays'] ?? []);
            if (! $stays || ! $this->allRowsHave($stays, [['city'], ['hotel_name'], ['check_in'], ['check_out'], ['confirmation_number', 'confirmation_no', 'booking_reference', 'reference']])) {
                $blockers[] = 'Hotel stay or confirmation/reference is incomplete.';
            }
        }

        if (in_array('transport', $selected, true)) {
            $rows = (array) ($transport['transports'] ?? []);
            if (! $rows || ! $this->allRowsHave($rows, [['company_name'], ['route_name'], ['vehicle_type']])) {
                $blockers[] = 'Transport company, route or vehicle is incomplete.';
            }
        }

        if (in_array('visa', $selected, true)) {
            $rows = (array) ($visa['visa_rows'] ?? []);
            // Visa readiness is scoped only to passengers who actually have a
            // Visa service row. Other booking passengers are never blockers.
            if ($rows && ! $this->visaRowsIssued($rows)) {
                $blockers[] = 'One or more Visa service rows are not issued.';
            }
        }

        $ready = $blockers === [];
        return ['status' => $ready ? 'Ready' : 'PendingTravel', 'ready' => $ready, 'blockers' => $blockers];
    }

    private function bookingApproved(array $booking): bool
    {
        $value = strtoupper($this->firstString($booking, [
            'approval_status', 'workflow_status', 'booking_status', 'status',
        ]));
        return in_array($value, ['APPROVED', 'CONFIRMED', 'COMPLETED', 'READY'], true);
    }

    private function ticketsIssued(array $rows): bool
    {
        foreach ($rows as $row) {
            $row = (array) $row;
            $status = strtoupper($this->firstString($row, ['ticket_status', 'status']));
            if ($status !== 'ISSUED' || $this->firstString($row, ['ticket_number', 'ticket_no']) === '') return false;
        }
        return true;
    }

    private function visaRowsIssued(array $rows): bool
    {
        foreach ($rows as $row) {
            if (strtoupper($this->firstString((array) $row, ['status', 'visa_status'])) !== 'ISSUED') return false;
        }
        return true;
    }

    /** @param list<list<string>> $fieldGroups */
    private function allRowsHave(array $rows, array $fieldGroups): bool
    {
        foreach ($rows as $row) {
            $row = (array) $row;
            foreach ($fieldGroups as $fields) {
                if ($this->firstString($row, $fields) === '') return false;
            }
        }
        return true;
    }

    private function firstString(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }
}
