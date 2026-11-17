<?php

namespace ChristianHnl\AiDebugger\Filament\Pages;

use ChristianHnl\AiDebugger\Enums\ChangeAction;
use ChristianHnl\AiDebugger\Enums\SessionStatus;
use ChristianHnl\AiDebugger\Filament\Concerns\BuildsSessionActions;
use ChristianHnl\AiDebugger\Models\DebugChange;
use ChristianHnl\AiDebugger\Models\DebugSession;
use ChristianHnl\AiDebugger\Services\DebugSessionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Live view of one session: progress, activity log, report and the list of changes.
 */
abstract class BaseSessionPage extends Page implements HasTable
{
    use BuildsSessionActions;
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'ai-debugger/session';

    #[Url(as: 'session')]
    public ?string $uuid = null;

    // How many log entries are rendered
    public int $limit = 250;

    protected ?DebugSession $cachedSession = null;

    /** @return Builder<DebugSession> */
    abstract protected function sessionQuery(): Builder;

    abstract protected function listUrl(): string;

    public function mount(): void
    {
        abort_unless($this->debugSession() && $this->allowed('view', $this->debugSession()), 404);
    }

    public function debugSession(): ?DebugSession
    {
        if ($this->cachedSession?->uuid !== $this->uuid) {
            $this->cachedSession = $this->uuid ? $this->sessionQuery()->with(['server', 'user'])->where('uuid', $this->uuid)->first() : null;
        }

        return $this->cachedSession;
    }

    protected function freshSession(): DebugSession
    {
        $this->cachedSession = null;

        $session = $this->debugSession();
        abort_unless($session !== null, 404);

        return $session;
    }

    public function getTitle(): string
    {
        $session = $this->debugSession();

        return trans('ai-debugger::strings.session.title', ['server' => $session?->server->name ?? '?']);
    }

    public function getSubheading(): ?string
    {
        $session = $this->debugSession();

        return $session ? '#' . $session->shortId() . ' · ' . $session->created_at?->format('Y-m-d H:i') . ($session->user ? ' · ' . $session->user->username : '') : null;
    }

    public function getBreadcrumbs(): array
    {
        return [
            $this->listUrl() => trans('ai-debugger::strings.title'),
            '#' . ($this->debugSession()?->shortId() ?? ''),
        ];
    }

    public function loadMore(): void
    {
        $this->limit = min(5000, $this->limit + 500);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('ai-debugger::session')
                ->viewData(function () {
                    $session = $this->freshSession();
                    $total = $session->steps()->count();

                    return [
                        'session' => $session,
                        'steps' => $session->steps()->orderByDesc('id')->limit($this->limit)->get()->reverse()->values(),
                        'hiddenSteps' => max(0, $total - $this->limit),
                        'changesCount' => $session->changes()->count(),
                        'revertedCount' => $session->changes()->where('reverted', true)->count(),
                    ];
                }),
            Section::make(trans('ai-debugger::strings.session.changes'))
                ->description(trans('ai-debugger::strings.session.changes_description'))
                ->icon('tabler-git-compare')
                ->collapsible()
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $record = fn () => $this->debugSession();

        return [
            Action::make('back')
                ->label(trans('ai-debugger::strings.actions.back'))
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url($this->listUrl()),
            $this->cancelSessionAction()->record($record),
            $this->restoreBackupAction()->record($record),
            $this->deleteBackupAction()->record($record),
            $this->deleteSessionAction($this->listUrl())->record($record),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => DebugChange::query()->where('session_id', $this->debugSession()?->id ?? 0))
            ->defaultSort('id', 'desc')
            ->poll(fn () => $this->debugSession()?->status->isActive() ? '5s' : null)
            ->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('action')
                    ->label(trans('ai-debugger::strings.columns.action'))
                    ->badge(),
                TextColumn::make('path')
                    ->label(trans('ai-debugger::strings.columns.path'))
                    ->formatStateUsing(fn (string $state) => '/' . $state)
                    ->description(fn (DebugChange $record) => $record->action === ChangeAction::Update
                        ? '→ /' . ($record->meta['new_path'] ?? '') . (isset($record->meta['to_version']) ? ' (' . $record->meta['to_version'] . ')' : '')
                        : ($record->action === ChangeAction::Move ? '→ /' . $record->target : null))
                    ->fontFamily('mono')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('reason')
                    ->label(trans('ai-debugger::strings.columns.reason'))
                    ->limit(120)
                    ->wrap()
                    ->placeholder('-'),
                IconColumn::make('reverted')
                    ->label(trans('ai-debugger::strings.columns.reverted'))
                    ->boolean()
                    ->trueIcon('tabler-arrow-back-up')
                    ->falseIcon('tabler-check')
                    ->trueColor('gray')
                    ->falseColor('success'),
                TextColumn::make('created_at')
                    ->label(trans('ai-debugger::strings.columns.time'))
                    ->time('H:i:s')
                    ->dateTimeTooltip(),
            ])
            ->recordActions([
                Action::make('previous')
                    ->label(trans('ai-debugger::strings.actions.previous_content'))
                    ->icon('tabler-file-diff')
                    ->color('gray')
                    ->visible(fn (DebugChange $record) => $record->action === ChangeAction::Write && $record->previous_content !== null)
                    ->modalHeading(fn (DebugChange $record) => '/' . $record->path)
                    ->modalContent(fn (DebugChange $record) => new HtmlString('<pre class="aid-code">' . e((string) $record->previous_content) . '</pre>'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(trans('ai-debugger::strings.actions.close'))
                    ->modalWidth('5xl'),
                Action::make('revert')
                    ->label(trans('ai-debugger::strings.actions.revert'))
                    ->icon('tabler-arrow-back-up')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (DebugChange $record) => trans('ai-debugger::strings.actions.revert_description', ['action' => $record->action->getLabel(), 'path' => '/' . $record->path]))
                    ->visible(fn (DebugChange $record) => $record->canRevert() && !$this->debugSession()?->status->isActive() && $this->allowed('restore', $this->debugSession()))
                    ->action(function (DebugChange $record) {
                        try {
                            app(DebugSessionService::class)->revert($record);

                            Notification::make()->success()->title(trans('ai-debugger::strings.notifications.reverted'))->send();
                        } catch (Throwable $exception) {
                            $this->notifyError($exception);
                        }
                    }),
            ])
            ->emptyStateIcon('tabler-git-compare')
            ->emptyStateHeading(fn () => $this->debugSession()?->status === SessionStatus::Completed ? trans('ai-debugger::strings.session.no_changes') : trans('ai-debugger::strings.session.no_changes_yet'))
            ->emptyStateDescription(null);
    }
}
