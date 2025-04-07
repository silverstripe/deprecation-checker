<?php

namespace SomeOrg\Module2\Something;

use SilverStripe\ORM\DataObject;

final class DataObjectOne extends DataObject
{
    private static string $config1 = 'one';

    private static int $config2 = 2;

    /**
     * @deprecated This deprecation notice has no version
     */
    private static $config3;

    private static $config4;
}
