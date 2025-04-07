<?php

namespace SomeOrg\Module1\Extension;

use SilverStripe\Core\Extension;
use SomeOrg\Module1\Model\ModelTwo;

class ExtensionClassTwo extends Extension
{
    private static $db = [
        'MoveToExtension' => 'Varchar',
    ];

    private static $has_many = [
        'FromExtensionHasMany' => ModelTwo::class,
    ];

    public function moveMethodToExtension(): void
    {
        // no-op
    }

    protected function moveMethodToExtensionButNot()
    {
        // no-op
    }
}
