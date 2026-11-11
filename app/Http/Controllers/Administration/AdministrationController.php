<?php
namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\ApprovalPolicy;
use App\Models\Department;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\View\View;

class AdministrationController extends Controller
{
    public function index(): View
    {
        return view('administration.index', [
            'staffCount'=>Staff::query()->where('is_active',true)->count(),
            'userCount'=>User::query()->where('is_active',true)->count(),
            'roleCount'=>Role::query()->where('is_active',true)->count(),
            'departmentCount'=>Department::query()->where('is_active',true)->count(),
            'policyCount'=>ApprovalPolicy::query()->where('is_active',true)->count(),
        ]);
    }
}
