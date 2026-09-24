<?php

/*
 * Replays a real repository's history through `app:version` and requires the
 * cached run to write exactly what the uncached run writes.
 *
 * For each of the last N first-parent commits of <ref>, oldest first, the
 * command runs twice at that commit: without the stats cache, and with a
 * cache carried over from the previous commit (a deploy's shape). Both files
 * must be byte-identical. The repository is only read: the replay works in a
 * throwaway clone that borrows its objects (git clone --shared).
 *
 * Run it from a checkout of this package (it needs the dev dependencies):
 *
 *   php scripts/verify-stats-cache.php <repository> [--commits=20] [--ref=HEAD]
 *       [--config=<path to the app's config/app-version.php>] [--app-name=<name>] [--no-flat] [--keep]
 *
 * Exit status 0 when every commit matched, 1 otherwise.
 */

use Illuminate\Contracts\Console\Kernel;
use Medalink\AppVersion\AppVersionServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__).'/vendor/autoload.php';

$arguments = array_slice($argv, 1);
$options = ['commits' => '20', 'ref' => 'HEAD', 'config' => null, 'app-name' => null];
$flags = [];
$repository = null;

foreach ($arguments as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match) === 1 && array_key_exists($match[1], $options)) {
        $options[$match[1]] = $match[2];
    } elseif (in_array($argument, ['--no-flat', '--keep'], true)) {
        $flags[$argument] = true;
    } elseif ($repository === null && ! str_starts_with($argument, '--')) {
        $repository = $argument;
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

if ($repository === null || ! is_dir($repository)) {
    fwrite(STDERR, "Usage: php scripts/verify-stats-cache.php <repository> [--commits=20] [--ref=HEAD] [--config=<app-version.php>] [--app-name=<name>] [--no-flat] [--keep]\n");
    exit(2);
}

$git = static function (string $directory, array $arguments, bool $raw = false): string {
    $process = proc_open(['git', '-C', $directory, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        fwrite(STDERR, 'git '.implode(' ', $arguments).": {$error}");
        exit(2);
    }

    return $raw ? $output : trim($output);
};

$tip = $git($repository, ['rev-parse', '--verify', $options['ref'].'^{commit}']);
$work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'verify-stats-cache-'.bin2hex(random_bytes(4));
$clone = $work.DIRECTORY_SEPARATOR.'repository';
$out = $work.DIRECTORY_SEPARATOR.'out';
mkdir($out, 0777, true);
$git($work, ['clone', '--quiet', '--no-checkout', '--shared', realpath($repository), $clone]);
// The clone names the source's branches origin/*; the replay moves HEAD itself.
$git($clone, ['config', 'gc.auto', '0']);

$app = Application::create(options: ['extra' => ['providers' => [AppVersionServiceProvider::class], 'dont-discover' => ['*']]]);
$config = $options['config'] !== null ? require $options['config'] : [];
$app['config']->set('app.name', $options['app-name'] ?? $app['config']->get('app.name'));

foreach (['release_notes', 'tag_prefix'] as $key) {
    if (array_key_exists($key, $config)) {
        $app['config']->set("app-version.{$key}", $config[$key]);
    }
}

$app['config']->set('app-version.repository_path', $clone);
$app['config']->set('app-version.version_file', $clone.DIRECTORY_SEPARATOR.'VERSION');
$app['config']->set('app-version.json_path', $out.DIRECTORY_SEPARATOR.'version.json');
$app['config']->set('app-version.flat', false);
$app['config']->set('app-version.flat_path', $out.DIRECTORY_SEPARATOR.'version-info.json');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$flat = ! isset($flags['--no-flat']);
$run = static function (array $parameters) use ($kernel, $flat): float {
    $start = hrtime(true);
    $status = $kernel->call('app:version', [...($flat ? ['--flat' => true] : []), '--no-interaction' => true, ...$parameters]);

    if ($status !== 0) {
        fwrite(STDERR, "app:version failed: {$kernel->output()}\n");
        exit(2);
    }

    return (hrtime(true) - $start) / 1e9;
};

$commits = array_filter(explode("\n", $git($clone, ['rev-list', '--first-parent', '--reverse', '-n', (string) max(1, (int) $options['commits']), $tip])));
$cache = $out.DIRECTORY_SEPARATOR.'stats-cache.json';
$different = 0;

foreach ($commits as $commit) {
    $git($clone, ['update-ref', '--no-deref', 'HEAD', $commit]);
    // The index lists the tracked attribute files, as in a checkout of the commit.
    $git($clone, ['read-tree', $commit]);

    // The working-tree files app:version and Git's attribute lookup read.
    foreach (['VERSION', '.gitattributes'] as $file) {
        $path = $clone.DIRECTORY_SEPARATOR.$file;
        $present = $git($clone, ['ls-tree', '--name-only', $commit, '--', $file]) !== '';
        $present ? file_put_contents($path, $git($clone, ['cat-file', 'blob', "{$commit}:{$file}"], raw: true)) : @unlink($path);
    }

    $full = $run(['--output' => $out.DIRECTORY_SEPARATOR.'full.json']);
    $cached = $run(['--stats-cache' => $cache, '--output' => $out.DIRECTORY_SEPARATOR.'cached.json']);
    $same = file_get_contents($out.DIRECTORY_SEPARATOR.'full.json') === file_get_contents($out.DIRECTORY_SEPARATOR.'cached.json');

    if (! $same) {
        $different++;
        copy($out.DIRECTORY_SEPARATOR.'full.json', $out.DIRECTORY_SEPARATOR."full-{$commit}.json");
        copy($out.DIRECTORY_SEPARATOR.'cached.json', $out.DIRECTORY_SEPARATOR."cached-{$commit}.json");
    }

    $parents = count(explode(' ', $git($clone, ['log', '-1', '--format=%P', $commit])));
    printf("%s %-5s full %.3fs  cached %.3fs  %s\n", substr($commit, 0, 12), $parents > 1 ? 'merge' : '', $full, $cached, $same ? 'identical' : 'DIFFERENT');
}

printf("%d commits of %s replayed (%s): %d different\n", count($commits), $options['ref'], $flat ? '--flat' : 'version.json', $different);

if (isset($flags['--keep']) || $different > 0) {
    echo "Kept {$work}\n";
} else {
    (PHP_OS_FAMILY === 'Windows') ? exec('rmdir /s /q '.escapeshellarg($work)) : exec('rm -rf '.escapeshellarg($work));
}

exit($different > 0 ? 1 : 0);
