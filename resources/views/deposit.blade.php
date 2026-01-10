<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Deposit - {{ $deposit->deposit_id }}</title>
    <style>
        *{ box-sizing:border-box; }
        body{
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color:#111;
        }

        .card{
            border: 1px solid #111;
            padding: 14px;
        }

        .title{
            text-align:center;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .meta{
            width:100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .meta td{
            font-size: 12px;
            padding: 4px 0;
        }
        .meta .left{ text-align:left; font-weight:700; }
        .meta .right{ text-align:right; font-weight:700; }

        table.report{
            width:100%;
            border-collapse: collapse;
        }
        table.report th,
        table.report td{
            border: 1px solid #111;
            padding: 6px 6px;
            vertical-align: top;
        }
        table.report th{
            text-align:left;
            font-weight:700;
            background:#f2f2f2;
        }

        .num{ text-align:right; white-space:nowrap; }
        .center{ text-align:center; }
        .nowrap{ white-space:nowrap; }

        .total-row td{
            font-weight:700;
        }
    </style>
</head>
<body>

<div class="card">
    <div class="title">Sabeel ul Khair e wal Barakat</div>

    <table class="meta">
        <tr>
            <td class="left">Deposit ID : {{ $deposit->deposit_id }}</td>
            <td class="right">Date : {{ $deposit_date }}</td>
        </tr>
        <tr>
            <td class="left">
                @if(!empty($remarks))
                    <div style="margin-top:4px; font-weight:400;">
                        Remarks : {{ $remarks }}
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <table class="report">
        <thead>
            <tr>
                <th style="width:40px;">SL</th>
                <th style="width:120px;">Receipt No</th>
                <th style="width:90px;">Date</th>
                <th>Name</th>
                <th style="width:100px;">Amount</th>
                <th style="width:90px;">Mode</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $i => $r)
                <tr>
                    <td class="center">{{ $i+1 }}</td>
                    <td class="nowrap">{{ $r['receipt_no'] }}</td>
                    <td class="nowrap">{{ $r['date'] }}</td>
                    <td>{{ $r['name'] }}</td>
                    <td class="num">{{ $r['amount'] }}</td>
                    <td class="nowrap">{{ $r['mode'] }}</td>
                </tr>
            @endforeach

            <tr class="total-row">
                <td colspan="4" class="num">Total</td>
                <td class="num">{{ $total_amount }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</div>

</body>
</html>
