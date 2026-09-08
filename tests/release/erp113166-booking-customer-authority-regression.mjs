import fs from 'node:fs';

let pass=0,fail=0;
const read=p=>fs.readFileSync(new URL('../../'+p,import.meta.url),'utf8');
const has=(s,n,l)=>{if(s.includes(n)){pass++;console.log('PASS: '+l)}else{fail++;console.log('FAIL: '+l)}};
const lacks=(s,n,l)=>{if(!s.includes(n)){pass++;console.log('PASS: '+l)}else{fail++;console.log('FAIL: '+l)}};

const writer=read('app/Services/Operations/AdaptiveBookingWriter.php');
const resolver=read('app/Services/Operations/NativeBookingCustomerResolver.php');
const booking=read('app/Http/Controllers/Operations/UnifiedGroupPackageBookingController.php');
const review=read('app/Http/Controllers/Operations/GeneralBookingReviewController.php');
const bridge=read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');
const creator=read('app/Services/Sales/NativeBookingSalesInvoiceCreator.php');
const view=read('resources/views/operations/bookings/group-package-unified-v103172.blade.php');

has(view,'name="customer_id" required','existing Customer / Party selector is the create/repair input authority');
has(writer,'$this->customerColumns()','create and update use one native customer-column map');
has(writer,"'customer_party_id'",'native customer_party_id schema is supported');
has(writer,"'party_master_id'",'native party_master_id schema is supported');
has(booking,'assertPersistedCustomer($bookingId, (int) $data[\'customer_id\'])','new booking verifies customer persistence inside its transaction');
has(booking,'assertPersistedCustomer($booking, (int) $data[\'customer_id\'])','commercial update verifies customer persistence inside its transaction');
has(resolver,"if (Schema::hasTable('bookings'))",'native bookings row is inspected first');
has(resolver,'fromNativeBookingModel($bookingId)','host Booking model relation is the first customer authority');
has(resolver,"'customer',",'native customer relationship alias is supported');
has(resolver,"'party',",'native party relationship alias is supported');
has(resolver,'$booking->getRelationValue($relation)','resolver consumes the same Eloquent relationship as the host page');
has(resolver,"method_exists($relationship, 'getForeignKeyName')",'resolver records the host relationship foreign key');
has(resolver,"'source' => 'native-booking-model.'.\$relation",'resolved identity records its native relation authority');
has(resolver,"if ($saved = $this->savedContext($bookingId))",'legacy context remains fallback only');
has(review,'$customerAuthority->resolve($booking)','Review consumes the shared customer authority');
lacks(review,"['customer_name','client_name','party_name'])?:'—'",'Review no longer relies on denormalized name columns');
has(bridge,'$this->customerAuthority->resolve($bookingId)','invoice validation consumes the shared customer authority');
lacks(bridge,"foreach (['customer_id', 'party_id', 'client_id']",'invoice bridge has no conflicting three-column resolver');
has(creator,'$this->customerAuthority->resolve($bookingId)','native host-service input consumes the shared customer authority');
has(creator,'private function withCustomerIdentity','resolved customer is presented to the host model in memory');
has(creator,"$booking->setAttribute('customer_id', $customerId)",'host compatibility has a canonical transient fallback');
lacks(creator,'$booking->save(','host compatibility never persists a parallel customer field');
has(view,'@if($bookingId && $customerId)','unresolved legacy booking exposes the existing selector for controlled repair');
lacks(booking,"DB::table('booking_group_umrah_contexts')->insert",'unified save creates no parallel customer record');

console.log('TESTS_PASS='+pass);
console.log('TESTS_FAIL='+fail);
process.exit(fail?1:0);
