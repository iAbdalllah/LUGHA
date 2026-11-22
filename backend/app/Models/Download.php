<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Download extends Model
{
    use HasUlids;

    protected $fillable = ['user_id', 'device_id', 'package_file_id', 'status', 'ip', 'expires_at', 'completed_at'];

    protected $attributes = ['status' => 'authorized'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(PackageFile::class, 'package_file_id');
    }
}
