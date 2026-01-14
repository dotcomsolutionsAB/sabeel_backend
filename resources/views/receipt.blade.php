<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Receipt - {{ $receiptNumber }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        html, body { 
            height: 100%; 
            width: 100%;
        }

        body {
            font-family: "Roboto", sans-serif;
            background: #fff;
            color: #1a1a1a;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Receipt card - fills A5 page */
        .receipt-card {
            width: 100%;
            min-height: 100%;
            border: 2.5px solid #1a1a1a;
            border-radius: 12px;
            background: #ffffff;
            padding: 22px 20px 20px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .receipt-content {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
        }

        /* Top row - Receipt Number and Date */
        .top {
            margin-bottom: 16px;
        }
        
        .top td {
            font-size: 12px;
            font-weight: 600;
            padding: 4px 0;
            color: #333;
        }
        
        .top .left { 
            text-align: left; 
        }
        
        .top .right { 
            text-align: right; 
        }
        
        .top span {
            font-weight: 700;
            color: #1a1a1a;
            letter-spacing: 0.3px;
        }

        /* Header section */
        .header {
            margin-bottom: 20px;
        }
        
        .header td {
            vertical-align: middle;
            padding: 6px 0;
        }
        
        .logo-cell {
            width: 28%;
            text-align: center;
            padding-right: 12px;
            vertical-align: middle;
        }
        
        .text-cell {
            width: 72%;
            text-align: center;
            vertical-align: middle;
        }
        
        .logo {
            width: 95px;
            height: auto;
            display: inline-block;
            max-width: 100%;
        }

        .org-title {
            font-family: "Playfair Display", serif;
            font-size: 20px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 4px;
            color: #1a1a1a;
            letter-spacing: 0.5px;
            line-height: 1.3;
        }
        
        .org-address {
            font-size: 11.5px;
            font-weight: 500;
            margin-bottom: 6px;
            color: #555;
            letter-spacing: 0.2px;
        }
        
        .anjuman {
            font-family: "Playfair Display", serif;
            font-size: 22px;
            font-weight: 900;
            text-transform: uppercase;
            text-decoration: underline;
            text-underline-offset: 4px;
            text-decoration-thickness: 2px;
            color: #1a1a1a;
            letter-spacing: 0.5px;
        }

        /* Main content area */
        .content-section {
            flex: 1;
            margin-bottom: 16px;
        }

        /* Single column rows */
        .single {
            margin-bottom: 12px;
        }
        
        .single td {
            padding: 10px 0;
            font-size: 13.5px;
            vertical-align: baseline;
        }
        
        .single.emph td {
            font-size: 15px;
            font-weight: 700;
            line-height: 1.5;
            padding: 12px 0;
        }
        
        .label-col {
            width: 200px;
            white-space: nowrap;
            font-weight: 700;
            font-size: 14.5px;
            color: #1a1a1a;
            padding-right: 8px;
            vertical-align: bottom;
            padding-bottom: 4px;
        }
        
        .value-col {
            font-weight: 500;
            font-size: 14.5px;
            color: #2c2c2c;
            word-wrap: break-word;
            line-height: 1.5;
            border-bottom: 1.8px solid #1a1a1a;
            padding-bottom: 3px;
            padding-left: 4px;
            vertical-align: bottom;
        }

        /* Split rows - two columns */
        .split6 {
            margin-bottom: 10px;
        }
        
        .split6 td {
            padding: 9px 0;
            font-size: 13px;
            vertical-align: bottom;
            line-height: 1.5;
        }

        .split6 .k1 { 
            width: 19%; 
            font-weight: 700; 
            white-space: nowrap; 
            color: #1a1a1a;
            padding-right: 4px;
            padding-bottom: 4px;
        }
        
        .split6 .s1 { 
            width: 2%; 
        }
        
        .split6 .v1 { 
            width: 29%; 
            font-weight: 500; 
            color: #2c2c2c;
            border-bottom: 1.8px solid #1a1a1a;
            padding-bottom: 3px;
            padding-left: 4px;
        }

        .split6 .k2 { 
            width: 27%; 
            font-weight: 700; 
            white-space: nowrap; 
            color: #1a1a1a;
            padding-right: 4px;
            padding-bottom: 4px;
        }
        
        .split6 .s2 { 
            width: 2%; 
        }
        
        .split6 .v2 { 
            width: 21%; 
            font-weight: 500; 
            color: #2c2c2c;
            border-bottom: 1.8px solid #1a1a1a;
            padding-bottom: 3px;
            padding-left: 4px;
        }

        /* Footer */
        .footer {
            text-align: center;
            font-style: italic;
            font-size: 11px;
            color: #666;
            margin-top: auto;
            padding-top: 16px;
            border-top: 1px solid #e0e0e0;
            font-weight: 400;
            letter-spacing: 0.2px;
        }

        @media print {
            body { 
                padding: 0; 
                margin: 0;
            }
            
            .receipt-card {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>

<div class="receipt-card">
    <div class="receipt-content">

    <!-- Receipt No + Date -->
    <table class="top">
        <tr>
            <td class="left">Receipt Number : <span style="font-weight:800;">{{ $receiptNumber }}</span></td>
            <td class="right">Date : <span style="font-weight:800;">{{ $date }}</span></td>
        </tr>
    </table>

    <!-- Header -->
    <table class="header">
        <tr>
            <td class="logo-cell">
                <img class="logo"
                     src="https://api.kolkatajamaat.com/storage/uploads/logo-DV4Ydy01.png"
                     alt="Logo"
                     onerror="this.style.display='none'"/>
            </td>
            <td class="text-cell">
                <div class="org-title">DAWOODI BOHRA JAMAAT TRUST (KOLKATA)</div>
                <div class="org-address">16/F, Dr, Syedna Mohammed Burhanuddin Road</div>
                <div class="anjuman">ANJUMAN - E - MOHAMMEDI</div>
            </td>
        </tr>
    </table>

        <!-- Main Content Section -->
        <div class="content-section">
    <!-- Received From -->
    <table class="single emph">
        <tr>
            <td class="label-col">Received From :</td>
            <td class="value-col">{{ $receivedFrom }}</td>
        </tr>
    </table>

    <!-- Amount -->
    <table class="single emph">
        <tr>
            <td class="label-col">Amount :</td>
            <td class="value-col">{{ $amount }} ( {{ $amountInWords }} )</td>
        </tr>
    </table>

    <!-- Payment Mode + Cheque/Txn -->
    <table class="split6">
        <tr>
            <td class="k1">Payment Mode :</td>
            <td class="s1"></td>
            <td class="v1">{{ $paymentMode }}</td>

            <td class="k2">Cheque No / Transaction Id :</td>
            <td class="s2"></td>
            <td class="v2">{{ $chequeNo }}</td>
        </tr>
    </table>

    <!-- Year + Bank -->
    <table class="split6">
        <tr>
            <td class="k1">For the Year :</td>
            <td class="s1"></td>
            <td class="v1">{{ $year }}</td>

            <td class="k2">Bank Name :</td>
            <td class="s2"></td>
            <td class="v2">{{ $bankName }}</td>
        </tr>
    </table>

    <!-- Received By + Dated -->
    <table class="split6">
        <tr>
            <td class="k1">Received By :</td>
            <td class="s1"></td>
            <td class="v1">{{ $receivedBy }}</td>

            <td class="k2">Dated :</td>
            <td class="s2"></td>
            <td class="v2">{{ $chequeDate }}</td>
        </tr>
    </table>
        </div>

    </div>

    <!-- Footer -->
    <div class="footer">
        This is a computer generated receipt and does not require any signature.
    </div>

</div>

</body>
</html>
