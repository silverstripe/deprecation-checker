<?php

namespace SilverStripe\DeprecationChecker\Compare;

use Doctum\Project;
use Doctum\Reflection\ClassReflection;
use Doctum\Reflection\ConstantReflection;
use Doctum\Reflection\FunctionReflection;
use Doctum\Reflection\HintReflection;
use Doctum\Reflection\MethodReflection;
use Doctum\Reflection\ParameterReflection;
use Doctum\Reflection\PropertyReflection;
use Doctum\Reflection\Reflection;
use Doctum\Version\Version;
use InvalidArgumentException;
use LogicException;
use PhpParser\JsonDecoder;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\MagicConst\Class_;
use PhpParser\Node\Scalar\String_;
use SilverStripe\DeprecationChecker\Command\CloneCommand;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compares code between versions to find breaking API changes.
 */
class BreakingChangesComparer
{
    /**
     * A string that represents the older of the two versions
     */
    public const string FROM = 'from';

    /**
     * A string that represents the newer of the two versions
     */
    public const string TO = 'to';

    /**
     * API which has been removed, but still needs to be deprecated in the old version
     */
    public const string ACTION_DEPRECATE = 'deprecate';

    /**
     * API which was deprecated in the old version but hasn't yet been removed
     */
    public const string ACTION_REMOVE = 'remove';

    /**
     * API which was deprecated in the old version but the deprecation message is malformed in some way
     */
    public const string ACTION_FIX_DEPRECATION = 'fix-deprecation';

    /**
     * Configuration properties that represent database field or relational config.
     */
    public const array DB_AND_RELATION = [
        'db',
        'fixed_fields',
        'has_one',
        'belongs_to',
        'has_many',
        'many_many',
        'belongs_many_many',
    ];

    private OutputInterface $output;

    private array $breakingChanges = [];

    private array $actionsToTake = [];

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * Get the list of all API breaking changes that were found.
     */
    public function getBreakingChanges(): array
    {
        return $this->breakingChanges;
    }

    /**
     * Get a list of any actions developers need to take to improve the fidelity of the comparison.
     */
    public function getActionsToTake(): array
    {
        return $this->actionsToTake;
    }

    /**
     * Compare the API in two versions of the same project and find API breaking changes.
     */
    public function compare(Project $project): void
    {
        $fromProject = $project;
        $fromProject->switchVersion(new Version(BreakingChangesComparer::FROM));
        $toProject = clone $project;
        $toProject->switchVersion(new Version(BreakingChangesComparer::TO));

        // Compare global functions that are provided in Silverstripe CMS code
        $functionsTo = $this->getFunctionsByName($toProject);
        foreach ($this->getFunctionsByName($fromProject) as $functionName => $functionInfo) {
            // Note the index of this array isn't the function name, unlike with classes and interfaces.
            $this->checkGlobalFunction($functionInfo->getName(), $functionInfo, $functionsTo[$functionName] ?? null);
        }
        // Free up some memory
        unset($functionsTo);

        // Compare classes and traits that are provided in Silverstripe CMS code
        $classesTo = $toProject->getProjectClasses();
        /** @var ClassReflection $classInfo */
        foreach ($fromProject->getProjectClasses() as $className => $classInfo) {
            $this->checkClass($className, $classInfo, $classesTo[$className] ?? null);
        }
        // Free up some memory
        unset($classesTo);

        // Compare interfaces that are provided in Silverstripe CMS code
        $interfacesTo = $toProject->getProjectInterfaces();
        /** @var ClassReflection $interfaceInfo */
        foreach ($fromProject->getProjectInterfaces() as $interfaceName => $interfaceInfo) {
            $this->checkClass($interfaceName, $interfaceInfo, $interfacesTo[$interfaceName] ?? null);
        }
        // Free up some memory
        unset($interfacesTo);
    }

    /**
     * Get an associative array of functions, since Project::getProjectFunctions() is uniquely 0-indexed.
     *
     * @return array<string, FunctionReflection>
     */
    private function getFunctionsByName(Project $project): array
    {
        $functions = [];
        foreach ($project->getProjectFunctions() as $function) {
            $functions[$function->getName()] = $function;
        }
        return $functions;
    }

    /**
     * Check for API breaking changes in a globally-scoped function.
     * Checks include:
     * - API existed but was removed
     * - API wasn't internal but now is
     * - API changed return type
     *
     * Additional checks are done against parameters.
     *
     * Also includes checking for actions that need to happen e.g. if the function wasn't deprecated.
     */
    private function checkGlobalFunction(
        string $name,
        FunctionReflection $functionFrom,
        ?FunctionReflection $functionTo
    ): void {
        $this->output->writeln("Checking globally-scoped function $name", OutputInterface::VERBOSITY_VERY_VERBOSE);
        $fileFrom = $functionFrom->getFile();
        $fileTo = $functionTo?->getFile();
        $module = $this->getModuleForFile($fileFrom);
        $dataFrom = [
            'name' => $name,
            'file' => $fileFrom,
            'apiType' => 'function',
        ];
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            'apiType' => 'function',
        ];

        $isMissing = $this->checkForMissingApi($name, $functionFrom, $functionTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($functionFrom, $functionTo, $dataTo, $module);

        // Changed whether it's passed by reference (preceeded with `&`) or not
        if ($functionFrom->isByRef() !== $functionTo->isByRef()) {
            $type = $this->getTypeFromReflection($functionFrom);
            $ref = $this->getRefFromReflection($functionTo);
            $this->breakingChanges[$module]['returnByRef'][$type][$ref] = [
                ...$dataTo,
                'isNow' => (bool) $functionTo->isByRef(),
            ];
        }

        $this->checkParameters($functionFrom->getParameters(), $functionTo->getParameters(), $module);
    }

    /**
     * Check for API breaking changes in a class, interface, or trait.
     * NOTE: The underlying library we're using doesn't support enum yet.
     * Checks include:
     * - API existed but was removed
     * - API wasn't internal but now is
     * - API changed type (e.g. was a class but became an interface)
     * - API became abstract
     * - API became final
     *
     * Additional checks are done against constants, properties, and methods.
     *
     * Also includes checking for actions that need to happen e.g. if the class wasn't deprecated.
     */
    private function checkClass(string $fqcn, ClassReflection $classFrom, ?ClassReflection $classTo): void
    {
        $apiTypeFrom = match ($classFrom->getCategoryId()) {
            1 => 'class',
            2 => 'interface',
            3 => 'trait',
        };
        $apiTypeTo = match ($classTo?->getCategoryId()) {
            1 => 'class',
            2 => 'interface',
            3 => 'trait',
            default => null,
        };
        $this->output->writeln("Checking $apiTypeFrom $fqcn", OutputInterface::VERBOSITY_VERY_VERBOSE);

        $fileFrom = $classFrom->getFile();
        $fileTo = $classTo?->getFile();
        $module = $this->getModuleForFile($fileFrom);
        $type = $this->getTypeFromReflection($classFrom);
        $dataFrom = [
            'name' => $fqcn,
            'file' => $fileFrom,
            'apiType' => $apiTypeFrom,
        ];
        $dataTo = [
            'name' => $fqcn,
            'file' => $fileTo,
            'apiType' => $apiTypeTo,
        ];

        $this->addApiFromExtensions($classFrom, $dataFrom, $module);
        $this->addApiFromExtensions($classTo, $dataTo, $module);

        $isMissing = $this->checkForMissingApi($fqcn, $classFrom, $classTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($classFrom, $classTo, $dataTo, $module);

        // Class-like has changed what type of class-like it is (e.g. from class to interface)
        if ($classFrom->getCategoryId() !== $classTo->getCategoryId()) {
            $this->breakingChanges[$module]['type'][$type][$fqcn] = [
                BreakingChangesComparer::FROM => $apiTypeFrom,
                BreakingChangesComparer::TO => $apiTypeTo,
            ];
        }

        if ($classFrom->isReadOnly() !== $classTo->isReadOnly()) {
            $this->breakingChanges[$module]['readonly'][$type][$fqcn] = [
                ...$dataTo,
                'isNow' => $classTo->isReadOnly(),
            ];
        }

        $this->checkConstants($fqcn, $classFrom->getConstants(true), $classTo->getConstants(true), $module);
        $this->checkProperties($fqcn, $classFrom->getProperties(true), $classTo->getProperties(true), $module);
        $this->checkMethods($fqcn, $classFrom->getMethods(true), $classTo->getMethods(true), $classTo, $module);

        // Extensions and DataObjects can define database fields and relations
        if ($this->instanceOf($classFrom, 'SilverStripe\ORM\DataObject') || $this->instanceOf($classFrom, 'SilverStripe\Core\Extension')) {
            $this->checkDbFieldsAndRelations($fqcn, $classFrom, $classTo, $module);
        }
    }

    /**
     * Add public methods and configuration from extensions.
     * API in extensions that are applied to an extension directly in the class are considered part of that class's API.
     */
    private function addApiFromExtensions(?ClassReflection $class, array $data, string $module): void
    {
        $classProperties = $class?->getProperties();
        foreach ($this->getExtensions($class, $data, $module) as $extension) {
            // Add public methods and config properties if the class doesn't have them already.
            /** @var MethodReflection $method */
            foreach ($extension->getMethods(true) as $method) {
                if ($method->isPublic() && !$class->getMethod($method->getName())) {
                    $extensionMethod = ExtensionMethodReflection::fromArray($class->getProject(), $method->toArray());
                    $extensionMethod->setExtensionClass($extension);
                    $class->addMethod($extensionMethod);
                }
            }
            /** @var PropertyReflection $method */
            foreach ($extension->getProperties(true) as $property) {
                if ($this->propertyIsConfig($property) && !array_key_exists($property->getName(), $classProperties)) {
                    $extensionConfig = ExtensionConfigReflection::fromArray($class->getProject(), $property->toArray());
                    $extensionConfig->setExtensionClass($extension);
                    $class->addProperty($extensionConfig);
                }
            }
        }
    }

    /**
     * Get class reflection for all extensions that are explicitly applied to a class.
     * @return array<string, ClassReflection>
     */
    private function getExtensions(?ClassReflection $class, array $data, string $module): array
    {
        $extensions = [];
        /** @var PropertyReflection $extensionsProperty */
        $extensionsProperty = $class?->getProperties()['extensions'] ?? null;
        if (!$extensionsProperty) {
            return [];
        }
        $type = $this->getTypeFromReflection($class);
        $ref = $this->getRefFromReflection($class);
        $extensionsRawValue = $extensionsProperty->getDefault();
        if (empty($extensionsRawValue['items'])) {
            return [];
        }
        foreach ($extensionsRawValue['items'] ?? [] as $extensionRaw) {
            $value = $extensionRaw['value'];
            // This is the type for PhpParser\Node\Expr\ClassConstFetch i.e. SomeClass::class
            if ($value['nodeType'] !== 'Expr_ClassConstFetch') {
                if (!empty($data)) {
                    $this->actionsToTake[$module][BreakingChangesComparer::ACTION_DEPRECATE][$type][$ref] = [
                        ...$data,
                        'message' => 'The value for the $extensions configuration property has an unexpected format or type.',
                    ];
                }
                continue;
            }
            // Get reflection object for the extension class
            $extensionFQCN = implode('\\', $value['class']['parts']);
            $extension = $class->getProject()->getClass($extensionFQCN);
            if (!$this->apiExists($extension)) {
                if (!empty($data)) {
                    $this->actionsToTake[$module][BreakingChangesComparer::ACTION_DEPRECATE][$type][$ref] = [
                        ...$data,
                        'message' => "The '$extensionFQCN' class referenced in the \$extensions configuration property doesn't exist.",
                    ];
                }
                continue;
            }
            $extensions[$extensionFQCN] = $extension;
        }
        return $extensions;
    }

    /**
     * Check for API breaking changes in all constants for a class.
     *
     * @param array<string,ConstantReflection> $constsFrom
     * @param array<string,ConstantReflection> $constsTo
     */
    private function checkConstants(string $className, array $constsFrom, array $constsTo, string $module): void
    {
        // Compare consts that have the same name in both versions or removed in the new one
        foreach ($constsFrom as $constName => $const) {
            $constClass = $const->getClass();
            // Skip comparison where the API used to be in a parent class or explicitly in a trait and wasn't overridden
            if ($constClass?->getName() !== $className) {
                continue;
            }
            // Skip comparison where the API used to exist on the parent class
            // i.e. even if it is explicitly overridden in the subclass, only the superclass needs to report the changes.
            if ($constClass?->getParent()?->getConstants(true)[$constName] ?? false) {
                continue;
            }
            $this->checkConstant($constName, $const, $constsTo[$constName] ?? null, $module);
        }
    }

    /**
     * Check for API breaking changes in a constant.
     * Checks include:
     * - API existed but was removed
     * - API wasn't internal but now is
     * - API changed type
     * - API changed visibility
     * - API became final
     *
     * Also includes checking for actions that need to happen e.g. if the const wasn't deprecated.
     */
    private function checkConstant(
        string $name,
        ConstantReflection $constFrom,
        ?ConstantReflection $constTo,
        string $module
    ): void {
        $this->output->writeln("Checking constant $name", OutputInterface::VERBOSITY_VERY_VERBOSE);
        /** @var ClassReflection|null $classFrom */
        $classFrom = $constFrom->getClass();
        $fileFrom = $classFrom?->getFile() ?? null;
        $dataFrom = [
            'name' => $name,
            'file' => $fileFrom,
            'class' => $classFrom?->getName(),
            'apiType' => 'constant',
        ];
        /** @var ClassReflection|null $classTo */
        $classTo = $constTo?->getClass();
        $fileTo = $classTo?->getFile() ?? null;
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            // Note we intentionally use $classFrom here because that's the reference we want in the changelog.
            // It's possible for example that the code was refactored into a trait in the new version which would
            // result in $classTo being the trait, NOT the class we're actually looking at.
            'class' => $classFrom?->getName(),
            'apiType' => 'constant',
        ];

        $isMissing = $this->checkForMissingApi($name, $constFrom, $constTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($constFrom, $constTo, $dataTo, $module);
    }

    /**
     * Check for API breaking changes in all properties for a class.
     *
     * @param array<string,PropertyReflection> $propertiesFrom
     * @param array<string,PropertyReflection> $propertiesTo
     */
    private function checkProperties(string $className, array $propertiesFrom, array $propertiesTo, string $module): void
    {
        // Compare properties that have the same name in both versions or removed in the new one
        foreach ($propertiesFrom as $propertyName => $property) {
            $propertyClass = $property->getClass();
            // Skip comparison where the API used to be in a parent class or explicitly in a trait and wasn't overridden
            if ($propertyClass?->getName() !== $className) {
                continue;
            }
            // Skip comparison where the API used to exist on the parent class
            // i.e. even if it is explicitly overridden in the subclass, only the superclass needs to report the changes.
            if ($propertyClass?->getParent()?->getProperties(true)[$propertyName] ?? false) {
                continue;
            }
            $this->checkProperty($propertyName, $property, $propertiesTo[$propertyName] ?? null, $module);
        }
    }

    /**
     * Check for API breaking changes in a property.
     * Checks include:
     * - API existed but was removed
     * - API wasn't internal but now is
     * - API changed type
     * - API changed visibility
     * - API became final
     *
     * Also includes checking for actions that need to happen e.g. if the const wasn't deprecated.
     */
    private function checkProperty(
        string $name,
        PropertyReflection $propertyFrom,
        ?PropertyReflection $propertyTo,
        string $module
    ): void {
        $this->output->writeln("Checking property $name", OutputInterface::VERBOSITY_VERY_VERBOSE);
        $type = $this->getTypeFromReflection($propertyFrom);
        $ref = $this->getRefFromReflection($propertyFrom);
        /** @var ClassReflection|null $classFrom */
        $classFrom = $propertyFrom->getClass();
        $fileFrom = $classFrom?->getFile() ?? null;
        $dataFrom = [
            'name' => $name,
            'file' => $fileFrom,
            'class' => $classFrom?->getName(),
            'apiType' => $this->propertyIsConfig($propertyFrom) ? 'config' : 'property',
        ];
        /** @var ClassReflection|null $classTo */
        $classTo = $propertyTo?->getClass();
        $fileTo = $classTo?->getFile() ?? null;
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            // Note we intentionally use $classFrom here because that's the reference we want in the changelog.
            // It's possible for example that the code was refactored into a trait in the new version which would
            // result in $classTo being the trait, NOT the class we're actually looking at.
            'class' => $classFrom?->getName(),
            'apiType' => $this->propertyIsConfig($propertyTo) ? 'config' : 'property',
        ];

        $isMissing = $this->checkForMissingApi($name, $propertyFrom, $propertyTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($propertyFrom, $propertyTo, $dataTo, $module);

        // Check if readonly changes
        if ($propertyFrom->isReadOnly() !== $propertyTo->isReadOnly()) {
            $this->breakingChanges[$module]['readonly'][$type][$ref] = [
                ...$dataTo,
                'isNow' => $propertyTo->isReadOnly(),
            ];
        }

        // Skip checking value changes for database fields and relations.
        // Those are specifically checked in more detail in a separate step.
        if (in_array($name, BreakingChangesComparer::DB_AND_RELATION) &&
            ($this->instanceOf($classFrom, 'SilverStripe\ORM\DataObject') || $this->instanceOf($classFrom, 'SilverStripe\Core\Extension'))
        ) {
            return;
        }

        // Check for changes to the default value of configuration property
        if ($this->propertyIsConfig($propertyFrom)) {
            // Compare the values as strings or arrays as that's much easier
            $propertyValueFrom = $this->getDefaultValue($propertyFrom->getDefault(), $classFrom);
            $propertyValueTo = $this->getDefaultValue($propertyTo->getDefault(), $classTo);
            if ($this->defaultValuesDiffer($propertyValueFrom, $propertyValueTo)) {
                if (is_array($propertyValueFrom) && is_array($propertyValueTo)) {
                    $this->breakingChanges[$module]['default-array'][$type][$ref] = $dataTo;
                } else {
                    if (is_array($propertyValueFrom)) {
                        if (empty($propertyValueFrom)) {
                            $propertyValueFrom = '[]';
                        } else {
                            $propertyValueFrom = 'array';
                        }
                    }
                    if (is_array($propertyValueTo)) {
                        if (empty($propertyValueTo)) {
                            $propertyValueTo = '[]';
                        } else {
                            $propertyValueTo = 'array';
                        }
                    }
                    $this->breakingChanges[$module]['default'][$type][$ref] = [
                        ...$dataTo,
                        BreakingChangesComparer::FROM => $propertyValueFrom,
                        BreakingChangesComparer::TO => $propertyValueTo,
                    ];
                }
            }
        }
    }

    /**
     * Check for API breaking changes in all methods for a class.
     *
     * @param array<string,MethodReflection> $methodsFrom
     * @param array<string,MethodReflection> $methodsTo
     */
    private function checkMethods(string $className, array $methodsFrom, array $methodsTo, ClassReflection $classTo, string $module): void
    {
        // Compare methods that have the same name in both versions or removed in the new one
        foreach ($methodsFrom as $methodName => $method) {
            $methodClass = $method->getClass();
            // Skip comparison where the API used to be in a parent class or explicitly in a trait and wasn't overridden
            if ($methodClass?->getName() !== $className) {
                continue;
            }
            // Skip comparison where the API used to exist on the parent class
            // i.e. even if it is explicitly overridden in the subclass, only the superclass needs to report the changes.
            if ($methodClass?->getParentMethod($methodName)) {
                continue;
            }
            $this->checkMethod($methodName, $method, $methodsTo[$methodName] ?? null, $module);
        }
    }

    /**
     * Check for API breaking changes in a method.
     * Checks include:
     * - API existed but was removed
     * - API wasn't internal but now is
     * - API changed return type
     * - API changed visibility
     * - API changed whether it returns by reference
     * - API became abstract
     * - API became final
     *
     * Additional checks are done against parameters.
     *
     * Also includes checking for actions that need to happen e.g. if the method wasn't deprecated.
     */
    private function checkMethod(
        string $name,
        MethodReflection $methodFrom,
        ?MethodReflection $methodTo,
        string $module
    ): void {
        $this->output->writeln("Checking method $name", OutputInterface::VERBOSITY_VERY_VERBOSE);
        $classFrom = $methodFrom->getClass();
        $fileFrom = $classFrom?->getFile() ?? null;
        $dataFrom = [
            'name' => $name,
            'file' => $fileFrom,
            'class' => $classFrom?->getName(),
            'apiType' => 'method',
        ];
        $classTo = $methodTo?->getClass();
        $fileTo = $classTo?->getFile() ?? null;
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            // Note we intentionally use $classFrom here because that's the reference we want in the changelog.
            // It's possible for example that the code was refactored into a trait in the new version which would
            // result in $classTo being the trait, NOT the class we're actually looking at.
            'class' => $classFrom?->getName(),
            'apiType' => 'method',
        ];

        $isMissing = $this->checkForMissingApi($name, $methodFrom, $methodTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($methodFrom, $methodTo, $dataTo, $module);

        // Check if the method changed whether it's static
        if ($methodFrom->isStatic() !== $methodTo->isStatic()) {
            $type = $this->getTypeFromReflection($methodTo);
            $ref = $this->getRefFromReflection($methodTo);
            $this->breakingChanges[$module]['static'][$type][$ref] = [
                ...$dataTo,
                'isNow' => $methodTo->isStatic(),
            ];
        }

        // Changed whether it's passed by reference (preceeded with `&`) or not
        if ($methodFrom->isByRef() !== $methodTo->isByRef()) {
            $type = $this->getTypeFromReflection($methodTo);
            $ref = $this->getRefFromReflection($methodTo);
            $this->breakingChanges[$module]['returnByRef'][$type][$ref] = [
                ...$dataTo,
                'isNow' => (bool) $methodTo->isByRef(),
            ];
        }

        $this->checkParameters($methodFrom->getParameters(), $methodTo->getParameters(), $module);
    }

    /**
     * Check for API breaking changes in db field and relational configuration defined on the class or explicit extensions
     */
    private function checkDbFieldsAndRelations(string $fqcn, ClassReflection $classFrom, ClassReflection $classTo, string $module): void
    {
        $baseRef = $this->getRefFromReflection($classFrom);

        // Check $db
        $dbFrom = $this->getArrayConfigValue($classFrom, 'db', $module);
        $dbTo = $this->getArrayConfigValue($classTo, 'db', $module);
        $this->checkDbAndSimpleRelation($fqcn, $dbFrom, $dbTo, 'db', 'database field', $baseRef, $module);

        // Check $fixed_fields
        $fixedFrom = $this->getArrayConfigValue($classFrom, 'fixed_fields', $module);
        $fixedTo = $this->getArrayConfigValue($classTo, 'fixed_fields', $module);
        $this->checkDbAndSimpleRelation($fqcn, $fixedFrom, $fixedTo, 'fixed_fields', 'fixed database field', $baseRef, $module);

        // Check $has_one
        $hasOneFrom = $this->getArrayConfigValue($classFrom, 'has_one', $module);
        $hasOneTo = $this->getArrayConfigValue($classTo, 'has_one', $module);
        $this->checkHasOne($fqcn, $hasOneFrom, $hasOneTo, $baseRef, $module);

        // Check $belongs_to
        $belongsToFrom = $this->getArrayConfigValue($classFrom, 'belongs_to', $module);
        $belongsToTo = $this->getArrayConfigValue($classTo, 'belongs_to', $module);
        $this->checkDbAndSimpleRelation($fqcn, $belongsToFrom, $belongsToTo, 'belongs_to', '`belongs_to` relation', $baseRef, $module);

        // Check $has_many
        $hasManyFrom = $this->getArrayConfigValue($classFrom, 'has_many', $module);
        $hasManyTo = $this->getArrayConfigValue($classTo, 'has_many', $module);
        $this->checkDbAndSimpleRelation($fqcn, $hasManyFrom, $hasManyTo, 'has_many', '`has_many` relation', $baseRef, $module);

        // Check $belongs_many_many
        $belongsManyFrom = $this->getArrayConfigValue($classFrom, 'belongs_many_many', $module);
        $belongsManyTo = $this->getArrayConfigValue($classTo, 'belongs_many_many', $module);
        $this->checkDbAndSimpleRelation($fqcn, $belongsManyFrom, $belongsManyTo, 'belongs_many_many', '`belongs_many_many` relation', $baseRef, $module);

        // Check $many_many
        $manyManyFrom = $this->getArrayConfigValue($classFrom, 'many_many', $module);
        $manyManyTo = $this->getArrayConfigValue($classTo, 'many_many', $module);
        $this->checkManyMany($fqcn, $manyManyFrom, $manyManyTo, $baseRef, $module);
    }

    /**
     * Check for API breaking changes in database fields and simple relation types.
     * Checks include:
     * - API existed but was removed
     * - API changed database field type or relation class
     */
    private function checkDbAndSimpleRelation(
        string $fqcn,
        array $configFrom,
        array $configTo,
        string $dataType,
        string $apiType,
        string $baseRef,
        string $module
    ): void {
        // Sorting makes this easier to debug
        ksort($configFrom);
        ksort($configTo);
        foreach ($configFrom as $key => $value) {
            $ref = "{$baseRef}.{$dataType}-{$key}";
            $data = [
                'name' => trim($key, "'"),
                'apiType' => $apiType,
                'class' => $fqcn,
            ];

            // Check if relation/field has been removed
            if (!array_key_exists($key, $configTo)) {
                $this->breakingChanges[$module]['removed'][$dataType][$ref] = $data;
                continue;
            }

            // Check if relation/field class has changed
            if ($value !== $configTo[$key]) {
                $this->breakingChanges[$module]['type'][$dataType][$ref] = [
                    ...$data,
                    BreakingChangesComparer::FROM => $value,
                    BreakingChangesComparer::TO => $configTo[$key],
                ];
            }
        }
    }

    /**
     * Check for API breaking changes in has_one relations.
     * Checks include:
     * - API existed but was removed
     * - API changed relation class
     * - API changed whether it's multi-relational or not
     */
    private function checkHasOne(string $fqcn, array $hasOneFrom, array $hasOneTo, string $baseRef, string $module): void
    {
        // Sorting makes this easier to debug
        ksort($hasOneFrom);
        ksort($hasOneTo);
        $type = 'has_one';
        foreach ($hasOneFrom as $key => $value) {
            $ref = "{$baseRef}.{$type}-{$key}";
            $data = [
                'name' => trim($key, "'"),
                'apiType' => '`has_one` relation',
                'class' => $fqcn,
            ];

            // Check if relation has been removed
            if (!array_key_exists($key, $hasOneTo)) {
                $this->breakingChanges[$module]['removed'][$type][$ref] = $data;
                continue;
            }

            // Check if relation class has changed
            $fromClass = $value['class'] ?? $value;
            $toClass = $hasOneTo[$key]['class'] ?? $hasOneTo[$key];
            if ($fromClass !== $toClass) {
                $this->breakingChanges[$module]['type'][$type][$ref] = [
                    ...$data,
                    BreakingChangesComparer::FROM => $fromClass,
                    BreakingChangesComparer::TO => $toClass,
                ];
            }

            // Check if multirelational has changed
            $fromMultiRelational = $value['multirelational'] ?? false;
            $toMultiRelational = $hasOneTo[$key]['multirelational'] ?? false;
            if ($fromMultiRelational !== $toMultiRelational) {
                $this->breakingChanges[$module]['multirelational'][$type][$ref] = [
                    ...$data,
                    // $toMultiRelational is probably a string due to the way getDefaultValue() works
                    'isNow' => filter_var($toMultiRelational, FILTER_VALIDATE_BOOL),
                ];
            }
        }
    }

    /**
     * Check for API breaking changes in many_many relations.
     * Checks include:
     * - API existed but was removed
     * - API changed relation class
     * - API changed whether it's a "through" relation or not
     * - API changed some of its "through" data
     */
    private function checkManyMany(string $fqcn, array $manyManyFrom, array $manyManyTo, string $baseRef, string $module): void
    {
        // Sorting makes this easier to debug
        ksort($manyManyFrom);
        ksort($manyManyTo);
        $type = 'many_many';
        foreach ($manyManyFrom as $key => $valueFrom) {
            $ref = "{$baseRef}.{$type}-{$key}";
            $data = [
                'name' => trim($key, "'"),
                'apiType' => '`many_many` relation',
                'class' => $fqcn,
            ];

            // Check if relation has been removed
            if (!array_key_exists($key, $manyManyTo)) {
                $this->breakingChanges[$module]['removed'][$type][$ref] = $data;
                continue;
            }

            $valueTo = $manyManyTo[$key];

            // Check if they're both regular many_many
            $fromIsThrough = is_array($valueFrom);
            $toIsThrough = is_array($valueTo);
            if (!$fromIsThrough && !$toIsThrough) {
                // Check if relation class has changed
                if ($valueFrom !== $valueTo) {
                    $this->breakingChanges[$module]['type'][$type][$ref] = [
                        ...$data,
                        BreakingChangesComparer::FROM => $valueFrom,
                        BreakingChangesComparer::TO => $valueTo,
                    ];
                }
                // We don't need to check through config because there is none
                continue;
            }

            // Check if it's changing whether it's a many_many through relation
            if ($fromIsThrough !== $toIsThrough) {
                $this->breakingChanges[$module]['through'][$type][$ref] = [
                    ...$data,
                    'isNow' => $toIsThrough,
                ];
                continue;
            }

            // Check if the through config has changed
            if ($valueFrom !== $valueTo) {
                $this->breakingChanges[$module]['through-data'][$type][$ref] = $data;
            }
        }
    }

    /**
     * Get the value of an array config property, such as `$db` or `$has_one`
     */
    private function getArrayConfigValue(ClassReflection $class, string $property, string $module)
    {
        $properties = [];
        $extensions = $this->getExtensions($class, [], $module);
        foreach ($extensions as $extension) {
            $properties[] = $extension->getProperties(true)[$property] ?? null;
        }
        // Add property from class last, as it has higher priority than extension config
        $properties[] = $class->getProperties(true)[$property] ?? null;

        $value = [];
        /** @var PropertyReflection $property */
        foreach (array_filter($properties) as $property) {
            if (!$this->propertyIsConfig($property)) {
                continue;
            }
            $value = array_merge($value, $this->getDefaultValue($property->getDefault(), $class));
        }
        return $value;
    }

    /**
     * Check for API breaking changes in all parameters for a function or method.
     *
     * @param array<string,ParameterReflection> $parametersFrom
     * @param array<string,ParameterReflection> $parametersTo
     */
    private function checkParameters(array $parametersFrom, array $parametersTo, string $module): void
    {
        // Compare parameters that have the same name in both versions or removed in the new one
        $count = 0;
        $notNew = [];
        foreach ($parametersFrom as $paramName => $parameter) {
            $paramTo = $parametersTo[$paramName] ?? null;
            if ($paramTo === null) {
                // Assume params in the same position are the same param, just renamed.
                $paramTo = array_values($parametersTo)[$count] ?? null;
                $notNew[$paramTo?->getName() ?? ''] = true;
            }
            $this->checkParameter($paramName, $parameter, $paramTo, $module);
            $count++;
        }

        // New params are also breaking API changes so we need to be aware of those
        $newParams = array_diff_key($parametersTo, $parametersFrom, $notNew);
        $type = null;
        foreach ($newParams as $paramName => $newParam) {
            $ref = $this->getRefFromReflection($newParam);
            $type ??= $this->getTypeFromReflection($newParam);
            $this->breakingChanges[$module]['new'][$type][$ref] = [
                'name' => $paramName,
                'hint' => $this->getHintStringWithFQCN($newParam->getHint(), $newParam->isIntersectionType()),
                // Because of https://github.com/code-lts/doctum/issues/76 we can't always rely on the FQCN resolution above.
                'hintOrig' => $newParam->getHintAsString(),
                'function' => $newParam->getFunction()?->getName(),
                'method' => $newParam->getMethod()?->getName(),
                'class' => $this->getClassFromParam($newParam)?->getName(),
                'apiType' => 'parameter',
            ];
        }
    }

    /**
     * Get the class a parameter belongs to, if there is one.
     */
    private function getClassFromParam(?ParameterReflection $reflection): ?ClassReflection
    {
        if (!$reflection) {
            return null;
        }
        // Internally the reflection calls `$this->method->getClass()` - so if there's no method we get an error.
        if (!$reflection->getMethod()) {
            return null;
        }
        return $reflection->getClass();
    }

    /**
     * Check for API breaking changes in a parameter.
     * Checks include:
     * - API existed but was removed
     * - API changed type
     * - API changed whether it's variadic or not
     * - API changed whether it's passed by reference or not
     * - API changed default value
     */
    private function checkParameter(
        string $name,
        ParameterReflection $parameterFrom,
        ?ParameterReflection $parameterTo,
        string $module
    ): void {
        $this->output->writeln("Checking parameter $name", OutputInterface::VERBOSITY_VERY_VERBOSE);
        // The getClass() method will check against the method, so we have to check if the method's there or not first.
        $classFrom = $this->getClassFromParam($parameterFrom);
        $fileFrom = $classFrom ? $classFrom->getFile() : $parameterFrom->getFunction()?->getFile();
        $type = $this->getTypeFromReflection($parameterFrom);
        $dataFrom = [
            'name' => $name,
            'file' => $fileFrom,
            'function' => $parameterFrom->getFunction()?->getName(),
            'method' => $parameterFrom->getMethod()?->getName(),
            'class' => $classFrom?->getName(),
            'apiType' => 'parameter',
        ];
        $classTo = $this->getClassFromParam($parameterTo);
        $fileTo = $classTo ? $classTo->getFile() : $parameterTo?->getFunction()?->getFile();
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            'function' => $parameterTo?->getFunction()?->getName(),
            'method' => $parameterTo?->getMethod()?->getName(),
            // Note we intentionally use $classFrom here because that's the reference we want in the changelog.
            // It's possible for example that the code was refactored into a trait in the new version which would
            // result in $classTo being the trait, NOT the class we're actually looking at.
            'class' => $classFrom?->getName(),
            'apiType' => 'parameter',
        ];

        // Check if param has been removed
        $isMissing = $this->checkForMissingApi($name, $parameterFrom, $parameterTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        // Check for type changes
        $ref = $this->getRefFromReflection($parameterTo);
        if ($this->paramHintChanged($parameterFrom, $parameterTo)) {
            $isIntersectionTypeFrom = method_exists($parameterFrom, 'isIntersectionType') ? $parameterFrom->isIntersectionType() : false;
            $isIntersectionTypeTo = method_exists($parameterTo, 'isIntersectionType') ? $parameterTo->isIntersectionType() : false;
            $this->breakingChanges[$module]['type'][$type][$ref] = [
                ...$dataTo,
                BreakingChangesComparer::FROM => $this->getHintStringWithFQCN($parameterFrom->getHint(), $isIntersectionTypeFrom),
                BreakingChangesComparer::TO => $this->getHintStringWithFQCN($parameterTo->getHint(), $isIntersectionTypeTo),
                // Because of https://github.com/code-lts/doctum/issues/76 we can't always rely on the FQCN resolution above.
                BreakingChangesComparer::FROM . 'Orig' => $parameterFrom->getHintAsString(),
                BreakingChangesComparer::TO . 'Orig' => $parameterTo->getHintAsString(),
            ];
        }

        // Check if param was renamed
        if ($parameterTo->getName() !== $name) {
            $this->breakingChanges[$module]['renamed'][$type][$ref] = [
                ...$dataTo,
                BreakingChangesComparer::FROM => $name,
                BreakingChangesComparer::TO => $parameterTo->getName(),
            ];
        }

        // Changed whether it's variable-length (preceded with `...`) or not
        if ($parameterFrom->getVariadic() !== $parameterTo->getVariadic()) {
            $this->breakingChanges[$module]['variadic'][$type][$ref] = [
                ...$dataTo,
                'isNow' => $parameterTo->getVariadic(),
            ];
        }

        // Changed whether it's passed by reference (preceeded with `&`) or not
        if ($parameterFrom->isByRef() !== $parameterTo->isByRef()) {
            $this->breakingChanges[$module]['passByRef'][$type][$ref] = [
                ...$dataTo,
                'isNow' => $parameterTo->isByRef(),
            ];
        }

        // Change to the default value
        if ($this->defaultValuesDiffer($parameterFrom->getDefault(), $parameterTo->getDefault())) {
            $this->breakingChanges[$module]['default'][$type][$ref] = [
                ...$dataTo,
                BreakingChangesComparer::FROM => $parameterFrom->getDefault(),
                BreakingChangesComparer::TO => $parameterTo->getDefault(),
            ];
        }
    }

    /**
     * Check if the type hint of a paramater has changed or not.
     */
    private function paramHintChanged(ParameterReflection $parameterFrom, ?ParameterReflection $parameterTo)
    {
        // No change
        if ($parameterFrom->getHintAsString() === $parameterTo->getHintAsString()) {
            return false;
        }

        // `string $something = null` is the same as `?string $something = null`
        // But don't ignore if the `string` part changes - i.e. `?string` and `?int` are not the same!
        if (($parameterFrom->getDefault() === 'null' || $this->hintIsNullable($parameterFrom->getHint()))
            || ($parameterTo->getDefault() === 'null' || $this->hintIsNullable($parameterTo->getHint()))
        ) {
            $fromNoNull = [];
            $toNoNull = [];
            foreach ($parameterFrom->getHint() as $hintReflection) {
                if ($hintReflection->getName() !== 'null') {
                    $fromNoNull[] = $hintReflection->getName();
                }
            }
            foreach ($parameterTo->getHint() as $hintReflection) {
                if ($hintReflection->getName() !== 'null') {
                    $toNoNull[] = $hintReflection->getName();
                }
            }
            return $fromNoNull !== $toNoNull;
        }

        return true;
    }

    /**
     * Check if a type hint is nullable or not.
     */
    private function hintIsNullable(array $hintArray): bool
    {
        foreach ($hintArray as $hint) {
            if ((string) $hint->getName() === 'null') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the default value for two params are materially different.
     * Ignores changes that don't affect anything, such as whether single or double quotes are used.
     */
    private function defaultValuesDiffer(mixed $valueFrom, mixed $valueTo): bool
    {
        if ($valueFrom !== $valueTo) {
            // I think it IS always a string, but the API doesn't guarantee that.
            // For safety, anything that isn't a string just gets a straight equality check.
            if (!is_string($valueFrom) || !is_string($valueTo)) {
                return $valueFrom !== $valueTo;
            }
            // See if the quote types are all that changed.
            // The values are RAW - i.e. string values include their surrounding quotes.
            // This is a very naive way to check if the quotes are all that changed - there are probably a lot of edge cases.
            $fromUseSingleQuote = str_replace(['\\"', '"'], "'", $valueFrom);
            $toUseSingleQuote = str_replace(['\\"', '"'], "'", $valueTo);
            return $fromUseSingleQuote !== $toUseSingleQuote;
        }
        return false;
    }

    /**
     * Checks to see if API was either removed, or became internal in the new version.
     * Also checks for any actions that need to be taken, e.g. deprecate in the old version.
     *
     * @return bool True if further comparisons should be skipped (e.g. the API is missing in the new version)
     */
    private function checkForMissingApi(
        string $name,
        Reflection $reflectionFrom,
        ?Reflection $reflectionTo,
        array $dataFrom,
        array $dataTo,
        string $module
    ): bool {
        // Skip check if the old version came from an extension.
        // It'll be checked against the extension itself instead.
        if ($reflectionFrom instanceof ExtensionReflection) {
            return true;
        }

        $type = $this->getTypeFromReflection($reflectionFrom);
        $ref = $this->getRefFromReflection($reflectionFrom);

        // If the API was already internal, we don't need to do anything.
        if ($reflectionFrom->isInternal()) {
            return true;
        }
        // If the API was already private (but not config), we don't need to do anything.
        if ($this->getVisibilityFromReflection($reflectionFrom) === 'private'
            && (!is_a($reflectionFrom, PropertyReflection::class) || !$this->propertyIsConfig($reflectionFrom))
        ) {
            return true;
        }

        // Check if API went missing
        if ($reflectionTo === null || $dataTo['file'] === null) {
            // Constructors don't need any actions if there were no params
            if (is_a($reflectionFrom, MethodReflection::class)
                && $name === '__construct'
                && count($reflectionFrom->getParameters()) === 0
            ) {
                return true;
            }

            // Mark whether we still need to deprecate it, and note the breaking change.
            // Note params can't be marked deprecated.
            if ($type !== 'param' && !$reflectionFrom->isDeprecated()) {
                $this->actionsToTake[$module][BreakingChangesComparer::ACTION_DEPRECATE][$type][$ref] = [
                    ...$dataFrom,
                    'message' => 'This API was removed, but hasn\'t been deprecated.',
                ];
            }
            // Note the breaking change.
            $this->breakingChanges[$module]['removed'][$type][$ref] = [
                ...$dataFrom,
                'message' => $this->getDeprecationMessage($name, $reflectionFrom, $dataFrom, $module),
            ];
            return true;
        }

        // API didn't used to be @internal, but it is now (removes it from our public API surface)
        if (!$reflectionFrom->isInternal() && $reflectionTo->isInternal()) {
            if ($type !== 'param' && !$reflectionFrom->isDeprecated()) {
                $this->actionsToTake[$module][BreakingChangesComparer::ACTION_DEPRECATE][$type][$ref] = [
                    ...$dataFrom,
                    'message' => 'This API was made @internal, but hasn\'t been deprecated.',
                ];
            }
            if ($type === 'config') {
                // @internal config is literally removed, because it won't be picked up as config anymore.
                $this->breakingChanges[$module]['removed'][$type][$ref] = [
                    ...$dataFrom,
                    'message' => $this->getDeprecationMessage($name, $reflectionFrom, $dataFrom, $module),
                ];
            } else {
                $this->breakingChanges[$module]['internal'][$type][$ref] = [
                    ...$dataFrom,
                    'message' => $this->getDeprecationMessage($name, $reflectionFrom, $dataFrom, $module),
                ];
            }
            // Internal API is effectively removed.
            return true;
        }

        // API was and still is deprecated but hasn't been removed
        // Note we don't care about API that used to be deprecated but no longer is,
        // or which is deprecated in the new version but not the old.
        if ($type !== 'param' && $reflectionFrom->isDeprecated() && $reflectionTo->isDeprecated()) {
            $this->actionsToTake[$module][BreakingChangesComparer::ACTION_REMOVE][$type][$ref] = [
                ...$dataTo,
                'message' => 'This API is deprecated, but hasn\'t been removed.',
            ];
        }

        return false;
    }

    /**
     * Checks to see if the signature changed for an API (e.g. change of type or became `final`)
     */
    private function checkForSignatureChanges(
        Reflection $reflectionFrom,
        ?Reflection $reflectionTo,
        array $data,
        string $module
    ): void {
        $type = $this->getTypeFromReflection($reflectionFrom);
        $ref = $this->getRefFromReflection($reflectionFrom);
        $hintType = null;
        if (in_array($type, ['function', 'method'])) {
            $hintType = 'returnType';
        }
        // param not included because that gets tested in a special way in checkParam()
        if (in_array($type, ['property', 'const'])) {
            $hintType = 'type';
        }

        // Check for type changes
        if ($hintType !== null && $reflectionFrom->getHintAsString() !== $reflectionTo->getHintAsString()) {
            $isIntersectionTypeFrom = method_exists($reflectionFrom, 'isIntersectionType') ? $reflectionFrom->isIntersectionType() : false;
            $isIntersectionTypeTo = method_exists($reflectionTo, 'isIntersectionType') ? $reflectionTo->isIntersectionType() : false;
            $this->breakingChanges[$module][$hintType][$type][$ref] = [
                ...$data,
                BreakingChangesComparer::FROM => $this->getHintStringWithFQCN($reflectionFrom->getHint(), $isIntersectionTypeFrom),
                BreakingChangesComparer::TO => $this->getHintStringWithFQCN($reflectionTo->getHint(), $isIntersectionTypeTo),
                // Because of https://github.com/code-lts/doctum/issues/76 we can't always rely on the FQCN resolution above.
                BreakingChangesComparer::FROM . 'Orig' => $reflectionFrom->getHintAsString(),
                BreakingChangesComparer::TO . 'Orig' => $reflectionTo->getHintAsString(),
            ];
        }

        $this->checkForVisibilityChanges($reflectionFrom, $reflectionTo, $data, $module);

        // Check for becoming final
        if (!$reflectionFrom->isFinal() && $reflectionTo->isFinal()) {
            $this->breakingChanges[$module]['final'][$type][$ref] = $data;
        }

        // Check for becoming abstract
        if (!$reflectionFrom->isAbstract() && $reflectionTo->isAbstract()) {
            $this->breakingChanges[$module]['abstract'][$type][$ref] = $data;
        }
    }

    /**
     * Checks to see if the visibility of the API has changed.
     * Note that changing between undefined and `public` doesn't count as a breaking change.
     */
    private function checkForVisibilityChanges(
        Reflection $reflectionFrom,
        ?Reflection $reflectionTo,
        array $data,
        string $module
    ): void {
        $visibilityFrom = $this->getVisibilityFromReflection($reflectionFrom);
        $visibilityTo = $this->getVisibilityFromReflection($reflectionTo);

        if ($visibilityFrom === $visibilityTo) {
            return;
        }

        // Nothing to public or vice versa isn't a breaking change in PHP 8.
        if ((!$visibilityFrom && $visibilityTo === 'public') || ($visibilityFrom === 'public' && !$visibilityFrom)) {
            return;
        }

        $type = $this->getTypeFromReflection($reflectionFrom);
        $ref = $this->getRefFromReflection($reflectionFrom);
        $this->breakingChanges[$module]['visibility'][$type][$ref] = [
            ...$data,
            BreakingChangesComparer::FROM => $visibilityFrom,
            BreakingChangesComparer::TO => $visibilityTo,
        ];
    }

    /**
     * Get the string deprecation message (excluding version number) if the API is deprecated.
     */
    private function getDeprecationMessage(string $name, Reflection $reflection, array $data, string $module): string
    {
        $type = $this->getTypeFromReflection($reflection);
        $ref = $this->getRefFromReflection($reflection);
        $messagesArray = $reflection->getDeprecated();

        // Params don't get deprecation messages, and anything that isn't deprecated obviously won't have a message.
        if ($type === 'param' || !$reflection->isDeprecated()) {
            return '';
        }

        // If there's no message at all, we need to add one.
        if (empty($messagesArray)) {
            $this->actionsToTake[$module][BreakingChangesComparer::ACTION_FIX_DEPRECATION][$type][$ref] = [
                ...$data,
                'message' => 'The deprecation annotation is missing a message.',
            ];
            return '';
        }

        // There should only be one deprecation message.
        if (count($messagesArray) > 1) {
            $this->actionsToTake[$module][BreakingChangesComparer::ACTION_FIX_DEPRECATION][$type][$ref] = [
                ...$data,
                'message' => 'There are multiple deprecation notices for this API.',
            ];
            return '';
        }

        $messageAsArray = $messagesArray[0];
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $messageAsArray[0])) {
            unset($messageAsArray[0]);
        } else {
            // If the first item in the array isn't a version number, the deprecation is malformed
            // We'll assume the message is present without a version number, though.
            $this->actionsToTake[$module][BreakingChangesComparer::ACTION_FIX_DEPRECATION][$type][$ref] = [
                ...$data,
                'message' => 'The version number for this deprecation notice is missing or malformed. Should be in the form "1.2.0".',
            ];
        }

        if (empty($messageAsArray)) {
            $this->actionsToTake[$module][BreakingChangesComparer::ACTION_FIX_DEPRECATION][$type][$ref] = [
                ...$data,
                'message' => 'The deprecation annotation is missing a message.',
            ];
            return '';
        }

        return implode(' ', $messageAsArray);
    }

    /**
     * Get the visibility of the API this reflection represents.
     */
    private function getVisibilityFromReflection(Reflection $reflection): string
    {
        if ($reflection->isPrivate()) {
            return 'private';
        }
        if ($reflection->isProtected()) {
            return 'protected';
        }
        if ($reflection->isPublic()) {
            return 'public';
        }
        return '';
    }

    /**
     * Get a "type" reference for the API this reflection represents.
     */
    private function getTypeFromReflection(Reflection $reflection): string
    {
        $reflectionClass = get_class($reflection);
        return match ($reflectionClass) {
            ClassReflection::class => 'class',
            ParameterReflection::class => 'param',
            MethodReflection::class => 'method',
            ExtensionMethodReflection::class => 'method',
            PropertyReflection::class => $this->propertyIsConfig($reflection) ? 'config' : 'property',
            ExtensionConfigReflection::class => 'config',
            ConstantReflection::class => 'const',
            FunctionReflection::class => 'function',
            default => throw new InvalidArgumentException("Unexpected reflection type: $reflectionClass"),
        };
    }

    /**
     * Get a unique reference for the API this reflection represents to avoid collisions in array keys.
     */
    private function getRefFromReflection(Reflection $reflection): string
    {
        $baseName = $reflection->getName();
        if ($reflection instanceof ClassReflection) {
            return $baseName;
        }

        if ($reflection instanceof FunctionReflection) {
            return "{$baseName}()";
        }

        $reflectionClass = get_class($reflection);
        $baseName = match ($reflectionClass) {
            ExtensionMethodReflection::class => "::{$baseName}()",
            MethodReflection::class => "::{$baseName}()",
            PropertyReflection::class => "->{$baseName}",
            ExtensionConfigReflection::class => "->{$baseName}",
            ConstantReflection::class => "::{$baseName}",
            ParameterReflection::class => "\${$baseName}",
            default => throw new InvalidArgumentException("Unexpected reflection type: $reflectionClass"),
        };

        if ($reflection instanceof ParameterReflection) {
            $function = $reflection->getFunction();
            if ($this->apiExists($function)) {
                return $function->getName() . "({$baseName})";
            }
            // If there was no function there should be a method
            $method = $reflection->getMethod();
            if ($method) {
                return rtrim($this->getRefFromReflection($method), ')') . "({$baseName})";
            }
            // In the off chance there's no method, just use the base name alone.
            return $baseName;
        }

        // Everything else should belong to a class, but if not just use the base name alone
        $class = $reflection->getClass();
        if (!$class) {
            return $baseName;
        }

        return $class->getName() . $baseName;
    }

    /**
     * Gets the packagist name for the module the file lives in.
     * @throws LogicException if the module name can't be found.
     */
    private function getModuleForFile(string $filePath): string
    {
        $regex = '#' . CloneCommand::DIR_CLONE. '/(?:' . BreakingChangesComparer::FROM . '|' . BreakingChangesComparer::TO . ')/vendor/([^/]+/[^/]+)/#';
        preg_match($regex, $filePath, $matches);
        $module = $matches[1];
        if (!$module) {
            throw new LogicException("There is no module name identifiable in the path '$filePath'");
        }
        return $module;
    }

    /**
     * Get the string for a typehint including the FQCN of classes where appropriate.
     * Note that Reflection::getHintAsString() ignores both intersection types and FQCN.
     *
     * @param HintReflection[] $hints
     */
    private function getHintStringWithFQCN(array $hints, bool $isIntersectionType): string
    {
        $hintParts = [];
        foreach ($hints as $hint) {
            $hintParts[] = (string) $hint->getName();
        }
        $separator = $isIntersectionType ? '&' : '|';
        $x = implode($separator, $hintParts);
        return $x;
    }

    /**
     * Get the value a node represents as either a string or an array.
     */
    private function getDefaultValue(mixed $default, ClassReflection $class, bool $simplifyArray = true): string|array
    {
        // The values were encoded, then decoded as arrays. We need to re-encode then decode as node instances.
        $decoder = new JsonDecoder();
        /** @var Node|null $node */
        $node = $decoder->decode(json_encode($default));
        if (!$node) {
            return 'null';
        }
        if ($node instanceof String_) {
            return "'{$node->value}'";
        }
        if ($node instanceof Array_) {
            $items = [];
            foreach ($node->items as $item) {
                $key = trim($this->getDefaultValue($item->key, $class), "'");
                $value = $this->getDefaultValue($item->value, $class);
                $items[$key] = $value;
            }
            return $items;
        }
        // ConstFetch seems to include null and bool values
        if ($node instanceof ConstFetch) {
            return $node->name->name ?? $node->name->toString();
        }
        if ($node instanceof ClassConstFetch) {
            $class = $node->class->name ?? $node->class->toString();
            $const = $node->name->name ?? $node->name->toString();
            if ($const === 'class') {
                return $class;
            }
            return "{$class}::{$const}";
        }
        if ($node instanceof LNumber || $node instanceof DNumber) {
            return (string) $node->value;
        }
        if ($node instanceof Concat) {
            return "'" . trim($this->getDefaultValue($node->left, $class), "'") . trim($this->getDefaultValue($node->right, $class), "'") . "'";
        }
        if ($node instanceof UnaryMinus) {
            if ($node->expr instanceof DNumber || $node->expr instanceof LNumber) {
                return '-' . $this->getDefaultValue($node->expr, $class);
            }
        }
        if ($node instanceof Class_) {
            return $class->getName();
        }
        $nodeClass = get_class($node);
        throw new LogicException("Unexpected node type {$nodeClass} - need to add logic to handle this.");
    }

    /**
     * Check if API represented by this reflection actually exists
     */
    private function apiExists(Reflection|false|null $reflection): bool
    {
        if (!$reflection) {
            return false;
        }

        if (method_exists($reflection, 'getFile') && $reflection->getFile() === null) {
            return false;
        }

        return true;
    }

    /**
     * Check if a property is configuration
     */
    private function propertyIsConfig(?PropertyReflection $property): bool
    {
        if (!$property) {
            return false;
        }
        // Configuration can be in extensions even though they don't use the Configurable trait
        $class = $property->getClass();
        if (!$this->instanceOf($class, 'SilverStripe\Core\Extension') && !$this->hasTrait($class, 'SilverStripe\Core\Config\Configurable')) {
            return false;
        }
        return $property->isPrivate() && $property->isStatic();
    }

    private function instanceOf(?ClassReflection $class, string $instanceOf): bool
    {
        if (!$class) {
            return false;
        }
        $hierarchy = [$class, ...$class->getParent(true)];
        foreach ($hierarchy as $candidate) {
            if ($candidate->getName() === $instanceOf) {
                return true;
            }
        }
        return false;
    }

    private function hasTrait(?ClassReflection $class, string $traitName): bool
    {
        if (!$class) {
            return false;
        }
        foreach ($class->getTraits(true) as $trait) {
            if ($trait->getName() === $traitName) {
                return true;
            }
        }
        return false;
    }
}
