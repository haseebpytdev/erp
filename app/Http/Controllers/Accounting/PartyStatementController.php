<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\PartyStatementService;
use App\Services\Operations\NativeErpLayoutResolver;
use App\Services\Organization\CompanyProfileSnapshotService;
use Illuminate\Http\Request;

final class PartyStatementController extends Controller
{
    public function __construct(
        private readonly PartyStatementService $statements,
        private readonly NativeErpLayoutResolver $layout,
        private readonly CompanyProfileSnapshotService $companyProfile,
    ) {}

    public function index(Request $request)
    {
        $filters = $this->statements->filters($request);
        $statement = $filters['partyId'] > 0 ? $this->statements->statement($filters) : null;
        $party = collect($filters['parties'])->first(fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) $filters['partyId']);
        return view('accounting.party-statement.index', compact('filters', 'statement', 'party') + ['layoutMeta' => $this->layout->resolve(), 'companyProfile' => $this->companyProfile->get()]);
    }

    public function print(Request $request)
    {
        $filters = $this->statements->filters($request);
        abort_if($filters['partyId'] <= 0, 422, 'Select a party before printing.');
        $party = collect($filters['parties'])->first(fn (array $row): bool => (int) ($row['id'] ?? 0) === (int) $filters['partyId']);
        return view('accounting.party-statement.print', ['statement' => $this->statements->statement($filters), 'party' => $party, 'companyProfile' => $this->companyProfile->get()]);
    }
}
