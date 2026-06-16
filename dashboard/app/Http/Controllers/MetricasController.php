<?php

namespace App\Http\Controllers;

use App\Models\Asignacion;
use Illuminate\Support\Facades\DB;

class MetricasController extends Controller
{
    private const HORIZONTE = 30; // días de cierre de una cartera (un mes)

    public function index()
    {
        $opciones = Asignacion::orderByDesc('fecha_carga')->orderByDesc('id')
            ->get(['id', 'nombre', 'activa', 'fecha_carga']);

        // Selección múltiple (?asignacion[]=). Por defecto, las 2 más recientes.
        $sel = array_map('intval', (array) request('asignacion', []));
        if (empty($sel)) {
            $sel = $opciones->take(2)->pluck('id')->all();
        }

        $seleccionadas = $opciones->whereIn('id', $sel)->sortBy('fecha_carga')->values();
        if ($seleccionadas->isEmpty()) {
            return view('metricas.index', ['foco' => null, 'opciones' => $opciones, 'sel' => $sel]);
        }

        $series = [];
        foreach ($seleccionadas as $a) {
            $puntos = $this->serie($a);
            $series[$a->id] = [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'puntos' => $puntos,
                'adeudo' => $this->adeudo($a->id),
                'final' => empty($puntos) ? 0.0 : end($puntos)['acumulado'],
            ];
        }

        // Foco de proyección: la más reciente seleccionada.
        $foco = $seleccionadas->sortByDesc('fecha_carga')->first();
        $focoSerie = $series[$foco->id];

        // Mejor mes: entre las otras seleccionadas, la de mayor % recuperado.
        $mejor = null;
        foreach ($seleccionadas as $a) {
            if ($a->id === $foco->id) {
                continue;
            }
            $s = $series[$a->id];
            $pct = $s['adeudo'] > 0 ? $s['final'] / $s['adeudo'] : 0;
            if ($mejor === null || $pct > $mejor['pct']) {
                $mejor = ['id' => $a->id, 'nombre' => $a->nombre, 'pct' => $pct, 'serie' => $s];
            }
        }

        $proy = $this->proyecciones($focoSerie, $mejor);

        return view('metricas.index', compact(
            'opciones', 'seleccionadas', 'series', 'foco', 'mejor', 'proy', 'sel'
        ));
    }

    /** Serie de recuperación acumulada por día desde la carga. */
    private function serie(Asignacion $a): array
    {
        $rows = DB::select(
            "SELECT CAST(e.fecha_deteccion AS date) AS dia,
                    SUM(e.monto_pagado) AS monto, COUNT(*) AS pagos
             FROM eventos_pago e
             JOIN asignacion_cuentas c ON c.id = e.asignacion_cuenta_id
             WHERE c.asignacion_id = ?
             GROUP BY CAST(e.fecha_deteccion AS date) ORDER BY dia",
            [$a->id]
        );

        $carga = $a->fecha_carga;
        $acum = 0.0;
        $puntos = [];
        foreach ($rows as $r) {
            $acum += (float) $r->monto;
            $dia = \Carbon\Carbon::parse($r->dia);
            $puntos[] = [
                'offset' => $carga ? (int) $carga->diffInDays($dia) : count($puntos),
                'monto_dia' => (float) $r->monto,
                'pagos_dia' => (int) $r->pagos,
                'acumulado' => round($acum, 2),
            ];
        }
        return $puntos;
    }

    private function adeudo(int $id): float
    {
        return (float) DB::table('asignacion_cuentas')
            ->where('asignacion_id', $id)
            ->whereIn('tipo_linea', ['bait', 'desconocido'])
            ->sum('saldo_referencia');
    }

    /** % recuperado del mejor mes en el día d (curva escalón). */
    private function pctEnDia(array $puntos, float $adeudo, int $d): float
    {
        $acum = 0.0;
        foreach ($puntos as $p) {
            if ($p['offset'] <= $d) {
                $acum = $p['acumulado'];
            } else {
                break;
            }
        }
        return $adeudo > 0 ? $acum / $adeudo : 0.0;
    }

    /**
     * Dos proyecciones del cierre del foco al horizonte de un mes:
     *  A) siguiendo la curva del mejor mes (escalada al adeudo del foco),
     *  B) extrapolando el ritmo actual del foco.
     */
    private function proyecciones(array $foco, ?array $mejor): array
    {
        $H = self::HORIZONTE;
        $puntos = $foco['puntos'];
        $adeudo = $foco['adeudo'];
        $t = empty($puntos) ? 0 : end($puntos)['offset'];
        $recup = empty($puntos) ? 0.0 : end($puntos)['acumulado'];
        $tasa = $t > 0 ? $recup / $t : 0.0;

        // B: ritmo actual (recta de hoy al cierre).
        $cierreB = min($adeudo, $recup + $tasa * max(0, $H - $t));
        $bPts = [];
        if ($t < $H) {
            $bPts = [['x' => $t, 'y' => round($recup, 2)], ['x' => $H, 'y' => round($cierreB, 2)]];
        }

        // A: forma del mejor mes escalada al adeudo del foco.
        $aPts = [];
        $cierreA = 0.0;
        $tasaMejorDia = 0.0;
        if ($mejor) {
            $mp = $mejor['serie']['puntos'];
            $adM = $mejor['serie']['adeudo'];
            $diasMejor = empty($mp) ? 1 : max(1, end($mp)['offset']);
            $tasaMejorDia = $adeudo > 0 && $diasMejor > 0
                ? ($adeudo * $mejor['pct']) / $diasMejor : 0.0;

            $dias = collect($mp)->pluck('offset')->filter(fn ($d) => $d <= $H)
                ->push($H)->unique()->sort()->values();
            foreach ($dias as $d) {
                $aPts[] = ['x' => $d, 'y' => round($adeudo * $this->pctEnDia($mp, $adM, $d), 2)];
            }
            $cierreA = $adeudo * $this->pctEnDia($mp, $adM, $H);
        }

        return [
            'horizonte' => $H,
            't' => $t,
            'recuperado' => $recup,
            'adeudo' => $adeudo,
            'pct_actual_hoy' => $adeudo > 0 ? round($recup / $adeudo * 100, 1) : 0,
            'tasa_dia' => $tasa,
            'tasa_dia_pct' => $adeudo > 0 ? round($tasa / $adeudo * 100, 2) : 0,
            'tasa_mejor_dia' => $tasaMejorDia,
            'tasa_mejor_dia_pct' => $adeudo > 0 ? round($tasaMejorDia / $adeudo * 100, 2) : 0,
            'cierre_actual' => $cierreB,
            'pct_cierre_actual' => $adeudo > 0 ? round($cierreB / $adeudo * 100, 1) : 0,
            'cierre_mejor' => $cierreA,
            'pct_cierre_mejor' => $adeudo > 0 ? round($cierreA / $adeudo * 100, 1) : 0,
            'brecha' => round($cierreA - $cierreB, 2),
            'a_pts' => $aPts,
            'b_pts' => $bPts,
        ];
    }
}
