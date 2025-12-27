<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MumineenModel extends Model
{
    //
    protected $table = 't_mumineen';

    protected $fillable = [
        'family_id',
        'hof_type',
        'its',
        'hof_its',
        'family_its',
        'name',
        'sector',
        'sub_sector',
        'mobile',
        'email',
        'gender',
        'age',
        'status',
    ];

    protected $casts = [
        'family_id' => 'integer',
        'age'       => 'integer',
    ];

    // One family can have many links to establishments
    public function establishmentLinks(): HasMany
    {
        return $this->hasMany(MumineenEstablishment::class, 'family_id', 'family_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'family_id', 'family_id');
    }

    public function sabeelEntries(): HasMany
    {
        return $this->hasMany(MumineenSabeel::class, 'family_id', 'family_id');
    }
}
