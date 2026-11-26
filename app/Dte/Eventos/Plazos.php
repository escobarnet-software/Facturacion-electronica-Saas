<?php

declare(strict_types=1);

namespace App\Dte\Eventos;

use App\Models\Dte;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Plazos de la Normativa de Cumplimiento DTE v2.0 (reglas 9, 13.1–13.4; Cuadro 6), en hora de El Salvador.
 * Cada «límite» es el ÚLTIMO instante válido (23:59:59 del último día permitido).
 */
final class Plazos
{
    /** CCF, NR, NC, ND, CR, CL, DCL y CD: hasta el 10.º día hábil del mes siguiente al del sello. */
    public const DECIMO_DIA_HABIL = ['03', '04', '05', '06', '07', '08', '09', '15'];

    /** FE, FEX y FSE: 3 meses desde el sello (FE y FEX de medicamentos perecederos: 2 años). */
    public const TRES_MESES = ['01', '11', '14'];

    public static function zona(): string
    {
        return (string) config('dte.zona_horaria', 'America/El_Salvador');
    }

    public function esHabil(CarbonImmutable $fecha): bool
    {
        $f = $fecha->setTimezone(self::zona());
        if ($f->isWeekend()) {
            return false;
        }

        return ! in_array($f->format('Y-m-d'), $this->feriados((int) $f->format('Y')), true);
    }

    /** @return list<string> feriados del año en formato AAAA-MM-DD */
    public function feriados(int $anio): array
    {
        $dias = array_map(fn (string $md) => "{$anio}-{$md}", (array) config('dte.feriados.fijos', []));

        $pascua = self::domingoDePascua($anio);
        $santos = ['jueves' => -3, 'viernes' => -2, 'sabado' => -1];
        foreach ((array) config('dte.feriados.semana_santa', []) as $dia) {
            $dias[] = $pascua->addDays($santos[$dia])->format('Y-m-d');
        }

        $adicionales = array_filter((array) config('dte.feriados.adicionales', []), fn ($d) => str_starts_with((string) $d, "{$anio}-"));

        return array_values(array_unique([...$dias, ...$adicionales]));
    }

    /**
     * Domingo de Pascua (calendario gregoriano), algoritmo anónimo de Meeus/Jones/Butcher.
     * Sin depender de la extensión «calendar» de PHP (easter_days), que no siempre está instalada.
     */
    public static function domingoDePascua(int $anio): CarbonImmutable
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($anio, $mes, $dia, 0, 0, 0, self::zona());
    }

    /** Último instante del 10.º día hábil del mes siguiente al de $fecha. */
    public function decimoDiaHabilMesSiguiente(CarbonImmutable $fecha): CarbonImmutable
    {
        $dia = $fecha->setTimezone(self::zona())->startOfMonth()->addMonthNoOverflow()->startOfDay();
        $habiles = 0;
        while (true) {
            if ($this->esHabil($dia) && ++$habiles === 10) {
                return $dia->endOfDay();
            }
            $dia = $dia->addDay();
        }
    }

    /** Último instante para transmitir la invalidación de un DTE (Cuadro 6). */
    public function limiteInvalidacion(Dte $dte): CarbonImmutable
    {
        $sello = $this->fechaSello($dte);

        if (in_array($dte->tipo, self::TRES_MESES, true)) {
            $meses = $dte->medicamento_perecedero && in_array($dte->tipo, ['01', '11'], true) ? 24 : 3;

            return $sello->addMonthsNoOverflow($meses)->endOfDay();
        }
        if (in_array($dte->tipo, self::DECIMO_DIA_HABIL, true)) {
            return $this->decimoDiaHabilMesSiguiente($sello);
        }

        throw new LogicException("Tipo de DTE sin plazo de invalidación: {$dte->tipo}");
    }

    /** Evento de Retorno: hasta 3 meses desde el sello del documento original (FE, FEX, FSE). */
    public function limiteRetorno(Dte $dte): CarbonImmutable
    {
        return $this->fechaSello($dte)->addMonthsNoOverflow(3)->endOfDay();
    }

    /** Operaciones Especiales y su invalidación: 10.º día hábil del mes siguiente. */
    public function limiteOperacionesEspeciales(CarbonImmutable $fechaOperaciones): CarbonImmutable
    {
        return $this->decimoDiaHabilMesSiguiente($fechaOperaciones);
    }

    /** El Evento de Contingencia se transmite dentro de las 24 h siguientes al cese de la falla. */
    public function limiteEventoContingencia(CarbonImmutable $cese): CarbonImmutable
    {
        return $cese->addHours((int) config('dte.contingencia.evento_horas', 24));
    }

    /** Los DTE de la contingencia se transmiten dentro de 72 h desde el sello del evento. */
    public function limiteTransmisionContingencia(CarbonImmutable $selloEvento): CarbonImmutable
    {
        return $selloEvento->addHours((int) config('dte.contingencia.transmision_horas', 72));
    }

    public function dentroDelPlazo(CarbonImmutable $momento, CarbonImmutable $limite): bool
    {
        return $momento->lessThanOrEqualTo($limite);
    }

    private function fechaSello(Dte $dte): CarbonImmutable
    {
        if ($dte->sello_recibido === null || $dte->fh_procesamiento === null) {
            throw new LogicException('Solo se calculan plazos de documentos con sello de recepción.');
        }

        return CarbonImmutable::parse($dte->fh_procesamiento)->setTimezone(self::zona());
    }
}
