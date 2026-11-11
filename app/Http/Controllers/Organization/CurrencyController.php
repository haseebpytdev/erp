<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CurrencyController extends Controller
{
    public function index(Request $request): View
    {
        return view('organization.currencies', ['currencies' => Currency::query()->orderBy('code')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required','string','size:3','unique:currencies,code'],
            'name' => ['required','string','max:80'],
            'symbol' => ['nullable','string','max:12'],
            'decimal_places' => ['required','integer','between:0,6'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = true;
        $currency = Currency::query()->create($data);
        AuditService::log($request, 'currency.created', null, [], $currency->toArray());
        return back()->with('status', 'Currency added.');
    }

    public function update(Request $request, string $currency): RedirectResponse
    {
        $model = Currency::query()->findOrFail(strtoupper($currency));
        $old = $model->toArray();
        $data = $request->validate([
            'name' => ['required','string','max:80'],
            'symbol' => ['nullable','string','max:12'],
            'decimal_places' => ['required','integer','between:0,6'],
            'is_active' => ['nullable','boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $model->update($data);
        AuditService::log($request, 'currency.updated', null, $old, $model->fresh()->toArray());
        return back()->with('status', 'Currency updated.');
    }

}
