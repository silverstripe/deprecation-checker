<?php

namespace SomeOrg\Module1\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SomeOrg\Module1\Model\ModelOne;

class ExtensionClassTwo extends Extension
{
    /**
     * @deprecated 1.2.3 Will be replaced with SomeOrg\Module1\Extension\ExtensionClass.has_one
     */
    private static $has_one = [
        'FromExtensionHasOne' => DataObject::class,
    ];

    private static $has_many = [
        'FromExtensionHasMany' => ModelOne::class,
    ];
}
