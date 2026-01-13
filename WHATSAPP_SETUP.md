# WhatsApp Meta API Integration Setup

## Environment Variables

Add the following variables to your `.env` file:

```env
# WhatsApp Meta Business API Configuration
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
WHATSAPP_ACCESS_TOKEN=your_access_token
WHATSAPP_BUSINESS_ACCOUNT_ID=your_business_account_id
WHATSAPP_API_VERSION=v21.0
WHATSAPP_API_BASE_URL=https://graph.facebook.com
```

## Configuration Details

### Required Variables

1. **WHATSAPP_PHONE_NUMBER_ID**: Your WhatsApp Business Phone Number ID from Meta Business Manager
   - Found in: Meta Business Manager > WhatsApp > Phone Numbers > Select your number > Phone Number ID

2. **WHATSAPP_ACCESS_TOKEN**: Your permanent or temporary access token
   - Found in: Meta Business Manager > WhatsApp > API Setup > Temporary token (or create permanent token)
   - For production, use a permanent token with appropriate permissions

3. **WHATSAPP_BUSINESS_ACCOUNT_ID**: Your WhatsApp Business Account ID (optional, for logging/reporting)
   - Found in: Meta Business Manager > Business Settings > Accounts > WhatsApp Accounts

4. **WHATSAPP_API_VERSION**: Meta API version (default: v21.0)
   - Check latest version at: https://developers.facebook.com/docs/whatsapp/cloud-api

5. **WHATSAPP_API_BASE_URL**: Meta API base URL (default: https://graph.facebook.com)
   - Usually doesn't need to be changed

## WhatsApp Template Setup

Before using this integration, you must:

1. **Create a WhatsApp Template** in Meta Business Manager:
   - Go to: Meta Business Manager > WhatsApp > Message Templates
   - Create a template named: `sabeel_receipt`
   - Template must have 3 text variables:
     - Variable 1: `name` (recipient name)
     - Variable 2: `its` or `establishment` (ITS number or establishment name)
     - Variable 3: `amount` (receipt amount)
   - Template must support document header attachment

2. **Get Template Approved**:
   - Submit template for approval
   - Wait for Meta approval (usually 24-48 hours)

## How It Works

1. When a receipt is created via `/receipt/create` endpoint, the system automatically:
   - Generates PDF(s) for the receipt(s)
   - Determines recipients (HOF for family, all partners for establishment)
   - Sends WhatsApp message with PDF attachment
   - Logs the campaign in `whatsapp_campaign_logs` table
   - Cleans up PDF files after sending

2. **For Multiple Receipts (Cash Payments)**:
   - All receipts are combined into a single PDF
   - Single message sent to recipient(s) with combined PDF
   - For establishments: Each partner receives the combined PDF individually

3. **PDF Cleanup**:
   - All PDF files are automatically deleted after sending (success or failure)
   - Prevents server storage bloat

## Testing

1. Ensure all environment variables are set
2. Create a test receipt via API
3. Check `whatsapp_campaign_logs` table for campaign status
4. Verify message received on WhatsApp

## Troubleshooting

- **"WhatsApp configuration missing"**: Check that all required env variables are set
- **"No recipients found"**: Verify family/establishment has valid mobile numbers
- **API errors**: Check Meta API status and token validity
- **PDF not attached**: Verify template supports document header and media upload succeeded
