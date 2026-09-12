<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\DayOneSequenceResetService;
use App\Services\System\DayZeroDataResetService;
use App\Services\System\DayZeroExecutionService;
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
        DayZeroDataResetService $service,
        DayOneSequenceResetService $sequences
    ): View {
        $authority->authorize($request->user());

        if ($service->completedRecord()) {
            return view(
                'system.day-one-sequence-reset-v113247',
                [
                    'plan' => $sequences->plan(),
                ]
            );
        }

        $plan = $service->plan();
        $backupReady = $this->backupReady($request, $service);
        $executionReady = (
            DayZeroExecutionService::EXECUTION_ENABLED
            && $backupReady
            && ($plan['safety_preview_ready'] ?? false) === true
            && empty($plan['completed'])
        );

        return view(
            'system.day-zero-data-reset-v113246',
            [
                'plan' => $plan,
                'backupReady' => $backupReady,
                'backupFilename' => basename(
                    (string) $request->session()->get(
                        'day_zero_reset_backup_path',
                        ''
                    )
                ),
                'executionEnabled' => DayZeroExecutionService::EXECUTION_ENABLED,
                'executionReady' => $executionReady,
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
        DayZeroDataResetService $planner,
        DayZeroExecutionService $executor,
        DayOneSequenceResetService $sequences
    ): RedirectResponse {
        $authority->authorize($request->user());

        if ($planner->completedRecord()) {
            $request->validate(
                [
                    'confirmation' => [
                        'required',
                        'string',
                        'in:'.DayOneSequenceResetService::CONFIRMATION,
                    ],
                    'acknowledge' => ['accepted'],
                ],
                [
                    'confirmation.in' =>
                        'Type exactly: '.DayOneSequenceResetService::CONFIRMATION,
                    'acknowledge.accepted' =>
                        'You must confirm that no new production business data has been entered since Day-Zero.',
                ]
            );

            try {
                $result = $sequences->execute($request->user());
            } catch (Throwable $e) {
                return redirect()
                    ->route('system.production-data-reset.index')
                    ->withInput($request->only('confirmation'))
                    ->with('reset_error', $e->getMessage());
            }

            return redirect()
                ->route('system.production-data-reset.index')
                ->with(
                    'reset_success',
                    sprintf(
                        'Day-One numbering finalized. %d empty table identities reset; %d counter rows normalized. New document numbering can start from 1000.',
                        (int) ($result['identity_targets_reset'] ?? 0),
                        (int) ($result['counter_rows_updated'] ?? 0)
                    )
                );
        }

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
            $planner->validateBackup($backupPath, $backupAt);
            $result = $executor->execute(
                $request->user(),
                $backupPath,
                $backupAt
            );
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
                    'Day-Zero reset completed permanently. %d rows cleared across %d tables; %d preserved FK rows neutralized; %d counter rows reset.',
                    (int) ($result['rows_deleted'] ?? 0),
                    (int) ($result['tables_cleared'] ?? 0),
                    (int) ($result['preserved_fk_rows_neutralized'] ?? 0),
                    (int) ($result['counter_rows_updated'] ?? 0)
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
