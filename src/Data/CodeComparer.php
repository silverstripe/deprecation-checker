<?php

namespace Silverstripe\DeprecationChangelogGenerator\Data;

use Doctum\Project;
use Doctum\Reflection\ClassReflection;
use Doctum\Reflection\ConstantReflection;
use Doctum\Reflection\FunctionReflection;
use Doctum\Reflection\MethodReflection;
use Doctum\Reflection\PropertyReflection;
use Doctum\Version\Version;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compares code between versions to find breaking API changes.
 */
class CodeComparer
{
    public const string FROM = 'from';

    public const string TO = 'to';

    private Project $project;

    private OutputInterface $output;

    public function __construct(Project $project, OutputInterface $output)
    {
        $this->project = $project;
        $this->output = $output; // @TODO dunno if we need that
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

            $this->debugChecked++;
            if ($this->debugChecked >= 2) {
                $this->debugChecked = 0;
                break;
            }
        }
        // Free up some memory
        unset($functionsTo);

        // Compare classes and traits that are introduced in Silverstripe CMS code
        $classesTo = $toProject->getProjectClasses();
        /** @var ClassReflection $classInfo */
        foreach ($fromProject->getProjectClasses() as $className => $classInfo) { // @TODO does this include enum and trait?
            $this->checkClass($className, $classInfo, $classesTo[$className] ?? null);

            $this->debugChecked++;
            if ($this->debugChecked >= 2) {
                $this->debugChecked = 0;
                break;
            }
        }
        // Free up some memory
        unset($classesTo);

        // Compare interfaces that are introduced in Silverstripe CMS code
        $interfacesTo = $toProject->getProjectInterfaces();
        /** @var ClassReflection $interfaceInfo */
        foreach ($fromProject->getProjectInterfaces() as $interfaceName => $interfaceInfo) {
            $this->checkClass($interfaceName, $interfaceInfo, $interfacesTo[$interfaceName] ?? null);

            $this->debugChecked++;
            if ($this->debugChecked >= 2) {
                $this->debugChecked = 0;
                break;
            }
        }
        // Free up some memory
        unset($interfacesTo);


        /**
         * Still TODO possibly
         * 1. For anything missing, check if the superclass has it (NOTE this will have to be a separate future step??)
         *   - If it had it in the past OR has it now, don't note it for the subclass.
         *   - If it didn't have it in both places, DO note it for the subclass.
         */
    }

    // @TODO double-check the class type for this
    private function checkGlobalFunction(string $name, FunctionReflection $functionFrom, ?FunctionReflection $functionTo)
    {
        // Get data about functionFrom
        $fromExists = $functionFrom->getFile() !== null;
        $fromIsDeprecated = $functionFrom->isDeprecated();
        $fromIsAbstract = $functionFrom->isAbstract();
        $fromIsInternal = $functionFrom->isInternal();
        // Get data about functionTo
        $toExists = $functionTo?->getFile() !== null;
        $toIsDeprecated = $functionTo?->isDeprecated();
        $toIsAbstract = $functionTo?->isAbstract();
        $toIsInternal = $functionTo?->isInternal();


        // @DEBUG - DELETE
        $infoBits = [
            $name,
            'fromExists: ' . ($fromExists ? 'yes' : 'no'),
            'fromDeprecated: ' . ($fromIsDeprecated ? 'yes' : 'no'),
            'fromAbstract: ' . ($fromIsAbstract ? 'yes' : 'no'),
            'fromIsInternal: ' . ($fromIsInternal ? 'yes' : 'no'),
            'toExists: ' . ($toExists ? 'yes' : 'no'),
            'toDeprecated: ' . ($toIsDeprecated ? 'yes' : 'no'),
            'toAbstract: ' . ($toIsAbstract ? 'yes' : 'no'),
            'toIsInternal: ' . ($toIsInternal ? 'yes' : 'no'),
        ];
        $this->output->write('<fg=red>');
        $this->output->writeln($infoBits);
        $this->output->write('</>');

        // Check for anything exists in "from" but removed in "to"
        // Check for signature changes
        // Check for anything removed but not deprecated
        // Check for anything deprecated but not removed
    }

    private int $debugChecked = 0;

    private function checkClass(string $fqcn, ClassReflection $classFrom, ?ClassReflection $classTo)
    {
        // Check for anything exists in "from" but removed in "to"
        // Check for signature changes
        // Check for anything removed but not deprecated
        // Check for anything deprecated but not removed

        // Get data about classFrom
        $fromExists = $classFrom->getFile() !== null;
        $fromIsDeprecated = $classFrom->isDeprecated();
        $fromIsAbstract = $classFrom->isAbstract();
        $fromIsTrait = $classFrom->isTrait();
        $fromIsInterface = $classFrom->isInterface();
        $fromIsFinal = $classFrom->isFinal();
        $fromIsInternal = $classFrom->isInternal();
        // Get data about classTo
        $toExists = $classTo?->getFile() !== null;
        $toIsDeprecated = $classTo?->isDeprecated();
        $toIsAbstract = $classTo?->isAbstract();
        $toIsTrait = $classTo?->isTrait();
        $toIsInterface = $classTo?->isInterface();
        $toIsFinal = $classTo?->isFinal();
        $toIsInternal = $classTo?->isInternal();

        // @DEBUG - DELETE
        $infoBits = [
            $fqcn,
            'fromExists: ' . ($fromExists ? 'yes' : 'no'),
            'fromDeprecated: ' . ($fromIsDeprecated ? 'yes' : 'no'),
            'fromAbstract: ' . ($fromIsAbstract ? 'yes' : 'no'),
            'fromIsTrait: ' . ($fromIsTrait ? 'yes' : 'no'),
            'fromIsInterface: ' . ($fromIsInterface ? 'yes' : 'no'),
            'fromIsFinal: ' . ($fromIsFinal ? 'yes' : 'no'),
            'fromIsInternal: ' . ($fromIsInternal ? 'yes' : 'no'),
            'toExists: ' . ($toExists ? 'yes' : 'no'),
            'toDeprecated: ' . ($toIsDeprecated ? 'yes' : 'no'),
            'toAbstract: ' . ($toIsAbstract ? 'yes' : 'no'),
            'toIsTrait: ' . ($toIsTrait ? 'yes' : 'no'),
            'toIsInterface: ' . ($toIsInterface ? 'yes' : 'no'),
            'toIsFinal: ' . ($toIsFinal ? 'yes' : 'no'),
            'toIsInternal: ' . ($toIsInternal ? 'yes' : 'no'),
        ];
        $this->output->write('<fg=red>');
        $this->output->writeln($infoBits);
        $this->output->write('</>');

        // $this->project->switchVersion(new Version(CodeComparer::FROM));
        // $propertiesFrom = $classFrom->getProperties(true);
        // $methodsFrom = $classFrom->getMethods(true);
        // $constsFrom = $classFrom->getConstants(true);
        // $this->project->switchVersion(new Version(CodeComparer::TO));
        // $propertiesTo = $classTo->getProperties(true);
        // $methodsTo = $classTo->getMethods(true);
        // $constsTo = $classTo->getConstants(true);
        // $this->checkProperties($propertiesFrom, $propertiesTo);
        // $this->checkMethods($methodsFrom, $methodsTo);
        // $this->checkConstants($constsFrom, $constsTo);
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

    private function checkProperty(PropertyReflection $node)
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

    private function checkMethod(MethodReflection $node)
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
    private function checkConst(ConstantReflection $node)
    {
        // Check for anything exists in "from" but removed in "to"
        // Check for signature changes
        // Check for anything removed but not deprecated
        // Check for anything deprecated but not removed
    }
}
