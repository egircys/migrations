<?php

declare(strict_types=1);

namespace Doctrine\Migrations\Tools\Console\Command;

use Doctrine\Migrations\Generator\Exception\NoChangesDetected;
use Doctrine\Migrations\Metadata\AvailableMigrationsList;
use Doctrine\Migrations\Metadata\ExecutedMigrationsSet;
use Doctrine\Migrations\Tools\Console\Exception\InvalidOptionUsage;
use OutOfBoundsException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function addslashes;
use function assert;
use function class_exists;
use function count;
use function filter_var;
use function is_string;
use function key;
use function sprintf;
use const FILTER_VALIDATE_BOOLEAN;

/**
 * The DiffCommand class is responsible for generating a migration by comparing your current database schema to
 * your mapping information.
 */
final class DiffCommand extends DoctrineCommand
{
    /** @var string */
    protected static $defaultName = 'migrations:diff';

    protected function configure() : void
    {
        parent::configure();

        $this
            ->setAliases(['diff'])
            ->setDescription('Generate a migration by comparing your current database to your mapping information.')
            ->setHelp(<<<EOT
The <info>%command.name%</info> command generates a migration by comparing your current database to your mapping information:

    <info>%command.full_name%</info>

EOT
            )
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED,
                'The namespace to use for the migration (must be in the list of configured namespaces)'
            )
            ->addOption(
                'filter-expression',
                null,
                InputOption::VALUE_REQUIRED,
                'Tables which are filtered by Regular Expression.'
            )
            ->addOption(
                'formatted',
                null,
                InputOption::VALUE_NONE,
                'Format the generated SQL.'
            )
            ->addOption(
                'line-length',
                null,
                InputOption::VALUE_REQUIRED,
                'Max line length of unformatted lines.',
                120
            )
            ->addOption(
                'check-database-platform',
                null,
                InputOption::VALUE_OPTIONAL,
                'Check Database Platform to the generated code.',
                false
            )
            ->addOption(
                'allow-empty-diff',
                null,
                InputOption::VALUE_NONE,
                'Do not throw an exception when no changes are detected.'
            );
    }

    /**
     * @throws InvalidOptionUsage
     */
    public function execute(
        InputInterface $input,
        OutputInterface $output
    ) : int {
        $filterExpression = (string) $input->getOption('filter-expression');
        if ($filterExpression === '') {
            $filterExpression = null;
        }

        $formatted       = filter_var($input->getOption('formatted'), FILTER_VALIDATE_BOOLEAN);
        $lineLength      = (int) $input->getOption('line-length');
        $allowEmptyDiff  = $input->getOption('allow-empty-diff');
        $checkDbPlatform = filter_var($input->getOption('check-database-platform'), FILTER_VALIDATE_BOOLEAN);
        $namespace       = $input->getOption('namespace');
        if ($namespace === '') {
            $namespace = null;
        }

        if ($formatted) {
            if (! class_exists('SqlFormatter')) {
                throw InvalidOptionUsage::new(
                    'The "--formatted" option can only be used if the sql formatter is installed. Please run "composer require jdorn/sql-formatter".'
                );
            }
        }

        $configuration = $this->getDependencyFactory()->getConfiguration();

        $dirs = $configuration->getMigrationDirectories();
        if ($namespace === null) {
            $namespace = key($dirs);
        } elseif (! isset($dirs[$namespace])) {
            throw new OutOfBoundsException(sprintf('Path not defined for the namespace %s', $namespace));
        }

        assert(is_string($namespace));

        $statusCalculator              = $this->getDependencyFactory()->getMigrationStatusCalculator();
        $executedUnavailableMigrations = $statusCalculator->getExecutedUnavailableMigrations();
        $newMigrations                 = $statusCalculator->getNewMigrations();

        if ($this->checkNewMigrations($newMigrations, $input, $output) === false) {
            return 3;
        }

        if ($this->checkExecutedUnavailableMigrations($executedUnavailableMigrations, $input, $output) === false) {
            return 3;
        }

        $fqcn = $this->getDependencyFactory()->getClassNameGenerator()->generateClassName($namespace);

        $diffGenerator = $this->getDependencyFactory()->getDiffGenerator();

        try {
            $path = $diffGenerator->generate(
                $fqcn,
                $filterExpression,
                $formatted,
                $lineLength,
                $checkDbPlatform
            );
        } catch (NoChangesDetected $exception) {
            if ($allowEmptyDiff) {
                $output->writeln($exception->getMessage());

                return 0;
            }

            throw $exception;
        }

        $output->writeln([
            sprintf('Generated new migration class to "<info>%s</info>"', $path),
            '',
            sprintf(
                'To run just this migration for testing purposes, you can use <info>migrations:execute --up \'%s\'</info>',
                addslashes($fqcn)
            ),
            '',
            sprintf(
                'To revert the migration you can use <info>migrations:execute --down \'%s\'</info>',
                addslashes($fqcn)
            ),
        ]);

        return 0;
    }

    private function checkNewMigrations(
        AvailableMigrationsList $newMigrations,
        InputInterface $input,
        OutputInterface $output
    ) : bool {
        if (count($newMigrations) !== 0) {
            $output->writeln(sprintf(
                '<error>WARNING! You have %s available migrations to execute.</error>',
                count($newMigrations)
            ));

            $question = 'Are you sure you wish to continue? (y/n)';

            if (! $this->canExecute($question, $input, $output)) {
                $output->writeln('<error>Migration cancelled!</error>');

                return false;
            }
        }

        return true;
    }

    private function checkExecutedUnavailableMigrations(
        ExecutedMigrationsSet $executedUnavailableMigrations,
        InputInterface $input,
        OutputInterface $output
    ) : bool {
        if (count($executedUnavailableMigrations) !== 0) {
            $output->writeln(sprintf(
                '<error>WARNING! You have %s previously executed migrations in the database that are not registered migrations.</error>',
                count($executedUnavailableMigrations)
            ));

            foreach ($executedUnavailableMigrations->getItems() as $executedUnavailableMigration) {
                $output->writeln(sprintf(
                    '    <comment>>></comment> %s (<comment>%s</comment>)',
                    $executedUnavailableMigration->getExecutedAt() !== null
                        ? $executedUnavailableMigration->getExecutedAt()->format('Y-m-d H:i:s')
                        : null,
                    $executedUnavailableMigration->getVersion()
                ));
            }

            $question = 'Are you sure you wish to continue? (y/n)';

            if (! $this->canExecute($question, $input, $output)) {
                $output->writeln('<error>Migration cancelled!</error>');

                return false;
            }
        }

        return true;
    }
}
