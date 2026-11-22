<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(string $action, Model $subject, ?array $changes = null): void
    {
        static::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
            'ip' => request()?->ip(),
        ]);
    }
}
