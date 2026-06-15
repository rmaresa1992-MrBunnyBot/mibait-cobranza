@extends('layouts.app')
@section('title', 'Cargar cartera')

@section('content')
<div class="page-head"><div>
    <h1>Cargar cartera</h1>
    <p>Layout esperado: columnas <strong>Numero</strong>, <strong>Estatus</strong> y <strong>Fecha de entrega</strong>. Formato .xlsx o .csv.</p>
</div></div>

@if ($errors->any())
    <div class="alert err"><ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="panel" style="max-width:640px">
    <div style="padding:24px">
        <form method="POST" action="{{ route('asignaciones.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="field">
                <label>Tipo de carga</label>
                <div class="radio-row">
                    <label class="radio-card sel" data-radio>
                        <input type="radio" name="tipo_origen" value="nueva" checked>
                        <div class="t">Nueva asignación</div>
                        <div class="d">Archiva la activa y abre una cartera nueva.</div>
                    </label>
                    <label class="radio-card" data-radio>
                        <input type="radio" name="tipo_origen" value="complemento">
                        <div class="t">Complemento</div>
                        <div class="d">Agrega cuentas a la asignación activa
                            @if($activa)(<strong>{{ $activa->nombre }}</strong>)@endif.</div>
                    </label>
                </div>
            </div>

            <div class="field">
                <label for="nombre">Nombre de la cartera <span class="faint">(opcional)</span></label>
                <input type="text" id="nombre" name="nombre" value="{{ old('nombre') }}" placeholder="Ej. Cartera junio 2026">
            </div>

            <div class="field">
                <label for="archivo">Archivo (.xlsx / .csv)</label>
                <input type="file" id="archivo" name="archivo" accept=".xlsx,.csv" required>
            </div>

            <button type="submit" class="btn primary">Cargar y crear asignación</button>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.querySelectorAll('[data-radio]').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('[data-radio]').forEach(c => c.classList.remove('sel'));
        card.classList.add('sel');
    });
});
</script>
@endpush
@endsection
