<?php

namespace Silverstripe\DeprecationChangelogGenerator\Compare;

use Doctum\Project;
use Doctum\Reflection\ClassReflection;
use Doctum\Reflection\PropertyReflection;

class ExtensionConfigReflection extends PropertyReflection implements ExtensionReflection
{
    private ?ClassReflection $extensionClass = null;

    public function setExtensionClass(ClassReflection $extensionClass): void
    {
        $this->extensionClass = $extensionClass;
    }

    public function getExtensionClass(): ?ClassReflection
    {
        return $this->extensionClass;
    }

    public static function fromArray(Project $project, array $array): static
    {
        // Direct copy/paste from PropertyReflection::fromArray() but swap self for static
        $property            = new self($array['name'], $array['line']);
        $property->shortDesc = $array['short_desc'];
        $property->longDesc  = $array['long_desc'];
        $property->hint      = $array['hint'];
        $property->hintDesc  = $array['hint_desc'];
        $property->tags      = $array['tags'];
        $property->modifiers = $array['modifiers'];
        $property->default   = $array['default'];
        $property->errors    = $array['errors'];

        if (isset($array['is_read_only'])) {// New in 5.4.0
            $property->setReadOnly($array['is_read_only']);
        }

        if (isset($array['is_write_only'])) {// New in 5.4.0
            $property->setWriteOnly($array['is_write_only']);
        }

        if (isset($array['is_intersection_type'])) {// New in 5.5.3
            $property->setIntersectionType($array['is_intersection_type']);
        }

        return $property;
    }
}
