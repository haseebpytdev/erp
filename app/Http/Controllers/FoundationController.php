<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FoundationController extends Controller
{
    public function edit(): View
    {
        return view('foundation.edit', [
            'company' => Company::query()->first(),
            'branch' => Branch::query()->first(),
            'fiscalYear' => FiscalYear::query()->latest('starts_on')->first(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_super_admin, 403);

        $data = $request->validate([
            'company_name' => ['required','string','max:150'],
            'company_code' => ['required','string','max:20'],
            'base_currency' => ['required','string','size:3'],
            'branch_name' => ['required','string','max:150'],
            'branch_code' => ['required','string','max:20'],
            'branch_city' => ['nullable','string','max:100'],
        ]);

        $company = Company::query()->firstOrFail();
        $company->update([
            'name' => $data['company_name'],
            'code' => strtoupper($data['company_code']),
            'base_currency' => strtoupper($data['base_currency']),
        ]);

        $branch = Branch::query()->firstOrFail();
        $branch->update([
            'name' => $data['branch_name'],
            'code' => strtoupper($data['branch_code']),
            'city' => $data['branch_city'] ?? null,
        ]);

        return back()->with('status', 'Foundation settings updated.');
    }
}
