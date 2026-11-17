<?php

namespace ChristianHnl\AiDebugger\Filament\Admin\Pages;

use App\Models\Server;
use ChristianHnl\AiDebugger\Filament\Pages\BaseDebuggerPage;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\DebugSessionService;
use Illuminate\Database\Eloquent\Builder;

class AiDebugger extends BaseDebuggerPage
{
    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        return DebugSessionService::isAdmin(user(), 'view');
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('ai-debugger::strings.navigation_group');
    }

    public static function getNavigationBadge(): ?string
    {
        $active = DebugSession::query()->active()->count();

        return $active > 0 ? (string) $active : null;
    }

    protected function fixedServer(): ?Server
    {
        return null;
    }

    protected function sessionQuery(): Builder
    {
        return DebugSession::query();
    }

    protected function sessionUrl(DebugSession $session): string
    {
        return AiDebuggerSession::getUrl(['session' => $session->uuid]);
    }

    protected function canStart(?Server $server): bool
    {
        return DebugSessionService::isAdmin(user(), 'create');
    }

    protected function allowed(string $ability, DebugSession $session): bool
    {
        return DebugSessionService::isAdmin(user(), $ability);
    }
}
