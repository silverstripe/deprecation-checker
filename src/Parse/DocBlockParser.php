<?php

namespace SilverStripe\DeprecationChecker\Parse;

use Doctum\Parser\DocBlockParser as BaseParser;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tags\Method;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\PropertyRead;
use phpDocumentor\Reflection\DocBlock\Tags\Property;
use phpDocumentor\Reflection\DocBlock\Tags\PropertyWrite;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;

/**
 * DockBlockParser that doesn't parse tags which resolve types.
 */
class DocBlockParser extends BaseParser
{
    protected function parseTag(Tag $tag)
    {
        // We don't want to parse these tags, because they result in getting soft-API results
        // e.g. @param may hint the type for parameters, but not in a way that is relevant for checking for API breaking changes
        $class = get_class($tag);
        switch ($class) {
            case Var_::class:
            case Return_::class:
                return null;
            case Method::class:
                return [];
            case Property::class:
            case PropertyRead::class:
            case PropertyWrite::class:
                // The parsing logic expects explicitly to have an array returned for these in this format.
                /** @var Property|PropertyRead|PropertyWrite $tag */
                return [[], '', ''];
            case Param::class:
                // The parsing logic expects explicitly to have an array returned for these in this format.
                // For params it explicitly needs the name of the param to be included.
                /** @var Property|PropertyRead|PropertyWrite|Param $tag */
                return [[], ltrim($tag->getVariableName() ?? '', '$'), ''];
        }

        return parent::parseTag($tag);
    }
}
