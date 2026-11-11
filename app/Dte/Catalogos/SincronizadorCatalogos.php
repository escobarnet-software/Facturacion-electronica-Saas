<?php

declare(strict_types=1);

namespace App\Dte\Catalogos;

use App\Models\CatValor;
use Illuminate\Support\Facades\DB;

/**
 * Compara el Excel oficial con la tabla cat_valores y aplica los cambios.
 *
 * Idempotente: aplicarlo dos veces con el mismo Excel no cambia nada.
 * Lo que desaparece del Excel NO se borra (documentos viejos lo usan):
 * se marca vigente = false.
 */
final class SincronizadorCatalogos
{
    public function __construct(private readonly LectorCatalogosExcel $lector) {}

    /**
     * @return array{version: string, nuevos: list<array<string, string>>, cambiados: list<array<string, string>>, retirados: list<array<string, string>>, conteo: array<string, int>}
     */
    public function comparar(string $archivo): array
    {
        $excel = $this->lector->leer($archivo);
        $actuales = CatValor::query()->get()->keyBy(fn (CatValor $v) => $this->llave($v->catalogo, $v->padre, $v->codigo));

        $nuevos = $cambiados = [];
        $vistos = [];
        $conteo = [];

        foreach ($excel['catalogos'] as $catalogo => $datos) {
            $conteo[$catalogo] = count($datos['valores']);
            foreach ($datos['valores'] as $v) {
                $llave = $this->llave($catalogo, $v['padre'], $v['codigo']);
                $vistos[$llave] = true;
                $fila = ['catalogo' => $catalogo, 'padre' => $v['padre'], 'codigo' => $v['codigo'], 'valor' => $v['valor'], 'seccion' => (string) $v['seccion']];

                $actual = $actuales->get($llave);
                if ($actual === null) {
                    $nuevos[] = $fila;
                } elseif ($actual->valor !== $v['valor'] || (string) $actual->seccion !== (string) $v['seccion'] || ! $actual->vigente) {
                    $cambiados[] = $fila + ['valor_anterior' => $actual->valor];
                }
            }
        }

        $retirados = $actuales
            ->filter(fn (CatValor $v, string $llave) => $v->vigente && ! isset($vistos[$llave]))
            ->map(fn (CatValor $v) => ['catalogo' => $v->catalogo, 'padre' => $v->padre, 'codigo' => $v->codigo, 'valor' => $v->valor])
            ->values()->all();

        return ['version' => $excel['version'], 'nuevos' => $nuevos, 'cambiados' => $cambiados, 'retirados' => $retirados, 'conteo' => $conteo];
    }

    /**
     * @return array{version: string, nuevos: list<array<string, string>>, cambiados: list<array<string, string>>, retirados: list<array<string, string>>, conteo: array<string, int>}
     */
    public function aplicar(string $archivo): array
    {
        $diferencias = $this->comparar($archivo);
        $version = $diferencias['version'];

        DB::transaction(function () use ($diferencias, $version) {
            $ahora = now();
            foreach ([...$diferencias['nuevos'], ...$diferencias['cambiados']] as $fila) {
                CatValor::query()->updateOrCreate(
                    ['catalogo' => $fila['catalogo'], 'padre' => $fila['padre'], 'codigo' => $fila['codigo']],
                    ['valor' => $fila['valor'], 'seccion' => $fila['seccion'] !== '' ? $fila['seccion'] : null, 'vigente' => true, 'version' => $version, 'updated_at' => $ahora],
                );
            }
            foreach ($diferencias['retirados'] as $fila) {
                CatValor::query()
                    ->where(['catalogo' => $fila['catalogo'], 'padre' => $fila['padre'], 'codigo' => $fila['codigo']])
                    ->update(['vigente' => false, 'version' => $version, 'updated_at' => $ahora]);
            }
        });

        return $diferencias;
    }

    private function llave(string $catalogo, string $padre, string $codigo): string
    {
        return "{$catalogo}|{$padre}|{$codigo}";
    }
}
