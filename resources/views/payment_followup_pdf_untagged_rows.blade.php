@if ($empty)
    <tr class="{{ !($u['is_takhmeen_updated'] ?? false) ? 'not-updated' : '' }}">
        <td colspan="{{ 5 + count($reportYears) }}" style="text-align:center;padding:10px;">No untagged families.</td>
    </tr>
@else
    @php $sn = $startSn; @endphp
    @foreach ($untagged as $u)
        <tr class="{{ !($u['is_takhmeen_updated'] ?? false) ? 'not-updated' : '' }}">
            <td class="col-sn">{{ $sn++ }}</td>
            <td class="col-name">{{ $u['label'] }}</td>
            <td class="col-phone">{{ $u['phone'] ?? '—' }}</td>
            <td class="col-hub">{{ number_format($u['hub']) }}</td>
            @foreach ($reportYears as $i => $yr)
                <td class="col-year">{{ $u['due_cells'][$i] ?? '—' }}</td>
            @endforeach
            <td class="col-last">{{ $u['last_pay'] }}</td>
        </tr>
    @endforeach
@endif
