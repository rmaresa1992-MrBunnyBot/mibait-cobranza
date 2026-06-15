<?php

namespace App\Http\Controllers;

use App\Models\Asignacion;
use Illuminate\Support\Facades\DB;

class MetricasController extends Controller
{
    public function index()
    {
        // Asignación foco: la activa por defecto, o ?asignacion=ID.
        $foco = request('asignacion')
            ? Asignacion::find(request('asignacion'))
            : Asignacion::where('activa', true)->first();
        $foco = $foco ?: Asignacion::orderByDesc('fecha_carga')->first();

        if (! $foco) {
            return view('metricas.index', ['foco' => null]);
        }

        // Asignación anterior: la archivada cargada justo antes del foco.
        $anterior = Asignacion::where('id', '!=', $foco->id)
            ->where('fecha_carga', '<=', $foco->fecha_carga)
            ->orderByDesc('fecha_carga')->orderByDesc('id')->first();

        $serieFoco = $this->serie($foco);
        $serieAnterior = $anterior ? $this->serie($anterior) : null;
        $kpis = $this->kpis($foco, $serieFoco);
        $repetidas = $this->repetidasHistorico($foco->id);
        $opciones = Asignacion::orderByDesc('fecha_carga')->get(['id', 'nombre', 'activa']);

        return view('metricas.index', compact(
            'foco', 'anterior', 'serieFoco', 'serieAnterior', 'kpis', 'repetidas', 'opciones'
        ));
    }

    /**
     * Serie de recuperación de una asignación: por día con pagos, el monto y
     * conteo del día, el acumulado, y el offset de días desde la carga.
     */
    private function serie(Asignacion $a): array
    {
        $rows = DB::select(
            "SELECT CAST(e.fecha_deteccion AS date) AS dia,
                    SUM(e.monto_pagado) AS monto, COUNT(*) AS pagos
             FROM eventos_pago e
             JOIN asignacion_cuentas c ON c.id = e.asignacion_cuenta_id
             WHERE c.asignacion_id = ?
             GROUP BY CAST(e.fecha_deteccion AS date)
             ORDER BY dia",
            [$a->id]
        );

        $carga = $a->fecha_carga;
        $acum = 0.0;
        $puntos = [];
        foreach ($rows as $r) {
            $acum += (float) $r->monto;
            $dia = \Carbon\Carbon::parse($r->dia);
            $puntos[] = [
                'fecha' => $dia->toDateString(),
                'offset' => $carga ? $carga->diffInDays($dia) : count($puntos),
                'monto_dia' => (float) $r->monto,
                'pagos_dia' => (int) $r->pagos,
                'acumulado' => round($acum, 2),
            ];
        }
        return $puntos;
    }

    private function kpis(Asignacion $a, array $serie): array
    {
        $mejor = null;
        foreach ($serie as $p) {
            if ($mejor === null || $p['monto_dia'] > $mejor['monto_dia']) {
                $mejor = $p;
            }
        }

        // Velocidad: días promedio entre entrega y pago inferido.
        $velocidad = DB::select(
            "SELECT AVG(CAST(DATEDIFF(day, fecha_entrega, fecha_pago_inferida) AS float)) AS dias
             FROM asignacion_cuentas
             WHERE asignacion_id = ? AND fecha_pago_inferida IS NOT NULL
               AND fecha_entrega IS NOT NULL",
            [$a->id]
        )[0]->dias ?? null;

        $original = (float) DB::table('asignacion_cuentas')
            ->where('asignacion_id', $a->id)
            ->whereIn('tipo_linea', ['bait', 'desconocido'])
            ->sum('saldo_referencia');
        $recuperado = empty($serie) ? 0.0 : end($serie)['acumulado'];

        return [
            'recuperado' => $recuperado,
            'original' => $original,
            'pct' => $original > 0 ? round($recuperado / $original * 100, 1) : 0.0,
            'pagos' => array_sum(array_column($serie, 'pagos_dia')),
            'mejor_dia' => $mejor,
            'velocidad' => $velocidad !== null ? round($velocidad, 1) : null,
        ];
    }

    /** Cuentas del foco que también aparecen en otras asignaciones, con su historia. */
    private function repetidasHistorico(int $focoId): array
    {
        $numeros = DB::table('asignacion_cuentas')
            ->select('numero')
            ->where('asignacion_id', $focoId)
            ->whereIn('numero', function ($q) use ($focoId) {
                $q->select('numero')->from('asignacion_cuentas')
                    ->where('asignacion_id', '!=', $focoId);
            })
            ->distinct()->limit(100)->pluck('numero');

        if ($numeros->isEmpty()) {
            return [];
        }

        $rows = DB::table('asignacion_cuentas as c')
            ->join('asignaciones as a', 'a.id', '=', 'c.asignacion_id')
            ->whereIn('c.numero', $numeros)
            ->orderBy('c.numero')->orderBy('a.fecha_carga')
            ->get(['c.numero', 'a.nombre', 'a.fecha_carga', 'c.estatus_cobranza',
                   'c.fecha_pago_inferida', 'c.asignacion_id']);

        $hist = [];
        foreach ($rows as $r) {
            $hist[$r->numero][] = $r;
        }
        return $hist;
    }
}
