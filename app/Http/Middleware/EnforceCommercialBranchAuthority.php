<?php

namespace App\Http\Middleware;

use App\Services\Administration\ErpCommercialBranchAuthority;
use Closure;
use Illuminate\Http\Request;

final class EnforceCommercialBranchAuthority
{
    public function __construct(private readonly ErpCommercialBranchAuthority $authority) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $route = $request->route();
        $name = (string) ($route?->getName() ?? '');
        $user = $request->user();
        if (str_starts_with($name, 'purchase.supplier-costing.')) {
            if (($id = (int) ($route?->parameter('costing') ?? 0)) > 0) $this->authority->assertSupplierCosting($user, $id);
            elseif (($id = (int) ($route?->parameter('booking') ?? 0)) > 0) $this->authority->assertBooking($user, $id);
        } elseif (str_starts_with($name, 'sales.invoices.')) {
            if (($id = (int) ($route?->parameter('invoice') ?? 0)) > 0) $this->authority->assertInvoice($user, $id);
            elseif (($id = (int) ($route?->parameter('booking') ?? 0)) > 0) $this->authority->assertBooking($user, $id);
        }
        return $next($request);
    }
}
