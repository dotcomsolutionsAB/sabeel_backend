<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstablishmentModel extends Model
{
    //
    protected $table = 't_establishment';

    protected $fillable = [
        'establishment_no',
        'name',
        'address',
        'status',
        'type',
        'remarks',
    ];

    protected $casts = [
        // enums are stored as strings in DB; cast not needed, but ok as string.
        'status' => 'string',
        'type'   => 'string',
    ];

    public function sabeelEntries(): HasMany
    {
        return $this->hasMany(EstablishmentSabeel::class, 'establishment_id');
    }

    public function mumineenLinks(): HasMany
    {
        return $this->hasMany(MumineenEstablishment::class, 'establishment_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'establishment_id');
    }
}
