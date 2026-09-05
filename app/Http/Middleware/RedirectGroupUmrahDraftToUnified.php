<?php
namespace App\Http\Middleware;
use App\Services\Operations\NativeBookingCustomerResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RedirectGroupUmrahDraftToUnified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->boolean('group_umrah_unified')) return $next($request);
        $beforeMax = null;
        try { if (Schema::hasTable('bookings')) $beforeMax = (int) (DB::table('bookings')->max('id') ?? 0); } catch (\Throwable) {}
        $response = $next($request);
        if ($response->getStatusCode() >= 400) return $response;
        $bookingId = $this->bookingIdFromResponse($response) ?: $this->newBookingId($request, $beforeMax);
        if (! $bookingId) return $response;

        try {
            app(NativeBookingCustomerResolver::class)->capture(
                $bookingId,
                (int) (
                    $request->input('group_umrah_customer_id')
                    ?: $request->input('customer_id')
                    ?: $request->input('party_id')
                    ?: $request->input('client_id')
                    ?: 0
                ),
                (string) $request->input('group_umrah_customer_name', ''),
                'native-booking-create'
            );
        } catch (\Throwable) {
        }

        return redirect()->route('operations.bookings.group-package-unified.edit', ['booking' => $bookingId]);
    }
    private function bookingIdFromResponse(Response $response): ?int
    {
        $location = (string) $response->headers->get('Location','');
        if ($location !== '' && preg_match('#/operations/bookings/(\\d+)(?:/|$|\\?)#', $location, $m)) return (int) $m[1];
        try {
            $content=(string)$response->getContent();
            if ($content !== '' && str_contains(strtolower((string)$response->headers->get('Content-Type','')),'json')) {
                $data=json_decode($content,true);
                if (!is_array($data)) return null;
                foreach(['booking_id','id'] as $key) if (!empty($data[$key])) return (int)$data[$key];
                if (!empty($data['booking']['id'])) return (int)$data['booking']['id'];
            }
        } catch (\Throwable) {}
        return null;
    }
    private function newBookingId(Request $request, ?int $beforeMax): ?int
    {
        try {
            if (!Schema::hasTable('bookings')) return null;
            $columns=Schema::getColumnListing('bookings'); $q=DB::table('bookings');
            if ($beforeMax !== null) $q->where('id','>',$beforeMax);
            $customerId=(int)($request->input('customer_id') ?: $request->input('party_id') ?: $request->input('client_id') ?: 0);
            foreach(['customer_id','party_id','client_id'] as $c) if ($customerId>0 && in_array($c,$columns,true)) { $q->where($c,$customerId); break; }
            $uid=Auth::id(); foreach(['created_by','created_by_id','user_id'] as $c) if ($uid && in_array($c,$columns,true)) { $q->where($c,$uid); break; }
            $id=$q->orderByDesc('id')->value('id'); return $id ? (int)$id : null;
        } catch (\Throwable) { return null; }
    }
}
