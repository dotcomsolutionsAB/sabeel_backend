<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptModel extends Model
{
    //
    protected $table = 't_receipts';

    protected $fillable = [
        'family_id',
        'establishment_id',
        'receipt_no',
        'date',
        'deposit_id',
        'name',
        'its',
        'mode',
        'transaction_no',
        'transaction_date',
        'bank',
        'cheque_no',
        'cheque_date',
        'ifsc',
        'amount',
        'year',
        'comment',
        'status',
        'updated_by',
    ];

    protected $casts = [
        'family_id'         => 'integer',
        'establishment_id'  => 'integer',
        'date'              => 'date',
        'transaction_date'  => 'date',
        'cheque_date'       => 'date',
        'amount'            => 'decimal:2',
        'updated_by'        => 'integer',
    ];

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(EstablishmentModel::class, 'establishment_id', 'establishment_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }

    // Helpful for searching by ITS (optional)
    public function mumineen(): BelongsTo
    {
        return $this->belongsTo(MumineenModel::class, 'its', 'its');
    }
}
