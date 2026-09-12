<?php

return [
    'version' => 'v1.1.33.247-ERP11.3.247',
    'release' => 'ERP-11.3.247',
    'package' => 'ERP-11.3.247 Unified Travel ERP',
    'package_detail' => 'ERP-11.3.247 is the one-time Day-One production numbering normalization release. It requires the ERP-11.3.246 Day-Zero reset to have completed and refuses execution if any new production business row exists. Empty transactional table identities are normalized so their next IDs begin at 1000, native sequence counters use last-used 999 or next-value 1000 semantics as appropriate, and booking, invoice, voucher, supplier-costing and posting references use plain 1000, 1001, 1002 numbering without leading zero padding. A permanent completion marker prevents a second Day-One sequence reset. No migration or accounting formula change is introduced.',
];
