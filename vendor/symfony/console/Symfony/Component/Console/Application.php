<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console;

use Symfony\Component\Console\Descriptor\TextDescriptor;
use Symfony\Component\Console\Descriptor\XmlDescriptor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\ProgressHelper;
use Symfony\Component\Console\Helper\TableHelper;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * An Application is the container for a collection of commands.
 *
 * It is the main entry point of a Console application.
 *
 * This class is optimized for a standard CLI environment.
 *
 * Usage:
 *
 *     $app = new Application('myapp', '1.0 (stable)');
 *     $app->add(new SimpleCommand());
 *     $app->run();
 *
 * @author Fabien Potencier <fabien@symfony.com>
 *
 * @api
 */
class Application
{
    private $commands;
    private $wantHelps = false;
    private $runningCommand;
    private $name;
    private $version;
    private $catchExceptions;
    private $autoExit;
    private $definition;
    private $helperSet;
    private $dispatcher;

    /**
     * Constructor.
     *
     * @param string $name    The name of the application
     * @param string $version The version of the application
     *
     * @api
     */
    public function __construct($name = 'UNKNOWN', $version = 'UNKNOWN')
    {
        $this->name = $name;
        $this->version = $version;
        $this->catchExceptions = true;
        $this->autoExit = true;
        $this->commands = array();
        $this->helperSet = $this->getDefaultHelperSet();
        $this->definition = $this->getDefaultInputDefinition();

        foreach ($this->getDefaultCommands() as $command) {
            $this->add($command);
        }
    }

    public function setDispatcher(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Runs the current application.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return integer 0 if everything went fine, or an error code
     *
     * @throws \Exception When doRun returns Exception
     *
     * @api
     */
    public function run(InputInterface $input = null, OutputInterface $output = null)
    {
        if (null === $input) {
            $input = new ArgvInput();
        }

        if (null === $output) {
            $output = new ConsoleOutput();
        }

        $this->configureIO($input, $output);

        try {
            $exitCode = $this->doRun($input, $output);
        } catch (\Exception $e) {
            if (!$this->catchExceptions) {
                throw $e;
            }

            if ($output instanceof ConsoleOutputInterface) {
                $this->renderException($e, $output->getErrorOutput());
            } else {
                $this->renderException($e, $output);
            }

            $exitCode = $e->getCode();
            if (is_numeric($exitCode)) {
                $exitCode = (int) $exitCode;
                if (0 === $exitCode) {
                    $exitCode = 1;
                }
            } else {
                $exitCode = 1;
            }
        }

        if ($this->autoExit) {
            if ($exitCode > 255) {
                $exitCode = 255;
            }
            // @codeCoverageIgnoreStart
            exit($exitCode);
            // @codeCoverageIgnoreEnd
        }

        return $exitCode;
    }

    /**
     * Runs the current application.
     *
     * @param InputInterface  $input  An Input instance
     * @param OutputInterface $output An Output instance
     *
     * @return integer 0 if everything went fine, or an error code
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        if (true === $input->hasParameterOption(array('--version', '-V'))) {
            $output->writeln($this->getLongVersion());

            return 0;
        }

        $name = $this->getCommandName($input);
        if (true === $input->hasParameterOption(array('--help', '-h'))) {
            if (!$name) {
                $name = 'help';
                $input = new ArrayInput(array('command' => 'help'));
            } else {
                $this->wantHelps = true;
            }
        }

        if (!$name) {
            $name = 'list';
            $input = new ArrayInput(array('command' => 'list'));
        }

        // the command name MUST be the first element of the input
        $command = $this->find($name);

        $this->runningCommand = $command;
        $exitCode = $this->doRunCommand($command, $input, $output);
        $this->runningCommand = null;

        return $exitCode;
    }

    /**
     * Set a helper set to be used with the command.
     *
     * @param HelperSet $helperSet The helper set
     *
     * @api
     */
    public function setHelperSet(HelperSet $helperSet)
    {
        $this->helperSet = $helperSet;
    }

    /**
     * Get the helper set associated with the command.
     *
     * @return HelperSet The HelperSet instance associated with this command
     *
     * @api
     */
    public function getHelperSet()
    {
        return $this->helperSet;
    }

    /**
     * Set an input definition set to be used with this application
     *
     * @param InputDefinition $definition The input definition
     *
     * @api
     */
    public function setDefinition(InputDefinition $definition)
    {
        $this->definition = $definition;
    }

    /**
     * Gets the InputDefinition related to this Application.
     *
     * @return InputDefinition The InputDefinition instance
     */
    public function getDefinition()
    {
        return $this->definition;
    }

    /**
     * Gets the help message.
     *
     * @return string A help message.
     */
    public function getHelp()
    {
        $messages = array(
            $this->getLongVersion(),
            '',
            '<comment>Usage:</comment>',
            '  [options] command [arguments]',
            '',
            '<comment>Options:</comment>',
        );

        foreach ($this->getDefinition()->getOptions() as $option) {
            $messages[] = sprintf('  %-29s %s %s',
                '<info>--'.$option->getName().'</info>',
                $option->getShortcut() ? '<info>-'.$option->getShortcut().'</info>' : '  ',
                $option->getDescription()
            );
        }

        return implode(PHP_EOL, $messages);
    }

    /**
     * Sets whether to catch exceptions or not during commands execution.
     *
     * @param bool    $boolean Whether to catch exceptions or not during commands execution
     *
     * @api
     */
    public function setCatchExceptions($boolean)
    {
        $this->catchExceptions = (bool) $boolean;
    }

    /**
     * Sets whether to automatically exit after a command execution or not.
     *
     * @param bool    $boolean Whether to automatically exit after a command execution or not
     *
     * @api
     */
    public function setAutoExit($boolean)
    {
        $this->autoExit = (bool) $boolean;
    }

    /**
     * Gets the name of the application.
     *
     * @return string The application name
     *
     * @api
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Sets the application name.
     *
     * @param string $name The application name
     *
     * @api
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Gets the application version.
     *
     * @return string The application version
     *
     * @api
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Sets the application version.
     *
     * @param string $version The application version
     *
     * @api
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * Returns the long version of the application.
     *
     * @return string The long application version
     *
     * @api
     */
    public function getLongVersion()
    {
        if ('UNKNOWN' !== $this->getName() && 'UNKNOWN' !== $this->getVersion()) {
            return sprintf('<info>%s</info> version <comment>%s</comment>', $this->getName(), $this->getVersion());
        }

        return '<info>Console Tool</info>';
    }

    /**
     * Registers a new command.
     *
     * @param string $name The command name
     *
     * @return Command The newly created command
     *
     * @api
     */
    public function register($name)
    {
        return $this->add(new Command($name));
    }

    /**
     * Adds an array of command objects.
     *
     * @param Command[] $commands An array of commands
     *
     * @api
     */
    public function addCommands(array $commands)
    {
        foreach ($commands as $command) {
            $this->add($command);
        }
    }

    /**
     * Adds a command object.
     *
     * If a command with the same name already exists, it will be overridden.
     *
     * @param Command $command A Command object
     *
     * @return Command The registered command
     *
     * @api
     */
    public function add(Command $command)
    {
        $command->setApplication($this);

        if (!$command->isEnabled()) {
            $command->setApplication(null);

            return;
        }

        $this->commands[$command->getName()] = $command;

        foreach ($command->getAliases() as $alias) {
            $this->commands[$alias] = $command;
        }

        return $command;
    }

    /**
     * Returns a registered command by name or alias.
     *
     * @param string $name The command name or alias
     *
     * @return Command A Command object
     *
     * @throws \InvalidArgumentException When command name given does not exist
     *
     * @api
     */
    public function get($name)
    {
        if (!isset($this->commands[$name])) {
            throw new \InvalidArgumentException(sprintf('The command "%s" does not exist.', $name));
        }

        $command = $this->commands[$name];

        if ($this->wantHelps) {
            $this->wantHelps = false;

            $helpCommand = $this->get('help');
            $helpCommand->setCommand($command);

            return $helpCommand;
        }

        return $command;
    }

    /**
     * Returns true if the command exists, false otherwise.
     *
     * @param string $name The command name or alias
     *
     * @return Boolean true if the command exists, false otherwise
     *
     * @api
     */
    public function has($name)
    {
        return isset($this->commands[$name]);
    }

    /**
     * Returns an array of all unique namespaces used by currently registered commands.
     *
     * It does not returns the global namespace which always exists.
     *
     * @return array An array of namespaces
     */
    public function getNamespaces()
    {
        $namespaces = array();
        foreach ($this->commands as $command) {
            $namespaces[] = $this->extractNamespace($command->getName());

            foreach ($command->getAliases() as $alias) {
                $namespaces[] = $this->extractNamespace($alias);
            }
        }

        return array_values(array_unique(array_filter($namespaces)));
    }

    /**
     * Finds a registered namespace by a name or an abbreviation.
     *
     * @param string $namespace A namespace or abbreviation to search for
     *
     * @return string A registered namespace
     *
     * @throws \InvalidArgumentException When namespace is incorrect or ambiguous
     */
    public function findNamespace($namespace)
    {
        $allNamespaces = $this->getNamespaces();
        $found = '';
        foreach (explode(':', $namespace) as $i => $part) {
            // select sub-namespaces matching the current namespace we found
            $namespaces = array();
            foreach ($allNamespaces as $n) {
                if ('' === $found || 0 === strpos($n, $found)) {
                    $namespaces[$n] = explode(':', $n);
                }
            }

            $abbrevs = static::getAbbreviations(array_unique(array_values(array_filter(array_map(function ($p) use ($i) { return isset($p[$i]) ? $p[$i] : ''; }, $namespaces)))));

            if (!isset($abbrevs[$part])) {
                $message = sprintf('There are no commands defined in the "%s" namespace.', $namespace);

                if (1 <= $i) {
                    $part = $found.':'.$part;
                }

                if ($alternatives = $this->findAlternativeNamespace($part, $abbrevs)) {
                    if (1 == count($alternatives)) {
                        $message .= "\n\nDid you mean this?\n    ";
                    } else {
                        $message .= "\n\nDid you mean one of these?\n    ";
                    }

                    $message .= implode("\n    ", $alternatives);
                }

                throw new \InvalidArgumentException($message);
            }

            // there are multiple matches, but $part is an exact match of one of them so we select it
            if (in_array($part, $abbrevs[$part])) {
                $abbrevs[$part] = array($part);
            }

            if (count($abbrevs[$part]) > 1) {
                throw new \InvalidArgumentException(sprintf('The namespace "%s" is ambiguous (%s).', $namespace, $this->getAbbreviationSuggestions($abbrevs[$part])));
            }

            $found .= $found ? ':' . $abbrevs[$part][0] : $abbrevs[$part][0];
        }

        return $found;
    }

    /**
     * Finds a command by name or alias.
     *
     * Contrary to get, this command tries to find the best
     * match if you give it an abbreviation of a name or alias.
     *
     * @param string $name A command name or a command alias
     *
     * @return Command A Command instance
     *
     * @throws \InvalidArgumentException When command name is incorrect or ambiguous
     *
     * @api
     */
    public function find($name)
    {
        // namespace
        $namespace = '';
        $searchName = $name;
        if (false !== $pos = strrpos($name, ':')) {
            $namespace = $this->findNamespace(substr($name, 0, $pos));
            $searchName = $namespace.substr($name, $pos);
        }

        // name
        $commands = array();
        foreach ($this->commands as $command) {
            $extractedNamespace = $this->extractNamespace($command->getName());
            if ($extractedNamespace === $namespace
               || !empty($namespace) && 0 === strpos($extractedNamespace, $namespace)
            ) {
                $commands[] = $command->getName();
            }
        }

        $abbrevs = static::getAbbreviations(array_unique($commands));
        if (isset($abbrevs[$searchName]) && 1 == count($abbrevs[$searchName])) {
            return $this->get($abbrevs[$searchName][0]);
        }

        if (isset($abbrevs[$searchName]) && in_array($searchName, $abbrevs[$searchName])) {
            return $this->get($searchName);
        }

        if (isset($abbrevs[$searchName]) && count($abbrevs[$searchName]) > 1) {
            $suggestions = $this->getAbbreviationSuggestions($abbrevs[$searchName]);

            throw new \InvalidArgumentException(sprintf('Command "%s" is ambiguous (%s).', $name, $suggestions));
        }

        // aliases
        $aliases = array();
        foreach ($this->commands as $command) {
            foreach ($command->getAliases() as $alias) {
                $extractedNamespace = $this->extractNamespace($alias);
                if ($extractedNamespace === $namespace
                   || !empty($namespace) && 0 === strpos($extractedNamespace, $namespace)
                ) {
                    $aliases[] = $alias;
                }
            }
        }

        $aliases = static::getAbbreviations(array_unique($aliases));
        if (!isset($aliases[$searchName])) {
            $message = sprintf('Command "%s" is not defined.', $name);

            if ($alternatives = $this->findAlternativeCommands($searchName, $abbrevs)) {
                if (1 == count($alternatives)) {
                    $message .= "\n\nDid you mean this?\n    ";
                } else {
                    $message .= "\n\nDid you mean one of these?\n    ";
                }
                $message .= implode("\n    ", $alternatives);
            }

            throw new \InvalidArgumentException($message);
        }

        if (count($aliases[$searchName]) > 1) {
            throw new \InvalidArgumentException(sprintf('Command "%s" is ambiguous (%s).', $name, $this->getAbbreviationSuggestions($aliases[$searchName])));
        }

        return $this->get($aliases[$searchName][0]);
    }

    /**
     * Gets the commands (registered in the given namespace if provided).
     *
     * The array keys are the full names and the values the command instances.
     *
     * @param string $namespace A namespace name
     *
     * @return Command[] An array of Command instances
     *
     * @api
     */
    public function all($namespace = null)
    {
        if (null === $namespace) {
            return $this->commands;
        }

        $commands = array();
        foreach ($this->commands as $name => $command) {
            if ($namespace === $this->extractNamespace($name, substr_count($namespace, ':') + 1)) {
                $commands[$name] = $command;
            }
        }

        return $commands;
    }

    /**
     * Returns an array of possible abbreviations given a set of names.
     *
     * @param array $names An array of names
     *
     * @return array An array of abbreviations
     */
    public static function getAbbreviations($names)
    {
        $abbrevs = array();
        foreach ($names as $name) {
            for ($len = strlen($name); $len > 0; --$len) {
                $abbrev = substr($name, 0, $len);
                $abbrevs[$abbrev][] = $name;
            }
        }

        return $abbrevs;
    }

    /**
     * Returns a text representation of the Application.
     *
     * @param string  $namespace An optional namespace name
     * @param bool    $raw       Whether to return raw command list
     *
     * @return string A string representing the Application
     *
     * @deprecated Deprecated since version 2.3, to be removed in 3.0.
     */
    public function asText($namespace = null, $raw = false)
    {
        $descriptor = new TextDescriptor();

        return $descriptor->describe($this, array('namespace' => $namespace, 'raw_text' => $raw));
    }

    /**
     * Returns an XML representation of the Application.
     *
     * @param string  $namespace An optional namespace name
     * @param bool    $asDom     Whether to return a DOM or an XML string
     *
     * @return string|\DOMDocument An XML string representing the Application
     *
     * @deprecated Deprecated since version 2.3, to be removed in 3.0.
     */
    public function asXml($namespace = null, $asDom = false)
    {
        $descriptor = new XmlDescriptor();

        return $descriptor->describe($this, array('namespace' => $namespace, 'as_dom' => $asDom));
    }

    /**
     * Renders a caught exception.
     *
     * @param \Exception       $e      An exception instance
     * @param OutputInterface $output An OutputInterface instance
     */
    public function renderException($e, $output)
    {
        $strlen = function ($string) {
            if (!function_exists('mb_strlen')) {
                return strlen($string);
            }

            if (false === $encoding = mb_detect_encoding($string)) {
                return strlen($string);
            }

            return mb_strlen($string, $encoding);
        };

        do {
            $title = sprintf('  [%s]  ', get_class($e));
            $len = $strlen($title);
            // HHVM only accepts 32 bits integer in str_split, even when PHP_INT_MAX is a 64 bit integer: https://github.com/facebook/hhvm/issues/1327
            $width = $this->getTerminalWidth() ? $this->getTerminalWidth() - 1 : (defined('HHVM_VERSION') ? 1 << 31 : PHP_INT_MAX);
            $formatter = $output->getFormatter();
            $lines = array();
            foreach (preg_split('/\r?\n/', $e->getMessage()) as $line) {
                foreach (str_split($line, $width - 4) as $line) {
                    // pre-format lines to get the right string length
                    $lineLength = $strlen(preg_replace('/\[[^m]*m/', '', $formatter->format($line))) + 4;
                    $lines[] = array($line, $lineLength);

                    $len = max($lineLength, $len);
                }
            }

            $messages = array('', '');
            $messages[] = $emptyLine = $formatter->format(sprintf('<error>%s</error>', str_repeat(' ', $len)));
            $messages[] = $formatter->format(sprintf('<error>%s%s</error>', $title, str_repeat(' ', max(0, $len - $strlen($title)))));
            foreach ($lines as $line) {
                $messages[] = $formatter->format(sprintf('<error>  %s  %s</error>', $line[0], str_repeat(' ', $len - $line[1])));
            }
            $messages[] = $emptyLine;
            $messages[] = '';
            $messages[] = '';

            $output->writeln($messages, OutputInterface::OUTPUT_RAW);

            if (OutputInterface::VERBOSITY_VERBOSE <= $output->getVerbosity()) {
                $output->writeln('<comment>Exception trace:</comment>');

                // exception related properties
                $trace = $e->getTrace();
                array_unshift($trace, array(
                    'function' => '',
                    'file'     => $e->getFile() != null ? $e->getFile() : 'n/a',
                    'line'     => $e->getLine() != null ? $e->getLine() : 'n/a',
                    'args'     => array(),
                ));

                for ($i = 0, $count = count($trace); $i < $count; $i++) {
                    $class = isset($trace[$i]['class']) ? $trace[$i]['class'] : '';
                    $type = isset($trace[$i]['type']) ? $trace[$i]['type'] : '';
                    $function = $trace[$i]['function'];
                    $file = isset($trace[$i]['file']) ? $trace[$i]['file'] : 'n/a';
                    $line = isset($trace[$i]['line']) ? $trace[$i]['line'] : 'n/a';

                    $output->writeln(sprintf(' %s%s%s() at <info>%s:%s</info>', $class, $type, $function, $file, $line));
                }

                $output->writeln("");
                $output->writeln("");
            }
        } while ($e = $e->getPrevious());

        if (null !== $this->runningCommand) {
            $output->writeln(sprintf('<info>%s</info>', sprintf($this->runningCommand->getSynopsis(), $this->getName())));
            $output->writeln("");
            $output->writeln("");
        }
    }

    /**
     * Tries to figure out the terminal width in which this application runs
     *
     * @return int|null
     */
    protected function getTerminalWidth()
    {
        $dimensions = $this->getTerminalDimensions();

        return $dimensions[0];
    }

    /**
     * Tries to figure out the terminal height in which this application runs
     *
     * @return int|null
     */
    protected function getTerminalHeight()
    {
        $dimensions = $this->getTerminalDimensions();

        return $dimensions[1];
    }

    /**
     * Tries to figure out the terminal dimensions based on the current environment
     *
     * @return array Array containing width and height
     */
    public function getTerminalDimensions()
    {
        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            // extract [w, H] from "wxh (WxH)"
            if (preg_match('/^(\d+)x\d+ \(\d+x(\d+)\)$/', trim(getenv('ANSICON')), $matches)) {
                return array((int) $matches[1], (int) $matches[2]);
            }
            // extract [w, h] from "wxh"
            if (preg_match('/^(\d+)x(\d+)$/', $this->getConsoleMode(), $matches)) {
                return array((int) $matches[1], (int) $matches[2]);
            }
        }

        if ($sttyString = $this->getSttyColumns()) {
            // extract [w, h] from "rows h; columns w;"
            if (preg_match('/rows.(\d+);.columns.(\d+);/i', $sttyString, $matches)) {
                return array((int) $matches[2], (int) $matches[1]);
            }
            // extract [w, h] from "; h rows; w columns"
            if (preg_match('/;.(\d+).rows;.(\d+).columns/i', $sttyString, $matches)) {
                return array((int) $matches[2], (int) $matches[1]);
            }
        }

        return array(null, null);
    }

    /**
     * Configures the input and output instances based on the user arguments and options.
     *
     * @param InputInterface  $input  An InputInterface instance
     * @param OutputInterface $output An OutputInterface instance
     */
    protected function configureIO(InputInterface $input, OutputInterface $output)
    {
        if (true === $input->hasParameterOption(array('--ansi'))) {
            $output->setDecorated(true);
        } elseif (true === $input->hasParameterOption(array('--no-ansi'))) {
            $output->setDecorated(false);
        }

        if (true === $input->hasParameterOption(array('--no-interaction', '-n'))) {
            $input->setInteractive(false);
        } elseif (function_exists('posix_isatty') && $this->getHelperSet()->has('dialog')) {
            $inputStream = $this->getHelperSet()->get('dialog')->getInputStream();
            if (!@posix_isatty($inputStream)) {
                $input->setInteractive(false);
            }
        }

        if (true === $input->hasParameterOption(array('--quiet', '-q'))) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        } else {
            if ($input->hasParameterOption('-vvv') || $input->hasParameterOption('--verbose=3') || $input->getParameterOption('--verbose') === 3) {
                $output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
            } elseif ($input->hasParameterOption('-vv') || $input->hasParameterOption('--verbose=2') || $input->getParameterOption('--verbose') === 2) {
                $output->setVerbosity(OutputInterface::VERBOSITY_VERY_VERBOSE);
            } elseif ($input->hasParameterOption('-v') || $input->hasParameterOption('--verbose=1') || $input->hasParameterOption('--verbose') || $input->getParameterOption('--verbose')) {
                $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
            }
        }
    }

    /**
     * Runs the current command.
     *
     * If an event dispatcher has been attached to the application,
     * events are also dispatched during the life-cycle of the command.
     *
     * @param Command         $command A Command instance
     * @param InputInterface  $input   An Input instance
     * @param OutputInterface $output  An Output instance
     *
     * @return integer 0 if everything went fine, or an error code
     */
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output)
    {
        if (null === $this->dispatcher) {
            return $command->run($input, $output);
        }

        $event = new ConsoleCommandEvent($command, $input, $output);
        $this->dispatcher->dispatch(ConsoleEvents::COMMAND, $event);

        try {
            $exitCode = $command->run($input, $output);
        } catch (\Exception $e) {
            $event = new ConsoleTerminateEvent($command, $input, $output, $e->getCode());
            $this->dispatcher->dispatch(ConsoleEvents::TERMINATE, $event);

            $event = new ConsoleExceptionEvent($command, $input, $output, $e, $event->getExitCode());
            $this->dispatcher->dispatch(ConsoleEvents::EXCEPTION, $event);

            throw $event->getException();
        }

        $event = new ConsoleTerminateEvent($command, $input, $output, $exitCode);
        $this->dispatcher->dispatch(ConsoleEvents::TERMINATE, $event);

        return $event->getExitCode();
    }

    /**
     * Gets the name of the command based on input.
     *
     * @param InputInterface $input The input interface
     *
     * @return string The command name
     */
    protected function getCommandName(InputInterface $input)
    {
        return $input->getFirstArgument();
    }

    /**
     * Gets the default input definition.
     *
     * @return InputDefinition An InputDefinition instance
     */
    protected function getDefaultInputDefinition()
    {
        return new InputDefinition(array(
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),

            new InputOption('--help',           '-h', InputOption::VALUE_NONE, 'Display this help message.'),
            new InputOption('--quiet',          '-q', InputOption::VALUE_NONE, 'Do not output any message.'),
            new InputOption('--verbose',        '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug'),
            new InputOption('--version',        '-V', InputOption::VALUE_NONE, 'Display this application version.'),
            new InputOption('--ansi',           '',   InputOption::VALUE_NONE, 'Force ANSI output.'),
            new InputOption('--no-ansi',        '',   InputOption::VALUE_NONE, 'Disable ANSI output.'),
            new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, 'Do not ask any interactive question.'),
        ));
    }

    /**
     * Gets the default commands that should always be available.
     *
     * @return Command[] An array of default Command instances
     */
    protected function getDefaultCommands()
    {
        return array(new HelpCommand(), new ListCommand());
    }

    /**
     * Gets the default helper set with the helpers that should always be available.
     *
     * @return HelperSet A HelperSet instance
     */
    protected function getDefaultHelperSet()
    {
        return new HelperSet(array(
            new FormatterHelper(),
            new DialogHelper(),
            new ProgressHelper(),
            new TableHelper(),
        ));
    }

    /**
     * Runs and parses stty -a if it's available, suppressing any error output
     *
     * @return string
     */
    private function getSttyColumns()
    {
        if (!function_exists('proc_open')) {
            return;
        }

        $descriptorspec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $process = proc_open('stty -a | grep columns', $descriptorspec, $pipes, null, null, array('suppress_errors' => true));
        if (is_resource($process)) {
            $info = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return $info;
        }
    }

    /**
     * Runs and parses mode CON if it's available, suppressing any error output
     *
     * @return string <width>x<height> or null if it could not be parsed
     */
    private function getConsoleMode()
    {
        if (!function_exists('proc_open')) {
            return;
        }

        $descriptorspec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
        $process = proc_open('mode CON', $descriptorspec, $pipes, null, null, array('suppress_errors' => true));
        if (is_resource($process)) {
            $info = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            if (preg_match('/--------+\r?\n.+?(\d+)\r?\n.+?(\d+)\r?\n/', $info, $matches)) {
                return $matches[2].'x'.$matches[1];
            }
        }
    }

    /**
     * Returns abbreviated suggestions in string format.
     *
     * @param array $abbrevs Abbreviated suggestions to convert
     *
     * @return string A formatted string of abbreviated suggestions
     */
    private function getAbbreviationSuggestions($abbrevs)
    {
        return sprintf('%s, %s%s', $abbrevs[0], $abbrevs[1], count($abbrevs) > 2 ? sprintf(' and %d more', count($abbrevs) - 2) : '');
    }

    /**
     * Returns the namespace part of the command name.
     *
     * This method is not part of public API and should not be used directly.
     *
     * @param string $name  The full name of the command
     * @param string $limit The maximum number of parts of the namespace
     *
     * @return string The namespace of the command
     */
    public function extractNamespace($name, $limit = null)
    {
        $parts = explode(':', $name);
        array_pop($parts);

        return implode(':', null === $limit ? $parts : array_slice($parts, 0, $limit));
    }

    /**
     * Finds alternative commands of $name
     *
     * @param string $name    The full name of the command
     * @param array  $abbrevs The abbreviations
     *
     * @return array A sorted array of similar commands
     */
    private function findAlternativeCommands($name, $abbrevs)
    {
        $callback = function ($item) {
            return $item->getName();
        };

        return $this->findAlternatives($name, $this->commands, $abbrevs, $callback);
    }

    /**
     * Finds alternative namespace of $name
     *
     * @param string $name    The full name of the namespace
     * @param array  $abbrevs The abbreviations
     *
     * @return array A sorted array of similar namespace
     */
    private function findAlternativeNamespace($name, $abbrevs)
    {
        return $this->findAlternatives($name, $this->getNamespaces(), $abbrevs);
    }

    /**
     * Finds alternative of $name among $collection,
     * if nothing is found in $collection, try in $abbrevs
     *
     * @param string               $name       The string
     * @param array|\Traversable   $collection The collection
     * @param array                $abbrevs    The abbreviations
     * @param Closure|string|array $callback   The callable to transform collection item before comparison
     *
     * @return array A sorted array of similar string
     */
    private function findAlternatives($name, $collection, $abbrevs, $callback = null)
    {
        $alternatives = array();

        foreach ($collection as $item) {
            if (null !== $callback) {
                $item = call_user_func($callback, $item);
            }

            $lev = levenshtein($name, $item);
            if ($lev <= strlen($name) / 3 || false !== strpos($item, $name)) {
                $alternatives[$item] = $lev;
            }
        }

        if (!$alternatives) {
            foreach ($abbrevs as $key => $values) {
                $lev = levenshtein($name, $key);
                if ($lev <= strlen($name) / 3 || false !== strpos($key, $name)) {
                    foreach ($values as $value) {
                        $alternatives[$value] = $lev;
                    }
                }
            }
        }

        asort($alternatives);

        return array_keys($alternatives);
    }
}
