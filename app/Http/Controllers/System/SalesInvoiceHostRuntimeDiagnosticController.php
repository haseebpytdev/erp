<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Administration\ErpPermissionMatrixService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionNamedType;

/** Temporary read-only diagnostic; remove after the host confirmation predicate is reconciled. */
final class SalesInvoiceHostRuntimeDiagnosticController extends Controller
{
    public function __invoke(Request $request, int $booking, ErpPermissionMatrixService $permissions): JsonResponse
    {
        abort_unless($permissions->isSuperAdmin($request->user()), 403);
        abort_unless(Schema::hasTable('bookings'), 404, 'Native bookings table is unavailable.');

        $serviceClass = \App\Services\Sales\SalesInvoiceService::class;
        abort_unless(class_exists($serviceClass) && method_exists($serviceClass, 'createFromBooking'), 404, 'Native Sales Invoice service is unavailable.');

        $method = new ReflectionMethod($serviceClass, 'createFromBooking');
        $parameters = array_map(fn ($parameter): array => [
            'name'=>$parameter->getName(),
            'type'=>$parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : null,
            'nullable'=>$parameter->allowsNull(),
        ], $method->getParameters());
        $bookingClass = $this->bookingParameterClass($method);
        $row = (array) (DB::table('bookings')->where('id', $booking)->first() ?? []);
        abort_unless($row, 404, 'Booking not found.');
        $model = $this->freshBooking($bookingClass, $booking);
        $lines = $this->confirmationLines($method);

        return response()->json([
            'service_file'=>basename((string) $method->getFileName()),
            'method_parameters'=>$parameters,
            'booking_parameter_type'=>$bookingClass,
            'fresh_booking_class'=>$model ? $model::class : null,
            'db_status_raw'=>$row['status'] ?? null,
            'model_status_value'=>$model?->getAttribute('status'),
            'status_cast_type'=>$model ? (($model->getCasts()['status'] ?? null) ?: gettype($model->getAttribute('status'))) : null,
            'approval_status'=>$row['approval_status'] ?? null,
            'travel_status'=>$row['travel_status'] ?? null,
            'method_lines_containing_status_or_confirmed'=>$lines,
            'expected_confirmation_field'=>$this->expectedField($lines),
            'expected_confirmation_value'=>$this->expectedValue($lines),
            'create_from_booking_reloads_booking'=>$this->reloadsBooking($lines),
        ]);
    }

    private function bookingParameterClass(ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_a($type->getName(), Model::class, true) && str_contains(strtolower($type->getName()), 'booking')) return $type->getName();
        }
        return null;
    }

    private function freshBooking(?string $class, int $booking): ?Model
    {
        if (! $class || ! class_exists($class)) return null;
        $model = new $class();
        return $model->newQuery()->find($booking);
    }

    private function confirmationLines(ReflectionMethod $method): array
    {
        $file = $method->getFileName();
        if (! $file || ! is_readable($file)) return [];
        $source = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $result = [];
        for ($line = $method->getStartLine(); $line <= $method->getEndLine(); $line++) {
            $value = trim((string) ($source[$line - 1] ?? ''));
            if ($value !== '' && preg_match('/(?:status|confirm)/i', $value)) $result[] = ['line'=>$line, 'code'=>mb_substr($value, 0, 500)];
        }
        return $result;
    }

    private function expectedField(array $lines): ?string
    {
        foreach ($lines as $line) if (preg_match('/\$booking->([A-Za-z_][A-Za-z0-9_]*)/', (string) $line['code'], $match)) return $match[1];
        return null;
    }

    private function expectedValue(array $lines): ?string
    {
        foreach ($lines as $line) if (preg_match('/[\'\"](confirmed|approved|booked|finalized?)[\'\"]/i', (string) $line['code'], $match)) return strtolower($match[1]);
        return null;
    }

    private function reloadsBooking(array $lines): bool
    {
        foreach ($lines as $line) if (preg_match('/(?:Booking::|->(?:find|findOrFail|first|where)\s*\()/i', (string) $line['code'])) return true;
        return false;
    }
}
