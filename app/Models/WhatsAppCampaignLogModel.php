<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppCampaignLogModel extends Model
{
    protected $table = 'whatsapp_campaign_logs';

    protected $fillable = [
        'campaign_name',
        'template_name',
        'recipient_count',
        'success_count',
        'failure_count',
        'recipient_details',
        'message_variables',
        'pdf_path',
        'receipt_ids',
        'type',
        'family_id',
        'establishment_id',
        'status',
        'error_log',
    ];

    protected $casts = [
        'recipient_count' => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
        'recipient_details' => 'array',
        'message_variables' => 'array',
        'receipt_ids' => 'array',
        'family_id' => 'integer',
        'establishment_id' => 'integer',
    ];
}
