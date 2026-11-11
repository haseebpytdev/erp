<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\FiscalYearAutomationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureNextFiscalYear
{
    private static bool $checked=false;

    public function __construct(private readonly FiscalYearAutomationService $service){}

    public function handle(Request $request, Closure $next): Response
    {
        if(!self::$checked){
            self::$checked=true;
            try{
                $company=Company::query()->first();
                if($company) $this->service->ensureNext((int)$company->id,$request->user()?->id);
            }catch(Throwable $e){
                // Never interrupt operational work because of housekeeping; the Financial Years page
                // exposes a manual preparation action and any schema issue can be corrected there.
                report($e);
            }
        }
        return $next($request);
    }
}
