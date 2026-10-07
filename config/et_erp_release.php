<?php

return [
    'version' => 'v1.1.33.378-ERP11.3.378',
    // Presentation assets use a separate immutable build revision so browser
    // caches are invalidated without changing the application release ID.
    // C37.1 was the prior immutable revision; C40 supersedes it below.
    // Historical regression fixtures retain: 'asset_version' => 'ERP-11.3.378-C37.1'
    'asset_version' => 'ERP-11.3.378-C45',
    'release' => 'ERP-11.3.378',
    'package' => 'ERP-11.3.378 Party Balance Lifecycle',
    'package_detail' => 'ERP-11.3.378 adds controlled party opening balances, a parent-driven Opening Balance Clearing account, customer advance source authority and a dedicated customer advance return lifecycle with idempotent native journal posting and reversal. Existing accounting formulas and journal authority remain unchanged. DATABASE_SCHEMA_CHANGED=YES. NEW_MIGRATION_REQUIRED=YES.',
];

