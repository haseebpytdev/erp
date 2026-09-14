<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\NativeHotelMasterAuthority;
use App\Services\Administration\ErpPermissionMatrixService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class HotelMasterBulkImportController extends Controller
{
    public function __construct(private readonly NativeHotelMasterAuthority $authority, private readonly ErpPermissionMatrixService $permissions) {}
    public function template(): StreamedResponse { return response()->streamDownload(fn()=>print "City,Hotel Name\nMakkah,Example Hotel Makkah\nMadinah,Example Hotel Madinah\n", 'ET-Hotel-Master-Template.csv', ['Content-Type'=>'text/csv; charset=UTF-8']); }
    public function preview(Request $request) { $rows=$this->rows($request); return response()->json(['ok'=>true]+$this->authority->preview($rows)); }
    public function import(Request $request) { abort_unless($this->permissions->isSuperAdmin($request->user()) || $this->permissions->hasPermissionLike($request->user(), ['hotel master','travel master','master data write','administration']), 403); $rows=$this->rows($request); return response()->json(['ok'=>true]+$this->authority->import($rows)); }
    private function rows(Request $request): array { $csv=(string)($request->input('csv','')); if($csv===''&&$request->hasFile('file'))$csv=(string)file_get_contents($request->file('file')->getRealPath()); return $this->authority->parse($csv); }
}
