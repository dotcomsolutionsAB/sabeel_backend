<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstablishmentSabeelModel extends Model
{
    //
    protected $table = 't_establishment_sabeel';

    protected $fillable = [
        'establishment_no',
        'year',
        'sabeel',
        'updated_by',
    ];

    protected $casts = [
        'establishment_no' => 'integer',
        'year'             => 'integer',
        'sabeel'           => 'integer',
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
}
