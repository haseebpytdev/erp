<?php

namespace App\Services\Operations;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;

/**
 * Request-local, non-persistent timing diagnostics for dedicated product pages.
 * This context deliberately has no effect unless the current request is one of
 * the dedicated /operations/bookings/{id}/products/{product} routes.
 */
final class DedicatedProductTimingContext
{
    private const ATTRIBUTE = '_et_dedicated_product_timing';

    private float $startedAt;
    private array $durations = [];
    private array $counters = [];
    private array $marks = [];
    private string $customerBranch = 'unknown';
    private bool $listenerAttached = false;

    private function __construct()
    {
        $this->startedAt = hrtime(true) / 1_000_000;
        $this->start('product_pipeline_total');
    }

    public static function forRequest(Request $request): ?self
    {
        if (! self::isDedicatedProductRequest($request)) {
            return null;
        }

        $existing = $request->attributes->get(self::ATTRIBUTE);
        if ($existing instanceof self) {
            return $existing;
        }

        $context = new self();
        $request->attributes->set(self::ATTRIBUTE, $context);
        $context->attachDbListener();

        return $context;
    }

    public static function fromRequest(Request $request): ?self
    {
        $context = $request->attributes->get(self::ATTRIBUTE);

        return $context instanceof self ? $context : null;
    }

    public static function isDedicatedProductRequest(Request $request): bool
    {
        return preg_match(
            '#^operations/bookings/\d+/products/(?:air|hotel|transport|visa|other-services)$#i',
            trim($request->path(), '/')
        ) === 1;
    }

    public function start(string $name): void
    {
        $this->marks[$name] = hrtime(true) / 1_000_000;
    }

    public function stop(string $name): void
    {
        if (! isset($this->marks[$name])) {
            return;
        }

        $this->durations[$name] = round(
            (hrtime(true) / 1_000_000) - $this->marks[$name],
            3
        );
        unset($this->marks[$name]);
    }

    public function addMeasuredDuration(string $name, float $milliseconds): void
    {
        $this->durations[$name] = round(
            ($this->durations[$name] ?? 0) + max(0, $milliseconds),
            3
        );
    }

    public function measureAccumulating(string $name, callable $callback): mixed
    {
        $started = hrtime(true) / 1_000_000;

        try {
            return $callback();
        } finally {
            $this->addMeasuredDuration($name, (hrtime(true) / 1_000_000) - $started);
        }
    }

    public function measure(string $name, callable $callback): mixed
    {
        $this->start($name);

        try {
            return $callback();
        } finally {
            $this->stop($name);
        }
    }

    public function addDuration(string $name, float $milliseconds): void
    {
        $this->durations[$name] = round(max(0, $milliseconds), 3);
    }

    public function increment(string $name, int $amount = 1): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + $amount;
    }

    public function setCustomerBranch(string $branch): void
    {
        $this->customerBranch = preg_replace('/[^A-Za-z0-9_.:-]/', '', $branch) ?: 'unknown';
    }

    public function finishResponse(Response $response): Response
    {
        $serverTiming = [];
        foreach ([
            'controller_total' => 'controller',
            'downstream_response' => 'downstream',
            'product_pipeline_total' => 'pipeline-total',
            'schema_has_bookings' => 'schema',
            'booking_query' => 'booking-query',
            'layout_resolve' => 'layout',
            'customer_resolve' => 'customer',
            'lock_from_row' => 'lock-row',
            'view_object_create' => 'view-object',
            'presenter_lock_resolve' => 'presenter-lock',
            'presenter_transform_total' => 'presenter',
            'db_total' => 'db-total',
        ] as $key => $label) {
            if (isset($this->durations[$key])) {
                $serverTiming[] = $label.';dur='.$this->durations[$key];
            }
        }

        $serverTiming[] = 'db-count;desc="'.(int) ($this->counters['db_count'] ?? 0).'"';
        $response->headers->set('Server-Timing', implode(', ', $serverTiming));
        $response->headers->set('X-ET-Product-Diag', '1');
        $response->headers->set('X-ET-Customer-Branch', $this->customerBranch);
        $response->headers->set('X-ET-DB-Count', (string) (int) ($this->counters['db_count'] ?? 0));

        return $response;
    }

    private function attachDbListener(): void
    {
        if ($this->listenerAttached) {
            return;
        }

        $this->listenerAttached = true;
        DB::listen(function (QueryExecuted $query): void {
            $this->increment('db_count');
            $this->addMeasuredDuration('db_total', (float) $query->time);
        });
    }
}
