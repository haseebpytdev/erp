<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeHotelMasterAuthority;
use App\Services\Administration\ErpPermissionMatrixService;
use App\Services\Administration\ErpUserManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HotelMasterBulkImportController extends Controller
{
    public function __construct(private readonly NativeHotelMasterAuthority $authority, private readonly ErpPermissionMatrixService $permissions, private readonly ErpUserManagementService $users) {}
    public function template(): StreamedResponse { return response()->streamDownload(fn()=>print "City,Hotel Name\nMakkah,Example Hotel Makkah\nMadinah,Example Hotel Madinah\n", 'ET-Hotel-Master-Template.csv', ['Content-Type'=>'text/csv; charset=UTF-8']); }
    public function preview(Request $request) { $rows=$this->rows($request); return response()->json(['ok'=>true]+$this->authority->preview($rows, $this->companyId($request))); }
    public function import(Request $request) { abort_unless($this->permissions->isSuperAdmin($request->user()) || $this->permissions->hasPermissionLike($request->user(), ['hotel master','travel master','master data write','administration']), 403); $rows=$this->rows($request); return response()->json(['ok'=>true]+$this->authority->import($rows, $this->companyId($request))); }
    private function rows(Request $request): array { $csv=(string)($request->input('csv','')); if($csv===''&&$request->hasFile('file'))$csv=(string)file_get_contents($request->file('file')->getRealPath()); return $this->authority->parse($csv); }
    private function companyId(Request $request): ?int
    {
        $user = $request->user();
        foreach (['company_id', 'current_company_id', 'active_company_id'] as $field) {
            $id = (int) ($user?->getAttribute($field) ?? 0);
            if ($id > 0) return $id;
        }
        $userId = (int) ($user?->getAuthIdentifier() ?? 0);
        if ($userId <= 0) return null;

        $snapshot = $this->users->user($userId);
        $schema = $this->users->schema();
        if (! $snapshot || ! $schema['branches_table'] || ! $schema['branch_id_column']) return null;
        $branchColumns = Schema::getColumnListing($schema['branches_table']);
        if (! in_array('company_id', $branchColumns, true)) return null;

        $primaryBranchId = (int) ($snapshot['primary_branch_id'] ?? 0);
        if ($primaryBranchId > 0) {
            $companyId = $this->companyIdFromBranch($schema['branches_table'], $schema['branch_id_column'], $primaryBranchId);
            if ($companyId > 0) return $companyId;
        }

        $branchIds = array_values(array_unique(array_filter(array_map('intval', (array) ($snapshot['branch_ids'] ?? [])), fn (int $id): bool => $id > 0)));
        if (! $branchIds) return null;
        $companyIds = DB::table($schema['branches_table'])
            ->whereIn($schema['branch_id_column'], $branchIds)
            ->pluck('company_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        return count($companyIds) === 1 ? (int) $companyIds[0] : null;
    }

    private function companyIdFromBranch(string $table, string $idColumn, int $branchId): int
    {
        return (int) (DB::table($table)->where($idColumn, $branchId)->value('company_id') ?? 0);
    }
}
