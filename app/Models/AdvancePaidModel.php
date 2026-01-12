<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvancePaidModel extends Model
{
    protected $table = 't_advance_paid';

    protected $fillable = [
        'type',
        'family_id',
        'establishment_id',
        'amount',
        'mode',
        'date',
        'remarks',
        'status',
        'user_id',
    ];

    protected $casts = [
        'family_id'        => 'integer',
        'establishment_id' => 'integer',
        'amount'           => 'decimal:2',
        'date'             => 'date',
        'user_id'          => 'integer',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(MumineenModel::class, 'family_id', 'family_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(EstablishmentModel::class, 'establishment_id', 'establishment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
