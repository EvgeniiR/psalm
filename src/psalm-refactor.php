<?php
require_once('command_functions.php');

use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Config;
use Psalm\IssueBuffer;
use Psalm\Progress\DebugProgress;
use Psalm\Progress\DefaultProgress;

// show all errors
error_reporting(-1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('memory_limit', '4096M');

gc_collect_cycles();
gc_disable();

$args = array_slice($argv, 1);

$valid_short_options = ['f:', 'm', 'h', 'r:'];
$valid_long_options = [
    'help', 'debug', 'config:', 'root:',
    'threads:',
];

// get options from command line
$options = getopt(implode('', $valid_short_options), $valid_long_options);

array_map(
    /**
     * @param string $arg
     *
     * @return void
     */
    function ($arg) use ($valid_long_options, $valid_short_options) {
        if (substr($arg, 0, 2) === '--' && $arg !== '--') {
            $arg_name = preg_replace('/=.*$/', '', substr($arg, 2));

            if ($arg_name === 'refactor') {
                // valid option for psalm, ignored by psalter
                return;
            }

            if (!in_array($arg_name, $valid_long_options)
                && !in_array($arg_name . ':', $valid_long_options)
                && !in_array($arg_name . '::', $valid_long_options)
            ) {
                fwrite(
                    STDERR,
                    'Unrecognised argument "--' . $arg_name . '"' . PHP_EOL
                    . 'Type --help to see a list of supported arguments'. PHP_EOL
                );
                exit(1);
            }
        } elseif (substr($arg, 0, 2) === '-' && $arg !== '-' && $arg !== '--') {
            $arg_name = preg_replace('/=.*$/', '', substr($arg, 1));

            if (!in_array($arg_name, $valid_short_options) && !in_array($arg_name . ':', $valid_short_options)) {
                fwrite(
                    STDERR,
                    'Unrecognised argument "-' . $arg_name . '"' . PHP_EOL
                    . 'Type --help to see a list of supported arguments'. PHP_EOL
                );
                exit(1);
            }
        }
    },
    $args
);

if (array_key_exists('help', $options)) {
    $options['h'] = false;
}

if (isset($options['config'])) {
    $options['c'] = $options['config'];
}

if (isset($options['c']) && is_array($options['c'])) {
    die('Too many config files provided' . PHP_EOL);
}

if (array_key_exists('h', $options)) {
    echo <<< HELP
Usage:
    psalm-refactor [options] [symbol1] into [symbol2]

Options:
    -h, --help
        Display this help message

    --debug, --debug-by-line
        Debug information

    -c, --config=psalm.xml
        Path to a psalm.xml configuration file. Run psalm --init to create one.

    -r, --root
        If running Psalm globally you'll need to specify a project root. Defaults to cwd

    --threads=INT
        If greater than one, Psalm will run analysis on multiple threads, speeding things up.

HELP;

    exit;
}

if (isset($options['root'])) {
    $options['r'] = $options['root'];
}

$current_dir = (string)getcwd() . DIRECTORY_SEPARATOR;

if (isset($options['r']) && is_string($options['r'])) {
    $root_path = realpath($options['r']);

    if (!$root_path) {
        die('Could not locate root directory ' . $current_dir . DIRECTORY_SEPARATOR . $options['r'] . PHP_EOL);
    }

    $current_dir = $root_path . DIRECTORY_SEPARATOR;
}

$vendor_dir = getVendorDir($current_dir);

$first_autoloader = requireAutoloaders($current_dir, isset($options['r']), $vendor_dir);

// If XDebug is enabled, restart without it
(new \Composer\XdebugHandler\XdebugHandler('PSALTER'))->check();

$path_to_config = isset($options['c']) && is_string($options['c']) ? realpath($options['c']) : null;

if ($path_to_config === false) {
    /** @psalm-suppress InvalidCast */
    die('Could not resolve path to config ' . (string)$options['c'] . PHP_EOL);
}

// initialise custom config, if passed
if ($path_to_config) {
    $config = Config::loadFromXMLFile($path_to_config, $current_dir);
} else {
    $config = Config::getConfigForPath($current_dir, $current_dir, ProjectAnalyzer::TYPE_CONSOLE);
}

$config->setComposerClassLoader($first_autoloader);

$threads = isset($options['threads']) ? (int)$options['threads'] : 1;

$providers = new Psalm\Internal\Provider\Providers(
    new Psalm\Internal\Provider\FileProvider(),
    new Psalm\Internal\Provider\ParserCacheProvider($config),
    new Psalm\Internal\Provider\FileStorageCacheProvider($config),
    new Psalm\Internal\Provider\ClassLikeStorageCacheProvider($config)
);

$debug = array_key_exists('debug', $options);
$progress = $debug
    ? new DebugProgress()
    : new DefaultProgress();

$project_analyzer = new ProjectAnalyzer(
    $config,
    $providers,
    !array_key_exists('m', $options),
    false,
    ProjectAnalyzer::TYPE_CONSOLE,
    $threads,
    $progress
);

$config->visitComposerAutoloadFiles($project_analyzer);

$args = getArguments();

if (count($args) !== 3 || $args[1] !== 'into') {
    die('Expecting XXX into YYY' . PHP_EOL);
}

$codebase = $project_analyzer->getCodebase();

$codebase->method_migrations = [strtolower($args[0]) => $args[2]];
$codebase->call_transforms = [strtolower($args[0]) . '\((.*\))' => $args[2] . '($1)'];

$project_analyzer->refactorCodeAfterCompletion();

$start_time = microtime(true);

$project_analyzer->check($current_dir);

IssueBuffer::finish($project_analyzer, false, $start_time);