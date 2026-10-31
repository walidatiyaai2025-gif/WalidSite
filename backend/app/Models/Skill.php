<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Skill extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'name_en', 'name_ar', 'category', 'evidence_status'];

    public function profiles(): BelongsToMany { return $this->belongsToMany(Profile::class); }
}
