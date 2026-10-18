<?php

namespace App\Services\Accounting;

use Illuminate\Http\UploadedFile;

final class ReceiptExtractionService
{
    // Historical C90.5 contract names retained as documentation: partyCandidates
    // and accountCandidates remain local projections over partyOptions and cashBankAccounts.
    public function __construct(
        private readonly OpenAiReceiptExtractionProvider $provider,
        private readonly CashVoucherService $cash,
        private readonly ReceiptTransactionMatcher $matcher,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(UploadedFile $file, mixed $user, string $voucherType, ?int $branchId = null): array
    {
        $allowedTypes = ['receipt', 'payment', 'customer_advance', 'supplier_advance'];
        if (! in_array($voucherType, $allowedTypes, true) || ! $this->cash->canUseType($user, $voucherType, 'create')) {
            abort(403);
        }
        $branches = $this->cash->branchOptions($user);
        if ($branchId !== null && ! in_array($branchId, array_map(static fn (array $b): int => (int) $b['id'], $branches), true)) {
            abort(403);
        }

        $result = $this->provider->extract($file);
        if (($result['configured'] ?? false) !== true || isset($result['error'])) {
            return ['ok' => false, 'message' => (string) ($result['error'] ?? 'AI receipt capture is unavailable.')];
        }
        $data = $this->normalise((array) ($result['data'] ?? []));
        $data['local_match'] = $this->matcher->match($data, $user, $voucherType);
        return ['ok' => true, 'suggestions' => $data];
    }

    /** @return array<string,mixed> */
    private function normalise(array $source): array
    {
        $names = ['receipt_date','receipt_time','amount','currency','transaction_reference','counterparty_name','bank_name','payment_method','visible_account_last_digits','voucher_type_suggestion','narration','from_account_name','from_account_last_digits','from_bank_name','to_account_name','to_account_last_digits','to_bank_name','bank_transaction_type'];
        $out = [];
        $confidence = (array) ($source['confidence'] ?? []);
        foreach ($names as $name) {
            $value = $source[$name] ?? null;
            $score = max(0.0, min(1.0, (float) ($confidence[$name] ?? ($value === null ? 0 : 0.5))));
            if (str_ends_with($name, 'last_digits') || $name === 'visible_account_last_digits') {
                $value = preg_replace('/\D+/', '', (string) $value) ?: null;
                $value = $value !== null ? substr($value, -8) : null;
            }
            if ($name === 'voucher_type_suggestion' && ! in_array($value, ['PAYMENT','RECEIPT','UNKNOWN'], true)) $value = 'UNKNOWN';
            $out[$name] = ['value' => $value, 'confidence' => $score, 'level' => $score >= .8 ? 'High' : ($score >= .5 ? 'Medium' : 'Low')];
        }
        $out['warnings'] = array_values(array_filter((array) ($source['warnings'] ?? []), 'is_string'));
        return $out;
    }

}
