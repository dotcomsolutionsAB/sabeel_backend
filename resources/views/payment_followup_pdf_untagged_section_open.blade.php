    </tbody>
</table>
<div class="section-title">Families not linked to any establishment</div>
<table class="main">
    <thead>
        <tr>
            <th class="col-sn">SN</th>
            <th class="col-name">Family (HOF)</th>
            <th class="col-hub">Hub ({{ $currentYear }})</th>
            @foreach ($reportYears as $yr)
                <th class="col-year">Due {{ $reportYearLabels[$yr] ?? $yr }}</th>
            @endforeach
            <th class="col-last">Last payment <span style="font-weight:normal;">(amt / date / mode)</span></th>
        </tr>
    </thead>
    <tbody>
