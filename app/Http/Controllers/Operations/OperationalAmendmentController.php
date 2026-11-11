<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\OperationalAmendmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OperationalAmendmentController extends Controller
{
    public function store(Request $request, int $booking, OperationalAmendmentService $amendments): JsonResponse
    {
        $payload = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'changes' => ['nullable', 'array', 'min:1'],
            'changes_json' => ['nullable', 'string'],
            'changes.*.section' => ['required', 'string'],
            'changes.*.field' => ['required', 'string'],
            'changes.*.record_id' => ['nullable', 'integer', 'min:1'],
            'changes.*.old_value' => ['nullable'],
            'changes.*.new_value' => ['nullable'],
        ]);

        $changes = $payload['changes'] ?? [];
        if (!$changes && !empty($payload['changes_json'])) {
            $changes = json_decode((string) $payload['changes_json'], true);
        }
        if (!is_array($changes) || !$changes) {
            throw ValidationException::withMessages(['changes' => 'At least one operational field is required.']);
        }
        $id = $amendments->apply(
            $booking,
            $request->user(),
            (string) $payload['reason'],
            $changes,
            function (object $before, array $changes) use ($booking, $amendments): array {
                $notesChanged = false;
                $notesValue = null;
                foreach ($changes as $change) {
                    if (strtolower((string) ($change['section'] ?? '')) === 'booking' && strtolower((string) ($change['field'] ?? '')) === 'notes') {
                        $notesChanged = true;
                        $notesValue = $change['new_value'] ?? '';
                    }
                }
                $amendments->applyNativeOperationalChanges($booking, $changes);
                $update = ['travel_status' => 'PendingTravel', 'updated_at' => now()];
                if ($notesChanged) $update['notes'] = (string) $notesValue;
                \DB::table('bookings')->where('id', $booking)->update($update);
                return ['travel_status_after' => 'PendingTravel'];
            }
        );

        return response()->json(['ok' => true, 'amendment_id' => $id, 'travel_status' => 'PendingTravel']);
    }
}
