<?php

namespace App\Services\Accounting;

final class ReceiptTransactionMatcher
{
    public function __construct(private readonly OurBankAccountService $banks, private readonly CashVoucherService $cash) {}

    public function match(array $data, mixed $user, string $requestedType): array
    {
        $from = $this->side($data, 'from');
        $to = $this->side($data, 'to');
        $fromMatches = $this->bankMatches($from, $user);
        $toMatches = $this->bankMatches($to, $user);
        $direction = 'REVIEW REQUIRED'; $our = null; $counterparty = null; $counterpartySide = null;
        if (count($toMatches) === 1 && count($fromMatches) === 0) { $direction = 'RECEIPT'; $our = $toMatches[0]; $counterparty = $from; $counterpartySide = 'FROM'; }
        elseif (count($fromMatches) === 1 && count($toMatches) === 0) { $direction = 'PAYMENT'; $our = $fromMatches[0]; $counterparty = $to; $counterpartySide = 'TO'; }
        $voucherType = $direction === 'RECEIPT' ? 'receipt' : ($direction === 'PAYMENT' ? 'payment' : $requestedType);
        $party = $this->partyMatch($counterparty['name'] ?? '', $voucherType, $user);
        return ['direction'=>$direction,'counterparty_side'=>$counterpartySide,'our_account_side'=>$our ? ($direction === 'RECEIPT' ? 'TO' : 'FROM') : null,'our_bank_match'=>$our,'party_match'=>$party,'from'=>$from,'to'=>$to,'warnings'=>(array)($data['warnings'] ?? [])];
    }

    private function side(array $data, string $side): array
    {
        $legacy = $side === 'from' ? 'counterparty_name' : null;
        return ['name'=>$data[$side.'_account_name']['value'] ?? ($legacy ? ($data[$legacy]['value'] ?? null) : null),'bank'=>$data[$side.'_bank_name']['value'] ?? ($side === 'from' ? ($data['bank_name']['value'] ?? null) : null),'last_digits'=>$this->suffix((string)($data[$side.'_account_last_digits']['value'] ?? ($side === 'from' ? ($data['visible_account_last_digits']['value'] ?? '') : '')) )];
    }

    private function suffix(string $value): ?string { $digits = preg_replace('/\D+/', '', $value) ?: ''; return $digits === '' ? null : substr($digits, -8); }

    private function bankMatches(array $side, mixed $user): array
    {
        if (!$side['last_digits']) return [];
        $matches = array_values(array_filter($this->banks->safeForMatching($user), fn(array $row): bool => $row['account_last_digits'] === $side['last_digits']));
        if (count($matches) <= 1) return $matches;
        $name = strtolower(trim((string)$side['name'])); $bank = strtolower(trim((string)$side['bank']));
        $filtered = array_values(array_filter($matches, fn(array $row): bool => ($name !== '' && strtolower(trim($row['account_title'])) === $name) || ($bank !== '' && strtolower(trim($row['bank_name'])) === $bank)));
        return count($filtered) === 1 ? $filtered : [];
    }

    private function partyMatch(string $name, string $type, mixed $user): ?array
    {
        if ($name === '') return null;
        $role = in_array($type, ['payment','supplier_advance'], true) ? 'supplier' : 'customer';
        $needle = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        $matches = array_values(array_filter($this->cash->partyOptions($role), fn(array $row): bool => strtolower(trim(preg_replace('/\s+/', ' ', (string)$row['name']))) === $needle));
        return count($matches) === 1 ? ['party_id'=>(int)$matches[0]['id'],'display_name'=>$matches[0]['name'],'role'=>$role,'status'=>'Auto matched'] : null;
    }
}
