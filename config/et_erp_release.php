<?php

return [
    'version' => 'v1.1.33.334-ERP11.3.334',
    'release' => 'ERP-11.3.334',
    'package' => 'ERP-11.3.334 Inactive Passenger Re-add Authority Hotfix',
    'package_detail' => 'ERP-11.3.334 corrects inactive passenger re-add duplicate authority. Duplicate detection uses the shared ActiveBookingPassengerResolver so only current active booking snapshots participate; REMOVED, INACTIVE, DELETED, CANCELLED and CANCELED snapshots, plus deleted_at, is_active=false and active=false snapshots, no longer block re-add. Active duplicate Passenger Master, passport or name+DOB still blocks. Passenger Master reuse, historical ticket and Issue Date evidence remain preserved; old Air and generic links are not resurrected, and an inactive ADULT snapshot may be re-added with a new current fare such as CHILD. No commercial formula, database schema, migration or API contract change is included. NEW_MIGRATION_REQUIRED=NO.',
];

