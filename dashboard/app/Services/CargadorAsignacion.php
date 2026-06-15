<?php

namespace App\Services;

use App\Models\Asignacion;
use App\Models\AsignacionCuenta;
use App\Models\Numero;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\CSV\Reader as CsvReader;

/**
 * Lee un Excel/CSV de cartera (layout: Numero, Estatus, Fecha de entrega),
 * crea la asignacion (nueva o complemento) y vincula el catalogo de numeros
 * detectando cuentas repetidas entre asignaciones.
 */
class CargadorAsignacion
{
    /** Alias aceptados para cada columna del layout. */
    private const COLS = [
        'numero' => ['numero', 'numeros', 'telefono', 'linea', 'msisdn'],
        'estatus' => ['estatus', 'status', 'estado'],
        'fecha_entrega' => ['fecha_de_entrega', 'fecha_entrega', 'entrega', 'fecha'],
    ];

    private const ESTATUS = [
        'con adeudo' => 'con_adeudo',
        'pago parcial' => 'pago_parcial',
        'pago total' => 'pago_total',
    ];

    /**
     * @return array{asignacion: Asignacion, total: int, insertadas: int,
     *   repetidas: array<string>, duplicadas: int, invalidas: array<array>}
     */
    public function cargar(string $path, string $nombreArchivo, string $tipoOrigen, ?string $nombre = null): array
    {
        $filas = $this->leer($path, $nombreArchivo);
        if (empty($filas['map'])) {
            throw new \RuntimeException(
                'No se reconocieron las columnas. Se requieren: Numero, Estatus, Fecha de entrega.'
            );
        }

        return DB::transaction(function () use ($filas, $nombreArchivo, $tipoOrigen, $nombre) {
            $asignacion = $this->resolverAsignacion($tipoOrigen, $nombreArchivo, $nombre);

            $insertadas = 0;
            $repetidas = [];
            $invalidas = [];
            $vistosEnArchivo = [];
            $duplicadas = 0;

            foreach ($filas['rows'] as $i => $row) {
                $numero = $this->limpiarNumero($row[$filas['map']['numero']] ?? null);
                if (! preg_match('/^\d{10}$/', $numero)) {
                    $invalidas[] = ['fila' => $i + 2, 'valor' => $numero, 'motivo' => 'número inválido (deben ser 10 dígitos)'];
                    continue;
                }
                if (isset($vistosEnArchivo[$numero])) {
                    $duplicadas++;
                    continue;
                }
                $vistosEnArchivo[$numero] = true;

                $estatus = $this->normalizarEstatus($row[$filas['map']['estatus']] ?? null);
                $fechaEntrega = $this->parsearFecha($row[$filas['map']['fecha_entrega']] ?? null);

                // Catálogo de números: detectar si ya existía (repetida histórica).
                $numModel = Numero::firstOrNew(['numero' => $numero]);
                if ($numModel->exists) {
                    $repetidas[] = $numero;
                    $numModel->veces_asignado = ($numModel->veces_asignado ?? 0) + 1;
                } else {
                    $numModel->primera_vez_visto = now();
                    $numModel->veces_asignado = 1;
                }
                $numModel->save();

                AsignacionCuenta::create([
                    'asignacion_id' => $asignacion->id,
                    'numero_id' => $numModel->id,
                    'numero' => $numero,
                    'fecha_entrega' => $fechaEntrega,
                    'estatus_carga' => $estatus,
                    'estatus_cobranza' => 'con_adeudo',
                    'tipo_linea' => 'desconocido',
                    'cerrada' => false,
                ]);
                $insertadas++;
            }

            $asignacion->total_cuentas = $asignacion->cuentas()->count();
            $asignacion->save();

            return [
                'asignacion' => $asignacion,
                'total' => count($filas['rows']),
                'insertadas' => $insertadas,
                'repetidas' => array_values(array_unique($repetidas)),
                'duplicadas' => $duplicadas,
                'invalidas' => $invalidas,
            ];
        });
    }

    private function resolverAsignacion(string $tipoOrigen, string $nombreArchivo, ?string $nombre): Asignacion
    {
        if ($tipoOrigen === 'complemento') {
            $activa = Asignacion::where('activa', true)->first();
            if (! $activa) {
                throw new \RuntimeException('No hay una asignación activa para complementar. Carga una nueva.');
            }
            $activa->tipo_origen = 'complemento';
            $activa->save();
            return $activa;
        }

        // Nueva: archivar la activa y abrir otra.
        Asignacion::where('activa', true)->update(['activa' => false, 'estado' => 'archivada']);
        return Asignacion::create([
            'nombre' => $nombre ?: pathinfo($nombreArchivo, PATHINFO_FILENAME),
            'fecha_carga' => now()->toDateString(),
            'activa' => true,
            'estado' => 'activa',
            'tipo_origen' => 'nueva',
            'archivo_origen' => $nombreArchivo,
            'total_cuentas' => 0,
        ]);
    }

    /** Lee el archivo y devuelve filas + mapa de columnas. */
    private function leer(string $path, string $nombre): array
    {
        $reader = str_ends_with(strtolower($nombre), '.csv') ? new CsvReader() : new XlsxReader();
        $reader->open($path);

        $map = [];
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $n => $row) {
                $cells = $row->toArray();
                if ($n === 1) {
                    $map = $this->mapearColumnas($cells);
                    continue;
                }
                if ($this->filaVacia($cells)) {
                    continue;
                }
                $rows[] = $cells;
            }
            break; // solo la primera hoja
        }
        $reader->close();

        return ['map' => $map, 'rows' => $rows];
    }

    private function mapearColumnas(array $headers): array
    {
        $norm = array_map([$this, 'normalizar'], $headers);
        $map = [];
        foreach (self::COLS as $clave => $alias) {
            foreach ($norm as $idx => $h) {
                if (in_array($h, $alias, true)) {
                    $map[$clave] = $idx;
                    break;
                }
            }
        }
        return (isset($map['numero'])) ? $map : [];
    }

    private function normalizar(?string $s): string
    {
        $s = strtolower(trim((string) $s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        return preg_replace('/\s+/', '_', $s);
    }

    private function limpiarNumero($valor): string
    {
        if (is_float($valor) || is_int($valor)) {
            $valor = number_format((float) $valor, 0, '', '');
        }
        return preg_replace('/\D/', '', (string) $valor);
    }

    private function normalizarEstatus($valor): string
    {
        $n = strtolower(trim((string) $valor));
        return self::ESTATUS[$n] ?? 'con_adeudo';
    }

    private function parsearFecha($valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($valor))->toDateString();
        }
        $s = trim((string) $valor);
        if ($s === '') {
            return null;
        }
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $s)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function filaVacia(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }
        return true;
    }
}
