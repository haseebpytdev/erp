<?php

namespace App\Http\Controllers\Foundation;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\FiscalYearAutomationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class FinancialYearAutomationController extends Controller
{
    public function __construct(private readonly FiscalYearAutomationService $service){}

    public function index(Request $request): View
    {
        abort_unless($request->user()->is_super_admin,403);
        $company=Company::query()->firstOrFail();
        $rows=$this->service->rows((int)$company->id)->map(fn($row)=>$this->service->displayRow($row));
        return view('foundation.financial-years',[ 'company'=>$company,'years'=>$rows,'installed'=>$this->service->installed() ]);
    }

    public function createNext(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin,403);
        $company=Company::query()->firstOrFail();
        if(!$this->service->installed()) return back()->withErrors(['financial_year'=>'Fiscal Year tables are not installed. Run Safe Database Upgrade first.']);
        try{
            $id=$this->service->ensureNext((int)$company->id,$request->user()->id);
            return back()->with('status',$id?'Next Financial Year created and prepared.':'The next Financial Year is already prepared.');
        }catch(Throwable $e){
            report($e);
            return back()->withErrors(['financial_year'=>$e->getMessage()]);
        }
    }
}
