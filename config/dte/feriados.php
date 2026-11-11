<?php

/*
| Días no hábiles para los plazos de Hacienda (10.º día hábil del mes siguiente, etc.).
|
| Fijos: asueto nacional remunerado (Código de Trabajo, art. 190).
| Móviles: Jueves, Viernes y Sábado Santo (se calculan cada año desde la fecha de Pascua).
| Adicionales: días que Hacienda o un decreto declare inhábiles en un año concreto (AAAA-MM-DD).
|
| TODO(normativa): confirmar con Hacienda si cuenta como inhábil toda la Semana Santa, las fiestas
| agostinas (3 al 5 de agosto, San Salvador) y el cierre de fin de año; si es así, agregarlos aquí.
*/

return [
    'fijos' => ['01-01', '05-01', '05-10', '06-17', '08-06', '09-15', '11-02', '12-25'],
    'semana_santa' => ['jueves', 'viernes', 'sabado'],
    'adicionales' => [],
];
