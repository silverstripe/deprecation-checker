<?php

namespace SomeOrg\Module1\Model;

use SilverStripe\ORM\DataObject;
use SomeOrg\Module1\Extension\ExtensionClass;
use SomeOrg\Module1\Extension\ExtensionClassTwo;

class ModelTwo extends DataObject
{
    private static $db = [
        'OverrideExtension' => 'Varchar',
    ];

    private static array $extensions = [
        ExtensionClass::class,
        'extension-two' => ExtensionClassTwo::class,
    ];
}
