<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Medalink\AppVersion\AppVersion;

it('keeps a real committed snapshot clean after recording it in Git', function (): void {
    $git = function (array $arguments): string {
        $result = Process::path($this->workspace)->run(['git', ...$arguments]);
        expect($result->successful())->toBeTrue($result->errorOutput());

        return trim($result->output());
    };
    $git(['init']);
    $git(['config', 'user.name', 'Package Tests']);
    $git(['config', 'user.email', 'tests@example.test']);
    $git(['config', 'commit.gpgsign', 'false']);
    $this->writeVersionFile('0.1.0');
    File::put($this->workspace.'/editor.txt', "Manuscript editor\n");
    $git(['add', 'VERSION', 'editor.txt']);
    $git(['commit', '-m', 'feat: add manuscript editor']);
    $source = $git(['rev-parse', 'HEAD']);
    $this->artisan('app:version', ['--flat' => true])->assertSuccessful();
    $snapshot = File::get(AppVersion::flatPath());
    expect(json_decode($snapshot, true)['source_commit'])->toBe($source);
    $git(['add', 'version-info.json']);
    $git(['commit', '-m', 'chore: record version snapshot']);

    $this->artisan('app:version', ['--flat' => true])->assertSuccessful();
    expect(File::get(AppVersion::flatPath()))->toBe($snapshot)
        ->and($git(['status', '--porcelain']))->toBe('');
});
