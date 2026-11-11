<?php

declare(strict_types=1);

namespace App\Dte\Validacion;

use Opis\JsonSchema\Info\SchemaInfo;
use Opis\JsonSchema\Keyword;
use Opis\JsonSchema\Keywords\MultipleOfKeyword;
use Opis\JsonSchema\Parsers\Keywords\MultipleOfKeywordParser;
use Opis\JsonSchema\Parsers\SchemaParser;

/** Igual que el parser de Opis, pero produce MultiploExacto. */
final class ParserMultiploExacto extends MultipleOfKeywordParser
{
    public function parse(SchemaInfo $info, SchemaParser $parser, object $shared): ?Keyword
    {
        $keyword = parent::parse($info, $parser, $shared);

        if ($keyword !== null && $keyword::class === MultipleOfKeyword::class) {
            return new MultiploExacto((float) $this->keywordValue($info->data()));
        }

        return $keyword;
    }
}
