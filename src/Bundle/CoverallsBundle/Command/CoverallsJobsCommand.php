<?php

namespace PhpCoveralls\Bundle\CoverallsBundle\Command;

use GuzzleHttp\Client;
use PhpCoveralls\Bundle\CoverallsBundle\Api\Jobs;
use PhpCoveralls\Bundle\CoverallsBundle\Config\Configuration;
use PhpCoveralls\Bundle\CoverallsBundle\Config\Configurator;
use PhpCoveralls\Bundle\CoverallsBundle\Repository\JobsRepository;
use PhpCoveralls\Component\File\Path;
use PhpCoveralls\Component\Log\ConsoleLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Coveralls Jobs API v1 command.
 *
 * @author Kitamura Satoshi <with.no.parachute@gmail.com>
 */
class CoverallsJobsCommand extends Command
{
    /**
     * Path to project root directory.
     *
     * @var string
     */
    protected $rootDir;

    /**
     * Logger.
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    // accessor

    /**
     * Set root directory.
     *
     * @param string $rootDir path to project root directory
     */
    public function setRootDir($rootDir)
    {
        $this->rootDir = $rootDir;
    }

    // internal method

    /**
     * {@inheritdoc}
     *
     * @see \Symfony\Component\Console\Command\Command::configure()
     */
    protected function configure()
    {
        $this
            ->setName('coveralls:v1:jobs')
            ->setDescription('Coveralls Jobs API v1')
            ->addOption(
                'config',
                '-c',
                InputOption::VALUE_OPTIONAL,
                '.coveralls.yml path',
                '.coveralls.yml'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not send json_file to Jobs API'
            )
            ->addOption(
                'exclude-no-stmt',
                null,
                InputOption::VALUE_NONE,
                'Exclude source files that have no executable statements'
            )
            ->addOption(
                'env',
                '-e',
                InputOption::VALUE_OPTIONAL,
                'Runtime environment name: test, dev, prod',
                'prod'
            )
            ->addOption(
                'coverage_clover',
                '-x',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Coverage clover xml files (allowing multiple values).',
                []
            )
            ->addOption(
                'json_path',
                '-o',
                InputOption::VALUE_REQUIRED,
                'Coveralls output json file',
                []
            )
            ->addOption(
                'entry_point',
                '-o',
                InputOption::VALUE_REQUIRED,
                'Coveralls entrypoint',
                'https://coveralls.io'
            )
            ->addOption(
                'root_dir',
                '-r',
                InputOption::VALUE_OPTIONAL,
                'Root directory of the project.',
                '.'
            )
            ->addOption(
                'insecure',
                '-k',
                InputOption::VALUE_NONE,
                'Skip SSL certificate check.'
            );
    }

    /**
     * {@inheritdoc}
     *
     * @see \Symfony\Component\Console\Command\Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start(__CLASS__);
        $file = new Path();
        if ($input->getOption('root_dir') !== '.') {
            $this->rootDir = $file->toAbsolutePath(
                $input->getOption('root_dir'),
                $this->rootDir
            );
        }

        $config = $this->loadConfiguration($input, $this->rootDir);
        $this->logger = $config->isVerbose() && !$config->isTestEnv() ? new ConsoleLogger($output) : new NullLogger();

        $executionStatus = $this->executeApi($input, $config);

        $event = $stopwatch->stop(__CLASS__);
        $time = number_format($event->getDuration() / 1000, 3);        // sec
        $mem = number_format($event->getMemory() / (1024 * 1024), 2); // MB
        $this->logger->info(sprintf('elapsed time: <info>%s</info> sec memory: <info>%s</info> MB', $time, $mem));

        return $executionStatus ? 0 : 1;
    }

    // for Jobs API

    /**
     * Load configuration.
     *
     * @param InputInterface $input   input arguments
     * @param string         $rootDir path to project root directory
     *
     * @return \PhpCoveralls\Bundle\CoverallsBundle\Config\Configuration
     */
    protected function loadConfiguration(InputInterface $input, $rootDir)
    {
        $coverallsYmlPath = $input->getOption('config');

        $ymlPath = $this->rootDir . DIRECTORY_SEPARATOR . $coverallsYmlPath;
        $configurator = new Configurator();

        return $configurator
            ->load($ymlPath, $rootDir, $input)
            ->setDryRun($input->getOption('dry-run'))
            ->setExcludeNoStatementsUnlessFalse($input->getOption('exclude-no-stmt'))
            ->setVerbose($input->getOption('verbose'))
            ->setEnv($input->getOption('env'));
    }

    /**
     * Execute Jobs API.
     *
     * @param InputInterface $input  input arguments
     * @param Configuration  $config configuration
     *
     * @return bool
     */
    protected function executeApi(InputInterface $input, Configuration $config)
    {
        $params = [];
        if ($input->getOption('insecure')) {
            $params['verify'] = false;
        }

        $client = new Client($params);
        $api = new Jobs($config, $client);
        $repository = new JobsRepository($api, $config);

        $repository->setLogger($this->logger);

        return $repository->persist();
    }
}
