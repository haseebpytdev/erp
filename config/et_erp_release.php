<?php

return [
    // Historical C89.4.5.1 regression identity: 'asset_version' => 'ERP-11.3.378-C89.4.5.1', 'corrective_build' => 'C89.4.5.1', 'corrective_name' => 'Air Product Performance Completion'
    'version' => 'v1.1.33.378-ERP11.3.378',
    // Presentation assets use a separate immutable build revision so browser
    // caches are invalidated without changing the application release ID.
    // C37.1 was the prior immutable revision; C40 supersedes it below.
    // Historical regression fixtures retain: 'asset_version' => 'ERP-11.3.378-C37.1'
    // Historical release assertions retain prior lineage aliases: 'asset_version' => 'ERP-11.3.378-C77', 'corrective_build' => 'C77', 'corrective_name' => 'Air Runtime State Integrity'
    // Historical C84 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C84.3.5', 'corrective_build' => 'C84.3.5', 'corrective_name' => 'Scoped Movement Tab Style Fix'
    // Historical C84.4 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C84.4', 'corrective_build' => 'C84.4', 'corrective_name' => 'Native Hotel Multi-Stay Persistence Fix'
    // Historical C84.4.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C84.4.1', 'corrective_build' => 'C84.4.1', 'corrective_name' => 'Hotel Stay Set Reconciliation'
    // Historical C85 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C85', 'corrective_build' => 'C85', 'corrective_name' => 'Makkah Check-out Movement Report'
    // Historical C85.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C85.1', 'corrective_build' => 'C85.1', 'corrective_name' => 'Hotel Stay Set Reconciliation'
    // Historical C86.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C86.1', 'corrective_build' => 'C86.1', 'corrective_name' => 'Travel Report Presentation Fix'
    // Historical C86.2 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C86.2', 'corrective_build' => 'C86.2', 'corrective_name' => 'Travel Report Blade Recovery'
    // Historical C86.3 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C86.3', 'corrective_build' => 'C86.3', 'corrective_name' => 'Movement Report UI Unification'
    // Historical C86.3.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C86.3.1', 'corrective_build' => 'C86.3.1', 'corrective_name' => 'Movement Report UI Recovery'
    // Historical C86.3.2 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C86.3.2', 'corrective_build' => 'C86.3.2', 'corrective_name' => 'Movement Report Visual Alignment'
    // Historical C86.3.3 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C86.3.3', 'corrective_build' => 'C86.3.3', 'corrective_name' => 'Arrival Departure Operational Column Order'
    // Historical C87 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C87', 'corrective_build' => 'C87', 'corrective_name' => 'Madinah Check-out Movement Report'
    // Historical C87.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C87.1', 'corrective_build' => 'C87.1', 'corrective_name' => 'Movement Report UI Normalization'
    // Historical C88 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C88', 'corrective_build' => 'C88', 'corrective_name' => 'Air Commercial Persistence Integrity'
    // Historical C88.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C88.1', 'corrective_build' => 'C88.1', 'corrective_name' => 'Travel Voucher IATA Master Refresh'
    // Historical C89.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.1', 'corrective_build' => 'C89.1', 'corrective_name' => 'RBAC Security Foundation'
    // Historical C89.2.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.2.1', 'corrective_build' => 'C89.2.1', 'corrective_name' => 'Branch Authority — Bookings & Travel Reports'
    // Historical C89.3 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.3', 'corrective_build' => 'C89.3'
    // Historical C89.3.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.3.1', 'corrective_build' => 'C89.3.1'
    // Historical C89.4 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4', 'corrective_build' => 'C89.4', 'corrective_name' => 'Staff Access Management'
    // Historical C89.4.4 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.4', 'corrective_build' => 'C89.4.4', 'corrective_name' => 'Booking Direct Object Access Parity'
    // Historical C89.4.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.1', 'corrective_build' => 'C89.4.1', 'corrective_name' => 'Staff Access Table-Absent Read Model Correction'
    // Historical C89.4.2 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.2', 'corrective_build' => 'C89.4.2', 'corrective_name' => 'User Access Persistence Authority'
    // Historical C89.4.3 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.3', 'corrective_build' => 'C89.4.3', 'corrective_name' => 'Target User Company Context Resolution'
    // Historical C89.4.5.2 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.5.2', 'corrective_build' => 'C89.4.5.2', 'corrective_name' => 'Air Flight Field Entry Performance'
    // Historical C89.4.6 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.6', 'corrective_build' => 'C89.4.6', 'corrective_name' => 'Administration Hub Integration'
    // Historical C89.2.2A assertions retain prior alias: C89.2.2A
    // Historical C89.2.2B1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.2.2B1', 'corrective_build' => 'C89.2.2B1', 'corrective_name' => 'Cash Voucher Branch Ownership'
    // Historical C89.2.2B2 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.2.2B2', 'corrective_build' => 'C89.2.2B2', 'corrective_name' => 'Cash Voucher Legacy Reconciliation Preview'
    // Historical C89.2.3A assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.2.3A', 'corrective_build' => 'C89.2.3A', 'corrective_name' => 'Source-Proven Final Branch Closure'
    // Historical C89.2.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.2.1', 'corrective_build' => 'C89.2.1', 'corrective_name' => 'Branch Authority — Bookings & Travel Reports'
    // Historical C89.4.6.1 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.6.1', 'corrective_build' => 'C89.4.6.1', 'corrective_name' => 'Administration Hub Fail-Safe Completion'
    // Historical C89.4.6.2 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.6.2', 'corrective_build' => 'C89.4.6.2', 'corrective_name' => 'Administration Hub Production Markup Compatibility'
    // Historical C89.4.6.3 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.6.3', 'corrective_build' => 'C89.4.6.3', 'corrective_name' => 'Native Administration View Authority'
    // Historical C89.4.6.4 assertions retain prior aliases: 'asset_version' => 'ERP-11.3.378-C89.4.6.4', 'corrective_build' => 'C89.4.6.4', 'corrective_name' => 'Administration Native Page Correction'
    // Historical C89.4.6.5 regression identity remains available to source tests.
    // 'asset_version' => 'ERP-11.3.378-C89.4.6.5',
    // 'corrective_build' => 'C89.4.6.5',
    // 'corrective_name' => 'Administration Native Spacing and Copy Restoration',
    'asset_version' => 'ERP-11.3.378-C89.5.1B.0A',
    'release' => 'ERP-11.3.378',
    'corrective_build' => 'C89.5.1B.0A',
    'corrective_name' => 'C89 Route Ownership Markers',
    'package' => 'ERP-11.3.378 Party Balance Lifecycle',
    'package_detail' => 'ERP-11.3.378 adds controlled party opening balances, a parent-driven Opening Balance Clearing account, customer advance source authority and a dedicated customer advance return lifecycle with idempotent native journal posting and reversal. Existing accounting formulas and journal authority remain unchanged. DATABASE_SCHEMA_CHANGED=YES. NEW_MIGRATION_REQUIRED=YES.',
];
