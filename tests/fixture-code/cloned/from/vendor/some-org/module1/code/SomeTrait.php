<?php

namespace SomeOrg\Module1;

/**
 * Note that the method and property below are not counted because they're just IDE hints, not real API.
 *
 * @method string fakeMethod()
 * @property string $fakeProperty
 */
trait SomeTrait
{
    public bool $someProperty;

    protected $wasProtected = 'oooh';

    private array $ignorePrivate;

    private static string $someConfig = 'abc123';

    private static $db = [
        'FieldOne' => 'Varchar',
    ];

    public function someMethod(array $returnMe = []): array
    {
        return $returnMe;
    }

    public function anotherMethod($someParam, array &$param2)
    {
        $param2 = 'hello';
    }

    /**
     * Note that the PHPDoc param and return type will be ignored
     * @param string $someParam
     * @return string|bool
     */
    protected static function thirdMethod()
    {
        // no-op
    }

    /** @internal */
    public function internalMethod()
    {
        // no-op
    }

    private function privateMethod()
    {
        // no-op
    }
}
