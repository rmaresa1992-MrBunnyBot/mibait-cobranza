<?php

namespace App\Http\Controllers;

use App\Models\Asignacion;
use App\Models\AsignacionCuenta;
use App\Services\CargadorAsignacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

class AsignacionController extends Controller
{
    public function index()
    {
        $asignaciones = Asignacion::query()
            ->orderByDesc('activa')->orderByDesc('fecha_carga')
            ->get()
            ->map(function (Asignacion $a) {
                $m = $this->metricas($a->id);
                $a->setAttribute('m', $m);
                return $a;
            });

        return view('asignaciones.index', compact('asignaciones'));
    }

    public function create()
    {
        $activa = Asignacion::where('activa', true)->first();
        return view('asignaciones.create', compact('activa'));
    }

    /** Descarga un .xlsx de ejemplo con el layout esperado. */
    public function plantilla()
    {
        $tmp = storage_path('app/layout_cartera_'.uniqid().'.xlsx');
        $writer = new XlsxWriter();
        $writer->openToFile($tmp);
        $writer->addRow(Row::fromValues(['Numero', 'Estatus', 'Fecha de entrega']));
        foreach ([
            ['5512345678', 'Con adeudo', '01/06/2026'],
            ['3398765432', 'Con adeudo', '01/06/2026'],
            ['8112223344', 'Con adeudo', '02/06/2026'],
        ] as $fila) {
            $writer->addRow(Row::fromValues($fila));
        }
        $writer->close();

        return response()->download($tmp, 'layout_cartera_mibait.xlsx')
            ->deleteFileAfterSend(true);
    }

    public function store(Request $request, CargadorAsignacion $cargador)
    {
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:20480'],
            'tipo_origen' => ['required', 'in:nueva,complemento'],
            'nombre' => ['nullable', 'string', 'max:120'],
        ], [], ['archivo' => 'archivo']);

        try {
            $r = $cargador->cargar(
                $request->file('archivo')->getRealPath(),
                $request->file('archivo')->getClientOriginalName(),
                $request->input('tipo_origen'),
                $request->input('nombre'),
            );
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('asignaciones.show', $r['asignacion'])
            ->with('resumen', [
                'insertadas' => $r['insertadas'],
                'total' => $r['total'],
                'repetidas' => count($r['repetidas']),
                'duplicadas' => $r['duplicadas'],
                'invalidas' => $r['invalidas'],
            ]);
    }

    public function show(Request $request, Asignacion $asignacion)
    {
        $m = $this->metricas($asignacion->id);
        $indicadores = $this->indicadoresBase($asignacion->id);
        $repetidas = $this->repetidasEntreAsignaciones($asignacion->id);

        $q = preg_replace('/\D/', '', (string) $request->input('q'));
        $estatus = $request->input('estatus');

        $cuentas = $asignacion->cuentas()
            ->when($q !== '', fn ($w) => $w->where('numero', 'like', "%{$q}%"))
            ->when($estatus, fn ($w) => $w->where('estatus_cobranza', $estatus))
            ->orderByRaw("CASE estatus_cobranza WHEN 'con_adeudo' THEN 0 WHEN 'pago_parcial' THEN 1 ELSE 2 END")
            ->orderBy('numero')
            ->paginate(50)
            ->withQueryString();

        return view('asignaciones.show', compact('asignacion', 'm', 'indicadores', 'repetidas', 'cuentas', 'q', 'estatus'));
    }

    /** Indicadores principales de la cartera (campos de origen del layout). */
    private function indicadoresBase(int $id): array
    {
        $base = fn () => DB::table('asignacion_cuentas')->where('asignacion_id', $id);

        $tot = $base()->selectRaw(
            'COUNT(*) AS emisiones, COUNT(DISTINCT numero) AS dn, SUM(monto_emision) AS monto'
        )->first();

        $dist = fn (string $col) => $base()
            ->selectRaw("$col AS k, COUNT(*) AS n")
            ->whereNotNull($col)
            ->groupBy($col)->orderByRaw('COUNT(*) DESC')->get()
            ->map(fn ($r) => ['k' => $r->k, 'n' => (int) $r->n]);

        return [
            'emisiones' => (int) $tot->emisiones,
            'dn' => (int) $tot->dn,
            'monto' => (float) $tot->monto,
            'reetiqueta' => $dist('reetiqueta'),
            'estatus_contrato' => $dist('estatus_contrato'),
            'bracket' => $dist('ban_bracket_vencimiento'),
        ];
    }

    /** Métricas agregadas de una asignación. */
    private function metricas(int $asignacionId): array
    {
        $base = AsignacionCuenta::where('asignacion_id', $asignacionId);
        $cobrables = (clone $base)->whereIn('tipo_linea', ['bait', 'desconocido']);

        $total = (clone $base)->count();
        $nCobrables = (clone $cobrables)->count();
        $pagadas = (clone $cobrables)->where('estatus_cobranza', 'pago_total')->count();
        $parciales = (clone $cobrables)->where('estatus_cobranza', 'pago_parcial')->count();
        $adeudo = (clone $cobrables)->where('estatus_cobranza', 'con_adeudo')->count();
        $prepago = (clone $base)->where('tipo_linea', 'prepago')->count();
        $noBait = (clone $base)->where('tipo_linea', 'no_bait')->count();

        $montoOriginal = (clone $cobrables)->sum('saldo_referencia');
        $montoActual = (clone $cobrables)->sum('saldo_actual');
        $recuperado = max(0, $montoOriginal - $montoActual);

        return [
            'total' => $total,
            'cobrables' => $nCobrables,
            'pagadas' => $pagadas,
            'parciales' => $parciales,
            'adeudo' => $adeudo,
            'prepago' => $prepago,
            'no_bait' => $noBait,
            'pct_pagadas' => $nCobrables ? round($pagadas / $nCobrables * 100, 1) : 0.0,
            'monto_original' => (float) $montoOriginal,
            'monto_recuperado' => (float) $recuperado,
            'pct_monto' => $montoOriginal > 0 ? round($recuperado / $montoOriginal * 100, 1) : 0.0,
        ];
    }

    /** Números de esta asignación que también aparecen en otras. */
    private function repetidasEntreAsignaciones(int $asignacionId): int
    {
        return AsignacionCuenta::where('asignacion_id', $asignacionId)
            ->whereIn('numero', function ($q) use ($asignacionId) {
                $q->select('numero')->from('asignacion_cuentas')
                    ->where('asignacion_id', '!=', $asignacionId);
            })->distinct()->count('numero');
    }
}
