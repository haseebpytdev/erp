import fs from 'node:fs';

let pass=0,fail=0;
const read=p=>fs.readFileSync(new URL('../../'+p,import.meta.url),'utf8');
const has=(s,n,l)=>{if(s.includes(n)){pass++;console.log('PASS: '+l)}else{fail++;console.log('FAIL: '+l)}};
const lacks=(s,n,l)=>{if(!s.includes(n)){pass++;console.log('PASS: '+l)}else{fail++;console.log('FAIL: '+l)}};

const js=read('public/erp11390/general-progressive-step1.js');
const css=read('public/erp11390/general-progressive-step1.css');
const resolver=read('app/Services/Operations/NativeBookingCustomerResolver.php');

has(js,"etgp-visa-title-113142','Visa'",'Visa section title is distinct from inner card');
has(js,'Passenger visa processing, issuance and commercials','Visa section has concise subtitle');
has(js,"'Visa Setup / Rates'",'editable header retains setup action');
has(js,"'Bulk Actions'",'editable header retains bulk action');
has(js,"'+ Add Visa'",'editable header retains add action');
has(js,"'Save Visa Data'",'editable header retains save action');
has(js,'etgp-visa-filters-113167','search row has compact Filters control');
has(js,"['Passenger','Country','Visa Type','Saudi Company','Pakistani IATA','Status','Sale (PKR)','Vendor Cost (PKR)','Answer','Actions']",'primary table has approved one-line headers');
has(js,"addField('Application Ref.'",'detail strip retains Application Reference');
has(js,"addField('Visa No.'",'detail strip retains Visa Number');
has(js,"addField('Issue Date'",'detail strip retains Issue Date');
has(js,"addField('Expiry Date'",'detail strip retains Expiry Date');
has(js,"addField('Notes'",'detail strip retains wide Notes field');
lacks(js,'detail.hidden=!expanded','detail strip remains visibly associated with each passenger');
has(css,'grid-template-columns:28px minmax(132px,1.35fr)','desktop uses fitted eleven-column Visa grid');
has(css,'.etgp-visa-detail-113142{grid-template-columns:','detail row has aligned five-field grid');
has(css,'.etgp-visa-footer-113167{display:grid','totals and pager share one balanced footer');
has(css,'html.et-booking-locked-113162 .etgp-visa-head-actions-113142','locked mode hides Visa mutation toolbar');
has(css,'html.et-booking-locked-113162 .etgp-visa-actions-113142','locked mode hides row mutation actions');
has(css,'@media(max-width:1100px)','smaller screens use controlled overflow breakpoint');
has(js,"return fetch('/system/erp-bookings/'+bookingId+'/visa-product'",'Visa persistence endpoint is unchanged');
has(js,'row.margin_pkr=row.sale_pkr-row.vendor_cost_pkr','Visa commercial calculation is unchanged');
has(resolver,'fromNativeBookingModel($bookingId)','shared customer authority remains intact');

console.log('TESTS_PASS='+pass);
console.log('TESTS_FAIL='+fail);
process.exit(fail?1:0);
