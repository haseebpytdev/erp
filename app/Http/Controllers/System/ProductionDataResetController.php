<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\ProductionDataResetAuthority;
use App\Services\System\ProductionDataResetService;
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
        ProductionDataResetService $service
    ): View {
        $authority->authorize(
            $request->user()
        );

        return view(
            'system.production-data-reset-v103172',
            [
                'plan' => $service->plan(),
                'backupReady' => $this->backupReady(
                    $request
                ),
                'backupFilename' => basename(
                    (string) $request->session()->get(
                        'production_reset_backup_path',
                        ''
                    )
                ),
            ]
        );
    }

    public function backup(
        Request $request,
        ProductionDataResetAuthority $authority,
        ProductionDataResetService $service
    ): BinaryFileResponse {
        $authority->authorize(
            $request->user()
        );

        try {
            $backup = $service->createBackup(
                $request->user()
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to prepare reset backup: '
                .$e->getMessage(),
                previous: $e
            );
        }

        $request->session()->put(
            'production_reset_backup_path',
            $backup['path']
        );

        $request->session()->put(
            'production_reset_backup_at',
            $backup['created_at']
        );

        $request->session()->put(
            'production_reset_backup_filename',
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
        ProductionDataResetService $service
    ): RedirectResponse {
        $authority->authorize(
            $request->user()
        );

        $validated = $request->validate(
            [
                'confirmation' => [
                    'required',
                    'string',
                    'in:'.ProductionDataResetService::CONFIRMATION,
                ],
                'acknowledge' => [
                    'accepted',
                ],
            ],
            [
                'confirmation.in' =>
                    'Type exactly: '
                    .ProductionDataResetService::CONFIRMATION,
                'acknowledge.accepted' =>
                    'You must confirm that you understand transactional data will be deleted.',
            ]
        );

        $backupPath = (string) $request->session()->get(
            'production_reset_backup_path',
            ''
        );

        $backupAt = $request->session()->get(
            'production_reset_backup_at'
        );

        try {
            $service->validateBackup(
                $backupPath,
                $backupAt
            );

            $result = $service->execute(
                $request->user(),
                $backupPath
            );
        } catch (Throwable $e) {
            return redirect()
                ->route(
                    'system.production-data-reset.index'
                )
                ->withInput(
                    $request->only(
                        'confirmation'
                    )
                )
                ->with(
                    'reset_error',
                    $e->getMessage()
                );
        }

        $request->session()->forget([
            'production_reset_backup_path',
            'production_reset_backup_at',
            'production_reset_backup_filename',
        ]);

        return redirect()
            ->route(
                'system.production-data-reset.index'
            )
            ->with(
                'reset_success',
                sprintf(
                    'Production transactions reset completed. %d rows cleared across %d tables.',
                    (int) ($result['rows_deleted'] ?? 0),
                    (int) ($result['tables_cleared'] ?? 0)
                )
            );
    }

    private function backupReady(Request $request): bool
    {
        $path = (string) $request->session()->get(
            'production_reset_backup_path',
            ''
        );

        $at = (int) $request->session()->get(
            'production_reset_backup_at',
            0
        );

        return (
            $path !== ''
            && is_file($path)
            && filesize($path) > 0
            && $at > 0
            && (time() - $at) <= 7200
        );
    }
}
