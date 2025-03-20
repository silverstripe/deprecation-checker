<?php

namespace Silverstripe\DeprecationChangelogGenerator\Compare;

use Doctum\Project;
use Doctum\Reflection\ClassReflection;
use Doctum\Reflection\MethodReflection;
use Doctum\Reflection\ParameterReflection;

class ExtensionMethodReflection extends MethodReflection implements ExtensionReflection
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
        // Direct copy/paste from MethodReflection::fromArray() but swap self for static
        $method                     = new static($array['name'], $array['line']);
        $method->shortDesc          = $array['short_desc'];
        $method->longDesc           = $array['long_desc'];
        $method->hint               = $array['hint'];
        $method->hintDesc           = $array['hint_desc'];
        $method->tags               = $array['tags'];
        $method->modifiers          = $array['modifiers'];
        $method->byRef              = $array['is_by_ref'];
        $method->exceptions         = $array['exceptions'];
        $method->errors             = $array['errors'];
        $method->see                = $array['see'] ?? [];// New in 5.4.0
        $method->isIntersectionType = $array['is_intersection_type'] ?? false;// New in 5.5.3


        foreach ($array['parameters'] as $parameter) {
            $method->addParameter(ParameterReflection::fromArray($project, $parameter));
        }

        return $method;
    }
}
