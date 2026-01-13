<?php

return [
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
    'api_base_url' => env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com'),
    'test_phone' => env('WHATSAPP_TEST_PHONE', ''),
    'beta_test_phone' => env('WHATSAPP_BETA_TEST_PHONE', ''),
    'due_followup_enabled' => env('WHATSAPP_DUE_FOLLOWUP_ENABLED', false),
];
