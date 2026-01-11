<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MumineenSabeelModel extends Model
{
    //
    protected $table = 't_mumineen_sabeel';

    protected $fillable = [
        'family_id',
        'year',
        'sabeel',
        'updated_by',
    ];

    protected $casts = [
        'family_id'  => 'integer',
        'sabeel'     => 'integer',
        'updated_by' => 'integer',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }
}
