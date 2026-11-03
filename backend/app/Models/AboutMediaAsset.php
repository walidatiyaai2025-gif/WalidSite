<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AboutMediaAsset extends Model
{
    protected $primaryKey = 'slot';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'published_at' => 'datetime'];
    }
}
