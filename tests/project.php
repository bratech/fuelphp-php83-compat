<?php

declare(strict_types=1);

use Bratech\FuelPhpCompat\Project;

if (!is_file(__DIR__.'/../src/Project.php')) {
    fwrite(STDERR, "FAIL: Project inspector is not implemented.\n");
    exit(1);
}
require_once __DIR__.'/../src/Project.php';

function projectAssert($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

function projectWriteJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function projectRemove(string $path): void
{
    if (is_link($path) || !is_dir($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') {
            projectRemove($path.'/'.$name);
        }
    }
    rmdir($path);
}

function projectFixture(string $base): array
{
    $root = $base.'/application';
    $package = $base.'/compat';
    mkdir($root.'/deps/composer', 0777, true);
    mkdir($root.'/framework/core/classes', 0777, true);
    mkdir($package, 0777, true);
    projectWriteJson($root.'/composer.json', ['config' => ['vendor-dir' => 'deps']]);
    file_put_contents($root.'/framework/core/classes/a.php', "original a\n");
    file_put_contents($root.'/framework/core/classes/b.php', "original b\n");
    // Executing either file would make an otherwise valid inspection fail.
    file_put_contents($root.'/deps/autoload.php', '<?php throw new RuntimeException("autoload executed");');
    file_put_contents($root.'/bootstrap.php', '<?php throw new RuntimeException("bootstrap executed");');
    $variant = [
        'reference' => str_repeat('a', 40),
        'mode' => 'patch',
        'patch' => 'patches/core.patch',
        'patch_sha256' => str_repeat('b', 64),
        'files' => [
            'classes/a.php' => ['before' => hash('sha256', "original a\n"), 'after' => hash('sha256', "patched a\n")],
            'classes/b.php' => ['before' => hash('sha256', "original b\n"), 'after' => hash('sha256', "patched b\n")],
        ],
    ];
    $manifest = [
        'schema' => 1,
        'release' => '1.0.0',
        'packages' => [
            'fuel/core' => ['required' => true, 'variants' => ['1.8.2' => $variant]],
            'fuel/email' => ['required' => false, 'variants' => ['1.8.2' => $variant]],
        ],
    ];
    $installed = ['packages' => [[
        'name' => 'fuel/core',
        'version' => '1.8.2',
        'source' => ['type' => 'git', 'reference' => str_repeat('a', 40)],
        'dist' => ['type' => 'zip', 'reference' => str_repeat('a', 40)],
        'install-path' => '../../framework/core',
    ]], 'dev' => true, 'dev-package-names' => []];
    projectWriteJson($package.'/manifest.json', $manifest);
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    return [$root, $package, $manifest, $installed];
}

function projectState(string $root, string $package): string
{
    return (new Project($root, $package))->inspect()['packages']['fuel/core']['state'];
}

function projectThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        return;
    }
    throw new RuntimeException($message);
}

$tests = [];
$tests['custom vendor and installer paths, optional absence, read-only report'] = function ($root, $package) {
    $before = file_get_contents($root.'/framework/core/classes/a.php');
    $report = (new Project($root, $package))->inspect();
    projectAssert('1.0.0', $report['release'], 'Release must come from the manifest.');
    projectAssert(realpath($root), $report['root'], 'Report root must be absolute.');
    projectAssert('unpatched', $report['packages']['fuel/core']['state'], 'Known original source must be accepted.');
    projectAssert(realpath($root.'/framework/core'), $report['packages']['fuel/core']['path'], 'Install path must be relative to installed.json.');
    projectAssert('not_installed', $report['packages']['fuel/email']['state'], 'Optional absence must be explicit.');
    projectAssert(false, Project::hasErrors($report), 'Unpatched source is valid for status.');
    projectAssert(false, Project::isReady($report), 'Unpatched source must fail check.');
    projectAssert($before, file_get_contents($root.'/framework/core/classes/a.php'), 'Inspection must not modify sources.');
};
$tests['patched source passes check'] = function ($root, $package) {
    file_put_contents($root.'/framework/core/classes/a.php', "patched a\n");
    file_put_contents($root.'/framework/core/classes/b.php', "patched b\n");
    $report = (new Project($root, $package))->inspect();
    projectAssert('patched', $report['packages']['fuel/core']['state'], 'All patched files must be recognized.');
    projectAssert(true, Project::isReady($report), 'Patched project with optional absence must pass check.');
};
$tests['partial application is rejected'] = function ($root, $package) {
    file_put_contents($root.'/framework/core/classes/a.php', "patched a\n");
    $report = (new Project($root, $package))->inspect();
    projectAssert('partial', $report['packages']['fuel/core']['state'], 'Mixed files within one package must fail.');
    projectAssert(true, Project::hasErrors($report), 'Partial application must fail status.');
};
$tests['unknown source is rejected'] = function ($root, $package) {
    file_put_contents($root.'/framework/core/classes/a.php', "user customization\n");
    projectAssert('modified', projectState($root, $package), 'Unknown source must not be accepted.');
};
$tests['missing tracked file is rejected'] = function ($root, $package) {
    unlink($root.'/framework/core/classes/b.php');
    projectAssert('missing', projectState($root, $package), 'Missing tracked files must fail.');
};
$tests['missing required package is rejected'] = function ($root, $package, $manifest, $installed) {
    $installed['packages'] = [];
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('missing', projectState($root, $package), 'Core must be required.');
};
$tests['unsupported version is rejected'] = function ($root, $package, $manifest, $installed) {
    $installed['packages'][0]['version'] = '1.9.0';
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unsupported', projectState($root, $package), 'Unknown versions must fail.');
};
$tests['source reference mismatch is rejected'] = function ($root, $package, $manifest, $installed) {
    $installed['packages'][0]['source']['reference'] = str_repeat('c', 40);
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unsupported', projectState($root, $package), 'Matching files cannot override a different reference.');
};
$tests['dist-only metadata is supported'] = function ($root, $package, $manifest, $installed) {
    unset($installed['packages'][0]['source']);
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unpatched', projectState($root, $package), 'Dist reference must suffice when source is absent.');
};
$tests['missing references are rejected'] = function ($root, $package, $manifest, $installed) {
    unset($installed['packages'][0]['source'], $installed['packages'][0]['dist']);
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unsupported', projectState($root, $package), 'Reference must be verified.');
};
$tests['duplicate package metadata is rejected'] = function ($root, $package, $manifest, $installed) {
    $installed['packages'][] = $installed['packages'][0];
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectThrows(function () use ($root, $package) { (new Project($root, $package))->inspect(); }, 'Duplicate metadata must fail.');
};
$tests['outside project install path is rejected'] = function ($root, $package, $manifest, $installed) {
    $installed['packages'][0]['install-path'] = '../../../compat';
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unsafe', projectState($root, $package), 'External package paths must fail.');
};
$tests['package directory symlink is rejected'] = function ($root, $package, $manifest, $installed) {
    symlink($root.'/framework/core', $root.'/linked-core');
    $installed['packages'][0]['install-path'] = '../../linked-core';
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unsafe', projectState($root, $package), 'Even internal symlinked package paths must fail.');
};
$tests['file symlink is rejected'] = function ($root, $package) {
    rename($root.'/framework/core/classes/a.php', $root.'/original.php');
    symlink($root.'/original.php', $root.'/framework/core/classes/a.php');
    projectAssert('unsafe', projectState($root, $package), 'Target file symlinks must fail before hashing.');
};
$tests['symlink followed by parent traversal is rejected'] = function ($root, $package, $manifest, $installed) {
    symlink($root.'/framework/core', $root.'/alias');
    $installed['packages'][0]['install-path'] = '../../alias/../core';
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unsafe', projectState($root, $package), 'Lexical normalization must not erase symlink traversal.');
};
$tests['root symlink is rejected'] = function ($root, $package) {
    symlink($root, dirname($root).'/alias-root');
    projectThrows(function () use ($root, $package) { (new Project(dirname($root).'/alias-root', $package))->inspect(); }, 'Root symlink must fail.');
};
$tests['metadata symlink is rejected'] = function ($root, $package) {
    rename($root.'/deps/composer/installed.json', $root.'/installed.json');
    symlink($root.'/installed.json', $root.'/deps/composer/installed.json');
    projectThrows(function () use ($root, $package) { (new Project($root, $package))->inspect(); }, 'Metadata symlink must fail.');
};
$tests['invalid metadata is rejected'] = function ($root, $package) {
    file_put_contents($root.'/deps/composer/installed.json', '{invalid');
    projectThrows(function () use ($root, $package) { (new Project($root, $package))->inspect(); }, 'Invalid JSON must fail.');
};
$tests['Composer 1 metadata is rejected'] = function ($root, $package, $manifest, $installed) {
    projectWriteJson($root.'/deps/composer/installed.json', $installed['packages']);
    projectThrows(function () use ($root, $package) { (new Project($root, $package))->inspect(); }, 'Composer 2 metadata is required.');
};
$tests['upstream variant verifies hashes'] = function ($root, $package, $manifest) {
    $variant = &$manifest['packages']['fuel/core']['variants']['1.8.2'];
    $variant['mode'] = 'upstream';
    $variant['patch'] = null;
    $variant['patch_sha256'] = null;
    foreach ($variant['files'] as &$hashes) {
        $hashes['after'] = $hashes['before'];
    }
    projectWriteJson($package.'/manifest.json', $manifest);
    $report = (new Project($root, $package))->inspect();
    projectAssert('upstream', $report['packages']['fuel/core']['state'], 'Known upstream source must pass.');
    projectAssert(true, Project::isReady($report), 'Upstream source must pass check.');
    file_put_contents($root.'/framework/core/classes/b.php', 'custom');
    projectAssert('modified', projectState($root, $package), 'Upstream mode must still verify content.');
};
$tests['mixed packages are accepted for apply'] = function ($root, $package, $manifest, $installed) {
    mkdir($root.'/framework/email/classes', 0777, true);
    file_put_contents($root.'/framework/email/classes/a.php', "patched a\n");
    file_put_contents($root.'/framework/email/classes/b.php', "patched b\n");
    $email = $installed['packages'][0];
    $email['name'] = 'fuel/email';
    $email['install-path'] = '../../framework/email';
    $installed['packages'][] = $email;
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    $report = (new Project($root, $package))->inspect();
    projectAssert('patched', $report['packages']['fuel/email']['state'], 'Package state must be independent.');
    projectAssert(false, Project::hasErrors($report), 'Mixed complete package states must remain applicable.');
};
$tests['manifest traversal is rejected'] = function ($root, $package, $manifest) {
    $manifest['packages']['fuel/core']['variants']['1.8.2']['files']['../outside.php'] = [
        'before' => str_repeat('a', 64), 'after' => str_repeat('b', 64),
    ];
    projectWriteJson($package.'/manifest.json', $manifest);
    projectThrows(function () use ($root, $package) { (new Project($root, $package))->inspect(); }, 'Manifest file paths must stay inside package.');
};

$tests['default vendor directory is supported'] = function ($root, $package) {
    rename($root.'/deps', $root.'/vendor');
    projectWriteJson($root.'/composer.json', ['name' => 'example/application']);
    projectAssert('unpatched', projectState($root, $package), 'Default vendor must resolve metadata.');
};
$tests['missing install path is rejected'] = function ($root, $package, $manifest, $installed) {
    unset($installed['packages'][0]['install-path']);
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unsafe', projectState($root, $package), 'Missing paths must not fall back to package names.');
};
$tests['missing package directory is rejected'] = function ($root, $package) {
    projectRemove($root.'/framework/core');
    projectAssert('missing', projectState($root, $package), 'Composer metadata cannot stand in for installed files.');
};
$tests['source subdirectory symlink is rejected'] = function ($root, $package) {
    rename($root.'/framework/core/classes', $root.'/classes');
    symlink($root.'/classes', $root.'/framework/core/classes');
    projectAssert('unsafe', projectState($root, $package), 'All source path components must be checked.');
};
$tests['conflicting distribution reference is rejected'] = function ($root, $package, $manifest, $installed) {
    $installed['packages'][0]['dist']['reference'] = str_repeat('d', 40);
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unsupported', projectState($root, $package), 'Source reference must not hide a conflicting archive reference.');
};
$tests['unchanged tracked files do not cause partial application'] = function ($root, $package, $manifest) {
    $manifest['packages']['fuel/core']['variants']['1.8.2']['files']['classes/b.php']['after'] = hash('sha256', "original b\n");
    projectWriteJson($package.'/manifest.json', $manifest);
    file_put_contents($root.'/framework/core/classes/a.php', "patched a\n");
    projectAssert('patched', projectState($root, $package), 'Unchanged verification files must not count as unpatched.');
};
$tests['inspection rereads metadata and files'] = function ($root, $package, $manifest, $installed) {
    $project = new Project($root, $package);
    projectAssert('unpatched', $project->inspect()['packages']['fuel/core']['state'], 'Initial state must be original.');
    file_put_contents($root.'/framework/core/classes/a.php', "patched a\n");
    file_put_contents($root.'/framework/core/classes/b.php', "patched b\n");
    projectAssert('patched', $project->inspect()['packages']['fuel/core']['state'], 'A reused inspector must rehash source files.');
    $installed['packages'][0]['version'] = 'unknown';
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectAssert('unsupported', $project->inspect()['packages']['fuel/core']['state'], 'A reused inspector must reload metadata.');
};
$tests['invalid reports fail closed'] = function () {
    projectAssert(false, Project::isReady([]), 'Empty report must fail check.');
    projectAssert(true, Project::hasErrors([]), 'Empty report must report errors.');
    projectAssert(false, Project::isReady(['packages' => [['state' => 'unknown']]]), 'Unknown states must fail check.');
    projectAssert(true, Project::hasErrors(['packages' => [['state' => 'unknown']]]), 'Unknown states must report errors.');
};

$tests['Composer v-prefixed version matches its numeric release'] = function ($root, $package, $manifest, $installed) {
    $installed['packages'][0]['version'] = 'v1.8.2';
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    $report = (new Project($root, $package))->inspect();
    projectAssert('unpatched', $report['packages']['fuel/core']['state'], 'Composer tag prefixes must not hide a verified release.');
    projectAssert('v1.8.2', $report['packages']['fuel/core']['version'], 'Report must retain the installed version string.');
};
$tests['scalar source and distribution metadata are rejected'] = function ($root, $package, $manifest, $installed) {
    foreach (['source', 'dist'] as $kind) {
        $invalid = $installed;
        $invalid['packages'][0][$kind] = 'invalid';
        projectWriteJson($root.'/deps/composer/installed.json', $invalid);
        projectThrows(function () use ($root, $package) { (new Project($root, $package))->inspect(); }, 'Malformed reference metadata must fail.');
    }
};
$tests['JSON lists cannot replace metadata objects'] = function ($root, $package) {
    rename($root.'/deps', $root.'/vendor');
    projectWriteJson($root.'/composer.json', []);
    projectThrows(function () use ($root, $package) { (new Project($root, $package))->inspect(); }, 'composer.json must contain an object.');
};
$tests['installed packages must be a list'] = function ($root, $package, $manifest, $installed) {
    $installed['packages'] = ['fuel/core' => $installed['packages'][0]];
    projectWriteJson($root.'/deps/composer/installed.json', $installed);
    projectThrows(function () use ($root, $package) { (new Project($root, $package))->inspect(); }, 'Package entries must use the Composer 2 array format.');
};
$tests['null Composer configuration is rejected'] = function ($root, $package) {
    rename($root.'/deps', $root.'/vendor');
    projectWriteJson($root.'/composer.json', ['config' => null]);
    projectThrows(function () use ($root, $package) { (new Project($root, $package))->inspect(); }, 'A present config must be an object.');
};

$failed = 0;
foreach ($tests as $name => $test) {
    $base = sys_get_temp_dir().'/fuelphp-compat-project-'.bin2hex(random_bytes(6));
    mkdir($base);
    try {
        $test(...projectFixture($base));
        echo "PASS: $name\n";
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL: $name: ".$exception->getMessage()."\n");
    } finally {
        projectRemove($base);
    }
}
exit($failed === 0 ? 0 : 1);
