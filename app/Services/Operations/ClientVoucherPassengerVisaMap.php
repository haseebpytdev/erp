<?php

namespace App\Services\Operations;

final class ClientVoucherPassengerVisaMap
{
    /** @return array<int,array<string,mixed>> */
    public function build(array $visaRows): array
    {
        $map = [];
        foreach ($visaRows as $row) {
            $row = (array) $row;
            $passengerId = (int) ($row['booking_passenger_id'] ?? $row['passenger_id'] ?? $row['booking_traveller_id'] ?? $row['traveller_id'] ?? 0);
            if ($passengerId > 0 && ! isset($map[$passengerId])) $map[$passengerId] = $row;
        }
        return $map;
    }
}
