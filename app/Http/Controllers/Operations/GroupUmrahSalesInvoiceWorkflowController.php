<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeSalesInvoiceInspector;
use App\Services\Operations\NativeSalesInvoiceWorkflowReconciler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class GroupUmrahSalesInvoiceWorkflowController extends Controller
{
    public function __construct(
        private readonly NativeSalesInvoiceInspector $salesInvoices,
        private readonly NativeSalesInvoiceWorkflowReconciler $reconciler,
    ) {}

    public function __invoke(Request $request,int $booking,string $action): SymfonyResponse
    {
        abort_unless(in_array($action,['submit','approve','post'],true),404);

        $invoice=$this->salesInvoices->find($booking);

        if (!$invoice) {
            return $this->back($booking)
                ->with(
                    'error',
                    'Create the Customer Sales Invoice before using its accounting workflow.'
                );
        }

        $invoiceId=(int)($invoice['id'] ?? 0);
        $beforeWorkflow=$this->salesInvoices->workflowAction($booking);
        $beforeStatus=$this->normalizeStatus(
            (string)($beforeWorkflow['status'] ?? $invoice['status'] ?? '')
        );
        $native=$beforeWorkflow['action']['native'] ?? null;

        if ($invoiceId<=0 || !is_array($native) || empty($native['url'])) {
            return $this->back($booking)
                ->with(
                    'error',
                    'The native Sales Invoice '.$action
                    .' route could not be resolved for the current invoice status.'
                );
        }

        if ($action==='submit') {
            $this->reconciler->reconcileDraftForSubmit($invoiceId);
        }

        try {
            $nativeResponse=$this->dispatchNative(
                $request,
                (string)$native['url'],
                (string)($native['method'] ?? 'POST')
            );

            /*
             * A native controller may redirect back with a flashed error
             * rather than throw ValidationException. Therefore success is
             * NEVER inferred from HTTP/redirect alone. Re-read the invoice.
             */
            $afterWorkflow=$this->salesInvoices->workflowAction($booking);
            $afterStatus=$this->normalizeStatus(
                (string)($afterWorkflow['status'] ?? '')
            );

            if (! $this->transitionSucceeded(
                $action,
                $beforeStatus,
                $afterStatus
            )) {
                $message=$this->nativeFlashedFailure($request)
                    ?: 'The native Sales Invoice '.$this->actionLabel($action)
                        .' action returned without changing the invoice status after applying the exact native Draft contract.';

                if ($action==='submit') {
                    $detail=$this->reconciler
                        ->diagnosticForInvoice($invoiceId);

                    if ($detail!=='') {
                        $message.=' [Native Draft reconciliation: '
                            .$detail
                            .']';
                    }
                }

                $message.=' [Status remained '
                    .($afterStatus!=='' ? $afterStatus : 'unknown')
                    .'; before '
                    .($beforeStatus!=='' ? $beforeStatus : 'unknown')
                    .']';

                return $this->back($booking)
                    ->withErrors([
                        'invoice'=>$message,
                    ]);
            }

            return $this->back($booking)
                ->with(
                    'success',
                    $this->successMessage(
                        $action,
                        $afterStatus
                    )
                );
        } catch (ValidationException $e) {
            $message=$this->validationMessage($e);

            if ($action==='submit') {
                $detail=$this->reconciler
                    ->diagnosticForInvoice($invoiceId);

                if ($detail!=='') {
                    $message.=' [Native Draft reconciliation: '
                        .$detail
                        .']';
                }
            }

            return $this->back($booking)
                ->withErrors([
                    'invoice'=>$message,
                ]);
        } catch (\Throwable $e) {
            report($e);

            return $this->back($booking)
                ->with(
                    'error',
                    'The native Sales Invoice '.$this->actionLabel($action)
                    .' action failed: '
                    .mb_substr($e->getMessage(),0,700)
                );
        }
    }

    private function transitionSucceeded(
        string $action,
        string $before,
        string $after
    ): bool {
        if ($after==='' || $after===$before) {
            return false;
        }

        return match($action) {
            'submit'=>in_array(
                $after,
                [
                    'pending',
                    'pending_approval',
                    'pendingapproval',
                    'submitted',
                    'pending_review',
                    'awaiting_approval',
                    'approved',
                    'posted',
                    'posted_to_gl',
                ],
                true
            ),
            'approve'=>in_array(
                $after,
                [
                    'approved',
                    'authorized',
                    'authorised',
                    'posted',
                    'posted_to_gl',
                ],
                true
            ),
            'post'=>in_array(
                $after,
                [
                    'posted',
                    'posted_to_gl',
                    'final',
                    'finalized',
                    'completed',
                ],
                true
            ),
            default=>false,
        };
    }

    private function normalizeStatus(string $status): string
    {
        return str_replace(
            [' ','-'],
            '_',
            strtolower(trim($status))
        );
    }

    private function successMessage(
        string $action,
        string $status
    ): string {
        $label=ucwords(
            str_replace('_',' ',$status)
        );

        return match($action) {
            'submit'=>'Sales Invoice submitted for approval successfully. Current status: '.$label.'.',
            'approve'=>'Sales Invoice approved successfully. Current status: '.$label.'.',
            'post'=>'Sales Invoice posted successfully. Current status: '.$label.'.',
            default=>'Sales Invoice workflow updated successfully.',
        };
    }

    private function actionLabel(string $action): string
    {
        return match($action) {
            'submit'=>'Submit for Approval',
            'approve'=>'Approve',
            'post'=>'Post',
            default=>ucfirst($action),
        };
    }

    private function nativeFlashedFailure(Request $request): string
    {
        try {
            $session=$request->session();

            foreach ([
                'error',
                'warning',
                'message',
            ] as $key) {
                $value=$session->get($key);

                if (is_string($value) && trim($value)!=='') {
                    return trim($value);
                }
            }

            $errors=$session->get('errors');

            if (
                is_object($errors)
                && method_exists($errors,'getBag')
            ) {
                $bag=$errors->getBag('default');

                if (
                    $bag
                    && method_exists($bag,'all')
                ) {
                    $all=$bag->all();

                    if ($all) {
                        return implode(
                            ' | ',
                            array_map(
                                fn ($value): string =>
                                    trim((string)$value),
                                $all
                            )
                        );
                    }
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function back(int $booking)
    {
        return redirect()->route(
            'operations.bookings.group-package-unified.edit',
            ['booking'=>$booking]
        );
    }

    private function dispatchNative(Request $outer,string $url,string $method): SymfonyResponse
    {
        $sub=Request::create(
            $url,
            strtoupper($method),
            ['_token'=>$outer->session()->token()],
            $outer->cookies->all(),
            [],
            $outer->server->all()
        );

        if ($outer->hasSession()) {
            $sub->setLaravelSession($outer->session());
        }

        $sub->setUserResolver(fn()=>$outer->user());

        return app('router')->dispatch($sub);
    }

    private function validationMessage(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            foreach ((array)$messages as $message) {
                $message=trim((string)$message);
                if ($message!=='') return $message;
            }
        }

        return trim($e->getMessage())
            ?: 'The native Sales Invoice workflow rejected the request.';
    }
}
