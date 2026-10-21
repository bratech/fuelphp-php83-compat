<?php

namespace Bratech\FuelPhpCompat;

use RuntimeException;
use Throwable;

/** Stage verified patches, replace project files, and recover failed writes. */
class Transaction
{
    private $project;
    private $packageRoot;
    private $root;

    /**
     * Bind the project inspector and the compatibility release used for staging.
     *
     * @param Project $project Read-only inspector for the target project.
     * @param string $packageRoot Directory containing the manifest and patches.
     */
    public function __construct(Project $project, string $packageRoot)
    {
        $this->project = $project;
        $this->packageRoot = $packageRoot;
    }

    /**
     * Apply or reverse this release after checking every installed target.
     * File replacements are individual operations, not an atomic project update.
     *
     * @param string $direction Either apply or reverse.
     * @return array Inspection report after the operation.
     * @throws RuntimeException On unsafe sources, lock contention, or failed writes.
     *     A failed rollback retains backups at the location included in the error.
     */
    public function run(string $direction): array
    {
        if (!in_array($direction, ['apply', 'reverse'], true)) {
            throw new RuntimeException('Unknown transaction direction: ' . $direction);
        }
        $report = $this->project->inspect();
        $this->assertReport($report);
        $this->root = $report['root'];
        $lockPath = $this->root . '/.fuelphp-compat.lock';
        $this->assertSafePath($lockPath, false);
        if (file_exists($lockPath) && !is_file($lockPath)) {
            throw new RuntimeException('Invalid lock file: ' . $lockPath);
        }
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new RuntimeException('Cannot open project lock: ' . $lockPath);
        }
        $work = null;
        $retain = false;
        try {
            // Keep the same lock inode between runs so waiting processes share it.
            clearstatcache(true, $lockPath);
            $opened = fstat($lock);
            $named = lstat($lockPath);
            if (is_link($lockPath) || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
                throw new RuntimeException('Project lock changed while opening: ' . $lockPath);
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another compatibility operation holds the project lock.');
            }
            $report = $this->project->inspect();
            $this->assertReport($report);
            $selected = array_filter($report['packages'], function (array $package) use ($direction): bool {
                return $package['state'] === ($direction === 'apply' ? 'unpatched' : 'patched');
            });
            if (!$selected) {
                return $report;
            }
            $this->git(['--version'], $this->root);
            $work = $this->root . '/.fuelphp-compat-work-' . bin2hex(random_bytes(12));
            if (!mkdir($work, 0700)) {
                throw new RuntimeException('Cannot create transaction workspace: ' . $work);
            }
            $changes = $this->stage($selected, $direction, $work);

            // Check all live inputs again before the first replacement. Application
            // processes must still be stopped: this lock coordinates this tool only.
            foreach ($changes as $change) {
                $this->assertOriginal($change);
            }
            $attempted = [];
            try {
                foreach ($changes as $change) {
                    $this->assertOriginal($change);
                    $attempted[] = $change;
                    $this->replaceFile($change['path'], $this->read($change['staged']), $change['mode']);
                }
                $result = $this->project->inspect();
                $this->assertReport($result);
                foreach ($selected as $name => $package) {
                    $expected = $direction === 'apply' ? 'patched' : 'unpatched';
                    if ($result['packages'][$name]['state'] !== $expected) {
                        throw new RuntimeException('Final source verification failed for ' . $name);
                    }
                }
                return $result;
            } catch (Throwable $failure) {
                // Include the failed replacement because an implementation may throw
                // after it changed the file. Leave unknown external edits untouched
                // and retain backups rather than overwriting another writer's data.
                $failed = [];
                foreach (array_reverse($attempted) as $change) {
                    try {
                        $this->assertSafePath($change['path'], true);
                        $current = hash_file('sha256', $change['path']);
                        if ($current === $change['before']) {
                            continue;
                        }
                        if ($current !== $change['after']) {
                            throw new RuntimeException('Concurrent unknown edit; original backup retained.');
                        }
                        $this->replaceFile($change['path'], $this->read($change['backup']), $change['mode']);
                        $this->assertOriginal($change);
                    } catch (Throwable $rollbackFailure) {
                        $failed[] = $change['path'] . ': ' . $rollbackFailure->getMessage();
                    }
                }
                if ($failed) {
                    $retain = true;
                    throw new RuntimeException(
                        $failure->getMessage() . '; rollback failed. Backups and recovery.json retained at '
                        . $work . '. Affected files: ' . implode('; ', $failed),
                        0,
                        $failure
                    );
                }
                throw new RuntimeException($failure->getMessage() . '; changed files restored.', 0, $failure);
            }
        } finally {
            if ($work !== null && !$retain) {
                $this->removeTree($work);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Replace one regular file using a temporary file in the same directory.
     * Overrides must throw on failure; rollback invokes the same method.
     *
     * @param string $path Absolute target path inside the inspected project.
     * @param string $content Complete replacement bytes.
     * @param int $mode Original permission bits to preserve.
     * @return void
     * @throws RuntimeException If the path is unsafe or replacement fails.
     */
    protected function replaceFile(string $path, string $content, int $mode): void
    {
        $this->assertSafePath($path, true);
        $temporary = @tempnam(dirname($path), '.fuelphp-compat-');
        if ($temporary === false || dirname($temporary) !== dirname($path)) {
            if ($temporary !== false) {
                @unlink($temporary);
            }
            throw new RuntimeException('Cannot create replacement file for ' . $path);
        }
        try {
            $this->write($temporary, $content);
            if (!@chmod($temporary, $mode)) {
                throw new RuntimeException('Cannot preserve permissions for ' . $path);
            }
            $this->assertSafePath($path, true);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Cannot replace ' . $path);
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function assertReport(array $report): void
    {
        if (Project::hasErrors($report)) {
            $errors = [];
            foreach ($report['packages'] as $name => $package) {
                if (!in_array($package['state'], ['patched', 'unpatched', 'upstream', 'not_installed'], true)) {
                    $errors[] = $name . ': ' . $package['state'] . ' (' . $package['reason'] . ')';
                }
            }
            throw new RuntimeException('Project preflight failed: ' . implode('; ', $errors));
        }
    }

    private function stage(array $packages, string $direction, string $work): array
    {
        $changes = [];
        $index = 0;
        $sourceKey = $direction === 'apply' ? 'before' : 'after';
        $targetKey = $direction === 'apply' ? 'after' : 'before';
        foreach ($packages as $package) {
            $variant = $package['variant'];
            $stage = $work . '/stage-' . $index++;
            if (!mkdir($stage, 0700)) {
                throw new RuntimeException('Cannot create staging directory: ' . $stage);
            }
            $packageChanges = [];
            foreach ($variant['files'] as $relative => $hashes) {
                $path = $package['path'] . '/' . $relative;
                $this->assertSafePath($path, true);
                $original = $this->read($path);
                if (hash('sha256', $original) !== $hashes[$sourceKey]) {
                    throw new RuntimeException('Source changed during staging: ' . $path);
                }
                $staged = $stage . '/' . $relative;
                if (!is_dir(dirname($staged)) && !mkdir(dirname($staged), 0700, true)) {
                    throw new RuntimeException('Cannot create staging parent: ' . dirname($staged));
                }
                $backup = $work . '/backup-' . count($changes);
                $this->write($backup, $original);
                $this->write($staged, $original);
                $change = [
                    'path' => $path,
                    'backup' => $backup,
                    'staged' => $staged,
                    'mode' => fileperms($path) & 0777,
                    'before' => $hashes[$sourceKey],
                    'after' => $hashes[$targetKey],
                ];
                $changes[] = $change;
                $packageChanges[] = $change;
            }
            $packageRoot = realpath($this->packageRoot);
            if ($packageRoot === false) {
                throw new RuntimeException('Compatibility package directory disappeared.');
            }
            $patchPath = $packageRoot . '/' . $variant['patch'];
            $this->assertSafePath($patchPath, true, $packageRoot);
            $patch = $this->read($patchPath);
            if (hash('sha256', $patch) !== $variant['patch_sha256']) {
                throw new RuntimeException('Patch checksum mismatch: ' . $variant['patch']);
            }
            $stagedPatch = $work . '/patch-' . $index;
            $this->write($stagedPatch, $patch);

            // A fresh repository prevents Git from discovering a parent repository.
            $this->git(['init', '-q', '--template='], $stage);
            $arguments = ['apply', '--whitespace=nowarn'];
            if ($direction === 'reverse') {
                $arguments[] = '--reverse';
            }
            $this->git(array_merge($arguments, ['--check', '--', $stagedPatch]), $stage);
            $this->git(array_merge($arguments, ['--', $stagedPatch]), $stage);
            foreach ($packageChanges as $change) {
                $this->assertSafePath($change['staged'], true);
                if (!is_file($change['staged']) || is_link($change['staged'])
                    || hash_file('sha256', $change['staged']) !== $change['after']) {
                    throw new RuntimeException('Staged source checksum mismatch: ' . $change['path']);
                }
            }
        }
        // Persist recovery instructions before any live file can be replaced.
        $this->write($work . '/recovery.json', json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $changes;
    }

    private function assertOriginal(array $change): void
    {
        $this->assertSafePath($change['path'], true);
        if (hash_file('sha256', $change['path']) !== $change['before']) {
            throw new RuntimeException('Source changed during transaction: ' . $change['path']);
        }
    }

    private function assertSafePath(string $path, bool $mustExist, ?string $boundary = null): void
    {
        $boundary = $boundary ?? $this->root;
        if (strpos($path, rtrim($boundary, '/') . '/') !== 0) {
            throw new RuntimeException('Path is outside its allowed directory: ' . $path);
        }
        $cursor = '';
        foreach (explode('/', ltrim($path, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Unsafe path: ' . $path);
            }
            $cursor .= '/' . $part;
            clearstatcache(true, $cursor);
            if (is_link($cursor)) {
                throw new RuntimeException('Symlink is not supported: ' . $path);
            }
        }
        if ($mustExist && !is_file($path)) {
            throw new RuntimeException('Target is not a regular file: ' . $path);
        }
    }

    private function git(array $arguments, string $directory): void
    {
        // Ignore inherited repository selectors so staging stays self-contained.
        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (strpos($name, 'GIT_') === 0) {
                unset($environment[$name]);
            }
        }
        $environment['GIT_CONFIG_NOSYSTEM'] = '1';
        $environment['GIT_CONFIG_GLOBAL'] = '/dev/null';
        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('Cannot capture Git output.');
        }
        try {
            $process = @proc_open(
                array_merge(['git'], $arguments),
                [0 => ['pipe', 'r'], 1 => $output, 2 => $output],
                $pipes,
                $directory,
                $environment
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Git is required but could not be started.');
            }
            fclose($pipes[0]);
            $code = proc_close($process);
            rewind($output);
            $message = trim(stream_get_contents($output));
            if ($code !== 0) {
                throw new RuntimeException('Git failed (' . $code . '): ' . $message);
            }
        } finally {
            fclose($output);
        }
    }

    private function read(string $path): string
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Cannot read ' . $path);
        }
        return $content;
    }

    private function write(string $path, string $content): void
    {
        if (@file_put_contents($path, $content) !== strlen($content)) {
            throw new RuntimeException('Cannot write ' . $path);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
}
