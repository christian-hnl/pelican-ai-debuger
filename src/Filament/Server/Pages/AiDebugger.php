<?php

namespace ChristianHnl\AiDebugger\Filament\Server\Pages;

use App\Models\Server;
use ChristianHnl\AiDebugger\Filament\Pages\BaseDebuggerPage;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\DebugSessionService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

class AiDebugger extends BaseDebuggerPage
{
    protected static ?int $navigationSort = 35;

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

    public static function getNavigationBadge(): ?string
    {
        $server = static::server();

        return $server && DebugSessionService::activeSession($server) ? '•' : null;
    }

    public function getSubheading(): ?string
    {
        $server = static::server();
        $remaining = $server && user() ? DebugSessionService::remainingToday(user(), $server) : null;

        return parent::getSubheading() . ($remaining !== null ? ' ' . trans('ai-debugger::strings.remaining_today', ['count' => $remaining]) : '');
    }

    protected function fixedServer(): ?Server
    {
        return static::server();
    }

    protected function sessionQuery(): Builder
    {
        return DebugSession::query()->where('server_id', static::server()?->id ?? 0);
    }

    protected function sessionUrl(DebugSession $session): string
    {
        return AiDebuggerSession::getUrl(['session' => $session->uuid]);
    }

    protected function canStart(?Server $server): bool
    {
        return $server !== null && DebugSessionService::canUseOnServer(user(), $server, 'create');
    }

    protected function allowed(string $ability, DebugSession $session): bool
    {
        return $session->server_id === static::server()?->id && DebugSessionService::canUseOnServer(user(), $session->server, $ability);
    }
}
