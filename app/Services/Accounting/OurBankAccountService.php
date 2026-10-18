<?php

namespace App\Services\Accounting;

use App\Services\Administration\ErpBranchAccessAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class OurBankAccountService
{
    public function __construct(
        private readonly ChartOfAccountsWorkspaceService $chart,
        private readonly CashVoucherService $cash,
        private readonly ErpBranchAccessAuthority $branches,
    ) {}

    public function ledgerOptions(): array
    {
        return $this->cash->cashBankAccounts();
    }

    public function companyOptions(mixed $user): array
    {
        if (!Schema::hasTable('companies')) return [];
        $columns = Schema::getColumnListing('companies');
        $name = $this->first($columns, ['name', 'company_name', 'title', 'legal_name']);
        if (!$name) return [];
        $q = DB::table('companies')->select(['id', $name.' as name'])->orderBy($name);
        if (!$this->branches->isSuperAdmin($user)) {
            $companyId = $this->branches->companyIdForUser($user);
            if (!$companyId) return [];
            $q->where('id', $companyId);
        }
        return $q->get()->map(fn ($r): array => ['id'=>(int)$r->id,'name'=>(string)$r->name])->all();
    }

    public function branchOptions(mixed $user): array
    {
        return $this->cash->branchOptions($user);
    }

    public function rows(mixed $user): array
    {
        if (!Schema::hasTable('our_bank_accounts')) return [];
        $q = DB::table('our_bank_accounts')->orderBy('bank_name')->orderBy('account_title');
        if (!$this->branches->isSuperAdmin($user)) {
            $companyId = $this->branches->companyIdForUser($user);
            $allowed = $this->branches->allowedBranchIds($user);
            if (!$companyId) return [];
            $q->where('company_id', $companyId)->where(function ($w) use ($allowed): void { $w->whereIn('branch_id', $allowed ?: [-1])->orWhereNull('branch_id'); });
        }
        return $q->get()->map(fn ($r): array => (array)$r)->all();
    }

    public function findAuthorized(int $id, mixed $user): object
    {
        $row = DB::table('our_bank_accounts')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertRowScope($row, $user);
        return $row;
    }

    public function create(array $input, mixed $user): int
    {
        return $this->persist($input, $user, null);
    }

    public function update(int $id, array $input, mixed $user): void
    {
        $this->persist($input, $user, $id);
    }

    public function deactivate(int $id, mixed $user): void
    {
        $this->findAuthorized($id, $user);
        DB::table('our_bank_accounts')->where('id', $id)->update(['is_active'=>false,'updated_at'=>now()]);
    }

    public function safeForMatching(mixed $user): array
    {
        return array_map(static fn ($r): array => [
            'id'=>(int)$r['id'], 'bank_name'=>$r['bank_name'], 'account_title'=>$r['account_title'],
            'account_last_digits'=>$r['account_last_digits'], 'currency_code'=>$r['currency_code'],
            'linked_ledger_id'=>(int)$r['linked_ledger_id'], 'linked_ledger_code'=>$r['linked_ledger_code'],
            'linked_ledger_name'=>$r['linked_ledger_name'], 'company_id'=>(int)$r['company_id'],
            'branch_id'=>$r['branch_id'] === null ? null : (int)$r['branch_id'],
            'is_active'=>(bool)$r['is_active'], 'use_for_ai_receipt_matching'=>(bool)$r['use_for_ai_receipt_matching'],
        ], array_values(array_filter($this->rows($user), static fn ($r): bool => (bool)$r['is_active'] && (bool)$r['use_for_ai_receipt_matching'])));
    }

    private function persist(array $input, mixed $user, ?int $id): int
    {
        if ($id !== null) {
            // Ownership is checked before any submitted destination scope is trusted.
            $this->findAuthorized($id, $user);
        }
        $bank = trim((string)($input['bank_name'] ?? '')); $title = trim((string)($input['account_title'] ?? ''));
        $suffix = trim((string)($input['account_last_digits'] ?? '')); $currency = strtoupper(trim((string)($input['currency_code'] ?? '')));
        $ledgerId = (int)($input['linked_ledger_id'] ?? 0); $companyId = (int)($input['company_id'] ?? 0); $branchId = ($input['branch_id'] ?? '') === '' ? null : (int)$input['branch_id'];
        if ($bank === '' || $title === '') throw new RuntimeException('Bank name and account title are required.');
        if (!preg_match('/^\d{4,6}$/', $suffix)) throw new RuntimeException('Account last digits must contain 4 to 6 digits.');
        if (!preg_match('/^[A-Z]{3,12}$/', $currency)) throw new RuntimeException('Currency must be a valid alphabetic code.');
        $ledger = collect($this->ledgerOptions())->first(fn ($a): bool => (int)$a['id'] === $ledgerId);
        if (!$ledger) throw new RuntimeException('Select an active cash/bank COA ledger.');
        $this->assertCompanyBranch($companyId, $branchId, $user);
        $duplicate = DB::table('our_bank_accounts')->where('company_id',$companyId)->where('linked_ledger_id',$ledgerId)->where('account_last_digits',$suffix)->whereRaw('LOWER(bank_name)=?', [strtolower($bank)])->whereRaw('LOWER(account_title)=?', [strtolower($title)]);
        $branchId === null ? $duplicate->whereNull('branch_id') : $duplicate->where('branch_id',$branchId);
        if ($id !== null) $duplicate->where('id','<>',$id);
        if ($duplicate->exists()) throw new RuntimeException('An identical bank account already exists for this scope.');
        $payload = ['bank_name'=>$bank,'account_title'=>$title,'account_last_digits'=>$suffix,'currency_code'=>$currency,'linked_ledger_id'=>$ledger['id'],'linked_ledger_code'=>$ledger['code'],'linked_ledger_name'=>$ledger['name'],'company_id'=>$companyId,'branch_id'=>$branchId,'use_for_ai_receipt_matching'=>!empty($input['use_for_ai_receipt_matching']),'is_active'=>array_key_exists('is_active',$input) ? (bool)$input['is_active'] : true,'updated_at'=>now()];
        if ($id === null) { $payload['created_at']=now(); return (int)DB::table('our_bank_accounts')->insertGetId($payload); }
        DB::table('our_bank_accounts')->where('id',$id)->update($payload); return $id;
    }

    private function assertCompanyBranch(int $companyId, ?int $branchId, mixed $user): void
    {
        if ($companyId < 1 || !Schema::hasTable('companies') || !DB::table('companies')->where('id',$companyId)->exists()) throw new RuntimeException('Select a valid company.');
        if ($branchId === null) {
            $allowed = $this->branches->allowedBranchIds($user);
            if (!$this->branches->isSuperAdmin($user) && $allowed !== null && Schema::hasTable('branches') && !DB::table('branches')->where('company_id',$companyId)->whereIn('id',$allowed ?: [-1])->exists()) throw new RuntimeException('Selected company is outside your authorized scope.');
            return;
        }
        if (!$this->branches->mayAccessBranch($user, $branchId)) throw new RuntimeException('Selected branch is outside your authorized scope.');
        if (!Schema::hasTable('branches') || !DB::table('branches')->where('id',$branchId)->where('company_id',$companyId)->exists()) throw new RuntimeException('Selected branch does not belong to the company.');
    }

    private function assertRowScope(object $row, mixed $user): void
    {
        if ($this->branches->isSuperAdmin($user)) return;
        $companyId = $this->branches->companyIdForUser($user);
        $allowed = $this->branches->allowedBranchIds($user) ?? [];
        if (!$companyId || (int)$row->company_id !== $companyId || ($row->branch_id !== null && !in_array((int)$row->branch_id, $allowed, true))) abort(403, 'The requested bank account is outside your authorized scope.');
    }

    private function first(array $columns, array $names): ?string { foreach ($names as $name) if (in_array($name,$columns,true)) return $name; return null; }
}
