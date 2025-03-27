<?php

namespace SomeOrg\Module1;

interface SomeInterfaceTwo
{
    public bool $someProperty;

    private static string $someConfig = 'abc123';

    private static $db = [
        'FieldOne' => 'Varchar',
    ];

    public function someMethod(array $returnMe = []): array;

    public function anotherMethod($someParam, array &$param2);

    /**
     * Note that the PHPDoc param and return type will be ignored
     * @param string $someParam
     * @return string|bool
     */
    public static function thirdMethod();

    /** @internal */
    public function internalMethod();
}
