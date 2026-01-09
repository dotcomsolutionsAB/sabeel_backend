<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Receipt - {{ $receiptNumber }}</title>

    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        
        body{
            font-family: Arial, Helvetica, sans-serif;
            background:#fff;
            padding: 6px;              /* ✅ A5 */
            color:#111;
        }

        .receipt-card{
            border: 2px solid #111;
            border-radius: 22px;
            background: #fff;

            margin: 6px;               /* ✅ A5 */
            padding: 18px 18px 14px;   /* ✅ A5 */
        }
        .receipt-card{
    width: 100%;
}


        table{ width:100%; border-collapse: collapse; }

        .top td{
            font-size: 13px;           /* ✅ A5 */
            font-weight: 800;
            padding-bottom: 10px;
        }
        .top .left{ text-align:left; }
        .top .right{ text-align:right; }

        .header td{
            vertical-align: middle;
            padding: 4px 0 6px;        /* ✅ a bit tighter */
        }

        .logo-cell{
            width:30%;
            text-align:left;
            padding-right: 4px;        /* ✅ reduced gap */
        }

        .text-cell{
            width:70%;
            text-align:center;
            padding-left: 0;
        }

        .logo{
            width: 78px;               /* ✅ A5 */
            height: auto;
            display:inline-block;
        }

        .org-title{
            font-size: 18px;           /* ✅ A5 */
            font-weight: 900;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        .org-address{
            font-size: 13px;           /* ✅ A5 */
            font-weight: 700;
            margin-bottom: 6px;
        }
        .anjuman{
            font-size: 16px;           /* ✅ A5 */
            font-weight: 900;
            text-transform: uppercase;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .single td{
            padding: 8px 0;
            font-size: 13px;
            font-weight: 700;
        }

        .single.emph td{
            font-size: 14px;           /* ✅ A5 */
            font-weight: 900;
            line-height: 1.35;
            padding: 8px 0;            /* ✅ A5 */
        }

        .k{
            font-weight: 900;
            white-space: nowrap;
            padding-right: 10px;
        }
        .v{ font-weight: 700; }
        .single.emph .v{ font-weight: 900; }

        .split6 td{
            padding: 8px 0;            /* ✅ A5 */
            font-size: 13px;           /* ✅ A5 */
            vertical-align: top;
        }

        .split6 .k1{ width: 18%; font-weight:900; white-space:nowrap; }
        .split6 .s1{ width: 6%; }
        .split6 .v1{ width: 26%; font-weight:700; }

        .split6 .k2{ width: 26%; font-weight:900; white-space:nowrap; }
        .split6 .s2{ width: 6%; }
        .split6 .v2{ width: 18%; font-weight:700; }

        .footer{
            text-align:center;
            font-style: italic;
            font-size: 12px;           /* ✅ A5 */
            color:#333;
            padding-top: 12px;
        }

        @media print{
            body{ padding:0; }
        }
    </style>

</head>
<body>

<div class="receipt-card">

    <table class="top">
        <tr>
            <td class="left">Receipt Number : <span class="v">{{ $receiptNumber }}</span></td>
            <td class="right">Date : <span class="v">{{ $date }}</span></td>
        </tr>
    </table>

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

    <!-- ✅ Emphasized -->
    <table class="single emph">
        <tr>
            <td>
                <span class="k">Received From :</span>
                <span class="v">{{ $receivedFrom }}</span>
            </td>
        </tr>
    </table>

    <!-- ✅ Emphasized -->
    <table class="single emph">
        <tr>
            <td>
                <span class="k">Amount :</span>
                <span class="v">{{ $amount }} ( {{ $amountInWords }} )</span>
            </td>
        </tr>
    </table>

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

    <div class="footer">
        This is a computer generated receipt and does not require any signature.
    </div>

</div>

</body>
</html>
