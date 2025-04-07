<?php

namespace SilverStripe\DeprecationChecker\Parse;

use Doctum\Parser\Filter\DefaultFilter;
use Doctum\Reflection\PropertyReflection;

class IncludeConfigFilter extends DefaultFilter
{
    public function acceptProperty(PropertyReflection $property)
    {
        // Explicitly allow private static properties
        return $property->isStatic() || parent::acceptProperty($property);
    }
}
