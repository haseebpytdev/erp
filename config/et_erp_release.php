<?php

return [
    'version' => 'v1.1.33.320-ERP11.3.320',
    'release' => 'ERP-11.3.320',
    'package' => 'ERP-11.3.320 Air Fast-Navigation Root Ownership Fix',
    'package_detail' => 'ERP-11.3.320 corrects Booking to Air fast navigation so the dedicated Air main replaces the old Booking main instead of being nested inside it, while preserving transactional rollback on failure. No Air business-formula or API changes, no Hotel/Transport/Visa behavior changes, no database schema changes and no migration are included.',
];

