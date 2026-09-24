<?php

return [
    'version' => 'v1.1.33.339-ERP11.3.339',
    'release' => 'ERP-11.3.339',
    'package' => 'ERP-11.3.339 Travel Reports Startup Parse Hotfix',
    'package_detail' => 'ERP-11.3.339 carries the ERP-11.3.338 Operational Travel Reports feature set and repairs the production startup ParseError in TravelReportService.php caused by one missing closing parenthesis in the Group Umrah child-date whereExists expression. No report business logic, formulas, schema or database changes and no migration are included. The .338 deployment failed during startup and was rolled back; .337 is live, and .339 is not deployed. NEW_MIGRATION_REQUIRED=NO.',
];

