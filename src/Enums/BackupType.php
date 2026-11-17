<?php

namespace ChristianHnl\AiDebugger\Enums;

use Filament\Support\Contracts\HasLabel;

enum BackupType: string implements HasLabel
{
    // A tar.gz archive inside the server files (/.ai-debugger/backups)
    case Archive = 'archive';
    // A regular panel backup
    case Panel = 'panel';

    public function getLabel(): string
    {
        return trans('ai-debugger::strings.backup_type.' . $this->value);
    }
}
