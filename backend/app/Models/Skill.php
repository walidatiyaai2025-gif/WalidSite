<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'name_en', 'name_ar', 'category', 'evidence_status'];

    public function scopeWithVerifiedEvidence($query)
    {
        return $query->where('evidence_status', 'verified');
    }
}
