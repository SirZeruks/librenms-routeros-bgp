<div class="row">
    <div class="col-md-12">
        <x-panel class="device-overview panel-condensed">
            <x-slot name="heading">
                <i class="fa fa-exchange fa-lg icon-theme" aria-hidden="true"></i>
                @php($bgpUrl = \Illuminate\Support\Facades\Route::has('device.routing.bgp') ? route('device.routing.bgp', ['device' => $device->device_id]) : url('device/' . $device->device_id . '/routing'))
                <strong><a href="{{ $bgpUrl }}">BGP prefixes</a></strong>
                <small class="text-muted">
                    @if(! $enabled)
                        &middot; collection off for this device
                    @elseif($status)
                        &middot; {{ $status['ok'] ? 'read' : 'failed' }} {{ \Carbon\Carbon::createFromTimestamp($status['time'])->diffForHumans() }}
                    @endif
                </small>
            </x-slot>
            @if($status && ! $status['ok'])
                <div class="alert alert-danger" style="margin-bottom: 5px">{{ $status['message'] }}</div>
            @endif
            <table class="table table-condensed table-striped" style="margin: 0">
                @foreach($rows as $r)
                    <tr>
                        <td>{{ $r->bgpPeerIdentifier }}</td>
                        <td>AS{{ $r->bgpPeerRemoteAs }} <small class="text-muted">{{ $r->bgpPeerDescr }}</small></td>
                        <td>{{ $r->bgpPeerState }}</td>
                        <td class="text-right">
                            @if($r->afi !== null)
                                <strong>{{ number_format((int) $r->AcceptedPrefixes) }}</strong>
                                @if((int) $r->AcceptedPrefixes_delta !== 0)
                                    <small class="text-{{ $r->AcceptedPrefixes_delta > 0 ? 'success' : 'warning' }}">({{ $r->AcceptedPrefixes_delta > 0 ? '+' : '' }}{{ $r->AcceptedPrefixes_delta }})</small>
                                @endif
                                <small class="text-muted">{{ $r->afi }}</small>
                            @else
                                <span class="text-muted">&ndash;</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </x-panel>
    </div>
</div>
