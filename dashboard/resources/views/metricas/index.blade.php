@extends('layouts.app')
@section('title', 'Métricas')

@section('content')
<div class="page-head">
    <div>
        <h1>Métricas y proyección</h1>
        <p>Compara carteras-mes y proyecta el cierre de la más reciente contra el mejor mes y su propio ritmo.</p>
    </div>
</div>

<form method="GET" class="panel" style="margin-bottom:16px">
    <div class="panel-head"><h2>Carteras a comparar</h2><span class="faint">la más reciente se proyecta; el mejor mes es la referencia</span></div>
    <div style="padding:14px 20px; display:flex; flex-wrap:wrap; gap:10px">
        @foreach ($opciones as $o)
            <label class="radio-card {{ in_array($o->id, $sel) ? 'sel' : '' }}" style="flex:0 0 auto; padding:8px 14px">
                <input type="checkbox" name="asignacion[]" value="{{ $o->id }}" {{ in_array($o->id, $sel) ? 'checked' : '' }} onchange="this.form.submit()">
                <span class="t" style="font-size:13px">{{ $o->nombre }}</span>
                @if ($o->activa)<span class="chip activa" style="font-size:10px; margin-left:6px">activa</span>@endif
            </label>
        @endforeach
    </div>
</form>

@if (! $foco)
    <div class="panel"><div class="empty">Selecciona al menos una cartera.</div></div>
@else
@php
    $colores = ['#5b9dff', '#f0b450', '#b98bff', '#f06d6d', '#7de0c0'];
    $jsSeries = [];
    $i = 0;
    foreach ($seleccionadas as $a) {
        $s = $series[$a->id];
        $jsSeries[] = [
            'nombre' => $s['nombre'],
            'color' => $a->id === $foco->id ? '#4ad0a8' : $colores[$i % count($colores)],
            'foco' => $a->id === $foco->id,
            'puntos' => collect($s['puntos'])->map(fn ($p) => ['x' => $p['offset'], 'y' => $p['acumulado']])->values(),
        ];
        $i++;
    }
@endphp

<div class="grid cards" style="margin-bottom:16px">
    <div class="card metric"><div class="label">Recuperado hoy · {{ $foco->nombre }}</div><div class="value good">${{ number_format($proy['recuperado'], 0) }}</div><div class="sub">{{ $proy['pct_actual_hoy'] }}% · día {{ $proy['t'] }} de {{ $proy['horizonte'] }}</div></div>
    <div class="card metric"><div class="label">Proyección · mejor mes</div><div class="value">{{ $mejor ? '$'.number_format($proy['cierre_mejor'], 0) : '—' }}</div><div class="sub">{{ $mejor ? $proy['pct_cierre_mejor'].'% · base '.$mejor['nombre'] : 'selecciona otro mes' }}</div></div>
    <div class="card metric"><div class="label">Proyección · ritmo actual</div><div class="value">${{ number_format($proy['cierre_actual'], 0) }}</div><div class="sub">{{ $proy['pct_cierre_actual'] }}% al cierre del mes</div></div>
    @if ($mejor)
    <div class="card metric"><div class="label">Brecha al cierre</div>
        <div class="value {{ $proy['brecha'] > 0 ? 'warn' : 'good' }}">{{ $proy['brecha'] > 0 ? '-$'.number_format($proy['brecha'], 0) : '✓ en ritmo' }}</div>
        <div class="sub">{{ $proy['brecha'] > 0 ? 'por debajo del mejor mes' : 'alcanza o supera el mejor mes' }}</div></div>
    @endif
</div>

<div class="grid cards" style="margin-bottom:16px">
    <div class="card metric"><div class="label">Ritmo actual / día</div><div class="value">${{ number_format($proy['tasa_dia'], 0) }}</div><div class="sub">{{ $proy['tasa_dia_pct'] }}% del adeudo por día</div></div>
    <div class="card metric"><div class="label">Ritmo mejor mes / día</div><div class="value">{{ $mejor ? '$'.number_format($proy['tasa_mejor_dia'], 0) : '—' }}</div><div class="sub">{{ $mejor ? $proy['tasa_mejor_dia_pct'].'% del adeudo por día' : '—' }}</div></div>
</div>

<div class="panel" style="margin-bottom:16px">
    <div class="panel-head">
        <h2>Curva de recuperación y proyección</h2>
        <span class="faint">líneas: real · punteadas: proyección de cierre de {{ $foco->nombre }}</span>
    </div>
    <div style="padding:18px"><canvas id="curva" height="95"></canvas></div>
</div>
@endif
@endsection

@push('scripts')
@if ($foco)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
const series = @json($jsSeries);
const proyA = @json($proy['a_pts']);
const proyB = @json($proy['b_pts']);
const H = {{ $proy['horizonte'] }};
const C = { text:'#9aa7b8', grid:'rgba(40,51,66,.6)' };
Chart.defaults.color = C.text;
Chart.defaults.font.family = "Inter, sans-serif";

const ds = series.map(s => ({
    label: s.nombre,
    data: s.puntos,
    borderColor: s.color,
    backgroundColor: s.foco ? 'rgba(74,208,168,.10)' : 'transparent',
    fill: s.foco, tension: .25, pointRadius: 2,
    borderWidth: s.foco ? 2.5 : 1.8,
}));
if (proyA.length) ds.push({
    label: 'Proyección mejor mes', data: proyA,
    borderColor: '#b98bff', borderDash: [6,4], fill: false, tension: .2,
    pointRadius: 0, borderWidth: 2,
});
if (proyB.length) ds.push({
    label: 'Proyección ritmo actual', data: proyB,
    borderColor: '#f0b450', borderDash: [3,3], fill: false,
    pointRadius: 3, borderWidth: 2,
});

new Chart(document.getElementById('curva'), {
    type: 'line',
    data: { datasets: ds },
    options: {
        responsive: true, maintainAspectRatio: true,
        scales: {
            x: { type:'linear', min:0, max:H, title:{display:true,text:'Días desde la carga'}, grid:{color:C.grid}, ticks:{precision:0} },
            y: { title:{display:true,text:'Monto recuperado ($)'}, grid:{color:C.grid}, beginAtZero:true },
        },
        plugins: { legend: { labels: { usePointStyle: true, boxHeight: 7 } } },
    }
});
</script>
@endif
@endpush
