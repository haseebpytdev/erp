<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CustomerVendorBookingReportService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CustomerVendorBookingReportController extends Controller
{
    public function __construct(
        private readonly CustomerVendorBookingReportService $reports,
        private readonly NativeErpLayoutResolver $layout,
    ) {}

    public function index(Request $request)
    {
        return view('accounting.customer-vendor-booking-report', $this->viewData($request));
    }

    public function print(Request $request)
    {
        abort_unless($request->boolean('generate'), 422, 'Generate the report before printing.');
        return view('accounting.customer-vendor-booking-report-print', $this->viewData($request, true) + ['printMode' => true]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        return $this->csv($request, false);
    }

    public function exportExcel(Request $request): StreamedResponse
    {
        return $this->csv($request, true);
    }

    private function viewData(Request $request, bool $allRows = false): array
    {
        $data = $this->reports->report($request, $allRows ? 0 : 50);
        $data['layoutMeta'] = $this->layout->resolve();
        $data['printMode'] = false;
        return $data;
    }

    private function csv(Request $request, bool $excel): StreamedResponse
    {
        abort_unless($request->boolean('generate'), 422, 'Generate the report before exporting.');
        // Export the complete authorized filtered dataset, never only the
        // first UI page.
        $data = $this->reports->report($request, 0);
        $rows = $data['rows'];
        $tab = $data['filters']['tab'];
        $filename = 'customer-vendor-booking-report-'.now()->format('Ymd-His').($excel ? '.xls' : '.csv');
        if ($excel) {
            return response()->streamDownload(function () use ($rows, $tab): void {
                echo "<table><thead><tr><th>#</th><th>Booking Number</th><th>Booking Date</th><th>".($tab === 'vendor' ? 'Vendor / Supplier' : 'Customer / Agent')."</th><th>Booking Type</th><th>Active Passengers</th><th>Status</th><th>".($tab === 'vendor' ? 'Vendor Cost' : 'Total Sale Value')."</th><th>View Booking</th></tr></thead><tbody>";
                foreach ($rows as $index => $row) {
                    $values = [$index + 1, $row['booking_no'], $row['booking_date'], $tab === 'vendor' ? $row['vendor'] : $row['customer'], $row['booking_type'], $row['passengers'], $row['status'], $tab === 'vendor' ? $row['vendor_cost'] : $row['sale'], route('operations.bookings.show', ['booking' => $row['booking_id']])];
                    echo '<tr>'.implode('', array_map(static fn ($v): string => '<td>'.e((string) $v).'</td>', $values)).'</tr>';
                }
                echo '</tbody></table>';
            }, $filename, ['Content-Type' => 'application/vnd.ms-excel; charset=UTF-8']);
        }
        return response()->streamDownload(function () use ($rows, $tab): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['#','Booking Number','Booking Date',$tab === 'vendor' ? 'Vendor / Supplier' : 'Customer / Agent','Booking Type','Active Passengers','Status',$tab === 'vendor' ? 'Vendor Cost' : 'Total Sale Value','View Booking']);
            foreach ($rows as $index => $row) {
                $values = [$index + 1, $row['booking_no'], $row['booking_date'], $tab === 'vendor' ? $row['vendor'] : $row['customer'], $row['booking_type'], $row['passengers'], $row['status'], $tab === 'vendor' ? $row['vendor_cost'] : $row['sale'], route('operations.bookings.show', ['booking' => $row['booking_id']])];
                fputcsv($out, array_map(static fn ($v): string => "\t".str_replace(["\r","\n"], ' ', (string) $v), $values));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
