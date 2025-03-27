<?php

namespace SomeOrg\Module1;

interface SomeInterface
{
    public function someMethod(array $returnMe = [], $anotherParam): array;

    public function anotherMethod($someOtherParam);

    /**
     * Note that the PHPDoc param and return type will be ignored
     */
    public function thirdMethod(): void;

    /** @internal */
    public static function internalMethod(int $param = 2): static;
}
