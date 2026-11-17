<?php

namespace ChristianHnl\AiDebugger\Enums;

enum StepType: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
    case Thinking = 'thinking';
    case Message = 'message';
    case ToolCall = 'tool_call';
    case Change = 'change';

    public function getIcon(): string
    {
        return match ($this) {
            self::Info => 'tabler-info-circle',
            self::Success => 'tabler-circle-check',
            self::Warning => 'tabler-alert-triangle',
            self::Error => 'tabler-circle-x',
            self::Thinking => 'tabler-bulb',
            self::Message => 'tabler-robot',
            self::ToolCall => 'tabler-tool',
            self::Change => 'tabler-pencil',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Info, self::ToolCall => 'gray',
            self::Success => 'success',
            self::Warning => 'warning',
            self::Error => 'danger',
            self::Thinking => 'gray',
            self::Message => 'primary',
            self::Change => 'info',
        };
    }
}
