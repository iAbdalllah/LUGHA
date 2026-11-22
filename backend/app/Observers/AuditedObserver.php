<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Records staff changes to catalog and pricing data (who, what, before → after). */
class AuditedObserver
{
    private function staff(): bool
    {
        return (bool) auth()->user()?->is_staff;
    }

    public function created(Model $model): void
    {
        if ($this->staff()) {
            AuditLog::record(class_basename($model).'.created', $model, $this->clean($model->getAttributes()));
        }
    }

    public function updated(Model $model): void
    {
        if ($this->staff() && $changes = $this->clean($model->getChanges())) {
            $before = array_intersect_key($model->getOriginal(), $changes);
            AuditLog::record(class_basename($model).'.updated', $model, ['before' => $this->clean($before), 'after' => $changes]);
        }
    }

    public function deleted(Model $model): void
    {
        if ($this->staff()) {
            AuditLog::record(class_basename($model).'.deleted', $model);
        }
    }

    private function clean(array $attrs): array
    {
        $out = array_diff_key($attrs, array_flip(['updated_at', 'created_at', 'password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes']));

        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : (is_object($v) ? (string) $v : $v), $out);
    }
}
