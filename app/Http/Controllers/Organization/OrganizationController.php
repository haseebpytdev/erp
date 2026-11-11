<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Currency;
use App\Models\FiscalYear;
use App\Models\NumberSequence;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(): View
    {
        $company = Company::query()->firstOrFail();

        return view('organization.index', [
            'company' => $company,
            'branchCount' => $company->branches()->count(),
            'currencyCount' => Currency::query()->where('is_active', true)->count(),
            'fiscalYears' => FiscalYear::query()->withCount('periods')->orderByDesc('starts_on')->get(),
            'sequenceCount' => NumberSequence::query()->where('company_id', $company->id)->where('is_active', true)->count(),
        ]);
    }
}
