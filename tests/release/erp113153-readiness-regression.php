<?php

require_once __DIR__.'/../../app/Services/Operations/BookingTravelReadinessResolver.php';

use App\Services\Operations\BookingTravelReadinessResolver;

$resolver = new BookingTravelReadinessResolver();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    $checks++;
};

$airReady = [
    'passengers' => [['id' => 1], ['id' => 2]],
    'common' => ['pnr' => 'ABC123'],
    'itinerary' => [['from' => 'KHI', 'to' => 'JED', 'departure_at' => '2026-09-10 10:00', 'flight_number' => 'SV701']],
    'tickets' => [
        ['booking_passenger_id' => 1, 'ticket_number' => '111', 'ticket_status' => 'ISSUED'],
        ['booking_passenger_id' => 2, 'ticket_number' => '222', 'ticket_status' => 'ISSUED'],
    ],
];
$hotelReady = ['stays' => [['city' => 'Makkah', 'hotel_name' => 'Hotel', 'check_in' => '2026-09-10', 'check_out' => '2026-09-12', 'confirmation_number' => 'HTL-100']]];
$transportReady = ['transports' => [['company_name' => 'Transport Co', 'route_name' => 'JED-MAK', 'vehicle_type' => 'Bus']]];
$visaReady = ['visa_rows' => [['booking_passenger_id' => 1, 'status' => 'issued']]];

$state = $resolver->resolve(['status' => 'Draft'], ['air', 'hotel', 'transport', 'visa'], $airReady, $hotelReady, $transportReady, $visaReady);
$assert($state['status'] === 'PendingTravel', 'Draft plus complete services remains PendingTravel');

$state = $resolver->resolve(['status' => 'Approved'], ['visa'], [], [], [], ['visa_rows' => [['status' => 'pending']]]);
$assert($state['status'] === 'PendingTravel', 'Approved plus pending Visa remains PendingTravel');

$airPending = $airReady; $airPending['tickets'][1]['ticket_status'] = 'PENDING';
$state = $resolver->resolve(['status' => 'Approved'], ['air'], $airPending, [], [], []);
$assert($state['status'] === 'PendingTravel', 'Approved plus pending Ticket remains PendingTravel');

$state = $resolver->resolve(['status' => 'Approved'], ['air', 'hotel', 'transport', 'visa'], $airReady, $hotelReady, $transportReady, $visaReady);
$assert($state['status'] === 'Ready', 'Approved plus every selected service ready becomes Ready');

$hotelPending = $hotelReady; unset($hotelPending['stays'][0]['confirmation_number']);
$state = $resolver->resolve(['status' => 'Approved'], ['hotel'], [], $hotelPending, [], []);
$assert($state['status'] === 'PendingTravel', 'Approved Hotel without confirmation/reference remains PendingTravel');

$state = $resolver->resolve(['status' => 'Approved'], ['visa'], [], [], [], ['visa_rows' => [['status' => 'pending']]]);
$assert($state['status'] === 'PendingTravel', 'Ready reverses when Visa becomes pending');

$state = $resolver->resolve(['status' => 'Confirmed'], ['hotel'], [], $hotelReady, [], []);
$assert($state['status'] === 'Ready', 'Booking without Visa does not require Visa');

$state = $resolver->resolve(['status' => 'Confirmed'], ['hotel'], [], $hotelReady, [], []);
$assert(! in_array('Transport company, route or vehicle is incomplete.', $state['blockers'], true), 'Booking without Transport does not require Transport');

$state = $resolver->resolve(['status' => 'Confirmed'], ['visa'], [], [], [], ['visa_rows' => []]);
$assert($state['status'] === 'Ready', 'Passengers without a Visa row do not block readiness');

echo "ERP-11.3.153 readiness regression checks passed: {$checks}\n";
