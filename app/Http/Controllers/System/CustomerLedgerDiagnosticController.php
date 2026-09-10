<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Administration\ErpPermissionMatrixService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\ImplicitRouteBinding;
use Illuminate\Routing\Route as NativeRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Super-Admin-only, rollback-only native Customer Ledger pipeline probe.
 */
final class CustomerLedgerDiagnosticController extends Controller
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions,
    ) {
    }

    public function index(Request $request, int $customer)
    {
        abort_unless($this->permissions->isSuperAdmin($request->user()), 403);

        $route = $this->customerLedgerRoute();
        $controller = $this->controllerSnapshot($route);
        $binding = $this->bindingSnapshot($route, $controller, $customer);
        $authorities = $this->middlewareAuthorities($route);
        $probes = $this->pipelineProbes($request, $route, $customer, $authorities);
        $firstFailure = $this->firstFailure($probes);
        $viewProbe = $this->stageByName($probes, 'VIEW_RENDER');

        return response()->json([
            'DIAGNOSTIC' => 'CUSTOMER_LEDGER_503_PHASE_2',
            'READ_ONLY' => true,
            'ROLLBACK_ONLY' => true,
            'SUPER_ADMIN_ONLY' => true,
            'CUSTOMER_ROUTE_VALUE' => $customer,
            'ROUTE' => $this->routeSnapshot($route),
            'CONTROLLER' => $controller,
            'MODEL_BINDING' => $binding,
            'INVOICE_CUSTOMER_AUTHORITY' => $this->invoiceSnapshot(
                max(0, (int) $request->query('invoice', 0)),
                $customer
            ),
            'PERMISSION_JOURNALS_VIEW' => $this->permissionSnapshot($request),
            'MIDDLEWARE_AUTHORITIES' => $authorities,
            'PROGRESSIVE_MIDDLEWARE_PROBES' => $probes,
            'VIEW_RENDER' => [
                'name' => $viewProbe['view_name'] ?? null,
                'status' => $viewProbe['status'] ?? null,
                'exception' => $viewProbe['exception'] ?? null,
            ],
            'FIRST_FAILING_STAGE' => $firstFailure,
            'HTTP_503_SOURCE' => $this->http503Source($firstFailure),
            'ACCOUNTING_DATA_MUTATED' => false,
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
                && preg_match('#^accounting/ledgers/customers/\{[^}]+\}$#', $uri) === 1
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
            return $this->unresolvedController($action);
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return $this->unresolvedController($action, $class, $method);
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

            return [
                'resolved' => true,
                'action' => $action,
                'class' => $class,
                'method' => $method,
                'parameters' => $parameters,
                'source' => $this->methodSource($reflection),
            ];
        } catch (Throwable $error) {
            return $this->unresolvedController($action, $class, $method, $error);
        }
    }

    /** @return array<string,mixed> */
    private function unresolvedController(
        string $action,
        ?string $class = null,
        ?string $method = null,
        ?Throwable $error = null
    ): array {
        $result = [
            'resolved' => false,
            'action' => $action,
            'class' => $class,
            'method' => $method,
            'parameters' => [],
            'source' => null,
        ];

        if ($error) {
            $result['inspection_error'] = $this->safeMessage($error);
        }

        return $result;
    }

    /**
     * Match the real route parameter to a reflected Eloquent model argument.
     * This intentionally does not assume that the parameter is named customer.
     *
     * @param array<string,mixed> $controller
     * @return array<string,mixed>
     */
    private function bindingSnapshot(
        ?NativeRoute $route,
        array $controller,
        int $customer
    ): array {
        $routeParameters = $route?->parameterNames() ?? [];
        $modelParameters = [];

        foreach ((array) ($controller['parameters'] ?? []) as $parameter) {
            $class = (string) ($parameter['type'] ?? '');

            if ($class !== '' && is_subclass_of($class, Model::class)) {
                $modelParameters[] = $parameter;
            }
        }

        $matched = null;
        foreach ($modelParameters as $parameter) {
            if (in_array($parameter['name'] ?? null, $routeParameters, true)) {
                $matched = $parameter;
                break;
            }
        }

        if (! $matched && count($routeParameters) === 1 && count($modelParameters) === 1) {
            $matched = $modelParameters[0];
        }

        $parameterName = $matched['name'] ?? ($routeParameters[0] ?? null);
        $class = (string) ($matched['type'] ?? '');

        if ($class === '' || ! is_subclass_of($class, Model::class)) {
            return [
                'parameter' => $parameterName,
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
            $key = $model->getRouteKeyName();

            return [
                'parameter' => $parameterName,
                'type' => $class,
                'model' => $class,
                'table' => $model->getTable(),
                'key' => $key,
                'value' => $customer,
                'exists' => $model->newQuery()->where($key, $customer)->exists(),
            ];
        } catch (Throwable $error) {
            return [
                'parameter' => $parameterName,
                'type' => $class,
                'model' => $class,
                'table' => null,
                'key' => null,
                'value' => $customer,
                'exists' => null,
                'inspection_error' => $this->safeMessage($error),
            ];
        }
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
    private function permissionSnapshot(Request $request): array
    {
        try {
            $user = $request->user();

            return [
                'permission' => 'journals.view',
                'user_authenticated' => $user !== null,
                'granted' => $user !== null ? (bool) $user->can('journals.view') : false,
                'expected_denial_status' => 403,
            ];
        } catch (Throwable $error) {
            return [
                'permission' => 'journals.view',
                'user_authenticated' => $request->user() !== null,
                'granted' => null,
                'expected_denial_status' => 403,
                'inspection_error' => $this->safeMessage($error),
            ];
        }
    }

    /** @return list<array<string,mixed>> */
    private function middlewareAuthorities(?NativeRoute $route): array
    {
        if (! $route) {
            return [];
        }

        $result = [];

        foreach ($route->gatherMiddleware() as $declaration) {
            $resolved = $this->resolveMiddleware((string) $declaration);
            $inspections = [];

            foreach ($resolved as $middleware) {
                $inspections[] = $this->inspectMiddleware($middleware);
            }

            $result[] = [
                'declaration' => (string) $declaration,
                'resolved' => $resolved,
                'inspection' => $inspections,
                'safe_to_probe' => $this->inspectionsSafe($inspections),
            ];
        }

        return $result;
    }

    /** @return list<string> */
    private function resolveMiddleware(string $declaration): array
    {
        try {
            /** @var Router $router */
            $router = app('router');
            return array_values($router->resolveMiddleware([$declaration]));
        } catch (Throwable) {
            return [$declaration];
        }
    }

    /** @return array<string,mixed> */
    private function inspectMiddleware(string $middleware): array
    {
        [$class] = explode(':', $middleware, 2);

        if (! class_exists($class) || ! method_exists($class, 'handle')) {
            return [
                'class' => $class,
                'handle_method' => null,
                'source_file' => null,
                'handle_source' => null,
                'can_return_503' => null,
                'calls_abort_503' => null,
                'catches_throwable' => null,
                'transforms_response_content' => null,
                'performs_database_writes' => null,
                'performs_filesystem_writes' => null,
                'safe_to_probe' => false,
                'inspection_error' => 'Runtime middleware class could not be reflected.',
            ];
        }

        try {
            $reflection = new ReflectionMethod($class, 'handle');
            $source = $this->methodSource($reflection) ?? '';
            $writesDatabase = preg_match(
                '/(?:->|::)(?:insert|insertGetId|update|delete|save|create|upsert)\s*\(/i',
                $source
            ) === 1;
            $writesFilesystem = preg_match(
                '/(?:File::(?:delete|deleteDirectory|put|append|move)|'
                    .'Storage::(?:put|delete|move)|'
                    .'ObsoleteFileCleaner|StabilizationCleaner)/i',
                $source
            ) === 1;

            return [
                'class' => $class,
                'handle_method' => 'handle',
                'source_file' => basename((string) $reflection->getFileName()),
                'handle_source' => $source,
                'can_return_503' => preg_match(
                    '/(?:\b503\b|HTTP_SERVICE_UNAVAILABLE|ServiceUnavailableHttpException)/i',
                    $source
                ) === 1,
                'calls_abort_503' => preg_match(
                    '/abort(?:_if|_unless)?\s*\([^\)]*\b503\b/is',
                    $source
                ) === 1,
                'catches_throwable' => preg_match(
                    '/catch\s*\(\s*\\?Throwable\b/i',
                    $source
                ) === 1,
                'transforms_response_content' => preg_match(
                    '/(?:getContent|setContent|render\s*\()/i',
                    $source
                ) === 1,
                'performs_database_writes' => $writesDatabase,
                'performs_filesystem_writes' => $writesFilesystem,
                'safe_to_probe' => ! $writesDatabase && ! $writesFilesystem,
            ];
        } catch (Throwable $error) {
            return [
                'class' => $class,
                'handle_method' => null,
                'source_file' => null,
                'handle_source' => null,
                'can_return_503' => null,
                'calls_abort_503' => null,
                'catches_throwable' => null,
                'transforms_response_content' => null,
                'performs_database_writes' => null,
                'performs_filesystem_writes' => null,
                'safe_to_probe' => false,
                'inspection_error' => $this->safeMessage($error),
            ];
        }
    }

    /** @param list<array<string,mixed>> $inspections */
    private function inspectionsSafe(array $inspections): bool
    {
        return $inspections !== [] && array_reduce(
            $inspections,
            static fn (bool $safe, array $inspection): bool => $safe
                && ($inspection['safe_to_probe'] ?? false) === true,
            true
        );
    }

    /**
     * @param list<array<string,mixed>> $authorities
     * @return list<array<string,mixed>>
     */
    private function pipelineProbes(
        Request $request,
        ?NativeRoute $route,
        int $customer,
        array $authorities
    ): array {
        $probes = [
            $this->executeProbe($request, $route, $customer, 'MODEL_BINDING'),
            $this->executeProbe($request, $route, $customer, 'CONTROLLER_DIRECT'),
            $this->executeProbe($request, $route, $customer, 'VIEW_RENDER'),
        ];
        $cumulative = [];
        $unsafeDeclaration = null;

        foreach ($authorities as $authority) {
            $declaration = (string) ($authority['declaration'] ?? '');

            if (in_array($declaration, ['web', 'auth'], true)) {
                $probes[] = [
                    'stage' => 'MIDDLEWARE_'.strtoupper($declaration),
                    'middleware_added' => $declaration,
                    'middleware_stack' => [],
                    'executed' => false,
                    'represented_by_authenticated_diagnostic_request' => true,
                    'status' => 200,
                    'response_class' => null,
                    'elapsed_ms' => 0,
                    'transaction_rolled_back' => true,
                    'exception' => null,
                ];
                continue;
            }

            $cumulative[] = $declaration;

            if (($authority['safe_to_probe'] ?? false) !== true) {
                $unsafeDeclaration ??= $declaration;
            }

            if ($unsafeDeclaration !== null) {
                $probes[] = [
                    'stage' => 'MIDDLEWARE_'.count($cumulative),
                    'middleware_added' => $declaration,
                    'middleware_stack' => $cumulative,
                    'executed' => false,
                    'status' => null,
                    'response_class' => null,
                    'elapsed_ms' => 0,
                    'transaction_rolled_back' => true,
                    'exception' => [
                        'class' => 'SafetyGuard',
                        'message' => 'Probe withheld because '.$unsafeDeclaration
                            .' may perform an irreversible write.',
                        'file' => null,
                        'line' => null,
                    ],
                ];
                continue;
            }

            $probes[] = $this->executeProbe(
                $request,
                $route,
                $customer,
                'MIDDLEWARE_'.count($cumulative),
                $cumulative,
                $declaration
            );
        }

        foreach ($authorities as $index => $authority) {
            $declaration = (string) ($authority['declaration'] ?? '');

            if (
                in_array($declaration, ['web', 'auth'], true)
                || ($authority['safe_to_probe'] ?? false) !== true
            ) {
                continue;
            }

            $probes[] = $this->executeProbe(
                $request,
                $route,
                $customer,
                'ISOLATED_MIDDLEWARE_'.($index + 1),
                [$declaration],
                $declaration
            );
        }

        return $probes;
    }

    /** @return array<string,mixed> */
    private function executeProbe(
        Request $request,
        ?NativeRoute $route,
        int $customer,
        string $stage,
        array $middleware = [],
        ?string $middlewareAdded = null
    ): array {
        if (! $route) {
            return $this->unexecutedProbe($stage, 'Native route not found.');
        }

        try {
            [$probeRequest, $probeRoute] = $this->boundProbeRequest(
                $request,
                $route,
                $customer
            );
        } catch (Throwable $error) {
            return $this->unexecutedProbe($stage, $this->safeMessage($error));
        }

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

            if ($stage === 'MODEL_BINDING') {
                return $this->successfulProbe(
                    $stage,
                    $middlewareAdded,
                    $middleware,
                    200,
                    get_debug_type($probeRoute->parameters()),
                    $startedAt,
                    $readOnlyEnforced
                );
            }

            if ($stage === 'CONTROLLER_DIRECT' || $stage === 'VIEW_RENDER') {
                $result = $probeRoute->run();
                $viewName = $result instanceof ViewContract ? $result->name() : null;

                if ($stage === 'VIEW_RENDER' && $result instanceof ViewContract) {
                    $result->render();
                }

                return $this->successfulProbe(
                    $stage,
                    $middlewareAdded,
                    $middleware,
                    method_exists($result, 'getStatusCode') ? $result->getStatusCode() : 200,
                    is_object($result) ? get_class($result) : gettype($result),
                    $startedAt,
                    $readOnlyEnforced,
                    $viewName
                );
            }

            /** @var Router $router */
            $router = app('router');
            $resolved = $router->resolveMiddleware($middleware);
            $result = null;
            $response = app(Pipeline::class)
                ->send($probeRequest)
                ->through($resolved)
                ->then(function () use ($probeRequest, $probeRoute, &$result) {
                    $result = $probeRoute->run();
                    return Router::toResponse($probeRequest, $result);
                });
            $viewName = $result instanceof ViewContract ? $result->name() : null;

            return $this->successfulProbe(
                $stage,
                $middlewareAdded,
                $middleware,
                method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200,
                is_object($response) ? get_class($response) : gettype($response),
                $startedAt,
                $readOnlyEnforced,
                $viewName
            );
        } catch (Throwable $error) {
            return [
                'stage' => $stage,
                'middleware_added' => $middlewareAdded,
                'middleware_stack' => $middleware,
                'executed' => true,
                'status' => $this->exceptionStatus($error),
                'response_class' => null,
                'view_name' => null,
                'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'transaction_rolled_back' => true,
                'database_read_only_enforced' => $readOnlyEnforced,
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

    /** @return array{0:Request,1:NativeRoute} */
    private function boundProbeRequest(
        Request $request,
        NativeRoute $route,
        int $customer
    ): array {
        $parameters = $route->parameterNames();

        if (count($parameters) !== 1) {
            throw new \RuntimeException(
                'Native Customer Ledger route must have exactly one parameter.'
            );
        }

        $uri = preg_replace(
            '/\{'.preg_quote($parameters[0], '/').'\??\}/',
            (string) $customer,
            $route->uri(),
            1
        );

        if (! is_string($uri) || str_contains($uri, '{')) {
            throw new \RuntimeException('Native route parameters could not be resolved.');
        }

        $probeRequest = Request::create('/'.ltrim($uri, '/'), 'GET');
        $probeRequest->setUserResolver(fn () => $request->user());

        if ($request->hasSession()) {
            $probeRequest->setLaravelSession($request->session());
        }

        $probeRoute = clone $route;
        $probeRoute->bind($probeRequest);
        $probeRequest->setRouteResolver(fn () => $probeRoute);

        return [$probeRequest, $probeRoute];
    }

    /** @return array<string,mixed> */
    private function successfulProbe(
        string $stage,
        ?string $middlewareAdded,
        array $middleware,
        int $status,
        string $responseClass,
        float $startedAt,
        bool $readOnlyEnforced,
        ?string $viewName = null
    ): array {
        return [
            'stage' => $stage,
            'middleware_added' => $middlewareAdded,
            'middleware_stack' => $middleware,
            'executed' => true,
            'status' => $status,
            'response_class' => $responseClass,
            'view_name' => $viewName,
            'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'transaction_rolled_back' => true,
            'database_read_only_enforced' => $readOnlyEnforced,
            'exception' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function unexecutedProbe(string $stage, string $message): array
    {
        return [
            'stage' => $stage,
            'middleware_added' => null,
            'middleware_stack' => [],
            'executed' => false,
            'status' => null,
            'response_class' => null,
            'view_name' => null,
            'elapsed_ms' => 0,
            'transaction_rolled_back' => true,
            'exception' => [
                'class' => 'DiagnosticConfiguration',
                'message' => $message,
                'file' => null,
                'line' => null,
            ],
        ];
    }

    private function exceptionStatus(Throwable $error): ?int
    {
        if (method_exists($error, 'getStatusCode')) {
            $status = (int) $error->getStatusCode();
            return $status > 0 ? $status : null;
        }

        $code = (int) $error->getCode();
        return $code >= 400 && $code <= 599 ? $code : null;
    }

    /** @param list<array<string,mixed>> $probes */
    private function firstFailure(array $probes): ?array
    {
        foreach ($probes as $probe) {
            if (($probe['executed'] ?? false) !== true) {
                continue;
            }

            if (($probe['exception'] ?? null) !== null || ($probe['status'] ?? 200) !== 200) {
                return $probe;
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $probes */
    private function stageByName(array $probes, string $stage): array
    {
        foreach ($probes as $probe) {
            if (($probe['stage'] ?? null) === $stage) {
                return $probe;
            }
        }

        return [];
    }

    /** @param array<string,mixed>|null $failure */
    private function http503Source(?array $failure): ?string
    {
        if (! $failure || ($failure['status'] ?? null) !== 503) {
            return null;
        }

        return (string) ($failure['middleware_added'] ?? $failure['stage'] ?? 'unknown');
    }

    private function methodSource(ReflectionMethod $reflection): ?string
    {
        $file = $reflection->getFileName();

        if (! is_string($file) || ! is_file($file)) {
            return null;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        return $this->redact(implode("\n", array_slice(
            $lines ?: [],
            max(0, $reflection->getStartLine() - 1),
            max(1, $reflection->getEndLine() - $reflection->getStartLine() + 1)
        )));
    }

    private function safeMessage(Throwable $error): string
    {
        return $this->redact(mb_substr($error->getMessage(), 0, 3000));
    }

    private function redact(string $value): string
    {
        return preg_replace(
            '/\b(password|passwd|secret|token|api[_-]?key|cookie|session)\b'
                .'\s*[\'\"]?\s*(?:=>|=|:)\s*[\'\"]?[^\s,;\'\"]+/i',
            '$1=[REDACTED]',
            $value
        ) ?? 'Diagnostic content could not be rendered.';
    }
}
