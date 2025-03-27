<?php

namespace SilverStripe\DeprecationChangelogGenerator\Tests;

use Doctum\Project;
use PHPUnit\Framework\TestCase;
use SilverStripe\DeprecationChangelogGenerator\Compare\BreakingChangesComparer;
use SilverStripe\DeprecationChangelogGenerator\Parse\ParserFactory;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class BreakingChangesComparerTest extends TestCase
{
    private static Project $project;

    public static function setUpBeforeClass(): void
    {
        // Note that from-only wouldn't be included in the supported modules list
        // because we only care about modules in the "to" list. See GenerateCommand::findSupportedModules().
        // But we do have some code in some-org/from-only to check that this list is respected.
        $supportedModules = [
            'some-org/module1' => [
                'type' => 'module',
                'packagist' => 'some-org/module1',
            ],
            'some-org/module2' => [
                'type' => 'module',
                'packagist' => 'some-org/module2',
            ],
            'some-org/to-only' => [
                'type' => 'module',
                'packagist' => 'some-org/to-only',
            ],
            'some-org/some-recipe' => [
                'type' => 'recipe',
                'packagist' => 'some-org/some-recipe',
            ],
            'some-org/some-theme' => [
                'type' => 'theme',
                'packagist' => 'some-org/some-theme',
            ],
            // We need to include framework with some stubs for some of the type checks
            // e.g. checking for the configurable trait on DataObject subclasses.
            'silverstripe/framework' => [
                'type' => 'module',
                'packagist' => 'silverstripe/framework',
            ],
        ];
        $factory = new ParserFactory($supportedModules, Path::join(__DIR__, 'fixture-code'));
        BreakingChangesComparerTest::$project = $factory->buildProject();
        BreakingChangesComparerTest::$project->parse();
    }

    public static function tearDownAfterClass(): void
    {
        // Remove any cache from running the test.
        $cacheDir = Path::join(__DIR__, 'fixture-code/cache');
        if (is_dir($cacheDir)) {
            $filesystem = new Filesystem();
            $filesystem->remove($cacheDir);
        }
    }

    public function testCompareActions()
    {
        $comparer = new BreakingChangesComparer(new NullOutput());

        // There should be no actions before we've started comparing
        $this->assertEmpty($comparer->getActionsToTake());

        $comparer->compare(BreakingChangesComparerTest::$project);
        $this->assertEquals($this->getExpectedActions(), $comparer->getActionsToTake(), 'should get expected actions');
    }

    public function testCompareChanges()
    {
        $comparer = new BreakingChangesComparer(new NullOutput());

        // There should be no breaking changes before we've started comparing
        $this->assertEmpty($comparer->getBreakingChanges());

        $comparer->compare(BreakingChangesComparerTest::$project);
        $this->assertEquals($this->getExpectedChanges(), $comparer->getBreakingChanges(), 'should get expected changes');
    }

    private function getExpectedActions(): array
    {
        $removedNotDeprecated = 'This API was removed, but hasn\'t been deprecated.';
        $internalNotDeprecated = 'This API was made @internal, but hasn\'t been deprecated.';
        $fixVersionNumber = 'The version number for this deprecation notice is missing or malformed. Should be in the form "1.2.0".';
        $missingMessage = 'The deprecation annotation is missing a message.';
        return [
            'some-org/module1' => [
                'deprecate' => [
                    'method' => [
                        'SomeOrg\Module1\SomeTrait::thirdMethod()' => [
                            'name' => 'thirdMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'method',
                            'message' => $removedNotDeprecated,
                        ],
                    ],
                    'class' => [
                        'SomeOrg\Module1\SomeClass' => [
                            'name' => 'SomeOrg\Module1\SomeClass',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeClass.php',
                            'apiType' => 'class',
                            'message' => $internalNotDeprecated,
                        ],
                        'SomeOrg\Module1\SomeTraitTwo' => [
                            'name' => 'SomeOrg\Module1\SomeTraitTwo',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeTraitTwo.php',
                            'apiType' => 'trait',
                            'message' => $removedNotDeprecated,
                        ],
                    ],
                    'property' => [
                        'SomeOrg\Module1\SomeInterface->someProperty' => [
                            'name' => 'someProperty',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeInterface.php',
                            'class' => 'SomeOrg\Module1\SomeInterface',
                            'apiType' => 'property',
                            'message' => $removedNotDeprecated,
                        ],
                        'SomeOrg\Module1\SomeTrait->wasProtected' => [
                            'name' => 'wasProtected',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'property',
                            'message' => $internalNotDeprecated,
                        ],
                    ],
                    'config' => [
                        'SomeOrg\Module1\Extension\ExtensionClass->has_one' => [
                            'name' => 'has_one',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/Extension/ExtensionClass.php',
                            'class' => 'SomeOrg\Module1\Extension\ExtensionClass',
                            'apiType' => 'config',
                            'message' => $removedNotDeprecated,
                        ],
                    ]
                ],
                'fix-deprecation' => [
                    'class' => [
                        'SomeOrg\Module1\Model\ModelOne' => [
                            'name' => 'SomeOrg\Module1\Model\ModelOne',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/Model/ModelOne.php',
                            'apiType' => 'class',
                            'message' => $fixVersionNumber,
                        ],
                        'SomeOrg\Module1\SomeClassTwo' => [
                            'name' => 'SomeOrg\Module1\SomeClassTwo',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeClassTwo.php',
                            'apiType' => 'class',
                            'message' => $fixVersionNumber,
                        ],
                    ],
                    'property' => [
                        'SomeOrg\Module1\SomeTrait->someProperty' => [
                            'name' => 'someProperty',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'property',
                            'message' => $missingMessage,
                        ],
                    ],
                    'method' => [
                        'SomeOrg\Module1\Model\ModelTwo::moveMethodToExtensionButNot()' => [
                            'name' => 'moveMethodToExtensionButNot',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/Model/ModelTwo.php',
                            'class' => 'SomeOrg\Module1\Model\ModelTwo',
                            'apiType' => 'method',
                            'message' => 'There are multiple deprecation notices for this API.',
                        ],
                    ],
                ],
            ],
            'some-org/module2' => [
                'deprecate' => [
                    'const' => [
                        'SomeOrg\Module2\Something\ClassOne::CONST_ONE' => [
                            'name' => 'CONST_ONE',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'constant',
                            'message' => $removedNotDeprecated,
                        ],
                    ],
                    'property' => [
                        'SomeOrg\Module2\Something\ClassOne->property3' => [
                            'name' => 'property3',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'property',
                            'message' => $removedNotDeprecated,
                        ],
                    ],
                    'config' => [
                        'SomeOrg\Module2\Something\DataObjectOne->config2' => [
                            'name' => 'config2',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module2/src/Something/DataObjectOne.php',
                            'class' => 'SomeOrg\Module2\Something\DataObjectOne',
                            'apiType' => 'config',
                            'message' => $removedNotDeprecated,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function getExpectedChanges(): array
    {
        // @TODO check what changes when we disable the fix T_T
        return [
            'some-org/module1' => [
                'returnByRef' => [
                    'function' => [
                        'someGlobalFunctionFive()' => [
                            'name' => 'someGlobalFunctionFive',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'apiType' => 'function',
                            'isNow' => false,
                        ],
                        'someGlobalFunctionSix()' => [
                            'name' => 'someGlobalFunctionSix',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'apiType' => 'function',
                            'isNow' => true,
                        ],
                    ],
                ],
                'passByRef' => [
                    'param' => [
                        'someGlobalFunctionFour($string)' => [
                            'name' => 'string',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'function' => 'someGlobalFunctionFour',
                            'method' => null,
                            'class' => null,
                            'apiType' => 'parameter',
                            'isNow' => true,
                        ],
                        'SomeOrg\Module1\SomeTrait::anotherMethod(($param2)' => [
                            'name' => 'param2',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeTrait.php',
                            'function' => null,
                            'method' => 'anotherMethod',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'parameter',
                            'isNow' => false,
                        ],
                    ],
                ],
                'returnType' => [
                    'function' => [
                        'someGlobalFunctionThree()' => [
                            'name' => 'someGlobalFunctionThree',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'apiType' => 'function',
                            'from' => 'bool|null',
                            'to' => 'null',
                            'fromOrig' => 'bool|null',
                            'toOrig' => 'null',
                        ],
                    ],
                    'method' => [
                        'SomeOrg\Module1\SomeInterface::thirdMethod()' => [
                            'name' => 'thirdMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeInterface.php',
                            'class' => 'SomeOrg\Module1\SomeInterface',
                            'apiType' => 'method',
                            'from' => '',
                            'to' => 'void',
                            'fromOrig' => '',
                            'toOrig' => 'void',
                        ],
                        'SomeOrg\Module1\SomeTrait::someMethod()' => [
                            'name' => 'someMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'method',
                            'from' => 'array',
                            'to' => 'array|null',
                            'fromOrig' => 'array',
                            'toOrig' => 'array|null',
                        ],
                        'SomeOrg\Module1\Model\ModelTwo::moveMethodToExtension()' => [
                            'name' => 'moveMethodToExtension',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/Model/ModelTwo.php',
                            'class' => 'SomeOrg\Module1\Model\ModelTwo',
                            'apiType' => 'method',
                            'from' => '',
                            'to' => 'void',
                            'fromOrig' => '',
                            'toOrig' => 'void',
                        ],
                    ],
                ],
                'static' => [
                    'method' => [
                        'SomeOrg\Module1\SomeInterface::thirdMethod()' => [
                            'name' => 'thirdMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeInterface.php',
                            'class' => 'SomeOrg\Module1\SomeInterface',
                            'apiType' => 'method',
                            'isNow' => false,
                        ],
                        'SomeOrg\Module1\SomeTrait::anotherMethod()' => [
                            'name' => 'anotherMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'method',
                            'isNow' => true,
                        ],
                    ],
                ],
                'type' => [
                    'param' => [
                        'someGlobalFunctionThree($stringRenamed)' => [
                            'name' => 'string',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'function' => 'someGlobalFunctionThree',
                            'method' => null,
                            'class' => null,
                            'apiType' => 'parameter',
                            'from' => 'string',
                            'to' => 'string|null',
                            'fromOrig' => 'string',
                            'toOrig' => 'string|null',
                        ],
                        'someGlobalFunctionTwo($someArg2)' => [
                            'name' => 'someArg2',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'function' => 'someGlobalFunctionTwo',
                            'method' => null,
                            'class' => null,
                            'apiType' => 'parameter',
                            'from' => 'bool',
                            'to' => '',
                            'fromOrig' => 'bool',
                            'toOrig' => '',
                        ],
                    ],
                    'db' => [
                        'SomeOrg\Module1\Extension\ExtensionClass.db-\'FromExtensionDB\'' => [
                            'name' => 'FromExtensionDB',
                            'class' => 'SomeOrg\Module1\Extension\ExtensionClass',
                            'apiType' => 'database field',
                            'from' => "'Boolean(true)'",
                            'to' => "'Boolean(false)'",
                        ],
                        'SomeOrg\Module1\Model\ModelTwo.db-\'FromExtensionDB\'' => [
                            'name' => 'FromExtensionDB',
                            'class' => 'SomeOrg\Module1\Model\ModelTwo',
                            'apiType' => 'database field',
                            'from' => "'Boolean(true)'",
                            'to' => "'Boolean(false)'",
                        ],
                        'SomeOrg\Module1\Model\ModelTwo.db-\'MoveToExtension\'' => [
                            'name' => 'MoveToExtension',
                            'class' => 'SomeOrg\Module1\Model\ModelTwo',
                            'apiType' => 'database field',
                            'from' => "'HTMLText'",
                            'to' => "'Varchar'",
                        ],
                    ],
                    'has_many' => [
                        'SomeOrg\Module1\Extension\ExtensionClassTwo.has_many-\'FromExtensionHasMany\'' => [
                            'name' => 'FromExtensionHasMany',
                            'class' => 'SomeOrg\Module1\Extension\ExtensionClassTwo',
                            'apiType' => '`has_many` relation',
                            'from' => 'SomeOrg\Module1\Model\ModelOne',
                            'to' => 'SomeOrg\Module1\Model\ModelTwo',
                        ],
                        'SomeOrg\Module1\Model\ModelTwo.has_many-\'FromExtensionHasMany\'' => [
                            'name' => 'FromExtensionHasMany',
                            'class' => 'SomeOrg\Module1\Model\ModelTwo',
                            'apiType' => '`has_many` relation',
                            'from' => 'SomeOrg\Module1\Model\ModelOne',
                            'to' => 'SomeOrg\Module1\Model\ModelTwo',
                        ],
                    ],
                ],
                'renamed' => [
                    'param' => [
                        'someGlobalFunctionThree($stringRenamed)' => [
                            'name' => 'string',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'function' => 'someGlobalFunctionThree',
                            'method' => null,
                            'class' => null,
                            'apiType' => 'parameter',
                            'from' => 'string',
                            'to' => 'stringRenamed',
                        ],
                        'SomeOrg\Module1\SomeInterface::anotherMethod(($someOtherParam)' => [
                            'name' => 'someParam',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeInterface.php',
                            'function' => null,
                            'method' => 'anotherMethod',
                            'class' => 'SomeOrg\Module1\SomeInterface',
                            'apiType' => 'parameter',
                            'from' => 'someParam',
                            'to' => 'someOtherParam',
                        ],
                        'SomeOrg\Module1\SomeTrait::someMethod(($return)' => [
                            'name' => 'returnMe',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeTrait.php',
                            'function' => null,
                            'method' => 'someMethod',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'parameter',
                            'from' => 'returnMe',
                            'to' => 'return',
                        ],
                    ],
                ],
                'default' => [
                    'param' => [
                        'someGlobalFunctionThree($stringRenamed)' => [
                            'name' => 'string',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'function' => 'someGlobalFunctionThree',
                            'method' => null,
                            'class' => null,
                            'apiType' => 'parameter',
                            'from' => 'null',
                            'to' => null,
                        ],
                        'someGlobalFunctionTwo($someArg2)' => [
                            'name' => 'someArg2',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'function' => 'someGlobalFunctionTwo',
                            'method' => null,
                            'class' => null,
                            'apiType' => 'parameter',
                            'from' => 'false',
                            'to' => null,
                        ],
                        'SomeOrg\Module1\SomeTrait::someMethod(($return)' => [
                            'name' => 'returnMe',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeTrait.php',
                            'function' => null,
                            'method' => 'someMethod',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'parameter',
                            'from' => '[]',
                            'to' => 'null',
                        ],
                    ],
                ],
                'new' => [
                    'param' => [
                        'someGlobalFunctionThree($newArg)' => [
                            'name' => 'newArg',
                            'hint' => 'bool',
                            'hintOrig' => 'bool',
                            'function' => 'someGlobalFunctionThree',
                            'method' => null,
                            'class' => null,
                            'apiType' => 'parameter',
                        ],
                        'SomeOrg\Module1\SomeInterface::someMethod(($anotherParam)' => [
                            'name' => 'anotherParam',
                            'hint' => '',
                            'hintOrig' => '',
                            'function' => null,
                            'method' => 'someMethod',
                            'class' => 'SomeOrg\Module1\SomeInterface',
                            'apiType' => 'parameter',
                        ],
                        'SomeOrg\Module1\SomeTrait::someMethod(($param2)' => [
                            'name' => 'param2',
                            'hint' => '',
                            'hintOrig' => '',
                            'function' => null,
                            'method' => 'someMethod',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'parameter',
                        ],
                    ],
                ],
                'variadic' => [
                    'param' => [
                        'someGlobalFunctionTwo($someArg2)' => [
                            'name' => 'someArg2',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/_config.php',
                            'function' => 'someGlobalFunctionTwo',
                            'method' => null,
                            'class' => null,
                            'apiType' => 'parameter',
                            'isNow' => true,
                        ],
                    ],
                ],
                'default-array' => [
                    'config' => [
                        'SomeOrg\Module1\Extension\ExtensionClass->array_config' => [
                            'name' => 'array_config',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/Extension/ExtensionClass.php',
                            'class' => 'SomeOrg\Module1\Extension\ExtensionClass',
                            'apiType' => 'config',
                        ],
                    ],
                ],
                'removed' => [
                    'method' => [
                        'SomeOrg\Module1\Model\ModelTwo::moveMethodToExtensionButNot()' => [
                            'name' => 'moveMethodToExtensionButNot',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/Model/ModelTwo.php',
                            'class' => 'SomeOrg\Module1\Model\ModelTwo',
                            'apiType' => 'method',
                            'message' => '',
                        ],
                        'SomeOrg\Module1\SomeTrait::thirdMethod()' => [
                            'name' => 'thirdMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'method',
                            'message' => '',
                        ],
                    ],
                    'class' => [
                        'SomeOrg\Module1\Model\ModelOne' => [
                            'name' => 'SomeOrg\Module1\Model\ModelOne',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/Model/ModelOne.php',
                            'apiType' => 'class',
                            'message' => 'This deprecation notice has no version',
                        ],
                        'SomeOrg\Module1\SomeClassTwo' => [
                            'name' => 'SomeOrg\Module1\SomeClassTwo',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeClassTwo.php',
                            'apiType' => 'class',
                            'message' => '1.2 Version number format is wrong!!',
                        ],
                        'SomeOrg\Module1\SomeInterfaceTwo' => [
                            'name' => 'SomeOrg\Module1\SomeInterfaceTwo',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeInterfaceTwo.php',
                            'apiType' => 'interface',
                            'message' => 'This interface has been deprecated, hurray!',
                        ],
                        'SomeOrg\Module1\SomeTraitTwo' => [
                            'name' => 'SomeOrg\Module1\SomeTraitTwo',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeTraitTwo.php',
                            'apiType' => 'trait',
                            'message' => '',
                        ],
                    ],
                    'has_one' => [
                        'SomeOrg\Module1\Extension\ExtensionClass.has_one-\'FromExtensionHasOne\'' => [
                            'name' => 'FromExtensionHasOne',
                            'apiType' => '`has_one` relation',
                            'class' => 'SomeOrg\Module1\Extension\ExtensionClass',
                        ],
                        'SomeOrg\Module1\Extension\ExtensionClassTwo.has_one-\'FromExtensionHasOne\'' => [
                            'name' => 'FromExtensionHasOne',
                            'apiType' => '`has_one` relation',
                            'class' => 'SomeOrg\Module1\Extension\ExtensionClassTwo',
                        ],
                        'SomeOrg\Module1\Model\ModelTwo.has_one-\'FromExtensionHasOne\'' => [
                            'name' => 'FromExtensionHasOne',
                            'apiType' => '`has_one` relation',
                            'class' => 'SomeOrg\Module1\Model\ModelTwo',
                        ],
                    ],
                    'property' => [
                        'SomeOrg\Module1\SomeInterface->someProperty' => [
                            'name' => 'someProperty',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeInterface.php',
                            'class' => 'SomeOrg\Module1\SomeInterface',
                            'apiType' => 'property',
                            'message' => '',
                        ],
                        'SomeOrg\Module1\SomeTrait->someProperty' => [
                            'name' => 'someProperty',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'property',
                            'message' => '',
                        ],
                    ],
                    'param' => [
                        'SomeOrg\Module1\SomeInterface::anotherMethod(($param2)' => [
                            'name' => 'param2',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeInterface.php',
                            'function' => null,
                            'method' => 'anotherMethod',
                            'class' => 'SomeOrg\Module1\SomeInterface',
                            'apiType' => 'parameter',
                            'message' => '',
                        ],
                    ],
                    'config' => [
                        'SomeOrg\Module1\Extension\ExtensionClass->has_one' => [
                            'name' => 'has_one',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/Extension/ExtensionClass.php',
                            'class' => 'SomeOrg\Module1\Extension\ExtensionClass',
                            'apiType' => 'config',
                            'message' => '',
                        ],
                        'SomeOrg\Module1\Extension\ExtensionClassTwo->has_one' => [
                            'name' => 'has_one',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/Extension/ExtensionClassTwo.php',
                            'class' => 'SomeOrg\Module1\Extension\ExtensionClassTwo',
                            'apiType' => 'config',
                            'message' => 'Will be replaced with SomeOrg\Module1\Extension\ExtensionClass.has_one',
                        ],
                    ],
                ],
                'internal' => [
                    'class' => [
                        'SomeOrg\Module1\SomeClass' => [
                            'name' => 'SomeOrg\Module1\SomeClass',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeClass.php',
                            'apiType' => 'class',
                            'message' => '',
                        ],
                    ],
                    'property' => [
                        'SomeOrg\Module1\SomeTrait->wasProtected' => [
                            'name' => 'wasProtected',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module1/code/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'property',
                            'message' => '',
                        ],
                    ],
                ],
                'visibility' => [
                    'method' => [
                        'SomeOrg\Module1\SomeTrait::anotherMethod()' => [
                            'name' => 'anotherMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'method',
                            'from' => 'public',
                            'to' => 'protected',
                        ],
                    ],
                ],
                'abstract' => [
                    'method' => [
                        'SomeOrg\Module1\SomeTrait::anotherMethod()' => [
                            'name' => 'anotherMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module1/src/SomeTrait.php',
                            'class' => 'SomeOrg\Module1\SomeTrait',
                            'apiType' => 'method',
                        ],
                    ],
                ],
            ],
            'some-org/module2' => [
                'returnByRef' => [
                    'method' => [
                        'SomeOrg\Module2\Something\ClassOne::methodThree()' => [
                            'name' => 'methodThree',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'method',
                            'isNow' => false,
                        ],
                        'SomeOrg\Module2\Something\ClassOne::methodFour()' => [
                            'name' => 'methodFour',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'method',
                            'isNow' => true,
                        ],
                    ],
                ],
                'type' => [
                    'property' => [
                        'SomeOrg\Module2\Something\ClassOne->property1' => [
                            'name' => 'property1',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'property',
                            'from' => '',
                            'to' => 'string|null',
                            'fromOrig' => '',
                            'toOrig' => 'string|null',
                        ],
                        'SomeOrg\Module2\Something\ClassOne->property2' => [
                            'name' => 'property2',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'property',
                            'from' => 'string|SilverStripe\Security\Member',
                            'to' => 'int|SilverStripe\Security\Member|null',
                            'fromOrig' => 'string|Member',
                            'toOrig' => 'int|Member|null',
                        ],
                    ],
                    'param' => [
                        'SomeOrg\Module2\Something\ClassOne::methodTwo(($variadicParam)' => [
                            'name' => 'variadicParam',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'function' => null,
                            'method' => 'methodTwo',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'parameter',
                            'from' => '',
                            'to' => 'array',
                            'fromOrig' => '',
                            'toOrig' => 'array',
                        ],
                        'SomeOrg\Module2\Something\ClassTwo::someMethod(($returnMe)' => [
                            'name' => 'returnMe',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassTwo.php',
                            'function' => null,
                            'method' => 'someMethod',
                            'class' => 'SomeOrg\Module2\Something\ClassTwo',
                            'apiType' => 'parameter',
                            'from' => 'array',
                            'to' => 'array|int',
                            'fromOrig' => 'array',
                            'toOrig' => 'array|int',
                        ],
                    ]
                ],
                'removed' => [
                    'property' => [
                        'SomeOrg\Module2\Something\ClassOne->property3' => [
                            'name' => 'property3',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'property',
                            'message' => '',
                        ],
                    ],
                    'config' => [
                        'SomeOrg\Module2\Something\DataObjectOne->config2' => [
                            'name' => 'config2',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module2/src/Something/DataObjectOne.php',
                            'class' => 'SomeOrg\Module2\Something\DataObjectOne',
                            'apiType' => 'config',
                            'message' => '',
                        ],
                        'SomeOrg\Module2\Something\DataObjectOne->config3' => [
                            'name' => 'config3',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module2/src/Something/DataObjectOne.php',
                            'class' => 'SomeOrg\Module2\Something\DataObjectOne',
                            'apiType' => 'config',
                            'message' => 'Will be removed without a replacement',
                        ],
                    ],
                    'const' => [
                        'SomeOrg\Module2\Something\ClassOne::CONST_ONE' => [
                            'name' => 'CONST_ONE',
                            'file' => __DIR__ . '/fixture-code/cloned/from/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'constant',
                            'message' => '',
                        ],
                    ],
                ],
                'visibility' => [
                    'property' => [
                        'SomeOrg\Module2\Something\ClassOne->property1' => [
                            'name' => 'property1',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'property',
                            'from' => 'protected',
                            'to' => 'public',
                        ],
                    ],
                    'method' => [
                        'SomeOrg\Module2\Something\ClassOne::methodOne()' => [
                            'name' => 'methodOne',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'method',
                            'from' => 'public',
                            'to' => 'protected',
                        ],
                        'SomeOrg\Module2\Something\ClassOne::methodTwo()' => [
                            'name' => 'methodTwo',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'method',
                            'from' => 'protected',
                            'to' => 'public',
                        ],
                        'SomeOrg\Module2\Something\ClassTwo::someMethod()' => [
                            'name' => 'someMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassTwo.php',
                            'class' => 'SomeOrg\Module2\Something\ClassTwo',
                            'apiType' => 'method',
                            'from' => 'public',
                            'to' => 'protected',
                        ],
                    ],
                ],
                'returnType' => [
                    'method' => [
                        'SomeOrg\Module2\Something\ClassOne::methodTwo()' => [
                            'name' => 'methodTwo',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'method',
                            'from' => 'void',
                            'to' => 'null',
                            'fromOrig' => 'void',
                            'toOrig' => 'null',
                        ],
                        'SomeOrg\Module2\Something\ClassTwo::someMethod()' => [
                            'name' => 'someMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassTwo.php',
                            'class' => 'SomeOrg\Module2\Something\ClassTwo',
                            'apiType' => 'method',
                            'from' => 'array',
                            'to' => 'array|null',
                            'fromOrig' => 'array',
                            'toOrig' => 'array|null',
                        ],
                    ],
                ],
                'static' => [
                    'method' => [
                        'SomeOrg\Module2\Something\ClassOne::methodOne()' => [
                            'name' => 'methodOne',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'method',
                            'isNow' => true,
                        ],
                        'SomeOrg\Module2\Something\ClassTwo::someMethod()' => [
                            'name' => 'someMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassTwo.php',
                            'class' => 'SomeOrg\Module2\Something\ClassTwo',
                            'apiType' => 'method',
                            'isNow' => true,
                        ],
                    ],
                ],
                'variadic' => [
                    'param' => [
                        'SomeOrg\Module2\Something\ClassOne::methodTwo(($variadicParam)' => [
                            'name' => 'variadicParam',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'function' => null,
                            'method' => 'methodTwo',
                            'class' => 'SomeOrg\Module2\Something\ClassOne',
                            'apiType' => 'parameter',
                            'isNow' => false,
                        ],
                    ],
                ],
                'default' => [
                    'config' => [
                        'SomeOrg\Module2\Something\DataObjectOne->config1' => [
                            'name' => 'config1',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/DataObjectOne.php',
                            'class' => 'SomeOrg\Module2\Something\DataObjectOne',
                            'apiType' => 'config',
                            'from' => "'one'",
                            'to' => '1',
                        ],
                        'SomeOrg\Module2\Something\DataObjectOne->config4' => [
                            'name' => 'config4',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/DataObjectOne.php',
                            'class' => 'SomeOrg\Module2\Something\DataObjectOne',
                            'apiType' => 'config',
                            'from' => 'null',
                            'to' => '4',
                        ],
                    ],
                    'param' => [
                        'SomeOrg\Module2\Something\ClassTwo::someMethod(($returnMe)' => [
                            'name' => 'returnMe',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassTwo.php',
                            'function' => null,
                            'method' => 'someMethod',
                            'class' => 'SomeOrg\Module2\Something\ClassTwo',
                            'apiType' => 'parameter',
                            'from' => '[]',
                            'to' => null,
                        ],
                    ],
                ],
                'abstract' => [
                    'class' => [
                        'SomeOrg\Module2\Something\ClassTwo' => [
                            'name' => 'SomeOrg\Module2\Something\ClassTwo',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassTwo.php',
                            'apiType' => 'class',
                        ],
                    ],
                    'method' => [
                        'SomeOrg\Module2\Something\ClassTwo::someMethod()' => [
                            'name' => 'someMethod',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassTwo.php',
                            'class' => 'SomeOrg\Module2\Something\ClassTwo',
                            'apiType' => 'method',
                        ],
                    ],
                ],
                'final' => [
                    'class' => [
                        'SomeOrg\Module2\Something\ClassOne' => [
                            'name' => 'SomeOrg\Module2\Something\ClassOne',
                            'file' => __DIR__ . '/fixture-code/cloned/to/vendor/some-org/module2/src/Something/ClassOne.php',
                            'apiType' => 'class',
                        ],
                    ],
                ],
            ],
        ];
    }
}
