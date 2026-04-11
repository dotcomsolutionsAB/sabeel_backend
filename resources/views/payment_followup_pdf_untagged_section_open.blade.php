    </tbody>
</table>
<div class="section-title">Families not linked to any establishment</div>
<table class="main">
    <thead>
        <tr>
            <th class="col-sn">SN</th>
            <th class="col-name">Family (HOF)</th>
            <th class="col-hub">Hub<br/>({{ $currentYear }})</th>
            @foreach ($reportYears as $yr)
                <th class="col-year">Due<br/>{{ $reportYearLabels[$yr] ?? $yr }}</th>
            @endforeach
            <th class="col-last">Last payment<br/><span style="font-weight:normal;font-size:7px;">Amt / date / mode</span></th>
        </tr>
    </thead>
    <tbody>
