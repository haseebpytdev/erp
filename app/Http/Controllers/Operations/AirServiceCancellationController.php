<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\AirServiceCancellationService;
use App\Services\Operations\AirServiceRemovalAuthorization;
use App\Services\Operations\AirServiceRemovalConcurrencyContract;
use App\Services\Operations\AirServiceRemovalEligibilityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Disconnected command boundary for the future Air cancellation workflow.
 * No route currently references this controller; ordinary Air Save remains separate.
 */
final class AirServiceCancellationController extends Controller
{
    public function __construct(
        private readonly AirServiceRemovalAuthorization $authorization,
        private readonly AirServiceRemovalConcurrencyContract $concurrency,
        private readonly AirServiceRemovalEligibilityResolver $eligibility,
        private readonly AirServiceCancellationService $cancellation,
    ) {
    }

    public function preview(Request $request, int $booking, int $service): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) return response()->json(['eligible' => false, 'blocking_reason' => 'AUTHENTICATION_REQUIRED'], 401);

        $auth = $this->authorization->authorize($actor, $booking, $service);
        if (empty($auth['authorized'])) return response()->json($this->blockedPreview((string) ($auth['code'] ?? 'UNAUTHORIZED')), 403);

        $financial = $this->eligibility->resolve($booking, $service, $actor);
        if (empty($financial['eligible'])) return response()->json($this->blockedPreview((string) ($financial['code'] ?? 'UNKNOWN_DEPENDENCY')));

        $ticket = $this->cancellation->previewOperationalState($service);
        if (empty($ticket['ok'])) return response()->json($this->blockedPreview((string) ($ticket['code'] ?? 'UNKNOWN_TICKET_STATE')));

        $snapshot = $this->concurrency->capture($booking, $service);
        if (($snapshot['code'] ?? null) !== 'SNAPSHOT' || empty($snapshot['token'])) {
            return response()->json($this->blockedPreview('UNKNOWN_SERVICE_STATE'));
        }

        return response()->json([
            'eligible' => true,
            'blocking_reason' => null,
            'concurrency_token' => (string) $snapshot['token'],
            'booking_id' => $booking,
            'booking_service_id' => $service,
        ]);
    }

    public function confirm(Request $request, int $booking, int $service): JsonResponse
    {
        $actor = $request->user();
        if (! $actor) return response()->json(['outcome' => 'blocked', 'code' => 'AUTHENTICATION_REQUIRED'], 401);

        $data = $request->validate([
            'booking_id' => ['sometimes', 'integer', 'in:' . $booking],
            'booking_service_id' => ['sometimes', 'integer', 'in:' . $service],
            'removal_intent' => ['required', 'string', 'in:AIR_SERVICE_CANCELLATION'],
            'confirmation_acknowledged' => ['required', 'accepted'],
            'concurrency_token' => ['required', 'string', 'max:512'],
            'cancellation_reason' => ['required', 'string', 'max:500'],
        ]);

        $auth = $this->authorization->authorize($actor, $booking, $service);
        if (empty($auth['authorized'])) return response()->json(['outcome' => 'blocked', 'code' => (string) ($auth['code'] ?? 'UNAUTHORIZED')], 403);

        $result = $this->cancellation->cancel([
            'booking_id' => $booking,
            'booking_service_id' => $service,
            'removal_intent' => $data['removal_intent'],
            'confirmation_acknowledged' => (bool) $data['confirmation_acknowledged'],
            'concurrency_token' => $data['concurrency_token'],
            'cancellation_reason' => trim($data['cancellation_reason']),
        ], $actor);

        if (($result['outcome'] ?? null) === 'SUCCESS') {
            return response()->json([
                'outcome' => 'success',
                'code' => $result['code'],
                'booking_id' => $booking,
                'booking_service_id' => $service,
                'status' => $result['status'] ?? null,
            ]);
        }
        if (($result['outcome'] ?? null) === 'UNKNOWN') {
            return response()->json([
                'outcome' => 'unknown',
                'code' => 'TRANSACTION_OUTCOME_UNKNOWN',
                'message' => 'Cancellation outcome could not be confirmed. Verify the booking before retrying.',
                'booking_id' => $booking,
                'booking_service_id' => $service,
            ], 409);
        }

        return response()->json([
            'outcome' => 'blocked',
            'code' => (string) ($result['code'] ?? 'CANCELLATION_BLOCKED'),
            'booking_id' => $booking,
            'booking_service_id' => $service,
        ], 409);
    }

    private function blockedPreview(string $code): array
    {
        return ['eligible' => false, 'blocking_reason' => $code];
    }
}
