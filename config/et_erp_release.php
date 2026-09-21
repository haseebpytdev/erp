<?php

return [
    'version' => 'v1.1.33.325-ERP11.3.325',
    'release' => 'ERP-11.3.325',
    'package' => 'ERP-11.3.325 Air Multi-Ticket Groups',
    'package_detail' => 'ERP-11.3.325 adds native multi-ticket-group support to the GENERAL Air booking workspace. One Air booking can now contain multiple Ticket Groups, with each group represented by its own native booking service and owning its Vendor / Supplier, PNR, Airline PNR, GDS / Source, ticket status, issue date, assigned itinerary segment(s), passenger ticket numbers and existing PNR Fare Commercials. The page keeps one booking-level Flight Itinerary and aggregate Air totals while preserving independent group ownership. Draft recovery, locking, validation, save lifecycle, service-ID reconciliation, Travel Readiness and legacy single-group compatibility are preserved. Existing Air commercial formulas are unchanged. This release adds one additive database migration: database/migrations/2026_09_21_000000_add_booking_service_id_to_booking_itinerary_segments.php. The migration adds nullable indexed booking_service_id ownership to booking_itinerary_segments. It is required before multi-ticket-group Air saves are used. No destructive migration is introduced.',
];

