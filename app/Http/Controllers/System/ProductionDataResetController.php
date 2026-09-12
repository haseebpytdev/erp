<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\DayZeroDataResetService;
use App\Services\System\ProductionDataResetAuthority;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ProductionDataResetController extends Controller
{
    public function index(
        Request $request,
        ProductionDataResetAuthority $authority,
        DayZeroDataResetService $service
    ): View {
        $authority->authorize($request->user());

        return view(
            'system.day-zero-data-reset-v113242',
            [
                'plan' => $service->plan(),
                'backupReady' => $this->backupReady($request, $service),
                'backupFilename' => basename(
                    (string) $request->session()->get(
                        'day_zero_reset_backup_path',
                        ''
                    )
                ),
            ]
        );
    }

    public function backup(
        Request $request,
        ProductionDataResetAuthority $authority,
        DayZeroDataResetService $service
    ): BinaryFileResponse {
        $authority->authorize($request->user());

        try {
            $backup = $service->createBackup($request->user());
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to prepare Day-Zero backup: '.$e->getMessage(),
                previous: $e
            );
        }

        $request->session()->put(
            'day_zero_reset_backup_path',
            $backup['path']
        );
        $request->session()->put(
            'day_zero_reset_backup_at',
            $backup['created_at']
        );
        $request->session()->put(
            'day_zero_reset_backup_filename',
            $backup['filename']
        );

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
        DayZeroDataResetService $service
    ): RedirectResponse {
        $authority->authorize($request->user());

        $request->validate(
            [
                'confirmation' => [
                    'required',
                    'string',
                    'in:'.DayZeroDataResetService::CONFIRMATION,
                ],
                'acknowledge' => ['accepted'],
            ],
            [
                'confirmation.in' =>
                    'Type exactly: '.DayZeroDataResetService::CONFIRMATION,
                'acknowledge.accepted' =>
                    'You must acknowledge that Day-Zero reset is permanent.',
            ]
        );

        $backupPath = (string) $request->session()->get(
            'day_zero_reset_backup_path',
            ''
        );
        $backupAt = $request->session()->get(
            'day_zero_reset_backup_at'
        );

        try {
            $service->validateBackup($backupPath, $backupAt);
            $result = $service->execute($request->user(), $backupPath);
        } catch (Throwable $e) {
            return redirect()
                ->route('system.production-data-reset.index')
                ->withInput($request->only('confirmation'))
                ->with('reset_error', $e->getMessage());
        }

        $request->session()->forget([
            'day_zero_reset_backup_path',
            'day_zero_reset_backup_at',
            'day_zero_reset_backup_filename',
        ]);

        return redirect()
            ->route('system.production-data-reset.index')
            ->with(
                'reset_success',
                sprintf(
                    'Day-Zero reset completed. %d rows cleared across %d tables.',
                    (int) ($result['rows_deleted'] ?? 0),
                    (int) ($result['tables_cleared'] ?? 0)
                )
            );
    }

    private function backupReady(
        Request $request,
        DayZeroDataResetService $service
    ): bool {
        $path = (string) $request->session()->get(
            'day_zero_reset_backup_path',
            ''
        );
        $at = $request->session()->get(
            'day_zero_reset_backup_at'
        );

        try {
            $service->validateBackup($path, $at);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
