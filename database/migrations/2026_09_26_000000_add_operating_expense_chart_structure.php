<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        $s = $this->resolveSchema();
        $targets = $this->targets();

        DB::transaction(function () use ($s, $targets): void {
            $rows = DB::table($s['table'])->get();
            $byCode = $rows->keyBy(fn ($r) => trim((string) $r->{$s['code']}));
            $byName = $rows->keyBy(fn ($r) => mb_strtolower(trim((string) $r->{$s['name']})));

            foreach ($targets as $target) {
                $code = trim($target['code']);
                $nameKey = mb_strtolower(trim($target['name']));
                $existing = $byCode->get($code);
                $sameName = $byName->get($nameKey);
                if ($sameName && (!$existing || (int) $sameName->{$s['id']} !== (int) $existing->{$s['id']})) {
                    throw new RuntimeException("Operating expense name conflict: {$target['name']}");
                }
                if (!$existing) {
                    continue;
                }
                if (!$this->matches($existing, $target, $s, $rows)) {
                    throw new RuntimeException("Operating expense code conflict: {$code}");
                }
            }

            $prototype = $rows->first(fn ($r) => (string) $r->{$s['code']} === '5110');
            $created = [];
            foreach ($targets as $target) {
                if ($byCode->has(trim($target['code']))) {
                    $created[$target['code']] = $byCode->get(trim($target['code']));
                    continue;
                }
                $insert = $this->insertValues($target, $s, $created, $prototype);
                $id = DB::table($s['table'])->insertGetId($insert, $s['id']);
                $row = DB::table($s['table'])->where($s['id'], $id)->first();
                $created[$target['code']] = $row;
            }
        });
    }

    /** Accounting master data is intentionally never deleted by rollback. */
    public function down(): void
    {
        // Non-destructive by design: operating accounts may already be used by journals.
    }

    private function targets(): array
    {
        $groups = [
            ['6000', 'Operating Expenses', null, 'OPERATING_EXPENSE'],
            ['6100', 'Staff Expenses', '6000', 'STAFF_EXPENSE'],
            ['6200', 'Office & Administration Expenses', '6000', 'OFFICE_ADMIN_EXPENSE'],
            ['6300', 'Utilities & Communication', '6000', 'UTILITIES_COMMUNICATION'],
            ['6400', 'IT & Technology Expenses', '6000', 'IT_TECHNOLOGY_EXPENSE'],
            ['6500', 'Marketing & Promotion Expenses', '6000', 'MARKETING_PROMOTION_EXPENSE'],
            ['6600', 'Travel & Conveyance Expenses', '6000', 'TRAVEL_CONVEYANCE_EXPENSE'],
            ['6700', 'Professional & Regulatory Expenses', '6000', 'PROFESSIONAL_REGULATORY_EXPENSE'],
            ['6800', 'Repairs & Maintenance Expenses', '6000', 'REPAIRS_MAINTENANCE_EXPENSE'],
            ['6900', 'General & Other Expenses', '6000', 'GENERAL_OTHER_EXPENSE'],
        ];
        $children = [
            '6100'=>['6101 Salaries & Wages','6102 Overtime & Allowances','6103 Staff Bonus & Incentives','6104 Staff Commission & Incentives','6105 Staff Meals & Refreshments','6106 Staff Medical Expense','6107 Staff Training & Development','6108 Recruitment Expense','6109 Staff Welfare Expense','6110 Staff Travel Expense','6111 Staff Accommodation Expense','6112 Employee Benefits','6113 Leave Encashment','6114 Gratuity / End-of-Service Expense','6119 Other Staff Expenses'],
            '6200'=>['6201 Office Rent','6202 Office Service Charges','6203 Stationery & Office Supplies','6204 Printing & Photocopy','6205 Courier & Postage','6206 Cleaning & Janitorial','6207 Office Refreshments','6208 Security Expense','6209 Furniture & Fixture Expense','6210 Office Equipment Expense','6211 Pantry / Kitchen Supplies','6212 Documentation Expense','6213 Membership & Subscription Expense','6219 Other Office & Admin Expenses'],
            '6300'=>['6301 Electricity Expense','6302 Gas Expense','6303 Water Expense','6304 Telephone Expense','6305 Mobile Expense','6306 Internet Expense','6307 SMS / Messaging Expense','6308 Communication Charges','6309 Generator Fuel Expense','6310 Generator / UPS Running Expense','6319 Other Utilities & Communication'],
            '6400'=>['6401 Software Subscriptions','6402 Cloud Hosting','6403 Website Hosting','6404 Domain Registration','6405 Email / Workspace Subscriptions','6406 ERP / Software Maintenance','6407 IT Support','6408 Computer Repair & Maintenance','6409 Computer Accessories','6410 Cybersecurity Expense','6411 API / Integration Charges','6412 Backup / Data Services','6413 AI / Automation Tools','6419 Other IT & Technology Expenses'],
            '6500'=>['6501 Digital Advertising','6502 Social Media Advertising','6503 Search / Google Advertising','6504 Promotional Material','6505 Signboard / Branding','6506 Events & Exhibitions','6507 Sponsorship','6508 Customer Promotions','6509 Gifts & Giveaways','6510 Photography / Video Production','6511 Marketing Agency Expense','6512 Influencer / Content Promotion','6519 Other Marketing Expenses'],
            '6600'=>['6601 Local Conveyance','6602 Fuel Expense','6603 Vehicle Hire / Taxi','6604 Business Air Travel','6605 Business Accommodation','6606 Travel Meals','6607 Toll & Parking','6608 Business Trip Expense','6609 Mileage Reimbursement','6610 Vehicle Running Expense','6619 Other Travel & Conveyance'],
            '6700'=>['6701 Audit Fee','6702 Accounting / Bookkeeping Fee','6703 Legal Fee','6704 Consultancy Fee','6705 Tax Consultancy','6706 Government Fees','6707 License & Registration Fees','6708 IATA / Travel Industry Fees','6709 Chamber / Association Fees','6710 Regulatory Portal Fees','6711 Certification Expense','6712 Professional Membership Fees','6713 Filing / Documentation Fees','6719 Other Professional & Regulatory Expenses'],
            '6800'=>['6801 Office Repair & Maintenance','6802 Furniture Repair & Maintenance','6803 Electrical Repair & Maintenance','6804 AC Repair & Maintenance','6805 Generator / UPS Maintenance','6806 Vehicle Repair & Maintenance','6807 Computer / Hardware Maintenance','6808 Printer / Scanner Maintenance','6809 Building Maintenance','6810 Equipment Maintenance','6819 Other Repairs & Maintenance'],
            '6900'=>['6901 Bank Charges','6902 Payment Gateway / Merchant Charges','6903 Credit Card Charges','6904 Cash Handling Charges','6905 Insurance Expense','6906 Donations & Charity','6907 Entertainment Expense','6908 Business Meeting Expense','6909 Miscellaneous Expense','6910 Bad Debt Expense','6911 Depreciation Expense','6912 Amortization Expense','6913 Penalties & Fines','6914 Loss on Asset Disposal','6915 Remittance Charges','6916 Round-Off / Small Difference Expense','6919 Other General Expenses'],
        ];
        $out = [];
        foreach ($groups as [$code, $name, $parent, $subtype]) $out[] = compact('code','name','parent','subtype') + ['posting'=>false];
        foreach ($children as $parent => $list) foreach ($list as $item) { [$code, $name] = explode(' ', $item, 2); $subtype = collect($groups)->first(fn ($g) => $g[0] === $parent)[3]; $out[] = compact('code','name','parent','subtype') + ['posting'=>true]; }
        return $out;
    }

    private function resolveSchema(): array
    {
        foreach (['chart_of_accounts','chart_accounts','accounts','account_masters','gl_accounts'] as $table) {
            if (!Schema::hasTable($table)) continue;
            $c = Schema::getColumnListing($table);
            $s = ['table'=>$table,'columns'=>$c,'id'=>$this->first($c,['id','account_id']),'code'=>$this->first($c,['code','account_code','gl_code','number','account_number']),'name'=>$this->first($c,['name','account_name','title']),'type'=>$this->first($c,['type','account_type','category','account_category']),'subtype'=>$this->first($c,['subtype','sub_type','account_subtype','account_sub_type']),'parent'=>$this->first($c,['parent_id','parent_account_id','parent_account','parent_code']),'normal'=>$this->first($c,['normal_balance','normal_side','balance_type']),'posting'=>$this->first($c,['allow_direct_journal_posting','allow_direct_posting','allow_posting','is_posting','posting_allowed','can_post']),'control'=>$this->first($c,['is_control_account','is_control','control_account']),'control_type'=>$this->first($c,['control_type','control_code','control_key']),'status'=>$this->first($c,['status','account_status']),'active'=>$this->first($c,['is_active','active','enabled']),'created_at'=>$this->first($c,['created_at']),'updated_at'=>$this->first($c,['updated_at'])];
            foreach (['id','code','name','type','subtype','parent','normal','posting','control'] as $key) if (!$s[$key]) continue 2;
            if (!$s['active'] && !$s['status']) continue;
            return $s;
        }
        throw new RuntimeException('No safe native Chart of Accounts schema found.');
    }

    private function insertValues(array $t, array $s, array $created, ?object $prototype): array
    {
        $v = [$s['code']=>$t['code'],$s['name']=>$t['name'],$s['type']=>$this->storage('Expense',$s['type'],$s),$s['subtype']=>$t['subtype'],$s['normal']=>$this->storage('DEBIT',$s['normal'],$s),$s['posting']=>$t['posting']?1:0,$s['control']=>0];
        if ($s['control_type']) $v[$s['control_type']] = null;
        $parent = $t['parent'] === null ? null : ($created[$t['parent']]->{$s['id']} ?? null);
        $v[$s['parent']] = $this->parentValue($parent, $t['parent'], $s);
        if ($s['active']) $v[$s['active']] = 1;
        if ($s['status']) $v[$s['status']] = $this->storage('Active',$s['status'],$s);
        if ($prototype) foreach (['company_id','branch_id','organization_id','tenant_id','legal_entity_id'] as $scope) if (in_array($scope,$s['columns'],true)) $v[$scope] = $prototype->{$scope} ?? null;
        $now = now(); if ($s['created_at']) $v[$s['created_at']]=$now; if ($s['updated_at']) $v[$s['updated_at']]=$now;
        return $v;
    }
    private function matches(object $row,array $t,array $s,$rows): bool { $parent=$t['parent']; if($parent!==null){$p=$rows->first(fn($r)=>trim((string)$r->{$s['code']})===$parent);$parent=$p?->{$s['id']};} $activeOk=$s['active']?(int)($row->{$s['active']}??0)===1:strcasecmp((string)($row->{$s['status']}??''),'active')===0; $controlTypeOk=!$s['control_type']||empty($row->{$s['control_type']}); return (strcasecmp(trim((string)$row->{$s['name']}),trim($t['name']))===0 && strcasecmp((string)$row->{$s['type']},'Expense')===0 && strcasecmp((string)$row->{$s['subtype']},$t['subtype'])===0 && strcasecmp((string)$row->{$s['normal']},'DEBIT')===0 && ((int)($row->{$s['posting']}??0)===(int)$t['posting']) && (int)($row->{$s['control']}??0)===0 && $controlTypeOk && $activeOk && $this->parentMatches($row->{$s['parent']}??null,$parent,$t['parent'],$s)); }
    private function parentMatches($actual,$id,?string $code,array $s): bool { if($code===null)return $actual===null; return $this->parentUsesId($s)?(string)$actual===(string)$id:(string)$actual===$code; }
    private function parentUsesId(array $s): bool { if(str_ends_with(strtolower($s['parent']),'_id'))return true; $sample=DB::table($s['table'])->whereNotNull($s['parent'])->value($s['parent']); if($sample!==null){$id=DB::table($s['table'])->where($s['id'],$sample)->exists();$code=DB::table($s['table'])->where($s['code'],trim((string)$sample))->exists();if($id xor $code)return$id;} return in_array(strtolower($s['parent']),['parent_code','parent_account'],true)?false:true; }
    private function parentValue($id,?string $code,array $s){ if($code===null)return null; if($this->parentUsesId($s)){if(!$id)throw new RuntimeException("Unresolved parent {$code}");return$id;} return$code; }
    private function storage(string $value,string $column,array $s): string { $sample=DB::table($s['table'])->whereNotNull($column)->value($column); if($sample===null)return$value; $sample=(string)$sample; if($sample===strtoupper($sample))return strtoupper($value); if($sample===strtolower($sample))return strtolower($value); if($sample===ucfirst(strtolower($sample)))return ucfirst(strtolower($value)); return$value; }
    private function first(array $columns,array $names): ?string { foreach($names as $name)if(in_array($name,$columns,true))return$name; return null; }
};
