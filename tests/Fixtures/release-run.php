<?php

/*
 * Runs `app:version` the way a release runs it: from the release's own copy
 * of this package's code, in the release's own checkout. The Git commands it
 * starts really run and are written to a log, one per line.
 *
 *   php tests/Fixtures/release-run.php <package copy> <checkout> <command log> <options as JSON>
 *
 * <package copy> holds src/ and config/ (as vendor/medalink/laravel-app-version
 * does); the dev dependencies still come from this checkout's vendor/. Exit
 * status 0 only when the command succeeded and the log was written.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersionServiceProvider;
use Medalink\AppVersion\Console\GenerateVersionCommand;
use Orchestra\Testbench\Foundation\Application;
use Symfony\Component\Process\Process as SymfonyProcess;

[, $package, $checkout, $log, $options] = array_pad($argv, 5, null);

if ($package === null || $checkout === null || $log === null || $options === null) {
    fwrite(STDERR, "Usage: php release-run.php <package copy> <checkout> <command log> <options as JSON>\n");
    exit(2);
}

$loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
// Every package class loads from the release's copy.
$loader->setPsr4('Medalink\\AppVersion\\', [$package.DIRECTORY_SEPARATOR.'src']);

$loaded = (string) (new ReflectionClass(GenerateVersionCommand::class))->getFileName();

if (! str_starts_with(str_replace('\\', '/', $loaded), str_replace('\\', '/', $package))) {
    fwrite(STDERR, "The package loaded from {$loaded}, not from {$package}\n");
    exit(2);
}

try {
    $app = Application::create(options: ['extra' => ['providers' => [AppVersionServiceProvider::class], 'dont-discover' => ['*']]]);
    $app['config']->set('app.name', 'Sample');
    $app['config']->set('app-version.repository_path', $checkout);
    $app['config']->set('app-version.version_file', $checkout.DIRECTORY_SEPARATOR.'VERSION');
    $app['config']->set('app-version.json_path', $checkout.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'version.json');
    $app['config']->set('app-version.flat', false);
    $app['config']->set('app-version.flat_path', $checkout.DIRECTORY_SEPARATOR.'version-info.json');
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    $commands = [];
    Process::fake(function (PendingProcess $pending) use (&$commands) {
        $command = $pending->command;
        $process = is_array($command)
            ? new SymfonyProcess($command, $pending->path)
            : SymfonyProcess::fromShellCommandline($command, $pending->path);

        if ($pending->input !== null) {
            $process->setInput($pending->input);
        }

        $process->setTimeout(120)->run();
        $commands[] = is_array($command) ? implode(' ', $command) : $command;

        return Process::result($process->getOutput(), $process->getErrorOutput(), $process->getExitCode());
    });

    $status = $kernel->call('app:version', ['--no-interaction' => true, ...json_decode($options, true, flags: JSON_THROW_ON_ERROR)]);
} catch (Throwable $e) {
    fwrite(STDERR, (string) $e);
    exit(2);
}

if ($status !== 0) {
    fwrite(STDERR, $kernel->output());
    exit($status);
}

exit(file_put_contents($log, implode("\n", $commands)."\n") !== false ? 0 : 2);
