<?php

namespace Devinweb\TestParallel;

use Devinweb\TestParallel\Facades\ParallelTesting;
use Illuminate\Contracts\Console\Kernel;
use ParaTest\Runners\PHPUnit\Options;
use ParaTest\Runners\PHPUnit\WrapperRunner;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

class ParallelRunner
{
    /**
     * The application resolver callback.
     *
     * @var \Closure|null
     */
    protected static $applicationResolver;

    /**
     * The original test runner options.
     *
     * @var \ParaTest\Runners\PHPUnit\Options
     */
    protected $options;

    /**
     * The output instance.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * The original test runner.
     *
     * @var \ParaTest\Runners\PHPUnit\WrapperRunner
     */
    protected $runner;

    /**
     * Creates a new test runner instance.
     *
     * @param \ParaTest\Runners\PHPUnit\Options|array            $options
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return void
     */
    public function __construct($options, OutputInterface $output)
    {
        // Normalize options to ParaTest v1.x Options instance while keeping
        // a plain array for constructing WrapperRunner (which expects array in v1).
        if (is_array($options)) {
            $runnerOpts = $options;
            $this->options = new Options($options);
        } elseif ($options instanceof Options) {
            // Build an array compatible with ParaTest v1.x BaseRunner
            $runnerOpts = [
                'processes'      => isset($options->processes) ? $options->processes : 5,
                'path'           => isset($options->path) ? $options->path : '',
                'phpunit'        => isset($options->phpunit) ? $options->phpunit : null,
                'functional'     => isset($options->functional) ? $options->functional : false,
                'stop-on-failure'=> isset($options->stopOnFailure) ? $options->stopOnFailure : false,
                'runner'         => isset($options->runner) ? $options->runner : 'Runner',
                'no-test-tokens' => isset($options->noTestTokens) ? $options->noTestTokens : false,
                'colors'         => isset($options->colors) ? $options->colors : false,
                'testsuite'      => isset($options->testsuite) ? $options->testsuite : '',
                'max-batch-size' => isset($options->maxBatchSize) ? $options->maxBatchSize : 0,
                'filter'         => isset($options->filter) ? $options->filter : null,
            ];
            $this->options = $options;
        } else {
            throw new \InvalidArgumentException('Invalid options provided to ParallelRunner.');
        }

        // Always keep an output instance
        $this->output = $output instanceof ConsoleOutput ? new ParallelConsoleOutput($output) : $output;

        // ParaTest v1.x WrapperRunner constructor accepts only an array of options
        $this->runner = new WrapperRunner($runnerOpts);
    }

    /**
     * Set the application resolver callback.
     *
     * @param \Closure|null $resolver
     *
     * @return void
     */
    public static function resolveApplicationUsing($resolver)
    {
        static::$applicationResolver = $resolver;
    }

    /**
     * Runs the test suite.
     *
     * @return void
     */
    public function run()
    {
        $first_message = "Runing Phpunit in {$this->getProcesses()} processes";

        $this->output->writeln($first_message);

        // Handle <php> settings from phpunit.xml when available (PHPUnit 9+). For older PHPUnit versions, skip.
        if (class_exists('PHPUnit\\TextUI\\XmlConfiguration\\PhpHandler') && method_exists($this->options, 'configuration')) {
            $handler = new \PHPUnit\TextUI\XmlConfiguration\PhpHandler();
            $configuration = $this->options->configuration();
            if (is_object($configuration) && method_exists($configuration, 'php')) {
                $handler->handle($configuration->php());
            }
        }

        $this->forEachProcess(function () {
            ParallelTesting::callSetUpProcessCallbacks();
        });

        try {
            $this->runner->run();
        } finally {
            $this->forEachProcess(function () {
                ParallelTesting::callTearDownProcessCallbacks();
            });
        }
    }

    /**
     * Returns the highest exit code encountered throughout the course of test execution.
     *
     * @return int
     */
    public function getExitCode()
    {
        return $this->runner->getExitCode();
    }

    /**
     * Apply the given callback for each process.
     *
     * @param callable $callback
     *
     * @return void
     */
    protected function forEachProcess($callback)
    {
        collect(range(1, $this->getProcesses()))->each(function ($token) use ($callback) {
            tap($this->createApplication(), function ($app) use ($callback, $token) {
                ParallelTesting::resolveTokenUsing(function () use ($token) {
                    return $token;
                });

                $callback($app);
            })->flush();
        });
    }

    /**
     * Get the number of processes from Options, compatible with ParaTest v1.x and newer.
     *
     * @return int
     */
    protected function getProcesses()
    {
        // Newer Options may expose a processes() accessor; v1.x exposes a property.
        if (is_object($this->options)) {
            if (method_exists($this->options, 'processes')) {
                return (int) $this->options->processes();
            }
            if (isset($this->options->processes)) {
                return (int) $this->options->processes;
            }
        }

        return 1;
    }

    /**
     * Creates the application.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     */
    protected function createApplication()
    {
        $applicationResolver = static::$applicationResolver ?: function () {
            if (trait_exists(\Tests\CreatesApplication::class)) {
                $applicationCreator = new class() {
                    use \Tests\CreatesApplication;
                };

                return $applicationCreator->createApplication();
            } elseif (file_exists(getcwd().'/bootstrap/app.php')) {
                $app = require getcwd().'/bootstrap/app.php';

                $app->make(Kernel::class)->bootstrap();

                return $app;
            }

            throw new RuntimeException('Parallel Runner unable to resolve application.');
        };

        return call_user_func($applicationResolver);
    }
}
