<?php

declare(strict_types=1);

namespace Bratech\FuelPhpCompat;

use RuntimeException;

/**
 * Inspects Composer metadata and known source files without loading application code.
 * Package failures are reported as states; unusable project metadata raises an exception.
 */
final class Project
{
    private $root;
    private $packageRoot;

    /**
     * Selects the application and the compatibility release to inspect.
     *
     * @param string $root Application root containing composer.json.
     * @param string $packageRoot Compatibility package directory containing manifest.json.
     */
    public function __construct(string $root, string $packageRoot)
    {
        $this->root = $root;
        $this->packageRoot = $packageRoot;
    }

    /**
     * Rechecks metadata, placement, references and every manifest source hash.
     *
     * @return array Release, absolute project root and package reports keyed by package name.
     * @throws RuntimeException If project paths or metadata cannot be safely interpreted.
     */
    public function inspect(): array
    {
        // Re-resolve paths on each inspection so a transaction can recheck under its lock.
        $root = $this->directory($this->root);
        $packageRoot = $this->directory($this->packageRoot);
        $manifest = $this->readJson($packageRoot.'/manifest.json');
        $this->validateManifest($manifest);
        $composer = $this->readJson($root.'/composer.json');
        if (array_key_exists('config', $composer) && !is_array($composer['config'])) {
            throw new RuntimeException('composer.json config must be an object.');
        }
        $vendor = $composer['config']['vendor-dir'] ?? 'vendor';
        if (!is_string($vendor) || $vendor === '') {
            throw new RuntimeException('composer.json vendor-dir must be a nonempty path.');
        }
        $metadataDirectory = $this->resolvePath($vendor.'/composer', $root);
        if (!$this->within($metadataDirectory, $root)) {
            throw new RuntimeException('Composer metadata must be inside the project root.');
        }
        $installed = $this->readJson($metadataDirectory.'/installed.json');
        if (!isset($installed['packages']) || !is_array($installed['packages'])
            || ($installed['packages'] && array_keys($installed['packages']) !== range(0, count($installed['packages']) - 1))) {
            throw new RuntimeException('Composer 2 installed.json with a packages array is required.');
        }

        // Duplicate names make the installed version and destination ambiguous.
        $packages = [];
        foreach ($installed['packages'] as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name']) || $entry['name'] === '') {
                throw new RuntimeException('Installed package metadata has no valid name.');
            }
            if (isset($packages[$entry['name']])) {
                throw new RuntimeException('Duplicate installed package: '.$entry['name']);
            }
            $packages[$entry['name']] = $entry;
        }

        $reports = [];
        foreach ($manifest['packages'] as $name => $definition) {
            $reports[$name] = $this->inspectPackage(
                $name,
                $definition,
                $packages[$name] ?? null,
                $metadataDirectory,
                $root
            );
        }
        return ['release' => $manifest['release'], 'root' => $root, 'packages' => $reports];
    }

    /**
     * Determines whether all installed targets already provide the required compatibility.
     *
     * @param array $report Report returned by inspect().
     * @return bool True for patched or upstream targets and absent optional packages only.
     */
    public static function isReady(array $report): bool
    {
        if (empty($report['packages']) || !is_array($report['packages'])) {
            return false;
        }
        foreach ($report['packages'] as $package) {
            if (!in_array($package['state'] ?? null, ['patched', 'upstream', 'not_installed'], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Determines whether inspection found a state that prevents applying or reversing.
     *
     * @param array $report Report returned by inspect().
     * @return bool True for invalid reports or any unknown, incomplete or unsafe target.
     */
    public static function hasErrors(array $report): bool
    {
        if (empty($report['packages']) || !is_array($report['packages'])) {
            return true;
        }
        foreach ($report['packages'] as $package) {
            if (!in_array($package['state'] ?? null, ['unpatched', 'patched', 'upstream', 'not_installed'], true)) {
                return true;
            }
        }
        return false;
    }

    private function inspectPackage(string $name, array $definition, ?array $installed, string $metadataDirectory, string $root): array
    {
        $report = [
            'name' => $name,
            'version' => null,
            'state' => $definition['required'] ? 'missing' : 'not_installed',
            'reason' => $definition['required'] ? 'Required package is not installed.' : 'Optional package is not installed.',
            'path' => null,
            'variant' => null,
        ];
        if ($installed === null) {
            return $report;
        }
        $version = $installed['version'] ?? null;
        if (!is_string($version) || $version === '') {
            throw new RuntimeException('Installed package has no valid version: '.$name);
        }
        $report['version'] = $version;
        // Composer retains the optional v prefix of release tags in its display version.
        $lookupVersion = preg_replace('/^v(?=[0-9])/', '', $version);
        if (!isset($definition['variants'][$lookupVersion])) {
            return $this->state($report, 'unsupported', 'Version is not supported by this release.');
        }
        $variant = $definition['variants'][$lookupVersion];
        $report['variant'] = $variant;

        // Matching bytes alone do not establish that Composer selected the verified revision.
        $references = [];
        foreach (['source', 'dist'] as $kind) {
            if (isset($installed[$kind]) && !is_array($installed[$kind])) {
                throw new RuntimeException('Invalid '.$kind.' metadata for package: '.$name);
            }
            if (isset($installed[$kind]['reference']) && $installed[$kind]['reference'] !== '') {
                $references[] = $installed[$kind]['reference'];
            }
        }
        if (!$references) {
            return $this->state($report, 'unsupported', 'Installed package has no source or distribution reference.');
        }
        foreach ($references as $reference) {
            if ($reference !== $variant['reference']) {
                return $this->state($report, 'unsupported', 'Installed reference does not match the verified revision.');
            }
        }

        $installPath = $installed['install-path'] ?? null;
        if (!is_string($installPath) || $installPath === '') {
            return $this->state($report, 'unsafe', 'Installed package has no usable install-path.');
        }
        try {
            $path = $this->resolvePath($installPath, $metadataDirectory);
        } catch (RuntimeException $exception) {
            return $this->state($report, 'unsafe', $exception->getMessage());
        }
        if (!$this->within($path, $root) || $path === $root) {
            return $this->state($report, 'unsafe', 'Package directory must be below the project root.');
        }
        $report['path'] = $path;
        if (!is_dir($path)) {
            return $this->state($report, 'missing', 'Installed package directory is missing.');
        }

        // Classify the package as a whole; a mixture of known file states is not safe to patch.
        $before = false;
        $after = false;
        foreach ($variant['files'] as $relative => $hashes) {
            try {
                $file = $this->resolvePath($relative, $path);
            } catch (RuntimeException $exception) {
                return $this->state($report, 'unsafe', $exception->getMessage());
            }
            if (!is_file($file)) {
                return $this->state($report, 'missing', 'Required source file is missing: '.$relative);
            }
            $hash = @hash_file('sha256', $file);
            if ($hash === false) {
                return $this->state($report, 'unsafe', 'Cannot read source file: '.$relative);
            }
            if ($hash !== $hashes['before'] && $hash !== $hashes['after']) {
                return $this->state($report, 'modified', 'Source hash is unknown: '.$relative);
            }
            // Unchanged manifest files verify provenance but do not determine patch state.
            if ($hashes['before'] !== $hashes['after']) {
                $before = $before || $hash === $hashes['before'];
                $after = $after || $hash === $hashes['after'];
            }
        }
        if ($variant['mode'] === 'upstream') {
            return $this->state($report, 'upstream', 'Verified upstream source already contains the required compatibility.');
        }
        if ($before && $after) {
            return $this->state($report, 'partial', 'Package contains both original and patched source files.');
        }
        return $after
            ? $this->state($report, 'patched', 'All source hashes match this compatibility release.')
            : $this->state($report, 'unpatched', 'All source hashes match the verified original source.');
    }

    private function state(array $report, string $state, string $reason): array
    {
        $report['state'] = $state;
        $report['reason'] = $reason;
        return $report;
    }

    private function directory(string $path): string
    {
        $resolved = $this->resolvePath($path, (string) getcwd());
        if (!is_dir($resolved)) {
            throw new RuntimeException('Directory does not exist: '.$resolved);
        }
        return $resolved;
    }

    private function resolvePath(string $path, string $base): string
    {
        if ($path === '' || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            throw new RuntimeException('Invalid filesystem path.');
        }
        $absolute = $path[0] === '/' ? $path : $base.'/'.$path;
        $parts = [];
        // Inspect components before processing ".." so a symlink cannot disappear during normalization.
        foreach (explode('/', $absolute) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
            $candidate = '/'.implode('/', $parts);
            clearstatcache(true, $candidate);
            if (is_link($candidate)) {
                throw new RuntimeException('Symbolic links are not supported: '.$candidate);
            }
        }
        return '/'.implode('/', $parts);
    }

    private function within(string $path, string $root): bool
    {
        return $path === $root || strpos($path, rtrim($root, '/').'/') === 0;
    }

    private function readJson(string $path): array
    {
        $resolved = $this->resolvePath($path, '/');
        if (!is_file($resolved)) {
            throw new RuntimeException('Metadata file is missing: '.$resolved);
        }
        $contents = @file_get_contents($resolved);
        if ($contents === false) {
            throw new RuntimeException('Cannot read metadata: '.$resolved);
        }
        $data = json_decode($contents, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE || substr(ltrim($contents), 0, 1) !== '{') {
            throw new RuntimeException('Invalid JSON metadata: '.$resolved);
        }
        return $data;
    }

    private function validateManifest(array $manifest): void
    {
        if (($manifest['schema'] ?? null) !== 1
            || !isset($manifest['release']) || !is_string($manifest['release']) || $manifest['release'] === ''
            || empty($manifest['packages']) || !is_array($manifest['packages'])) {
            throw new RuntimeException('Unsupported or incomplete compatibility manifest.');
        }
        foreach ($manifest['packages'] as $name => $definition) {
            if (!is_string($name) || !is_array($definition)
                || !isset($definition['required']) || !is_bool($definition['required'])
                || empty($definition['variants']) || !is_array($definition['variants'])) {
                throw new RuntimeException('Invalid manifest package definition.');
            }
            foreach ($definition['variants'] as $version => $variant) {
                if (!is_string($version) || !is_array($variant)
                    || !isset($variant['reference']) || !is_string($variant['reference']) || $variant['reference'] === ''
                    || !in_array($variant['mode'] ?? null, ['patch', 'upstream'], true)
                    || empty($variant['files']) || !is_array($variant['files'])) {
                    throw new RuntimeException('Invalid manifest variant: '.$name);
                }
                if ($variant['mode'] === 'patch'
                    && (!$this->relativeFile($variant['patch'] ?? null) || !$this->sha256($variant['patch_sha256'] ?? null))) {
                    throw new RuntimeException('Invalid manifest patch: '.$name);
                }
                $changed = false;
                foreach ($variant['files'] as $relative => $hashes) {
                    if (!$this->relativeFile($relative) || !is_array($hashes)
                        || !$this->sha256($hashes['before'] ?? null) || !$this->sha256($hashes['after'] ?? null)) {
                        throw new RuntimeException('Invalid manifest source path or hashes: '.$name);
                    }
                    $changed = $changed || $hashes['before'] !== $hashes['after'];
                }
                if (($variant['mode'] === 'upstream' && $changed) || ($variant['mode'] === 'patch' && !$changed)) {
                    throw new RuntimeException('Manifest hashes do not match the declared mode: '.$name);
                }
            }
        }
    }

    private function relativeFile($path): bool
    {
        if (!is_string($path) || $path === '' || $path[0] === '/'
            || strpos($path, '\\') !== false || strpos($path, "\0") !== false || strpos($path, ':') !== false) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }

    private function sha256($value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
