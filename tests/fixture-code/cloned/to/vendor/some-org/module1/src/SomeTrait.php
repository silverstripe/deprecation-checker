<?php

namespace SomeOrg\Module1;

/**
 * Note that the method and property below are not counted because they're just IDE hints, not real API.
 */
trait SomeTrait
{
    private bool $someProperty;

    /** @internal */
    public $wasProtected = 'oooh';

    private static string $someConfig = '123abc';

    private static $db = [];

    public function someMethod(?array $return = null, $param2 = []): ?array
    {
        return $return;
    }

    abstract protected static function anotherMethod($someParam, array $param2);

    /**
     * Note that the PHPDoc param and return type will be ignored
     * @param string $someParam
     * @return string|bool
     */
    private static function thirdMethod()
    {
        // no-op
    }
}
