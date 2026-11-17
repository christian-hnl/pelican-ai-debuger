<?php

namespace ChristianHnl\AiDebugger\Filament\Pages;

use App\Models\Server;
use ChristianHnl\AiDebugger\Enums\BackupStatus;
use ChristianHnl\AiDebugger\Enums\SessionStatus;
use ChristianHnl\AiDebugger\Filament\Concerns\BuildsSessionActions;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\Agent\DebugAgent;
use ChristianHnl\AiDebugger\Services\DebugSessionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * List of sessions + "start new session" for the admin and the server panel.
 */
abstract class BaseDebuggerPage extends Page implements HasTable
{
    use BuildsSessionActions;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-robot';

    protected static ?string $slug = 'ai-debugger';

    /**
     * The server the page is bound to (server panel), null = choose one (admin panel).
     */
    abstract protected function fixedServer(): ?Server;

    /** @return Builder<DebugSession> */
    abstract protected function sessionQuery(): Builder;

    abstract protected function sessionUrl(DebugSession $session): string;

    abstract protected function canStart(?Server $server): bool;

    public static function getNavigationLabel(): string
    {
        return trans('ai-debugger::strings.navigation');
    }

    public function getTitle(): string
    {
        return trans('ai-debugger::strings.title');
    }

    public function getSubheading(): ?string
    {
        return trans('ai-debugger::strings.subheading');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('ai-debugger::hero')
                ->viewData(function () {
                    $active = (clone $this->sessionQuery())->active()->with('server')->latest('id')->first();

                    return [
                        'configured' => DebugAgent::isConfigured(),
                        'model' => DebugAgent::model(),
                        'stats' => $this->stats(),
                        'active' => $active,
                        'activeUrl' => $active ? $this->sessionUrl($active) : null,
                    ];
                }),
            EmbeddedTable::make(),
        ]);
    }

    /** @return array<string, int|float|null> */
    protected function stats(): array
    {
        $query = $this->sessionQuery();

        return [
            'sessions' => (clone $query)->count(),
            'completed' => (clone $query)->where('status', SessionStatus::Completed->value)->count(),
            'changes' => (int) \ChristianHnl\AiDebugger\Models\DebugChange::query()->whereIn('session_id', (clone $query)->select('id'))->count(),
            'backups' => (clone $query)->whereIn('backup_status', [BackupStatus::Ready->value, BackupStatus::Restored->value, BackupStatus::RestoreFailed->value])->count(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->startAction(),
        ];
    }

    protected function startAction(): Action
    {
        $fixed = $this->fixedServer();

        return Action::make('start')
            ->label(trans('ai-debugger::strings.actions.start'))
            ->icon('tabler-player-play')
            ->color('primary')
            ->modalIcon('tabler-robot')
            ->modalHeading(trans('ai-debugger::strings.start.heading'))
            ->modalDescription(trans('ai-debugger::strings.start.description'))
            ->modalSubmitActionLabel(trans('ai-debugger::strings.actions.start'))
            ->modalWidth('3xl')
            ->visible(fn () => $this->canStart($fixed))
            ->disabled(fn () => !DebugAgent::isConfigured())
            ->tooltip(fn () => DebugAgent::isConfigured() ? null : trans('ai-debugger::strings.notifications.no_api_key'))
            ->schema([
                Select::make('server_id')
                    ->label(trans('ai-debugger::strings.start.server'))
                    ->hidden($fixed !== null)
                    ->required($fixed === null)
                    ->searchable()
                    ->preload()
                    ->options(fn () => Server::query()->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                    ->getSearchResultsUsing(fn (string $search) => Server::query()
                        ->where(fn ($query) => $query->where('name', 'like', "%$search%")->orWhere('uuid', 'like', "$search%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->pluck('name', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => Server::query()->find($value)?->name),
                Section::make(trans('ai-debugger::strings.start.options'))
                    ->compact()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])->schema([
                            Toggle::make('remove_client_mods')
                                ->label(trans('ai-debugger::strings.options.remove_client_mods'))
                                ->helperText(trans('ai-debugger::strings.options.remove_client_mods_help'))
                                ->default(true),
                            Toggle::make('update_mods')
                                ->label(trans('ai-debugger::strings.options.update_mods'))
                                ->helperText(trans('ai-debugger::strings.options.update_mods_help'))
                                ->default(true),
                            Toggle::make('install_missing_dependencies')
                                ->label(trans('ai-debugger::strings.options.install_missing_dependencies'))
                                ->helperText(trans('ai-debugger::strings.options.install_missing_dependencies_help'))
                                ->default(true),
                            Toggle::make('edit_configs')
                                ->label(trans('ai-debugger::strings.options.edit_configs'))
                                ->helperText(trans('ai-debugger::strings.options.edit_configs_help'))
                                ->default(true),
                            Toggle::make('allow_test_starts')
                                ->label(trans('ai-debugger::strings.options.allow_test_starts'))
                                ->helperText(trans('ai-debugger::strings.options.allow_test_starts_help'))
                                ->default(true)
                                ->live(),
                            Toggle::make('leave_running')
                                ->label(trans('ai-debugger::strings.options.leave_running'))
                                ->helperText(trans('ai-debugger::strings.options.leave_running_help'))
                                ->default(false)
                                ->visible(fn ($get) => (bool) $get('allow_test_starts')),
                            Toggle::make('accept_eula')
                                ->label(trans('ai-debugger::strings.options.accept_eula'))
                                ->helperText(trans('ai-debugger::strings.options.accept_eula_help'))
                                ->default(false),
                        ]),
                    ]),
                Textarea::make('instructions')
                    ->label(trans('ai-debugger::strings.start.instructions'))
                    ->placeholder(trans('ai-debugger::strings.start.instructions_placeholder'))
                    ->rows(3)
                    ->maxLength(4000),
            ])
            ->action(function (array $data) use ($fixed) {
                $server = $fixed ?? Server::query()->find($data['server_id'] ?? null);

                if (!$server || !$this->canStart($server)) {
                    Notification::make()->danger()->title(trans('ai-debugger::strings.notifications.not_allowed'))->send();

                    return;
                }

                try {
                    $session = app(DebugSessionService::class)->start($server, user(), $data, $data['instructions'] ?? null);
                } catch (Throwable $exception) {
                    $this->notifyError($exception);

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(trans('ai-debugger::strings.notifications.started'))
                    ->body(trans('ai-debugger::strings.notifications.started_body'))
                    ->send();

                $this->redirect($this->sessionUrl($session), navigate: true);
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->sessionQuery()->with(['server', 'user'])->withCount('changes'))
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->recordUrl(fn (DebugSession $record) => $this->sessionUrl($record))
            ->columns([
                TextColumn::make('server.name')
                    ->label(trans('ai-debugger::strings.columns.server'))
                    ->searchable()
                    ->hidden($this->fixedServer() !== null)
                    ->icon('tabler-server'),
                TextColumn::make('status')
                    ->label(trans('ai-debugger::strings.columns.status'))
                    ->badge(),
                TextColumn::make('summary')
                    ->label(trans('ai-debugger::strings.columns.summary'))
                    ->state(fn (DebugSession $record) => $record->summary ?? $record->error)
                    ->limit(80)
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('changes_count')
                    ->label(trans('ai-debugger::strings.columns.changes'))
                    ->badge()
                    ->color('info'),
                TextColumn::make('backup_status')
                    ->label(trans('ai-debugger::strings.columns.backup'))
                    ->badge()
                    ->description(fn (DebugSession $record) => $record->backup_type?->getLabel()),
                TextColumn::make('user.username')
                    ->label(trans('ai-debugger::strings.columns.user'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost')
                    ->label(trans('ai-debugger::strings.columns.cost'))
                    ->state(fn (DebugSession $record) => $record->estimatedCost() !== null ? '$' . number_format((float) $record->estimatedCost(), 2) : null)
                    ->description(fn (DebugSession $record) => number_format($record->input_tokens + $record->output_tokens + $record->cache_read_tokens + $record->cache_write_tokens) . ' tokens')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(trans('ai-debugger::strings.columns.created'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(trans('ai-debugger::strings.columns.status'))
                    ->options(collect(SessionStatus::cases())->mapWithKeys(fn (SessionStatus $status) => [$status->value => $status->getLabel()])->all()),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(trans('ai-debugger::strings.actions.view'))
                    ->icon('tabler-eye')
                    ->url(fn (DebugSession $record) => $this->sessionUrl($record)),
                ActionGroup::make([
                    $this->cancelSessionAction(),
                    $this->restoreBackupAction(),
                    $this->deleteBackupAction(),
                    $this->deleteSessionAction(),
                ]),
            ])
            ->emptyStateIcon('tabler-robot')
            ->emptyStateHeading(trans('ai-debugger::strings.empty.heading'))
            ->emptyStateDescription(trans('ai-debugger::strings.empty.description'));
    }
}
