<?php

namespace SomeOrg\Module2\Something;

use SilverStripe\Security\Member;

class ClassOne
{
    public const CONST_ONE = 'one';

    protected const string CONST_TWO = 'two';

    private const CONST_THREE = 3;

    protected $property1;

    protected string|Member $property2;

    public $property3;

    public readonly int $property4;

    public bool $property5;

    public function methodOne()
    {
        // no-op
    }

    protected static function methodTwo(...$variadicParam): void
    {
        // no-op
    }

    protected function &methodThree()
    {

    }

    public static function methodFour()
    {

    }
}
