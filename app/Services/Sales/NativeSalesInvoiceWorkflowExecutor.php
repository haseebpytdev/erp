<?php

namespace App\Services\Sales;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use UnitEnum;

/**
 * ERP-11.3.76
 *
 * Executes native Sales Invoice workflow transitions directly through the
 * host ERP's SalesInvoiceService instead of relying on uncertain native
 * controller/route wrappers.
 */
final class NativeSalesInvoiceWorkflowExecutor
{
    public function execute(
        Request $request,
        int $invoiceId,
        string $workflow
    ): mixed {
        $workflow = strtolower(trim($workflow));

        if (! in_array($workflow, ['submit', 'approve', 'post'], true)) {
            throw ValidationException::withMessages([
                'workflow' => 'Unsupported Sales Invoice workflow action.',
            ]);
        }

        $class = \App\Services\Sales\SalesInvoiceService::class;

        if (! class_exists($class)) {
            throw ValidationException::withMessages([
                'workflow' => 'The native Sales Invoice service is unavailable.',
            ]);
        }

        $native = app($class);
        $methodName = $this->resolveMethod($native, $workflow);

        if ($methodName === null) {
            throw ValidationException::withMessages([
                'workflow' => 'The native Sales Invoice service does not expose a '
                    .$workflow.' workflow operation.',
            ]);
        }

        $method = new ReflectionMethod($native, $methodName);

        if (! $method->isPublic()) {
            $method->setAccessible(true);
        }

        $invoice = $this->invoice($invoiceId);

        if (! $invoice) {
            throw ValidationException::withMessages([
                'workflow' => 'The Sales Invoice could not be resolved.',
            ]);
        }

        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = strtolower($parameter->getName());

            if (
                $type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
            ) {
                $className = $type->getName();

                if (is_a($className, Request::class, true)) {
                    $arguments[] = $this->subRequest(
                        $request,
                        $invoiceId,
                        $workflow
                    );
                    continue;
                }

                if ($invoice instanceof $className) {
                    $arguments[] = $invoice;
                    continue;
                }

                $user = $request->user();

                if (
                    $user
                    && $user instanceof $className
                ) {
                    $arguments[] = $user;
                    continue;
                }

                try {
                    $arguments[] = app($className);
                    continue;
                } catch (Throwable) {
                    if ($parameter->allowsNull()) {
                        $arguments[] = null;
                        continue;
                    }
                }
            }

            if (
                $type instanceof ReflectionNamedType
                && $type->isBuiltin()
            ) {
                $builtin = $type->getName();

                if (
                    $builtin === 'int'
                    && (
                        str_contains($name, 'invoice')
                        || $name === 'id'
                    )
                ) {
                    $arguments[] = $invoiceId;
                    continue;
                }

                if ($builtin === 'array') {
                    $arguments[] = $request->all();
                    continue;
                }

                if ($builtin === 'bool') {
                    $arguments[] = $parameter->isDefaultValueAvailable()
                        ? $parameter->getDefaultValue()
                        : false;
                    continue;
                }

                if ($builtin === 'string') {
                    if (
                        str_contains($name, 'reason')
                        || str_contains($name, 'note')
                        || str_contains($name, 'remark')
                        || str_contains($name, 'comment')
                    ) {
                        $arguments[] = (string) (
                            $request->input($name)
                            ?? $request->input('reason')
                            ?? $request->input('notes')
                            ?? ''
                        );
                        continue;
                    }

                    if (str_contains($name, 'date')) {
                        $arguments[] = (string) (
                            $request->input($name)
                            ?? $request->input('date')
                            ?? now()->toDateString()
                        );
                        continue;
                    }

                    if ($parameter->isDefaultValueAvailable()) {
                        $arguments[] = $parameter->getDefaultValue();
                        continue;
                    }
                }
            }

            if (
                str_contains($name, 'invoice')
                || $name === 'id'
            ) {
                $arguments[] = $invoiceId;
                continue;
            }

            if (
                in_array(
                    $name,
                    ['data', 'payload', 'attributes'],
                    true
                )
            ) {
                $arguments[] = $request->all();
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw ValidationException::withMessages([
                'workflow' => 'The native Sales Invoice '.$methodName
                    .' method has an unsupported parameter: $'
                    .$parameter->getName().'.',
            ]);
        }

        Log::info(
            'ERP-11.3.76 invoking native Sales Invoice workflow service.',
            [
                'invoice_id' => $invoiceId,
                'workflow' => $workflow,
                'native_method' => $methodName,
                'parameter_count' => count($arguments),
            ]
        );

        return $method->invokeArgs(
            $native,
            $arguments
        );
    }

    public function status(int $invoiceId): string
    {
        $invoice = $this->invoice($invoiceId);

        if (! $invoice) {
            return '';
        }

        foreach ([
            'status',
            'invoice_status',
            'workflow_status',
            'document_status',
        ] as $field) {
            $value = $invoice->getAttribute($field);

            if ($value instanceof BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof UnitEnum) {
                $value = $value->name;
            }

            if (
                $value !== null
                && (
                    is_scalar($value)
                    || (
                        is_object($value)
                        && method_exists(
                            $value,
                            '__toString'
                        )
                    )
                )
                && trim((string) $value) !== ''
            ) {
                return $this->normalizeStatus(
                    (string) $value
                );
            }
        }

        return '';
    }

    private function resolveMethod(
        object $native,
        string $workflow
    ): ?string {
        $candidates = match ($workflow) {
            'submit' => [
                'submit',
                'submitForApproval',
                'submitInvoice',
                'requestApproval',
                'sendForApproval',
            ],
            'approve' => [
                'approve',
                'approveInvoice',
                'authorize',
                'authorise',
            ],
            'post' => [
                'post',
                'postInvoice',
                'postToAccounting',
                'postToLedger',
                'finalize',
                'finalise',
            ],
            default => [],
        };

        foreach ($candidates as $candidate) {
            if (method_exists($native, $candidate)) {
                return $candidate;
            }
        }

        /*
         * Last-resort method discovery by normalized method name.
         */
        $methods = get_class_methods($native) ?: [];

        foreach ($methods as $method) {
            $normalized = strtolower(
                preg_replace(
                    '/[^a-z0-9]+/i',
                    '',
                    $method
                ) ?? $method
            );

            if (
                $workflow === 'submit'
                && str_contains($normalized, 'submit')
                && (
                    str_contains($normalized, 'approval')
                    || $normalized === 'submit'
                )
            ) {
                return $method;
            }

            if (
                $workflow === 'approve'
                && str_contains($normalized, 'approv')
            ) {
                return $method;
            }

            if (
                $workflow === 'post'
                && (
                    str_contains($normalized, 'post')
                    || str_contains($normalized, 'final')
                )
                && ! str_contains($normalized, 'unpost')
            ) {
                return $method;
            }
        }

        return null;
    }

    private function invoice(int $invoiceId): ?Model
    {
        if (
            $invoiceId <= 0
            || ! class_exists(
                \App\Models\SalesInvoice::class
            )
        ) {
            return null;
        }

        try {
            $model = app(
                \App\Models\SalesInvoice::class
            );

            if (! $model instanceof Model) {
                return null;
            }

            return $model
                ->newQuery()
                ->whereKey($invoiceId)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function subRequest(
        Request $outer,
        int $invoiceId,
        string $workflow
    ): Request {
        $request = Request::create(
            '/internal/sales-invoices/'
                .$invoiceId
                .'/workflow/'
                .$workflow,
            'POST',
            $outer->all(),
            $outer->cookies->all(),
            [],
            $outer->server->all()
        );

        if ($outer->hasSession()) {
            $request->setLaravelSession(
                $outer->session()
            );
        }

        $request->setUserResolver(
            fn () => $outer->user()
        );

        return $request;
    }

    private function normalizeStatus(
        string $status
    ): string {
        return str_replace(
            [' ', '-'],
            '_',
            strtolower(trim($status))
        );
    }
}
