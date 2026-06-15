@extends('layouts.app')
@section('title', $asignacion->nombre)

@section('content')
<div class="page-head">
    <div>
        <div class="flex" style="gap:12px">
            <h1>{{ $asignacion->nombre }}</h1>
            <span class="chip {{ $asignacion->estado }}">{{ ucfirst($asignacion->estado) }}</span>
        </div>
        <p>Cargada el {{ $asignacion->fecha_carga?->format('d/m/Y') }} ·
           origen {{ $asignacion->tipo_origen }} ·
           {{ number_format($m['total']) }} cuentas</p>
    </div>
    <a href="{{ route('asignaciones.index') }}" class="btn">← Asignaciones</a>
</div>

@if ($r = session('resumen'))
    <div class="alert ok">
        Carga lista: <strong>{{ number_format($r['insertadas']) }}</strong> cuentas insertadas de {{ number_format($r['total']) }} filas.
        @if ($r['repetidas']) · <strong>{{ $r['repetidas'] }}</strong> repetidas de otras carteras @endif
        @if ($r['duplicadas']) · {{ $r['duplicadas'] }} duplicadas en el archivo @endif
        @if (count($r['invalidas'])) · {{ count($r['invalidas']) }} filas inválidas ignoradas @endif
    </div>
@endif

<div class="grid cards" style="margin-bottom:16px">
    <div class="card metric"><div class="label">Cobrables</div><div class="value">{{ number_format($m['cobrables']) }}</div><div class="sub">{{ $m['prepago'] }} prepago · {{ $m['no_bait'] }} no Bait</div></div>
    <div class="card metric"><div class="label">Pagadas</div><div class="value good">{{ number_format($m['pagadas']) }}</div><div class="sub">{{ $m['pct_pagadas'] }}% de cobrables</div></div>
    <div class="card metric"><div class="label">Pago parcial</div><div class="value">{{ number_format($m['parciales']) }}</div><div class="sub">{{ number_format($m['adeudo']) }} con adeudo</div></div>
    <div class="card metric"><div class="label">Monto recuperado</div><div class="value good">${{ number_format($m['monto_recuperado'], 0) }}</div><div class="sub">{{ $m['pct_monto'] }}% de ${{ number_format($m['monto_original'], 0) }}</div></div>
    <div class="card metric"><div class="label">Repetidas</div><div class="value">{{ number_format($repetidas) }}</div><div class="sub">también en otras carteras</div></div>
</div>

@php
    $emis = max(1, $indicadores['emisiones']);
    $dists = [
        'Reetiqueta' => $indicadores['reetiqueta'],
        'Estatus de contrato' => $indicadores['estatus_contrato'],
        'Bracket de vencimiento' => $indicadores['bracket'],
    ];
@endphp

<div class="grid cards" style="margin-bottom:16px">
    <div class="card metric"><div class="label">Adeudo total</div><div class="value warn">${{ number_format($indicadores['monto'], 0) }}</div><div class="sub">monto de emisión</div></div>
    <div class="card metric"><div class="label">Números únicos</div><div class="value">{{ number_format($indicadores['dn']) }}</div><div class="sub">DN distintos</div></div>
    <div class="card metric"><div class="label">Emisiones</div><div class="value">{{ number_format($indicadores['emisiones']) }}</div><div class="sub">filas de cartera</div></div>
</div>

<div class="grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom:16px; align-items:start">
    @foreach ($dists as $titulo => $dist)
    <div class="panel">
        <div class="panel-head"><h2>{{ $titulo }}</h2></div>
        <div style="padding:16px 20px">
            @forelse ($dist as $d)
                <div style="margin-bottom:12px">
                    <div class="flex between" style="margin-bottom:5px">
                        <span style="font-size:13px">{{ $d['k'] }}</span>
                        <span class="mono faint" style="font-size:12px">{{ number_format($d['n']) }} · {{ round($d['n'] / $emis * 100) }}%</span>
                    </div>
                    <div class="bar"><i style="width:{{ $d['n'] / $emis * 100 }}%"></i></div>
                </div>
            @empty
                <div class="faint" style="font-size:13px">Sin datos.</div>
            @endforelse
        </div>
    </div>
    @endforeach
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Cuentas <span class="faint" style="font-weight:500">· {{ number_format($cuentas->total()) }}{{ ($q || $estatus) ? ' encontradas' : '' }}</span></h2>
        <form method="GET" class="flex" style="gap:8px">
            <input type="text" name="q" value="{{ $q }}" placeholder="Buscar por DN…" inputmode="numeric" style="width:180px" autofocus>
            <select name="estatus" onchange="this.form.submit()">
                <option value="">Todos los estatus</option>
                <option value="con_adeudo" @selected($estatus==='con_adeudo')>Con adeudo</option>
                <option value="pago_parcial" @selected($estatus==='pago_parcial')>Pago parcial</option>
                <option value="pago_total" @selected($estatus==='pago_total')>Pago total</option>
            </select>
            <button class="btn" type="submit">Buscar</button>
            @if ($q || $estatus)
                <a class="btn" href="{{ route('asignaciones.show', $asignacion) }}">Limpiar</a>
            @endif
        </form>
    </div>
    <table>
        <thead><tr>
            <th>Número</th><th>Estatus</th><th>Tipo</th>
            <th class="right">Saldo ref.</th><th class="right">Saldo actual</th>
            <th>Entrega</th><th>Pago inferido</th><th>Últ. consulta</th>
        </tr></thead>
        <tbody>
        @forelse ($cuentas as $c)
            <tr>
                <td class="num">{{ $c->numero }}</td>
                <td><span class="chip {{ $c->estatus_cobranza }}">{{ str_replace('_',' ', $c->estatus_cobranza) }}</span></td>
                <td class="muted">{{ $c->tipo_linea }}</td>
                <td class="right mono">{{ is_null($c->saldo_referencia) ? '—' : '$'.number_format($c->saldo_referencia,2) }}</td>
                <td class="right mono">{{ is_null($c->saldo_actual) ? '—' : '$'.number_format($c->saldo_actual,2) }}</td>
                <td class="muted">{{ $c->fecha_entrega?->format('d/m/Y') ?? '—' }}</td>
                <td class="muted">{{ $c->fecha_pago_inferida?->format('d/m/Y') ?? '—' }}</td>
                <td class="muted">{{ $c->ultima_consulta_at?->format('d/m H:i') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty">Sin cuentas.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if ($cuentas->hasPages())
<div class="flex between" style="margin-top:16px">
    <span class="faint">Página {{ $cuentas->currentPage() }} de {{ $cuentas->lastPage() }}</span>
    <div class="flex">
        @if ($cuentas->previousPageUrl())<a class="btn" href="{{ $cuentas->previousPageUrl() }}">← Anterior</a>@endif
        @if ($cuentas->nextPageUrl())<a class="btn" href="{{ $cuentas->nextPageUrl() }}">Siguiente →</a>@endif
    </div>
</div>
@endif
@endsection
