<?php

namespace App\Services\Accounting;

use Illuminate\Http\UploadedFile;

final class ReceiptExtractionService
{
    public function __construct(
        private readonly OpenAiReceiptExtractionProvider $provider,
        private readonly CashVoucherService $cash,
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
        $data['party_candidates'] = $this->partyCandidates((string) ($data['counterparty_name']['value'] ?? ''), $voucherType);
        $data['account_candidates'] = $this->accountCandidates((string) ($data['bank_name']['value'] ?? ''));
        return ['ok' => true, 'suggestions' => $data];
    }

    /** @return array<string,mixed> */
    private function normalise(array $source): array
    {
        $names = ['receipt_date','receipt_time','amount','currency','transaction_reference','counterparty_name','bank_name','payment_method','visible_account_last_digits','voucher_type_suggestion','narration'];
        $out = [];
        $confidence = (array) ($source['confidence'] ?? []);
        foreach ($names as $name) {
            $value = $source[$name] ?? null;
            $score = max(0.0, min(1.0, (float) ($confidence[$name] ?? ($value === null ? 0 : 0.5))));
            if ($name === 'visible_account_last_digits') {
                $value = preg_replace('/\D+/', '', (string) $value) ?: null;
                $value = $value !== null ? substr($value, -8) : null;
            }
            if ($name === 'voucher_type_suggestion' && ! in_array($value, ['PAYMENT','RECEIPT','UNKNOWN'], true)) $value = 'UNKNOWN';
            $out[$name] = ['value' => $value, 'confidence' => $score, 'level' => $score >= .8 ? 'High' : ($score >= .5 ? 'Medium' : 'Low')];
        }
        $out['warnings'] = array_values(array_filter((array) ($source['warnings'] ?? []), 'is_string'));
        return $out;
    }

    private function partyCandidates(string $name, string $type): array
    {
        if ($name === '') return [];
        $partyType = in_array($type, ['payment','supplier_advance'], true) ? 'supplier' : 'customer';
        $needle = strtolower(trim($name));
        return array_values(array_filter(array_map(static function (array $party) use ($needle): ?array {
            $label = strtolower(trim((string) ($party['name'] ?? '')));
            similar_text($needle, $label, $percent);
            return $percent >= 45 ? ['party_id' => (int) $party['id'], 'display_name' => $party['name'], 'role' => $party['role'] ?? null, 'confidence' => round(min(1, $percent / 100), 2)] : null;
        }, $this->cash->partyOptions($partyType))));
    }

    private function accountCandidates(string $bank): array
    {
        $needle = strtolower(trim($bank));
        return array_values(array_filter(array_map(static function (array $account) use ($needle): ?array {
            $label = strtolower((string) ($account['code'] ?? '').' '.($account['name'] ?? ''));
            return $needle !== '' && str_contains($label, $needle) ? ['code' => $account['code'], 'name' => $account['name']] : null;
        }, $this->cash->cashBankAccounts())));
    }
}
