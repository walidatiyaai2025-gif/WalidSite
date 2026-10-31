<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profile extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'public_name', 'bio_en', 'bio_ar', 'portrait_asset_id', 'publish_status'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function careerPositions(): HasMany { return $this->hasMany(CareerPosition::class); }
    public function skills(): BelongsToMany { return $this->belongsToMany(Skill::class); }
    public function certifications(): HasMany { return $this->hasMany(Certification::class); }
    public function professionalClaims(): HasMany { return $this->hasMany(ProfessionalClaim::class); }
}
