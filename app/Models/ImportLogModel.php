<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportLogModel extends Model
{
    protected $table = 'import_logs';

    protected $fillable = [
        'operation_type',
        'file_name',
        'total_records',
        'hof_found',
        'hof_updated',
        'hof_created',
        'fm_synced',
        'fm_added',
        'fm_removed',
        'sabeel_created',
        'errors',
        'details',
        'error_log',
        'status',
        'user_id',
    ];

    protected $casts = [
        'total_records' => 'integer',
        'hof_found' => 'integer',
        'hof_updated' => 'integer',
        'hof_created' => 'integer',
        'fm_synced' => 'integer',
        'fm_added' => 'integer',
        'fm_removed' => 'integer',
        'sabeel_created' => 'integer',
        'errors' => 'integer',
        'details' => 'array',
        'error_log' => 'array',
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
