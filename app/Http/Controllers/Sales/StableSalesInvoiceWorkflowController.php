<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Sales\AirTicketInvoiceCommercialSyncService;
use App\Services\Sales\NativeSalesInvoiceWorkflowExecutor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ERP-11.3.76
 *
 * Stable Draft -> Pending Approval -> Approved -> Posted workflow boundary.
 */
final class StableSalesInvoiceWorkflowController extends Controller
{
    public function __construct(
        private readonly NativeSalesInvoiceWorkflowExecutor $executor,
        private readonly AirTicketInvoiceCommercialSyncService $airSync,
        private readonly NativeSalesInvoiceInspector $invoices,
    ) {}

    public function submit(
        Request $request
    ): RedirectResponse {
        return $this->transition(
            $request,
            $this->invoiceId($request),
            'submit'
        );
    }

    public function approve(
        Request $request
    ): RedirectResponse {
        return $this->transition(
            $request,
            $this->invoiceId($request),
            'approve'
        );
    }

    public function post(
        Request $request
    ): RedirectResponse {
        return $this->transition(
            $request,
            $this->invoiceId($request),
            'post'
        );
    }


    private function invoiceId(
        Request $request
    ): int {
        foreach ([
            'invoice',
            'sales_invoice',
            'salesInvoice',
            'id',
        ] as $name) {
            $value = $request->route(
                $name
            );

            if (
                is_object($value)
                && method_exists(
                    $value,
                    'getKey'
                )
            ) {
                $id = (int) $value->getKey();

                if ($id > 0) {
                    return $id;
                }
            }

            if (
                is_scalar($value)
                && (int) $value > 0
            ) {
                return (int) $value;
            }
        }

        foreach (
            (array) (
                $request->route()?->parameters()
                ?? []
            )
            as $value
        ) {
            if (
                is_object($value)
                && method_exists(
                    $value,
                    'getKey'
                )
                && (int) $value->getKey() > 0
            ) {
                return (int) $value->getKey();
            }

            if (
                is_scalar($value)
                && (int) $value > 0
            ) {
                return (int) $value;
            }
        }

        if (
            preg_match(
                '#/sales/invoices/(\d+)/(?:submit|approve|post)(?:[/?#]|$)#i',
                '/'.ltrim(
                    $request->path(),
                    '/'
                ),
                $match
            ) === 1
        ) {
            return (int) (
                $match[1]
                ?? 0
            );
        }

        return 0;
    }

    private function transition(
        Request $request,
        int $invoiceId,
        string $workflow
    ): RedirectResponse {
        if ($invoiceId <= 0) {
            abort(404);
        }

        $before = $this->executor->status(
            $invoiceId
        );

        if (
            $this->alreadyAtOrBeyond(
                $workflow,
                $before
            )
        ) {
            return $this->open(
                $invoiceId,
                $this->successMessage(
                    $workflow,
                    $before
                )
            );
        }

        try {
            $this->prepareCommercialIntegrity(
                $request,
                $invoiceId,
                $workflow
            );

            $this->executor->execute(
                $request,
                $invoiceId,
                $workflow
            );
        } catch (ValidationException $error) {
            return $this->blocked(
                $invoiceId,
                $error->errors()['workflow'][0]
                    ?? $error->errors()['invoice'][0]
                    ?? $error->getMessage()
            );
        } catch (Throwable $error) {
            $afterException = $this->executor->status(
                $invoiceId
            );

            if (
                $this->transitionSucceeded(
                    $workflow,
                    $before,
                    $afterException
                )
            ) {
                Log::warning(
                    'ERP-11.3.76 recovered a completed Sales Invoice workflow transition after native service exception.',
                    [
                        'invoice_id' => $invoiceId,
                        'workflow' => $workflow,
                        'before_status' => $before,
                        'after_status' => $afterException,
                        'exception' => get_class($error),
                        'message' => $error->getMessage(),
                    ]
                );

                return $this->open(
                    $invoiceId,
                    $this->successMessage(
                        $workflow,
                        $afterException
                    )
                );
            }

            Log::error(
                'ERP-11.3.76 native Sales Invoice workflow service failed.',
                [
                    'invoice_id' => $invoiceId,
                    'workflow' => $workflow,
                    'before_status' => $before,
                    'after_status' => $afterException,
                    'exception' => get_class($error),
                    'message' => $error->getMessage(),
                    'file' => basename($error->getFile()),
                    'line' => $error->getLine(),
                ]
            );

            return $this->blocked(
                $invoiceId,
                'The native Sales Invoice '
                    .$this->actionLabel($workflow)
                    .' operation failed: '
                    .mb_substr(
                        $error->getMessage(),
                        0,
                        700
                    )
            );
        }

        $after = $this->executor->status(
            $invoiceId
        );

        if (
            ! $this->transitionSucceeded(
                $workflow,
                $before,
                $after
            )
        ) {
            Log::warning(
                'ERP-11.3.76 rejected a silent Sales Invoice workflow reload because status did not transition.',
                [
                    'invoice_id' => $invoiceId,
                    'workflow' => $workflow,
                    'before_status' => $before,
                    'after_status' => $after,
                ]
            );

            $message = 'The native Sales Invoice '
                .$this->actionLabel($workflow)
                .' operation returned without changing the invoice status.'
                .' [Before: '
                .($before !== '' ? $before : 'unknown')
                .'; After: '
                .($after !== '' ? $after : 'unknown')
                .']';

            return $this->blocked(
                $invoiceId,
                $message
            );
        }

        return $this->open(
            $invoiceId,
            $this->successMessage(
                $workflow,
                $after
            )
        );
    }

    private function prepareCommercialIntegrity(
        Request $request,
        int $invoiceId,
        string $workflow
    ): void {
        try {
            if (
                ! $this->airSync->supports(
                    $invoiceId
                )
            ) {
                return;
            }

            $snapshot = $this->airSync
                ->snapshot(
                    $invoiceId
                );

            $sourceTotal = (float) (
                $snapshot['total']
                ?? 0
            );

            if ($sourceTotal <= 0) {
                throw ValidationException::withMessages([
                    'workflow' => 'Air Ticket customer sale is PKR 0.00. Update the booking ticket pricing before continuing the invoice workflow.',
                ]);
            }

            $needsSync = (bool) (
                $snapshot['needs_sync']
                ?? false
            );

            if (
                $workflow === 'submit'
                && $needsSync
            ) {
                $this->airSync->sync(
                    $invoiceId,
                    $request
                );

                $snapshot = $this->airSync
                    ->snapshot(
                        $invoiceId
                    );

                if (
                    ! ($snapshot['supported'] ?? false)
                    || (bool) (
                        $snapshot['needs_sync']
                        ?? true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'workflow' => 'Air Ticket commercial values could not be synchronized. Keep the invoice Draft and review the booking pricing.',
                    ]);
                }
            }

            if (
                in_array(
                    $workflow,
                    ['approve', 'post'],
                    true
                )
                && $needsSync
            ) {
                throw ValidationException::withMessages([
                    'workflow' => 'This Air Ticket invoice is not commercially synchronized and cannot continue the workflow.',
                ]);
            }

        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning(
                'ERP-11.3.76 could not prepare Air Ticket commercial workflow integrity.',
                [
                    'invoice_id' => $invoiceId,
                    'workflow' => $workflow,
                    'message' => $error->getMessage(),
                ]
            );

            throw ValidationException::withMessages([
                'workflow' => 'Air Ticket commercial workflow validation failed: '
                    .mb_substr(
                        $error->getMessage(),
                        0,
                        500
                    ),
            ]);
        }
    }

    private function transitionSucceeded(
        string $workflow,
        string $before,
        string $after
    ): bool {
        if ($after === '') {
            return false;
        }

        if (
            $after !== $before
            && $this->alreadyAtOrBeyond(
                $workflow,
                $after
            )
        ) {
            return true;
        }

        return false;
    }

    private function alreadyAtOrBeyond(
        string $workflow,
        string $status
    ): bool {
        return match ($workflow) {
            'submit' => in_array(
                $status,
                [
                    'pending',
                    'pending_approval',
                    'pendingapproval',
                    'submitted',
                    'pending_review',
                    'awaiting_approval',
                    'approved',
                    'authorized',
                    'authorised',
                    'posted',
                    'posted_to_gl',
                    'final',
                    'finalized',
                    'completed',
                ],
                true
            ),
            'approve' => in_array(
                $status,
                [
                    'approved',
                    'authorized',
                    'authorised',
                    'posted',
                    'posted_to_gl',
                    'final',
                    'finalized',
                    'completed',
                ],
                true
            ),
            'post' => in_array(
                $status,
                [
                    'posted',
                    'posted_to_gl',
                    'final',
                    'finalized',
                    'completed',
                ],
                true
            ),
            default => false,
        };
    }

    private function successMessage(
        string $workflow,
        string $status
    ): string {
        return match ($workflow) {
            'submit' => 'Sales Invoice submitted for approval successfully.',
            'approve' => 'Sales Invoice approved successfully.',
            'post' => 'Sales Invoice posted to accounting successfully.',
            default => 'Sales Invoice workflow updated successfully.',
        };
    }

    private function actionLabel(
        string $workflow
    ): string {
        return match ($workflow) {
            'submit' => 'Submit for Approval',
            'approve' => 'Approve',
            'post' => 'Post',
            default => ucfirst($workflow),
        };
    }

    private function open(
        int $invoiceId,
        string $success
    ): RedirectResponse {
        $url = $this->invoices
            ->nativeInvoiceUrl(
                $invoiceId
            );

        if (
            ! is_string($url)
            || trim($url) === ''
        ) {
            $url = url(
                '/sales/invoices/'.$invoiceId
            );
        }

        return redirect()
            ->to($url)
            ->with(
                'success',
                $success
            );
    }

    private function blocked(
        int $invoiceId,
        string $message
    ): RedirectResponse {
        $url = $this->invoices
            ->nativeInvoiceUrl(
                $invoiceId
            );

        if (
            ! is_string($url)
            || trim($url) === ''
        ) {
            $url = url(
                '/sales/invoices/'.$invoiceId
            );
        }

        return redirect()
            ->to($url)
            ->withErrors([
                'workflow' => trim($message),
            ])
            ->with(
                'error',
                trim($message)
            );
    }
}
