<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositsModel extends Model
{
    //
    protected $table = 't_deposits';

    protected $fillable = [
        'deposit_id',
        'date',
        'receipt_ids',
        'amount',
        'created_by',
        'remarks',
    ];
}
