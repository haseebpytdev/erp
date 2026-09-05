<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\ExistingErpActionResolver;
use App\Services\Operations\GroupUmrahWorkflowService;
use App\Services\Operations\GroupUmrahEditAuthority;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GroupUmrahWorkflowController extends Controller
{
    public function __construct(
        private readonly GroupUmrahWorkflowService $workflow,
        private readonly ExistingErpActionResolver $actions,
        private readonly GroupUmrahEditAuthority $editAuthority,
    ) {}

    public function transition(Request $request, int $booking, string $action): JsonResponse
    {
        abort_unless(
            Schema::hasTable('bookings') && DB::table('bookings')->where('id', $booking)->exists(),
            404
        );

        try {
            if ($action === 'reopen-editing' && ! $this->editAuthority->canReopen($request->user())) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Only an Admin or Super Admin can reopen a locked Group Umrah booking for editing.',
                ], 403);
            }

            $snapshot = $this->workflow->transition($booking, $action, $request->user()?->id);
            $snapshot['can_reopen'] = (bool) ($snapshot['editing_locked'] ?? false)
                && $this->editAuthority->canReopen($request->user());

            return response()->json([
                'ok' => true,
                'message' => $this->message($action),
                'workflow' => $snapshot,
                'actions' => $this->actions->links($booking),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'ok' => false,
                'message' => 'The workflow action could not be completed. No accounting data was changed.',
            ], 500);
        }
    }

    private function message(string $action): string
    {
        return match ($action) {
            'confirm' => 'Group Umrah booking confirmed. Voucher approval and accounting are now available.',
            'voucher-submit' => 'Voucher submitted for approval.',
            'voucher-approve' => 'Voucher approved.',
            'voucher-issue' => 'Voucher marked as issued.',
            'reopen-editing' => 'Group Umrah reopened for editing. Voucher approval is now required again after changes.',
            default => 'Workflow updated.',
        };
    }
}
