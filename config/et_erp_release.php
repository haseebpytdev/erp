<?php

return [
    'version' => 'v1.1.33.326-ERP11.3.326',
    'release' => 'ERP-11.3.326',
    'package' => 'ERP-11.3.326 Zero-Ticket-Group Air Bootstrap',
    'package_detail' => 'ERP-11.3.326 corrects Air bootstrap behavior for valid bookings whose operational summary contains ticket_groups as an empty array. Such bookings now open directly in the native multi-ticket-group workspace with one unsaved starter group, while legacy responses that omit ticket_groups retain their compatibility path. No backend persistence, commercial formulas, API contracts, migration, locking or other product behavior changes are included.',
];

