@php
    use ChristianHnl\AiDebugger\Enums\SessionStatus;
    use ChristianHnl\AiDebugger\Enums\StepType;
    use ChristianHnl\AiDebugger\Services\BackupService;
    use Illuminate\Support\Str;

    /** @var \ChristianHnl\AiDebugger\Models\DebugSession $session */
    $status = $session->status;
    $active = $status->isActive();

    // Which phase was reached (for failed/cancelled sessions)
    $reached = match (true) {
        $session->report !== null => 4,
        $session->iterations > 0 => 2,
        $session->backup_status->value !== 'none' => 1,
        default => 0,
    };

    $current = match ($status) {
        SessionStatus::Pending, SessionStatus::Preparing => 0,
        SessionStatus::BackingUp => 1,
        SessionStatus::Analyzing => 2,
        SessionStatus::Finalizing => 3,
        SessionStatus::Completed => 5,
        default => $reached,
    };

    $failed = in_array($status, [SessionStatus::Failed, SessionStatus::Cancelled, SessionStatus::Cancelling], true);

    $phases = [
        ['key' => 'prepare', 'icon' => 'tabler-settings-automation'],
        ['key' => 'backup', 'icon' => 'tabler-database-export'],
        ['key' => 'analyze', 'icon' => 'tabler-robot'],
        ['key' => 'finalize', 'icon' => 'tabler-flag'],
        ['key' => 'done', 'icon' => 'tabler-circle-check'],
    ];

    $orb = match ($status) {
        SessionStatus::Completed => 'success',
        SessionStatus::Failed => 'danger',
        SessionStatus::Cancelled, SessionStatus::Cancelling => 'warning',
        SessionStatus::Pending => 'gray',
        default => 'info',
    };

    $colorClass = fn (StepType $type) => match ($type) {
        StepType::Success => 'success',
        StepType::Warning => 'warning',
        StepType::Error => 'danger',
        StepType::Message => 'primary',
        StepType::Change => 'info',
        default => 'gray',
    };

    $tokens = $session->input_tokens + $session->output_tokens + $session->cache_read_tokens + $session->cache_write_tokens;
    $cost = $session->estimatedCost();
    $report = $session->report ?? [];
    $markdown = fn (?string $text) => Str::markdown((string) $text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

    $startedAt = $session->started_at ?? $session->created_at;
    $endedAt = $session->finished_at;
@endphp

@include('ai-debugger::partials.styles')

<div class="aid" @if ($active) wire:poll.2500ms @endif>
    {{-- ============ Status header ============ --}}
    <section class="aid-card" wire:key="aid-status-{{ $status->value }}">
        @if ($active)
            <div class="aid-progress"></div>
        @endif

        @if ($status === SessionStatus::Completed && ($report['server_starts'] ?? false) && $session->finished_at?->gt(now()->subMinutes(2)))
            <div class="aid-confetti" aria-hidden="true">
                @foreach (range(1, 28) as $i)
                    <i style="left: {{ ($i * 37) % 100 }}%; background: {{ ['var(--aid-primary)', 'var(--aid-success)', 'var(--aid-warning)', 'var(--aid-info)', 'var(--aid-danger)'][$i % 5] }}; animation-delay: {{ ($i % 7) * 120 }}ms"></i>
                @endforeach
            </div>
        @endif

        <div class="aid-status-head">
            <div @class(['aid-orb', 'aid-orb--' . $orb, 'aid-orb--active' => $active])>
                @if ($status === SessionStatus::Completed)
                    <svg class="aid-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                @else
                    <x-filament::icon :icon="$status->getIcon()" />
                @endif
            </div>

            <div style="flex: 1; min-width: 14rem">
                <div class="aid-status-label">{{ $status->getLabel() }}</div>
                <div class="aid-muted aid-small">{{ trans('ai-debugger::strings.session.phase_text.' . $status->value) }}</div>
            </div>

            <div class="aid-row aid-small aid-muted"
                 x-data="{ start: {{ $startedAt?->getTimestamp() ?? 'null' }}, end: {{ $endedAt?->getTimestamp() ?? 'null' }}, now: Math.floor(Date.now() / 1000) }"
                 x-init="if (!end) setInterval(() => now = Math.floor(Date.now() / 1000), 1000)">
                <x-filament::icon icon="tabler-clock" class="aid-ico-sm" />
                <span class="aid-mono" x-text="(() => { if (!start) return '-'; let s = Math.max(0, (end ?? now) - start); const h = Math.floor(s / 3600); const m = Math.floor(s % 3600 / 60); s = s % 60; return (h ? h + 'h ' : '') + m + 'm ' + String(s).padStart(2, '0') + 's' })()"></span>
            </div>
        </div>

        <div class="aid-steps">
            @foreach ($phases as $index => $phase)
                @php
                    $state = match (true) {
                        $index < $current => 'done',
                        $index === $current && $failed => 'error',
                        $index === $current && $active => 'current',
                        $index === $current && $status === SessionStatus::Completed => 'done',
                        default => 'todo',
                    };
                    if ($status === SessionStatus::Completed) {
                        $state = 'done';
                    }
                @endphp
                <div @class(['aid-step', 'aid-step--' . $state]) style="animation-delay: {{ $index * 80 }}ms">
                    <div class="aid-step-dot">
                        @if ($state === 'done')
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                        @elseif ($state === 'error')
                            <x-filament::icon icon="tabler-x" />
                        @elseif ($state === 'current')
                            <x-filament::icon icon="tabler-loader-2" />
                        @else
                            <x-filament::icon :icon="$phase['icon']" />
                        @endif
                    </div>
                    <span class="aid-step-label">{{ trans('ai-debugger::strings.session.phases.' . $phase['key']) }}</span>
                </div>
            @endforeach
        </div>

        <div class="aid-stats">
            <div class="aid-stat">
                <div class="aid-stat-label">{{ trans('ai-debugger::strings.session.stats.turns') }}</div>
                <div class="aid-stat-value">{{ $session->iterations }}</div>
            </div>
            <div class="aid-stat">
                <div class="aid-stat-label">{{ trans('ai-debugger::strings.session.stats.changes') }}</div>
                <div class="aid-stat-value">{{ $changesCount }}@if ($revertedCount)<span class="aid-small aid-muted"> / {{ trans('ai-debugger::strings.session.stats.reverted', ['count' => $revertedCount]) }}</span>@endif</div>
            </div>
            <div class="aid-stat">
                <div class="aid-stat-label">{{ trans('ai-debugger::strings.session.stats.tokens') }}</div>
                <div class="aid-stat-value">{{ $tokens >= 1000000 ? round($tokens / 1000000, 2) . 'M' : ($tokens >= 1000 ? round($tokens / 1000, 1) . 'k' : $tokens) }}</div>
            </div>
            <div class="aid-stat">
                <div class="aid-stat-label">{{ trans('ai-debugger::strings.session.stats.cost') }}</div>
                <div class="aid-stat-value">{{ $cost !== null ? '$' . number_format($cost, 2) : '-' }}</div>
            </div>
            <div class="aid-stat">
                <div class="aid-stat-label">{{ trans('ai-debugger::strings.session.stats.backup') }}</div>
                <div class="aid-stat-value" style="font-size: 1rem; padding-top: .2rem">
                    <x-filament::badge :color="$session->backup_status->getColor()">{{ $session->backup_status->getLabel() }}</x-filament::badge>
                </div>
            </div>
        </div>
    </section>

    <div class="aid-columns">
        {{-- ============ Live activity ============ --}}
        <section class="aid-card">
            <div class="aid-between">
                <div class="aid-row">
                    <x-filament::icon icon="tabler-activity" class="aid-ico" />
                    <span class="aid-h2">{{ trans('ai-debugger::strings.session.activity') }}</span>
                </div>
                @if ($active)
                    <span class="aid-live">{{ trans('ai-debugger::strings.session.live') }}</span>
                @else
                    <span class="aid-small aid-muted">{{ trans('ai-debugger::strings.session.entries', ['count' => $steps->count() + $hiddenSteps]) }}</span>
                @endif
            </div>

            <div class="aid-log"
                 wire:key="aid-log-{{ $session->id }}"
                 x-data="{ stick: true, away: false }"
                 x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => { if (stick) $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, subtree: true })"
                 x-on:scroll.passive="stick = $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 60; away = !stick">
                @if ($hiddenSteps > 0)
                    <button type="button" class="aid-more" wire:click="loadMore">
                        {{ trans('ai-debugger::strings.session.load_more', ['count' => $hiddenSteps]) }}
                    </button>
                @endif

                @forelse ($steps as $step)
                    @php
                        $meta = $step->meta ?? [];
                        $running = $step->type === StepType::ToolCall && ($meta['running'] ?? false) && $active;
                        $isTool = isset($meta['tool']);
                    @endphp
                    <div wire:key="aid-step-{{ $step->id }}"
                         @class([
                             'aid-entry',
                             'aid-entry--' . $colorClass($step->type),
                             'aid-entry--thinking' => $step->type === StepType::Thinking,
                             'aid-entry--running' => $running,
                         ])>
                        <div class="aid-entry-icon">
                            <x-filament::icon :icon="$step->type->getIcon()" />
                        </div>

                        <div class="aid-entry-body">
                            <div class="aid-between" style="gap: .5rem; align-items: flex-start">
                                <div class="aid-entry-title">
                                    @if ($isTool)
                                        <span class="aid-tag aid-mono">{{ $meta['tool'] }}</span>
                                    @endif
                                    @if ($step->type !== StepType::Message)
                                        {{ $step->title }}
                                    @endif
                                    @if ($isTool && isset($meta['duration']) && !$running)
                                        <span class="aid-small aid-muted">· {{ $meta['duration'] }}s</span>
                                    @endif
                                </div>
                                <span class="aid-entry-time" title="{{ $step->created_at?->toDateTimeString() }}">{{ $step->created_at?->format('H:i:s') }}</span>
                            </div>

                            @if ($step->type === StepType::Message)
                                <div class="aid-bubble aid-prose">{!! $markdown($step->content ?? $step->title) !!}</div>
                            @elseif ($step->type === StepType::Thinking && filled($step->content) && $step->content !== $step->title)
                                <details class="aid-details">
                                    <summary>{{ trans('ai-debugger::strings.session.show_thinking') }}</summary>
                                    <div class="aid-bubble aid-prose" style="background: var(--aid-soft); outline-color: var(--aid-border)">{!! $markdown($step->content) !!}</div>
                                </details>
                            @elseif ($isTool && (filled($step->content) || !empty($meta['input'])))
                                <details class="aid-details">
                                    <summary>{{ $running ? trans('ai-debugger::strings.session.running') : trans('ai-debugger::strings.session.show_details') }}</summary>
                                    @if (!empty($meta['input']))
                                        <pre class="aid-code">{{ json_encode($meta['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    @endif
                                    @if (filled($step->content))
                                        <pre class="aid-code">{{ $step->content }}</pre>
                                    @endif
                                </details>
                            @elseif (filled($step->content))
                                <details class="aid-details" @if ($step->type === StepType::Error) open @endif>
                                    <summary>{{ trans('ai-debugger::strings.session.show_details') }}</summary>
                                    <pre class="aid-code">{{ $step->content }}</pre>
                                </details>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="aid-muted aid-small" style="padding: 1rem 0 0 2.75rem">{{ trans('ai-debugger::strings.session.waiting') }}</div>
                @endforelse

                @if ($active)
                    <div class="aid-typing" wire:key="aid-typing">
                        <span class="aid-dots"><i></i><i></i><i></i></span>
                        <span>{{ trans('ai-debugger::strings.session.typing.' . $status->value) }}</span>
                    </div>
                @endif

                <button type="button" class="aid-jump" x-show="away" x-cloak x-on:click="$el.parentElement.scrollTop = $el.parentElement.scrollHeight; stick = true; away = false">
                    <x-filament::icon icon="tabler-arrow-down" class="aid-ico-sm" />
                    {{ trans('ai-debugger::strings.session.jump') }}
                </button>
            </div>
        </section>

        {{-- ============ Side: report, backup, options ============ --}}
        <aside class="aid-side">
            @if ($status === SessionStatus::Completed && !empty($report))
                <section class="aid-card" wire:key="aid-report">
                    <div class="aid-row" style="margin-bottom: .9rem">
                        <x-filament::icon icon="tabler-report" class="aid-ico" />
                        <span class="aid-h2">{{ trans('ai-debugger::strings.session.report') }}</span>
                    </div>

                    <div @class(['aid-report-ok', 'aid-report-ok--bad' => !($report['server_starts'] ?? false)])>
                        @if ($report['server_starts'] ?? false)
                            <svg class="aid-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" opacity=".25" /><path d="M7 12.5l3.2 3.2L17 9" /></svg>
                            <span>{{ trans('ai-debugger::strings.session.server_starts') }}</span>
                        @else
                            <x-filament::icon icon="tabler-alert-triangle" />
                            <span>{{ ($report['tested'] ?? false) ? trans('ai-debugger::strings.session.server_not_starting') : trans('ai-debugger::strings.session.server_untested') }}</span>
                        @endif
                    </div>

                    <div class="aid-prose aid-small" style="margin-top: 1rem; line-height: 1.6">{!! $markdown($report['summary'] ?? $session->summary) !!}</div>

                    @foreach (['fixed_issues' => ['success', 'tabler-circle-check'], 'remaining_issues' => ['warning', 'tabler-alert-circle'], 'recommendations' => ['info', 'tabler-bulb']] as $key => [$color, $icon])
                        @if (!empty($report[$key]))
                            <div style="margin-top: 1.1rem">
                                <div class="aid-h3">{{ trans('ai-debugger::strings.session.' . $key) }}</div>
                                <ul class="aid-list aid-list--{{ $color }}">
                                    @foreach ($report[$key] as $item)
                                        <li style="animation-delay: {{ min(20, $loop->index) * 70 }}ms">
                                            <x-filament::icon :icon="$icon" />
                                            <span>{{ $item }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </section>
            @endif

            @if (in_array($status, [SessionStatus::Failed, SessionStatus::Cancelled], true) && filled($session->error))
                <section class="aid-card" wire:key="aid-error">
                    <div class="aid-row">
                        <x-filament::icon icon="tabler-alert-octagon" class="aid-ico" style="color: var(--aid-danger)" />
                        <span class="aid-h2">{{ trans('ai-debugger::strings.session.error') }}</span>
                    </div>
                    <div class="aid-error">{{ $session->error }}</div>
                </section>
            @endif

            <section class="aid-card">
                <div class="aid-row">
                    <x-filament::icon icon="tabler-database" class="aid-ico" />
                    <span class="aid-h2">{{ trans('ai-debugger::strings.session.backup') }}</span>
                </div>
                <dl class="aid-kv">
                    <dt>{{ trans('ai-debugger::strings.columns.status') }}</dt>
                    <dd><x-filament::badge :color="$session->backup_status->getColor()">{{ $session->backup_status->getLabel() }}</x-filament::badge></dd>
                    <dt>{{ trans('ai-debugger::strings.session.backup_type') }}</dt>
                    <dd>{{ $session->backup_type?->getLabel() ?? '-' }}</dd>
                    <dt>{{ trans('ai-debugger::strings.session.backup_size') }}</dt>
                    <dd>{{ $session->backup_size ? BackupService::formatBytes($session->backup_size) : '-' }}</dd>
                    @if ($session->backup_reference)
                        <dt>{{ trans('ai-debugger::strings.session.backup_reference') }}</dt>
                        <dd class="aid-mono aid-small">{{ $session->backup_type?->value === 'archive' ? '/' . $session->backup_reference : Str::limit($session->backup_reference, 13) }}</dd>
                    @endif
                </dl>
                <p class="aid-small aid-muted" style="margin-top: .75rem">{{ trans('ai-debugger::strings.session.backup_hint') }}</p>
            </section>

            <section class="aid-card">
                <div class="aid-row">
                    <x-filament::icon icon="tabler-adjustments" class="aid-ico" />
                    <span class="aid-h2">{{ trans('ai-debugger::strings.session.options') }}</span>
                </div>
                <ul class="aid-list">
                    @foreach (\ChristianHnl\AiDebugger\Services\DebugSessionService::OPTIONS as $key => $default)
                        @php $enabled = (bool) $session->option($key, $default); @endphp
                        <li style="animation-delay: {{ $loop->index * 50 }}ms">
                            <x-filament::icon :icon="$enabled ? 'tabler-circle-check' : 'tabler-circle-minus'" :style="'color: ' . ($enabled ? 'var(--aid-success)' : 'var(--aid-muted)')" />
                            <span @class(['aid-muted' => !$enabled])>{{ trans('ai-debugger::strings.options.' . $key) }}</span>
                        </li>
                    @endforeach
                </ul>
                @if (filled($session->instructions))
                    <div class="aid-h3" style="margin-top: 1rem">{{ trans('ai-debugger::strings.start.instructions') }}</div>
                    <p class="aid-small aid-muted" style="margin-top: .3rem; white-space: pre-wrap">{{ $session->instructions }}</p>
                @endif
                <dl class="aid-kv">
                    <dt>{{ trans('ai-debugger::strings.session.model') }}</dt>
                    <dd class="aid-mono aid-small">{{ $session->model ?? '-' }}</dd>
                </dl>
            </section>
        </aside>
    </div>
</div>
