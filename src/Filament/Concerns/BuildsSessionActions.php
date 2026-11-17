<?php

namespace ChristianHnl\AiDebugger\Filament\Concerns;

use ChristianHnl\AiDebugger\Enums\BackupType;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\BackupService;
use ChristianHnl\AiDebugger\Services\DebugSessionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Actions on a session, shared by the session table and the session page.
 */
trait BuildsSessionActions
{
    /**
     * Whether the current user may do $ability (view, create, restore, delete) on the session.
     */
    abstract protected function allowed(string $ability, DebugSession $session): bool;

    protected function notifyError(Throwable $exception): void
    {
        Notification::make()
            ->danger()
            ->title(trans('ai-debugger::strings.notifications.error'))
            ->body($exception->getMessage())
            ->send();
    }

    protected function cancelSessionAction(): Action
    {
        return Action::make('cancel_session')
            ->label(trans('ai-debugger::strings.actions.cancel'))
            ->icon('tabler-player-stop')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(trans('ai-debugger::strings.actions.cancel_description'))
            ->visible(fn (DebugSession $record) => $record->status->isActive() && $this->allowed('create', $record))
            ->action(function (DebugSession $record) {
                app(DebugSessionService::class)->cancel($record);

                Notification::make()->warning()->title(trans('ai-debugger::strings.notifications.cancel_requested'))->send();
            });
    }

    protected function restoreBackupAction(): Action
    {
        return Action::make('restore_backup')
            ->label(trans('ai-debugger::strings.actions.restore'))
            ->icon('tabler-restore')
            ->color('danger')
            ->requiresConfirmation()
            ->modalIcon('tabler-restore')
            ->modalHeading(trans('ai-debugger::strings.actions.restore_heading'))
            ->modalDescription(fn (DebugSession $record) => trans('ai-debugger::strings.actions.restore_description', [
                'date' => $record->created_at?->format('Y-m-d H:i'),
                'type' => $record->backup_type?->getLabel() ?? '-',
                'size' => BackupService::formatBytes($record->backup_size),
            ]))
            ->modalSubmitActionLabel(trans('ai-debugger::strings.actions.restore'))
            ->visible(fn (DebugSession $record) => !$record->status->isActive() && $record->backup_status->canRestore() && $this->allowed('restore', $record))
            ->action(function (DebugSession $record) {
                try {
                    app(DebugSessionService::class)->restore($record);

                    Notification::make()->success()->title(trans('ai-debugger::strings.notifications.restore_started'))->send();
                } catch (Throwable $exception) {
                    $this->notifyError($exception);
                }
            });
    }

    protected function deleteBackupAction(): Action
    {
        return Action::make('delete_backup')
            ->label(trans('ai-debugger::strings.actions.delete_backup'))
            ->icon('tabler-database-x')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (DebugSession $record) => trans($record->backup_type === BackupType::Panel ? 'ai-debugger::strings.actions.delete_backup_panel' : 'ai-debugger::strings.actions.delete_backup_archive'))
            ->visible(fn (DebugSession $record) => !$record->status->isActive() && $record->backup_status->canDelete() && $this->allowed('delete', $record))
            ->action(function (DebugSession $record) {
                try {
                    app(DebugSessionService::class)->deleteBackup($record);

                    Notification::make()->success()->title(trans('ai-debugger::strings.notifications.backup_deleted'))->send();
                } catch (Throwable $exception) {
                    $this->notifyError($exception);
                }
            });
    }

    protected function deleteSessionAction(?string $redirect = null): Action
    {
        return Action::make('delete_session')
            ->label(trans('ai-debugger::strings.actions.delete'))
            ->icon('tabler-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(trans('ai-debugger::strings.actions.delete_description'))
            ->visible(fn (DebugSession $record) => !$record->status->isActive() && $this->allowed('delete', $record))
            ->action(function (DebugSession $record) use ($redirect) {
                try {
                    app(DebugSessionService::class)->delete($record);

                    Notification::make()->success()->title(trans('ai-debugger::strings.notifications.session_deleted'))->send();

                    if ($redirect) {
                        $this->redirect($redirect, navigate: true);
                    }
                } catch (Throwable $exception) {
                    $this->notifyError($exception);
                }
            });
    }
}
