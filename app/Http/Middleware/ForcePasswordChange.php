<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && Schema::hasColumn('users','force_password_change') && $request->user()->force_password_change
            && ! $request->routeIs('account.password.*','logout')) {
            return redirect()->route('account.password.edit');
        }
        return $next($request);
    }
}
