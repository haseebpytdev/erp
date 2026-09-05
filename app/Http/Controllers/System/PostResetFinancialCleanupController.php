<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\PostResetFinancialCleanupService;
use App\Services\System\ProductionDataResetAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PostResetFinancialCleanupController extends Controller
{
    public function index(
        Request $request,
        ProductionDataResetAuthority $authority,
        PostResetFinancialCleanupService $service
    ): View {
        $authority->authorize($request->user());

        return view('system.post-reset-financial-cleanup-v103179', [
            'plan' => $service->plan(),
            'backupReady' => $this->backupReady($request),
            'backupFilename' => basename((string) $request->session()->get('financial_cleanup_backup_path', '')),
        ]);
    }

    public function backup(
        Request $request,
        ProductionDataResetAuthority $authority,
        PostResetFinancialCleanupService $service
    ): BinaryFileResponse {
        $authority->authorize($request->user());

        $backup = $service->createBackup($request->user());

        $request->session()->put('financial_cleanup_backup_path', $backup['path']);
        $request->session()->put('financial_cleanup_backup_at', $backup['created_at']);
        $request->session()->put('financial_cleanup_backup_filename', $backup['filename']);

        return response()->download(
            $backup['path'],
            $backup['filename'],
            [
                'Content-Type' => 'application/gzip',
                'Cache-Control' => 'no-store, private',
            ]
        );
    }

    public function execute(
        Request $request,
        ProductionDataResetAuthority $authority,
        PostResetFinancialCleanupService $service
    ): RedirectResponse {
        $authority->authorize($request->user());

        $request->validate([
            'confirmation' => [
                'required',
                'string',
                'in:'.PostResetFinancialCleanupService::CONFIRMATION,
            ],
            'acknowledge' => ['accepted'],
        ], [
            'confirmation.in' => 'Type exactly: '.PostResetFinancialCleanupService::CONFIRMATION,
            'acknowledge.accepted' => 'Confirm that you understand residual financial state will be removed.',
        ]);

        $backupPath = (string) $request->session()->get('financial_cleanup_backup_path', '');
        $backupAt = $request->session()->get('financial_cleanup_backup_at');

        try {
            $service->validateBackup($backupPath, $backupAt);
            $result = $service->execute($request->user(), $backupPath);
        } catch (Throwable $e) {
            return redirect()
                ->route('system.post-reset-financial-cleanup.index')
                ->withInput($request->only('confirmation'))
                ->with('cleanup_error', $e->getMessage());
        }

        $request->session()->forget([
            'financial_cleanup_backup_path',
            'financial_cleanup_backup_at',
            'financial_cleanup_backup_filename',
        ]);

        return redirect()
            ->route('system.post-reset-financial-cleanup.index')
            ->with(
                'cleanup_success',
                sprintf(
                    'Financial residue cleanup completed. %d rows deleted and %d rows had balance/cost fields reset to zero.',
                    (int) ($result['rows_deleted'] ?? 0),
                    (int) ($result['rows_zeroed'] ?? 0)
                )
            );
    }

    private function backupReady(Request $request): bool
    {
        $path = (string) $request->session()->get('financial_cleanup_backup_path', '');
        $at = (int) $request->session()->get('financial_cleanup_backup_at', 0);

        return $path !== ''
            && is_file($path)
            && filesize($path) > 0
            && $at > 0
            && (time() - $at) <= 7200;
    }
}
