<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    /** @var array<string,array<int,string>> */
    private array $columnCache = [];

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $companyId = $this->firstId('companies');
        // Executive finance values (cash/bank, profit, receivables and payables) are
        // restricted to Super Admin or accounting-ledger viewers. Sales/costing-only
        // roles still receive the operational dashboard without sensitive finance KPIs.
        $canSeeFinance = (bool) ($user?->is_super_admin ?? false)
            || (method_exists($user, 'hasPermission') && $user->hasPermission('journals.view'));
        $canChooseBranch = (bool) ($user?->is_super_admin ?? false)
            || (method_exists($user, 'hasPermission') && $user->hasPermission('organization.branches.view'));

        $branches = $this->branches($companyId);
        $branchId = $this->resolveBranchId($request, $user, $branches, $canChooseBranch);
        [$periodKey, $periodLabel, $periodStart, $periodEnd] = $this->resolvePeriod($request, $companyId);

        $todayStart = now()->copy()->startOfDay();
        $todayEnd = now()->copy()->endOfDay();
        $monthStart = now()->copy()->startOfMonth();
        $monthEnd = now()->copy()->endOfMonth();

        $sales = $this->salesMetrics($companyId, $branchId, $periodStart, $periodEnd, $todayStart, $todayEnd, $monthStart, $monthEnd);
        $costs = $this->costMetrics($companyId, $branchId, $periodStart, $periodEnd);
        $receivable = $this->receivableMetric($companyId, $branchId);
        $payable = $this->supplierPayableMetric($companyId, $branchId);
        $cashBank = $this->cashBankMetric($companyId, $branchId);
        $journalFlow = $this->journalFlowMetrics($companyId, $branchId, $todayStart, $todayEnd);

        $finance = [
            'today_sales' => $sales['today'],
            'month_sales' => $sales['month'],
            'period_sales' => $sales['period'],
            'period_cost' => $costs['period'],
            'gross_profit' => $sales['period'] - $costs['period'],
            'receivable' => $receivable['value'],
            'receivable_available' => $receivable['available'],
            'payable' => $payable['value'],
            'payable_available' => $payable['available'],
            'payable_note' => $payable['note'],
            'cash_bank' => $cashBank['value'],
            'cash_bank_available' => $cashBank['available'],
            'today_collections' => $journalFlow['collections'],
            'today_payments' => $journalFlow['payments'],
            'journal_flow_available' => $journalFlow['available'],
        ];

        $operations = $this->operationMetrics($companyId, $branchId);
        $upcomingTravel = $this->upcomingTravel($companyId, $branchId, 7);
        $recentBookings = $this->recentBookings($companyId, $branchId, 8);
        $recentTransactions = $this->recentTransactions($companyId, $branchId, 8);
        $alerts = $this->alerts($companyId, $branchId);
        $approvals = $this->pendingApprovals($companyId, $branchId);
        $topReceivables = $this->topReceivables($companyId, $branchId, 6);
        $topPayables = $this->topPayables($companyId, $branchId, 6);
        $trend = $this->monthlyTrend($companyId, $branchId, 12);
        $productMix = $this->productMix($companyId, $branchId, $periodStart, $periodEnd);

        return view('dashboard', compact(
            'canSeeFinance', 'canChooseBranch', 'branches', 'branchId',
            'periodKey', 'periodLabel', 'periodStart', 'periodEnd',
            'finance', 'operations', 'upcomingTravel', 'recentBookings', 'recentTransactions',
            'alerts', 'approvals', 'topReceivables', 'topPayables', 'trend', 'productMix'
        ));
    }

    private function salesMetrics(?int $companyId, ?int $branchId, Carbon $periodStart, Carbon $periodEnd, Carbon $todayStart, Carbon $todayEnd, Carbon $monthStart, Carbon $monthEnd): array
    {
        if (!$this->hasTable('sales_invoices')) return ['today'=>0.0,'month'=>0.0,'period'=>0.0];
        $amount = $this->column('sales_invoices', ['grand_total','total_amount','invoice_total','net_total','total','amount']);
        $date = $this->column('sales_invoices', ['invoice_date','date','posted_at','created_at']);
        if (!$amount || !$date) return ['today'=>0.0,'month'=>0.0,'period'=>0.0];

        return [
            'today' => $this->sumRange('sales_invoices', $amount, $date, $todayStart, $todayEnd, $companyId, $branchId, ['CANCELLED','VOID']),
            'month' => $this->sumRange('sales_invoices', $amount, $date, $monthStart, $monthEnd, $companyId, $branchId, ['CANCELLED','VOID']),
            'period' => $this->sumRange('sales_invoices', $amount, $date, $periodStart, $periodEnd, $companyId, $branchId, ['CANCELLED','VOID']),
        ];
    }

    private function costMetrics(?int $companyId, ?int $branchId, Carbon $start, Carbon $end): array
    {
        $table = $this->firstTable(['service_cost_allocations','supplier_cost_allocations','booking_cost_allocations']);
        if (!$table) return ['period'=>$this->operationalSupplierCost($companyId, $branchId, $start, $end)];
        $amount = $this->column($table, ['net_supplier_cost','total_cost','cost_amount','amount','allocated_cost']);
        $date = $this->column($table, ['cost_date','confirmed_at','created_at','updated_at']);
        if (!$amount || !$date) return ['period'=>$this->operationalSupplierCost($companyId, $branchId, $start, $end)];
        return ['period'=>$this->sumRange($table, $amount, $date, $start, $end, $companyId, $branchId, ['CANCELLED','VOID'])];
    }

    private function operationalSupplierCost(?int $companyId, ?int $branchId, Carbon $start, Carbon $end): float
    {
        $total = 0.0;
        foreach ([
            ['air_ticket_details','net_supplier_cost',['created_at','departure_at']],
            ['hotel_booking_details',$this->hasColumn('hotel_booking_details','supplier_cost_base')?'supplier_cost_base':'net_supplier_cost',['created_at','check_in']],
            ['visa_service_operators',$this->hasColumn('visa_service_operators','total_cost_base')?'total_cost_base':'adult_cost_price',['created_at','updated_at']],
            ['transport_booking_details',$this->hasColumn('transport_booking_details','cost_price_base')?'cost_price_base':'cost_price',['created_at','pickup_at']],
        ] as [$table,$amount,$dates]) {
            if (!$this->hasTable($table) || !$this->hasColumn($table,$amount)) continue;
            $date = $this->column($table,$dates);
            if (!$date) continue;
            $total += $this->sumRange($table,$amount,$date,$start,$end,$companyId,$branchId,['CANCELLED','VOID']);
        }
        return $total;
    }

    private function receivableMetric(?int $companyId, ?int $branchId): array
    {
        if (!$this->hasTable('sales_invoices')) return ['value'=>0.0,'available'=>false];
        $balance = $this->column('sales_invoices',['balance_due','outstanding_amount','receivable_amount','amount_due']);
        if ($balance) return ['value'=>$this->sumCurrent('sales_invoices',$balance,$companyId,$branchId,['CANCELLED','VOID']), 'available'=>true];

        $total = $this->column('sales_invoices',['grand_total','total_amount','invoice_total','net_total','total','amount']);
        $paid = $this->column('sales_invoices',['paid_amount','amount_paid','received_amount']);
        if (!$total) return ['value'=>0.0,'available'=>false];
        if (!$paid) return ['value'=>$this->sumCurrent('sales_invoices',$total,$companyId,$branchId,['CANCELLED','VOID']), 'available'=>true];

        $query = $this->scoped('sales_invoices',$companyId,$branchId);
        $this->excludeStatuses($query,'sales_invoices',['CANCELLED','VOID']);
        $value = $query->get([$total,$paid])->sum(fn($r)=>max(0,(float)($r->{$total}??0)-(float)($r->{$paid}??0)));
        return ['value'=>(float)$value,'available'=>true];
    }

    private function supplierPayableMetric(?int $companyId, ?int $branchId): array
    {
        foreach (['vendor_bills','purchase_invoices','supplier_bills'] as $table) {
            if (!$this->hasTable($table)) continue;
            $balance = $this->column($table,['balance_due','outstanding_amount','payable_amount','amount_due']);
            $amount = $balance ?: $this->column($table,['grand_total','total_amount','net_total','total','amount']);
            if ($amount) return ['value'=>$this->sumCurrent($table,$amount,$companyId,$branchId,['CANCELLED','VOID']), 'available'=>true, 'note'=>''];
        }

        $table = $this->firstTable(['service_cost_allocations','supplier_cost_allocations','booking_cost_allocations']);
        if ($table) {
            $amount = $this->column($table,['net_supplier_cost','total_cost','cost_amount','amount','allocated_cost']);
            if ($amount) return ['value'=>$this->sumCurrent($table,$amount,$companyId,$branchId,['CANCELLED','VOID']), 'available'=>true, 'note'=>'Confirmed supplier cost exposure'];
        }
        return ['value'=>0.0,'available'=>false,'note'=>''];
    }

    private function cashBankMetric(?int $companyId, ?int $branchId): array
    {
        foreach (['accounts','chart_of_accounts'] as $table) {
            if (!$this->hasTable($table)) continue;
            $balance = $this->column($table,['current_balance','balance','closing_balance']);
            if (!$balance) continue;
            $type = $this->column($table,['account_type','type','category','account_group']);
            $name = $this->column($table,['name','account_name','title']);
            $query = $this->scoped($table,$companyId,$branchId);
            if ($type || $name) {
                $query->where(function($q) use($type,$name): void {
                    if ($type) {
                        $q->where($type,'like','%CASH%')->orWhere($type,'like','%BANK%');
                        if ($name) $q->orWhere($name,'like','%Cash%')->orWhere($name,'like','%Bank%');
                    } elseif ($name) {
                        $q->where($name,'like','%Cash%')->orWhere($name,'like','%Bank%');
                    }
                });
            }
            return ['value'=>(float)$query->sum($balance),'available'=>true];
        }
        return ['value'=>0.0,'available'=>false];
    }

    private function journalFlowMetrics(?int $companyId, ?int $branchId, Carbon $start, Carbon $end): array
    {
        $table = $this->firstTable(['journals','journal_vouchers','journal_entries']);
        if (!$table) return ['collections'=>0.0,'payments'=>0.0,'available'=>false];
        $type = $this->column($table,['voucher_type','journal_type','type','transaction_type']);
        $amount = $this->column($table,['total_amount','amount','total_debit','debit_total']);
        $date = $this->column($table,['journal_date','voucher_date','date','posted_at','created_at']);
        if (!$type || !$amount || !$date) return ['collections'=>0.0,'payments'=>0.0,'available'=>false];
        $base = fn()=> $this->periodQuery($table,$date,$start,$end,$companyId,$branchId);
        $receipts = $base()->where(function($q)use($type){$q->where($type,'like','%RECEIPT%')->orWhere($type,'RV');})->sum($amount);
        $payments = $base()->where(function($q)use($type){$q->where($type,'like','%PAYMENT%')->orWhere($type,'PV');})->sum($amount);
        return ['collections'=>(float)$receipts,'payments'=>(float)$payments,'available'=>true];
    }

    private function operationMetrics(?int $companyId, ?int $branchId): array
    {
        $bookingStatuses = $this->statusCounts('bookings','status',$companyId,$branchId);
        $ticketStatuses = $this->statusCounts('air_ticket_details','ticket_status',$companyId,$branchId);
        $visaStatuses = $this->statusCounts('visa_booking_details','status',$companyId,$branchId);

        $hotel = ['upcoming'=>0,'check_in_today'=>0,'check_out_today'=>0,'in_house'=>0];
        if ($this->hasTable('hotel_booking_details')) {
            $checkIn = $this->column('hotel_booking_details',['check_in','check_in_date']);
            $checkOut = $this->column('hotel_booking_details',['check_out','check_out_date']);
            if ($checkIn) {
                $hotel['check_in_today'] = $this->scoped('hotel_booking_details',$companyId,$branchId)->whereDate($checkIn,now()->toDateString())->count();
                $hotel['upcoming'] = $this->scoped('hotel_booking_details',$companyId,$branchId)->whereDate($checkIn,'>=',now()->toDateString())->count();
            }
            if ($checkOut) $hotel['check_out_today'] = $this->scoped('hotel_booking_details',$companyId,$branchId)->whereDate($checkOut,now()->toDateString())->count();
            if ($checkIn && $checkOut) $hotel['in_house'] = $this->scoped('hotel_booking_details',$companyId,$branchId)->whereDate($checkIn,'<=',now()->toDateString())->whereDate($checkOut,'>=',now()->toDateString())->count();
        }

        $passengerTotal = $this->hasTable('booking_passengers') ? $this->scoped('booking_passengers',$companyId,$branchId)->count() : 0;
        $travelNext7 = 0;
        $returningNext7 = 0;
        if ($this->hasTable('bookings') && $this->hasTable('booking_passengers') && $this->hasColumn('bookings','travel_start_date') && $this->hasColumn('booking_passengers','booking_id')) {
            $window=[now()->toDateString(),now()->copy()->addDays(7)->toDateString()];
            $q=DB::table('booking_passengers as bp')->join('bookings as b','b.id','=','bp.booking_id')->whereBetween('b.travel_start_date',$window);
            if ($companyId && $this->hasColumn('bookings','company_id')) $q->where('b.company_id',$companyId);
            if ($branchId && $this->hasColumn('bookings','branch_id')) $q->where('b.branch_id',$branchId);
            $travelNext7=$q->count();

            if ($this->hasColumn('bookings','travel_end_date')) {
                $rq=DB::table('booking_passengers as bp')->join('bookings as b','b.id','=','bp.booking_id')->whereBetween('b.travel_end_date',$window);
                if ($companyId && $this->hasColumn('bookings','company_id')) $rq->where('b.company_id',$companyId);
                if ($branchId && $this->hasColumn('bookings','branch_id')) $rq->where('b.branch_id',$branchId);
                $returningNext7=$rq->count();
            }
        }

        return [
            'bookings'=>[
                'draft'=>$this->countStatus($bookingStatuses,['DRAFT']),
                'pending'=>$this->countStatus($bookingStatuses,['PENDING','PENDING_REVIEW','UNCONFIRMED']),
                'confirmed'=>$this->countStatus($bookingStatuses,['CONFIRMED','APPROVED']),
                'cancelled'=>$this->countStatus($bookingStatuses,['CANCELLED','VOID']),
                'total'=>array_sum($bookingStatuses),
            ],
            'tickets'=>[
                'booked'=>$this->countStatus($ticketStatuses,['BOOKED']),
                'issued'=>$this->countStatus($ticketStatuses,['ISSUED']),
                'refund_void'=>$this->countStatus($ticketStatuses,['REFUNDED','VOID','REISSUED']),
                'missing_number'=>$this->missingTicketNumberCount($companyId,$branchId),
                'total'=>array_sum($ticketStatuses),
            ],
            'hotels'=>$hotel,
            'visa'=>[
                'pending'=>$this->countStatus($visaStatuses,['PENDING','DRAFT']),
                'processing'=>$this->countStatus($visaStatuses,['PROCESSING','SUBMITTED','APPLIED']),
                'issued'=>$this->countStatus($visaStatuses,['ISSUED','APPROVED']),
                'rejected'=>$this->countStatus($visaStatuses,['REJECTED','CANCELLED']),
                'total'=>array_sum($visaStatuses),
            ],
            'passengers'=>['total'=>$passengerTotal,'next_7_days'=>$travelNext7,'returning_7_days'=>$returningNext7],
        ];
    }

    private function upcomingTravel(?int $companyId, ?int $branchId, int $days): Collection
    {
        if (!$this->hasTable('bookings') || !$this->hasColumn('bookings','travel_start_date')) return collect();

        $from = now()->toDateString();
        $to = now()->copy()->addDays($days)->toDateString();
        $hasReturnDate = $this->hasColumn('bookings','travel_end_date');

        $query = $this->scoped('bookings',$companyId,$branchId)
            ->where(function($q) use($from,$to,$hasReturnDate): void {
                $q->whereBetween('travel_start_date',[$from,$to]);
                if ($hasReturnDate) $q->orWhereBetween('travel_end_date',[$from,$to]);
            })
            ->orderBy('id');

        $cols = array_values(array_filter(
            ['id','booking_no','booking_type','status','travel_start_date','travel_end_date','currency_code'],
            fn($col)=>$this->hasColumn('bookings',$col)
        ));

        $rows = $query->limit(24)->get($cols);
        $movements = collect();
        $inWindow = static function($value) use($from,$to): bool {
            if (!$value) return false;
            $date = substr((string)$value,0,10);
            return $date >= $from && $date <= $to;
        };

        foreach ($rows as $row) {
            $passengerCount = $this->bookingPassengerCount((int)$row->id);
            $route = $this->bookingRoute((int)$row->id);

            if ($inWindow($row->travel_start_date ?? null)) {
                $movement = clone $row;
                $movement->event_date = $row->travel_start_date;
                $movement->movement = 'DEPARTURE';
                $movement->passenger_count = $passengerCount;
                $movement->route = $route;
                $movements->push($movement);
            }

            if ($hasReturnDate && $inWindow($row->travel_end_date ?? null)) {
                $movement = clone $row;
                $movement->event_date = $row->travel_end_date;
                $movement->movement = 'RETURN';
                $movement->passenger_count = $passengerCount;
                $movement->route = $route;
                $movements->push($movement);
            }
        }

        return $movements
            ->sortBy(fn($row)=>sprintf('%s-%s-%010d',(string)$row->event_date,$row->movement==='RETURN'?'1':'0',(int)$row->id))
            ->take(12)
            ->values();
    }

    private function recentBookings(?int $companyId, ?int $branchId, int $limit): Collection
    {
        if (!$this->hasTable('bookings')) return collect();
        $date=$this->column('bookings',['booking_date','created_at']);
        $query=$this->scoped('bookings',$companyId,$branchId);
        if($date)$query->orderByDesc($date); else $query->orderByDesc('id');
        $cols=array_values(array_filter(['id','booking_no','booking_type','status','booking_date','travel_start_date','currency_code'],fn($c)=>$this->hasColumn('bookings',$c)));
        $rows=$query->limit($limit)->get($cols);
        foreach($rows as $row){
            $row->passenger_count=$this->bookingPassengerCount((int)$row->id);
            $row->invoice_total=$this->bookingInvoiceTotal((int)$row->id);
        }
        return $rows;
    }

    private function recentTransactions(?int $companyId, ?int $branchId, int $limit): Collection
    {
        $rows=collect();
        if($this->hasTable('sales_invoices')){
            $date=$this->column('sales_invoices',['invoice_date','posted_at','created_at']);
            $amount=$this->column('sales_invoices',['grand_total','total_amount','invoice_total','net_total','total','amount']);
            $number=$this->column('sales_invoices',['invoice_no','invoice_number','number','reference']);
            $q=$this->scoped('sales_invoices',$companyId,$branchId);
            if($date)$q->orderByDesc($date); else $q->orderByDesc('id');
            foreach($q->limit($limit)->get() as $r){
                $rows->push((object)['type'=>'Sales Invoice','reference'=>$number?($r->{$number}??('#'.$r->id)):('#'.$r->id),'amount'=>$amount?(float)($r->{$amount}??0):null,'status'=>$r->status??null,'date'=>$date?($r->{$date}??null):null,'url'=>route('sales.invoices.show',$r->id),'permission'=>'sales_invoices.view']);
            }
        }
        $journalTable=$this->firstTable(['journals','journal_vouchers','journal_entries']);
        if($journalTable){
            $date=$this->column($journalTable,['journal_date','voucher_date','date','posted_at','created_at']);
            $amount=$this->column($journalTable,['total_amount','amount','total_debit','debit_total']);
            $number=$this->column($journalTable,['journal_no','voucher_no','number','reference']);
            $type=$this->column($journalTable,['voucher_type','journal_type','type','transaction_type']);
            $q=$this->scoped($journalTable,$companyId,$branchId);
            if($date)$q->orderByDesc($date); else $q->orderByDesc('id');
            foreach($q->limit($limit)->get() as $r){
                $rows->push((object)['type'=>$type?str_replace('_',' ',(string)($r->{$type}??'Journal')):'Journal','reference'=>$number?($r->{$number}??('#'.$r->id)):('#'.$r->id),'amount'=>$amount?(float)($r->{$amount}??0):null,'status'=>$r->status??null,'date'=>$date?($r->{$date}??null):null,'url'=>route('accounting.journals.show',$r->id),'permission'=>'journals.view']);
            }
        }
        return $rows->sortByDesc(fn($r)=>strtotime((string)($r->date??''))?:0)->take($limit)->values();
    }

    private function alerts(?int $companyId, ?int $branchId): array
    {
        $alerts=[];
        $missing=$this->missingTicketNumberCount($companyId,$branchId);
        if($missing>0)$alerts[]=['level'=>'danger','label'=>"{$missing} issued/reissued ticket(s) missing Ticket Number",'url'=>route('operations.bookings.index',['attention'=>'missing-ticket-number']),'permission'=>'bookings.view'];

        if($this->hasTable('air_ticket_details') && $this->hasColumn('air_ticket_details','supplier_vendor_party_id')){
            $q=$this->scoped('air_ticket_details',$companyId,$branchId)->whereNull('supplier_vendor_party_id');
            if($this->hasColumn('air_ticket_details','ticket_status'))$q->whereNotIn('ticket_status',['VOID','REFUNDED']);
            $n=$q->count();
            if($n>0)$alerts[]=['level'=>'warning','label'=>"{$n} active ticket(s) missing supplier/vendor",'url'=>route('operations.costing.index'),'permission'=>'costing.view'];
        }
        if($this->hasTable('hotel_booking_details') && $this->hasColumn('hotel_booking_details','occupancy')){
            $n=$this->scoped('hotel_booking_details',$companyId,$branchId)->where('occupancy','like','%No Bed%')->count();
            if($n>0)$alerts[]=['level'=>'info','label'=>"{$n} hotel stay(s) include No-Bed passenger allocation",'url'=>route('operations.bookings.index',['attention'=>'hotel-no-bed']),'permission'=>'bookings.view'];
        }
        if($this->hasTable('sales_invoices') && $this->hasColumn('sales_invoices','status')){
            $n=$this->scoped('sales_invoices',$companyId,$branchId)->where('status','PENDING_APPROVAL')->count();
            if($n>0)$alerts[]=['level'=>'warning','label'=>"{$n} sales invoice(s) pending approval",'url'=>route('sales.invoices.index',['status'=>'PENDING_APPROVAL']),'permission'=>'sales_invoices.view'];
        }
        if($this->hasTable('booking_passengers')){
            $expiry=$this->column('booking_passengers',['passport_expiry','document_expiry_date','passport_expiry_date']);
            if($expiry){
                $n=$this->scoped('booking_passengers',$companyId,$branchId)->whereNotNull($expiry)->whereDate($expiry,'<=',now()->copy()->addDays(180)->toDateString())->count();
                if($n>0)$alerts[]=['level'=>'warning','label'=>"{$n} passenger document(s) expire within 180 days",'url'=>route('operations.bookings.index',['attention'=>'passport-expiry']),'permission'=>'bookings.view'];
            }
        }
        if(!$alerts)$alerts[]=['level'=>'success','label'=>'No critical record exceptions detected in the current dashboard scope.','url'=>null,'permission'=>null];
        return array_slice($alerts,0,8);
    }

    private function pendingApprovals(?int $companyId, ?int $branchId): array
    {
        $items=[];
        if($this->hasTable('sales_invoices') && $this->hasColumn('sales_invoices','status')){
            $items[]=['label'=>'Sales Invoices','count'=>$this->scoped('sales_invoices',$companyId,$branchId)->where('status','PENDING_APPROVAL')->count(),'url'=>route('sales.invoices.index',['status'=>'PENDING_APPROVAL']),'permission'=>'sales_invoices.view'];
        }
        $journalTable=$this->firstTable(['journals','journal_vouchers','journal_entries']);
        if($journalTable && $this->hasColumn($journalTable,'status')){
            $items[]=['label'=>'Journals','count'=>$this->scoped($journalTable,$companyId,$branchId)->whereIn('status',['SUBMITTED','PENDING_APPROVAL'])->count(),'url'=>route('accounting.journals.index',['status'=>'PENDING_APPROVAL']),'permission'=>'journals.view'];
        }
        return $items;
    }

    private function topReceivables(?int $companyId, ?int $branchId, int $limit): Collection
    {
        if(!$this->hasTable('sales_invoices'))return collect();
        $party=$this->column('sales_invoices',['customer_party_id','party_id','bill_to_party_id']);
        $balance=$this->column('sales_invoices',['balance_due','outstanding_amount','receivable_amount','amount_due']);
        $total=$this->column('sales_invoices',['grand_total','total_amount','invoice_total','net_total','total','amount']);
        $paid=$this->column('sales_invoices',['paid_amount','amount_paid','received_amount']);
        if(!$party || (!$balance && !$total))return collect();
        $q=$this->scoped('sales_invoices',$companyId,$branchId);
        $this->excludeStatuses($q,'sales_invoices',['CANCELLED','VOID']);
        $rows=$q->get(array_values(array_filter([$party,$balance,$total,$paid])));
        $grouped=[];
        foreach($rows as $r){
            $pid=(int)($r->{$party}??0); if(!$pid)continue;
            $value=$balance?(float)($r->{$balance}??0):max(0,(float)($r->{$total}??0)-(float)($paid?($r->{$paid}??0):0));
            $grouped[$pid]=($grouped[$pid]??0)+$value;
        }
        arsort($grouped);
        return collect(array_slice($grouped,0,$limit,true))->map(function($amount,$pid){
            return (object)['party_id'=>(int)$pid,'name'=>$this->partyName((int)$pid),'amount'=>(float)$amount];
        })->values();
    }

    private function topPayables(?int $companyId, ?int $branchId, int $limit): Collection
    {
        $table=$this->firstTable(['service_cost_allocations','vendor_bills','purchase_invoices','supplier_bills']);
        if(!$table)return collect();
        $party=$this->column($table,['supplier_vendor_party_id','vendor_party_id','supplier_party_id','party_id']);
        $amount=$this->column($table,['balance_due','outstanding_amount','net_supplier_cost','total_amount','grand_total','total','amount']);
        if(!$party || !$amount)return collect();
        $q=$this->scoped($table,$companyId,$branchId); $this->excludeStatuses($q,$table,['CANCELLED','VOID']);
        $rows=$q->get([$party,$amount]); $grouped=[];
        foreach($rows as $r){$pid=(int)($r->{$party}??0);if(!$pid)continue;$grouped[$pid]=($grouped[$pid]??0)+(float)($r->{$amount}??0);}
        arsort($grouped);
        return collect(array_slice($grouped,0,$limit,true))->map(function($amount,$pid){return (object)['party_id'=>(int)$pid,'name'=>$this->partyName((int)$pid),'amount'=>(float)$amount];})->values();
    }

    private function monthlyTrend(?int $companyId, ?int $branchId, int $months): array
    {
        $labels=[];$sales=[];$costs=[];$profit=[];
        $cursor=now()->copy()->startOfMonth()->subMonths($months-1);
        for($i=0;$i<$months;$i++){
            $start=$cursor->copy()->startOfMonth();$end=$cursor->copy()->endOfMonth();
            $s=$this->salesMetrics($companyId,$branchId,$start,$end,$start,$end,$start,$end)['period'];
            $c=$this->costMetrics($companyId,$branchId,$start,$end)['period'];
            $labels[]=$start->format('M y');$sales[]=round($s,2);$costs[]=round($c,2);$profit[]=round($s-$c,2);$cursor->addMonth();
        }
        return compact('labels','sales','costs','profit');
    }

    private function productMix(?int $companyId, ?int $branchId, Carbon $start, Carbon $end): array
    {
        $mix=[];
        if($this->hasTable('air_ticket_details') && $this->hasColumn('air_ticket_details','selling_total')){
            $date=$this->column('air_ticket_details',['departure_at','created_at']);
            if($date)$mix['Air Tickets']=$this->sumRange('air_ticket_details','selling_total',$date,$start,$end,$companyId,$branchId,['VOID','REFUNDED']);
        }
        if($this->hasTable('hotel_booking_details') && $this->hasColumn('hotel_booking_details','customer_sell_total')){
            $date=$this->column('hotel_booking_details',['check_in','created_at']);
            if($date)$mix['Hotels']=$this->sumRange('hotel_booking_details','customer_sell_total',$date,$start,$end,$companyId,$branchId,['CANCELLED','VOID']);
        }
        // If service/invoice line structures expose category totals, include them without assuming a fixed schema.
        if($this->hasTable('sales_invoice_lines')){
            $category=$this->column('sales_invoice_lines',['category','service_category','product_category']);
            $amount=$this->column('sales_invoice_lines',['line_total','total_amount','amount','total']);
            $date=$this->column('sales_invoice_lines',['service_date','created_at']);
            if($category && $amount && $date){
                $q=$this->periodQuery('sales_invoice_lines',$date,$start,$end,$companyId,$branchId);
                foreach($q->get([$category,$amount]) as $row){
                    $key=ucwords(strtolower(str_replace('_',' ',(string)($row->{$category}??'Other'))));
                    if(in_array(strtolower($key),['air ticket','air tickets','hotel','hotels'],true))continue;
                    $mix[$key]=($mix[$key]??0)+(float)($row->{$amount}??0);
                }
            }
        }
        $mix=array_filter($mix,fn($v)=>(float)$v>0);
        arsort($mix);
        if(!$mix)$mix=['Air Tickets'=>0,'Hotels'=>0,'Visa'=>0,'Transport'=>0,'Other'=>0];
        return ['labels'=>array_keys($mix),'values'=>array_values(array_map(fn($v)=>round((float)$v,2),$mix))];
    }

    private function bookingPassengerCount(int $bookingId): int
    {
        return $this->hasTable('booking_passengers') && $this->hasColumn('booking_passengers','booking_id')
            ? DB::table('booking_passengers')->where('booking_id',$bookingId)->count() : 0;
    }

    private function bookingRoute(int $bookingId): string
    {
        if(!$this->hasTable('booking_services') || !$this->hasTable('air_ticket_details'))return '';
        if(!$this->hasColumn('booking_services','booking_id') || !$this->hasColumn('air_ticket_details','booking_service_id'))return '';
        $row=DB::table('air_ticket_details as a')->join('booking_services as s','s.id','=','a.booking_service_id')->where('s.booking_id',$bookingId)->orderBy('a.id')->first(['a.origin','a.destination']);
        return $row ? trim((string)$row->origin).' → '.trim((string)$row->destination) : '';
    }

    private function bookingInvoiceTotal(int $bookingId): float
    {
        if(!$this->hasTable('sales_invoices') || !$this->hasColumn('sales_invoices','booking_id'))return 0.0;
        $amount=$this->column('sales_invoices',['grand_total','total_amount','invoice_total','net_total','total','amount']); if(!$amount)return 0.0;
        $q=DB::table('sales_invoices')->where('booking_id',$bookingId);$this->excludeStatuses($q,'sales_invoices',['CANCELLED','VOID']);return (float)$q->sum($amount);
    }

    private function missingTicketNumberCount(?int $companyId, ?int $branchId): int
    {
        if(!$this->hasTable('air_ticket_details') || !$this->hasColumn('air_ticket_details','ticket_status') || !$this->hasColumn('air_ticket_details','ticket_number'))return 0;
        return $this->scoped('air_ticket_details',$companyId,$branchId)->whereIn('ticket_status',['ISSUED','REISSUED','REFUNDED'])->where(function($q){$q->whereNull('ticket_number')->orWhere('ticket_number','');})->count();
    }

    private function partyName(int $partyId): string
    {
        if(!$this->hasTable('parties'))return 'Party #'.$partyId;
        $name=$this->column('parties',['display_name','name','legal_name']);
        if(!$name)return 'Party #'.$partyId;
        return (string)(DB::table('parties')->where('id',$partyId)->value($name) ?: ('Party #'.$partyId));
    }

    private function statusCounts(string $table,string $column,?int $companyId,?int $branchId): array
    {
        if(!$this->hasTable($table) || !$this->hasColumn($table,$column))return [];
        return $this->scoped($table,$companyId,$branchId)->select($column,DB::raw('COUNT(*) as c'))->groupBy($column)->pluck('c',$column)->mapWithKeys(fn($v,$k)=>[strtoupper((string)$k)=>(int)$v])->all();
    }

    private function countStatus(array $counts,array $statuses): int
    { $n=0;foreach($statuses as $s)$n+=(int)($counts[strtoupper($s)]??0);return $n; }

    private function branches(?int $companyId): Collection
    {
        if(!$this->hasTable('branches'))return collect();
        $q=DB::table('branches');
        if($companyId && $this->hasColumn('branches','company_id'))$q->where('company_id',$companyId);
        if($this->hasColumn('branches','is_active'))$q->where('is_active',true);
        $name=$this->column('branches',['name','branch_name','code']);
        $rows=$q->orderBy($name ?: 'id')->get(array_values(array_filter(['id',$name])));
        return $rows->map(function($row) use($name){
            $row->name=$name ? (string)($row->{$name} ?? ('Branch #'.$row->id)) : ('Branch #'.$row->id);
            return $row;
        });
    }

    private function resolveBranchId(Request $request, $user, Collection $branches, bool $canChoose): ?int
    {
        if($canChoose && $request->filled('branch_id')){
            $id=(int)$request->query('branch_id');
            if($branches->contains(fn($b)=>(int)$b->id===$id))return $id;
        }
        try{
            if($user && method_exists($user,'primaryBranch')){
                $branch=$user->primaryBranch(); if($branch)return (int)$branch->id;
            }
        }catch(Throwable){}
        return null;
    }

    private function resolvePeriod(Request $request, ?int $companyId): array
    {
        $key=(string)$request->query('period','month');
        $end=now()->copy()->endOfDay();
        $start=match($key){
            'today'=>now()->copy()->startOfDay(),
            '3m'=>now()->copy()->subMonths(2)->startOfMonth(),
            '6m'=>now()->copy()->subMonths(5)->startOfMonth(),
            '12m'=>now()->copy()->subMonths(11)->startOfMonth(),
            'fy'=>$this->fiscalStart($companyId) ?: now()->copy()->startOfYear(),
            'custom'=>$this->dateOr($request->query('start'),now()->copy()->startOfMonth())->startOfDay(),
            default=>now()->copy()->startOfMonth(),
        };
        if($key==='custom')$end=$this->dateOr($request->query('end'),now())->endOfDay();
        if($end->lt($start))[$start,$end]=[$end->copy()->startOfDay(),$start->copy()->endOfDay()];
        $label=match($key){'today'=>'Today','3m'=>'Last 3 Months','6m'=>'Last 6 Months','12m'=>'Last 12 Months','fy'=>'Financial Year','custom'=>$start->format('d M Y').' – '.$end->format('d M Y'),default=>'This Month'};
        return [$key,$label,$start,$end];
    }

    private function fiscalStart(?int $companyId): ?Carbon
    {
        if(!$this->hasTable('fiscal_years'))return null;
        $start=$this->column('fiscal_years',['start_date','starts_on']);$end=$this->column('fiscal_years',['end_date','ends_on']);if(!$start)return null;
        $q=DB::table('fiscal_years');if($companyId && $this->hasColumn('fiscal_years','company_id'))$q->where('company_id',$companyId);
        if($end)$q->whereDate($start,'<=',now()->toDateString())->whereDate($end,'>=',now()->toDateString());
        $value=$q->orderByDesc($start)->value($start);return $value?Carbon::parse($value)->startOfDay():null;
    }

    private function dateOr(mixed $value, Carbon $fallback): Carbon
    { try{return $value?Carbon::parse((string)$value):$fallback->copy();}catch(Throwable){return $fallback->copy();} }

    private function sumRange(string $table,string $amount,string $date,Carbon $start,Carbon $end,?int $companyId,?int $branchId,array $exclude=[]): float
    {
        $q=$this->periodQuery($table,$date,$start,$end,$companyId,$branchId);$this->excludeStatuses($q,$table,$exclude);return (float)$q->sum($amount);
    }

    private function sumCurrent(string $table,string $amount,?int $companyId,?int $branchId,array $exclude=[]): float
    { $q=$this->scoped($table,$companyId,$branchId);$this->excludeStatuses($q,$table,$exclude);return (float)$q->sum($amount); }

    private function periodQuery(string $table,string $date,Carbon $start,Carbon $end,?int $companyId,?int $branchId): Builder
    { return $this->scoped($table,$companyId,$branchId)->whereBetween($date,[$start,$end]); }

    private function scoped(string $table,?int $companyId,?int $branchId): Builder
    {
        $q=DB::table($table);
        if($companyId && $this->hasColumn($table,'company_id'))$q->where('company_id',$companyId);
        if($branchId && $this->hasColumn($table,'branch_id'))$q->where('branch_id',$branchId);
        return $q;
    }

    private function excludeStatuses(Builder $q,string $table,array $statuses): void
    {
        if(!$statuses)return;$col=$this->column($table,['status','invoice_status','journal_status']);if($col)$q->whereNotIn($col,$statuses);
    }

    private function firstId(string $table): ?int
    { return $this->hasTable($table) ? (int)(DB::table($table)->orderBy('id')->value('id') ?: 0) ?: null : null; }

    private function firstTable(array $tables): ?string
    { foreach($tables as $table)if($this->hasTable($table))return $table;return null; }

    private function hasTable(string $table): bool
    { try{return Schema::hasTable($table);}catch(Throwable){return false;} }

    private function hasColumn(string $table,string $column): bool
    { return in_array($column,$this->columns($table),true); }

    private function column(string $table,array $candidates): ?string
    { foreach($candidates as $column)if($this->hasColumn($table,$column))return $column;return null; }

    private function columns(string $table): array
    {
        if(isset($this->columnCache[$table]))return $this->columnCache[$table];
        try{return $this->columnCache[$table]=Schema::getColumnListing($table);}catch(Throwable){return $this->columnCache[$table]=[];}
    }
}
