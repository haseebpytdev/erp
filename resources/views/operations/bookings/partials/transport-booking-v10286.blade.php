@php
    $erp10286BookingId = (int) data_get($booking ?? null, 'id', 0);
    $erp10286IsGroupPackage = \App\Services\Operations\GroupPackageOperationalPolicy::isGroupPackage($booking ?? null);
    $erp10286Transport = collect();

    if ($erp10286BookingId && \Illuminate\Support\Facades\Schema::hasTable('booking_transport_segments')) {
        $erp10286Transport = \App\Models\BookingTransportSegment::query()
            ->where('booking_id', $erp10286BookingId)
            ->orderBy('sort_order')
            ->orderBy('transport_date')
            ->get();
    }
@endphp

<section id="erp10286-transport" data-movement-count="{{ $erp10286Transport->count() }}" style="display:none;">
<style>
#erp10286-transport{margin:12px 0 14px;font-family:inherit}
#erp10286-transport *{box-sizing:border-box}
#erp10286-transport .et86t-box{border:1px solid #dfe7f1;border-radius:10px;background:#fff;overflow:hidden}
#erp10286-transport .et86t-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 14px}
#erp10286-transport .et86t-title{font-size:15px;font-weight:800;color:#18253c;margin-bottom:3px}
#erp10286-transport .et86t-sub{font-size:10.5px;color:#718096}
#erp10286-transport .et86t-right{display:flex;align-items:center;gap:7px}
#erp10286-transport .et86t-count{font-size:9.5px;font-weight:800;background:#eaf8ef;color:#278052;padding:4px 8px;border-radius:999px;white-space:nowrap}
#erp10286-transport .et86t-btn{border:0;border-radius:6px;padding:7px 10px;font-size:10px;font-weight:800;cursor:pointer}
#erp10286-transport .primary{background:#1769d2;color:#fff}
#erp10286-transport .light{background:#eef2f7;color:#26364d}
#erp10286-transport .danger{background:#fff0f0;color:#bd3232}
#erp10286-transport .et86t-table-wrap{overflow:auto;border-top:1px solid #edf1f6}
#erp10286-transport table{width:100%;border-collapse:collapse;font-size:10.5px}
#erp10286-transport th{background:#f6f8fb;color:#526176;text-align:left;font-size:9.5px;text-transform:uppercase;padding:7px 8px;white-space:nowrap}
#erp10286-transport td{padding:7px 8px;border-top:1px solid #edf1f6;white-space:nowrap;color:#243149}
#erp10286-transport .et86t-form{display:none;padding:12px 14px;border-top:1px solid #edf1f6;background:#fbfcfe}
#erp10286-transport .et86t-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:8px}
#erp10286-transport .et86t-field{grid-column:span 3}
#erp10286-transport .et86t-field.small{grid-column:span 2}
#erp10286-transport .et86t-field.wide{grid-column:span 4}
#erp10286-transport label{display:block;font-size:9.5px;font-weight:700;color:#536176;margin-bottom:4px}
#erp10286-transport .et86t-input{width:100%;height:32px;border:1px solid #d4deea;border-radius:6px;background:#fff;padding:5px 7px;font:inherit;font-size:10.5px}
#erp10286-transport .et86t-save{display:flex;align-items:end;grid-column:span 2}
#erp10286-transport .et86t-save button{width:100%;height:32px}
#erp10286-transport .et86t-policy{margin:10px 14px 12px;padding:8px 10px;border-radius:7px;background:#eaf8ef;color:#28764d;font-size:10px;font-weight:600}
@media(max-width:950px){
  #erp10286-transport .et86t-field,#erp10286-transport .et86t-field.small,#erp10286-transport .et86t-field.wide{grid-column:span 4}
  #erp10286-transport .et86t-save{grid-column:span 4}
}
@media(max-width:700px){
  #erp10286-transport .et86t-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  #erp10286-transport .et86t-field,#erp10286-transport .et86t-field.small,#erp10286-transport .et86t-field.wide,#erp10286-transport .et86t-save{grid-column:span 1}
  #erp10286-transport .et86t-head{display:block}
  #erp10286-transport .et86t-right{margin-top:8px}
}
</style>

<div class="et86t-box">
    <div class="et86t-head">
        <div>
            <div class="et86t-title">Transport</div>
            <div class="et86t-sub">
                @if($erp10286IsGroupPackage)
                    Included in the Group Package. Enter movement and voucher details only.
                @else
                    Enter transport movement and simple total sale/cost details.
                @endif
            </div>
        </div>
        <div class="et86t-right">
            <span class="et86t-count">{{ $erp10286Transport->count() }} movement(s)</span>
            <button type="button" class="et86t-btn primary" id="erp10286-transport-toggle">+ Add Transport</button>
        </div>
    </div>

    @if($erp10286Transport->isNotEmpty())
    <div class="et86t-table-wrap">
        <table>
            <thead><tr><th>Date/Time</th><th>Pickup</th><th>Drop-off</th><th>Vehicle</th><th>Driver</th><th>Ref</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach($erp10286Transport as $row)
                <tr>
                    <td>{{ optional($row->transport_date)->format('d M Y') ?: '—' }} {{ $row->pickup_time ? \Illuminate\Support\Str::of($row->pickup_time)->substr(0,5) : '' }}</td>
                    <td>{{ $row->pickup_location ?: '—' }}</td>
                    <td>{{ $row->dropoff_location ?: '—' }}</td>
                    <td>{{ $row->vehicle_type ?: '—' }}{{ $row->vehicle_number ? ' · '.$row->vehicle_number : '' }}</td>
                    <td>{{ $row->driver_name ?: '—' }}{{ $row->driver_contact ? ' · '.$row->driver_contact : '' }}</td>
                    <td>{{ $row->provider_reference ?: '—' }}</td>
                    <td>{{ strtoupper($row->status) }}</td>
                    <td>
                        <form method="POST" action="{{ route('operations.bookings.transport-segments.destroy', [$erp10286BookingId, $row->id]) }}" onsubmit="return confirm('Remove this transport movement?')">
                            @csrf @method('DELETE')
                            <button class="et86t-btn danger" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div id="erp10286-transport-form" class="et86t-form">
        <form method="POST" action="{{ route('operations.bookings.transport-segments.store', $erp10286BookingId) }}">
            @csrf
            <div class="et86t-grid">
                <div class="et86t-field small"><label>Date</label><input type="date" name="transport_date" class="et86t-input" required></div>
                <div class="et86t-field small"><label>Pickup Time</label><input type="time" name="pickup_time" class="et86t-input"></div>
                <div class="et86t-field wide"><label>Pickup</label><input name="pickup_location" class="et86t-input" required></div>
                <div class="et86t-field wide"><label>Drop-off</label><input name="dropoff_location" class="et86t-input" required></div>

                <div class="et86t-field"><label>Vehicle Type</label><input name="vehicle_type" class="et86t-input" placeholder="Bus / Coaster / Car"></div>
                <div class="et86t-field"><label>Vehicle No.</label><input name="vehicle_number" class="et86t-input"></div>
                <div class="et86t-field"><label>Driver</label><input name="driver_name" class="et86t-input"></div>
                <div class="et86t-field"><label>Driver Contact</label><input name="driver_contact" class="et86t-input"></div>

                <div class="et86t-field"><label>BRN / Provider Ref</label><input name="provider_reference" class="et86t-input"></div>
                <div class="et86t-field small"><label>Pax</label><input type="number" min="1" name="passengers_count" class="et86t-input" value="{{ max(1, (int) data_get($booking ?? null, 'passengers_count', 1)) }}"></div>
                <div class="et86t-field"><label>Status</label>
                    <select name="status" class="et86t-input">
                        <option value="requested">Requested</option>
                        <option value="booked">Booked</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="et86t-field wide"><label>Voucher Notes</label><input name="voucher_notes" class="et86t-input"></div>

                @unless($erp10286IsGroupPackage)
                    <div class="et86t-field small"><label>Sale Currency</label><input name="sale_currency_code" class="et86t-input" value="PKR"></div>
                    <div class="et86t-field"><label>Total Sale</label><input type="number" step="0.01" min="0" name="sale_amount" class="et86t-input" value="0"></div>
                    <div class="et86t-field small"><label>Cost Currency</label><input name="supplier_currency_code" class="et86t-input" value="SAR"></div>
                    <div class="et86t-field"><label>Total Supplier Cost</label><input type="number" step="0.01" min="0" name="supplier_amount" class="et86t-input" value="0"></div>
                    <div class="et86t-field small"><label>FX to PKR</label><input type="number" step="0.000001" min="0" name="fx_rate_to_pkr" class="et86t-input"></div>
                @endunless

                <div class="et86t-save"><button type="submit" class="et86t-btn primary">Save Transport</button></div>
            </div>
        </form>
    </div>

    @if($erp10286IsGroupPackage)
        <div class="et86t-policy">Included in Package • No separate transport accounting for this booking.</div>
    @endif
</div>

<script>
(function(){
    const root = document.getElementById('erp10286-transport');
    const form = document.getElementById('erp10286-transport-form');
    const toggle = document.getElementById('erp10286-transport-toggle');
    if (!root || !form || !toggle) return;

    toggle.addEventListener('click', function(){
        const open = form.style.display === 'block';
        form.style.display = open ? 'none' : 'block';
        toggle.textContent = open ? '+ Add Transport' : 'Close';
        if (!open) form.querySelector('input,select,textarea')?.focus();
    });

    const norm = t => (t || '').replace(/\s+/g,' ').trim().toLowerCase();

    function exactNode(text){
        const wanted = norm(text);
        return Array.from(document.querySelectorAll('h1,h2,h3,h4,h5,h6,.card-title,strong,span,div'))
            .filter(el => !root.contains(el) && el.children.length <= 4)
            .find(el => norm(el.textContent) === wanted) || null;
    }

    function smallestCardFor(node){
        if (!node) return null;
        const direct = node.closest('.card');
        if (direct) return direct;
        let current = node.parentElement;
        for (let i=0; i<5 && current; i++, current=current.parentElement) {
            const text = norm(current.textContent);
            if (text.length < 3500 && current.parentElement) return current;
        }
        return null;
    }

    function relocate(){
        if (root.dataset.relocated === '1') return true;

        // Canonical position: immediately before Other Services / Products.
        const other = exactNode('Other Services / Products');
        const otherCard = smallestCardFor(other);
        if (otherCard?.parentElement) {
            otherCard.parentElement.insertBefore(root, otherCard);
            root.dataset.relocated = '1';
            root.style.display = 'block';
            if (Number(root.dataset.movementCount || 0) === 0 && root.dataset.autoOpened !== '1') {
                root.dataset.autoOpened = '1';
                form.style.display = 'block';
                toggle.textContent = 'Close';
            }
            return true;
        }

        // Fallback: after the LAST hotel-related card, not the Hotel Stays header.
        const hotelCards = Array.from(document.querySelectorAll('.card')).filter(card => {
            if (root.contains(card)) return false;
            const text = norm(card.textContent);
            return text.includes('hotel stay') || text.includes('hotel accommodation') || text.includes('accommodation document readiness');
        });
        const lastHotel = hotelCards.length ? hotelCards[hotelCards.length - 1] : null;
        if (lastHotel?.parentElement) {
            lastHotel.insertAdjacentElement('afterend', root);
            root.dataset.relocated = '1';
            root.style.display = 'block';
            if (Number(root.dataset.movementCount || 0) === 0 && root.dataset.autoOpened !== '1') {
                root.dataset.autoOpened = '1';
                form.style.display = 'block';
                toggle.textContent = 'Close';
            }
            return true;
        }

        // Important: do NOT show at the appended Blade location. Remaining
        // hidden is safer than rendering Transport above the booking header.
        root.style.display = 'none';
        return false;
    }

    function boot(){
        relocate();
        [100,300,700,1500,2500].forEach(ms => setTimeout(relocate, ms));
        const observer = new MutationObserver(() => {
            if (relocate()) observer.disconnect();
        });
        observer.observe(document.body, {childList:true,subtree:true});
        setTimeout(() => observer.disconnect(), 6000);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
    else boot();
})();
</script>
</section>
