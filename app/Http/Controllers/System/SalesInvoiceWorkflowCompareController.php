<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class SalesInvoiceWorkflowCompareController extends Controller
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions,
        private readonly NativeErpLayoutResolver $layout,
    ) {}

    public function index(Request $request)
    {
        abort_unless(
            $this->permissions->isSuperAdmin($request->user()),
            403
        );

        $airId=(int)$request->query('air',3);
        $generalId=(int)$request->query('general',4);

        $air=$this->invoice($airId);
        $general=$this->invoice($generalId);

        return view(
            'system.sales-invoice-workflow-compare-v11379',
            [
                'layoutMeta'=>$this->layout->resolve(),
                'air'=>$this->snapshot($air,'AIR ONLY'),
                'general'=>$this->snapshot($general,'GENERAL'),
                'workflowRoutes'=>$this->workflowRoutes(),
                'nativeClasses'=>$this->nativeClassSnapshot(),
            ]
        );
    }

    private function invoice(int $id): ?Model
    {
        if (
            $id<=0
            || !class_exists(\App\Models\SalesInvoice::class)
        ) {
            return null;
        }

        try {
            $model=app(\App\Models\SalesInvoice::class);

            if (!$model instanceof Model) {
                return null;
            }

            return $model
                ->newQuery()
                ->whereKey($id)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function snapshot(?Model $invoice,string $expectedType): array
    {
        if (!$invoice) {
            return [
                'found'=>false,
                'expected_type'=>$expectedType,
            ];
        }

        $attributes=$invoice->getAttributes();
        $table=$invoice->getTable();
        $id=(int)$invoice->getKey();

        $number=$this->firstValue(
            $attributes,
            [
                'invoice_no',
                'invoice_number',
                'number',
                'reference',
                'document_no',
            ]
        );

        $bookingId=(int)$this->firstValue(
            $attributes,
            [
                'booking_id',
                'source_booking_id',
                'travel_booking_id',
            ],
            0
        );

        $workflowFields=[];
        $commercialFields=[];
        $identityFields=[];

        foreach ($attributes as $field=>$value) {
            if (
                !is_scalar($value)
                && $value!==null
            ) {
                continue;
            }

            $lower=strtolower((string)$field);

            if (
                preg_match(
                    '/status|draft|submit|approv|post|workflow|journal|immutable|locked/',
                    $lower
                )
            ) {
                $workflowFields[$field]=$value;
            }

            if (
                preg_match(
                    '/total|amount|fare|price|discount|tax|currency|customer|supplier|vendor|commission|service|line/',
                    $lower
                )
            ) {
                $commercialFields[$field]=$value;
            }

            if (
                preg_match(
                    '/booking|branch|agent|sales|customer|type|product|source|created|updated|date/',
                    $lower
                )
            ) {
                $identityFields[$field]=$value;
            }
        }

        ksort($workflowFields);
        ksort($commercialFields);
        ksort($identityFields);

        return [
            'found'=>true,
            'expected_type'=>$expectedType,
            'id'=>$id,
            'number'=>$number,
            'booking_id'=>$bookingId,
            'model'=>get_class($invoice),
            'table'=>$table,
            'url'=>$this->invoiceUrl($id),
            'booking_url'=>$bookingId>0
                ? url('/operations/bookings/'.$bookingId)
                : null,
            'workflow_fields'=>$workflowFields,
            'commercial_fields'=>$commercialFields,
            'identity_fields'=>$identityFields,
            'columns'=>$this->columns($table),
        ];
    }

    private function firstValue(
        array $attributes,
        array $fields,
        mixed $default=''
    ): mixed {
        foreach ($fields as $field) {
            if (
                array_key_exists($field,$attributes)
                && $attributes[$field]!==null
                && trim((string)$attributes[$field])!==''
            ) {
                return $attributes[$field];
            }
        }

        return $default;
    }

    private function columns(string $table): array
    {
        try {
            return Schema::getColumnListing($table);
        } catch (Throwable) {
            return [];
        }
    }

    private function invoiceUrl(int $invoiceId): string
    {
        if (Route::has('sales.invoices.show')) {
            try {
                return route(
                    'sales.invoices.show',
                    ['invoice'=>$invoiceId]
                );
            } catch (Throwable) {
            }
        }

        return url('/sales/invoices/'.$invoiceId);
    }

    private function workflowRoutes(): array
    {
        $rows=[];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name=(string)$route->getName();
            $uri=(string)$route->uri();
            $action=(string)$route->getActionName();
            $haystack=strtolower($name.' '.$uri.' '.$action);

            if (
                !str_contains($haystack,'invoice')
                || !(
                    str_contains($haystack,'submit')
                    || str_contains($haystack,'approv')
                    || str_contains($haystack,'post')
                    || str_contains($haystack,'workflow')
                )
            ) {
                continue;
            }

            try {
                $middleware=$route->gatherMiddleware();
            } catch (Throwable) {
                $middleware=(array)($route->getAction('middleware')??[]);
            }

            $rows[]=[
                'name'=>$name,
                'methods'=>implode('|',$route->methods()),
                'uri'=>$uri,
                'action'=>$action,
                'middleware'=>implode(
                    ' | ',
                    array_map(
                        static fn($m): string =>
                            is_string($m)
                                ? $m
                                : get_debug_type($m),
                        $middleware
                    )
                ),
            ];
        }

        usort(
            $rows,
            static fn(array $a,array $b): int =>
                strcmp(
                    $a['uri'].' '.$a['methods'],
                    $b['uri'].' '.$b['methods']
                )
        );

        return $rows;
    }

    private function nativeClassSnapshot(): array
    {
        $classes=[
            \App\Services\Sales\SalesInvoiceService::class,
            \App\Http\Controllers\Sales\SalesInvoiceController::class,
            \App\Services\Sales\NativeSalesInvoiceWorkflowExecutor::class,
            \App\Http\Controllers\Sales\StableSalesInvoiceWorkflowController::class,
            \App\Models\SalesInvoice::class,
        ];

        $rows=[];

        foreach ($classes as $class) {
            $row=[
                'class'=>$class,
                'exists'=>class_exists($class),
                'methods'=>[],
            ];

            if (!$row['exists']) {
                $rows[]=$row;
                continue;
            }

            try {
                $ref=new ReflectionClass($class);

                foreach (
                    $ref->getMethods(ReflectionMethod::IS_PUBLIC)
                    as $method
                ) {
                    if (
                        $method->getDeclaringClass()->getName()
                        !== $class
                    ) {
                        continue;
                    }

                    $name=strtolower($method->getName());

                    if (
                        $class===\App\Services\Sales\SalesInvoiceService::class
                        || $class===\App\Http\Controllers\Sales\SalesInvoiceController::class
                    ) {
                        if (
                            !(
                                str_contains($name,'submit')
                                || str_contains($name,'approv')
                                || str_contains($name,'post')
                                || str_contains($name,'workflow')
                                || str_contains($name,'create')
                                || str_contains($name,'update')
                            )
                        ) {
                            continue;
                        }
                    }

                    $params=[];

                    foreach ($method->getParameters() as $param) {
                        $type=$param->getType();
                        $typeName='';

                        if ($type instanceof ReflectionNamedType) {
                            $typeName=$type->getName();
                        } elseif ($type!==null) {
                            $typeName=(string)$type;
                        }

                        $part=($typeName!==''?$typeName.' ':'')
                            .'$'.$param->getName();

                        if ($param->isDefaultValueAvailable()) {
                            try {
                                $default=$param->getDefaultValue();
                                if (is_scalar($default)||$default===null) {
                                    $part.='='.var_export($default,true);
                                }
                            } catch (Throwable) {
                            }
                        }

                        $params[]=$part;
                    }

                    $row['methods'][]=[
                        'signature'=>$method->getName()
                            .'('.implode(', ',$params).')',
                        'file'=>$method->getFileName()
                            ? basename((string)$method->getFileName())
                            : '',
                        'line'=>$method->getStartLine(),
                    ];
                }
            } catch (Throwable $error) {
                $row['methods'][]=[
                    'signature'=>'[reflection failed: '.$error->getMessage().']',
                    'file'=>'',
                    'line'=>null,
                ];
            }

            $rows[]=$row;
        }

        return $rows;
    }
}
