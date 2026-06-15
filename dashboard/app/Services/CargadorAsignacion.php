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
 * Lee un Excel/CSV de cartera y crea la asignacion (nueva o complemento),
 * vinculando el catalogo de numeros y detectando repetidas.
 *
 * Soporta el layout real (NUMERO_TEL_CONTRATO, MONTO_EMISION, ...) y, por
 * compatibilidad, el layout simple (Numero, Estatus, Fecha de entrega).
 * El DN a consultar sale de numero_tel_contrato / numero.
 */
class CargadorAsignacion
{
    /** clave en BD => alias de encabezado aceptados (ya normalizados). */
    private const COLS = [
        'numero' => ['numero_tel_contrato', 'numero', 'num_telefono_ov', 'telefono', 'linea', 'msisdn'],
        'fecha_emision' => ['fecha_emision'],
        'mes_emision' => ['mes_emision'],
        'monto_emision' => ['monto_emision', 'monto'],
        'num_edo_cuenta' => ['num_edo_cuenta'],
        'estatus_contrato' => ['estatus_contrato'],
        'fecha_creacion_contrato' => ['fecha_creacion_contrato'],
        'nva_ban_of' => ['nva_ban_of'],
        'estatus_uf' => ['estatus_uf'],
        'numero_tel_contrato' => ['numero_tel_contrato'],
        'asignacion_origen' => ['asignacion'],
        'pagos_mes' => ['pagos_en_mayo', 'pagos_mes', 'pagos'],
        'num_telefono_ov' => ['num_telefono_ov'],
        'flp' => ['flp'],
        'reetiqueta' => ['reetiqueta'],
        'ban_vencimiento' => ['ban_vencimiento'],
        'ban_bracket_vencimiento' => ['ban_bracket_vencimiento'],
        'asignada' => ['asignada'],
        'canal' => ['canal'],
        // layout simple (compatibilidad)
        'estatus' => ['estatus', 'status', 'estado'],
        'fecha_entrega' => ['fecha_de_entrega', 'fecha_entrega', 'entrega'],
    ];

    private const FECHAS = ['fecha_emision', 'fecha_creacion_contrato', 'flp', 'fecha_entrega'];

    private const ESTATUS = [
        'con adeudo' => 'con_adeudo',
        'pago parcial' => 'pago_parcial',
        'pago total' => 'pago_total',
    ];

    public function cargar(string $path, string $nombreArchivo, string $tipoOrigen, ?string $nombre = null): array
    {
        $datos = $this->leer($path, $nombreArchivo);
        if (! isset($datos['map']['numero'])) {
            throw new \RuntimeException(
                'No se encontró la columna del número (NUMERO_TEL_CONTRATO o Numero).'
            );
        }
        $map = $datos['map'];

        return DB::transaction(function () use ($datos, $map, $nombreArchivo, $tipoOrigen, $nombre) {
            $asignacion = $this->resolverAsignacion($tipoOrigen, $nombreArchivo, $nombre);

            $insertadas = 0;
            $repetidas = [];
            $invalidas = [];
            $numeroIds = [];   // DN -> numero_id ya resuelto en esta carga

            foreach ($datos['rows'] as $i => $row) {
                $numero = $this->limpiarNumero($this->celda($row, $map, 'numero'));
                if (! preg_match('/^\d{10}$/', $numero)) {
                    $invalidas[] = ['fila' => $i + 2, 'valor' => $numero, 'motivo' => 'número inválido (10 dígitos)'];
                    continue;
                }

                // Se guardan todas las emisiones (filas), pero el catálogo de
                // números y el conteo de repetidas son por DN único.
                if (isset($numeroIds[$numero])) {
                    $numeroId = $numeroIds[$numero];
                } else {
                    $numModel = Numero::firstOrNew(['numero' => $numero]);
                    if ($numModel->exists) {
                        $repetidas[] = $numero;   // existía en una carga anterior
                        $numModel->veces_asignado = ($numModel->veces_asignado ?? 0) + 1;
                    } else {
                        $numModel->primera_vez_visto = now();
                        $numModel->veces_asignado = 1;
                    }
                    $numModel->save();
                    $numeroId = $numModel->id;
                    $numeroIds[$numero] = $numeroId;
                }

                AsignacionCuenta::create($this->filaACuenta($asignacion->id, $numeroId, $numero, $row, $map));
                $insertadas++;
            }

            $asignacion->total_cuentas = $asignacion->cuentas()->count();
            $asignacion->save();

            return [
                'asignacion' => $asignacion,
                'total' => count($datos['rows']),
                'insertadas' => $insertadas,
                'repetidas' => array_values(array_unique($repetidas)),
                'duplicadas' => 0,
                'invalidas' => $invalidas,
            ];
        });
    }

    /** Construye el arreglo de atributos de la cuenta a partir de la fila. */
    private function filaACuenta(int $asignacionId, int $numeroId, string $numero, array $row, array $map): array
    {
        $estatus = $this->normalizarEstatus($this->celda($row, $map, 'estatus'));
        // El monto del adeudo viene del archivo: fija el saldo de referencia,
        // para detectar pagos desde la primera consulta del RPA.
        $monto = $this->celdaDecimal($row, $map, 'monto_emision');

        return [
            'asignacion_id' => $asignacionId,
            'numero_id' => $numeroId,
            'numero' => $numero,
            'fecha_entrega' => $this->celdaFecha($row, $map, 'fecha_entrega'),
            'estatus_carga' => $estatus,
            'estatus_cobranza' => 'con_adeudo',
            'tipo_linea' => 'desconocido',
            'cerrada' => false,
            'saldo_referencia' => $monto,
            'saldo_actual' => $monto,
            // datos de origen
            'fecha_emision' => $this->celdaFecha($row, $map, 'fecha_emision'),
            'mes_emision' => $this->celda($row, $map, 'mes_emision'),
            'monto_emision' => $this->celdaDecimal($row, $map, 'monto_emision'),
            'num_edo_cuenta' => $this->celda($row, $map, 'num_edo_cuenta'),
            'estatus_contrato' => $this->celda($row, $map, 'estatus_contrato'),
            'fecha_creacion_contrato' => $this->celdaFecha($row, $map, 'fecha_creacion_contrato'),
            'nva_ban_of' => $this->celda($row, $map, 'nva_ban_of'),
            'estatus_uf' => $this->celda($row, $map, 'estatus_uf'),
            'numero_tel_contrato' => $this->celda($row, $map, 'numero_tel_contrato'),
            'asignacion_origen' => $this->celda($row, $map, 'asignacion_origen'),
            'pagos_mes' => $this->celda($row, $map, 'pagos_mes'),
            'num_telefono_ov' => $this->celda($row, $map, 'num_telefono_ov'),
            'flp' => $this->celdaFecha($row, $map, 'flp'),
            'reetiqueta' => $this->celda($row, $map, 'reetiqueta'),
            'ban_vencimiento' => $this->celdaInt($row, $map, 'ban_vencimiento'),
            'ban_bracket_vencimiento' => $this->celda($row, $map, 'ban_bracket_vencimiento'),
            'asignada' => $this->celda($row, $map, 'asignada'),
            'canal' => $this->celda($row, $map, 'canal'),
        ];
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
                if (! $this->filaVacia($cells)) {
                    $rows[] = $cells;
                }
            }
            break;
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
                if ($h !== '' && in_array($h, $alias, true)) {
                    $map[$clave] = $idx;
                    break;
                }
            }
        }
        return $map;
    }

    private function normalizar(?string $s): string
    {
        $s = trim((string) $s);
        // Quitar acentos en ambos casos antes de bajar a minúsculas, porque
        // strtolower/mb_strtolower no cubren bien mayúsculas acentuadas (Ó).
        $s = strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', 'Ü' => 'u',
        ]);
        $s = mb_strtolower($s, 'UTF-8');
        return preg_replace('/\s+/', '_', $s);
    }

    // ---- acceso a celdas por clave mapeada ----

    private function raw(array $row, array $map, string $clave)
    {
        return isset($map[$clave]) ? ($row[$map[$clave]] ?? null) : null;
    }

    private function celda(array $row, array $map, string $clave): ?string
    {
        $v = $this->raw($row, $map, $clave);
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (string) (int) $v;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function celdaFecha(array $row, array $map, string $clave): ?string
    {
        $v = $this->raw($row, $map, $clave);
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTime::createFromInterface($v))->toDateString();
        }
        $s = trim((string) $v);
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

    private function celdaDecimal(array $row, array $map, string $clave): ?float
    {
        $v = $this->celda($row, $map, $clave);
        if ($v === null) {
            return null;
        }
        $v = preg_replace('/[^0-9.\-]/', '', $v);
        return $v === '' ? null : (float) $v;
    }

    private function celdaInt(array $row, array $map, string $clave): ?int
    {
        $v = $this->celdaDecimal($row, $map, $clave);
        return $v === null ? null : (int) $v;
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

    private function filaVacia(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c instanceof \DateTimeInterface) {
                return false;
            }
            if (trim((string) $c) !== '') {
                return false;
            }
        }
        return true;
    }
}
