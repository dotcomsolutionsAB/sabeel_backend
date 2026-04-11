<div class="doc-title">{{ $title }}</div>
<div class="meta">Current year (hub column): {{ $currentYear }}</div>
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
