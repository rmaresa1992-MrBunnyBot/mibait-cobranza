@extends('layouts.app')
@section('title', 'Métricas')

@section('content')
<div class="page-head">
    <div>
        <h1>Métricas evolutivas</h1>
        <p>Avance de recuperación y comparativa entre carteras.</p>
    </div>
    @isset($opciones)
    <form method="GET" style="min-width:240px">
        <select name="asignacion" onchange="this.form.submit()">
            @foreach ($opciones as $o)
                <option value="{{ $o->id }}" @selected($foco && $o->id === $foco->id)>
                    {{ $o->nombre }}{{ $o->activa ? ' · activa' : '' }}
                </option>
            @endforeach
        </select>
    </form>
    @endisset
</div>

@if (! $foco)
    <div class="panel"><div class="empty">Aún no hay asignaciones para analizar.</div></div>
@else
<div class="grid cards" style="margin-bottom:16px">
    <div class="card metric"><div class="label">Monto recuperado</div><div class="value good">${{ number_format($kpis['recuperado'], 0) }}</div><div class="sub">{{ $kpis['pct'] }}% de ${{ number_format($kpis['original'], 0) }}</div></div>
    <div class="card metric"><div class="label">Pagos detectados</div><div class="value">{{ number_format($kpis['pagos']) }}</div><div class="sub">desde la carga</div></div>
    <div class="card metric"><div class="label">Mejor día</div>
        <div class="value">{{ $kpis['mejor_dia'] ? '$'.number_format($kpis['mejor_dia']['monto_dia'], 0) : '—' }}</div>
        <div class="sub">{{ $kpis['mejor_dia'] ? \Carbon\Carbon::parse($kpis['mejor_dia']['fecha'])->format('d/m/Y').' · '.$kpis['mejor_dia']['pagos_dia'].' pagos' : 'sin pagos aún' }}</div></div>
    <div class="card metric"><div class="label">Velocidad</div>
        <div class="value">{{ $kpis['velocidad'] !== null ? $kpis['velocidad'] : '—' }}<span style="font-size:14px;color:var(--text-faint)"> días</span></div>
        <div class="sub">promedio entrega → pago</div></div>
</div>

<div class="panel" style="margin-bottom:16px">
    <div class="panel-head">
        <h2>Curva de recuperación</h2>
        <span class="faint">acumulado por días desde la carga · {{ $foco->nombre }}@if($anterior) vs {{ $anterior->nombre }}@endif</span>
    </div>
    <div style="padding:18px"><canvas id="curva" height="90"></canvas></div>
</div>

<div class="grid" style="grid-template-columns: 1.4fr 1fr; align-items:start">
    <div class="panel">
        <div class="panel-head"><h2>Pagos por día</h2><span class="faint">{{ $foco->nombre }}</span></div>
        <div style="padding:18px"><canvas id="porDia" height="150"></canvas></div>
    </div>

    <div class="panel">
        <div class="panel-head"><h2>Cuentas repetidas</h2><span class="faint">{{ count($repetidas) }} también en otras carteras</span></div>
        @if (empty($repetidas))
            <div class="empty">Ninguna cuenta de esta cartera aparece en otras.</div>
        @else
        <table>
            <thead><tr><th>Número</th><th>Historial</th></tr></thead>
            <tbody>
            @foreach ($repetidas as $numero => $apariciones)
                <tr>
                    <td class="num">{{ $numero }}</td>
                    <td>
                        @foreach ($apariciones as $ap)
                            <div class="flex" style="gap:8px; margin:2px 0">
                                <span class="chip {{ $ap->estatus_cobranza }}" style="font-size:11px">{{ str_replace('_',' ', $ap->estatus_cobranza) }}</span>
                                <span class="faint" style="font-size:12px">{{ $ap->nombre }} · {{ \Carbon\Carbon::parse($ap->fecha_carga)->format('d/m/y') }}@if($ap->fecha_pago_inferida) · pagó {{ \Carbon\Carbon::parse($ap->fecha_pago_inferida)->format('d/m') }}@endif</span>
                            </div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
@endif
@endsection

@push('scripts')
@if ($foco)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
const foco = @json($serieFoco);
const anterior = @json($serieAnterior);
const C = { text:'#9aa7b8', grid:'rgba(40,51,66,.6)', teal:'#4ad0a8', blue:'#5b9dff', warn:'#f0b450' };
Chart.defaults.color = C.text;
Chart.defaults.font.family = "Inter, sans-serif";

// Curva acumulada por días desde la carga
const ds = [{
    label: @json($foco->nombre),
    data: foco.map(p => ({x: p.offset, y: p.acumulado})),
    borderColor: C.teal, backgroundColor: 'rgba(74,208,168,.12)',
    fill: true, tension: .25, pointRadius: 3, borderWidth: 2,
}];
@if($anterior)
ds.push({
    label: @json($anterior->nombre),
    data: anterior.map(p => ({x: p.offset, y: p.acumulado})),
    borderColor: C.blue, backgroundColor: 'transparent',
    borderDash: [6,4], fill: false, tension: .25, pointRadius: 3, borderWidth: 2,
});
@endif
new Chart(document.getElementById('curva'), {
    type: 'line',
    data: { datasets: ds },
    options: {
        responsive: true, maintainAspectRatio: true,
        scales: {
            x: { type:'linear', title:{display:true,text:'Días desde la carga'}, grid:{color:C.grid}, ticks:{precision:0} },
            y: { title:{display:true,text:'Monto recuperado ($)'}, grid:{color:C.grid}, beginAtZero:true },
        },
        plugins: { legend: { labels: { usePointStyle: true } } },
    }
});

// Pagos por día
const mejor = foco.reduce((m,p)=> (!m||p.monto_dia>m.monto_dia)?p:m, null);
new Chart(document.getElementById('porDia'), {
    type: 'bar',
    data: {
        labels: foco.map(p => p.fecha),
        datasets: [{
            label: 'Monto del día',
            data: foco.map(p => p.monto_dia),
            backgroundColor: foco.map(p => (mejor && p.fecha===mejor.fecha) ? C.teal : 'rgba(91,157,255,.55)'),
            borderRadius: 5,
        }]
    },
    options: {
        responsive: true,
        scales: { x:{grid:{display:false}}, y:{grid:{color:C.grid}, beginAtZero:true} },
        plugins: { legend: { display:false } },
    }
});
</script>
@endif
@endpush
