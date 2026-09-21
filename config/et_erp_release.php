<?php

return [
    'version' => 'v1.1.33.326-ERP11.3.326',
    'release' => 'ERP-11.3.326',
    'package' => 'ERP-11.3.326 Air Empty-Booking Ticket Group Bootstrap',
    'package_detail' => 'ERP-11.3.326 corrects the Air multi-ticket-group bootstrap for fresh GENERAL Air bookings. When the current Air product API returns a valid ticket_groups: [] contract, the Air workspace opens directly in the multi-ticket-group interface with one unsaved starter Ticket Group instead of falling back to the legacy single-PNR editor. The starter group has service_id=null and creates no database state during page render. Native Air service creation remains backend-authoritative and occurs only through the existing final multi-group Save flow. Existing one-group and multi-group bookings remain supported, legacy responses where ticket_groups is absent retain their compatibility path, and draft recovery remains preserved. No backend persistence logic, commercial formulas, CSS, API contract, database schema, new migration, routes, ticket-counting rules, locking or travel-readiness logic are changed by ERP-11.3.326. The booking_service_id migration introduced by ERP-11.3.325 remains part of the cumulative source baseline and is not a new .326 migration.',
];

