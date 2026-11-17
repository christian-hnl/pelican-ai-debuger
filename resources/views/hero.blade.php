@include('ai-debugger::partials.styles')

<div class="aid">
    <section class="aid-card aid-hero">
        @include('ai-debugger::partials.robot')

        <div>
            <div class="aid-hero-title">{{ trans('ai-debugger::strings.hero.title') }}</div>
            <p class="aid-muted" style="margin-top: .35rem; max-width: 60rem">{{ trans('ai-debugger::strings.hero.text') }}</p>

            <div class="aid-flow">
                @foreach (['backup' => 'tabler-database-export', 'analyze' => 'tabler-zoom-code', 'fix' => 'tabler-tool', 'test' => 'tabler-player-play', 'report' => 'tabler-report'] as $step => $icon)
                    @if (!$loop->first)
                        <span class="aid-chip-arrow" style="animation-delay: {{ $loop->index * 140 - 70 }}ms">→</span>
                    @endif
                    <span class="aid-chip" style="animation-delay: {{ $loop->index * 140 }}ms">
                        <x-filament::icon :icon="$icon" class="aid-ico-sm" />
                        {{ trans('ai-debugger::strings.hero.steps.' . $step) }}
                    </span>
                @endforeach
            </div>

            <div class="aid-stats">
                @foreach (['sessions' => 'tabler-robot', 'completed' => 'tabler-circle-check', 'changes' => 'tabler-git-compare', 'backups' => 'tabler-database'] as $key => $icon)
                    <div class="aid-stat"
                         x-data="{ n: 0, target: {{ (int) ($stats[$key] ?? 0) }} }"
                         x-init="let start = null; const step = (t) => { start ??= t; const p = Math.min(1, (t - start) / 900); n = Math.round(target * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step) }; requestAnimationFrame(step)">
                        <div class="aid-row" style="gap: .4rem">
                            <x-filament::icon :icon="$icon" class="aid-ico-sm aid-muted" />
                            <span class="aid-stat-label">{{ trans('ai-debugger::strings.hero.stats.' . $key) }}</span>
                        </div>
                        <div class="aid-stat-value" x-text="n">{{ (int) ($stats[$key] ?? 0) }}</div>
                    </div>
                @endforeach
            </div>

            @if (!$configured)
                <div class="aid-banner aid-banner--warning">
                    <x-filament::icon icon="tabler-key" class="aid-ico" style="color: var(--aid-warning)" />
                    <div>
                        <div class="aid-h3">{{ trans('ai-debugger::strings.hero.no_key_title') }}</div>
                        <div class="aid-small aid-muted">{{ trans('ai-debugger::strings.hero.no_key_text') }}</div>
                    </div>
                </div>
            @elseif ($active)
                <a href="{{ $activeUrl }}" wire:navigate class="aid-banner" wire:poll.5s>
                    <div class="aid-orb aid-orb--info aid-orb--active" style="width: 36px; height: 36px">
                        <x-filament::icon :icon="$active->status->getIcon()" class="aid-ico-sm" />
                    </div>
                    <div style="flex: 1">
                        <div class="aid-h3">{{ trans('ai-debugger::strings.hero.active', ['server' => $active->server->name]) }}</div>
                        <div class="aid-small aid-muted">{{ $active->status->getLabel() }} · {{ $active->started_at?->diffForHumans() ?? $active->created_at?->diffForHumans() }}</div>
                    </div>
                    <x-filament::icon icon="tabler-chevron-right" class="aid-ico aid-muted" />
                </a>
            @else
                <p class="aid-small aid-muted" style="margin-top: 1rem">
                    {{ trans('ai-debugger::strings.hero.model', ['model' => $model]) }}
                </p>
            @endif
        </div>
    </section>
</div>
