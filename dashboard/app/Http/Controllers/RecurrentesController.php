<?php

namespace App\Http\Controllers;

use App\Models\Asignacion;
use Illuminate\Support\Facades\DB;

/**
 * Clientes recurrentes: números de la cartera activa que ya aparecieron en
 * asignaciones anteriores. Muestra su historial de pago (cuándo y cuánto
 * pagaron antes) para clasificarlos y priorizar la gestión.
 */
class RecurrentesController extends Controller
{
    public function index()
    {
        $activa = Asignacion::where('activa', true)->first();
        if (! $activa) {
            return view('recurrentes.index', ['activa' => null]);
        }

        $kpis = $this->kpis($activa->id);

        // DN de la activa presentes en otras asignaciones (paginado).
        $dn = DB::table('asignacion_cuentas')
            ->where('asignacion_id', $activa->id)
            ->whereIn('numero', function ($q) use ($activa) {
                $q->select('numero')->from('asignacion_cuentas')
                    ->where('asignacion_id', '!=', $activa->id);
            })
            ->select('numero')->groupBy('numero')->orderBy('numero')
            ->paginate(25);

        $clientes = $this->historial($dn->pluck('numero')->all(), $activa->id);

        return view('recurrentes.index', compact('activa', 'kpis', 'dn', 'clientes'));
    }

    /** Para los DN de la página, arma su historial en asignaciones anteriores. */
    private function historial(array $numeros, int $activaId): array
    {
        if (empty($numeros)) {
            return [];
        }

        // Estatus actual del DN en la cartera activa (puede tener varias emisiones).
        $actual = DB::table('asignacion_cuentas')
            ->where('asignacion_id', $activaId)->whereIn('numero', $numeros)
            ->select('numero', 'estatus_cobranza', 'monto_emision')->get()
            ->groupBy('numero');

        // Apariciones en OTRAS asignaciones (distinct numero+asignacion).
        $aparic = DB::table('asignacion_cuentas as a')
            ->join('asignaciones as asg', 'asg.id', '=', 'a.asignacion_id')
            ->whereIn('a.numero', $numeros)->where('a.asignacion_id', '!=', $activaId)
            ->select('a.numero', 'a.asignacion_id', 'asg.nombre', 'asg.fecha_carga')
            ->distinct()->get()->groupBy('numero');

        // Pagos inferidos por numero+asignacion en otras asignaciones.
        $pagos = DB::table('eventos_pago as e')
            ->join('asignacion_cuentas as a', 'a.id', '=', 'e.asignacion_cuenta_id')
            ->whereIn('a.numero', $numeros)->where('a.asignacion_id', '!=', $activaId)
            ->groupBy('a.numero', 'a.asignacion_id')
            ->selectRaw('a.numero, a.asignacion_id, SUM(e.monto_pagado) AS monto, MAX(e.fecha_deteccion) AS fecha')
            ->get()->keyBy(fn ($r) => $r->numero.'|'.$r->asignacion_id);

        $out = [];
        foreach ($numeros as $num) {
            $hist = [];
            $vecesPago = 0;
            $montoTotal = 0.0;
            foreach (($aparic[$num] ?? collect()) as $ap) {
                $pago = $pagos->get($num.'|'.$ap->asignacion_id);
                $pagó = $pago !== null;
                if ($pagó) {
                    $vecesPago++;
                    $montoTotal += (float) $pago->monto;
                }
                $hist[] = [
                    'asignacion' => $ap->nombre,
                    'fecha_carga' => $ap->fecha_carga,
                    'pago' => $pagó,
                    'monto' => $pago ? (float) $pago->monto : null,
                    'fecha' => $pago->fecha ?? null,
                ];
            }
            usort($hist, fn ($a, $b) => strcmp($a['fecha_carga'], $b['fecha_carga']));
            $vecesAnt = count($hist);

            $clasif = $vecesPago === 0 ? 'moroso'
                : ($vecesPago === $vecesAnt ? 'buen_pagador' : 'irregular');

            $actEmis = $actual[$num] ?? collect();
            $out[] = [
                'numero' => $num,
                'estatus_actual' => optional($actEmis->first())->estatus_cobranza ?? 'con_adeudo',
                'historico' => $hist,
                'veces_anteriores' => $vecesAnt,
                'veces_pago' => $vecesPago,
                'monto_historico' => $montoTotal,
                'clasificacion' => $clasif,
            ];
        }
        return $out;
    }

    private function kpis(int $activaId): array
    {
        // DN de la activa que están en otras asignaciones.
        $recurrentes = DB::table('asignacion_cuentas')
            ->where('asignacion_id', $activaId)
            ->whereIn('numero', function ($q) use ($activaId) {
                $q->select('numero')->from('asignacion_cuentas')
                    ->where('asignacion_id', '!=', $activaId);
            })
            ->distinct()->count('numero');

        // De esos, cuántos tienen algún pago inferido en otra asignación.
        $conPago = DB::table('asignacion_cuentas as a')
            ->where('a.asignacion_id', $activaId)
            ->whereIn('a.numero', function ($q) use ($activaId) {
                $q->select('a2.numero')
                    ->from('asignacion_cuentas as a2')
                    ->join('eventos_pago as e', 'e.asignacion_cuenta_id', '=', 'a2.id')
                    ->where('a2.asignacion_id', '!=', $activaId);
            })
            ->distinct()->count('a.numero');

        return [
            'recurrentes' => $recurrentes,
            'con_pago' => $conPago,
            'sin_pago' => max(0, $recurrentes - $conPago),
        ];
    }
}
