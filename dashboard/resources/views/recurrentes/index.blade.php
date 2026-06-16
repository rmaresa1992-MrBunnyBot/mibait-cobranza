@extends('layouts.app')
@section('title', 'Recurrentes')

@section('content')
@php
    $etiqueta = ['buen_pagador' => 'Buen pagador', 'irregular' => 'Irregular', 'moroso' => 'Moroso'];
@endphp
<div class="page-head">
    <div>
        <h1>Clientes recurrentes</h1>
        <p>Números de la cartera activa que ya estuvieron en carteras anteriores, clasificados por su historial de pago.</p>
    </div>
</div>

@if (! $activa)
    <div class="panel"><div class="empty">No hay cartera activa.</div></div>
@else
<div class="grid cards" style="margin-bottom:16px">
    <div class="card metric"><div class="label">Clientes recurrentes</div><div class="value">{{ number_format($kpis['recurrentes']) }}</div><div class="sub">también en carteras previas</div></div>
    <div class="card metric"><div class="label">Pagaron antes</div><div class="value good">{{ number_format($kpis['con_pago']) }}</div><div class="sub">priorizar: tienen historial de pago</div></div>
    <div class="card metric"><div class="label">Nunca pagaron</div><div class="value warn">{{ number_format($kpis['sin_pago']) }}</div><div class="sub">requieren otra estrategia</div></div>
</div>

@if (empty($clientes))
    <div class="panel"><div class="empty">Ningún número de la cartera activa aparece en asignaciones anteriores todavía. Aparecerán aquí cuando cargues una cartera con cuentas ya trabajadas.</div></div>
@else
<div class="panel">
    <div class="panel-head"><h2>Historial por cliente</h2><span class="faint">{{ number_format($kpis['recurrentes']) }} recurrentes</span></div>
    <table>
        <thead><tr>
            <th>Número</th><th>Clasificación</th><th>Estatus actual</th>
            <th class="right">Pagó / veces</th><th class="right">Monto histórico</th>
            <th>Historial de pago</th>
        </tr></thead>
        <tbody>
        @foreach ($clientes as $c)
            <tr>
                <td class="num">{{ $c['numero'] }}</td>
                <td><span class="chip {{ $c['clasificacion'] }}">{{ $etiqueta[$c['clasificacion']] }}</span></td>
                <td><span class="chip {{ $c['estatus_actual'] }}">{{ str_replace('_', ' ', $c['estatus_actual']) }}</span></td>
                <td class="right mono">{{ $c['veces_pago'] }} / {{ $c['veces_anteriores'] }}</td>
                <td class="right mono">${{ number_format($c['monto_historico'], 0) }}</td>
                <td>
                    @foreach ($c['historico'] as $h)
                        <div class="flex" style="gap:6px; margin:2px 0; font-size:12px">
                            @if ($h['pago'])
                                <span style="color:var(--accent); font-weight:600">✓ ${{ number_format($h['monto'], 0) }}</span>
                            @else
                                <span class="faint">✗ sin pago</span>
                            @endif
                            <span class="faint">{{ $h['asignacion'] }} ({{ \Carbon\Carbon::parse($h['fecha_carga'])->format('m/y') }})@if ($h['fecha']) · pagó {{ \Carbon\Carbon::parse($h['fecha'])->format('d/m/y') }}@endif</span>
                        </div>
                    @endforeach
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if ($dn->hasPages())
<div class="flex between" style="margin-top:16px">
    <span class="faint">Página {{ $dn->currentPage() }} de {{ $dn->lastPage() }}</span>
    <div class="flex">
        @if ($dn->previousPageUrl())<a class="btn" href="{{ $dn->previousPageUrl() }}">← Anterior</a>@endif
        @if ($dn->nextPageUrl())<a class="btn" href="{{ $dn->nextPageUrl() }}">Siguiente →</a>@endif
    </div>
</div>
@endif
@endif
@endif
@endsection
