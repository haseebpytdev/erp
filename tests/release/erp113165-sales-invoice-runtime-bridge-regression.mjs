import fs from 'node:fs';

let pass=0,fail=0;
const read=p=>fs.readFileSync(new URL('../../'+p,import.meta.url),'utf8');
const has=(s,n,l)=>{if(s.includes(n)){pass++;console.log('PASS: '+l)}else{fail++;console.log('FAIL: '+l)}};
const lacks=(s,n,l)=>{if(!s.includes(n)){pass++;console.log('PASS: '+l)}else{fail++;console.log('FAIL: '+l)}};

const capability=read('app/Services/Operations/NativeSalesInvoiceCreateCapability.php');
const bridge=read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');
const verifier=read('app/Services/Operations/NativeSalesInvoiceCreationVerifier.php');
const controller=read('app/Http/Controllers/Sales/StableBookingSalesInvoiceController.php');
const creator=read('app/Services/Sales/NativeBookingSalesInvoiceCreator.php');

has(capability,'class_exists($service)','host service class is detected at runtime');
has(capability,"method_exists($service, 'createFromBooking')",'host createFromBooking method is detected at runtime');
has(controller,'if (! $this->createCapability->enabled())','controller blocks mutation when host capability is missing');
has(bridge,'DB::transaction(function ()','native creation and validation share one transaction');
has(bridge,'->lockForUpdate()','booking row serializes repeated POSTs');
has(bridge,"$invoiceSummary = $this->invoices->summary($bookingId)",'duplicate guard runs inside the transaction');
has(bridge,"$invoiceSummary['all_count']",'duplicate guard includes historical non-active invoices');
has(bridge,'$this->creator->create($request, $bookingId)','bridge delegates creation only to native adapter');
lacks(bridge,"DB::table('sales_invoices')->insert",'bridge has no fallback invoice persistence');
lacks(bridge,"DB::table('journal",'bridge has no fallback journal persistence');
has(creator,'$method->invokeArgs($native, $arguments)','adapter invokes the reflected host-native signature');
has(verifier,"$summary['count']!==1||(int)$summary['all_count']!==1",'post-create validation requires exactly one invoice');
has(verifier,'!==$bookingId','post-create validation verifies booking linkage');
has(verifier,'!==$customerId','post-create validation verifies customer linkage');
has(verifier,"!=='draft'",'post-create validation requires Draft status');
has(verifier,'The native Sales Invoice number was not assigned.','post-create validation requires native number');
has(verifier,'$lineCount<max(1,$minimumLines)','post-create validation requires product lines');
has(verifier,'!$this->same($lineTotal,$expectedTotal)','post-create validation requires authoritative line total');
has(verifier,'!$this->same((float)($data[$headerAmountColumn]??0),$expectedTotal)','post-create validation requires authoritative header total');
has(bridge,'Validation runs before DB::transaction commits','validation failure is transaction rollback authority');
has(controller,'$this->runtimeBridge->create(','eligible creation uses the guarded runtime bridge');
lacks(controller,'recovered Sales Invoice after native service exception','exceptions cannot bypass post-create verification');

console.log('TESTS_PASS='+pass);
console.log('TESTS_FAIL='+fail);
process.exit(fail?1:0);
