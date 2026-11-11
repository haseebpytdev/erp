<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class SystemUpdateController extends Controller
{
    public function index(Request $request): View
    {
        $this->admin($request);
        return view('system.update',[
            'database'=>$this->databaseOk(),
            'erp01Ready'=>Schema::hasTable('number_sequences')&&Schema::hasTable('currencies'),
            'erp02Ready'=>Schema::hasTable('staff')&&Schema::hasTable('roles')&&Schema::hasTable('permissions')&&Schema::hasTable('approval_policies'),
            'erp03Ready'=>Schema::hasTable('accounts')&&Schema::hasTable('account_mappings'),
            'erp04Ready'=>Schema::hasTable('journal_entries')&&Schema::hasTable('journal_lines')&&Schema::hasTable('approval_actions')&&Schema::hasTable('number_sequence_counters'),
            'erp05Ready'=>Schema::hasTable('parties')&&Schema::hasTable('party_roles')&&Schema::hasTable('customer_profiles')&&Schema::hasTable('vendor_profiles')&&Schema::hasTable('agent_profiles'),
            'erp06Ready'=>Schema::hasTable('airlines')&&Schema::hasTable('booking_sources')&&Schema::hasTable('hotels')&&Schema::hasTable('products_services'),
            'erp07Ready'=>Schema::hasTable('bookings')&&Schema::hasTable('booking_passengers')&&Schema::hasTable('booking_services')&&Schema::hasTable('booking_service_passengers'),
            'erp08Ready'=>Schema::hasTable('air_ticket_details')&&Schema::hasTable('hotel_booking_details')&&Schema::hasTable('visa_booking_details')&&Schema::hasTable('transport_booking_details'),
            'erp09Ready'=>Schema::hasTable('sales_invoices')&&Schema::hasTable('invoice_passengers')&&Schema::hasTable('sales_invoice_lines')&&Schema::hasTable('sales_invoice_line_passengers'),
            'erp096Ready'=>Schema::hasColumn('companies','website')&&Schema::hasColumn('companies','logo_data_uri'),
            'erp10Ready'=>Schema::hasTable('service_cost_allocations')&&DB::table('account_mappings')->where('mapping_key','OTHER_DIRECT_COST')->whereNotNull('account_id')->exists(),
            'erp101Ready'=>Schema::hasTable('commission_allocations')&&Schema::hasColumn('air_ticket_details','ticket_status')&&Schema::hasColumn('air_ticket_details','supplier_vendor_party_id')&&Schema::hasColumn('service_cost_allocations','air_ticket_detail_id'),
            'phpVersion'=>PHP_VERSION,'laravelVersion'=>app()->version(),
        ]);
    }
    public function migrate(Request $request): RedirectResponse
    {
        $this->admin($request);
        try { Artisan::call('migrate',['--force'=>true]); $output=trim(Artisan::output()); Artisan::call('optimize:clear'); return back()->with('status','Database upgrade completed. '.($output?:'Application cache cleared.')); }
        catch (\Throwable $e) { report($e); return back()->withErrors(['upgrade'=>'Database upgrade failed: '.$e->getMessage()]); }
    }
    public function clearCache(Request $request): RedirectResponse { $this->admin($request); Artisan::call('optimize:clear'); return back()->with('status','Application cache cleared.'); }
    private function databaseOk(): bool { try { DB::select('select 1'); return true; } catch (\Throwable) { return false; } }
    private function admin(Request $request): void { abort_unless($request->user()?->is_super_admin,403); }
}
