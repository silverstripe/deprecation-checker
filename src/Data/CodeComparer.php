<?php

namespace Silverstripe\DeprecationChangelogGenerator\Data;

use Doctum\Project;
use Doctum\Reflection\ClassReflection;
use Doctum\Reflection\ConstantReflection;
use Doctum\Reflection\FunctionReflection;
use Doctum\Reflection\MethodReflection;
use Doctum\Reflection\ParameterReflection;
use Doctum\Reflection\PropertyReflection;
use Doctum\Version\Version;
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

    private Project $project;

    private OutputInterface $output;

    private array $breakingChanges = [];

    private array $actionsToTake = [];

    public function __construct(Project $project, OutputInterface $output)
    {
        $this->project = $project;
        $this->output = $output; // @TODO dunno if we need that
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

        // Compare global functions that are introduced in Silverstripe CMS code
        $functionsTo = $toProject->getProjectFunctions();
        foreach ($fromProject->getProjectFunctions() as $index => $functionInfo) {
            // Note the index of this array isn't the function name, unlike with classes and interfaces.
            $this->checkGlobalFunction($functionInfo->getName(), $functionInfo, $functionsTo[$index] ?? null);
            // @TODO Index may not be identical across from and to!!!!! Need a more robust way to get the "TO" version
        }
        // Free up some memory
        unset($functionsTo);

        // Compare classes and traits that are introduced in Silverstripe CMS code
        $classesTo = $toProject->getProjectClasses();
        /** @var ClassReflection $classInfo */
        foreach ($fromProject->getProjectClasses() as $className => $classInfo) { // @TODO does this include enum and trait?
            $this->checkClass($className, $classInfo, $classesTo[$className] ?? null);

        }
        // Free up some memory
        unset($classesTo);

        // Compare interfaces that are introduced in Silverstripe CMS code
        $interfacesTo = $toProject->getProjectInterfaces();
        /** @var ClassReflection $interfaceInfo */
        foreach ($fromProject->getProjectInterfaces() as $interfaceName => $interfaceInfo) {
            $this->checkClass($interfaceName, $interfaceInfo, $interfacesTo[$interfaceName] ?? null);

        }
        // Free up some memory
        unset($interfacesTo);


        /**
         * Still TODO possibly (depending on how Doctum does its shit)
         * 1. For anything missing, check if the superclass has it (NOTE this will have to be a separate future step??)
         *   - If it had it in the past OR has it now, don't note it for the subclass.
         *   - If it didn't have it in both places, DO note it for the subclass.
         * 1. For anything missing, make sure only the super-est class that defines it has a notice in the changelog
         */
    }

    private function checkGlobalFunction(string $name, FunctionReflection $functionFrom, ?FunctionReflection $functionTo)
    {
        $fileFrom = $functionFrom->getFile();
        $fileTo = $functionTo?->getFile();
        $module = $this->getModuleForFile($fileFrom);
        $dataFrom = [
            'file' => $fileFrom,
            'line' => $functionFrom->getLine(),
        ];
        $dataTo = [
            'file' => $fileTo,
            'line' => $functionTo?->getLine(),
        ];

        // Global function was removed
        if ($functionTo?->getFile() === null) {
            // If the function is internal, we don't need to do anything.
            if ($functionFrom->isInternal()) {
                return;
            }
            // Mark whether we still need to deprecate it, and note the breaking change.
            if (!$functionFrom->isDeprecated()) {
                $this->actionsToTake[$module][CodeComparer::ACTION_DEPRECATE]['functions'][$name] = $dataFrom;
            }
            $this->breakingChanges[$module]['removed']['functions'][$name] = $functionFrom->getDeprecated();
            return;
        }

        // Function didn't used to be @internal, but it is now (removes it from our public API surface)
        if ($functionFrom->isInternal() && !$functionTo->isInternal()) {
            if (!$functionFrom->isDeprecated()) {
                $this->actionsToTake[$module][CodeComparer::ACTION_DEPRECATE]['functions'][$name] = $dataFrom;
            }
            $this->breakingChanges[$module]['internal']['functions'][$name] = $functionFrom->getDeprecated();
            // We don't need to mark any other changes to its API since an internal function is effectively a removed one.
            return;
        }

        // Function was and still is deprecated but hasn't been removed
        // Note we don't care about API that used to be deprecated but no longer is,
        // or which is deprecated in the new version but not the old.
        if ($functionFrom->isDeprecated() && $functionTo->isDeprecated()) {
            $this->actionsToTake[$module][CodeComparer::ACTION_REMOVE]['functions'][$name] = $dataTo;
        }

        // Check for signature changes
        // @TODO Validate this is the return type.
        // @TODO check if "hint" includes @return PHPDoc - if so, can I get the strict PHP hint?
        if ($functionFrom->getHintAsString() !== $functionTo->getHintAsString()) {
            $this->breakingChanges[$module]['returnType']['functions'][$name] = [
                CodeComparer::FROM => $functionFrom->getHint(),
                CodeComparer::TO => $functionTo->getHint(),
            ];
        }
        $this->checkParameters($functionFrom->getParameters(), $functionTo->getParameters(), $module);
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
        foreach ($newParams as $paramName => $newParam) {
            $this->breakingChanges[$module]['new']['parameters'][] = [
                'name' => $paramName,
                'hint' => $newParam->getHint(),
                'function' => $newParam->getFunction()?->getName(),
                'method' => $newParam->getMethod()?->getName(),
                'class' => $newParam->getClass()?->getName(),
            ];
        }
    }

    private function checkParameter(
        string $name,
        ParameterReflection $parameterFrom,
        ParameterReflection $parameterTo,
        string $module
    ) {
        $data = [
            'function' => $parameterFrom->getFunction()?->getName(),
            'method' => $parameterFrom->getMethod()?->getName(),
            // The getClass() method will check against the method, so we have to check if the method's there or not first.
            'class' => $parameterFrom->getMethod() ? $parameterFrom->getClass()?->getName() : null,
        ];

        // Param has been removed (or renamed - we can't easily tell the difference)
        // @TODO old compare code may help with telling if it was just renamed??
        if ($parameterTo === null) {
            // If the function is internal, we don't need to do anything.
            if (!$parameterFrom->isInternal()) {
                // Note the breaking change.
                $this->breakingChanges[$module]['removed']['params'][$name] = $data;
            }
            return;
        }

        // Parameter didn't used to be @internal but it is now (removes it from our public API surface)
        if ($parameterFrom->isInternal() && !$parameterTo->isInternal()) {
            $this->breakingChanges[$module]['internal']['params'][$name] = $data;
        }

        // Changed whether it's variable-length (preceded with `...`) or not
        if ($parameterFrom->getVariadic() !== $parameterTo->getVariadic()) {
            $this->breakingChanges[$module]['variadic']['params'][$name] = [
                ...$data,
                'isVariadicNow' => $parameterTo->getVariadic(),
            ];
        }

        // Changed whether it's passed by reference (preceeded with `&`) or not
        if ($parameterFrom->isByRef() !== $parameterTo->isByRef()) {
            $this->breakingChanges[$module]['passByRef']['params'][$name] = [
                ...$data,
                'isPassByRefNow' => $parameterTo->isByRef(),
            ];
        }

        // Change to typehint
        // @TODO validate if this includes @param or not
        if ($parameterFrom->getHintAsString() !== $parameterTo->getHintAsString()) {
            $this->breakingChanges[$module]['type']['params'][$name] = [
                ...$data,
                CodeComparer::FROM => $parameterFrom->getHint(),
                CodeComparer::TO => $parameterTo->getHint(),
            ];
        }

        // Change to the default value
        // @TODO May need to do a more manual comparison here.
        if ($parameterFrom->getDefault() !== $parameterTo->getDefault()) {
            $this->breakingChanges[$module]['default']['params'][$name] = [
                ...$data,
                CodeComparer::FROM => $parameterFrom->getDefault(),
                CodeComparer::TO => $parameterTo->getDefault(),
            ];
        }
    }

    // @TODO lots of overlap with this and functions - and probably others. E.g. same diff for still deprecated.
    //       Refactor to reduce repetition.
    private function checkClass(string $fqcn, ClassReflection $classFrom, ?ClassReflection $classTo)
    {
        $fileFrom = $classFrom->getFile();
        $fileTo = $classTo?->getFile();
        $module = $this->getModuleForFile($fileFrom);
        $dataFrom = [
            'file' => $fileFrom,
            'line' => $classFrom->getLine(),
        ];
        $dataTo = [
            'file' => $fileTo,
            'line' => $classTo?->getLine(),
        ];

        // Class was removed
        if ($classTo?->getFile() === null) {
            // If the class is internal, we don't need to do anything.
            if ($classFrom->isInternal()) {
                return;
            }
            // Mark whether we still need to deprecate it, and note the breaking change.
            if (!$classFrom->isDeprecated()) {
                $this->actionsToTake[$module][CodeComparer::ACTION_DEPRECATE]['classes'][$fqcn] = $dataFrom;
            }
            $this->breakingChanges[$module]['removed']['classes'][$fqcn] = $classFrom->getDeprecated();
            return;
        }

        // Class didn't used to be @internal, but it is now (removes it from our public API surface)
        if ($classFrom->isInternal() && !$classTo->isInternal()) {
            if (!$classFrom->isDeprecated()) {
                $this->actionsToTake[$module][CodeComparer::ACTION_DEPRECATE]['classes'][$fqcn] = $dataFrom;
            }
            $this->breakingChanges[$module]['internal']['classes'][$fqcn] = $classFrom->getDeprecated();
            // We don't need to mark any other changes to its API since an internal function is effectively a removed one.
            return;
        }

        // Class-like has changed what type of class-like it is (e.g. from class to interface)
        if ($classFrom->getCategoryId() !== $classTo->getCategoryId()) {
            $typeFrom = match ($classFrom->getCategoryId()) {
                1 => 'class',
                2 => 'interface',
                3 => 'trait',
            };
            $typeTo = match ($classTo->getCategoryId()) {
                1 => 'class',
                2 => 'interface',
                3 => 'trait',
            };
            $this->breakingChanges[$module]['type']['classes'][$fqcn] = [
                CodeComparer::FROM => $typeFrom,
                CodeComparer::TO => $typeTo,
            ];
        }

        // Class was and still is deprecated but hasn't been removed
        // Note we don't care about API that used to be deprecated but no longer is,
        // or which is deprecated in the new version but not the old.
        if ($classFrom->isDeprecated() && $classTo->isDeprecated()) {
            $this->actionsToTake[$module][CodeComparer::ACTION_REMOVE]['classes'][$fqcn] = $dataTo;
        }

        // Class didn't used to be abstract, but now it is
        if (!$classFrom->isAbstract() && $classTo->isAbstract()) {
            $this->breakingChanges[$module]['abstract']['classes'] = $fqcn;
        }

        // Class didn't used to be final, but it is now
        if ($classFrom->isFinal() && !$classTo->isFinal()) {
            $this->breakingChanges[$module]['final']['classes'] = $fqcn;
        }

        $this->checkProperties($classFrom->getProperties(true), $classTo->getProperties(true));
        $this->checkMethods($classFrom->getMethods(true), $classTo->getMethods(true));
        $this->checkConstants($classFrom->getConstants(true), $classTo->getConstants(true));
    }

    /**
     * Undocumented function
     *
     * @param PropertyReflection[] $propertiesFrom
     * @param PropertyReflection[] $propertiesTo
     */
    private function checkProperties(array $propertiesFrom, array $propertiesTo)
    {

    }

    private function checkProperty(PropertyReflection $propertyFrom, PropertyReflection $propertyTo)
    {
        // Check for anything exists in "from" but removed in "to"
        // Check for signature changes
        // Check for anything removed but not deprecated
        // Check for anything deprecated but not removed
    }

    /**
     * Undocumented function
     *
     * @param MethodReflection[] $methodsFrom
     * @param MethodReflection[] $methodsTo
     */
    private function checkMethods(array $methodsFrom, array $methodsTo)
    {

    }

    private function checkMethod(MethodReflection $methodFrom, MethodReflection $methodTo)
    {
        // Check for anything exists in "from" but removed in "to"
        // Check for signature changes
        // Check for anything removed but not deprecated
        // Check for anything deprecated but not removed
    }

    /**
     * Undocumented function
     *
     * @param ConstantReflection[] $constsFrom
     * @param ConstantReflection[] $constsTo
     */
    private function checkConstants(array $constsFrom, array $constsTo)
    {

    }

    // @TODO check the class type for this
    private function checkConstant(ConstantReflection $constFrom, ConstantReflection $constTo)
    {
        // Check for anything exists in "from" but removed in "to"
        // Check for signature changes
        // Check for anything removed but not deprecated
        // Check for anything deprecated but not removed
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
