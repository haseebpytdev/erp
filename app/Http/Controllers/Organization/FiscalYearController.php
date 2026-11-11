<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FiscalYearController extends Controller
{
    public function index(Request $request): View
    {
        return view('organization.fiscal-years', [
            'fiscalYears' => FiscalYear::query()->with('periods')->orderByDesc('starts_on')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = Company::query()->firstOrFail();
        $data = $request->validate([
            'code' => ['required','string','max:30'],
            'name' => ['required','string','max:100'],
            'starts_on' => ['required','date'],
            'ends_on' => ['required','date','after_or_equal:starts_on'],
        ]);

        $start = CarbonImmutable::parse($data['starts_on']);
        $end = CarbonImmutable::parse($data['ends_on']);
        abort_if($start->diffInMonths($end) > 24, 422, 'A fiscal year may not exceed 25 calendar months.');

        $overlap = FiscalYear::query()
            ->where('company_id', $company->id)
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('starts_on', [$start->toDateString(), $end->toDateString()])
                  ->orWhereBetween('ends_on', [$start->toDateString(), $end->toDateString()])
                  ->orWhere(function ($q2) use ($start, $end): void {
                      $q2->where('starts_on', '<=', $start->toDateString())->where('ends_on', '>=', $end->toDateString());
                  });
            })->exists();
        if ($overlap) {
            return back()->withErrors(['starts_on' => 'The fiscal year overlaps an existing fiscal year.'])->withInput();
        }

        DB::transaction(function () use ($company, $data, $start, $end, $request): void {
            $fy = FiscalYear::query()->create([
                'company_id' => $company->id,
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'starts_on' => $start,
                'ends_on' => $end,
                'status' => 'OPEN',
            ]);

            $cursor = $start;
            $periodNo = 1;
            while ($cursor->lte($end)) {
                $periodEnd = $cursor->endOfMonth()->min($end);
                AccountingPeriod::query()->create([
                    'fiscal_year_id' => $fy->id,
                    'period_no' => $periodNo,
                    'name' => $cursor->format('M Y'),
                    'starts_on' => $cursor,
                    'ends_on' => $periodEnd,
                    'status' => 'OPEN',
                ]);
                $cursor = $periodEnd->addDay();
                $periodNo++;
            }
            AuditService::log($request, 'fiscal_year.created', $fy, [], $fy->toArray());
        });

        return back()->with('status', 'Fiscal year created and accounting periods generated.');
    }

    public function lockPeriod(Request $request, AccountingPeriod $period): RedirectResponse
    {
        abort_if($period->status === 'LOCKED', 422, 'Period is already locked.');
        $old = $period->toArray();
        $period->update(['status' => 'LOCKED','locked_at' => now(),'locked_by' => $request->user()->id]);
        AuditService::log($request, 'accounting_period.locked', $period, $old, $period->fresh()->toArray());
        return back()->with('status', 'Accounting period locked.');
    }

    public function unlockPeriod(Request $request, AccountingPeriod $period): RedirectResponse
    {
        $old = $period->toArray();
        $period->update(['status' => 'OPEN','locked_at' => null,'locked_by' => null]);
        AuditService::log($request, 'accounting_period.unlocked', $period, $old, $period->fresh()->toArray());
        return back()->with('status', 'Accounting period reopened.');
    }

    public function close(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        if ($fiscalYear->periods()->where('status', '!=', 'LOCKED')->exists()) {
            return back()->withErrors(['fiscal_year' => 'Lock every accounting period before closing the fiscal year.']);
        }
        $old = $fiscalYear->toArray();
        $fiscalYear->update(['status' => 'CLOSED']);
        AuditService::log($request, 'fiscal_year.closed', $fiscalYear, $old, $fiscalYear->fresh()->toArray());
        return back()->with('status', 'Fiscal year closed.');
    }

    public function reopen(Request $request, FiscalYear $fiscalYear): RedirectResponse
    {
        $old = $fiscalYear->toArray();
        $fiscalYear->update(['status' => 'OPEN']);
        AuditService::log($request, 'fiscal_year.reopened', $fiscalYear, $old, $fiscalYear->fresh()->toArray());
        return back()->with('status', 'Fiscal year reopened. Periods remain locked until individually reopened.');
    }

}
