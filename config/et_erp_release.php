<?php

return [
    'version' => 'v1.1.33.330-ERP11.3.330',
    'release' => 'ERP-11.3.330',
    'package' => 'ERP-11.3.330 Air Workspace, Airline Validation and Booking Access',
    'package_detail' => 'ERP-11.3.330 finalizes the Air workspace and Booking access bridges. It preserves editable Outbound, Return and Connection itinerary defaults, persisted and legacy segment types, the balanced Air layout and controlled 12px rhythm, and the existing commercial formulas. Searchable Airline Master selection supports name/code and keyboard use, resolves legacy values, and fails closed before draft or network save when a meaningful segment lacks a resolved airline_id. Booking product routes and known Booking product APIs remain under Booking Operations authority, including the Booking-side Sales Invoice bridge, while ordinary Sales Invoice routes retain their own authority. Save/fresh-GET/remount regression evidence preserves two segments, Ticket Group ownership, Vendor, PNR, commercials and persisted service_id with second-save reuse. No Booking product API payload contract, persistence schema, database schema or migration changes are included; NEW_MIGRATION_REQUIRED=NO.',
];

