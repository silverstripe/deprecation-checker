<?php

namespace Silverstripe\DeprecationChangelogGenerator\Data;

use Doctum\Version\Version;
use Doctum\Version\VersionCollection;
use Silverstripe\DeprecationChangelogGenerator\Command\CloneCommand;
use Symfony\Component\Filesystem\Path;

/**
 * Provides a per-recipe view of all versions in a multi-repo checkout.
 */
class RecipeVersionCollection extends VersionCollection
{
    private ?Version $version = null;

    private array $supportedModules;

    private string $basePath;

    public function __construct(array $supportedModules, string $basePath)
    {
        parent::__construct([CodeComparer::FROM, CodeComparer::TO]);
        $this->version = $this->versions[0];
        $this->basePath = $basePath;
        foreach ($supportedModules as $moduleData) {
            if ($moduleData['type'] === 'theme') {
                continue;
            }
            if ($moduleData['packagist'] === 'silverstripe/recipe-kitchen-sink') {// @TODO use the name of the recipe we're caring about not sink hardcoded.
                // @TODO This is a bit ugly having to just ignore it entirely... can we do something else instead?
                continue;
            }
            $this->supportedModules[$moduleData['packagist']] = $moduleData;
        }
    }

    /**
     * List of all package names
     */
    public function getPackageNames(): array
    {
        return array_keys($this->supportedModules);
    }

    /**
     * Get path to the given package root
     */
    public function getPackagePath(string $package): string
    {
        return realpath(Path::join($this->basePath, CloneCommand::DIR_CLONE, $this->version, '/vendor/', $package));
    }

    /**
     * Will be one of "from" or "to"
     */
    public function getVersion(): ?Version
    {
        return $this->version;
    }

    /**
     * Switch to a specified version to collect from
     */
    protected function switchVersion(Version $version): void
    {
        $this->version = $version;
    }
}
