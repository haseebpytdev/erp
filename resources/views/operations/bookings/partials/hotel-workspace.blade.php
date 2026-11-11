@php
    $hotelDetail=$hotelDetailsByService->get($hotelService->id);
    $roomPlan=$hotelDetail?->roomsList?->first();

    $hotelAgentCommission=$hotelDetail?->commissions?->first(
        fn($c)=>$c->commission_type==='AGENT' && $c->status!=='CANCELLED'
    );
    $hotelStaffCommission=$hotelDetail?->commissions?->first(
        fn($c)=>$c->commission_type==='SALESPERSON' && $c->status!=='CANCELLED'
    );

    $activeBookingPassengerCount=$activePassengers->count();
    $totalCapacity=(int)($roomPlan?->total_capacity ?? 0);

    $customerTotal=(float)($hotelDetail?->customer_sell_total ?? 0);
    $supplierTotal=(float)($hotelDetail?->net_supplier_cost ?? 0);
    $agentCommission=(float)($hotelAgentCommission?->commission_amount ?? 0);
    $staffCommission=(float)($hotelStaffCommission?->commission_amount ?? 0);
    $grossMargin=$customerTotal-$supplierTotal;
    $netContribution=$grossMargin-$agentCommission-$staffCommission;
    $netMargin=$customerTotal>0 ? ($netContribution/$customerTotal)*100 : 0;

    $stayNo=(int)($hotelStayNo ?? 1);
    $suggestedCheckIn=(string)($hotelSuggestedCheckIn ?? '');
    $effectiveCheckIn=$hotelDetail?->check_in?->format('Y-m-d') ?: $suggestedCheckIn;
    $autoCheckIn=!$hotelDetail && $suggestedCheckIn!=='';

    $location=$hotelDetail
        ? $hotelLocationByHotelId->get((int)$hotelDetail->hotel_id,[])
        : [];

    $roomSummary=$roomPlan
        ? strtoupper((string)$roomPlan->room_type).' × '.max(1,(int)$roomPlan->room_count).' · '.$totalCapacity.' Pax'
        : 'Room Plan not entered';
@endphp

<div class="compact-hotel-card smart-two-line-hotel" data-compact-hotel>
    <div class="compact-hotel-summary">
        <div class="hotel-stay-number">{{ str_pad((string)$stayNo,2,'0',STR_PAD_LEFT) }}</div>

        <div class="compact-hotel-title">
            <strong>Hotel Stay {{ str_pad((string)$stayNo,2,'0',STR_PAD_LEFT) }} · {{ $hotelDetail?->hotel?->name ?: 'Hotel Accommodation' }}</strong>
            <span>
                {{ $hotelDetail?->hotel?->city ?: 'Hotel stay not completed' }}
                @if($hotelDetail?->check_in)
                    · {{ $hotelDetail->check_in->format('d M') }} → {{ $hotelDetail->check_out?->format('d M Y') }}
                    · {{ $hotelDetail->nights }} night(s)
                @endif
            </span>
        </div>

        <div class="compact-hotel-summary-stat">
            <small>Room Plan</small>
            <strong>{{ $roomSummary }}</strong>
        </div>

        <div class="compact-hotel-summary-stat">
            <small>Customer</small>
            <strong>{{ $booking->currency_code }} {{ number_format($customerTotal,0) }}</strong>
        </div>

        <div class="compact-hotel-summary-stat">
            <small>Supplier</small>
            <strong>{{ $booking->currency_code }} {{ number_format($supplierTotal,0) }}</strong>
        </div>

        <div class="compact-hotel-summary-stat margin">
            <small>Net Margin</small>
            <strong>{{ $booking->currency_code }} {{ number_format($netContribution,0) }}</strong>
        </div>

        <div class="hotel-stay-actions">
            <span class="status-pill {{ $hotelDetail?->hotel_status==='CONFIRMED'?'active':'open' }}">
                {{ $hotelDetail?->hotel_status ?: 'NOT ENTERED' }}
            </span>

            @if($hotelDetail)
                <a class="btn btn-secondary btn-small hotel-voucher-link"
                   href="{{ route('operations.bookings.travel-voucher.show',['booking'=>$booking,'hotel_service_id'=>$hotelService->id]) }}"
                   target="_blank">
                    Voucher
                </a>
            @endif

            @if($booking->isEditable() && auth()->user()->hasPermission('booking_services.manage'))
                <form method="POST"
                      action="{{ route('operations.bookings.services.cancel',[$booking,$hotelService]) }}"
                      onsubmit="return confirm('Remove Hotel Stay {{ str_pad((string)$stayNo,2,'0',STR_PAD_LEFT) }} from this booking? The historical service record will be retained as CANCELLED.');">
                    @csrf
                    <button type="submit"
                            class="btn btn-danger btn-small hotel-stay-remove"
                            title="Remove this Hotel Stay from the active booking">
                        Remove
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if($booking->isEditable() && auth()->user()->hasPermission('service_details.manage'))
    <details class="compact-hotel-editor hotel-stay-editor" {{ !$hotelDetail ? 'open' : '' }}>
        <summary>
            <span>
                {{ $hotelDetail
                    ? 'Hotel Stay '.str_pad((string)$stayNo,2,'0',STR_PAD_LEFT).' · Saved'
                    : 'Complete Hotel Stay '.str_pad((string)$stayNo,2,'0',STR_PAD_LEFT)
                }}
            </span>
            <span>{{ $hotelDetail ? 'Edit' : '⌄' }}</span>
        </summary>

        <form method="POST"
              action="{{ route('operations.bookings.services.hotel-workspace.update',[$booking,$hotelService]) }}"
              data-smart-hotel-form>
            @csrf
            @method('PUT')

            {{-- LINE 1: Hotel identity + backend location + status --}}
            <div class="hotel-identity-row">
                <div class="hotel-source-cell">
                    <label>
                        <span>Hotel *</span>

                        <div class="existing-hotel-source" data-existing-hotel-source>
                            <div class="hotel-source-line">
                                <select name="hotel_id" data-existing-hotel>
                                    <option value="">Select existing hotel</option>
                                    @foreach($hotels as $hotel)
                                        @php
                                            $hotelLocation=$hotelLocationByHotelId->get((int)$hotel->id,[]);
                                        @endphp
                                        <option value="{{ $hotel->id }}"
                                                data-default-vendor="{{ $hotel->default_vendor_party_id }}"
                                                data-city-name="{{ $hotelLocation['city'] ?? $hotel->city }}"
                                                data-city-code="{{ $hotelLocation['city_code'] ?? '' }}"
                                                data-country-code="{{ $hotelLocation['country_code'] ?? $hotel->country_code }}"
                                                {{ (int)($hotelDetail?->hotel_id)===(int)$hotel->id?'selected':'' }}>
                                            {{ $hotel->name }} · {{ $hotel->city }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-secondary btn-small" data-toggle-new-hotel>+ New Hotel</button>
                            </div>
                        </div>

                        <div class="new-hotel-source" data-new-hotel-source hidden>
                            <div class="hotel-source-line">
                                <input name="new_hotel_name"
                                       data-new-hotel-name
                                       placeholder="Enter new hotel name"
                                       disabled>
                                <button type="button" class="btn btn-ghost btn-small" data-use-existing-hotel>Use Existing</button>
                            </div>
                        </div>
                    </label>
                </div>

                <div class="existing-city-source" data-existing-city-source>
                    <div class="hotel-auto-box">
                        <span>City <em>Backend</em></span>
                        <strong data-existing-city-name>{{ $location['city'] ?? $hotelDetail?->hotel?->city ?: '—' }}</strong>
                    </div>
                </div>

                <div class="new-city-source" data-new-city-source hidden>
                    <label>
                        <span>City *</span>
                        <select name="new_hotel_city_code" data-new-hotel-city-code disabled>
                            <option value="">Select city</option>
                            @foreach($travelCities as $city)
                                <option value="{{ $city['code'] }}"
                                        data-city="{{ $city['city'] }}"
                                        data-country="{{ $city['country_code'] }}">
                                    {{ $city['city'] }} · {{ $city['code'] }} · {{ $city['country_code'] }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="hotel-auto-box">
                    <span>City Code <em>Auto</em></span>
                    <strong data-city-code-output>{{ $location['city_code'] ?? '—' }}</strong>
                </div>

                <div class="hotel-auto-box">
                    <span>Country Code <em>Auto</em></span>
                    <strong data-country-code-output>{{ $location['country_code'] ?? $hotelDetail?->hotel?->country_code ?: '—' }}</strong>
                </div>

                <label>
                    <span>Status *</span>
                    <select name="hotel_status" required>
                        @foreach(['REQUESTED','CONFIRMED','CANCELLED'] as $v)
                            <option value="{{ $v }}" {{ ($hotelDetail?->hotel_status ?: 'REQUESTED')===$v?'selected':'' }}>
                                {{ $v }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span>Confirmation / Voucher No.</span>
                    <input name="confirmation_number"
                           value="{{ $hotelDetail?->confirmation_number }}"
                           placeholder="Optional confirmation">
                </label>
            </div>

            {{-- LINE 2: Dates + one Room Plan --}}
            <div class="hotel-stay-room-row">
                <label class="smart-date-field">
                    <span>
                        Check-in *
                        @if($autoCheckIn)<em>Auto from previous stay</em>@endif
                    </span>
                    <div class="smart-date-control">
                        <input type="date"
                               name="check_in"
                               required
                               value="{{ $effectiveCheckIn }}"
                               data-hotel-checkin>
                    </div>
                </label>

                <label class="smart-date-field">
                    <span>Check-out *</span>
                    <div class="smart-date-control">
                        <input type="date"
                               name="check_out"
                               required
                               value="{{ $hotelDetail?->check_out?->format('Y-m-d') }}"
                               data-hotel-checkout>
                    </div>
                </label>

                <div class="hotel-auto-box hotel-nights-box">
                    <span>Nights <em>Auto</em></span>
                    <strong data-hotel-nights>{{ $hotelDetail?->nights ?: 0 }}</strong>
                </div>

                <label>
                    <span>Room Type *</span>
                    <select name="room_type" required data-room-type>
                        @foreach([
                            'SINGLE'=>'Single',
                            'DOUBLE'=>'Double',
                            'TRIPLE'=>'Triple',
                            'QUAD'=>'Quad',
                            'SHARING'=>'Sharing'
                        ] as $k=>$v)
                            <option value="{{ $k }}"
                                    {{ strtoupper((string)($roomPlan?->room_type ?: 'DOUBLE'))===$k?'selected':'' }}>
                                {{ $v }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span>No. of Rooms *</span>
                    <input type="number"
                           min="1"
                           max="100"
                           name="room_count"
                           value="{{ max(1,(int)($roomPlan?->room_count ?? 1)) }}"
                           required
                           data-room-count>
                </label>

                <label class="sharing-capacity-wrap" data-sharing-wrap
                       {{ strtoupper((string)($roomPlan?->room_type ?: 'DOUBLE'))==='SHARING'?'':'hidden' }}>
                    <span>Sharing Pax / Room</span>
                    <input type="number"
                           min="1"
                           max="50"
                           name="sharing_capacity"
                           value="{{ strtoupper((string)($roomPlan?->room_type ?: 'DOUBLE'))==='SHARING'
                               ? max(1,(int)($roomPlan?->capacity_per_room ?? 1))
                               : ''
                           }}"
                           data-sharing-capacity>
                </label>

                <div class="hotel-auto-box">
                    <span>Capacity <em>Auto</em></span>
                    <strong data-room-capacity>{{ $totalCapacity ?: 2 }} Pax</strong>
                </div>

                <label>
                    <span>Meal Plan</span>
                    <select name="meal_plan">
                        @foreach([
                            ''=>'—',
                            'RO'=>'RO · Room Only',
                            'BB'=>'BB · Bed & Breakfast',
                            'HB'=>'HB · Half Board',
                            'FB'=>'FB · Full Board'
                        ] as $k=>$v)
                            <option value="{{ $k }}" {{ (string)($roomPlan?->meal_plan ?? '')===(string)$k?'selected':'' }}>
                                {{ $v }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="hotel-room-note">
                    <span>Notes</span>
                    <input name="room_notes"
                           value="{{ $roomPlan?->notes }}"
                           placeholder="Optional note">
                </label>
            </div>

            <div class="compact-capacity-bar"
                 data-capacity-bar
                 data-booking-passengers="{{ $activeBookingPassengerCount }}">
                <span>Total Capacity: <strong data-total-capacity>{{ $totalCapacity ?: 2 }}</strong> Pax</span>
                <span>Booking Passengers: <strong>{{ $activeBookingPassengerCount }}</strong> Pax</span>
                <strong data-capacity-status class="{{ ($totalCapacity ?: 2) >= $activeBookingPassengerCount ? 'ok':'bad' }}">
                    {{ ($totalCapacity ?: 2) >= $activeBookingPassengerCount
                        ? '✓ Sufficient Capacity'
                        : 'Capacity Short'
                    }}
                </strong>
            </div>

            {{-- COMMERCIALS: one compact line --}}
            <div class="single-commercial-title">
                <strong>Commercials</strong>
            </div>

            <div class="hotel-commercial-row">
                <label>
                    <span>Customer Price *</span>
                    <input type="number"
                           min="0"
                           step="0.01"
                           name="customer_price"
                           value="{{ $hotelDetail?->customer_base_total ?? 0 }}"
                           required
                           data-customer-price>
                </label>

                <label>
                    <span>Discount Type</span>
                    <select name="customer_discount_type" data-discount-type>
                        <option value="NONE" {{ (float)($hotelDetail?->customer_discount ?? 0)<=0?'selected':'' }}>None</option>
                        <option value="FIXED" {{ (float)($hotelDetail?->customer_discount ?? 0)>0 && $hotelDetail?->customer_discount_type==='FIXED'?'selected':'' }}>Fixed</option>
                        <option value="PERCENT" {{ $hotelDetail?->customer_discount_type==='PERCENT'?'selected':'' }}>%</option>
                    </select>
                </label>

                <label>
                    <span>Discount Value</span>
                    <input type="number"
                           min="0"
                           step="0.01"
                           name="customer_discount_value"
                           value="{{ $hotelDetail?->customer_discount_value ?? 0 }}"
                           data-discount-value>
                </label>

                <label>
                    <span>Vendor / Supplier</span>
                    <select name="supplier_vendor_party_id" data-hotel-vendor>
                        <option value="">Select supplier</option>
                        @foreach($vendors as $vendor)
                            <option value="{{ $vendor->id }}"
                                    {{ (int)($hotelDetail?->supplier_vendor_party_id)===(int)$vendor->id?'selected':'' }}>
                                {{ $vendor->display_name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label>
                    <span>Supplier Price *</span>
                    <input type="number"
                           min="0"
                           step="0.01"
                           name="supplier_price"
                           value="{{ $hotelDetail?->net_supplier_cost ?? 0 }}"
                           required
                           data-supplier-price>
                </label>

                <label>
                    <span>Agent Commission</span>
                    <input type="number"
                           min="0"
                           step="0.01"
                           name="agent_commission"
                           value="{{ $hotelAgentCommission?->commission_amount ?? 0 }}"
                           required
                           data-agent-commission>
                </label>

                <label>
                    <span>Salesperson Commission</span>
                    <input type="number"
                           min="0"
                           step="0.01"
                           name="salesperson_commission"
                           value="{{ $hotelStaffCommission?->commission_amount ?? 0 }}"
                           required
                           data-staff-commission>
                </label>
            </div>

            <input type="hidden"
                   name="agent_party_id"
                   value="{{ $hotelAgentCommission?->agent_party_id ?: $booking->agent_party_id }}">
            <input type="hidden"
                   name="salesperson_staff_id"
                   value="{{ $hotelStaffCommission?->salesperson_staff_id ?: $booking->salesperson_staff_id }}">

            <div class="compact-profit-bar">
                <div>
                    <small>Customer Net</small>
                    <strong>{{ $booking->currency_code }} <span data-customer-net>{{ number_format($customerTotal,2) }}</span></strong>
                </div>
                <div>
                    <small>Supplier Price</small>
                    <strong>{{ $booking->currency_code }} <span data-supplier-total>{{ number_format($supplierTotal,2) }}</span></strong>
                </div>
                <div>
                    <small>Net Contribution</small>
                    <strong>{{ $booking->currency_code }} <span data-net-contribution>{{ number_format($netContribution,2) }}</span></strong>
                </div>
                <div>
                    <small>Net Margin %</small>
                    <strong><span data-net-margin>{{ number_format($netMargin,2) }}</span>%</strong>
                </div>
            </div>

            <div class="compact-hotel-footer">
                <label>
                    <span>Special Requests / Notes</span>
                    <input name="special_requests"
                           value="{{ $hotelDetail?->special_requests }}"
                           placeholder="Optional hotel / room request">
                </label>

                <button class="btn btn-success">Save Hotel Stay</button>
            </div>
        </form>
    </details>
    @endif
</div>
