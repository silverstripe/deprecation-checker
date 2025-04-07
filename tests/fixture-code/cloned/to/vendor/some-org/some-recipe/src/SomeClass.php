<?php

namespace SomeOrg\SomeRecipe;

/**
 * This class shouldn't appear in any of the results, because this isn't a module
 */
abstract class SomeClass
{
    abstract protected static function someMethod(array|int $returnMe): ?array;
}
