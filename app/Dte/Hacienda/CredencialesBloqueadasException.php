<?php

namespace App\Dte\Hacienda;

/** Se alcanzó el límite de logins fallidos: no se vuelve a intentar hasta que el cliente cargue credenciales nuevas. */
final class CredencialesBloqueadasException extends HaciendaException {}
