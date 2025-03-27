<?php

namespace SomeOrg\Module1;

/**
 * This class shouldn't appear in any of the results because it's in thirdparty/
 */
abstract class SomeThirdpartyCode
{
    abstract protected static function someMethod(array|int $returnMe): ?array;
}
