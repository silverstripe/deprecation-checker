<?php

namespace SomeOrg\Module2\Something;

abstract class ClassTwo
{
    abstract protected static function someMethod(array|int $returnMe): ?array;
}
