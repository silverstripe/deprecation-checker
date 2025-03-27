<?php

namespace SomeOrg\Module1\Tests;

/**
 * This class shouldn't appear in any of the results because it's in tests/
 */
class SomeTestClass
{
    public function someMethod(array $returnMe = []): array
    {
        return $returnMe;
    }
}
