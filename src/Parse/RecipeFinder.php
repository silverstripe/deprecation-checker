<?php

namespace SilverStripe\DeprecationChecker\Parse;

use Doctum\Parser\ParseError;
use Iterator;
use ReflectionProperty;
use Symfony\Component\Finder\Finder;

/**
 * Finds PHP files in supported modules as provided by RecipeVersionCollection
 */
class RecipeFinder extends Finder
{
    private RecipeVersionCollection $collection;

    private array $problems = [];

    public function __construct(RecipeVersionCollection $collection)
    {
        parent::__construct();
        $this->collection = $collection;
    }

    public function getIterator(): Iterator
    {
        // Ensure we start looking from scratch, in case the version changed since last iteration.
        $this->resetDirs();

        // Look through all supported modules we're aware of for this version
        $packages = $this->collection->getPackageNames();
        foreach ($packages as $package) {
            $path = $this->collection->getPackagePath($package);
            if (!$path) {
                $this->problems[] = new ParseError("No path for $package in '{$this->collection->getVersion()->getName()}'", null, null);
                continue;
            }
            $this->in($path);
        }

        return parent::getIterator();
    }

    /**
     * @return ParseError[]
     */
    public function getProblems(): array
    {
        return $this->problems;
    }

    /**
     * Reset the dirs property
     */
    private function resetDirs(): void
    {
        // Reset $this->dirs to the list of package paths.
        // This is necessary to ensure we have the dirs for the current version every time we iterate.
        // We don't control where the iteration happens so we can't just instantiate a new RecipeFinder each time.
        $dirsProp = new ReflectionProperty(Finder::class, 'dirs');
        $dirsProp->setAccessible(true);
        $dirsProp->setValue($this, []);
    }
}
