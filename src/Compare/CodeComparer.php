<?php

namespace Silverstripe\DeprecationChangelogGenerator\Compare;

use Doctum\Project;
use Doctum\Reflection\ClassReflection;
use Doctum\Reflection\ConstantReflection;
use Doctum\Reflection\FunctionReflection;
use Doctum\Reflection\MethodReflection;
use Doctum\Reflection\ParameterReflection;
use Doctum\Reflection\PropertyReflection;
use Doctum\Reflection\Reflection;
use Doctum\Version\Version;
use InvalidArgumentException;
use LogicException;
use Silverstripe\DeprecationChangelogGenerator\Command\CloneCommand;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compares code between versions to find breaking API changes.
 */
class CodeComparer
{
    public const string FROM = 'from';

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

    private Project $project;

    private OutputInterface $output;

    private array $breakingChanges = [];

    private array $actionsToTake = [];

    public function __construct(Project $project, OutputInterface $output)
    {
        $this->project = $project;
        $this->output = $output; // @TODO decide whether to provide some verbose output
    }

    public function getBreakingChanges(): array
    {
        return $this->breakingChanges;
    }

    public function getActionsToTake(): array
    {
        return $this->actionsToTake;
    }

    public function compare()
    {
        $fromProject = $this->project;
        // @TODO find out what the $force arg does and if we need to set it
        $fromProject->switchVersion(new Version(CodeComparer::FROM));
        $toProject = clone $this->project;
        $toProject->switchVersion(new Version(CodeComparer::TO));

        // @TODO is there a way to get the global consts?
        //       We'd want to check for any consts that went missing or were deprecated and not removed, etc

        // Compare global functions that are provided in Silverstripe CMS code
        $functionsTo = $toProject->getProjectFunctions();
        foreach ($fromProject->getProjectFunctions() as $index => $functionInfo) {
            // Note the index of this array isn't the function name, unlike with classes and interfaces.
            $this->checkGlobalFunction($functionInfo->getName(), $functionInfo, $functionsTo[$index] ?? null);
            // @TODO Index may not be identical across from and to!!!!! Need a more robust way to get the "TO" version
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

        /**
         * @TODO Consider iterating over $toProject now and check for NEWLY deprecated code (including new code immediately deprecated)
         */
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
    ) {
        $fileFrom = $functionFrom->getFile();
        $fileTo = $functionTo?->getFile();
        $module = $this->getModuleForFile($fileFrom);
        $dataFrom = [
            'file' => $fileFrom,
            'line' => $functionFrom->getLine(),
            'apiType' => 'function',
        ];
        $dataTo = [
            'file' => $fileTo,
            'line' => $functionTo?->getLine(),
            'apiType' => 'function',
        ];

        $isMissing = $this->checkForMissingApi($name, $functionFrom, $functionTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($name, $functionFrom, $functionTo, $dataFrom, $dataTo, $module);

        // Changed whether it's passed by reference (preceeded with `&`) or not
        if ($functionFrom->isByRef() !== $functionTo->isByRef()) {
            $type = $this->getTypeFromReflection($functionFrom);
            $this->breakingChanges[$module]['returnByRef'][$type][$name] = [
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
    private function checkClass(string $fqcn, ClassReflection $classFrom, ?ClassReflection $classTo)
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

        $fileFrom = $classFrom->getFile();
        $fileTo = $classTo?->getFile();
        $module = $this->getModuleForFile($fileFrom);
        $type = $this->getTypeFromReflection($classFrom);
        $dataFrom = [
            'file' => $fileFrom,
            'line' => $classFrom->getLine(),
            'apiType' => $apiTypeFrom,
        ];
        $dataTo = [
            'file' => $fileTo,
            'line' => $classTo?->getLine(),
            'apiType' => $apiTypeTo,
        ];

        $isMissing = $this->checkForMissingApi($fqcn, $classFrom, $classTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($fqcn, $classFrom, $classTo, $dataFrom, $dataTo, $module);

        // Class-like has changed what type of class-like it is (e.g. from class to interface)
        if ($classFrom->getCategoryId() !== $classTo->getCategoryId()) {
            $this->breakingChanges[$module]['type'][$type][$fqcn] = [
                CodeComparer::FROM => $apiTypeFrom,
                CodeComparer::TO => $apiTypeTo,
            ];
        }

        $this->checkConstants($fqcn, $classFrom->getConstants(true), $classTo->getConstants(true), $module);
        $this->checkProperties($fqcn, $classFrom->getProperties(true), $classTo->getProperties(true), $module);
        $this->checkMethods($fqcn, $classFrom->getMethods(true), $classTo->getMethods(true), $module);
    }

    /**
     * Undocumented function
     *
     * @param array<string,ConstantReflection> $constsFrom
     * @param array<string,ConstantReflection> $constsTo
     */
    private function checkConstants(string $className, array $constsFrom, array $constsTo, string $module)
    {
        // Compare consts that have the same name in both versions or removed in the new one
        foreach ($constsFrom as $constName => $const) {
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
    ) {
        /** @var ClassReflection|null $classFrom */
        $classFrom = $constFrom->getClass();
        $fileFrom = method_exists($classFrom ?? '', 'getFile') ? $classFrom->getFile() : null;
        $dataFrom = [
            'file' => $fileFrom,
            'line' => $constFrom->getLine(),
            'class' => $classFrom?->getName(),
            'apiType' => 'constant',
        ];
        /** @var ClassReflection|null $classTo */
        $classTo = $constTo?->getClass();
        $fileTo = method_exists($classTo ?? '', 'getFile') ? $classTo->getFile() : null;
        $dataTo = [
            'file' => $fileTo,
            'line' => $constTo?->getLine(),
            'class' => $classTo?->getName(),
            'apiType' => 'constant',
        ];

        $isMissing = $this->checkForMissingApi($name, $constFrom, $constTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($name, $constFrom, $constTo, $dataFrom, $dataTo, $module);
    }

    /**
     * Undocumented function
     *
     * @param array<string,PropertyReflection> $propertiesFrom
     * @param array<string,PropertyReflection> $propertiesTo
     */
    private function checkProperties(string $className, array $propertiesFrom, array $propertiesTo, string $module)
    {
        // Compare properties that have the same name in both versions or removed in the new one
        foreach ($propertiesFrom as $propertyName => $property) {
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
    ) {
        $type = $this->getTypeFromReflection($propertyFrom);
        /** @var ClassReflection|null $classFrom */
        $classFrom = $propertyFrom->getClass();
        $fileFrom = method_exists($classFrom ?? '', 'getFile') ? $classFrom->getFile() : null;
        $dataFrom = [
            'file' => $fileFrom,
            'line' => $propertyFrom->getLine(),
            'class' => $classFrom?->getName(),
            'apiType' => ($propertyFrom->isPrivate() && $propertyFrom->isStatic()) ? 'config' : 'property',
        ];
        /** @var ClassReflection|null $classTo */
        $classTo = $propertyTo?->getClass();
        $fileTo = method_exists($classTo ?? '', 'getFile') ? $classTo->getFile() : null;
        $dataTo = [
            'file' => $fileTo,
            'line' => $propertyTo?->getLine(),
            'class' => $classTo?->getName(),
            'apiType' => ($propertyTo?->isPrivate() && $propertyTo?->isStatic()) ? 'config' : 'property',
        ];

        $isMissing = $this->checkForMissingApi($name, $propertyFrom, $propertyTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($name, $propertyFrom, $propertyTo, $dataFrom, $dataTo, $module);

        if ($propertyFrom->isReadOnly() !== $propertyTo->isReadOnly()) {
            $this->breakingChanges[$module]['readonly'][$type][$name] = [
                ...$dataTo,
                'isNow' => $propertyTo->isReadOnly(),
            ];
        }
    }

    /**
     * Undocumented function
     *
     * @param array<string,MethodReflection> $methodsFrom
     * @param array<string,MethodReflection> $methodsTo
     */
    private function checkMethods(string $className, array $methodsFrom, array $methodsTo, string $module)
    {
        // @TODO make sure no @method tags are listed in here.
        // Compare methods that have the same name in both versions or removed in the new one
        foreach ($methodsFrom as $methodName => $method) {
            if ($method->getClass()?->getName() !== $className) {
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
    ) {
        $classFrom = $methodFrom->getClass();
        $fileFrom = method_exists($classFrom ?? '', 'getFile') ? $classFrom->getFile() : null;
        $dataFrom = [
            'file' => $fileFrom,
            'line' => $methodFrom->getLine(),
            'class' => $classFrom?->getName(),
            'apiType' => 'method',
        ];
        $classTo = $methodTo?->getClass();
        $fileTo = method_exists($classTo ?? '', 'getFile') ? $classTo->getFile() : null;
        $dataTo = [
            'file' => $fileTo,
            'line' => $methodTo?->getLine(),
            'class' => $classTo?->getName(),
            'apiType' => 'method',
        ];

        $isMissing = $this->checkForMissingApi($name, $methodFrom, $methodTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($name, $methodFrom, $methodTo, $dataFrom, $dataTo, $module);

        // Changed whether it's passed by reference (preceeded with `&`) or not
        if ($methodFrom->isByRef() !== $methodTo->isByRef()) {
            $type = $this->getTypeFromReflection($methodFrom);
            $this->breakingChanges[$module]['returnByRef'][$type][$name] = [
                ...$dataTo,
                'isNow' => $methodTo->isByRef(),
            ];
        }

        $this->checkParameters($methodFrom->getParameters(), $methodTo->getParameters(), $module);
    }

    /**
     * @param array<string,ParameterReflection> $parametersFrom
     * @param array<string,ParameterReflection> $parametersTo
     */
    private function checkParameters(array $parametersFrom, array $parametersTo, string $module) {
        // Compare parameters that have the same name in both versions or removed in the new one
        foreach ($parametersFrom as $paramName => $parameter) {
            $this->checkParameter($paramName, $parameter, $parametersTo[$paramName] ?? null, $module);
        }

        // New params are also breaking API changes so we need to be aware of those
        $newParams = array_diff($parametersTo, $parametersFrom);
        $type = null;
        foreach ($newParams as $paramName => $newParam) {
            $type ??= $this->getTypeFromReflection($newParam);
            $this->breakingChanges[$module]['new'][$type][$paramName] = [
                'hint' => $newParam->getHint(),
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
    ) {
        // The getClass() method will check against the method, so we have to check if the method's there or not first.
        $classFrom = $parameterFrom->getMethod() ? $parameterFrom->getClass() : null;
        $fileFrom = $classFrom ? $classFrom->getFile() : $parameterFrom->getFunction()?->getFile();
        $type = $this->getTypeFromReflection($parameterFrom);
        $dataFrom = [
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
            'file' => $fileTo,
            'line' => $parameterTo?->getLine(),
            'function' => $parameterTo?->getFunction()?->getName(),
            'method' => $parameterTo?->getMethod()?->getName(),
            'class' => $classTo?->getName(),
            'apiType' => 'parameter',
        ];

        // Param has been removed (or renamed - we can't easily tell the difference)
        // @TODO old compare code may help with telling if it was just renamed??
        $isMissing = $this->checkForMissingApi($name, $parameterFrom, $parameterTo, $dataFrom, $dataTo, $module);
        if ($isMissing) {
            return;
        }

        $this->checkForSignatureChanges($name, $parameterFrom, $parameterTo, $dataFrom, $dataTo, $module);

        // Changed whether it's variable-length (preceded with `...`) or not
        if ($parameterFrom->getVariadic() !== $parameterTo->getVariadic()) {
            $this->breakingChanges[$module]['variadic'][$type][$name] = [
                ...$dataTo,
                'isNow' => $parameterTo->getVariadic(),
            ];
        }

        // Changed whether it's passed by reference (preceeded with `&`) or not
        if ($parameterFrom->isByRef() !== $parameterTo->isByRef()) {
            $this->breakingChanges[$module]['passByRef'][$type][$name] = [
                ...$dataTo,
                'isNow' => $parameterTo->isByRef(),
            ];
        }

        // Change to the default value
        // @TODO May need to do a more manual comparison here.
        if ($parameterFrom->getDefault() !== $parameterTo->getDefault()) {
            $this->breakingChanges[$module]['default'][$type][$name] = [
                ...$dataTo,
                CodeComparer::FROM => $parameterFrom->getDefault(),
                CodeComparer::TO => $parameterTo->getDefault(),
            ];
        }
    }

    /**
     * Checks to see if API was either removed, or became internal in the new version.
     * Also checks for any actions that need to be taken, e.g. deprecate in the old version.
     *
     * @return bool True if the API is missing in the new version.
     */
    private function checkForMissingApi(
        string $name,
        Reflection $reflectionFrom,
        ?Reflection $reflectionTo,
        array $dataFrom,
        array $dataTo,
        string $module
    ): bool {
        $type = $this->getTypeFromReflection($reflectionFrom);

        // Check if API went missing
        if ($reflectionTo === null || $dataTo['file'] === null) {
            // If the API was already internal, we don't need to do anything.
            if ($reflectionFrom->isInternal()) {
                return true;
            }
            // Mark whether we still need to deprecate it, and note the breaking change.
            // Note params can't be marked deprecated.
            if ($type !== 'param' && !$reflectionFrom->isDeprecated()) {
                $this->actionsToTake[$module][CodeComparer::ACTION_DEPRECATE][$type][$name] = $dataFrom;
            }
            // Note the breaking change.
            $this->breakingChanges[$module]['removed'][$type][$name] = [
                ...$dataFrom,
                'message' => $this->getDeprecationMessage($name, $reflectionFrom, $dataFrom, $module),
            ];
            return true;
        }

        // API didn't used to be @internal, but it is now (removes it from our public API surface)
        if ($reflectionFrom->isInternal() && !$reflectionTo->isInternal()) {
            if ($type !== 'param' && !$reflectionFrom->isDeprecated()) {
                $this->actionsToTake[$module][CodeComparer::ACTION_DEPRECATE][$type][$name] = $dataFrom;
            }
            $this->breakingChanges[$module]['internal'][$type][$name] = [
                ...$dataFrom,
                'message' => $this->getDeprecationMessage($name, $reflectionFrom, $dataFrom, $module),
            ];
            // Internal API is effectively removed.
            return true;
        }

        // API was and still is deprecated but hasn't been removed
        // Note we don't care about API that used to be deprecated but no longer is,
        // or which is deprecated in the new version but not the old.
        if ($type !== 'param' && $reflectionFrom->isDeprecated() && $reflectionTo->isDeprecated()) {
            $this->actionsToTake[$module][CodeComparer::ACTION_REMOVE][$type][$name] = $dataTo;
        }

        return false;
    }

    /**
     * Checks to see if the signature changed for an API (e.g. change of type or became `final`)
     */
    private function checkForSignatureChanges(
        string $name,
        Reflection $reflectionFrom,
        ?Reflection $reflectionTo,
        array $dataFrom,
        array $dataTo,
        string $module
    ) {
        $type = $this->getTypeFromReflection($reflectionFrom);
        $hintType = null;
        if (in_array($type, ['function', 'method'])) {
            $hintType = 'returnType';
        }
        if (in_array($type, ['property', 'const', 'param'])) {
            $hintType = 'type';
        }

        // Check for type changes
        if ($hintType !== null && $reflectionFrom->getHintAsString() !== $reflectionTo->getHintAsString()) {
            $this->breakingChanges[$module][$hintType][$type][$name] = [
                ...$dataTo,
                // @TODO getHintAsString gives short class names, e.g. `Controller` instead of `SilverStripe\Control\Controller`
                CodeComparer::FROM => $reflectionFrom->getHintAsString(),
                CodeComparer::TO => $reflectionTo->getHintAsString(),
            ];
        }

        // Check for visibility changes
        $visibilityFrom = $this->getVisibilityFromReflection($reflectionFrom);
        $visibilityTo = $this->getVisibilityFromReflection($reflectionTo);
        if ($visibilityFrom !== $visibilityTo) {
            $this->breakingChanges[$module]['visibility'][$type][$name] = [
                ...$dataTo,
                CodeComparer::FROM => $visibilityFrom,
                CodeComparer::TO => $visibilityTo,
            ];
        }

        // Check for becoming final
        if (!$reflectionFrom->isFinal() && $reflectionTo->isFinal()) {
            $this->breakingChanges[$module]['final'][$type][$name] = $dataTo;
        }

        // Check for becoming abstract
        if (!$reflectionFrom->isAbstract() && $reflectionTo->isAbstract()) {
            $this->breakingChanges[$module]['abstract'][$type][$name] = $dataTo;
        }
    }

    private function getDeprecationMessage(string $name, Reflection $reflection, array $data, string $module): string
    {
        $type = $this->getTypeFromReflection($reflection);
        $messagesArray = $reflection->getDeprecated();

        // If there's no message, we already have an action to deprecate it (or it's a param which doesn't get a message)
        if ($type === 'param' || empty($messagesArray)) {
            return '';
        }

        // There should only be one deprecation message.
        if (count($messagesArray) > 1) {
            $this->actionsToTake[$module][CodeComparer::ACTION_FIX_DEPRECATION][$type][$name] = $data;
            return '';
        }

        $messageAsArray = $messagesArray[0];
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $messageAsArray[0])) {
            unset($messageAsArray[0]);
        } else {
            // If the first item in the array isn't a version number, the deprecation is malformed
            // We'll assume the message is present without a version number, though.
            $this->actionsToTake[$module][CodeComparer::ACTION_FIX_DEPRECATION][$type][$name] = $data;
        }

        return implode(' ', $messageAsArray);
    }

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

    private function getTypeFromReflection(Reflection $reflection): string
    {
        $reflectionClass = get_class($reflection);
        return match ($reflectionClass) {
            ClassReflection::class => 'class',
            ParameterReflection::class => 'param',
            MethodReflection::class => 'method',
            PropertyReflection::class => ($reflection->isPrivate() && $reflection->isStatic()) ? 'config' : 'property',
            ConstantReflection::class => 'const',
            FunctionReflection::class => 'function',
            default => throw new InvalidArgumentException("Unexpected reflection type: $reflectionClass"),
        };
    }

    /**
     * Gets the packagist name for the module the file lives in.
     * @throws LogicException if the module name can't be found.
     */
    private function getModuleForFile(string $filePath): string
    {
        $regex = '#' . CloneCommand::DIR_CLONE. '/(?:' . CodeComparer::FROM . '|' . CodeComparer::TO . ')/vendor/([^/]+/[^/]+)/#';
        preg_match($regex, $filePath, $matches);
        $module = $matches[1];
        if (!$module) {
            throw new LogicException("There is no module name identifiable in the path '$filePath'");
        }
        return $module;
    }
}
