<?php

namespace ChristianHnl\AiDebugger\Models;

use ChristianHnl\AiDebugger\Enums\StepType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $session_id
 * @property StepType $type
 * @property string $title
 * @property string|null $content
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DebugSession $session
 */
class DebugStep extends Model
{
    protected $table = 'ai_debugger_steps';

    protected $fillable = [
        'session_id',
        'type',
        'title',
        'content',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => StepType::class,
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<DebugSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(DebugSession::class, 'session_id');
    }
}
