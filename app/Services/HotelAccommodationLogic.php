<?php

namespace App\Services;

final class HotelAccommodationLogic
{
    public static function capacityPerRoom(?string $roomType, ?int $sharingCapacity = null): ?int
    {
        $type = strtoupper(trim((string) $roomType));
        $type = preg_replace('/\s+ROOM$/', '', $type) ?: $type;

        return match ($type) {
            'SINGLE' => 1,
            'DOUBLE', 'TWIN' => 2,
            'TRIPLE' => 3,
            'QUAD', 'QUADRUPLE' => 4,
            'SHARING' => $sharingCapacity && $sharingCapacity > 0 ? $sharingCapacity : null,
            default => null,
        };
    }

    /**
     * Resolve hotel occupancy without confusing passenger headcount with bed capacity.
     * Excess passengers are No-Bed passengers; they do not block the stay.
     *
     * @return array{
     *   passenger_count:int,
     *   room_count:int,
     *   capacity_per_room:?int,
     *   total_capacity:?int,
     *   with_bed:int,
     *   no_bed:int,
     *   snapshot:string
     * }
     */
    public static function resolve(
        int $passengerCount,
        int $roomCount,
        ?string $roomType,
        ?int $sharingCapacity = null
    ): array {
        $passengerCount = max(0, $passengerCount);
        $roomCount = max(1, $roomCount);
        $capacityPerRoom = self::capacityPerRoom($roomType, $sharingCapacity);

        if ($capacityPerRoom === null) {
            return [
                'passenger_count' => $passengerCount,
                'room_count' => $roomCount,
                'capacity_per_room' => null,
                'total_capacity' => null,
                'with_bed' => $passengerCount,
                'no_bed' => 0,
                'snapshot' => $passengerCount . ' Pax',
            ];
        }

        $totalCapacity = $roomCount * $capacityPerRoom;
        $withBed = min($passengerCount, $totalCapacity);
        $noBed = max(0, $passengerCount - $totalCapacity);

        $snapshot = $passengerCount . ' Pax · ' . $withBed . ' Bed' . ($withBed === 1 ? '' : 's');
        if ($noBed > 0) {
            $snapshot .= ' · ' . $noBed . ' No Bed';
        }

        return [
            'passenger_count' => $passengerCount,
            'room_count' => $roomCount,
            'capacity_per_room' => $capacityPerRoom,
            'total_capacity' => $totalCapacity,
            'with_bed' => $withBed,
            'no_bed' => $noBed,
            'snapshot' => $snapshot,
        ];
    }
}
