<?php

return [
    'version' => 'v1.1.33.341-ERP11.3.341',
    'release' => 'ERP-11.3.341',
    'package' => 'ERP-11.3.341 Travel Reports PHP 8.5 Compatibility Corrective',
    'package_detail' => 'ERP-11.3.338 failed startup at TravelReportService.php line 55. ERP-11.3.339 fixed line 55 but failed startup at line 101. ERP-11.3.340 fixed both syntax defects, but its PHP 8.5.7 server preflight detected the implicit-nullability deprecation on $queryAlias; it was not packaged or deployed. ERP-11.3.341 uses ?string $queryAlias=null and passed the exact candidate PHP 8.5.7 parser preflight for all 6 files. No business logic, formula, schema, database or migration change is included. NEW_MIGRATION_REQUIRED=NO.',
];

