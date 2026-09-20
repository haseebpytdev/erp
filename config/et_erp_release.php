<?php

return [
    'version' => 'v1.1.33.321-ERP11.3.321',
    'release' => 'ERP-11.3.321',
    'package' => 'ERP-11.3.321 Shared Shell Selector Containment Fix',
    'package_detail' => 'ERP-11.3.321 corrects two shared shell CSS selectors whose whitespace-separated :not() clauses unintentionally became descendant selectors. The correction confines first-page vertical rhythm and page-canvas width authority to the intended immediate children of main, preventing nested dedicated-product elements from receiving shell-level spacing or width rules. This restores Booking-to-Air fast-navigation geometry parity with refreshed Air while preserving shared shell behavior for Air, Hotel, Transport, Visa, Other Services, Products Hub and the main Booking workspace. No product business logic, API, persistence, database schema or migration changes are included.',
];

