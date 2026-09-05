<?php

namespace App\Http\Controllers\Purchase;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Purchase\SupplierCostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SupplierCostingController extends Controller
{
    public function __construct(private SupplierCostingService $service, private NativeErpLayoutResolver $layout) {}

    public function index(Request $request)
    {
        $q=DB::table('supplier_costings')->orderByDesc('id');
        if ($request->filled('status')) $q->where('status',$request->string('status'));
        if ($request->filled('q')) {
            $term='%'.$request->string('q').'%';
            $q->where(fn($x)=>$x->where('costing_no','like',$term)->orWhere('supplier_name','like',$term)->orWhere('supplier_invoice_no','like',$term)->orWhere('supplier_reference','like',$term));
        }
        return view('purchase.supplier-costing.index', ['rows'=>$q->paginate(25)->withQueryString(),'layoutMeta'=>$this->layout->resolve()]);
    }

    public function create()
    {
        return view('purchase.supplier-costing.form', $this->formData(null));
    }

    public function store(Request $request)
    {
        $data=$this->validateHeader($request);
        $lines=$this->validateLines($request);
        $id=DB::transaction(function() use($data,$lines,$request){
            $now=now();
            $id=DB::table('supplier_costings')->insertGetId(array_merge($data,[
                'costing_no'=>$this->service->nextNumber(),'status'=>'draft','created_by'=>$request->user()?->id,'created_at'=>$now,'updated_at'=>$now,
            ]));
            $this->replaceLines($id,$lines);
            $this->service->recalculate($id);
            $this->service->activity($id,'create',null,'draft',$request->user(),'Supplier costing document created.');
            return $id;
        });
        return redirect()->route('purchase.supplier-costing.show',$id)->with('success','Supplier costing draft created.');
    }

    public function show(int $costing)
    {
        $row=$this->find($costing);
        return view('purchase.supplier-costing.show', array_merge($this->formData($row),[
            'lines'=>DB::table('supplier_costing_lines')->where('supplier_costing_id',$costing)->orderBy('line_no')->get(),
            'postings'=>DB::table('supplier_costing_posting_lines')->where('supplier_costing_id',$costing)->orderBy('id')->get(),
            'activities'=>DB::table('supplier_costing_activities')->where('supplier_costing_id',$costing)->orderByDesc('id')->get(),
            'canApprove'=>$this->service->canApprove(request()->user()),
        ]));
    }

    public function edit(int $costing)
    {
        $row=$this->find($costing);
        abort_unless($row->status==='draft',409,'Only Draft supplier costing can be edited.');
        return view('purchase.supplier-costing.form', array_merge($this->formData($row),[
            'lines'=>DB::table('supplier_costing_lines')->where('supplier_costing_id',$costing)->orderBy('line_no')->get(),
        ]));
    }

    public function update(Request $request,int $costing)
    {
        $row=$this->find($costing);
        abort_unless($row->status==='draft',409,'Only Draft supplier costing can be edited.');
        $data=$this->validateHeader($request); $lines=$this->validateLines($request);
        DB::transaction(function() use($costing,$data,$lines,$request){
            DB::table('supplier_costings')->where('id',$costing)->update(array_merge($data,['updated_at'=>now()]));
            $this->replaceLines($costing,$lines); $this->service->recalculate($costing);
            $this->service->activity($costing,'update','draft','draft',$request->user(),'Draft updated.');
        });
        return redirect()->route('purchase.supplier-costing.show',$costing)->with('success','Supplier costing draft saved.');
    }

    public function workflow(Request $request,int $costing,string $action)
    {
        abort_unless(in_array($action,['submit','approve','post'],true),404);
        try { $this->service->transition($costing,$action,$request->user()); }
        catch (\Throwable $e) { return back()->withErrors(['workflow'=>$e->getMessage()]); }
        return back()->with('success','Supplier costing workflow updated.');
    }

    private function validateHeader(Request $r): array
    {
        $d=$r->validate([
            'booking_id'=>['nullable','integer','min:1'],'supplier_id'=>['nullable','integer','min:1'],'supplier_name'=>['nullable','string','max:255'],
            'service_type'=>['required','string','max:60'],'cost_date'=>['required','date'],'due_date'=>['nullable','date','after_or_equal:cost_date'],
            'supplier_invoice_no'=>['nullable','string','max:120'],'supplier_reference'=>['nullable','string','max:190'],'currency_code'=>['required','string','max:10'],
            'exchange_rate'=>['required','numeric','gt:0'],'payment_terms'=>['nullable','string','max:80'],'remarks'=>['nullable','string','max:5000'],
        ]);
        if (!empty($d['supplier_id']) && empty($d['supplier_name'])) {
            foreach($this->service->supplierOptions() as $s) if((int)$s['id']===(int)$d['supplier_id']) {$d['supplier_name']=$s['name']; break;}
        }
        return $d;
    }

    private function validateLines(Request $r): array
    {
        $d=$r->validate([
            'lines'=>['required','array','min:1'],'lines.*.service_type'=>['required','string','max:60'],'lines.*.description'=>['required','string','max:255'],
            'lines.*.passenger_id'=>['nullable','integer','min:1'],'lines.*.passenger_name'=>['nullable','string','max:255'],'lines.*.supplier_service_ref'=>['nullable','string','max:190'],
            'lines.*.base_cost'=>['required','numeric','min:0'],'lines.*.tax_amount'=>['nullable','numeric','min:0'],'lines.*.other_charges'=>['nullable','numeric','min:0'],
        ]);
        return $d['lines'];
    }

    private function replaceLines(int $id,array $lines): void
    {
        DB::table('supplier_costing_lines')->where('supplier_costing_id',$id)->delete(); $now=now(); $insert=[];
        foreach(array_values($lines) as $i=>$l) {
            $base=round((float)$l['base_cost'],2); $tax=round((float)($l['tax_amount']??0),2); $other=round((float)($l['other_charges']??0),2);
            $insert[]=['supplier_costing_id'=>$id,'line_no'=>$i+1,'service_type'=>$l['service_type'],'description'=>$l['description'],'passenger_id'=>$l['passenger_id']??null,'passenger_name'=>$l['passenger_name']??null,'supplier_service_ref'=>$l['supplier_service_ref']??null,'base_cost'=>$base,'tax_amount'=>$tax,'other_charges'=>$other,'total_cost'=>$base+$tax+$other,'created_at'=>$now,'updated_at'=>$now];
        }
        if($insert) DB::table('supplier_costing_lines')->insert($insert);
    }

    private function find(int $id): object { $r=DB::table('supplier_costings')->where('id',$id)->first(); abort_unless($r,404); return $r; }
    private function formData(?object $row): array { return ['row'=>$row,'suppliers'=>$this->service->supplierOptions(),'bookings'=>$this->service->bookingOptions(),'layoutMeta'=>$this->layout->resolve()]; }
}
