<?php

namespace SomeOrg\Module2\Something;

use SilverStripe\Security\Member;

final class ClassOne
{
    protected const int CONST_TWO = 2;

    public ?string $property1 = 'oooooh';

    protected int|Member|null $property2 = null;

    public int $property4;

    public readonly bool $property5;

    protected static function methodOne()
    {
        // no-op
    }

    public static function methodTwo(array $variadicParam): null
    {
        return null;
    }

    protected function methodThree()
    {

    }

    public static function &methodFour()
    {

    }
}
