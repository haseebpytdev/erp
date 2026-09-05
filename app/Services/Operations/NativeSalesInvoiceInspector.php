<?php

namespace App\Services\Operations;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * ERP-10.31.72
 *
 * Reads an already-created native Easy Ticket Sales Invoice for a booking,
 * and resolves the best native accounting URL:
 *
 * - invoice exists -> open/show/edit that invoice
 * - invoice absent -> local safe bridge -> actual native create route when present
 * - no native create GET route -> actual Sales Invoice Register with booking context
 *
 * It never creates a parallel invoice table.
 */
class NativeSalesInvoiceInspector
{
    public function all(int $bookingId): array
    {
        $linked = $this->linkedInvoices($bookingId);

        if ($linked) {
            return $linked;
        }

        foreach ($this->candidateTables() as $table) {
            if (! Schema::hasTable($table)) continue;

            try { $columns = Schema::getColumnListing($table); }
            catch (\Throwable) { continue; }

            $bookingColumn = $this->first($columns, ['booking_id','travel_booking_id','source_booking_id']);
            if (! $bookingColumn) continue;

            $idColumn = $this->first($columns, ['id','sales_invoice_id','invoice_id']);
            $numberColumn = $this->first($columns, ['invoice_number','invoice_no','invoice_reference','reference_no','reference','number','document_no']);
            $statusColumn = $this->first($columns, ['status','invoice_status','document_status']);
            $amountColumn = $this->first($columns, ['grand_total','total_amount','net_total','total','amount','invoice_total']);
            $createdColumn = $this->first($columns, ['created_at','invoice_date','date']);
            $updatedColumn = $this->first($columns, ['updated_at','modified_at']);

            try {
                $query = DB::table($table)->where($bookingColumn, $bookingId);
                if ($idColumn) $query->orderBy($idColumn);
                $rows = $query->get();
            } catch (\Throwable) { continue; }

            if ($rows->isEmpty()) continue;

            return $rows->map(function ($row) use ($table,$idColumn,$numberColumn,$statusColumn,$amountColumn,$createdColumn,$updatedColumn): array {
                $a = (array) $row;
                return [
                    'table'=>$table,
                    'id'=>$idColumn ? (int)($a[$idColumn] ?? 0) : 0,
                    'number'=>$numberColumn ? trim((string)($a[$numberColumn] ?? '')) : '',
                    'status'=>$statusColumn ? strtolower(trim((string)($a[$statusColumn] ?? 'draft'))) : 'draft',
                    'amount'=>$amountColumn && array_key_exists($amountColumn,$a) ? (float)($a[$amountColumn] ?? 0) : null,
                    'created_at'=>$createdColumn ? ($a[$createdColumn] ?? null) : null,
                    'updated_at'=>$updatedColumn ? ($a[$updatedColumn] ?? null) : null,
                ];
            })->all();
        }
        return [];
    }

    public function summary(int $bookingId): array
    {
        $invoices = $this->all($bookingId);
        $active = array_values(array_filter($invoices, fn(array $i): bool =>
            !in_array(strtolower((string)($i['status'] ?? '')), ['cancelled','canceled','void','voided','rejected'], true)
        ));
        $latest = $active ? $active[array_key_last($active)] : null;
        $known = array_values(array_filter(array_column($active,'amount'), fn($v): bool => $v !== null));

        return [
            'count'=>count($active),
            'all_count'=>count($invoices),
            'invoices'=>$invoices,
            'active_invoices'=>$active,
            'latest'=>$latest,
            'total_amount'=>$known ? round(array_sum($known),2) : null,
            'amounts_known'=>count($active)>0 && count($known)===count($active),
            'has_posted'=>(bool)array_filter($active, fn(array $i): bool =>
                in_array(strtolower((string)($i['status'] ?? '')), ['posted','posted_to_gl','final','finalized'], true)
            ),
        ];
    }

    public function find(int $bookingId): ?array
    {
        return $this->summary($bookingId)['latest'] ?? null;
    }

    public function action(int $bookingId): array
    {
        $invoice = $this->find($bookingId);

        if ($invoice) {
            $number = trim((string) ($invoice['number'] ?? ''));
            $status = strtolower(trim((string) ($invoice['status'] ?? '')));

            /*
             * A Draft Group Umrah invoice must pass through the bridge before
             * opening so changed Adult/Child/Infant commercial data can
             * synchronize the SAME Draft instead of creating a duplicate.
             */
            if ($status === 'draft') {
                return [
                    'url' => route(
                        'operations.bookings.group-umrah-sales-invoice.bridge',
                        ['booking' => $bookingId]
                    ),
                    'label' => $number !== ''
                        ? 'Sync / Open '.$number
                        : 'Sync / Open Sales Invoice',
                    'mode' => 'sync_draft',
                    'invoice' => $invoice,
                ];
            }

            return [
                'url' => route(
                    'operations.bookings.group-umrah-sales-invoice.bridge',
                    ['booking' => $bookingId]
                ),
                'label' => $number !== ''
                    ? 'Open '.$number
                    : 'Open Sales Invoice',
                'mode' => 'open_bridge',
                'invoice' => $invoice,
            ];
        }

        return [
            'url' => route(
                'operations.bookings.group-umrah-sales-invoice.bridge',
                ['booking' => $bookingId]
            ),
            'label' => 'Create Sales Invoice',
            'mode' => 'create_bridge',
            'invoice' => null,
        ];
    }

    public function workflowAction(int $bookingId): array
    {
        $invoice=$this->find($bookingId);
        if (!$invoice) return ['status'=>'not_created','status_label'=>'Not Created','action'=>null];

        $invoiceId=(int)($invoice['id'] ?? 0);
        $status=str_replace([' ','-'],'_',strtolower(trim((string)($invoice['status'] ?? 'draft'))));
        $label=ucwords(str_replace('_',' ',$status));
        $definition=null;

        if (in_array($status,['draft','new'],true)) {
            $definition=['routes'=>['sales.invoices.submit'],'keywords'=>['submit'],'label'=>'Submit for Approval'];
        } elseif (in_array($status,['pending','pending_approval','pendingapproval','submitted','pending_review','awaiting_approval'],true)) {
            $definition=['routes'=>['sales.invoices.approve'],'keywords'=>['approve'],'label'=>'Approve Invoice'];
        } elseif (in_array($status,['approved','authorized','authorised'],true)) {
            $definition=['routes'=>['sales.invoices.post'],'keywords'=>['post'],'label'=>'Post Invoice'];
        }

        $action=null;
        if ($definition && $invoiceId>0) {
            $native=$this->resolveNativeInvoiceWorkflowRoute(
                $invoiceId,
                $definition['routes'],
                $definition['keywords']
            );

            if ($native) {
                $workflowAction=match($definition['label']) {
                    'Submit for Approval'=>'submit',
                    'Approve Invoice'=>'approve',
                    'Post Invoice'=>'post',
                    default=>null,
                };

                if ($workflowAction) {
                    $action=[
                        'url'=>route(
                            'operations.bookings.group-umrah-sales-invoice.workflow',
                            ['booking'=>$bookingId,'action'=>$workflowAction]
                        ),
                        'method'=>'POST',
                        'label'=>$definition['label'],
                        'native'=>$native,
                    ];
                }
            }
        }

        return ['status'=>$status,'status_label'=>$label ?: 'Unknown','action'=>$action,'invoice'=>$invoice];
    }

    private function resolveNativeInvoiceWorkflowRoute(int $invoiceId,array $preferredNames,array $keywords): ?array
    {
        foreach ($preferredNames as $name) {
            $route=Route::getRoutes()->getByName($name);
            if ($route instanceof LaravelRoute) {
                $payload=$this->workflowRoutePayload($route,$invoiceId);
                if ($payload) return $payload;
            }
        }

        $candidates=[];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name=strtolower((string)$route->getName());
            $uri=strtolower((string)$route->uri());
            $actionName=strtolower((string)$route->getActionName());
            $haystack=$name.' '.$uri.' '.$actionName;
            if (!str_contains($haystack,'invoice') || str_contains($haystack,'vendor') || str_contains($haystack,'purchase')) continue;
            $hit=false; foreach ($keywords as $keyword) { if (str_contains($haystack,strtolower($keyword))) { $hit=true; break; } }
            if (!$hit) continue;
            $payload=$this->workflowRoutePayload($route,$invoiceId); if (!$payload) continue;
            $score=0;
            if (str_contains($name,'sales.invoices')) $score+=100;
            if (str_contains($actionName,'salesinvoicecontroller')) $score+=80;
            foreach ($keywords as $keyword) { if (str_contains($name,strtolower($keyword))) $score+=60; if (str_contains($actionName,strtolower($keyword))) $score+=40; }
            $candidates[]=['score'=>$score,'payload'=>$payload];
        }
        if (!$candidates) return null;
        usort($candidates,fn(array $a,array $b): int => $b['score']<=>$a['score']);
        return $candidates[0]['payload'];
    }

    private function workflowRoutePayload(LaravelRoute $route,int $invoiceId): ?array
    {
        $methods=array_values(array_filter($route->methods(),fn(string $m): bool => !in_array($m,['GET','HEAD'],true)));
        if (!$methods) return null;
        $method=in_array('POST',$methods,true) ? 'POST' : (in_array('PUT',$methods,true) ? 'PUT' : (in_array('PATCH',$methods,true) ? 'PATCH' : $methods[0]));
        $values=[];
        foreach (method_exists($route,'parameterNames') ? $route->parameterNames() : [] as $parameter) {
            $lower=strtolower($parameter);
            if (str_contains($lower,'invoice') || in_array($lower,['id','sales_invoice'],true)) { $values[$parameter]=$invoiceId; continue; }
            if (!str_contains($route->uri(),'{'.$parameter.'?}')) return null;
        }
        try {
            $url=$route->getName() ? route($route->getName(),$values) : $this->urlForRoute($route,$values);
            return $url ? ['url'=>$url,'method'=>$method,'route_name'=>(string)$route->getName()] : null;
        } catch (\Throwable) { return null; }
    }

    public function supplementaryAction(int $bookingId, int $amendmentId): array
    {
        return [
            'url' => route(
                'operations.bookings.group-umrah-sales-invoice.bridge',
                [
                    'booking' => $bookingId,
                    'invoice_mode' => 'supplementary',
                    'group_umrah_amendment_id' => $amendmentId,
                ]
            ),
            'label' => 'Create Supplementary Invoice',
            'mode' => 'supplementary_bridge',
            'invoice' => null,
        ];
    }

    /**
     * Resolve a REAL native Sales Invoice create/new GET route.
     *
     * Route action/controller information is inspected in addition to URI/name,
     * so installations using a non-standard URL still work.
     */
    public function nativeCreateUrl(array $context = []): ?string
    {
        $candidates = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = strtolower((string) $route->uri());
            $name = strtolower((string) $route->getName());
            $action = strtolower((string) $route->getActionName());
            $haystack = $uri.' '.$name.' '.$action;

            if ($this->isOurBridge($route)) {
                continue;
            }

            if (
                ! str_contains($haystack, 'invoice')
                || str_contains($haystack, 'vendor')
                || str_contains($haystack, 'purchase')
            ) {
                continue;
            }

            $controllerCreate = (
                str_contains($action, 'salesinvoicecontroller')
                && (
                    str_ends_with($action, '@create')
                    || str_contains($action, '@createfrom')
                    || str_contains($action, '@new')
                    || str_contains($action, '@compose')
                )
            );

            $routeCreate = (
                str_contains($name, 'create')
                || str_contains($name, '.new')
                || str_ends_with($uri, '/create')
                || str_ends_with($uri, '/new')
                || str_contains($uri, '/create/')
            );

            if (! $controllerCreate && ! $routeCreate) {
                continue;
            }

            $url = $this->urlForRoute($route, []);
            if (! $url) {
                continue;
            }

            $url = $this->appendQuery($url, $context);

            $score = 0;
            if (str_contains($action, 'salesinvoicecontroller')) $score += 180;
            if ($controllerCreate) $score += 140;
            if (str_contains($uri, 'sales/invoices')) $score += 120;
            if (str_contains($name, 'sales')) $score += 50;
            if (str_contains($name, 'create')) $score += 50;
            if (str_ends_with($uri, '/create')) $score += 40;

            $candidates[] = [$score, $url];
        }

        return $this->bestUrl($candidates);
    }

    /**
     * Resolve the real native Sales Invoice register/index.
     */
    public function nativeRegisterUrl(array $context = []): ?string
    {
        $candidates = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = strtolower(trim((string) $route->uri(), '/'));
            $name = strtolower((string) $route->getName());
            $action = strtolower((string) $route->getActionName());
            $haystack = $uri.' '.$name.' '.$action;

            if ($this->isOurBridge($route)) {
                continue;
            }

            if (
                ! str_contains($haystack, 'invoice')
                || str_contains($haystack, 'vendor')
                || str_contains($haystack, 'purchase')
                || str_contains($haystack, 'create')
            ) {
                continue;
            }

            $parameters = method_exists($route, 'parameterNames')
                ? $route->parameterNames()
                : [];

            $required = array_filter(
                $parameters,
                fn (string $parameter): bool =>
                    ! str_contains($route->uri(), '{'.$parameter.'?}')
            );

            if ($required) {
                continue;
            }

            $exactRegister = $uri === 'sales/invoices';
            $controllerIndex = (
                str_contains($action, 'salesinvoicecontroller')
                && str_ends_with($action, '@index')
            );
            $namedIndex = (
                str_contains($name, 'invoice')
                && (
                    str_contains($name, 'index')
                    || str_contains($name, 'register')
                    || str_contains($name, 'list')
                )
            );

            if (! $exactRegister && ! $controllerIndex && ! $namedIndex) {
                continue;
            }

            $url = $this->urlForRoute($route, []);
            if (! $url) {
                continue;
            }

            $url = $this->appendQuery($url, $context);

            $score = 0;
            if ($exactRegister) $score += 250;
            if (str_contains($action, 'salesinvoicecontroller')) $score += 160;
            if ($controllerIndex) $score += 100;
            if (str_contains($name, 'sales')) $score += 50;

            $candidates[] = [$score, $url];
        }

        return $this->bestUrl($candidates);
    }

    public function nativeInvoiceUrl(int $invoiceId): ?string
    {
        if ($invoiceId <= 0) {
            return null;
        }

        $candidates = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = strtolower((string) $route->uri());
            $name = strtolower((string) $route->getName());
            $action = strtolower((string) $route->getActionName());
            $haystack = $uri.' '.$name.' '.$action;

            if ($this->isOurBridge($route)) {
                continue;
            }

            if (
                ! str_contains($haystack, 'invoice')
                || str_contains($haystack, 'vendor')
                || str_contains($haystack, 'purchase')
                || str_contains($haystack, 'create')
            ) {
                continue;
            }

            $parameters = method_exists($route, 'parameterNames')
                ? $route->parameterNames()
                : [];
            $values = [];
            $hasInvoiceParameter = false;
            $unsafe = false;

            foreach ($parameters as $parameter) {
                $key = strtolower($parameter);

                if (
                    str_contains($key, 'invoice')
                    || in_array($key, ['id', 'sales_invoice'], true)
                ) {
                    $values[$parameter] = $invoiceId;
                    $hasInvoiceParameter = true;
                    continue;
                }

                if (! str_contains($route->uri(), '{'.$parameter.'?}')) {
                    $unsafe = true;
                    break;
                }
            }

            if ($unsafe || ! $hasInvoiceParameter) {
                continue;
            }

            $url = $this->urlForRoute($route, $values);
            if (! $url) {
                continue;
            }

            $score = 0;
            if (str_contains($action, 'salesinvoicecontroller')) $score += 150;
            if (str_contains($uri, 'sales/invoices')) $score += 120;
            if (str_contains($name, 'show')) $score += 50;
            if (str_contains($name, 'edit')) $score += 30;
            if (str_contains($action, '@show')) $score += 50;
            if (str_contains($action, '@edit')) $score += 30;

            $candidates[] = [$score, $url];
        }

        return $this->bestUrl($candidates);
    }

    private function isOurBridge(LaravelRoute $route): bool
    {
        return str_contains(
            strtolower((string) $route->uri()),
            'group-package-bookings'
        );
    }

    private function urlForRoute(LaravelRoute $route, array $values): ?string
    {
        try {
            $parameters = method_exists($route, 'parameterNames')
                ? $route->parameterNames()
                : [];

            foreach ($parameters as $parameter) {
                if (array_key_exists($parameter, $values)) {
                    continue;
                }

                if (! str_contains($route->uri(), '{'.$parameter.'?}')) {
                    return null;
                }
            }

            if ($route->getName()) {
                return route($route->getName(), $values);
            }

            $uri = $route->uri();

            foreach ($values as $parameter => $value) {
                $uri = str_replace(
                    ['{'.$parameter.'}', '{'.$parameter.'?}'],
                    (string) $value,
                    $uri
                );
            }

            $uri = preg_replace('#/\{[^}]+\?\}#', '', $uri) ?: $uri;

            return url('/'.ltrim($uri, '/'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function appendQuery(string $url, array $context): string
    {
        $context = array_filter(
            $context,
            static fn (mixed $value): bool =>
                $value !== null && $value !== ''
        );

        if (! $context) {
            return $url;
        }

        return $url
            .(str_contains($url, '?') ? '&' : '?')
            .http_build_query($context);
    }

    private function bestUrl(array $candidates): ?string
    {
        if (! $candidates) {
            return null;
        }

        usort(
            $candidates,
            fn (array $a, array $b): int => $b[0] <=> $a[0]
        );

        return $candidates[0][1] ?? null;
    }

    private function linkedInvoices(int $bookingId): array
    {
        if (! Schema::hasTable('booking_group_umrah_invoice_links')) return [];

        try {
            $links = DB::table('booking_group_umrah_invoice_links')->where('booking_id', $bookingId)->orderBy('id')->get();
        } catch (\Throwable) { return []; }

        $result = [];

        foreach ($links as $link) {
            $table = trim((string) ($link->invoice_table ?? ''));
            $invoiceId = (int) ($link->invoice_id ?? 0);
            if ($table === '' || $invoiceId <= 0 || ! Schema::hasTable($table)) continue;

            try { $columns = Schema::getColumnListing($table); } catch (\Throwable) { continue; }
            $idColumn = $this->first($columns, ['id', 'sales_invoice_id', 'invoice_id']);
            if (! $idColumn) continue;
            try { $row = DB::table($table)->where($idColumn, $invoiceId)->first(); } catch (\Throwable) { continue; }
            if (! $row) continue;

            $a = (array) $row;
            $numberColumn = $this->first($columns, ['invoice_number', 'invoice_no', 'invoice_reference', 'reference_no', 'reference', 'number', 'document_no']);
            $statusColumn = $this->first($columns, ['status', 'invoice_status', 'document_status']);
            $amountColumn = $this->first($columns, ['grand_total', 'total_amount', 'net_total', 'total', 'amount', 'invoice_total']);

            $result[] = [
                'table' => $table,
                'id' => $invoiceId,
                'number' => $numberColumn ? trim((string) ($a[$numberColumn] ?? '')) : trim((string) ($link->invoice_number ?? '')),
                'status' => $statusColumn ? strtolower(trim((string) ($a[$statusColumn] ?? 'draft'))) : 'draft',
                'amount' => $amountColumn && array_key_exists($amountColumn, $a) ? (float) ($a[$amountColumn] ?? 0) : null,
                'created_at' => $a['created_at'] ?? ($link->created_at ?? null),
                'updated_at' => $a['updated_at'] ?? ($link->updated_at ?? null),
            ];
        }

        return $result;
    }

    private function candidateTables(): array
    {
        $tables = ['sales_invoices','sales_invoice_headers','sales_invoice','customer_invoices','invoices'];
        try {
            foreach (Schema::getTables() as $meta) {
                $name = is_array($meta) ? (string) ($meta['name'] ?? $meta['table_name'] ?? '') : '';
                if ($name === '') continue;
                $lower=strtolower($name);
                if (str_contains($lower,'vendor') || str_contains($lower,'purchase') || str_contains($lower,'line') || str_contains($lower,'item') || str_contains($lower,'detail')) continue;
                try { $columns=Schema::getColumnListing($name); } catch (\Throwable) { continue; }
                $hasId=$this->first($columns,['id','sales_invoice_id','invoice_id'])!==null;
                $hasCustomer=$this->first($columns,['customer_id','party_id','client_id','customer_party_id','bill_to_party_id'])!==null;
                $hasAmount=$this->first($columns,['grand_total','total_amount','net_total','invoice_total','total','amount'])!==null;
                $hasNumber=$this->first($columns,['invoice_number','invoice_no','invoice_reference','document_no','reference_no','number'])!==null;
                if ($hasId && (str_contains($lower,'invoice') || ($hasCustomer && $hasAmount && $hasNumber) || ((str_contains($lower,'sales') || str_contains($lower,'document') || str_contains($lower,'commercial')) && $hasCustomer && $hasAmount))) $tables[]=$name;
            }
        } catch (\Throwable) {}
        return array_values(array_unique($tables));
    }

    private function first(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
