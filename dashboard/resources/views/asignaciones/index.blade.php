@extends('layouts.app')
@section('title', 'Asignaciones')

@section('content')
<div class="page-head">
    <div>
        <h1>Asignaciones</h1>
        <p>Carteras cargadas y su avance de recuperación.</p>
    </div>
    <a href="{{ route('asignaciones.create') }}" class="btn primary">+ Cargar cartera</a>
</div>

@if ($asignaciones->isEmpty())
    <div class="panel"><div class="empty">Aún no hay asignaciones. Carga tu primera cartera para empezar.</div></div>
@else
<div class="panel">
    <table>
        <thead>
            <tr>
                <th>Cartera</th><th>Estado</th><th>Carga</th>
                <th class="right">Cuentas</th><th class="right">Pagadas</th>
                <th style="width:180px">Recuperación</th><th class="right">Monto recuperado</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($asignaciones as $a)
            <tr onclick="location='{{ route('asignaciones.show', $a) }}'" style="cursor:pointer">
                <td><strong>{{ $a->nombre }}</strong></td>
                <td><span class="chip {{ $a->estado }}">{{ ucfirst($a->estado) }}</span></td>
                <td class="muted">{{ $a->fecha_carga?->format('d/m/Y') }}</td>
                <td class="right mono">{{ number_format($a->m['total']) }}</td>
                <td class="right mono">{{ number_format($a->m['pagadas']) }} <span class="faint">/ {{ number_format($a->m['cobrables']) }}</span></td>
                <td>
                    <div class="flex between" style="margin-bottom:5px"><span class="faint" style="font-size:12px">{{ $a->m['pct_pagadas'] }}%</span></div>
                    <div class="bar"><i style="width:{{ $a->m['pct_pagadas'] }}%"></i></div>
                </td>
                <td class="right mono">${{ number_format($a->m['monto_recuperado'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
