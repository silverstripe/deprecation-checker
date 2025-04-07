<?php

namespace SomeOrg\SomeRecipe;

/**
 * This class shouldn't appear in any of the results, because this isn't a module
 */
class SomeClass
{
    public function someMethod(array $returnMe = []): array
    {
        return $returnMe;
    }
}
