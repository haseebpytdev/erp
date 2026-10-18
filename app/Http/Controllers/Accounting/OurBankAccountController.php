<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\OurBankAccountService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;

final class OurBankAccountController extends Controller
{
    public function __construct(private readonly OurBankAccountService $accounts, private readonly NativeErpLayoutResolver $layout) {}
    public function index(Request $request) { return view('accounting.our-bank-accounts.index',['layoutMeta'=>$this->layout->resolve(),'rows'=>$this->accounts->rows($request->user())]); }
    public function create() { return view('accounting.our-bank-accounts.form',['layoutMeta'=>$this->layout->resolve(),'row'=>null,'ledgers'=>$this->accounts->ledgerOptions(),'companies'=>$this->accounts->companyOptions(),'branches'=>$this->accounts->branchOptions(request()->user())]); }
    public function store(Request $request) { try { $id=$this->accounts->create($request->all(),$request->user()); return redirect()->route('accounting.our-bank-accounts.index')->with('success','Our bank account created (#'.$id.').'); } catch (\Throwable $e) { return back()->withInput()->withErrors(['bank_account'=>$e->getMessage()]); } }
    public function edit(int $id) { $row=\DB::table('our_bank_accounts')->where('id',$id)->first(); abort_unless($row,404); return view('accounting.our-bank-accounts.form',['layoutMeta'=>$this->layout->resolve(),'row'=>$row,'ledgers'=>$this->accounts->ledgerOptions(),'companies'=>$this->accounts->companyOptions(),'branches'=>$this->accounts->branchOptions(request()->user())]); }
    public function update(Request $request,int $id) { try { $this->accounts->update($id,$request->all(),$request->user()); return redirect()->route('accounting.our-bank-accounts.index')->with('success','Our bank account updated.'); } catch (\Throwable $e) { return back()->withInput()->withErrors(['bank_account'=>$e->getMessage()]); } }
    public function deactivate(Request $request,int $id) { try { $this->accounts->deactivate($id,$request->user()); return back()->with('success','Our bank account deactivated.'); } catch (\Throwable $e) { return back()->withErrors(['bank_account'=>$e->getMessage()]); } }
}
