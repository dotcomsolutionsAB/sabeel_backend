<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppDueFollowupModel extends Model
{
    protected $table = 'whatsapp_due_followups';

    protected $fillable = [
        'type',
        'family_id',
        'establishment_id',
        'phone',
        'sent_date',
        'template_name',
        'status',
        'error_message',
        'message_variables',
    ];

    protected $casts = [
        'family_id' => 'integer',
        'establishment_id' => 'integer',
        'sent_date' => 'date',
        'message_variables' => 'array',
    ];
}
