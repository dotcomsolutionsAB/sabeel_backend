<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Receipt - {{ $receiptNumber }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { height: 100%; }

        body{
            font-family:"Montserrat", sans-serif;
            background:#fff;
            color:#111;
        }

        /* Fill printable area (inside mPDF margins) */
        .receipt-card{
            width: 100%;
            height: 100%;
            border: 2px solid #111;
            border-radius: 22px;
            background: #fff;
            padding: 18px 18px 14px;
        }

        table{ width:100%; border-collapse: collapse; }

        /* Top row */
        .top td{
            font-size: 13px;
            font-weight: 800;
            padding-bottom: 10px;
        }
        .top .left{ text-align:left; }
        .top .right{ text-align:right; }

        /* Header row */
        .header td{
            vertical-align: middle;
            padding: 4px 0 6px;
        }
        .logo-cell{
            width:30%;
            text-align:center;
            padding-right: 4px;
            height:100%;
        }
        .text-cell{
            width:70%;
            text-align:center;
        }
        .logo{
            width: 100px;
            height: auto;
            display:inline-block;
        }

        .org-title{
            font-size: 24px;
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .org-address{
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .anjuman{
            font-size: 24px;
            font-weight: 900;
            text-transform: uppercase;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        /* Received From + Amount aligned */
        .single td{
            padding: 8px 0;
            font-size: 13px;
            font-weight: 700;
        }
        .single.emph td{
            font-size: 14px;
            font-weight: 900;
            line-height: 1.35;
            padding: 8px 0;
        }
        .label-col{
            width: 190px;
            white-space: nowrap;
            font-weight: 900;
            font-size:18px;
        }
        .value-col{
            font-weight: 600;
            font-size:18px;
        }

        /* Split rows */
        .split6 td{
            padding: 8px 0;
            font-size: 13px;
            vertical-align: top;
        }

        .split6 .k1{ width: 18%; font-weight:900; white-space:nowrap; }
        .split6 .s1{ width: 6%; }
        .split6 .v1{ width: 26%; font-weight:700; }

        .split6 .k2{ width: 26%; font-weight:900; white-space:nowrap; }
        .split6 .s2{ width: 6%; }
        .split6 .v2{ width: 18%; font-weight:700; }

        /* ✅ Footer like image (NOT bottom-sticky) */
        .footer{
            text-align:center;
            font-style: italic;
            font-size: 12px;
            color:#333;
            margin-top: 16px;   /* ✅ space above like image */
        }

        @media print{
            body{ padding:0; }
        }
    </style>
</head>
<body>

<div class="receipt-card">

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

    <!-- ✅ Footer positioned like screenshot -->
    <div class="footer">
        This is a computer generated receipt and does not require any signature.
    </div>

</div>

</body>
</html>
