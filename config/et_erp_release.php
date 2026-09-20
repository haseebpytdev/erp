<?php

return [
    'version' => 'v1.1.33.324-ERP11.3.324',
    'release' => 'ERP-11.3.324',
    'package' => 'ERP-11.3.324 Booking KPI Authority Correction',
    'package_detail' => 'ERP-11.3.324 corrects the main Booking passenger and ticket KPI authorities. The main Booking page now receives passenger count, Adult / Child / Infant fare mix and Air ticket count from the existing server-side Air booking snapshot through the operational summary. This prevents stale passenger totals, neutral Tickets “—” values and duplicated passenger DOM tables from becoming KPI authority. The existing Tickets single-writer guard remains authoritative. No commercial calculations, product fare/commercial formulas, database writes, persistence schema, database schema or migrations are changed.',
];

