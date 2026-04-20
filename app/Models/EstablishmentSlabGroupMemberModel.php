<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstablishmentSlabGroupMemberModel extends Model
{
    protected $table = 't_establishment_slab_group_member';

    protected $fillable = [
        'group_id',
        'establishment_id',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(EstablishmentSlabGroupModel::class, 'group_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(EstablishmentModel::class, 'establishment_id', 'establishment_id');
    }
}
