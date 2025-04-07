<?php

namespace SomeOrg\Module1\Model;

use SilverStripe\ORM\DataObject;
use SomeOrg\Module1\Extension\ExtensionClass;
use SomeOrg\Module1\Extension\ExtensionClassTwo;

class ModelTwo extends DataObject
{
    private static $db = [
        'OverrideExtension' => 'Varchar',
        'MoveToExtension' => 'HTMLText',
    ];

    private static array $extensions = [
        ExtensionClass::class,
        'extension-two' => ExtensionClassTwo::class,
    ];

    public function moveMethodToExtension()
    {
        // no-op
    }

    /**
     * Since this is protected in the extension in "to" it isn't counted since it can't be called directly.
     * @deprecated
     * @deprecated 1.2.3 I wonder what happens with two of these
     * @deprecated Nevermind, have three.
     */
    public function moveMethodToExtensionButNot()
    {
        // no-op
    }
}
