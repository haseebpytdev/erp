<?php

namespace App\Http\Controllers\Accounting;

use App\Services\Accounting\ReceiptExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileReceiptCaptureController
{
    public function __construct(private readonly ReceiptExtractionService $extraction)
    {
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_proof' => ['required', 'file', 'max:'.(int) config('cash_vouchers.proof_max_kb', 5120), 'mimes:pdf,jpg,jpeg,png,webp'],
            'voucher_type' => ['required', 'in:receipt,payment,customer_advance,supplier_advance'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $file = $request->file('payment_proof');
        abort_unless($file && in_array((string) $file->getMimeType(), ['application/pdf','image/jpeg','image/png','image/webp'], true), 422, 'Unsupported receipt type.');

        $result = $this->extraction->preview(
            $file,
            $request->user(),
            (string) $data['voucher_type'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
        );

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
