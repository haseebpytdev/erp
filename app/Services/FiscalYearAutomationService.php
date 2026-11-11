<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class FiscalYearAutomationService
{
    public function installed(): bool
    {
        return Schema::hasTable('fiscal_years')
            && $this->column('fiscal_years',['start_date','starts_on']) !== null
            && $this->column('fiscal_years',['end_date','ends_on']) !== null;
    }

    /**
     * Keep one future financial year prepared. Existing/current years are never closed or modified.
     * Returns the created row id, or null when the next year already exists / no current year exists.
     */
    public function ensureNext(int $companyId, ?int $userId = null): ?int
    {
        if(!$this->installed()) return null;

        $startCol=$this->column('fiscal_years',['start_date','starts_on']);
        $endCol=$this->column('fiscal_years',['end_date','ends_on']);
        $companyCol=$this->column('fiscal_years',['company_id']);
        $today=Carbon::today();

        $query=DB::table('fiscal_years');
        if($companyCol) $query->where($companyCol,$companyId);

        // Prefer the year containing today. If none, use the most recently started year.
        $current=(clone $query)->whereDate($startCol,'<=',$today->toDateString())
            ->whereDate($endCol,'>=',$today->toDateString())
            ->orderByDesc($startCol)->first();
        if(!$current){
            $current=(clone $query)->whereDate($startCol,'<=',$today->toDateString())->orderByDesc($startCol)->first();
        }
        if(!$current) return null;

        $currentStart=Carbon::parse($current->{$startCol});
        $currentEnd=Carbon::parse($current->{$endCol});
        $nextStart=$currentStart->copy()->addYear();
        $nextEnd=$currentEnd->copy()->addYear();

        // Do not create duplicates or overlapping future years.
        $existing=(clone $query)
            ->where(function($q) use($startCol,$endCol,$nextStart,$nextEnd): void {
                $q->whereDate($startCol,$nextStart->toDateString())
                  ->orWhere(function($q2) use($startCol,$endCol,$nextStart,$nextEnd): void {
                      $q2->whereDate($startCol,'<=',$nextEnd->toDateString())
                         ->whereDate($endCol,'>=',$nextStart->toDateString());
                  });
            })->first();
        if($existing) return null;

        return DB::transaction(function() use($companyId,$userId,$companyCol,$startCol,$endCol,$nextStart,$nextEnd): ?int {
            $q=DB::table('fiscal_years');
            if($companyCol) $q->where($companyCol,$companyId);
            $again=(clone $q)->whereDate($startCol,$nextStart->toDateString())->first();
            if($again) return null;

            $payload=[];
            if($companyCol) $payload[$companyCol]=$companyId;
            $payload[$startCol]=$nextStart->toDateString();
            $payload[$endCol]=$nextEnd->toDateString();

            $this->putIfColumn($payload,'fiscal_years','name','FY '.$nextStart->format('Y'));
            $this->putIfColumn($payload,'fiscal_years','label','FY '.$nextStart->format('Y'));
            $this->putIfColumn($payload,'fiscal_years','code','FY'.$nextStart->format('Y'));
            $this->putIfColumn($payload,'fiscal_years','year',(int)$nextStart->format('Y'));
            $this->putIfColumn($payload,'fiscal_years','fiscal_year',(int)$nextStart->format('Y'));
            $this->putIfColumn($payload,'fiscal_years','status','OPEN');
            $this->putIfColumn($payload,'fiscal_years','is_closed',false);
            $this->putIfColumn($payload,'fiscal_years','is_active',true);
            $this->putIfColumn($payload,'fiscal_years','created_by',$userId);
            $this->putIfColumn($payload,'fiscal_years','updated_by',$userId);
            $this->putIfColumn($payload,'fiscal_years','created_at',now());
            $this->putIfColumn($payload,'fiscal_years','updated_at',now());

            try{
                $id=(int)DB::table('fiscal_years')->insertGetId($payload);
            }catch(Throwable $e){
                throw new RuntimeException('Unable to create the next financial year automatically: '.$e->getMessage(),0,$e);
            }

            $this->createPeriodsBestEffort($id,$companyId,$nextStart,$nextEnd,$userId);
            return $id;
        });
    }

    public function rows(int $companyId)
    {
        if(!$this->installed()) return collect();
        $startCol=$this->column('fiscal_years',['start_date','starts_on']);
        $companyCol=$this->column('fiscal_years',['company_id']);
        $q=DB::table('fiscal_years');
        if($companyCol) $q->where($companyCol,$companyId);
        return $q->orderByDesc($startCol)->get();
    }

    public function displayRow(object $row): array
    {
        $startCol=$this->column('fiscal_years',['start_date','starts_on']);
        $endCol=$this->column('fiscal_years',['end_date','ends_on']);
        $start=Carbon::parse($row->{$startCol});
        $end=Carbon::parse($row->{$endCol});
        $closed=false;
        if(Schema::hasColumn('fiscal_years','is_closed')) $closed=(bool)($row->is_closed??false);
        if(Schema::hasColumn('fiscal_years','status')) $closed=$closed || strtoupper((string)($row->status??''))==='CLOSED';
        $today=Carbon::today();
        $state=$closed?'CLOSED':($today->lt($start)?'UPCOMING':($today->lte($end)?'OPEN':'OPEN FOR ADJUSTMENTS'));
        $label=(string)($row->name??$row->label??('FY '.$start->format('Y')));
        return ['id'=>(int)$row->id,'label'=>$label,'start'=>$start,'end'=>$end,'state'=>$state];
    }

    private function createPeriodsBestEffort(int $fiscalYearId,int $companyId,Carbon $start,Carbon $end,?int $userId): void
    {
        $table=null;
        foreach(['accounting_periods','fiscal_periods','periods'] as $candidate){
            if(Schema::hasTable($candidate)){$table=$candidate;break;}
        }
        if(!$table) return;
        $fyCol=$this->column($table,['fiscal_year_id','financial_year_id']);
        $startCol=$this->column($table,['start_date','starts_on']);
        $endCol=$this->column($table,['end_date','ends_on']);
        if(!$fyCol||!$startCol||!$endCol) return;
        if(DB::table($table)->where($fyCol,$fiscalYearId)->exists()) return;

        try{
            $cursor=$start->copy();$n=1;
            while($cursor->lte($end) && $n<=24){
                $periodEnd=$cursor->copy()->endOfMonth();
                if($periodEnd->gt($end)) $periodEnd=$end->copy();
                $p=[$fyCol=>$fiscalYearId,$startCol=>$cursor->toDateString(),$endCol=>$periodEnd->toDateString()];
                $this->putIfColumn($p,$table,'company_id',$companyId);
                $this->putIfColumn($p,$table,'period_no',$n);
                $this->putIfColumn($p,$table,'month_no',$n);
                $this->putIfColumn($p,$table,'sequence_no',$n);
                $this->putIfColumn($p,$table,'name',$cursor->format('M Y'));
                $this->putIfColumn($p,$table,'label',$cursor->format('M Y'));
                $this->putIfColumn($p,$table,'status','OPEN');
                $this->putIfColumn($p,$table,'is_locked',false);
                $this->putIfColumn($p,$table,'created_by',$userId);
                $this->putIfColumn($p,$table,'updated_by',$userId);
                $this->putIfColumn($p,$table,'created_at',now());
                $this->putIfColumn($p,$table,'updated_at',now());
                DB::table($table)->insert($p);
                $cursor=$periodEnd->copy()->addDay();$n++;
            }
        }catch(Throwable $e){
            // The existing ERP may use a different period schema. The fiscal year itself remains valid;
            // its native Fiscal Year management screen can generate/manage periods if required.
            report($e);
        }
    }

    private function putIfColumn(array &$payload,string $table,string $column,mixed $value): void
    {
        if(Schema::hasColumn($table,$column)) $payload[$column]=$value;
    }

    private function column(string $table,array $candidates): ?string
    {
        foreach($candidates as $column){if(Schema::hasColumn($table,$column)) return $column;}
        return null;
    }
}
