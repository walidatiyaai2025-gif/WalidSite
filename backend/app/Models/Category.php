<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'name_en', 'name_ar'];

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'article_categories');
    }
}
