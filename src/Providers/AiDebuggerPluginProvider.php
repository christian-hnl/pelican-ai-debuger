<?php

namespace ChristianHnl\AiDebugger\Providers;

use App\Enums\HeaderActionPosition;
use App\Filament\Server\Pages\Console;
use App\Models\Role;
use ChristianHnl\AiDebugger\Filament\Components\OpenDebuggerAction;
use Illuminate\Support\ServiceProvider;

class AiDebuggerPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // Admin role permissions: "view aiDebugger", "create aiDebugger", ...
        Role::registerCustomPermissions([
            'aiDebugger' => ['view', 'create', 'restore', 'delete'],
        ]);
        Role::registerCustomModelIcon('aiDebugger', 'tabler-robot');

        Console::registerCustomHeaderActions(HeaderActionPosition::Before, OpenDebuggerAction::make());
    }

    public function boot(): void {}
}
