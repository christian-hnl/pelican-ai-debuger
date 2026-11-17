<?php

namespace ChristianHnl\AiDebugger\Support;

use ChristianHnl\AiDebugger\Enums\SessionStatus;
use ChristianHnl\AiDebugger\Filament\Admin\Pages\AiDebuggerSession as AdminSessionPage;
use ChristianHnl\AiDebugger\Filament\Server\Pages\AiDebuggerSession as ServerSessionPage;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\DebugSessionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Database notifications (the bell in the panel) for finished sessions and restores.
 */
class SessionNotifier
{
    public static function url(DebugSession $session): ?string
    {
        try {
            if (DebugSessionService::isAdmin($session->user)) {
                return AdminSessionPage::getUrl(['session' => $session->uuid], panel: 'admin');
            }

            return ServerSessionPage::getUrl(['session' => $session->uuid], panel: 'server', tenant: $session->server);
        } catch (Throwable) {
            return null;
        }
    }

    public static function finished(DebugSession $session): void
    {
        if (!$session->user) {
            return;
        }

        $notification = match ($session->status) {
            SessionStatus::Completed => Notification::make()
                ->success()
                ->title(trans('ai-debugger::strings.notifications.session_completed', ['server' => $session->server->name]))
                ->body(str((string) $session->summary)->limit(300)->toString()),
            SessionStatus::Cancelled => Notification::make()
                ->warning()
                ->title(trans('ai-debugger::strings.notifications.session_cancelled', ['server' => $session->server->name])),
            default => Notification::make()
                ->danger()
                ->title(trans('ai-debugger::strings.notifications.session_failed', ['server' => $session->server->name]))
                ->body(str((string) $session->error)->limit(300)->toString()),
        };

        self::send($session, $notification);
    }

    public static function restored(DebugSession $session, bool $success, ?string $error = null): void
    {
        if (!$session->user) {
            return;
        }

        $notification = $success
            ? Notification::make()->success()->title(trans('ai-debugger::strings.notifications.restore_done', ['server' => $session->server->name]))
            : Notification::make()->danger()->title(trans('ai-debugger::strings.notifications.restore_failed', ['server' => $session->server->name]))->body(str((string) $error)->limit(300)->toString());

        self::send($session, $notification);
    }

    protected static function send(DebugSession $session, Notification $notification): void
    {
        try {
            if ($url = self::url($session)) {
                $notification->actions([
                    Action::make('view')
                        ->label(trans('ai-debugger::strings.actions.view'))
                        ->url($url)
                        ->markAsRead(),
                ]);
            }

            $notification->sendToDatabase($session->user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
