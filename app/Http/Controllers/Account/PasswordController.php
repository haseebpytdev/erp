<?php
namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(): View { return view('account.password'); }
    public function update(Request $request): RedirectResponse
    {
        $data=$request->validate(['current_password'=>['required','string'],'password'=>['required','string','min:10','confirmed']]);
        if (! Hash::check($data['current_password'],$request->user()->password)) return back()->withErrors(['current_password'=>'Current password is incorrect.']);
        if (Hash::check($data['password'],$request->user()->password)) return back()->withErrors(['password'=>'Choose a different password.']);
        $request->user()->update(['password'=>$data['password'],'force_password_change'=>false]);
        AuditService::log($request,'user.password_changed',$request->user());
        return redirect()->route('dashboard')->with('status','Password changed successfully.');
    }
}
