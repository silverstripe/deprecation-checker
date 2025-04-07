<?php

namespace SilverStripe\DeprecationChecker\Compare;

use Doctum\Reflection\ClassReflection;

interface ExtensionReflection
{
    public function setExtensionClass(ClassReflection $extensionClass): void;

    public function getExtensionClass(): ?ClassReflection;
}
