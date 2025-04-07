<?php

namespace SomeOrg\Module1\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Note that the method and property below are not counted because they're just IDE hints, not real API.
 *
 * @method string fakeMethod()
 * @property string $fakeProperty
 * @deprecated This deprecation notice has no version
 */
class ModelOne extends DataObject
{
    public bool $someProperty;

    protected $wasProtected = 'oooh';

    private array $ignorePrivate;

    private static string $someConfig = 'abc123';

    /** @internal */
    private static array $ignoreInternal = ['wow'];

    private static $db = [
        'FieldOne' => 'Varchar',
        'FieldTwo' => 'Enum("Red,Blue,Green","Blue")',
    ];

    private static $fixed_fields = [
        'SomeField' => 'SomeSpec',
    ];

    private static $has_one = [
        'FirstRelation' => Member::class,
        'SecondRelation' => [
            'class' => DataObject::class,
            'multirelational' => false,
        ],
        'ThirdRelation' => [
            'class' => DataObject::class,
            'multirelational' => true,
        ],
        'FourthRelation' => [
            'class' => Member::class,
            'multirelational' => true,
        ],
    ];

    private static $has_many = [
        'HasManyOne' => Member::class,
        'HasManyTwo' => ModelTwo::class . '.SomeRelation',
        'HasManyThree' => __CLASS__ . '.FirstRelation',
    ];

    private static $many_many = [
        'ManyManyOne' => ModelOne::class,
        'ManyManyTwo' => [
            'through' => 'SomeOtherOrg\SomeNamespace\SomeClass',
            'from' => 'Team',
            'to' => 'Supporter',
        ],
        'ManyManyThree' => [
            'through' => DataObject::class,
            'from' => 'Team',
            'to' => 'Supporter',
        ],
        'ManyManyFour' => Member::class,
    ];

    private static $belongs_to = [
        'BelongsToOne' => Member::class . '.SomeRelation',
        'BelongsToTwo' => ModelTwo::class,
    ];

    private static $belongs_many_many = [
        'BelongsManyOne' => Member::class . '.SomeRelation',
        'BelongsManyTwo' => ModelTwo::class,
    ];

    public function someMethod(array $returnMe = []): array
    {
        return $returnMe;
    }

    public function anotherMethod($someParam, array &$param2 = [])
    {
        $param2 = 'hello';
    }

    /**
     * Note that the PHPDoc param and return type will be ignored
     * @param string $someParam
     * @return string|bool
     */
    protected static function thirdMethod(string $param = null)
    {
        // no-op
    }

    protected static function fourthMethod(string $changeType = null)
    {
        // no-op
    }

    /** @internal */
    public function internalMethod()
    {
        // no-op
    }

    private function privateMethod()
    {
        // no-op
    }
}
