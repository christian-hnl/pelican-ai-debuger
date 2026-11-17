<?php

namespace ChristianHnl\AiDebugger\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BackupStatus: string implements HasColor, HasLabel
{
    case None = 'none';
    case Creating = 'creating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Restoring = 'restoring';
    case Restored = 'restored';
    case RestoreFailed = 'restore_failed';
    case Deleted = 'deleted';

    public function canRestore(): bool
    {
        return in_array($this, [self::Ready, self::Restored, self::RestoreFailed], true);
    }

    public function canDelete(): bool
    {
        return in_array($this, [self::Ready, self::Restored, self::RestoreFailed, self::Failed], true);
    }

    public function getLabel(): string
    {
        return trans('ai-debugger::strings.backup_status.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None, self::Deleted => 'gray',
            self::Creating, self::Restoring => 'info',
            self::Ready, self::Restored => 'success',
            self::Failed, self::RestoreFailed => 'danger',
        };
    }
}
