<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use HasUlids;

    public const CATEGORIES = ['billing', 'download', 'translation_error', 'device', 'other'];

    /** Mirror DB defaults so freshly created models behave like loaded ones. */
    protected $attributes = ['status' => 'open', 'priority' => 'normal'];

    protected $fillable = ['user_id', 'order_id', 'package_id', 'subject', 'category', 'status', 'priority', 'assignee_id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('id');
    }
}
