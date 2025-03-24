<?php

namespace Silverstripe\DeprecationChangelogGenerator\Compare;

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
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\MagicConst\Class_;
use PhpParser\Node\Scalar\String_;
use Silverstripe\DeprecationChangelogGenerator\Command\CloneCommand;
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
            'line' => $functionFrom->getLine(),
            'apiType' => 'function',
        ];
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            'line' => $functionTo?->getLine(),
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
                'isNow' => $functionTo->isByRef(),
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
            'line' => $classFrom->getLine(),
            'apiType' => $apiTypeFrom,
        ];
        $dataTo = [
            'name' => $fqcn,
            'file' => $fileTo,
            'line' => $classTo?->getLine(),
            'apiType' => $apiTypeTo,
        ];

        $this->addApiFromExtensions($fqcn, $classFrom, $dataFrom, $module);
        $this->addApiFromExtensions($fqcn, $classTo, $dataTo, $module);

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

        $this->checkConstants($fqcn, $classFrom->getConstants(true), $classTo->getConstants(true), $module);
        $this->checkProperties($fqcn, $classFrom->getProperties(true), $classTo->getProperties(true), $module);
        $this->checkMethods($fqcn, $classFrom->getMethods(true), $classTo->getMethods(true), $classTo, $module);
    }

    /**
     * Add public methods and configuration from extensions.
     * API in extensions that are applied to an extension directly in the class are considered part of that class's API.
     */
    private function addApiFromExtensions(string $name, ?ClassReflection $class, array $data, string $module): void
    {
        /** @var PropertyReflection $extensionsProperty */
        $extensionsProperty = $class?->getProperties()['extensions'] ?? null;
        if (!$extensionsProperty) {
            return;
        }
        $type = $this->getTypeFromReflection($class);
        $ref = $this->getRefFromReflection($class);
        $extensionsRawValue = $extensionsProperty->getDefault();
        if (empty($extensionsRawValue['items'])) {
            return;
        }
        $classProperties = $class->getProperties();
        foreach ($extensionsRawValue['items'] ?? [] as $extensionRaw) {
            $value = $extensionRaw['value'];
            // This is the type for PhpParser\Node\Expr\ClassConstFetch i.e. SomeClass::class
            if ($value['nodeType'] !== 'Expr_ClassConstFetch') {
                $this->actionsToTake[$module][BreakingChangesComparer::ACTION_DEPRECATE][$type][$ref] = [
                    ...$data,
                    'message' => 'The value for the $extensions configuration property has an unexpected format or type.',
                ];
                continue;
            }
            // Get reflection object for the extension class
            $extensionFQCN = implode('\\', $value['class']['parts']);
            $extension = $class->getProject()->getClass($extensionFQCN);
            if (!$this->apiExists($extension)) {
                $this->actionsToTake[$module][BreakingChangesComparer::ACTION_DEPRECATE][$type][$ref] = [
                    ...$data,
                    'message' => "The '$extensionFQCN' class referenced in the \$extensions configuration property doesn't exist.",
                ];
                continue;
            }
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
                if ($property->isPrivate() && $property->isStatic() && !array_key_exists($property->getName(), $classProperties)) {
                    $extensionConfig = ExtensionConfigReflection::fromArray($class->getProject(), $property->toArray());
                    $extensionConfig->setExtensionClass($extension);
                    $class->addProperty($extensionConfig);
                }
            }
        }
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
            // Skip comparison where the API used to be in a parent class or explicitly in a trait and wasn't overridden
            if ($const->getClass()?->getName() !== $className) {
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
            'line' => $constFrom->getLine(),
            'class' => $classFrom?->getName(),
            'apiType' => 'constant',
        ];
        /** @var ClassReflection|null $classTo */
        $classTo = $constTo?->getClass();
        $fileTo = $classTo?->getFile() ?? null;
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            'line' => $constTo?->getLine(),
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
            // Skip comparison where the API used to be in a parent class or explicitly in a trait and wasn't overridden
            if ($property->getClass()?->getName() !== $className) {
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
            'line' => $propertyFrom->getLine(),
            'class' => $classFrom?->getName(),
            'apiType' => ($propertyFrom->isPrivate() && $propertyFrom->isStatic()) ? 'config' : 'property',
        ];
        /** @var ClassReflection|null $classTo */
        $classTo = $propertyTo?->getClass();
        $fileTo = $classTo?->getFile() ?? null;
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            'line' => $propertyTo?->getLine(),
            // Note we intentionally use $classFrom here because that's the reference we want in the changelog.
            // It's possible for example that the code was refactored into a trait in the new version which would
            // result in $classTo being the trait, NOT the class we're actually looking at.
            'class' => $classFrom?->getName(),
            'apiType' => ($propertyTo?->isPrivate() && $propertyTo?->isStatic()) ? 'config' : 'property',
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

        // Change to the default value of configuration property specifically
        if ($propertyFrom->isPrivate() && $propertyFrom->isStatic()) {
            // The values were encoded, then decoded as arrays. We need to re-encode then decode as node instances.
            $decoder = new JsonDecoder();
            $propertyNodeFrom = $decoder->decode(json_encode($propertyFrom->getDefault()));
            $propertyNodeTo = $decoder->decode(json_encode($propertyTo->getDefault()));
            // Compare the values as strings or arrays as that's much easier
            $propertyValueFrom = $this->getNodeValue($propertyNodeFrom, $classFrom);
            $propertyValueTo = $this->getNodeValue($propertyNodeTo, $classTo);
            if ($this->defaultParamValuesDiffer($propertyValueFrom, $propertyValueTo)) {
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
            // Skip comparison where the API used to and still does belong on the parent class
            // i.e. even if it is explicitly overridden in the subclass, only the superclass needs to report the changes.
            if ($methodClass?->getParentMethod($methodName) && $classTo->getParentMethod($methodName)) {
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
            'line' => $methodFrom->getLine(),
            'class' => $classFrom?->getName(),
            'apiType' => 'method',
        ];
        $classTo = $methodTo?->getClass();
        $fileTo = $classTo?->getFile() ?? null;
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            'line' => $methodTo?->getLine(),
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

        // Changed whether it's passed by reference (preceeded with `&`) or not
        if ($methodFrom->isByRef() !== $methodTo->isByRef()) {
            $type = $this->getTypeFromReflection($methodTo);
            $ref = $this->getRefFromReflection($methodTo);
            $this->breakingChanges[$module]['returnByRef'][$type][$ref] = [
                ...$dataTo,
                'isNow' => $methodTo->isByRef(),
            ];
        }

        $this->checkParameters($methodFrom->getParameters(), $methodTo->getParameters(), $module);
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
                'class' => $newParam->getClass()?->getName(),
                'apiType' => 'parameter',
            ];
        }
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
        $classFrom = $parameterFrom->getMethod() ? $parameterFrom->getClass() : null;
        $fileFrom = $classFrom ? $classFrom->getFile() : $parameterFrom->getFunction()?->getFile();
        $type = $this->getTypeFromReflection($parameterFrom);
        $dataFrom = [
            'name' => $name,
            'file' => $fileFrom,
            'line' => $parameterFrom->getLine(),
            'function' => $parameterFrom->getFunction()?->getName(),
            'method' => $parameterFrom->getMethod()?->getName(),
            'class' => $classFrom?->getName(),
            'apiType' => 'parameter',
        ];
        $classTo = $parameterTo?->getMethod() ? $parameterTo?->getClass() : null;
        $fileTo = $classTo ? $classTo->getFile() : $parameterTo?->getFunction()?->getFile();
        $dataTo = [
            'name' => $name,
            'file' => $fileTo,
            'line' => $parameterTo?->getLine(),
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

        // @TODO check manually instead. Only need to check type - but we have to do that custom because
        //       `string $something = null` is the same as `?string $something = null`
        $this->checkForSignatureChanges($parameterFrom, $parameterTo, $dataTo, $module);

        // Check if param was renamed
        $ref = $this->getRefFromReflection($parameterTo);
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
        if ($this->defaultParamValuesDiffer($parameterFrom->getDefault(), $parameterTo->getDefault())) {
            $this->breakingChanges[$module]['default'][$type][$ref] = [
                ...$dataTo,
                BreakingChangesComparer::FROM => $parameterFrom->getDefault(),
                BreakingChangesComparer::TO => $parameterTo->getDefault(),
            ];
        }
    }

    /**
     * Check if the default value for two params are materially different.
     * Ignores changes that don't affect anything, such as whether single or double quotes are used.
     */
    private function defaultParamValuesDiffer(mixed $valueFrom, mixed $valueTo): bool
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

        // Check if API went missing
        if ($reflectionTo === null || $dataTo['file'] === null) {
            // If the API was already internal, we don't need to do anything.
            if ($reflectionFrom->isInternal()) {
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
        if (in_array($type, ['property', 'const', 'param'])) {
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
            PropertyReflection::class => ($reflection->isPrivate() && $reflection->isStatic()) ? 'config' : 'property',
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
    private function getNodeValue(?Node $node, ClassReflection $class): string|array
    {
        if (!$node) {
            return 'null';
        }
        if ($node instanceof String_) {
            return "'{$node->value}'";
        }
        if ($node instanceof Array_) {
            $items = [];
            foreach ($node->items as $item) {
                $items[] = $this->getNodeValue($item, $class);
            }
            return $items;
        }
        if ($node instanceof ArrayItem) {
            $key = $this->getNodeValue($node->key, $class);
            $value = $this->getNodeValue($node->value, $class);
            if (is_array($key) || is_array($value)) {
                // the value will be dealt with in checkProperty which calls this.
                return ['sub-array'];
            }
            return "[{$key} => {$value}]";
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
            return "'" . trim($this->getNodeValue($node->left, $class), "'") . trim($this->getNodeValue($node->right, $class), "'") . "'";
        }
        if ($node instanceof UnaryMinus) {
            if ($node->expr instanceof DNumber || $node->expr instanceof LNumber) {
                return '-' . $this->getNodeValue($node->expr, $class);
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
}
