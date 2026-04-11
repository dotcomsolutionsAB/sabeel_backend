@php $sn = $startSn; @endphp
@foreach ($blocks as $block)
    <tr class="est-row">
        <td class="col-sn">{{ $sn++ }}</td>
        <td class="col-name">{{ $block['establishment_name'] }}</td>
        <td class="col-hub">{{ number_format($block['hub']) }}</td>
        @foreach ($reportYears as $i => $yr)
            <td class="col-year">{{ $block['due_cells'][$i] ?? '—' }}</td>
        @endforeach
        <td class="col-last">{!! nl2br(e($block['last_pay'])) !!}</td>
    </tr>
    @foreach ($block['partners'] as $p)
        <tr class="partner-row">
            <td class="col-sn">{{ $sn++ }}</td>
            <td class="col-name">{{ $p['label'] }}</td>
            <td class="col-hub">{{ number_format($p['hub']) }}</td>
            @foreach ($reportYears as $i => $yr)
                <td class="col-year">{{ $p['due_cells'][$i] ?? '—' }}</td>
            @endforeach
            <td class="col-last">{!! nl2br(e($p['last_pay'])) !!}</td>
        </tr>
    @endforeach
@endforeach
