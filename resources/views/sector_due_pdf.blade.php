<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sector Due Report - {{ $sector }}</title>
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            font-family: "Arial", sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            line-height: 1.4;
        }

        .header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #1a1a1a;
        }

        .header h1 {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .header .subtitle {
            font-size: 12px;
            color: #555;
            margin-bottom: 3px;
        }

        .header .info {
            font-size: 9px;
            color: #777;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .main-table {
            margin-top: 10px;
        }

        .main-table thead {
            background-color: #f0f0f0;
        }

        .main-table th {
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
            font-size: 9px;
            border: 1px solid #333;
            background-color: #e0e0e0;
        }

        .main-table td {
            padding: 5px 4px;
            font-size: 9px;
            border: 1px solid #333;
            vertical-align: top;
        }

        .main-table tbody tr {
            page-break-inside: avoid;
        }

        .family-row {
            background-color: #ffffff;
        }

        .family-row td {
            font-weight: 500;
        }

        .establishment-row {
            background-color: #e3f2fd;
        }

        .establishment-row td {
            padding-left: 20px;
            font-size: 8.5px;
            font-style: italic;
        }

        .text-left {
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .number {
            text-align: right;
            font-family: "Courier New", monospace;
        }

        .sn-col {
            width: 4%;
        }

        .its-col {
            width: 10%;
        }

        .name-col {
            width: 25%;
        }

        .mobile-col {
            width: 12%;
        }

        .hub-col {
            width: 12%;
        }

        .due-col {
            width: 12%;
        }

        .prev-due-col {
            width: 12%;
        }

        .establishment-name-col {
            width: 25%;
        }

        .footer {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #ccc;
            font-size: 8px;
            color: #666;
            text-align: center;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>Sector Due Report</h1>
        <div class="subtitle">Sector: {{ $sector }}</div>
        <div class="subtitle">Year: {{ $currentYear }}</div>
        <div class="info">Generated on: {{ $generatedDate }}</div>
    </div>

    <table class="main-table">
        <thead>
            <tr>
                <th class="sn-col text-center">SN</th>
                <th class="its-col text-left">ITS</th>
                <th class="name-col text-left">Name</th>
                <th class="mobile-col text-left">Mobile</th>
                <th class="hub-col text-right">Hub</th>
                <th class="due-col text-right">Due</th>
                <th class="prev-due-col text-right">Prev Due</th>
                <th class="text-left" style="width: 15%;">Remark</th>
            </tr>
        </thead>
        <tbody>
            @foreach($data as $row)
                <tr class="family-row">
                    <td class="text-center">{{ $row['sn'] }}</td>
                    <td class="text-left">{{ $row['its'] }}</td>
                    <td class="text-left">{{ $row['name'] }}</td>
                    <td class="text-left">{{ $row['mobile'] ?: '-' }}</td>
                    <td class="number">{{ number_format($row['hub'], 2) }}</td>
                    <td class="number">{{ number_format($row['due'], 2) }}</td>
                    <td class="number">{{ number_format($row['prev_due'], 2) }}</td>
                    <td class="text-left" style="color: #d32f2f; font-weight: 500;">{{ $row['remark'] ?? '' }}</td>
                </tr>
                @if(!empty($row['establishments']))
                    @foreach($row['establishments'] as $est)
                        <tr class="establishment-row">
                            <td class="text-center">-</td>
                            <td class="text-left">-</td>
                            <td class="text-left">{{ $est['name'] }}</td>
                            <td class="text-left">-</td>
                            <td class="number">{{ number_format($est['hub'], 2) }}</td>
                            <td class="number">{{ number_format($est['due'], 2) }}</td>
                            <td class="number">{{ number_format($est['prev_due'], 2) }}</td>
                            <td class="text-left">-</td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <div>This is a computer generated report.</div>
        <div>Total Families: {{ count($data) }}</div>
    </div>

</body>
</html>
