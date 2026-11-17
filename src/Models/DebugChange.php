<?php

namespace ChristianHnl\AiDebugger\Models;

use ChristianHnl\AiDebugger\Enums\ChangeAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $session_id
 * @property ChangeAction $action
 * @property string $path
 * @property string|null $target
 * @property string|null $reason
 * @property string|null $previous_content
 * @property array<string, mixed>|null $meta
 * @property bool $reverted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DebugSession $session
 */
class DebugChange extends Model
{
    protected $table = 'ai_debugger_changes';

    protected $fillable = [
        'session_id',
        'action',
        'path',
        'target',
        'reason',
        'previous_content',
        'meta',
        'reverted',
    ];

    protected $hidden = [
        'previous_content',
    ];

    protected function casts(): array
    {
        return [
            'action' => ChangeAction::class,
            'meta' => 'array',
            'reverted' => 'boolean',
        ];
    }

    /** @return BelongsTo<DebugSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(DebugSession::class, 'session_id');
    }

    public function canRevert(): bool
    {
        if ($this->reverted || !$this->action->canRevert()) {
            return false;
        }

        // A write of an existing file can only be reverted if the old content was stored
        if ($this->action === ChangeAction::Write) {
            return $this->previous_content !== null;
        }

        return true;
    }
}
