<?php

namespace Silverstripe\DeprecationChangelogGenerator\PhpParser;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;

/**
 * Checks nodes in the "to" version of the recipe for any deleted or changed API.
 */
class DeprecationNodeVisitor // @TODO rename, and make one for visiting the "from" version.
{
    /**
     * The AST of the same file but from the "from" version of the recipe.
     * If empty, the file wasn't available and this is likely a new class.
     * @var Node[]
     */
    private array $astFrom;

    /**
     * @param Node[] $astFrom
     */
    public function __construct(array $astFrom)
    {
        $this->astFrom = $astFrom;
    }

    public function visitNode(Node $node)
    {
        /**
         * 1. For anything missing, check if the superclass has it (NOTE this will have to be a separate future step??)
         *   - If it had it in the past OR has it now, don't note it for the subclass.
         *   - If it didn't have it in both places, DO note it for the subclass.
         *
         * 1. Check for anything removed but not deprecated
         * 1. Check for anything deprecated but not removed
         */


         /*
            'classname' => $class->namespacedName->name,
            'name' => $class->extends->name,
         */
    }
    // @TODO double-check the class type for this
    private function checkGlobalFunction(Function_ $node)
    {
        // Check for missing global functions
        // Check for global function signature changes
    }

    // @TODO check the class type for this
    private function checkGlobalConst(Node $node)
    {
        // Check for missing global consts
        // Check for changes in const signatures
    }

    private function checkClassLike(ClassLike $node)
    {
        // @TODO Check for missing classes/traits/interfaces/enums
        // @TODO Check for changes in type (class, interface, enum, or trait) - e.g. class may be a trait now but same name.
        // Check for changes in class signatures (abstract, final, etc)
    }

    private function checkProperty(Property $node)
    {
        // Check for missing properties (public, protected, or private static)
        // Check for changes in property signatures
    }

    private function checkMethod(ClassMethod $node)
    {
        // Check for missing methods (public, protected)
        // Check for changes in method signatures
    }

    // @TODO check the class type for this
    private function checkConst(Node $node)
    {
        // Check for missing consts (public, protected, or not-specified)
        // Check for changes in const signatures
    }
}
