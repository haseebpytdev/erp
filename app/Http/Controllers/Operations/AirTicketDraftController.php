<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\AirTicketEntryDraft;
use App\Models\Booking;
use App\Models\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AirTicketDraftController extends Controller
{
    public function store(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        abort_unless((int) $service->booking_id === (int) $booking->id, 404);

        $payload = $request->except(['_token', '_method', '_autosave']);
        $payload['_air_ticket_batch_form'] = 1;
        $payload['_air_ticket_batch_service_id'] = (int) $service->id;

        $draft = AirTicketEntryDraft::query()->updateOrCreate(
            ['booking_service_id' => $service->id],
            [
                'booking_id' => $booking->id,
                'payload' => $payload,
                'updated_by' => auth()->id(),
            ]
        );

        return response()->json([
            'saved' => true,
            'saved_at' => optional($draft->updated_at)->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, Booking $booking, BookingService $service): JsonResponse
    {
        abort_unless((int) $service->booking_id === (int) $booking->id, 404);

        AirTicketEntryDraft::query()
            ->where('booking_id', $booking->id)
            ->where('booking_service_id', $service->id)
            ->delete();

        return response()->json(['cleared' => true]);
    }
}
