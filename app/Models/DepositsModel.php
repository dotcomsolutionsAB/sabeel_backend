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

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function receipts()
    {
        return $this->hasMany(ReceiptModel::class, 'deposit_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
