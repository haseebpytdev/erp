<?php
namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountMapping;
use RuntimeException;

class AccountResolver
{
    public function resolve(int $companyId, string $mappingKey): Account
    {
        $mapping = AccountMapping::query()
            ->where('company_id', $companyId)
            ->where('mapping_key', strtoupper($mappingKey))
            ->with('account')
            ->first();

        if (! $mapping || ! $mapping->account) {
            throw new RuntimeException("Account mapping [{$mappingKey}] is not configured.");
        }

        $account = $mapping->account;
        if (! $account->is_active || ! $account->allow_posting) {
            throw new RuntimeException("Mapped account [{$account->code}] is not an active posting account.");
        }
        if ($account->account_type !== $mapping->expected_account_type) {
            throw new RuntimeException("Mapped account [{$account->code}] has an invalid account type for [{$mappingKey}].");
        }

        return $account;
    }
}
