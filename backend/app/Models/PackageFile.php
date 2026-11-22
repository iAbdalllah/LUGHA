<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageFile extends Model
{
    protected $fillable = ['package_version_id', 'kind', 'base_version_id', 'disk', 'path', 'size_bytes', 'sha256'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class, 'package_version_id');
    }

    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class, 'base_version_id');
    }
}
