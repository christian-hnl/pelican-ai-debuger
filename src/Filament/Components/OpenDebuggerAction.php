<?php

namespace ChristianHnl\AiDebugger\Filament\Components;

use App\Models\Server;
use ChristianHnl\AiDebugger\Filament\Server\Pages\AiDebugger;
use ChristianHnl\AiDebugger\Services\DebugSessionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Enums\Size;

/**
 * Robot button in the console header that opens the AI Debugger of the server.
 */
class OpenDebuggerAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'ai_debugger';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->tooltip(fn () => trans('ai-debugger::strings.console_action'));
        $this->icon('tabler-robot');
        $this->color('primary');
        $this->size(Size::ExtraLarge);

        $this->visible(function () {
            $server = Filament::getTenant();

            return $server instanceof Server && DebugSessionService::canUseOnServer(user(), $server, 'view');
        });

        $this->url(fn () => AiDebugger::getUrl());
    }
}
