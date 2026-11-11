<?php

namespace App\Http\Controllers\Foundation;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Illuminate\View\View;

class CurrencyRateController extends Controller
{
    public function index(Request $request): View
    {
        $company=Company::query()->firstOrFail();
        $installed=Schema::hasTable('exchange_rates');
        $baseCurrency=$this->companyBaseCurrencyCode((int)$company->id);

        $rates=$installed
            ? DB::table('exchange_rates')
                ->where('company_id',$company->id)
                ->orderByDesc('effective_date')
                ->orderBy('from_currency_code')
                ->limit(250)
                ->get()
            : collect();

        $currencies=collect([(object)['code'=>'SAR'],(object)['code'=>'AED'],(object)['code'=>'USD'],(object)['code'=>'PKR']]);
        if(Schema::hasTable('currencies') && Schema::hasColumn('currencies','code')){
            $currencyQuery=DB::table('currencies');
            if(Schema::hasColumn('currencies','is_active')) $currencyQuery->where('is_active',true);
            $currencies=$currencyQuery->orderBy('code')->get();
        }

        $latest=$installed
            ? collect(['SAR','AED','USD'])->mapWithKeys(function($code) use($company,$baseCurrency){
                $row=DB::table('exchange_rates')->where('company_id',$company->id)
                    ->where('from_currency_code',$code)->where('to_currency_code',$baseCurrency)
                    ->where('is_active',true)->whereDate('effective_date','<=',now()->format('Y-m-d'))
                    ->orderByDesc('effective_date')->orderByDesc('id')->first();
                return [$code=>$row];
            })
            : collect();

        return view('foundation.currency-rates',compact('company','installed','baseCurrency','rates','currencies','latest'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Schema::hasTable('exchange_rates'),422,'Currency Rate upgrade is not installed. Run Safe Database Upgrade first.');
        $company=Company::query()->firstOrFail();
        $baseCurrency=$this->companyBaseCurrencyCode((int)$company->id);

        $data=$request->validate([
            'effective_date'=>['required','date'],
            'from_currency_code'=>['required','string','size:3'],
            'rate'=>['required','numeric','gt:0'],
            'notes'=>['nullable','string','max:500'],
        ]);
        $from=strtoupper($data['from_currency_code']);
        abort_if($from===$baseCurrency,422,'Do not create a rate from the company base currency to itself.');

        $existing=DB::table('exchange_rates')
            ->where('company_id',$company->id)
            ->where('from_currency_code',$from)
            ->where('to_currency_code',$baseCurrency)
            ->whereDate('effective_date',$data['effective_date'])
            ->first();

        $payload=[
            'company_id'=>$company->id,
            'from_currency_code'=>$from,
            'to_currency_code'=>$baseCurrency,
            'rate'=>(float)$data['rate'],
            'effective_date'=>$data['effective_date'],
            'notes'=>trim((string)($data['notes']??''))?:null,
            'is_active'=>true,
            'updated_by'=>$request->user()->id,
            'updated_at'=>now(),
        ];

        if($existing){
            DB::table('exchange_rates')->where('id',$existing->id)->update($payload);
            $message=$from.' rate for '.$data['effective_date'].' updated.';
        }else{
            $payload['created_by']=$request->user()->id;
            $payload['created_at']=now();
            DB::table('exchange_rates')->insert($payload);
            $message=$from.' rate for '.$data['effective_date'].' saved.';
        }

        return back()->with('status',$message.' Existing booking FX snapshots were not changed.');
    }

    public function destroy(Request $request, int $rate): RedirectResponse
    {
        abort_unless(Schema::hasTable('exchange_rates'),404);
        $company=Company::query()->firstOrFail();
        DB::table('exchange_rates')->where('company_id',$company->id)->where('id',$rate)->delete();
        return back()->with('status','Currency rate removed. Historical booking FX snapshots remain unchanged.');
    }

    private function companyBaseCurrencyCode(int $companyId): string
    {
        if(!Schema::hasTable('companies')) return 'PKR';
        foreach(['base_currency_code','currency_code','base_currency'] as $column){
            if(!Schema::hasColumn('companies',$column)) continue;
            try{
                $value=strtoupper(trim((string)DB::table('companies')->where('id',$companyId)->value($column)));
                if(preg_match('/^[A-Z]{3}$/',$value)) return $value;
            }catch(Throwable $e){
                report($e);
            }
        }
        return 'PKR';
    }
}
