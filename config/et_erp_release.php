<?php

return [
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
    'asset_version' => 'ERP-11.3.378-C86',
    'release' => 'ERP-11.3.378',
    'corrective_build' => 'C86',
    'corrective_name' => 'Madinah Check-in Movement Report',
    'package' => 'ERP-11.3.378 Party Balance Lifecycle',
    'package_detail' => 'ERP-11.3.378 adds controlled party opening balances, a parent-driven Opening Balance Clearing account, customer advance source authority and a dedicated customer advance return lifecycle with idempotent native journal posting and reversal. Existing accounting formulas and journal authority remain unchanged. DATABASE_SCHEMA_CHANGED=YES. NEW_MIGRATION_REQUIRED=YES.',
];
