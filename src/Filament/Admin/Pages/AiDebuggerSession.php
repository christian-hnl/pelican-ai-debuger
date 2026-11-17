<?php

namespace ChristianHnl\AiDebugger\Filament\Admin\Pages;

use ChristianHnl\AiDebugger\Filament\Pages\BaseSessionPage;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\DebugSessionService;
use Illuminate\Database\Eloquent\Builder;

class AiDebuggerSession extends BaseSessionPage
{
    public static function canAccess(): bool
    {
        return DebugSessionService::isAdmin(user(), 'view');
    }

    protected function sessionQuery(): Builder
    {
        return DebugSession::query();
    }

    protected function listUrl(): string
    {
        return AiDebugger::getUrl();
    }

    protected function allowed(string $ability, DebugSession $session): bool
    {
        return DebugSessionService::isAdmin(user(), $ability);
    }
}
