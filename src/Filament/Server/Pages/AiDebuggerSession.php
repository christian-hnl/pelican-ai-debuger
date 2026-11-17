<?php

namespace ChristianHnl\AiDebugger\Filament\Server\Pages;

use App\Models\Server;
use ChristianHnl\AiDebugger\Filament\Pages\BaseSessionPage;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\DebugSessionService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

class AiDebuggerSession extends BaseSessionPage
{
    protected static function server(): ?Server
    {
        $server = Filament::getTenant();

        return $server instanceof Server ? $server : null;
    }

    public static function canAccess(): bool
    {
        $server = static::server();

        return $server !== null && DebugSessionService::canUseOnServer(user(), $server, 'view');
    }

    protected function sessionQuery(): Builder
    {
        return DebugSession::query()->where('server_id', static::server()?->id ?? 0);
    }

    protected function listUrl(): string
    {
        return AiDebugger::getUrl();
    }

    protected function allowed(string $ability, DebugSession $session): bool
    {
        return $session->server_id === static::server()?->id && DebugSessionService::canUseOnServer(user(), $session->server, $ability);
    }
}
