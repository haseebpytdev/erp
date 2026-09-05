<?php

namespace App\Http\Middleware;

use App\Services\Operations\GroupUmrahEditAuthority;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final class GuardApprovedGeneralBookingCommercials
{
    public function __construct(private readonly GroupUmrahEditAuthority $authority) {}
    public function handle(Request $request, Closure $next): Response
    {
        $booking=(int)$request->route('booking');
        if($booking>0&&Schema::hasTable('bookings')){
            $columns=Schema::getColumnListing('bookings');$field=null;
            foreach(['approval_status','workflow_status','booking_status','status'] as $candidate)if(in_array($candidate,$columns,true)){$field=$candidate;break;}
            if($field){$status=strtoupper(trim((string)DB::table('bookings')->where('id',$booking)->value($field)));
                if(in_array($status,['APPROVED','CONFIRMED'],true)&&!$this->authority->canReopen($request->user()))abort(423,'Approved booking commercial details are locked. Ask an Administrator to reopen the booking.');
            }
        }
        return $next($request);
    }
}
