<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CounterModel extends Model
{
    //
    protected $table = 't_counter';

    protected $fillable = [
        'prefix',
        'number',
        'postfix',
    ];

    protected $casts = [
        'number' => 'integer',
    ];
}
