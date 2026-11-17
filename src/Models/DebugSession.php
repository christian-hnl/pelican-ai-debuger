<?php

namespace ChristianHnl\AiDebugger\Models;

use App\Models\Server;
use App\Models\User;
use ChristianHnl\AiDebugger\Enums\BackupStatus;
use ChristianHnl\AiDebugger\Enums\BackupType;
use ChristianHnl\AiDebugger\Enums\SessionStatus;
use ChristianHnl\AiDebugger\Enums\StepType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $server_id
 * @property int|null $user_id
 * @property SessionStatus $status
 * @property array<string, mixed>|null $options
 * @property string|null $instructions
 * @property BackupType|null $backup_type
 * @property string|null $backup_reference
 * @property BackupStatus $backup_status
 * @property int|null $backup_size
 * @property string|null $summary
 * @property array<string, mixed>|null $report
 * @property string|null $error
 * @property string|null $model
 * @property int $iterations
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read User|null $user
 */
class DebugSession extends Model
{
    protected $table = 'ai_debugger_sessions';

    protected $fillable = [
        'uuid',
        'server_id',
        'user_id',
        'status',
        'options',
        'instructions',
        'backup_type',
        'backup_reference',
        'backup_status',
        'backup_size',
        'summary',
        'report',
        'error',
        'model',
        'iterations',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => 'pending',
        'backup_status' => 'none',
    ];

    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'backup_type' => BackupType::class,
            'backup_status' => BackupStatus::class,
            'options' => 'array',
            'report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DebugStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(DebugStep::class, 'session_id');
    }

    /** @return HasMany<DebugChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(DebugChange::class, 'session_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (SessionStatus $status) => $status->value, SessionStatus::active()));
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options ?? [], $key, $default);
    }

    public function shortId(): string
    {
        return substr($this->uuid, 0, 8);
    }

    public function isCancelRequested(): bool
    {
        $status = static::query()->whereKey($this->id)->value('status');

        return $status === SessionStatus::Cancelling->value || $status === SessionStatus::Cancelled->value;
    }

    /** @param array<string, mixed>|null $meta */
    public function log(StepType $type, string $title, ?string $content = null, ?array $meta = null): DebugStep
    {
        return $this->steps()->create([
            'type' => $type,
            'title' => Str::limit($title, 250),
            'content' => $content,
            'meta' => $meta,
        ]);
    }

    public function estimatedCost(): ?float
    {
        // USD per million tokens: [input, output, cache read, cache write]
        $prices = [
            'claude-fable-5-1' => [10.0, 50.0, 0.25, 12.5],
            'claude-opus-5-5' => [4.0, 20.0, 0.20, 5.0],
            'claude-sonnet-5-5' => [2.0, 10.0, 0.20, 2.5],
            'claude-haiku-5-5' => [0.10, 0.50, 0.01, 0.125],
        ];

        $price = $prices[$this->model] ?? null;
        if (!$price) {
            return null;
        }

        return ($this->input_tokens * $price[0] + $this->output_tokens * $price[1] + $this->cache_read_tokens * $price[2] + $this->cache_write_tokens * $price[3]) / 1_000_000;
    }
}
