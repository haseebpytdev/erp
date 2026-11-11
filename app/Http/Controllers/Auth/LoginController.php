<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View { return view('auth.login'); }

    public function store(Request $request): RedirectResponse
    {
        $credentials=$request->validate(['email'=>['required','email'],'password'=>['required','string']]);
        $candidate=User::query()->where('email',strtolower($credentials['email']))->first();
        $erp02Columns=Schema::hasColumn('users','failed_login_count');

        if (! Auth::attempt($credentials,$request->boolean('remember'))) {
            if ($candidate && $erp02Columns) $candidate->update(['failed_login_count'=>$candidate->failed_login_count+1,'last_failed_login_at'=>now()]);
            $this->loginEvent($request,$candidate,false,$credentials['email']);
            return back()->withErrors(['email'=>'The email or password is incorrect.'])->onlyInput('email');
        }

        if (! $request->user()->is_active) {
            $this->loginEvent($request,$request->user(),false,$request->user()->email);
            Auth::logout();
            return back()->withErrors(['email'=>'This account has been disabled.']);
        }

        $request->session()->regenerate();
        $updates=['last_login_at'=>now()];
        if ($erp02Columns) $updates+=['last_login_ip'=>$request->ip(),'failed_login_count'=>0];
        $request->user()->forceFill($updates)->save();
        $this->loginEvent($request,$request->user(),true,$request->user()->email);
        AuditService::log($request,'auth.login',$request->user(),[],['ip'=>$request->ip()]);
        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($request->user()) AuditService::log($request,'auth.logout',$request->user());
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    private function loginEvent(Request $request, ?User $user, bool $success, string $email): void
    {
        if (! Schema::hasTable('login_events')) return;
        DB::table('login_events')->insert(['user_id'=>$user?->id,'email'=>strtolower($email),'was_successful'=>$success,'ip_address'=>$request->ip(),'user_agent'=>mb_substr((string)$request->userAgent(),0,1000),'occurred_at'=>now()]);
    }
}
