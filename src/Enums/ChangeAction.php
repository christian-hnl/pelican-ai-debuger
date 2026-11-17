<?php

namespace ChristianHnl\AiDebugger\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ChangeAction: string implements HasColor, HasLabel
{
    // File moved into the quarantine folder (can be reverted)
    case Quarantine = 'quarantine';
    case Delete = 'delete';
    case Write = 'write';
    case Create = 'create';
    case Move = 'move';
    case Update = 'update';
    case Install = 'install';

    public function canRevert(): bool
    {
        return $this !== self::Delete;
    }

    public function getLabel(): string
    {
        return trans('ai-debugger::strings.change_action.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Quarantine, self::Delete => 'danger',
            self::Write, self::Move => 'warning',
            self::Create, self::Install => 'success',
            self::Update => 'info',
        };
    }
}
