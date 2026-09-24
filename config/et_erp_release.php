<?php

return [
    'version' => 'v1.1.33.340-ERP11.3.340',
    'release' => 'ERP-11.3.340',
    'package' => 'ERP-11.3.340 Travel Reports Remaining Startup Parse Hotfix',
    'package_detail' => 'ERP-11.3.340 repairs the remaining production startup ParseError in TravelReportService.php at orderedChildRows() by restoring one missing closing parenthesis. The .338 and .339 deployments failed during startup and were rolled back; .337 is live, and .340 is not deployed. No report business logic, formulas, schema, database changes or migration are included. NEW_MIGRATION_REQUIRED=NO.',
];

