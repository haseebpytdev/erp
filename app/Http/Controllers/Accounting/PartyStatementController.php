<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\PartyStatementService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;

final class PartyStatementController extends Controller
{
    public function __construct(
        private readonly PartyStatementService $statements,
        private readonly NativeErpLayoutResolver $layout,
    ) {}

    public function index(Request $request)
    {
        $filters = $this->statements->filters($request);
        $statement = $filters['partyId'] > 0 ? $this->statements->statement($filters) : null;
        return view('accounting.party-statement.index', compact('filters', 'statement') + ['layoutMeta' => $this->layout->resolve()]);
    }

    public function print(Request $request)
    {
        $filters = $this->statements->filters($request);
        abort_if($filters['partyId'] <= 0, 422, 'Select a party before printing.');
        return view('accounting.party-statement.print', ['statement' => $this->statements->statement($filters)]);
    }
}
