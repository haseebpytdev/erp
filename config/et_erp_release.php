<?php

return [
    'version' => 'v1.1.33.333-ERP11.3.333',
    'release' => 'ERP-11.3.333',
    'package' => 'ERP-11.3.333 Historical Active Passenger Authority Hotfix',
    'package_detail' => 'ERP-11.3.333 follows live ERP-11.3.332 and excludes historical inactive booking-passenger snapshots from current Air/passenger presentation using deleted_at, is_active, active and inactive status variants when installed. Booking table, Air passenger list, Operational Summary, KPI and Review now share current-passenger semantics. Passenger Master records and irreversible issued/ticket/Issue Date history remain preserved; generic service-passenger authority remains behaviorally aligned. All ERP-11.3.332 Air/passenger/commercial behavior is preserved. Commercial formulas, Ticket Group architecture and API field shapes are unchanged; no database schema change or migration is included. NEW_MIGRATION_REQUIRED=NO.',
];

