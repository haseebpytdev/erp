<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\NumberSequence;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NumberSequenceController extends Controller
{
    public function index(Request $request): View
    {
        $company = Company::query()->firstOrFail();
        return view('organization.sequences', [
            'sequences' => NumberSequence::query()->where('company_id',$company->id)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, NumberSequence $sequence): RedirectResponse
    {
        $old = $sequence->toArray();
        $data = $request->validate([
            'prefix' => ['required','string','max:30'],
            'next_number' => ['required','integer','min:1'],
            'padding' => ['required','integer','between:3,12'],
            'reset_policy' => ['required','in:FISCAL_YEAR,CALENDAR_YEAR,NEVER'],
            'is_active' => ['nullable','boolean'],
        ]);
        $data['prefix'] = strtoupper($data['prefix']);
        $data['is_active'] = $request->boolean('is_active');
        $sequence->update($data);
        AuditService::log($request, 'number_sequence.updated', $sequence, $old, $sequence->fresh()->toArray());
        return back()->with('status', 'Number sequence updated.');
    }

}
