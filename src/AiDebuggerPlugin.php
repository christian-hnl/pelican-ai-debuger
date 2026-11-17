<?php

namespace ChristianHnl\AiDebugger;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;

class AiDebuggerPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'ai-debugger';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "ChristianHnl\\AiDebugger\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return [
            'api_key' => '',
            'base_url' => config('ai-debugger.anthropic.base_url'),
            'model' => config('ai-debugger.anthropic.model'),
            'effort' => config('ai-debugger.anthropic.effort'),
            'max_iterations' => config('ai-debugger.max_iterations'),
            'backup_mode' => config('ai-debugger.backup_mode'),
            'allow_server_users' => config('ai-debugger.allow_server_users'),
            'user_daily_limit' => config('ai-debugger.user_daily_limit'),
            'modrinth' => config('ai-debugger.modrinth'),
            'max_test_start_seconds' => config('ai-debugger.max_test_start_seconds'),
        ];
    }

    public function getSettingsForm(): array
    {
        return [
            TextInput::make('api_key')
                ->label(trans('ai-debugger::strings.settings.api_key'))
                ->password()
                ->revealable()
                ->placeholder(filled(config('ai-debugger.anthropic.api_key')) ? trans('ai-debugger::strings.settings.api_key_set') : 'sk-ant-...')
                ->helperText(trans('ai-debugger::strings.settings.api_key_help')),
            Select::make('model')
                ->label(trans('ai-debugger::strings.settings.model'))
                ->options([
                    'claude-opus-5-5' => 'Claude Opus 5.5 (' . trans('ai-debugger::strings.settings.recommended') . ')',
                    'claude-fable-5-2' => 'Claude Fable 5.2',
                    'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
                    'claude-haiku-5-5' => 'Claude Haiku 5.5',
                ])
                ->required()
                ->selectablePlaceholder(false),
            Select::make('effort')
                ->label(trans('ai-debugger::strings.settings.effort'))
                ->options([
                    'low' => 'low',
                    'medium' => 'medium',
                    'high' => 'high (' . trans('ai-debugger::strings.settings.recommended') . ')',
                    'xhigh' => 'xhigh',
                    'max' => 'max',
                ])
                ->helperText(trans('ai-debugger::strings.settings.effort_help'))
                ->required()
                ->selectablePlaceholder(false),
            TextInput::make('max_iterations')
                ->label(trans('ai-debugger::strings.settings.max_iterations'))
                ->numeric()
                ->minValue(5)
                ->maxValue(500)
                ->required(),
            Select::make('backup_mode')
                ->label(trans('ai-debugger::strings.settings.backup_mode'))
                ->options([
                    'auto' => trans('ai-debugger::strings.settings.backup_auto'),
                    'archive' => trans('ai-debugger::strings.settings.backup_archive'),
                    'panel' => trans('ai-debugger::strings.settings.backup_panel'),
                ])
                ->required()
                ->selectablePlaceholder(false),
            TextInput::make('max_test_start_seconds')
                ->label(trans('ai-debugger::strings.settings.max_test_start'))
                ->numeric()
                ->minValue(60)
                ->maxValue(3600)
                ->suffix('s')
                ->required(),
            Toggle::make('modrinth')
                ->label(trans('ai-debugger::strings.settings.modrinth'))
                ->helperText(trans('ai-debugger::strings.settings.modrinth_help')),
            Toggle::make('allow_server_users')
                ->label(trans('ai-debugger::strings.settings.allow_server_users'))
                ->helperText(trans('ai-debugger::strings.settings.allow_server_users_help'))
                ->live(),
            TextInput::make('user_daily_limit')
                ->label(trans('ai-debugger::strings.settings.user_daily_limit'))
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->visible(fn ($get) => (bool) $get('allow_server_users')),
            TextInput::make('base_url')
                ->label(trans('ai-debugger::strings.settings.base_url'))
                ->url()
                ->placeholder('https://api.anthropic.com'),
        ];
    }

    public function saveSettings(array $data): void
    {
        $values = [
            'AI_DEBUGGER_MODEL' => $data['model'] ?? 'claude-opus-5-5',
            'AI_DEBUGGER_EFFORT' => $data['effort'] ?? 'high',
            'AI_DEBUGGER_MAX_ITERATIONS' => (int) ($data['max_iterations'] ?? 80),
            'AI_DEBUGGER_BACKUP_MODE' => $data['backup_mode'] ?? 'auto',
            'AI_DEBUGGER_MAX_TEST_START' => (int) ($data['max_test_start_seconds'] ?? 900),
            'AI_DEBUGGER_MODRINTH' => !empty($data['modrinth']) ? 'true' : 'false',
            'AI_DEBUGGER_ALLOW_SERVER_USERS' => !empty($data['allow_server_users']) ? 'true' : 'false',
            'AI_DEBUGGER_USER_DAILY_LIMIT' => (int) ($data['user_daily_limit'] ?? 3),
            'AI_DEBUGGER_ANTHROPIC_BASE_URL' => (string) ($data['base_url'] ?? ''),
        ];

        // Empty field = keep the stored key
        if (filled($data['api_key'] ?? null)) {
            $values['AI_DEBUGGER_ANTHROPIC_API_KEY'] = trim((string) $data['api_key']);
        }

        $this->writeToEnvironment($values);

        Notification::make()
            ->title(trans('ai-debugger::strings.settings.saved'))
            ->success()
            ->send();
    }
}
