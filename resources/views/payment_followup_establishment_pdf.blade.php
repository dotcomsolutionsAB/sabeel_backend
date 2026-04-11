<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>{{ $title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 8px;
            color: #111;
            line-height: 1.35;
        }
        .doc-title {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 6px;
        }
        .meta {
            text-align: center;
            font-size: 8px;
            color: #444;
            margin-bottom: 10px;
        }
        table.main {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        table.main th {
            background: #e8e8e8;
            border: 1px solid #333;
            padding: 5px 4px;
            text-align: left;
            font-size: 7.5px;
            vertical-align: top;
        }
        table.main td {
            border: 1px solid #333;
            padding: 4px;
            vertical-align: top;
            font-size: 7.5px;
        }
        .col-name { width: 18%; }
        .col-hub { width: 8%; text-align: right; }
        .col-paid { width: 9%; text-align: right; }
        .col-due { width: 28%; }
        .col-last { width: 37%; font-size: 7px; }
        tr.est-row td {
            background: #f0f7ff;
            font-weight: bold;
        }
        tr.partner-row td {
            padding-left: 12px;
            background: #fafafa;
        }
        tr.partner-row .col-name { font-style: italic; font-weight: normal; }
        .due-cell { white-space: pre-line; }
        .section-title {
            margin: 16px 0 6px 0;
            font-size: 10px;
            font-weight: bold;
            border-bottom: 2px solid #333;
            padding-bottom: 3px;
        }
        tr.untagged-header td {
            background: #fff8e6;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="doc-title">{{ $title }}</div>
    <div class="meta">Current year (hub column): {{ $currentYear }}</div>

    <table class="main">
        <thead>
            <tr>
                <th class="col-name">Establishment / Partner</th>
                <th class="col-hub">Hub ({{ $currentYear }})</th>
                <th class="col-paid">Paid ({{ $currentYear }})</th>
                <th class="col-due">Due by year</th>
                <th class="col-last">Last payment (amount | date | mode)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($blocks as $block)
                <tr class="est-row">
                    <td class="col-name">{{ $block['establishment_name'] }}</td>
                    <td class="col-hub">{{ number_format($block['hub']) }}</td>
                    <td class="col-paid">{{ number_format($block['paid'], 2) }}</td>
                    <td class="col-due due-cell">{{ $block['due_lines'] }}</td>
                    <td class="col-last">{{ $block['last_pay'] }}</td>
                </tr>
                @foreach ($block['partners'] as $p)
                    <tr class="partner-row">
                        <td class="col-name">{{ $p['label'] }}</td>
                        <td class="col-hub">{{ number_format($p['hub']) }}</td>
                        <td class="col-paid">{{ number_format($p['paid'], 2) }}</td>
                        <td class="col-due due-cell">{{ $p['due_lines'] }}</td>
                        <td class="col-last">{{ $p['last_pay'] }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="section-title">Families not linked to any establishment</div>
    <table class="main">
        <thead>
            <tr>
                <th class="col-name">Family (HOF)</th>
                <th class="col-hub">Hub ({{ $currentYear }})</th>
                <th class="col-paid">Paid ({{ $currentYear }})</th>
                <th class="col-due">Due by year</th>
                <th class="col-last">Last payment (amount | date | mode)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($untagged as $u)
                <tr>
                    <td class="col-name">{{ $u['label'] }}</td>
                    <td class="col-hub">{{ number_format($u['hub']) }}</td>
                    <td class="col-paid">{{ number_format($u['paid'], 2) }}</td>
                    <td class="col-due due-cell">{{ $u['due_lines'] }}</td>
                    <td class="col-last">{{ $u['last_pay'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align:center;padding:8px;">No untagged families.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
