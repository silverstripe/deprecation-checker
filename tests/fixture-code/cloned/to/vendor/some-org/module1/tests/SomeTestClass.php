<?php

namespace SomeOrg\Module1\Tests;

/**
 * This class shouldn't appear in any of the results because it's in tests/
 */
abstract class SomeTestClass
{
    abstract protected static function someMethod(array|int $returnMe): ?array;
}
