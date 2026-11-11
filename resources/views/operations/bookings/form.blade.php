@extends('layouts.app')
@section('title','New Booking')
@section('page_title','New Booking')

@section('content')
@php
    $defaultBranchId=(int)(auth()->user()->primaryBranch()?->id ?? $branches->first()?->id ?? 0);
    if(!$branches->contains('id',$defaultBranchId)) $defaultBranchId=(int)($branches->first()?->id ?? 0);

    $bookingTypes=[
        'GENERAL'=>[
            'label'=>'General / Multi-service',
            'short'=>'General',
            'icon'=>'▦',
            'desc'=>'Combine air tickets, hotels, visas, transport, packages or other services.',
        ],
        'AIR_ONLY'=>[
            'label'=>'Air Ticket Only',
            'short'=>'Air Ticket',
            'icon'=>'✈',
            'desc'=>'A flight-only booking. Passenger tickets and fares are completed in Air Ticket workspace.',
        ],
        'HOTEL_ONLY'=>[
            'label'=>'Hotel / Accommodation Only',
            'short'=>'Hotel',
            'icon'=>'▤',
            'desc'=>'Accommodation-only booking with hotel stays, rooms, passengers and hotel pricing.',
        ],
        'VISA_ONLY'=>[
            'label'=>'Visa Only',
            'short'=>'Visa',
            'icon'=>'▣',
            'desc'=>'Visa processing without requiring flights or hotel accommodation.',
        ],
        'TRANSPORT_ONLY'=>[
            'label'=>'Transport Only',
            'short'=>'Transport',
            'icon'=>'↔',
            'desc'=>'Transfers, vehicle or transport services only.',
        ],
        'UMRAH'=>[
            'label'=>'Umrah / Package',
            'short'=>'Umrah / Package',
            'icon'=>'◈',
            'desc'=>'A package booking that may combine hotels, flights, visas, transport and other services.',
        ],
        'GROUP'=>[
            'label'=>'Group',
            'short'=>'Group',
            'icon'=>'◎',
            'desc'=>'Operational group booking with multiple travellers and services.',
        ],
        'CORPORATE'=>[
            'label'=>'Corporate',
            'short'=>'Corporate',
            'icon'=>'▥',
            'desc'=>'Corporate/client travel booking using the same service workspaces.',
        ],
    ];
@endphp

<style>
.smart-start-intro{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px}
.smart-start-intro h1{margin:2px 0 5px;font-size:28px}
.smart-start-intro p{margin:0;color:var(--muted);max-width:760px}
.smart-start-shell{max-width:1180px;margin:0 auto}
.smart-start-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 5px 18px rgba(31,54,79,.04)}
.smart-step{display:flex;align-items:center;gap:9px;margin-bottom:10px}
.smart-step-no{width:27px;height:27px;border-radius:999px;background:#1464d2;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900}
.smart-step strong{font-size:12px}.smart-step span{font-size:10px;color:var(--muted);display:block;margin-top:2px}
.booking-type-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}
.booking-type-choice{position:relative;display:block;cursor:pointer}
.booking-type-choice input{position:absolute;opacity:0;pointer-events:none}
.booking-type-box{height:100%;border:1px solid #d9e2ec;border-radius:11px;padding:11px;background:#fafbfd;transition:.15s ease}
.booking-type-choice:hover .booking-type-box{border-color:#9ebde4;background:#f7fbff}
.booking-type-choice input:checked + .booking-type-box{border:2px solid #1464d2;background:#eef6ff;padding:10px;box-shadow:0 0 0 3px rgba(20,100,210,.06)}
.booking-type-icon{width:31px;height:31px;border-radius:9px;background:#fff;border:1px solid #dfe8f2;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:8px}
.booking-type-box strong{display:block;font-size:11px;color:#17243a}
.booking-type-box p{font-size:9px;line-height:1.45;color:var(--muted);margin:4px 0 0}
.smart-divider{height:1px;background:var(--line);margin:16px 0}
.customer-pick{display:grid;grid-template-columns:minmax(0,1fr) 260px;gap:10px;align-items:end}
.customer-pick label{margin:0}
.customer-search-note{font-size:9px;color:var(--muted);margin-top:5px}
.smart-defaults{border:1px solid #dce7f2;background:#f8fbff;border-radius:10px;margin-top:14px;overflow:hidden}
.smart-defaults summary{cursor:pointer;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px;list-style:none}
.smart-defaults summary::-webkit-details-marker{display:none}
.smart-defaults-summary strong{font-size:10px}.smart-defaults-summary span{font-size:9px;color:var(--muted);display:block;margin-top:2px}
.smart-defaults-pill{background:#fff;border:1px solid #dce7f2;border-radius:999px;padding:5px 8px;font-size:9px;font-weight:800;color:#53677d}
.smart-defaults-body{border-top:1px solid #dce7f2;padding:11px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
.smart-start-action{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:16px}
.smart-start-action-note{font-size:10px;color:var(--muted);line-height:1.45}
.smart-start-action-note strong{color:#33475f}
.smart-start-action .btn{min-width:210px}
.smart-selected-summary{border:1px solid #dce7f2;border-radius:10px;padding:10px 12px;background:#fff}
.smart-selected-summary small{display:block;color:var(--muted);font-size:8px;text-transform:uppercase;font-weight:900}
.smart-selected-summary strong{display:block;margin-top:4px;font-size:11px}
@media(max-width:1000px){.booking-type-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:720px){
    .smart-start-intro{display:block}.smart-start-intro .btn{margin-top:10px}
    .booking-type-grid,.customer-pick,.smart-defaults-body{grid-template-columns:1fr}
    .smart-start-action{display:block}.smart-start-action .btn{width:100%;margin-top:10px}
}
</style>

<div class="smart-start-shell">
    <div class="smart-start-intro">
        <div>
            <span class="eyebrow">OPERATIONS · SMART BOOKING START</span>
            <h1>Start a new booking</h1>
            <p>Choose what the client is booking and who the booking is for. ERP creates the draft immediately; passengers, dates, agent, salesperson and service details are completed on the booking workspace.</p>
        </div>
        <a class="btn btn-ghost" href="{{ route('operations.bookings.index') }}">Back to Bookings</a>
    </div>

    <form method="POST" action="{{ route('operations.bookings.store') }}" class="smart-start-card" data-smart-booking-form>
        @csrf

        <div class="smart-step">
            <div class="smart-step-no">1</div>
            <div><strong>What is the client booking?</strong><span>This sets the intended workflow; extra services can still be added later.</span></div>
        </div>

        <div class="booking-type-grid">
            @foreach($bookingTypes as $key=>$type)
                <label class="booking-type-choice">
                    <input type="radio" name="booking_type" value="{{ $key }}" {{ old('booking_type','GENERAL')===$key?'checked':'' }} data-booking-type data-label="{{ $type['label'] }}">
                    <div class="booking-type-box">
                        <div class="booking-type-icon">{{ $type['icon'] }}</div>
                        <strong>{{ $type['label'] }}</strong>
                        <p>{{ $type['desc'] }}</p>
                    </div>
                </label>
            @endforeach
        </div>

        <div class="smart-divider"></div>

        <div class="smart-step">
            <div class="smart-step-no">2</div>
            <div><strong>Select customer</strong><span>Passengers can be selected from Saved / Previous Travellers on the next screen.</span></div>
        </div>

        <div class="customer-pick">
            <label>
                <span>Customer *</span>
                <select name="customer_party_id" required data-customer-select>
                    <option value="">Select customer</option>
                    @foreach($customers as $p)
                        <option value="{{ $p->id }}" {{ (string)old('customer_party_id')===(string)$p->id?'selected':'' }}>
                            {{ $p->code }} · {{ $p->display_name }}
                        </option>
                    @endforeach
                </select>
                <div class="customer-search-note">Choose the customer here; saved travellers linked to this customer will be prioritized on the Booking Workspace.</div>
            </label>

            <div class="smart-selected-summary">
                <small>Booking will start as</small>
                <strong data-selected-type-label>{{ $bookingTypes[old('booking_type','GENERAL')]['label'] ?? 'General / Multi-service' }}</strong>
            </div>
        </div>

        <details class="smart-defaults">
            <summary>
                <div class="smart-defaults-summary">
                    <strong>Automatic booking defaults</strong>
                    <span>Normally you do not need to change these. Full header remains editable after creation.</span>
                </div>
                <span class="smart-defaults-pill">Change defaults</span>
            </summary>
            <div class="smart-defaults-body">
                <label>
                    <span>Booking Date</span>
                    <input type="date" name="booking_date" value="{{ old('booking_date',now()->format('Y-m-d')) }}">
                </label>
                <label>
                    <span>Branch</span>
                    <select name="branch_id">
                        @foreach($branches as $branch)
                            <option value="{{ $branch->id }}" {{ (string)old('branch_id',$defaultBranchId)===(string)$branch->id?'selected':'' }}>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span>Currency</span>
                    <select name="currency_code">
                        @foreach($currencies as $c)
                            <option value="{{ $c->code }}" {{ old('currency_code',$company->base_currency)===$c->code?'selected':'' }}>{{ $c->code }} — {{ $c->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </details>

        <div class="smart-start-action">
            <div class="smart-start-action-note">
                <strong>Added on the next page:</strong> passengers, travel dates, agent/service partner, salesperson, customer reference, notes and all service-specific commercial details.
            </div>
            <button class="btn btn-primary">Create Booking & Continue</button>
        </div>
    </form>
</div>

<script>
(function(){
    const form=document.querySelector('[data-smart-booking-form]');
    if(!form)return;
    const label=form.querySelector('[data-selected-type-label]');
    form.querySelectorAll('[data-booking-type]').forEach(input=>{
        input.addEventListener('change',()=>{
            if(input.checked && label) label.textContent=input.dataset.label||input.value;
        });
    });
})();
</script>
@endsection
