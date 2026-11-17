<?php

namespace ChristianHnl\AiDebugger\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SessionStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case BackingUp = 'backing_up';
    case Analyzing = 'analyzing';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';

    /** @return self[] */
    public static function active(): array
    {
        return [self::Pending, self::Preparing, self::BackingUp, self::Analyzing, self::Finalizing, self::Cancelling];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function isFinished(): bool
    {
        return !$this->isActive();
    }

    public function getLabel(): string
    {
        return trans('ai-debugger::strings.status.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending, self::Preparing => 'gray',
            self::BackingUp, self::Analyzing, self::Finalizing => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Cancelling, self::Cancelled => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'tabler-clock',
            self::Preparing => 'tabler-settings-automation',
            self::BackingUp => 'tabler-database-export',
            self::Analyzing => 'tabler-robot',
            self::Finalizing => 'tabler-flag',
            self::Completed => 'tabler-circle-check',
            self::Failed => 'tabler-circle-x',
            self::Cancelling, self::Cancelled => 'tabler-player-stop',
        };
    }
}
