<?php

return [
    'version' => 'v1.1.33.322-ERP11.3.322',
    'release' => 'ERP-11.3.322',
    'package' => 'ERP-11.3.322 Commercial Summary Authority Correction',
    'package_detail' => 'ERP-11.3.322 carries the shared read-only commercial-summary authority correction. The main Booking mount now performs one deduplicated operational-summary refresh, while Booking Review consumes the same resolver for product breakdown, final booking value, supplier cost and margin. The read-only operational-summary response now exposes the resolved commercial summary fields used by the Booking UI. No product fare/commercial formulas, database writes, persistence schema, database schema or migrations are changed.',
];

