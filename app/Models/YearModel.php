<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YearModel extends Model
{
    //
    protected $table = 't_year';

    protected $fillable = [
        'year',
        'is_current',
    ];

    protected $casts = [
        'year'       => 'integer',
        'is_current' => 'boolean',
    ];
}
