<?php

namespace SomeOrg\Module2\Something;

use SilverStripe\ORM\DataObject;

class DataObjectOne extends DataObject
{
    private static int $config1 = 1;

    /** @internal */
    private static $config3;

    private static $config4 = 4;
}
