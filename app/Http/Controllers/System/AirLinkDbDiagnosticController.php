<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Services\Operations\GenericServicePassengerLinkSynchronizer;
use App\Services\Operations\VisaBookingServiceSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Temporary, Super-Admin-only, read-only inspection of the native Air link
 * authority. Native ticket rows are linked through booking_service_id, never
 * assumed to carry a direct booking_id column.
 */
final class AirLinkDbDiagnosticController extends Controller
{
    public function __construct(
        private readonly GenericServicePassengerLinkSynchronizer $passengerLinks,
        private readonly VisaBookingServiceSynchronizer $visaServices,
    ) {}

    public function __invoke(Request $request, int $booking, ErpPermissionMatrixService $permissions)
    {
        abort_unless($permissions->isSuperAdmin($request->user()), 403);

        $errors = [];
        $columns = static function (string $table) use (&$errors): array {
            try {
                return Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
            } catch (Throwable $exception) {
                $errors[] = $table.': '.$exception->getMessage();
                return [];
            }
        };

        $serviceColumns = $columns('booking_services');
        $ticketColumns = $columns('air_ticket_details');
        $passengerColumns = $columns('booking_passengers');
        $airService = null;
        $ticketRows = [];
        $passengerRows = [];

        try {
            if (in_array('booking_id', $serviceColumns, true)) {
                $services = DB::table('booking_services')->where('booking_id', $booking)->get();
                $airService = $services->first(static function (object $row): bool {
                    $data = (array) $row;
                    $text = strtolower(implode(' ', array_map('strval', array_intersect_key($data, array_flip([
                        'service_name', 'name', 'title', 'description', 'service_type', 'product_type',
                    ])))));

                    return str_contains($text, 'air ticket') || str_contains($text, 'flight');
                });
            }
        } catch (Throwable $exception) {
            $errors[] = 'booking_services: '.$exception->getMessage();
        }

        try {
            if ($airService && in_array('booking_service_id', $ticketColumns, true)) {
                $query = DB::table('air_ticket_details')
                    ->where('booking_service_id', (int) $airService->id);
                if (in_array('deleted_at', $ticketColumns, true)) {
                    $query->whereNull('deleted_at');
                }
                $ticketRows = $query
                    ->orderBy(in_array('id', $ticketColumns, true) ? 'id' : 'booking_service_id')
                    ->get()
                    ->map(static fn (object $row): array => (array) $row)
                    ->all();
            } elseif ($ticketColumns && ! in_array('booking_service_id', $ticketColumns, true)) {
                $errors[] = 'air_ticket_details has no booking_service_id column; native link rows cannot be inspected safely.';
            }
        } catch (Throwable $exception) {
            $errors[] = 'air_ticket_details: '.$exception->getMessage();
        }

        try {
            if (in_array('booking_id', $passengerColumns, true)) {
                $passengerRows = DB::table('booking_passengers')
                    ->where('booking_id', $booking)
                    ->orderBy(in_array('id', $passengerColumns, true) ? 'id' : 'booking_id')
                    ->get()
                    ->map(static fn (object $row): array => (array) $row)
                    ->all();
            }
        } catch (Throwable $exception) {
            $errors[] = 'booking_passengers: '.$exception->getMessage();
        }

        $linkedPassengerIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['booking_passenger_id'] ?? $row['passenger_id'] ?? 0),
            $ticketRows
        ))));

        $hostValidator = $this->hostPassengerValidator();
        $preNativeAudit = $this->preNativeServiceCommercialAudit($booking, $errors);
        $genericLinks = $this->genericPassengerLinks(
            $airService ? (int) $airService->id : 0,
            $errors
        );

        $linkColumns = static fn (array $list): array => array_values(array_filter(
            $list,
            static fn (string $column): bool => str_contains(strtolower($column), 'passenger')
                || str_contains(strtolower($column), 'service')
        ));

        return response()->json([
            'READ_ONLY' => true,
            'AIR_SERVICE_ROW' => $airService ? (array) $airService : null,
            'BOOKING_PASSENGER_IDS' => array_values(array_filter(array_map(
                static fn (array $row): int => (int) ($row['id'] ?? 0),
                $passengerRows
            ))),
            'BOOKING_SERVICES_COLUMNS' => $serviceColumns,
            'AIR_TICKET_DETAILS_COLUMNS' => $ticketColumns,
            'AIR_TICKET_ROWS' => $ticketRows,
            'NATIVE_LINKED_PASSENGER_IDS' => $linkedPassengerIds,
            'HOST_SALES_INVOICE_PASSENGER_VALIDATOR' => $hostValidator,
            'HOST_SALES_INVOICE_METHOD_FILE' => $hostValidator['method_file'] ?? null,
            'HOST_SALES_INVOICE_METHOD_START_LINE' => $hostValidator['method_start_line'] ?? null,
            'HOST_SALES_INVOICE_METHOD_END_LINE' => $hostValidator['method_end_line'] ?? null,
            'HOST_SALES_INVOICE_METHOD_SOURCE' => $hostValidator['method_source'] ?? null,
            'GENERIC_SERVICE_PASSENGER_LINK_CANDIDATES' => $genericLinks,
            'ACTIVE_SERVICE_PASSENGER_LINK_AUDIT' => $this->passengerLinks->auditActiveServices($booking),
            'PRE_NATIVE_SERVICE_COMMERCIAL_AUDIT' => $preNativeAudit,
            'AIR_SERVICE_PASSENGER_COLUMNS' => $linkColumns($serviceColumns),
            'AIR_TICKET_PASSENGER_COLUMNS' => $linkColumns($ticketColumns),
            'DIAGNOSTIC_ERRORS' => $errors,
        ]);
    }

    /** @return array<string,mixed> */
    private function hostPassengerValidator(): array
    {
        $class = \App\Services\Sales\SalesInvoiceService::class;
        if (! class_exists($class) || ! method_exists($class, 'createFromBooking')) {
            return [
                'available' => false,
                'class' => $class,
                'method' => 'createFromBooking',
                'reason' => 'Host SalesInvoiceService is not included in CURRENT; inspect at production runtime only.',
            ];
        }

        try {
            $method = new \ReflectionMethod($class, 'createFromBooking');
            $file = (string) $method->getFileName();
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            $source = @file($file, FILE_IGNORE_NEW_LINES);
            $matchingLine = null;
            if (is_array($source)) {
                for ($line = $start - 1; $line < $end; $line++) {
                    if (str_contains(strtolower((string) ($source[$line] ?? '')), 'requires passenger links')) {
                        $matchingLine = $line + 1;
                        break;
                    }
                }
            }

            $snippet = [];
            if ($matchingLine !== null && is_array($source)) {
                $from = max($start, $matchingLine - 8);
                $to = min($end, $matchingLine + 8);
                for ($line = $from; $line <= $to; $line++) {
                    $snippet[] = $line.': '.trim((string) ($source[$line - 1] ?? ''));
                }
            }

            $methodSource = [];
            if (is_array($source)) {
                for ($line = $start; $line <= $end; $line++) {
                    $methodSource[] = $line.': '.(string) ($source[$line - 1] ?? '');
                }
            }

            return [
                'available' => true,
                'class' => $class,
                'method' => 'createFromBooking',
                'source_file' => basename($file),
                'method_file' => $file,
                'method_start_line' => $start,
                'method_end_line' => $end,
                'method_source' => implode("\n", $methodSource),
                'error_line' => $matchingLine,
                'validation_snippet' => $snippet,
                'method_parameters' => array_map(
                    static fn (\ReflectionParameter $parameter): string => '$'.$parameter->getName(),
                    $method->getParameters()
                ),
            ];
        } catch (Throwable $exception) {
            return [
                'available' => false,
                'class' => $class,
                'method' => 'createFromBooking',
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function preNativeServiceCommercialAudit(int $booking, array &$errors): array
    {
        $startingLevel = DB::transactionLevel();
        $audit = [
            'ROLLBACK_ONLY' => true,
            'NATIVE_INVOICE_CREATOR_CALLED' => false,
            'ACTIVE_SERVICE_COUNT' => 0,
            'SUM_LINE_TOTAL' => 0.0,
            'SUM_QUANTITY_X_UNIT_PRICE' => 0.0,
            'SERVICES' => [],
        ];

        try {
            DB::beginTransaction();
            $this->visaServices->synchronize($booking);
            $this->passengerLinks->reconcileDeterministicServicesForInvoice($booking);

            $columns = Schema::getColumnListing('booking_services');
            $linkAudit = collect($this->passengerLinks->auditActiveServices($booking))
                ->keyBy('booking_service_id');
            $rows = DB::table('booking_services')->where('booking_id', $booking)
                ->orderBy(in_array('id', $columns, true) ? 'id' : 'booking_id')
                ->get();

            $services = [];
            $sumLineTotal = 0.0;
            $sumQuantityRate = 0.0;
            foreach ($rows as $object) {
                $row = (array) $object;
                if (! $this->serviceIsActive($row)) continue;

                $serviceId = (int) ($row['id'] ?? 0);
                $quantity = (float) ($row['quantity'] ?? 0);
                $unitPrice = (float) ($row['unit_price'] ?? 0);
                $lineTotal = (float) ($row['line_total'] ?? 0);
                $sumLineTotal += $lineTotal;
                $sumQuantityRate += $quantity * $unitPrice;
                $link = $linkAudit->get($serviceId, []);

                $services[] = [
                    'id' => $serviceId,
                    'product_service_id' => $row['product_service_id'] ?? null,
                    'description' => $row['description'] ?? null,
                    'quantity' => $row['quantity'] ?? null,
                    'unit_price' => $row['unit_price'] ?? null,
                    'line_total' => $row['line_total'] ?? null,
                    'currency_code' => $row['currency_code'] ?? null,
                    'passenger_link_mode_snapshot' => $row['passenger_link_mode_snapshot'] ?? null,
                    'pricing_basis_snapshot' => $row['pricing_basis_snapshot'] ?? null,
                    'generic_linked_passenger_count' => $link['generic_linked_passenger_count'] ?? null,
                ];
            }

            $audit['ACTIVE_SERVICE_COUNT'] = count($services);
            $audit['SUM_LINE_TOTAL'] = round($sumLineTotal, 2);
            $audit['SUM_QUANTITY_X_UNIT_PRICE'] = round($sumQuantityRate, 2);
            $audit['SERVICES'] = $services;
        } catch (Throwable $exception) {
            $audit['ERROR'] = $exception->getMessage();
            $errors[] = 'pre-native service commercial audit: '.$exception->getMessage();
        } finally {
            while (DB::transactionLevel() > $startingLevel) {
                DB::rollBack();
            }
        }

        return $audit;
    }

    /** @param array<string,mixed> $service */
    private function serviceIsActive(array $service): bool
    {
        if (! empty($service['deleted_at'])) return false;
        if (array_key_exists('is_active', $service) && ! (bool) $service['is_active']) return false;
        if (array_key_exists('active', $service) && ! (bool) $service['active']) return false;
        return ! in_array(strtolower(trim((string) ($service['status'] ?? ''))), [
            'inactive', 'deleted', 'removed', 'cancelled', 'canceled',
        ], true);
    }

    /** @return array<string,array<string,mixed>> */
    private function genericPassengerLinks(int $serviceId, array &$errors): array
    {
        $result = [];
        if ($serviceId <= 0) return $result;

        $tables = [
            'booking_service_passengers',
            'booking_passenger_services',
            'service_passenger_links',
            'booking_service_passenger_links',
        ];

        // The host is not present in CURRENT, so do not assume its generic
        // pivot name. Discover only physical tables that can actually link a
        // booking service to passengers, without invoking model relations.
        try {
            if (method_exists(Schema::class, 'getTables')) {
                foreach (Schema::getTables() as $metadata) {
                    $table = is_array($metadata)
                        ? (string) ($metadata['name'] ?? '')
                        : (is_object($metadata) ? (string) ($metadata->name ?? '') : '');
                    $normalized = strtolower($table);
                    if ($table !== '' && str_contains($normalized, 'service') && str_contains($normalized, 'passenger')) {
                        $tables[] = $table;
                    }
                }
            }
        } catch (Throwable $exception) {
            $errors[] = 'generic passenger-link table discovery: '.$exception->getMessage();
        }

        foreach (array_values(array_unique($tables)) as $table) {
            try {
                if (! Schema::hasTable($table)) continue;
                $columns = Schema::getColumnListing($table);
                $serviceColumn = in_array('booking_service_id', $columns, true)
                    ? 'booking_service_id'
                    : (in_array('service_id', $columns, true) ? 'service_id' : null);
                $rows = $serviceColumn
                    ? DB::table($table)->where($serviceColumn, $serviceId)->get()
                        ->map(static fn (object $row): array => (array) $row)->all()
                    : [];
                $result[$table] = [
                    'service_column' => $serviceColumn,
                    'passenger_columns' => array_values(array_filter(
                        $columns,
                        static fn (string $column): bool => str_contains(strtolower($column), 'passenger')
                    )),
                    'indexes' => $this->schemaMetadata($table, 'getIndexes'),
                    'foreign_keys' => $this->schemaMetadata($table, 'getForeignKeys'),
                    'rows' => $rows,
                ];
            } catch (Throwable $exception) {
                $errors[] = $table.': '.$exception->getMessage();
            }
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function schemaMetadata(string $table, string $method): array
    {
        try {
            if (! method_exists(Schema::class, $method)) return [];
            return array_map(static fn ($row): array => (array) $row, call_user_func([Schema::class, $method], $table));
        } catch (Throwable) {
            return [];
        }
    }
}
