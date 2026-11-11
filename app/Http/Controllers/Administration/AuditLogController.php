<?php
namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $q=trim((string)$request->query('q'));
        $audit=DB::table('audit_logs')->leftJoin('users','users.id','=','audit_logs.user_id')->select('audit_logs.*','users.name as user_name')
            ->when($q,fn($builder)=>$builder->where(function($x)use($q){$x->where('audit_logs.event','like',"%$q%")->orWhere('users.name','like',"%$q%");}))
            ->orderByDesc('audit_logs.id')->limit(200)->get();
        $logins=DB::table('login_events')->leftJoin('users','users.id','=','login_events.user_id')->select('login_events.*','users.name as user_name')->orderByDesc('login_events.id')->limit(100)->get();
        return view('administration.audit',['audit'=>$audit,'logins'=>$logins,'q'=>$q]);
    }
}
