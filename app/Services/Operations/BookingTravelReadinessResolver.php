<?php

namespace App\Services\Operations;

final class BookingTravelReadinessResolver
{
    /** @return array{status:string,ready:bool,blockers:list<string>,pending_information:list<string>} */
    public function resolve(array $booking, array $selectedProducts, array $air, array $hotel, array $transport, array $visa, array $other = []): array
    {
        $selected = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $selectedProducts
        ))));
        $blockers = [];
        $pendingInformation = [];

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
            $groups = (array) ($air['ticket_groups'] ?? []);
            if ($groups) {
                foreach ($groups as $groupIndex => $group) {
                    $group = (array) $group;
                    $groupSegments = (array) ($group['segment_ids'] ?? ($group['segments'] ?? []));
                    $groupCommon = (array) ($group['common'] ?? []);
                    $groupTickets = (array) ($group['tickets'] ?? []);
                    if (! $groupSegments) $blockers[] = 'Air Ticket Group #'.($groupIndex + 1).' has no assigned itinerary segment.';
                    if ($this->firstString($groupCommon, ['pnr', 'airline_pnr', 'reference', 'booking_reference']) === '') $blockers[] = 'Air Ticket Group #'.($groupIndex + 1).' PNR/reference is missing.';
                    if (($passengers && count($groupTickets) < count($passengers)) || ! $groupTickets || ! $this->ticketsIssued($groupTickets)) $blockers[] = 'Required passenger tickets are not issued for Air Ticket Group #'.($groupIndex + 1).'.';
                }
            }
            $tickets = (array) ($air['tickets'] ?? []);
            if (! $groups && (($passengers && count($tickets) < count($passengers)) || ! $tickets || ! $this->ticketsIssued($tickets))) {
                $blockers[] = 'Required passenger tickets are not issued.';
            }
        }

        if (in_array('hotel', $selected, true)) {
            $stays = (array) ($hotel['stays'] ?? []);
            if (! $stays) {
                $blockers[] = 'Hotel stay or confirmation/reference is incomplete.';
            } else {
                foreach (array_values($stays) as $index => $stay) {
                    $stay = (array) $stay;
                    $label = trim((string) ($stay['hotel_name'] ?? '')) ?: 'Hotel stay #'.($index + 1);
                    $missing = [];
                    if ($this->firstString($stay, ['hotel_name']) === '') $missing[] = 'hotel';
                    if ($this->firstString($stay, ['city']) === '') $missing[] = 'city';
                    if ($this->firstString($stay, ['check_in']) === '') $missing[] = 'check-in';
                    if ($this->firstString($stay, ['check_out']) === '') $missing[] = 'check-out';
                    if ($this->firstString($stay, ['confirmation_number', 'confirmation_no', 'booking_reference', 'reference']) === '') {
                        $pendingInformation[] = $label.' confirmation/reference is pending.';
                    }
                    if ($missing !== []) {
                        $blockers[] = $label.' is missing '.implode(' and ', array_values(array_unique($missing))).'.';
                    }
                }
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
            // A selected Visa service with no native detail rows is incomplete.
            // Other booking passengers are never blockers unless Visa is selected.
            if (! $rows) {
                $blockers[] = 'Visa service details are missing.';
            } elseif (! $this->visaRowsIssued($rows)) {
                $blockers[] = 'One or more Visa service rows are not issued.';
            }
        }

        if (in_array('other', $selected, true) && empty((array) ($other['services'] ?? []))) {
            $blockers[] = 'Other service details are missing.';
        }
        if (in_array('unsupported', $selected, true)) {
            $blockers[] = 'One or more selected services use an unsupported product category.';
        }

        $ready = $blockers === [];
        return ['status' => $ready ? 'Ready' : 'PendingTravel', 'ready' => $ready, 'blockers' => $blockers, 'pending_information' => array_values(array_unique($pendingInformation))];
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
