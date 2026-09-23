<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Administration\ErpUserManagementAuthority;
use App\Services\Administration\ErpUserManagementService;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ErpUserManagementController extends Controller
{
    public function __construct(
        private readonly ErpUserManagementAuthority $authority,
        private readonly ErpUserManagementService $users,
        private readonly ErpPermissionMatrixService $permissions,
        private readonly NativeErpLayoutResolver $layoutResolver,
    ) {}

    public function index(Request $request): View
    {
        $this->authority->authorize($request->user());

        $allUsers = $this->users->users();
        $selected = null;

        if ($request->filled('user')) {
            $selected = $this->users->user((int) $request->query('user'));
        } elseif ($request->filled('username')) {
            $selected = $this->users->findUserByUsername((string) $request->query('username'));
        } elseif ($request->filled('staff')) {
            $selected = $this->users->findUserByStaffCode((string) $request->query('staff'));
        }

        $layout = $this->layoutResolver->resolve();

        return view('administration.erp-user-management', [
            'users' => $allUsers,
            'selected' => $selected,
            'staffRows' => $this->users->staffRows(),
            'roles' => $this->users->roles(),
            'branches' => $this->users->branches(),
            'schema' => $this->users->schema(),
            'permissions' => $this->permissions->groupedPermissions(),
            'permissionStorageAvailable' => $this->users->directPermissionStorageAvailable(),
            'roleTemplates' => $this->roleTemplates(),
            'currentUserId' => (int) ($request->user()?->getAuthIdentifier() ?? 0),
            'erpLayout' => $layout['layout'],
            'erpContentSection' => $layout['content_section'],
            'erpTitleSection' => $layout['title_section'],
        ]);
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        $this->authority->authorize($request->user());

        try {
            $this->users->updateUser(
                $user,
                [
                    'username' => $request->input('username'),
                    'login_email' => $request->input('login_email'),
                    'staff_id' => $request->input('staff_id'),
                    'active' => $request->boolean('active'),
                    'access_scope' => $request->input('access_scope'),
                    'primary_branch_id' => $request->input('primary_branch_id'),
                    'role_ids' => $request->input('role_ids', []),
                    'branch_ids' => $request->input('branch_ids', []),
                    'permission_ids' => $request->input('permission_ids', []),
                    'password' => $request->input('password'),
                    'password_confirmation' => $request->input('password_confirmation'),
                ],
                (int) ($request->user()?->getAuthIdentifier() ?? 0)
            );
        } catch (Throwable $e) {
            return redirect()
                ->route('administration.erp-user-management.index', ['user' => $user])
                ->withInput()
                ->with('erp_user_error', $e->getMessage());
        }

        return redirect()
            ->route('administration.erp-user-management.index', ['user' => $user])
            ->with('erp_user_success', 'ERP user account updated.');
    }

    public function status(Request $request, int $user): RedirectResponse
    {
        $this->authority->authorize($request->user());

        $active = (string) $request->input('action') === 'activate';

        try {
            $this->users->setActive(
                $user,
                $active,
                (int) ($request->user()?->getAuthIdentifier() ?? 0)
            );
        } catch (Throwable $e) {
            return redirect()
                ->route('administration.erp-user-management.index', ['user' => $user])
                ->with('erp_user_error', $e->getMessage());
        }

        return redirect()
            ->route('administration.erp-user-management.index', ['user' => $user])
            ->with('erp_user_success', $active ? 'ERP user activated.' : 'ERP user deactivated.');
    }

    private function roleTemplates(): array
    {
        return [
            'Administrator' => ['include' => ['*'], 'exclude' => []],
            'Operations Staff' => ['include' => ['booking','passenger','travel master','party','product','supplier costing','vendor bill','refund','report'], 'exclude' => ['accounting','journal','ledger','role','permission','user administration']],
            'Ticketing Staff' => ['include' => ['booking','passenger','travel master','airline','airport'], 'exclude' => ['accounting','journal','chart of account','post','approve','role','permission']],
            'Ticketing Manager' => ['include' => ['booking','passenger','ticket','pnr','fare','approve','commercial','supplier','report'], 'exclude' => ['journal','chart of account','user administration']],
            'Cashier' => ['include' => ['receipt','payment','cash','bank'], 'exclude' => ['approve','post','reverse','journal','chart of account','user administration']],
            'Accountant' => ['include' => ['receipt','payment','expense voucher','contra','advance','journal','chart of account','account mapping','ledger','trial balance','financial report'], 'exclude' => ['user administration']],
            'Sales Executive' => ['include' => ['booking','party','customer','passenger','sales invoice'], 'exclude' => ['accounting','post','supplier','role','permission']],
            'Sales Manager' => ['include' => ['booking','party','customer','passenger','sales invoice','approve','margin','report'], 'exclude' => ['journal','post','role','permission']],
            'Umrah Staff' => ['include' => ['booking','group umrah','passenger','air','hotel','transport','visa','voucher','travel master','airline','airport'], 'exclude' => ['accounting','journal','administration']],
            'Umrah Manager' => ['include' => ['booking','group umrah','passenger','air','hotel','transport','visa','voucher','supplier costing','supplier','travel master','airline','airport','approve','report'], 'exclude' => ['sales invoice post','payment post','journal','chart of account','user management','role','permission','financial year']],
            'Visa Staff' => ['include' => ['booking','visa','passenger','saudi company','pakistani iata','visa master'], 'exclude' => ['accounting','journal','administration']],
            'Finance Manager' => ['include' => ['receipt','payment','expense voucher','contra','advance','journal','chart of account','account mapping','ledger','trial balance','financial report','management report','approve','post','reverse','margin'], 'exclude' => ['user administration']],
            'Auditor / Read Only' => ['include' => ['view','read','list','access','report','export','print'], 'exclude' => ['create','add','edit','update','delete','remove','approve','post','reverse','void','cancel','manage']],
            'Custom Access' => ['include' => [], 'exclude' => []],
        ];
    }
}
