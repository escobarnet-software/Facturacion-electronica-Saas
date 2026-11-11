<?php

declare(strict_types=1);

namespace App\Dte\Validacion;

use Opis\JsonSchema\Parsers\Drafts\Draft07;
use Opis\JsonSchema\Parsers\Keywords\MultipleOfKeywordParser;

/** Draft-07 (el de los esquemas de Hacienda) con «multipleOf» exacto. */
final class Draft07Exacto extends Draft07
{
    protected function getKeywordParsers(): array
    {
        return array_map(
            fn ($p) => $p instanceof MultipleOfKeywordParser ? new ParserMultiploExacto('multipleOf') : $p,
            parent::getKeywordParsers(),
        );
    }
}
