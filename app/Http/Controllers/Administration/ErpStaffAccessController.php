<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Administration\ErpStaffAccessService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ErpStaffAccessController extends Controller
{
    public function __construct(
        private readonly ErpStaffAccessService $access,
        private readonly NativeErpLayoutResolver $layoutResolver,
    ) {}

    public function index(Request $request): View
    {
        $layout = $this->layoutResolver->resolve();
        $data = $this->access->indexData($request->user(), $request->query());
        $selected = $request->filled('user') ? $this->access->read($request->user(), (int) $request->query('user')) : null;
        return view('administration.staff-access.index', $data + [
            'selected' => $selected,
            'erpLayout' => $layout['layout'],
            'erpContentSection' => $layout['content_section'],
            'erpTitleSection' => $layout['title_section'],
        ]);
    }

    public function show(Request $request, int $user): View
    {
        $layout = $this->layoutResolver->resolve();
        return view('administration.staff-access.index', $this->access->indexData($request->user(), $request->query()) + [
            'selected' => $this->access->read($request->user(), $user),
            'erpLayout' => $layout['layout'],
            'erpContentSection' => $layout['content_section'],
            'erpTitleSection' => $layout['title_section'],
        ]);
    }

    public function update(Request $request, int $user): RedirectResponse
    {
        try {
            $this->access->save($request->user(), $user, $request->all());
            return redirect()->route('administration.staff-access.show', ['user' => $user])->with('staff_access_success', 'Access updated.');
        } catch (Throwable $e) {
            return redirect()->route('administration.staff-access.show', ['user' => $user])->withInput()->with('staff_access_error', $e->getMessage());
        }
    }
}
