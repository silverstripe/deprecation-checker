<?php

namespace SomeOrg\Module1;

/**
 * This class shouldn't appear in any of the results because it's in thirdparty/
 */
class SomeThirdpartyCode
{
    public function someMethod(array $returnMe = []): array
    {
        return $returnMe;
    }
}
