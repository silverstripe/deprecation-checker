<?php

namespace Silverstripe\DeprecationChangelogGenerator\Command;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Packagist\Api\Client as PackagistClient;
use Packagist\Api\PackageNotFoundException;
use Packagist\Api\Result\Package;
use RuntimeException;
use Silverstripe\DeprecationChangelogGenerator\Compare\BreakingChangesComparer;
use SilverStripe\SupportedModules\MetaData;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsCommand('clone', 'Clone the data needed to generate the changelog chunk. Uses the host\'s <info>composer</info> binary.')]
class CloneCommand extends BaseCommand
{
    public const string DIR_CLONE = 'cloned';

    public const string META_FILE = 'changelog-gen-metadata.json';

    private array $recipeShortcuts = [
        'installer' => 'silverstripe/installer',
        'sink' => 'silverstripe/recipe-kitchen-sink',
        'core' => 'silverstripe/recipe-core',
        'cms' => 'silverstripe/recipe-cms',
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setIO($input, $output);

        // Make sure the constraints and recipe are valid and exist
        $recipe = $this->input->getArgument('recipe');
        if (isset($this->recipeShortcuts[$recipe])) {
            $recipe = $this->recipeShortcuts[$recipe];
        }
        $recipeDetails = $this->getRecipeDetails($recipe);
        $this->validateRecipe($recipeDetails);
        $this->validateConstraints($recipeDetails);

        // Get the output dir and convert it an absolute path
        $outputDir = $this->input->getOption('dir');
        $question = 'This command will output content into your current working directory. Continue?';
        $outputDir = Path::canonicalize($outputDir);
        if (!Path::isAbsolute($outputDir)) {
            $outputDir = Path::makeAbsolute($outputDir, getcwd());
        }
        if (getcwd() === $outputDir && !$this->output->confirm($question)) {
            $this->output->writeln('Cancelling. Set a directory using the <info>--dir</info> flag.');
            return BaseCommand::FAILURE;
        }

        $this->clone($recipe, $outputDir);

        $this->output->success("Successfully cloned '$recipe' into '$outputDir'");
        return BaseCommand::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument(
            'recipe',
            InputArgument::REQUIRED,
            'The recipe to clone. Can be any commercially supported Silverstripe CMS recipe in packagist.',
            null,
            array_keys($this->recipeShortcuts)
        );
        $this->addArgument(
            'fromConstraint',
            InputArgument::REQUIRED,
            'A packagist constraint for checking out the recipe when checking the "before" state.'
        );
        $this->addArgument(
            'toConstraint',
            InputArgument::REQUIRED,
            'A packagist constraint for checking out the recipe when checking the "after" state.'
        );

        $this->addOption(
            'dir',
            'd',
            InputOption::VALUE_REQUIRED,
            'Directory to output the cloned content into.',
            './'
        );

        $shortcuts = [];
        foreach ($this->recipeShortcuts as $shortcut => $recipe) {
            $shortcuts[] = "- <fg=blue>$shortcut</> ($recipe)";
        }
        $this->setHelp(
            'The following shortcuts can be used for the <info>recipe</info> argument:'
            . PHP_EOL . implode(PHP_EOL, $shortcuts)
        );
    }

    private function clone(string $recipe, string $outputDir): void
    {
        $fromConstraint = $this->input->getArgument('fromConstraint');
        $fromDir = Path::join($outputDir, CloneCommand::DIR_CLONE, BreakingChangesComparer::FROM);
        $this->cloneForConstraint($recipe, $fromConstraint, $fromDir);

        $toConstraint = $this->input->getArgument('toConstraint');
        $toDir = Path::join($outputDir, CloneCommand::DIR_CLONE, BreakingChangesComparer::TO);
        $this->cloneForConstraint($recipe, $toConstraint, $toDir);
    }

    private function cloneForConstraint(string $recipe, string $constraint, string $dir): void
    {
        $this->output->writeln("Preparing to clone '$recipe' with constriant '$constraint' into '$dir'");

        $filesystem = new Filesystem();
        if ($filesystem->exists($dir)) {
            $deleteDir = $this->output->confirm(
                "The directory for constraint <comment>$constraint</comment> already exists."
                . ' This is likely from running this command previously. Should I delete'
                . ' it and re-fetch the recipe for that constraint?'
            );
            if ($deleteDir) {
                $this->output->writeln("Deleting directory '$dir'");
                $filesystem->remove($dir);
            } else {
                $this->output->writeln("Directory '$dir' already exists. Skipping...");
                return;
            }
        }

        $this->output->writeln("Creating directory '$dir'");
        $filesystem->mkdir($dir);

        $process = $this->runCliCommand([
            'composer',
            'create-project',
            "$recipe:$constraint",
            $dir,
            '--no-interaction',
            '--prefer-source',
            '--keep-vcs',
            '--ignore-platform-reqs',
        ]);
        if (!$process->isSuccessful()) {
            throw new RuntimeException("Failed to clone '$recipe:$constraint' into $dir: {$process->getErrorOutput()}");
        }

        $metaData = [
            'recipe' => $recipe,
            'constraint' => $constraint,
            'path' => $dir,
        ];
        $filesystem->dumpFile(Path::join($dir, CloneCommand::META_FILE), json_encode($metaData));

        $this->output->writeln("Cloned '$recipe' with constriant '$constraint' successfully");
    }

    private function getRecipeDetails(string $recipe): Package
    {
        // Validate recipe exists
        $recipeDetails = null;
        try {
            $packagist = new PackagistClient();
            $recipeDetails = $packagist->get($recipe);
        } catch (PackageNotFoundException) {
            // no-op, it'll be thrown in our exception below.
            // The original exception message isn't clear enough and isn't consistent with the message we give if
            // the recipe isn't in the array we get back.
        }
        if (is_array($recipeDetails)) {
            $recipeDetails = $recipeDetails[$recipe] ?? null;
        }
        if ($recipeDetails === null) {
            throw new InvalidOptionException("The recipe '$recipe' doesn't exist in packagist");
        }

        return $recipeDetails;
    }

    private function validateRecipe(Package $recipeDetails): void
    {
        if ($recipeDetails->getType() !== 'silverstripe-recipe') {
            throw new InvalidOptionException(
                "The recipe '{$recipeDetails->getName()}' is not a Silverstripe CMS recipe"
            );
        }
        if (empty(MetaData::getMetaDataByPackagistName($recipeDetails->getName()))) {
            throw new InvalidOptionException(
                "The recipe '{$recipeDetails->getName()}' is not commercially supported"
            );
        }
    }

    private function validateConstraints(Package $recipeDetails): void
    {
        $fromConstraint = $this->input->getArgument('fromConstraint');
        $toConstraint = $this->input->getArgument('toConstraint');

        if (!Comparator::lessThan($fromConstraint, $toConstraint)) {
            throw new InvalidOptionException(
                "The constraint '$fromConstraint' is not less than the constraint '$toConstraint'"
            );
        }

        $this->checkRecipeMatchesConstraint($recipeDetails, $fromConstraint);
        $this->checkRecipeMatchesConstraint($recipeDetails, $toConstraint);
    }

    private function checkRecipeMatchesConstraint(Package $recipeDetails, string $constraint): void
    {
        // Validate recipe has a version matching the constraint
        $versionCandidates = Semver::satisfiedBy(array_keys($recipeDetails->getVersions()), $constraint);
        if (empty($versionCandidates)) {
            throw new InvalidOptionException(
                "The recipe '{$recipeDetails->getName()}' has no versions compatible with the constraint '$constraint'"
            );
        }
    }
}
