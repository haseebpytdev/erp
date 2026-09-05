<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Administration\ErpUserManagementAuthority;
use App\Services\Administration\ErpUserManagementService;
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
}
