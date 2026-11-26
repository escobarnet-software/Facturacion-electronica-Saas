<?php

// Solo lo que cambia respecto a la configuración por defecto de Livewire (se fusiona con ella).
return [
    // Compilación de Livewire/Alpine compatible con una CSP estricta: sin eval ni new Function,
    // así la cabecera Content-Security-Policy no necesita 'unsafe-eval'.
    'csp_safe' => true,
];
