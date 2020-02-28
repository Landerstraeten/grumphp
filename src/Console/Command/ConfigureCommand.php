<?php

declare(strict_types=1);

namespace GrumPHP\Console\Command;

use Exception;
use GrumPHP\Configuration\Resolver\TaskConfigResolver;
use GrumPHP\Util\Filesystem;
use GrumPHP\Util\Paths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

class ConfigureCommand extends Command
{
    protected static $defaultName = 'configure';

    /**
     * @var TaskConfigResolver
     */
    private $taskConfigResolver;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var Paths
     */
    private $paths;

    public function __construct(TaskConfigResolver $taskConfigResolver, Filesystem $filesystem, Paths $paths)
    {
        parent::__construct();

        $this->taskConfigResolver = $taskConfigResolver;
        $this->filesystem = $filesystem;
        $this->paths = $paths;
    }

    protected function configure(): void
    {
        $this->addOption(
            'skip-if-exists',
            null,
            InputOption::VALUE_OPTIONAL,
            'Skip configuration process if the configuration file already exists.',
            false
        );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $skip = $input->getOption('skip-if-exists') !== false;

        $configFileLocation = $this->paths->getConfigFile();

        if (!$input->isInteractive()) {
            $io->block('Skipping configuration due to no interaction.');

            return 0;
        }
        $configFileExists = $this->filesystem->exists($configFileLocation);
        if ($configFileExists && $skip) {
            $io->warning('Configuration process skipped since the configuration file already exists.');

            return 0;
        }

        $currentConfiguration = (array) Yaml::parseFile($configFileLocation);
        $configuration = $this->configureTasks($io, $currentConfiguration);
        try {
            $yaml = Yaml::dump($configuration, 10, 2);
            $this->filesystem->dumpFile($configFileLocation, $yaml);
        } catch (Exception $e) {
            $io->error('The configuration file could not be saved. '.$e->getMessage());

            return 1;
        }

        $io->success('GrumPHP is configured and ready to kick ass!');

        return 0;
    }

    private function configureTasks(SymfonyStyle $io, $configuration): array
    {
        $taskNames = $this->taskConfigResolver->listAvailableTaskNames();
        $question = new ChoiceQuestion('Which task do you want to configure?', $taskNames);

        do {
            $task = $io->askQuestion($question);

            $optionsResolver = $this->taskConfigResolver->fetchByName($task);
            $options = $optionsResolver->resolve();

            if (!$this->confirmOverride($io, $configuration, $task)) {
                continue;
            }

            $configuration['parameters']['tasks'][$task] = $options;
        } while ($io->confirm('Do you want to configure another task?'));

        return $configuration;
    }

    private function confirmOverride(SymfonyStyle $io, $configuration, $task): bool
    {
        if (!isset($configuration['parameters']['tasks'][$task])) {
            return true;
        }

        return $io->confirm(
            'Task '.$task.' already exists. Do you want to overwrite the current configuration with the default one?',
            false
        );
    }
}
