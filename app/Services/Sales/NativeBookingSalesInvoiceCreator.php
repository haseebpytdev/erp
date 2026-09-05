<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * ERP-11.3.75
 *
 * Runtime adapter around the host ERP's native
 * App\Services\Sales\SalesInvoiceService::createFromBooking().
 *
 * The cumulative package intentionally does not ship/replace the host
 * SalesInvoiceController. This adapter calls the native domain service directly,
 * resolving its actual runtime method signature with reflection.
 */
final class NativeBookingSalesInvoiceCreator
{
    public function create(Request $request, int $bookingId): mixed
    {
        if ($bookingId <= 0) {
            throw ValidationException::withMessages([
                'invoice' => 'A valid booking is required to create a Sales Invoice.',
            ]);
        }

        $class = \App\Services\Sales\SalesInvoiceService::class;

        if (! class_exists($class)) {
            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice service is unavailable.',
            ]);
        }

        $native = app($class);

        if (! method_exists($native, 'createFromBooking')) {
            throw ValidationException::withMessages([
                'invoice' => 'The native Sales Invoice create-from-booking operation is unavailable.',
            ]);
        }

        $method = new ReflectionMethod($native, 'createFromBooking');

        if (! $method->isPublic()) {
            $method->setAccessible(true);
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
                    $arguments[] = $this->nativeRequest($request, $bookingId);
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

                if (
                    is_a($className, Model::class, true)
                    && (
                        str_contains(strtolower($className), 'booking')
                        || str_contains($name, 'booking')
                    )
                ) {
                    /** @var Model $model */
                    $model = new $className();

                    $booking = $model->newQuery()->find($bookingId);

                    if (! $booking) {
                        throw ValidationException::withMessages([
                            'invoice' => 'The booking could not be resolved for Sales Invoice creation.',
                        ]);
                    }

                    $arguments[] = $booking;
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
                        str_contains($name, 'booking')
                        || $name === 'id'
                    )
                ) {
                    $arguments[] = $bookingId;
                    continue;
                }

                if ($builtin === 'array') {
                    $arguments[] = $request->all();
                    continue;
                }

                if ($builtin === 'bool') {
                    if ($parameter->isDefaultValueAvailable()) {
                        $arguments[] = $parameter->getDefaultValue();
                    } else {
                        $arguments[] = false;
                    }
                    continue;
                }

                if ($builtin === 'string') {
                    if (str_contains($name, 'booking')) {
                        $arguments[] = (string) $bookingId;
                        continue;
                    }

                    if ($parameter->isDefaultValueAvailable()) {
                        $arguments[] = $parameter->getDefaultValue();
                        continue;
                    }
                }
            }

            if (
                str_contains($name, 'booking')
                || $name === 'id'
            ) {
                $arguments[] = $bookingId;
                continue;
            }

            if (
                in_array(
                    $name,
                    ['request', 'http_request'],
                    true
                )
            ) {
                $arguments[] = $this->nativeRequest($request, $bookingId);
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
                'invoice' => 'The native Sales Invoice service has an unsupported createFromBooking parameter: $'
                    .$parameter->getName().'.',
            ]);
        }

        Log::info('ERP-11.3.75 invoking native SalesInvoiceService::createFromBooking.', [
            'booking_id' => $bookingId,
            'parameter_count' => count($arguments),
        ]);

        return $method->invokeArgs($native, $arguments);
    }

    private function nativeRequest(Request $outer, int $bookingId): Request
    {
        $request = Request::create(
            '/internal/native-sales-invoice/from-booking/'.$bookingId,
            'POST',
            $outer->all(),
            $outer->cookies->all(),
            [],
            $outer->server->all()
        );

        if ($outer->hasSession()) {
            $request->setLaravelSession($outer->session());
        }

        $request->setUserResolver(
            fn () => $outer->user()
        );

        $request->headers->set(
            'X-Requested-With',
            (string) $outer->headers->get(
                'X-Requested-With',
                'XMLHttpRequest'
            )
        );

        return $request;
    }
}
