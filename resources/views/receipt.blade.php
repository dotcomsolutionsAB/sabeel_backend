<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - {{ $receiptNumber }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            padding: 20px;
            background-color: #fff;
        }

        .receipt-container {
            max-width: 210mm;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border: 2px solid #000;
        }

        /* Top Section - Receipt Number and Date */
        .top-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 12px;
        }

        .receipt-number {
            font-weight: bold;
        }

        .receipt-date {
            font-weight: bold;
        }

        /* Header Section with Logo */
        .header-section {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .logo {
            width: 80px;
            height: auto;
            margin-right: 20px;
            flex-shrink: 0;
        }

        .header-text {
            flex: 1;
        }

        .organization-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
            text-align: center;
        }

        .address {
            font-size: 12px;
            text-align: center;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .anjuman-name {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            text-decoration: underline;
            margin-bottom: 20px;
        }

        /* Receipt Content Box */
        .receipt-content {
            padding: 20px 0;
            margin-top: 20px;
            font-size: 13px;
        }

        .receipt-row {
            display: flex;
            margin-bottom: 15px;
            align-items: baseline;
        }

        .receipt-row.split {
            justify-content: space-between;
        }

        .field-label {
            font-weight: bold;
            margin-right: 10px;
        }

        .field-value {
            flex: 1;
            border-bottom: 1px solid #000;
            min-height: 20px;
            padding-bottom: 2px;
        }

        .half-width {
            width: 48%;
            display: flex;
            align-items: baseline;
        }

        .half-width .field-value {
            flex: 1;
        }

        .computer-generated {
            text-align: center;
            font-size: 11px;
            margin-top: 20px;
            font-style: italic;
            color: #333;
        }

        @media print {
            body {
                padding: 0;
            }
            
            .receipt-container {
                padding: 10mm;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        
        <!-- Top Section: Receipt Number and Date -->
        <div class="top-section">
            <div class="receipt-number">{{ $receiptNumber }}</div>
            <div class="receipt-date">{{ $date }}</div>
        </div>

        <!-- Header Section -->
        <div class="header-section">
            <img src="https://api.kolkatajamaat.com/storage/uploads/logo-DV4Ydy01.png" 
                 alt="Logo" 
                 class="logo"
                 onerror="this.style.display='none'">
            
            <div class="header-text">
                <div class="organization-name">
                    Dawoodi Bohra Jamaat (Kolkata)
                </div>
                
                <div class="address">
                    16/F, Dr. Syedna Mohammed Burhanuddin Road
                </div>
                
                <div class="anjuman-name">
                    Anjuman-e-Ezzi Mohammedi
                </div>
            </div>
        </div>

        <!-- Receipt Content -->
        <div class="receipt-content">
            
            <!-- Received From -->
            <div class="receipt-row">
                <span class="field-label">Received From:</span>
                <span class="field-value">{{ $receivedFrom }}</span>
            </div>

            <!-- Amount -->
            <div class="receipt-row">
                <span class="field-label">Amount:</span>
                <span class="field-value">{{ $amount }} ({{ $amountInWords }})</span>
            </div>

            <!-- Payment Mode and Cheque/Transaction -->
            <div class="receipt-row split">
                <div class="half-width">
                    <span class="field-label">Payment Mode:</span>
                    <span class="field-value">{{ $paymentMode }}</span>
                </div>
                <div class="half-width">
                    <span class="field-label">Cheque No/Transaction Id:</span>
                    <span class="field-value">{{ $chequeNo }}</span>
                </div>
            </div>

            <!-- For the Year and Bank Name -->
            <div class="receipt-row split">
                <div class="half-width">
                    <span class="field-label">For the Year:</span>
                    <span class="field-value">{{ $year }}</span>
                </div>
                <div class="half-width">
                    <span class="field-label">Bank Name:</span>
                    <span class="field-value">{{ $bankName }}</span>
                </div>
            </div>

            <!-- Received By and Dated -->
            <div class="receipt-row split">
                <div class="half-width">
                    <span class="field-label">Received By:</span>
                    <span class="field-value">{{ $receivedBy }}</span>
                </div>
                <div class="half-width">
                    <span class="field-label">Dated:</span>
                    <span class="field-value">{{ $chequeDate }}</span>
                </div>
            </div>

            <!-- Computer Generated Text -->
            <div class="computer-generated">
                This is a computer generated receipt and does not require any signature.
            </div>

        </div>

    </div>
</body>
</html>