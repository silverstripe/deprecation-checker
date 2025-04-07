<?php

namespace SomeOrg\Module1\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\Security\Member;

/**
 * @deprecated 1.2.3 I've deprecated this even though it's not removed.
 */
class ExtensionClass extends Extension
{
    private static $db = [
        'OverrideExtension' => 'Int',
        'FromExtensionDB' => 'Boolean(true)',
    ];

    private static $has_one = [
        'FromExtensionHasOne' => Member::class,
    ];

    private static $array_config = [
        'some-value' => 123,
    ];
}
