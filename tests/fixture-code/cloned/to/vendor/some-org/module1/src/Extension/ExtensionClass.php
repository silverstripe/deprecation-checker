<?php

namespace SomeOrg\Module1\Extension;

use SilverStripe\Core\Extension;

class ExtensionClass extends Extension
{
    private static $db = [
        'OverrideExtension' => 'Int',
        'FromExtensionDB' => 'Boolean(false)',
    ];

    private static array $array_config = [
        'some-key' => 'abc',
    ];
}
