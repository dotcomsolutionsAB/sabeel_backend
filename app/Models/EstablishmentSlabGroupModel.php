<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstablishmentSlabGroupModel extends Model
{
    protected $table = 't_establishment_slab_group';

    protected $fillable = [
        'primary_establishment_id',
        'label',
        'remarks',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(EstablishmentSlabGroupMemberModel::class, 'group_id');
    }
}
