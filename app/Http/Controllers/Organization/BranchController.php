<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(Request $request): View
    {
        return view('organization.branches', ['branches' => Branch::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = Company::query()->firstOrFail();
        $data = $request->validate([
            'code' => ['required','string','max:20',Rule::unique('branches')->where('company_id',$company->id)],
            'name' => ['required','string','max:150'],
            'city' => ['nullable','string','max:100'],
            'country_code' => ['required','string','size:2'],
            'email' => ['nullable','email','max:150'],
            'phone' => ['nullable','string','max:60'],
            'address' => ['nullable','string','max:1500'],
        ]);
        $branch = Branch::query()->create(array_merge($data, [
            'company_id' => $company->id,
            'code' => strtoupper($data['code']),
            'country_code' => strtoupper($data['country_code']),
            'is_active' => true,
        ]));
        AuditService::log($request, 'branch.created', $branch, [], $branch->toArray());
        return back()->with('status', 'Branch created.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $old = $branch->toArray();
        $data = $request->validate([
            'code' => ['required','string','max:20',Rule::unique('branches')->ignore($branch->id)->where('company_id',$branch->company_id)],
            'name' => ['required','string','max:150'],
            'city' => ['nullable','string','max:100'],
            'country_code' => ['required','string','size:2'],
            'email' => ['nullable','email','max:150'],
            'phone' => ['nullable','string','max:60'],
            'address' => ['nullable','string','max:1500'],
            'is_active' => ['nullable','boolean'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['country_code'] = strtoupper($data['country_code']);
        $data['is_active'] = $request->boolean('is_active');
        $branch->update($data);
        AuditService::log($request, 'branch.updated', $branch, $old, $branch->fresh()->toArray());
        return back()->with('status', 'Branch updated.');
    }

}
