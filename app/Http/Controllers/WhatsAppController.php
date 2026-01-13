<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use App\Models\ReceiptModel;
use App\Models\MumineenModel;
use App\Models\EstablishmentModel;
use App\Models\MumineenEstablishmentModel;
use App\Models\WhatsAppCampaignLogModel;
use Mpdf\Mpdf;

class WhatsAppController extends Controller
{
    use ApiResponse;

    /**
     * Main method to send receipt notifications via WhatsApp
     * 
     * @param array $receiptIds Array of receipt IDs
     * @param string $type 'family' or 'establishment'
     * @param int|null $familyId
     * @param int|null $establishmentId
     * @return array
     */
    public function sendReceipt(array $receiptIds, string $type, ?int $familyId, ?int $establishmentId): array
    {
        try {
            Log::info('WhatsApp sendReceipt called', [
                'receipt_ids' => $receiptIds,
                'type' => $type,
                'family_id' => $familyId,
                'establishment_id' => $establishmentId,
            ]);

            if (empty($receiptIds)) {
                Log::warning('WhatsApp sendReceipt: No receipt IDs provided');
                return ['success' => false, 'message' => 'No receipts to send'];
            }

            // Get receipts
            $receipts = ReceiptModel::whereIn('id', $receiptIds)->get();
            if ($receipts->isEmpty()) {
                Log::warning('WhatsApp sendReceipt: Receipts not found in database', [
                    'receipt_ids' => $receiptIds,
                ]);
                return ['success' => false, 'message' => 'Receipts not found'];
            }

            Log::info('WhatsApp sendReceipt: Found receipts', [
                'count' => $receipts->count(),
            ]);

            // Determine if multiple receipts (should be clubbed)
            $isMultipleReceipts = count($receiptIds) > 1;

            // Generate PDF(s)
            $pdfPaths = [];
            $tempPdfPaths = []; // Track individual PDFs for cleanup

            if ($isMultipleReceipts) {
                // Combine all receipts into single PDF
                $combinedPdfPath = $this->combineReceiptPdfs($receiptIds);
                if ($combinedPdfPath) {
                    $pdfPaths[] = $combinedPdfPath;
                }
            } else {
                // Single receipt PDF
                $pdfPath = $this->generateReceiptPdf($receiptIds[0]);
                if ($pdfPath) {
                    $pdfPaths[] = $pdfPath;
                }
            }

            // Get recipients
            $recipients = $this->getRecipients($type, $familyId, $establishmentId);
            Log::info('WhatsApp sendReceipt: Recipients found', [
                'count' => count($recipients),
                'recipients' => $recipients,
            ]);
            
            if (empty($recipients)) {
                // Cleanup PDFs if no recipients
                $this->cleanupPdfFiles($pdfPaths);
                Log::warning('WhatsApp sendReceipt: No recipients found', [
                    'type' => $type,
                    'family_id' => $familyId,
                    'establishment_id' => $establishmentId,
                ]);
                return ['success' => false, 'message' => 'No recipients found'];
            }

            // Create campaign log
            $campaignLog = WhatsAppCampaignLogModel::create([
                'campaign_name' => 'sabeel_receipt',
                'template_name' => 'sabeel_receipt',
                'recipient_count' => count($recipients),
                'success_count' => 0,
                'failure_count' => 0,
                'recipient_details' => [],
                'message_variables' => [],
                'pdf_path' => $isMultipleReceipts ? ($pdfPaths[0] ?? null) : null,
                'receipt_ids' => $receiptIds,
                'type' => $type,
                'family_id' => $familyId,
                'establishment_id' => $establishmentId,
                'status' => 'processing',
            ]);

            $recipientDetails = [];
            $successCount = 0;
            $failureCount = 0;
            $totalAmount = $receipts->sum('amount');

            // Send to each recipient
            foreach ($recipients as $recipient) {
                $recipientDetail = [
                    'phone' => $recipient['phone'],
                    'name' => $recipient['name'],
                    'status' => 'pending',
                    'error_message' => null,
                    'sent_at' => null,
                ];

                try {
                    // Format message variables (for multiple receipts, use first receipt but sum amounts)
                    $firstReceipt = $receipts->first();
                    $variables = $this->formatMessageVariables(
                        $firstReceipt,
                        $type,
                        $familyId,
                        $establishmentId,
                        $recipient,
                        $receipts // Pass all receipts for amount calculation
                    );

                    // Send message (use first PDF path for all recipients if multiple receipts)
                    $pdfPath = $pdfPaths[0] ?? null;
                    $result = $this->sendTemplateMessage(
                        $recipient['phone'],
                        'sabeel_receipt',
                        $variables,
                        $pdfPath
                    );

                    if ($result['success']) {
                        $recipientDetail['status'] = 'success';
                        $recipientDetail['sent_at'] = now()->toDateTimeString();
                        $successCount++;
                    } else {
                        $recipientDetail['status'] = 'failed';
                        $recipientDetail['error_message'] = $result['error'] ?? 'Unknown error';
                        $failureCount++;
                    }
                } catch (\Throwable $e) {
                    $recipientDetail['status'] = 'failed';
                    $recipientDetail['error_message'] = $e->getMessage();
                    $failureCount++;
                    Log::error('WhatsApp send failed for recipient', [
                        'recipient' => $recipient,
                        'error' => $e->getMessage(),
                    ]);
                }

                $recipientDetails[] = $recipientDetail;
            }

            // Update campaign log
            $campaignLog->update([
                'success_count' => $successCount,
                'failure_count' => $failureCount,
                'recipient_details' => $recipientDetails,
                'message_variables' => $this->formatMessageVariables(
                    $receipts->first(),
                    $type,
                    $familyId,
                    $establishmentId,
                    $recipients[0] ?? [],
                    $receipts
                ),
                'status' => ($failureCount === 0) ? 'completed' : (($successCount > 0) ? 'completed' : 'failed'),
            ]);

            // Cleanup PDF files after sending
            $this->cleanupPdfFiles($pdfPaths);

            return [
                'success' => true,
                'campaign_log_id' => $campaignLog->id,
                'recipients_sent' => $successCount,
                'recipients_failed' => $failureCount,
            ];

        } catch (\Throwable $e) {
            Log::error('WhatsApp sendReceipt failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Cleanup PDFs on error
            if (isset($pdfPaths)) {
                $this->cleanupPdfFiles($pdfPaths);
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send WhatsApp template message via Meta API
     * 
     * @param string $to Phone number (with country code, e.g., 919876543210)
     * @param string $templateName Template name
     * @param array $variables Template variables
     * @param string|null $pdfPath Path to PDF file for attachment
     * @return array
     */
    private function sendTemplateMessage(string $to, string $templateName, array $variables, ?string $pdfPath = null): array
    {
        try {
            $phoneNumberId = config('whatsapp.phone_number_id');
            $accessToken = config('whatsapp.access_token');
            $apiVersion = config('whatsapp.api_version');
            $baseUrl = config('whatsapp.api_base_url');

            Log::info('WhatsApp sendTemplateMessage called', [
                'to' => $to,
                'template_name' => $templateName,
                'has_pdf' => !empty($pdfPath),
            ]);

            if (!$phoneNumberId || !$accessToken) {
                Log::error('WhatsApp configuration missing', [
                    'phone_number_id_set' => !empty($phoneNumberId),
                    'access_token_set' => !empty($accessToken),
                ]);
                return [
                    'success' => false,
                    'error' => 'WhatsApp configuration missing',
                ];
            }

            // Format phone number (ensure it starts with country code, no +)
            $to = $this->formatPhoneNumber($to);

            // Build message payload
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => 'en',
                    ],
                    'components' => [],
                ],
            ];

            // Add text parameters
            if (!empty($variables)) {
                $textParams = [];
                foreach ($variables as $key => $value) {
                    $textParams[] = [
                        'type' => 'text',
                        'text' => (string) $value,
                    ];
                }
                if (!empty($textParams)) {
                    $payload['template']['components'][] = [
                        'type' => 'body',
                        'parameters' => $textParams,
                    ];
                }
            }

            // Add PDF document attachment if provided
            if ($pdfPath) {
                // Upload media to Meta API first to get media ID
                $mediaId = $this->uploadMedia($pdfPath, $accessToken, $apiVersion, $baseUrl);
                
                if ($mediaId) {
                    $payload['template']['components'][] = [
                        'type' => 'header',
                        'parameters' => [
                            [
                                'type' => 'document',
                                'document' => [
                                    'id' => $mediaId,
                                    'filename' => basename($pdfPath),
                                ],
                            ],
                        ],
                    ];
                } else {
                    Log::warning('Failed to upload PDF media for WhatsApp', ['pdf_path' => $pdfPath]);
                }
            }

            // Make API call
            $endpoint = "/{$apiVersion}/{$phoneNumberId}/messages";
            $response = $this->callMetaAPI($endpoint, $payload, $accessToken, $baseUrl);

            if (isset($response['messages']) && !empty($response['messages'])) {
                return [
                    'success' => true,
                    'message_id' => $response['messages'][0]['id'] ?? null,
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $response['error']['message'] ?? 'Unknown error',
                ];
            }

        } catch (\Throwable $e) {
            Log::error('WhatsApp sendTemplateMessage failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload media file to Meta API and get media ID
     * 
     * @param string $filePath Path to file
     * @param string $accessToken
     * $param string $apiVersion
     * @param string $baseUrl
     * @return string|null Media ID
     */
    private function uploadMedia(string $filePath, string $accessToken, string $apiVersion, string $baseUrl): ?string
    {
        try {
            $fullPath = Storage::disk('public')->path($filePath);
            
            if (!file_exists($fullPath)) {
                Log::error('PDF file not found for upload', ['path' => $fullPath]);
                return null;
            }

            $mediaType = 'application/pdf';
            $fileName = basename($filePath);

            // Upload media
            $uploadUrl = "{$baseUrl}/{$apiVersion}/" . config('whatsapp.phone_number_id') . "/media";
            
            $response = Http::withToken($accessToken)
                ->attach('file', file_get_contents($fullPath), $fileName, [
                    'Content-Type' => $mediaType,
                ])
                ->post($uploadUrl, [
                    'messaging_product' => 'whatsapp',
                    'type' => $mediaType,
                ]);

            if ($response->successful() && isset($response->json()['id'])) {
                return $response->json()['id'];
            } else {
                Log::error('Media upload failed', [
                    'response' => $response->json(),
                ]);
                return null;
            }

        } catch (\Throwable $e) {
            Log::error('Media upload exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Generate PDF for a single receipt
     * 
     * @param int $receiptId
     * @return string|null File path
     */
    private function generateReceiptPdf(int $receiptId): ?string
    {
        try {
            $receipt = ReceiptModel::find($receiptId);
            if (!$receipt) {
                return null;
            }

            // Prepare data for blade template
            $data = [
                'receiptNumber' => $receipt->receipt_no,
                'date' => date('d-m-Y', strtotime($receipt->date)),
                'receivedFrom' => $receipt->name,
                'itsNumber' => $receipt->its,
                'amount' => 'Rs. ' . number_format($receipt->amount, 2),
                'amountInWords' => $this->convertNumberToWords($receipt->amount),
                'paymentMode' => ucfirst($receipt->mode),
                'chequeNo' => $receipt->mode === 'cheque' ? $receipt->cheque_no : $receipt->transaction_no,
                'year' => $receipt->year,
                'bankName' => $receipt->bank ?? '',
                'receivedBy' => strtoupper($receipt->establishment_id ?? 'ADMINISTRATION'),
                'chequeDate' => $receipt->mode === 'cheque' 
                    ? date('d-m-Y', strtotime($receipt->cheque_date)) 
                    : date('d-m-Y', strtotime($receipt->transaction_date)),
            ];

            // Render blade view to HTML
            $html = view('receipt', $data)->render();

            // Initialize mPDF
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => [210, 148],  // A5 Landscape
                'margin_left' => 6.5,
                'margin_right' => 6.5,
                'margin_top' => 5,
                'margin_bottom' => 5,
            ]);

            // Write HTML to PDF
            $mpdf->WriteHTML($html);

            // Create filename
            $filename = 'receipt_' . $receipt->receipt_no . '_' . time() . '.pdf';
            
            // Ensure directory exists
            $directory = 'uploads/receipt/whatsapp';
            if (!Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory, 0755, true);
            }

            // Save PDF to storage
            $path = $directory . '/' . $filename;
            $pdfOutput = $mpdf->Output('', 'S');
            Storage::disk('public')->put($path, $pdfOutput);

            return $path;

        } catch (\Throwable $e) {
            Log::error('PDF generation failed', [
                'receipt_id' => $receiptId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Combine multiple receipt PDFs into single document
     * 
     * @param array $receiptIds
     * @return string|null Combined PDF file path
     */
    private function combineReceiptPdfs(array $receiptIds): ?string
    {
        try {
            // Get all receipts
            $receipts = ReceiptModel::whereIn('id', $receiptIds)->orderBy('id')->get();
            
            if ($receipts->isEmpty()) {
                return null;
            }

            // Create a new PDF and add each receipt as HTML pages
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => [210, 148],  // A5 Landscape
                'margin_left' => 6.5,
                'margin_right' => 6.5,
                'margin_top' => 5,
                'margin_bottom' => 5,
            ]);

            foreach ($receipts as $index => $receipt) {
                if ($index > 0) {
                    $mpdf->AddPage();
                }

                $data = [
                    'receiptNumber' => $receipt->receipt_no,
                    'date' => date('d-m-Y', strtotime($receipt->date)),
                    'receivedFrom' => $receipt->name,
                    'itsNumber' => $receipt->its,
                    'amount' => 'Rs. ' . number_format($receipt->amount, 2),
                    'amountInWords' => $this->convertNumberToWords($receipt->amount),
                    'paymentMode' => ucfirst($receipt->mode),
                    'chequeNo' => $receipt->mode === 'cheque' ? $receipt->cheque_no : $receipt->transaction_no,
                    'year' => $receipt->year,
                    'bankName' => $receipt->bank ?? '',
                    'receivedBy' => strtoupper($receipt->establishment_id ?? 'ADMINISTRATION'),
                    'chequeDate' => $receipt->mode === 'cheque' 
                        ? date('d-m-Y', strtotime($receipt->cheque_date)) 
                        : date('d-m-Y', strtotime($receipt->transaction_date)),
                ];

                $html = view('receipt', $data)->render();
                $mpdf->WriteHTML($html);
            }

            // Save combined PDF
            $filename = 'combined_receipts_' . time() . '.pdf';
            $directory = 'uploads/receipt/whatsapp';
            if (!Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory, 0755, true);
            }

            $path = $directory . '/' . $filename;
            $pdfOutput = $mpdf->Output('', 'S');
            Storage::disk('public')->put($path, $pdfOutput);

            return $path;

        } catch (\Throwable $e) {
            Log::error('PDF combination failed', [
                'receipt_ids' => $receiptIds,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Format message variables for WhatsApp template
     * 
     * @param ReceiptModel $receipt First receipt (for reference)
     * @param string $type
     * @param int|null $familyId
     * @param int|null $establishmentId
     * @param array $recipient Recipient info (for establishment partners)
     * @param \Illuminate\Database\Eloquent\Collection|null $allReceipts All receipts (for amount calculation)
     * @return array
     */
    private function formatMessageVariables(ReceiptModel $receipt, string $type, ?int $familyId, ?int $establishmentId, array $recipient = [], $allReceipts = null): array
    {
        $variables = [];

        if ($type === 'family') {
            // Get HOF
            $hof = MumineenModel::where('family_id', $familyId)
                ->where('hof_type', 'HOF')
                ->where('status', 'active')
                ->first();

            $variables['name'] = $hof->name ?? $receipt->name;
            $variables['its'] = $hof->its ? "ITS : {$hof->its}" : '';
            
            // Calculate total amount (sum of all receipts if multiple)
            $totalAmount = $allReceipts ? $allReceipts->sum('amount') : $receipt->amount;
            $variables['amount'] = 'Rs. ' . number_format($totalAmount, 2);

        } else { // establishment
            // For establishment, use recipient name (partner name)
            $partnerName = $recipient['name'] ?? $receipt->name;
            
            // Get establishment name
            $establishment = EstablishmentModel::where('establishment_id', $establishmentId)->first();
            $estName = $establishment->name ?? '';

            $variables['name'] = $partnerName;
            $variables['establishment'] = $estName ? "c/o {$estName}" : '';
            
            // Calculate total amount (sum of all receipts if multiple)
            $totalAmount = $allReceipts ? $allReceipts->sum('amount') : $receipt->amount;
            $variables['amount'] = 'Rs. ' . number_format($totalAmount, 2);
        }

        return $variables;
    }

    /**
     * Get recipients for WhatsApp messages
     * 
     * @param string $type
     * @param int|null $familyId
     * @param int|null $establishmentId
     * @return array Array of recipients with 'phone' and 'name'
     */
    private function getRecipients(string $type, ?int $familyId, ?int $establishmentId): array
    {
        $recipients = [];

        if ($type === 'family') {
            // Get HOF
            $hof = MumineenModel::where('family_id', $familyId)
                ->where('hof_type', 'HOF')
                ->where('status', 'active')
                ->first();

            if ($hof && $hof->mobile) {
                $recipients[] = [
                    'phone' => $hof->mobile,
                    'name' => $hof->name,
                ];
            }

        } else { // establishment
            // Get all partners linked to establishment
            $links = MumineenEstablishmentModel::where('establishment_id', $establishmentId)->get();
            
            foreach ($links as $link) {
                // Get HOF for each partner family
                $hof = MumineenModel::where('family_id', $link->family_id)
                    ->where('hof_type', 'HOF')
                    ->where('status', 'active')
                    ->first();

                if ($hof && $hof->mobile) {
                    $recipients[] = [
                        'phone' => $hof->mobile,
                        'name' => $hof->name,
                    ];
                }
            }
        }

        return $recipients;
    }

    /**
     * Call Meta WhatsApp Business API
     * 
     * @param string $endpoint API endpoint
     * @param array $payload Request payload
     * @param string $accessToken
     * @param string $baseUrl
     * @return array API response
     */
    private function callMetaAPI(string $endpoint, array $payload, string $accessToken, string $baseUrl): array
    {
        try {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, $payload);

            if ($response->successful()) {
                return $response->json();
            } else {
                Log::error('Meta API call failed', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);
                return $response->json() ?? ['error' => ['message' => 'API call failed']];
            }

        } catch (\Throwable $e) {
            Log::error('Meta API call exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return ['error' => ['message' => $e->getMessage()]];
        }
    }

    /**
     * Cleanup PDF files from storage
     * 
     * @param array $pdfPaths Array of file paths to delete
     * @return void
     */
    private function cleanupPdfFiles(array $pdfPaths): void
    {
        foreach ($pdfPaths as $path) {
            try {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to delete PDF file', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Format phone number for WhatsApp (ensure country code, no +)
     * 
     * @param string $phone
     * @return string
     */
    private function formatPhoneNumber(string $phone): string
    {
        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);
        
        // If starts with 0, remove it
        if (substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }
        
        // If doesn't start with country code (91 for India), add it
        if (strlen($phone) === 10) {
            $phone = '91' . $phone; // Default to India country code
        }
        
        return $phone;
    }

    /**
     * Convert number to words (Indian format)
     * Copied from ReceiptController
     * 
     * @param float $number
     * @return string
     */
    private function convertNumberToWords($number)
    {
        $amount = number_format($number, 2, '.', '');
        list($rupees, $paise) = explode('.', $amount);
        
        $words = $this->numberToWords((int)$rupees);
        
        if ((int)$paise > 0) {
            $paiseWords = $this->numberToWords((int)$paise);
            return "Rupees " . ucwords($words) . " and " . ucwords($paiseWords) . " Paise Only";
        }
        
        return "Rupees " . ucwords($words) . " Only";
    }

    /**
     * Helper function to convert number to words
     * 
     * @param int $number
     * @return string
     */
    private function numberToWords($number)
    {
        $ones = array(
            0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
            5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
            10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
            14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
            18 => 'Eighteen', 19 => 'Nineteen'
        );

        $tens = array(
            2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
            6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
        );

        if ($number < 20) {
            return $ones[$number];
        }

        if ($number < 100) {
            return $tens[intval($number / 10)] . ' ' . $ones[$number % 10];
        }

        if ($number < 1000) {
            $hundreds = intval($number / 100);
            $remainder = $number % 100;
            $result = $ones[$hundreds] . ' Hundred';
            if ($remainder > 0) {
                $result .= ' ' . $this->numberToWords($remainder);
            }
            return $result;
        }

        if ($number < 100000) {
            $thousands = intval($number / 1000);
            $remainder = $number % 1000;
            $result = $this->numberToWords($thousands) . ' Thousand';
            if ($remainder > 0) {
                $result .= ' ' . $this->numberToWords($remainder);
            }
            return $result;
        }

        if ($number < 10000000) {
            $lakhs = intval($number / 100000);
            $remainder = $number % 100000;
            $result = $this->numberToWords($lakhs) . ' Lakh';
            if ($remainder > 0) {
                $result .= ' ' . $this->numberToWords($remainder);
            }
            return $result;
        }

        $crores = intval($number / 10000000);
        $remainder = $number % 10000000;
        $result = $this->numberToWords($crores) . ' Crore';
        if ($remainder > 0) {
            $result .= ' ' . $this->numberToWords($remainder);
        }
        return $result;
    }
}
