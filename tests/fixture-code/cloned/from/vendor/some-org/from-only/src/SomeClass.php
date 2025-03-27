<?php

namespace SomeOrg\FromOnly;

/**
 * This class shouldn't appear in any of the results, because this module doesn't exist in "to".
 */
class SomeClass
{
    public function someMethod(array $returnMe = []): array
    {
        return $returnMe;
    }
}
