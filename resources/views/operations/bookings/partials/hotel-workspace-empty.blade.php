@include('operations.bookings.partials.hotel-workspace',[
    'hotelService'=>$hotelService,
    'hotelStayNo'=>$hotelStayNo ?? 1,
    'hotelSuggestedCheckIn'=>$hotelSuggestedCheckIn ?? '',
])
