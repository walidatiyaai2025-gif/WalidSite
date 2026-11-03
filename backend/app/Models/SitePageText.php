<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SitePageText extends Model
{
    use HasUuids;

    protected $fillable = [];
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'published_at' => 'datetime',
        ];
    }
}
