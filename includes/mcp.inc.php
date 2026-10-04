<?php
/**
 * Enchilada MCP — Tool Class Loading and Registration
 *
 * Canonical include for an Enchilada-based MCP server app: autoloads
 * unnamespaced tool classes from the app's tools/ directory, and
 * discovers + instantiates + registers them against an McpServer.
 *
 * This is the canonical copy. A consuming app vendors it to its own
 * includes/mcp.inc.php, the same way system/autoload.inc.php is
 * vendored from Enchilada/Framework — never copied peer-to-peer
 * between app projects. Picked up automatically once there by
 * system/bootstrap.inc.php's component loader (any includes/*.inc.php).
 */

/**
 * Lazy autoloader for unnamespaced tool classes (e.g. UserTools,
 * TaskTools) living in the app's tools/ directory. Namespaced classes
 * are left to the framework autoloader.
 */
spl_autoload_register(function ($class) {
    if (str_contains($class, '\\')) {
        return;
    }
    $toolFile = APPLICATION_ROOT . 'tools' . DIRECTORY_SEPARATOR . $class . '.php';
    if (file_exists($toolFile)) {
        require $toolFile;
    }
});

/**
 * Discover every *.php file in the app's tools/ directory, instantiate
 * its eponymous class, and register it against $server.
 *
 * scandir(), not glob(): glob() never traverses the phar:// stream
 * wrapper (a long-standing PHP limitation, unlike file_exists()/
 * is_dir()/scandir(), which do), so under a phar build glob() would
 * silently find zero tool files. scandir() only answers "what class
 * names should exist" (by filename convention) — loading each one is
 * left entirely to the autoloader above via class_exists(), so there
 * is exactly one place that knows how a tool class name maps to a
 * file.
 *
 * @param  \EnchiladaMCP\McpServer $server    Server to register tools on
 * @param  callable|null           $onRegister Optional function(string $className): void,
 *                                             called after each successful registration
 *                                             (e.g. to log it) — kept decoupled from any
 *                                             specific logger implementation
 * @param  mixed                   ...$args   Constructor arguments passed to every tool
 *                                             class (e.g. an InstanceManager)
 * @return string[]                           Class names registered, in scan order
 */
function enchilada_mcp_register_tools(\EnchiladaMCP\McpServer $server, ?callable $onRegister, mixed ...$args): array
{
    $toolDir = APPLICATION_ROOT . 'tools';
    $entries = is_dir($toolDir) ? scandir($toolDir) : [];

    $registered = [];
    foreach ($entries as $entry) {
        if (substr($entry, -4) !== '.php') {
            continue;
        }
        $className = basename($entry, '.php');
        if (!class_exists($className)) {
            continue;
        }
        $server->register(new $className(...$args));
        $registered[] = $className;
        if ($onRegister !== null) {
            $onRegister($className);
        }
    }

    return $registered;
}
