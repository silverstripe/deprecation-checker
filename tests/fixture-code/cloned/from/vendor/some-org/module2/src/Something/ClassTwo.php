<?php

namespace SomeOrg\Module2\Something;

class ClassTwo
{
    public function __construct()
    {
        // note, no params in this one
    }

    public function someMethod(array $returnMe = []): array
    {
        return $returnMe;
    }
}
