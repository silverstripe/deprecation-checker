<?php

namespace SomeOrg\Module1\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

abstract class ModelOne extends DataObject
{
    public string $someProperty = 'Some value';

    /** @internal */
    private static string $someConfig = 'abc123';

    /** @internal */
    private static ?array $ignoreInternal = null;

    private static $db = [
        'FieldTwo' => 'Enum("Red,Blue,Green","Red")',
    ];

    private static $fixed_fields = [
        'SomeField' => 'SomeChangedSpec',
    ];

    private static $has_one = [
        'SecondRelation' => [
            'class' => DataObject::class,
            'multirelational' => true,
        ],
        'ThirdRelation' => [
            'class' => Member::class,
            'multirelational' => false,
        ],
        'FourthRelation' => Member::class,
    ];

    private static $has_many = [
        'HasManyTwo' => ModelTwo::class . '.SomeOtherRelation',
        'HasManyThree' => DataObject::class,
    ];

    private static $many_many = [
        'ManyManyOne' => [
            'through' => 'SomeOtherOrg\SomeNamespace\SomeClass',
            'from' => 'Team',
            'to' => 'Supporter',
        ],
        'ManyManyTwo' => ModelOne::class,
        'ManyManyThree' => [
            'through' => DataObject::class,
            'from' => 'Supporter',
            'to' => 'Team',
        ],
    ];

    private static $belongs_to = [
        'BelongsToOne' => Member::class,
    ];

    private static $belongs_many_many = [
        'BelongsManyTwo' => ModelOne::class . '.SomeRelation',
    ];

    abstract public static function someMethod(): string|int;

    public function anotherMethod(?array &$param, ...$param2): bool|DataObject
    {
        $param = 'hello';
        return true;
    }

    /**
     * Note that the PHPDoc param and return type will be ignored
     * @param string|int $someParam
     * @return void
     */
    protected static function thirdMethod(?string $param): array
    {
        return [];
    }

    protected static function fourthMethod(?int $changeType = null)
    {
        // no-op
    }
}
