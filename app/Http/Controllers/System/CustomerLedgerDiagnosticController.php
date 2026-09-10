<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Administration\ErpPermissionMatrixService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\ImplicitRouteBinding;
use Illuminate\Routing\Route as NativeRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Rollback-only native Customer Ledger probe.
 *
 * The installed LedgerController, model and route are host authorities and are
 * not shipped in this overlay. This Super-Admin diagnostic resolves those
 * runtime authorities and executes only the matched GET action inside a
 * transaction that is always rolled back.
 */
final class CustomerLedgerDiagnosticController extends Controller
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions,
    ) {
    }

    public function index(Request $request, int $customer)
    {
        abort_unless(
            $this->permissions->isSuperAdmin($request->user()),
            403
        );

        $route = $this->customerLedgerRoute();
        $controller = $this->controllerSnapshot($route);
        $binding = $this->bindingSnapshot($controller, $customer);
        $invoice = $this->invoiceSnapshot(
            max(0, (int) $request->query('invoice', 0)),
            $customer
        );

        return response()->json([
            'DIAGNOSTIC' => 'CUSTOMER_LEDGER_503',
            'READ_ONLY' => true,
            'SUPER_ADMIN_ONLY' => true,
            'CUSTOMER_ROUTE_VALUE' => $customer,
            'ROUTE' => $this->routeSnapshot($route),
            'CONTROLLER' => $controller,
            'MODEL_BINDING' => $binding,
            'INVOICE_CUSTOMER_AUTHORITY' => $invoice,
            'ROLLBACK_ONLY_ROUTE_PROBE' => $this->probe(
                $request,
                $route,
                $customer
            ),
            'SECRETS_EXPOSED' => false,
        ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function customerLedgerRoute(): ?NativeRoute
    {
        $named = Route::getRoutes()->getByName('accounting.ledgers.customer');

        if ($named instanceof NativeRoute) {
            return $named;
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = strtolower(trim((string) $route->uri(), '/'));

            if (
                in_array('GET', $route->methods(), true)
                && preg_match(
                    '#^accounting/ledgers/customers/\{[^}]+\}$#',
                    $uri
                ) === 1
            ) {
                return $route;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function routeSnapshot(?NativeRoute $route): array
    {
        if (! $route) {
            return [
                'found' => false,
                'name' => null,
                'uri' => null,
                'methods' => [],
                'action' => null,
                'middleware' => [],
                'parameters' => [],
            ];
        }

        return [
            'found' => true,
            'name' => $route->getName(),
            'uri' => $route->uri(),
            'methods' => $route->methods(),
            'action' => $route->getActionName(),
            'middleware' => $route->gatherMiddleware(),
            'parameters' => $route->parameterNames(),
        ];
    }

    /** @return array<string,mixed> */
    private function controllerSnapshot(?NativeRoute $route): array
    {
        $action = $route?->getActionName() ?? '';

        if (! str_contains($action, '@')) {
            return [
                'resolved' => false,
                'action' => $action,
                'class' => null,
                'method' => null,
                'parameters' => [],
                'source' => null,
            ];
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return [
                'resolved' => false,
                'action' => $action,
                'class' => $class,
                'method' => $method,
                'parameters' => [],
                'source' => null,
            ];
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
            $parameters = [];

            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();
                $parameters[] = [
                    'name' => $parameter->getName(),
                    'type' => $type instanceof ReflectionNamedType
                        ? $type->getName()
                        : ($type ? (string) $type : null),
                    'builtin' => $type instanceof ReflectionNamedType
                        ? $type->isBuiltin()
                        : null,
                    'nullable' => $type?->allowsNull(),
                ];
            }

            $source = null;
            $file = $reflection->getFileName();

            if (is_string($file) && is_file($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES);
                $source = $this->redact(implode("\n", array_slice(
                    $lines ?: [],
                    max(0, $reflection->getStartLine() - 1),
                    max(1, $reflection->getEndLine() - $reflection->getStartLine() + 1)
                )));
            }

            return [
                'resolved' => true,
                'action' => $action,
                'class' => $class,
                'method' => $method,
                'parameters' => $parameters,
                'source' => $source,
            ];
        } catch (Throwable $error) {
            return [
                'resolved' => false,
                'action' => $action,
                'class' => $class,
                'method' => $method,
                'parameters' => [],
                'source' => null,
                'inspection_error' => $this->safeMessage($error),
            ];
        }
    }

    /**
     * @param array<string,mixed> $controller
     * @return array<string,mixed>
     */
    private function bindingSnapshot(array $controller, int $customer): array
    {
        foreach ((array) ($controller['parameters'] ?? []) as $parameter) {
            if (($parameter['name'] ?? null) !== 'customer') {
                continue;
            }

            $class = (string) ($parameter['type'] ?? '');

            if ($class === '' || ! is_subclass_of($class, Model::class)) {
                return [
                    'parameter' => 'customer',
                    'type' => $class !== '' ? $class : null,
                    'model' => null,
                    'table' => null,
                    'key' => null,
                    'value' => $customer,
                    'exists' => null,
                ];
            }

            try {
                /** @var Model $model */
                $model = app($class);

                return [
                    'parameter' => 'customer',
                    'type' => $class,
                    'model' => $class,
                    'table' => $model->getTable(),
                    'key' => $model->getRouteKeyName(),
                    'value' => $customer,
                    'exists' => $model->newQuery()
                        ->where($model->getRouteKeyName(), $customer)
                        ->exists(),
                ];
            } catch (Throwable $error) {
                return [
                    'parameter' => 'customer',
                    'type' => $class,
                    'model' => $class,
                    'value' => $customer,
                    'exists' => null,
                    'inspection_error' => $this->safeMessage($error),
                ];
            }
        }

        return [
            'parameter' => null,
            'type' => null,
            'model' => null,
            'table' => null,
            'key' => null,
            'value' => $customer,
            'exists' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function invoiceSnapshot(int $invoiceId, int $customer): array
    {
        $class = \App\Models\SalesInvoice::class;

        if ($invoiceId <= 0 || ! class_exists($class)) {
            return [
                'invoice_id' => $invoiceId ?: null,
                'resolved' => false,
                'identifier_fields' => [],
                'link_id_matches_customer_field' => null,
            ];
        }

        try {
            /** @var Model $prototype */
            $prototype = app($class);
            $invoice = $prototype->newQuery()->whereKey($invoiceId)->first();

            if (! $invoice instanceof Model) {
                return [
                    'invoice_id' => $invoiceId,
                    'resolved' => false,
                    'identifier_fields' => [],
                    'link_id_matches_customer_field' => null,
                ];
            }

            $attributes = $invoice->getAttributes();
            $identifiers = [];

            foreach ([
                'customer_id',
                'party_id',
                'contact_id',
                'account_id',
                'subledger_id',
                'company_id',
                'branch_id',
            ] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $identifiers[$field] = $attributes[$field];
                }
            }

            $invoiceCustomer = (int) ($identifiers['customer_id'] ?? 0);

            return [
                'invoice_id' => $invoiceId,
                'resolved' => true,
                'identifier_fields' => $identifiers,
                'link_id_matches_customer_field' => $invoiceCustomer > 0
                    ? $invoiceCustomer === $customer
                    : null,
            ];
        } catch (Throwable $error) {
            return [
                'invoice_id' => $invoiceId,
                'resolved' => false,
                'identifier_fields' => [],
                'link_id_matches_customer_field' => null,
                'inspection_error' => $this->safeMessage($error),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function probe(
        Request $request,
        ?NativeRoute $route,
        int $customer
    ): array {
        if (! $route) {
            return [
                'executed' => false,
                'transaction_rolled_back' => true,
                'result' => 'native route not found',
            ];
        }

        $uri = preg_replace(
            '/\{[^}]+\}/',
            (string) $customer,
            $route->uri(),
            1
        );

        if (! is_string($uri) || str_contains($uri, '{')) {
            return [
                'executed' => false,
                'transaction_rolled_back' => true,
                'result' => 'native route parameters could not be resolved',
            ];
        }

        $probeRequest = Request::create('/'.ltrim($uri, '/'), 'GET');
        $probeRequest->setUserResolver(fn () => $request->user());
        $probeRoute = clone $route;
        $probeRoute->bind($probeRequest);
        $probeRequest->setRouteResolver(fn () => $probeRoute);

        $connection = DB::connection();
        $startedAt = microtime(true);
        $transactionLevel = $connection->transactionLevel();
        $readOnlyEnforced = false;

        try {
            if ($connection->getDriverName() === 'mysql') {
                $connection->statement('SET TRANSACTION READ ONLY');
                $readOnlyEnforced = true;
            }

            $connection->beginTransaction();
            ImplicitRouteBinding::resolveForRoute(app(), $probeRoute);
            $response = $probeRoute->run();

            return [
                'executed' => true,
                'transaction_rolled_back' => true,
                'database_read_only_enforced' => $readOnlyEnforced,
                'status' => method_exists($response, 'getStatusCode')
                    ? $response->getStatusCode()
                    : 200,
                'response_class' => is_object($response)
                    ? get_class($response)
                    : gettype($response),
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'exception' => null,
            ];
        } catch (Throwable $error) {
            return [
                'executed' => true,
                'transaction_rolled_back' => true,
                'database_read_only_enforced' => $readOnlyEnforced,
                'status' => null,
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'exception' => [
                    'class' => get_class($error),
                    'message' => $this->safeMessage($error),
                    'file' => basename($error->getFile()),
                    'line' => $error->getLine(),
                ],
            ];
        } finally {
            while ($connection->transactionLevel() > $transactionLevel) {
                $connection->rollBack();
            }
        }
    }

    private function safeMessage(Throwable $error): string
    {
        return $this->redact(mb_substr($error->getMessage(), 0, 3000));
    }

    private function redact(string $value): string
    {
        return preg_replace(
            '/\b(password|passwd|secret|token|api[_-]?key)\b'
                .'\s*[\'\"]?\s*(?:=>|=|:)\s*[\'\"]?[^\s,;\'\"]+/i',
            '$1=[REDACTED]',
            $value
        ) ?? 'Diagnostic error message could not be rendered.';
    }
}
