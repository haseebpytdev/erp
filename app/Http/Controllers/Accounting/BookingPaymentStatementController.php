<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\BookingPaymentStatementService;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Organization\CompanyProfileSnapshotService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BookingPaymentStatementController extends Controller
{
    public function __construct(
        private readonly BookingPaymentStatementService $statements,
        private readonly NativeErpLayoutResolver $layout,
        private readonly CompanyProfileSnapshotService $companyProfile,
    ) {}

    public function index(Request $request)
    {
        $statement = $this->statements->statement($request);
        return view('accounting.booking-payment-statement.index', $statement + ['layoutMeta' => $this->layout->resolve(), 'companyProfile' => $this->companyProfile->get()]);
    }

    public function print(Request $request)
    {
        $statement = $this->statements->statement($request, false);
        return view('accounting.booking-payment-statement.print', $statement + ['companyProfile' => $this->companyProfile->get()]);
    }

    public function export(Request $request): StreamedResponse
    {
        $statement = $this->statements->statement($request, false);
        $rows = $statement['rows'];
        return response()->streamDownload(function () use ($rows, $statement): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Date','Type','Reference','Particulars','Debit','Credit','Balance']);
            foreach ($rows as $row) fputcsv($out, [$this->csvCell($row['date']),$this->csvCell($row['type']),$this->csvCell($row['reference']),$this->csvCell($row['particulars']),$row['debit'],$row['credit'],$row['balance']]);
            fputcsv($out, []);
            fputcsv($out, ['Opening','','','',$statement['opening'],'','']);
            fputcsv($out, ['Closing','','','',$statement['closing_side'],$statement['closing'],'']);
            fclose($out);
        }, 'booking-payment-statement-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
