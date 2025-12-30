<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MumineenEstablishmentModel extends Model
{
    //
    protected $table = 't_mumineen_establishment';

    protected $fillable = [
        'family_id',
        'its',
        'establishment_no',
        'updated_by',
    ];

    protected $casts = [
        'family_id'        => 'integer',
        'establishment_no' => 'integer',
        'updated_by'       => 'integer',
    ];

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(EstablishmentModel::class, 'establishment_no');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Link to mumineen through ITS (optional helpful)
    public function mumineen(): BelongsTo
    {
        return $this->belongsTo(Mumineen::class, 'its', 'its');
    }
}
