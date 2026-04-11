<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>{{ $title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            color: #111;
            line-height: 1.35;
        }
        .doc-title {
            text-align: center;
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .meta {
            text-align: center;
            font-size: 10px;
            color: #444;
            margin-bottom: 12px;
        }
        table.main {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            table-layout: fixed;
        }
        table.main th {
            background: #e8e8e8;
            border: 1px solid #333;
            padding: 6px 3px;
            text-align: left;
            font-size: 9px;
            font-weight: bold;
            vertical-align: bottom;
            word-wrap: break-word;
        }
        table.main td {
            border: 1px solid #333;
            padding: 5px 3px;
            vertical-align: top;
            font-size: 9.5px;
        }
        .col-sn {
            width: 3.5%;
            text-align: center;
        }
        .col-name { width: 14%; }
        .col-hub { width: 6.5%; text-align: right; }
        .col-year {
            text-align: right;
            font-size: 8.5px;
            padding: 4px 2px !important;
        }
        .col-last {
            width: 7%;
            font-size: 7.5px;
            line-height: 1.25;
            white-space: pre-line;
            word-wrap: break-word;
        }
        th.col-sn, th.col-hub, th.col-year { text-align: center; }
        th.col-year { font-size: 8px; }
        tr.est-row td {
            background: #f0f7ff;
            font-weight: bold;
        }
        tr.partner-row td {
            padding-left: 8px;
            background: #fafafa;
            font-weight: normal;
        }
        tr.partner-row .col-name { font-style: italic; }
        .section-title {
            margin: 18px 0 8px 0;
            font-size: 12px;
            font-weight: bold;
            border-bottom: 2px solid #333;
            padding-bottom: 4px;
        }
    </style>
</head>
<body>
    <div class="doc-title">{{ $title }}</div>
    <div class="meta">Current year (hub column): {{ $currentYear }}</div>

    @php $rowSn = 1; @endphp
    <table class="main">
        <thead>
            <tr>
                <th class="col-sn">SN</th>
                <th class="col-name">Establishment / Partner</th>
                <th class="col-hub">Hub<br/>({{ $currentYear }})</th>
                @foreach ($reportYears as $yr)
                    <th class="col-year">Due<br/>{{ $yr }}</th>
                @endforeach
                <th class="col-last">Last payment<br/><span style="font-weight:normal;font-size:7px;">Amt / date / mode</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($blocks as $block)
                <tr class="est-row">
                    <td class="col-sn">{{ $rowSn++ }}</td>
                    <td class="col-name">{{ $block['establishment_name'] }}</td>
                    <td class="col-hub">{{ number_format($block['hub']) }}</td>
                    @foreach ($reportYears as $yr)
                        <td class="col-year">{{ $block['due_by_year'][$yr] ?? '—' }}</td>
                    @endforeach
                    <td class="col-last">{!! nl2br(e($block['last_pay'])) !!}</td>
                </tr>
                @foreach ($block['partners'] as $p)
                    <tr class="partner-row">
                        <td class="col-sn">{{ $rowSn++ }}</td>
                        <td class="col-name">{{ $p['label'] }}</td>
                        <td class="col-hub">{{ number_format($p['hub']) }}</td>
                        @foreach ($reportYears as $yr)
                            <td class="col-year">{{ $p['due_by_year'][$yr] ?? '—' }}</td>
                        @endforeach
                        <td class="col-last">{!! nl2br(e($p['last_pay'])) !!}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="section-title">Families not linked to any establishment</div>

    @php $rowSn2 = 1; @endphp
    <table class="main">
        <thead>
            <tr>
                <th class="col-sn">SN</th>
                <th class="col-name">Family (HOF)</th>
                <th class="col-hub">Hub<br/>({{ $currentYear }})</th>
                @foreach ($reportYears as $yr)
                    <th class="col-year">Due<br/>{{ $yr }}</th>
                @endforeach
                <th class="col-last">Last payment<br/><span style="font-weight:normal;font-size:7px;">Amt / date / mode</span></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($untagged as $u)
                <tr>
                    <td class="col-sn">{{ $rowSn2++ }}</td>
                    <td class="col-name">{{ $u['label'] }}</td>
                    <td class="col-hub">{{ number_format($u['hub']) }}</td>
                    @foreach ($reportYears as $yr)
                        <td class="col-year">{{ $u['due_by_year'][$yr] ?? '—' }}</td>
                    @endforeach
                    <td class="col-last">{!! nl2br(e($u['last_pay'])) !!}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 4 + count($reportYears) }}" style="text-align:center;padding:10px;">No untagged families.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
