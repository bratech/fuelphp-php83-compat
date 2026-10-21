<?php

if (!is_file(__DIR__ . '/../src/Transaction.php')) {
    throw new RuntimeException('Transaction implementation is missing.');
}
require_once __DIR__ . '/../src/Project.php';
require_once __DIR__ . '/../src/Transaction.php';

use Bratech\FuelPhpCompat\Project;
use Bratech\FuelPhpCompat\Transaction;

function txAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function txRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                txRemove($path . '/' . $entry);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

function txFixture(): array
{
    $base = sys_get_temp_dir() . '/fuelphp-compat-test-' . bin2hex(random_bytes(8));
    $root = $base . '/project';
    $bundle = $base . '/bundle';
    mkdir($root . '/vendor/composer', 0777, true);
    mkdir($bundle . '/patches', 0777, true);
    file_put_contents($root . '/composer.json', '{}');
    $manifest = ['schema' => 1, 'release' => '1.0.0', 'packages' => []];
    $installed = [];
    foreach (['fuel/core' => ['a.txt', 'nested/b.txt'], 'fuel/email' => ['email.txt']] as $name => $files) {
        $path = $root . '/components/' . basename($name);
        mkdir($path, 0777, true);
        $hashes = [];
        $patch = '';
        foreach ($files as $file) {
            if (!is_dir(dirname($path . '/' . $file))) {
                mkdir(dirname($path . '/' . $file), 0777, true);
            }
            file_put_contents($path . '/' . $file, "before\n");
            chmod($path . '/' . $file, 0640);
            $hashes[$file] = ['before' => hash('sha256', "before\n"), 'after' => hash('sha256', "after\n")];
            $patch .= "diff --git a/$file b/$file\n--- a/$file\n+++ b/$file\n@@ -1 +1 @@\n-before\n+after\n";
        }
        $patchPath = 'patches/' . basename($name) . '.patch';
        file_put_contents($bundle . '/' . $patchPath, $patch);
        $manifest['packages'][$name] = [
            'required' => $name === 'fuel/core',
            'variants' => ['1.8.2' => [
                'reference' => str_repeat('a', 40),
                'mode' => 'patch',
                'patch' => $patchPath,
                'patch_sha256' => hash('sha256', $patch),
                'files' => $hashes,
            ]],
        ];
        $installed[] = [
            'name' => $name,
            'version' => '1.8.2',
            'source' => ['type' => 'git', 'reference' => str_repeat('a', 40)],
            'install-path' => '../../components/' . basename($name),
        ];
    }
    file_put_contents($bundle . '/manifest.json', json_encode($manifest));
    file_put_contents($root . '/vendor/composer/installed.json', json_encode(['packages' => $installed]));
    return [$base, $root, $bundle];
}

function txFails(callable $operation, string $contains = ''): string
{
    try {
        $operation();
    } catch (RuntimeException $error) {
        txAssert($contains === '' || stripos($error->getMessage(), $contains) !== false, $error->getMessage());
        return $error->getMessage();
    }
    throw new RuntimeException('Operation unexpectedly succeeded.');
}

class FailingTransaction extends Transaction
{
    private $calls = 0;
    public $failRollback = false;

    /**
     * Inject a replacement failure without changing staging or backup behavior.
     *
     * @param string $path Absolute source file passed by the transaction.
     * @param string $content Replacement or rollback bytes.
     * @param int $mode Original permission bits.
     * @return void
     * @throws RuntimeException On the second write, or later rollback if enabled.
     */
    protected function replaceFile(string $path, string $content, int $mode): void
    {
        $this->calls++;
        if ($this->calls === 2 || ($this->failRollback && $this->calls > 2)) {
            throw new RuntimeException('Injected write failure');
        }
        parent::replaceFile($path, $content, $mode);
    }
}

class ConcurrentChangeTransaction extends Transaction
{
    private $calls = 0;
    private $firstPath;

    /**
     * Simulate an external edit after the first successful replacement.
     *
     * @param string $path Absolute source file passed by the transaction.
     * @param string $content Replacement or rollback bytes.
     * @param int $mode Original permission bits.
     * @return void
     * @throws RuntimeException After the injected external edit on the second write.
     */
    protected function replaceFile(string $path, string $content, int $mode): void
    {
        $this->calls++;
        if ($this->calls === 1) {
            $this->firstPath = $path;
        }
        if ($this->calls === 2) {
            file_put_contents($this->firstPath, "external edit\n");
            throw new RuntimeException('Injected concurrent edit');
        }
        parent::replaceFile($path, $content, $mode);
    }
}

class ChangedUnselectedPackageTransaction extends Transaction
{
    private $calls = 0;
    public $externalPath;
    public $externalContent;

    /**
     * Change a package excluded from staging while the first selected file is written.
     *
     * @param string $path Absolute source file selected by the transaction.
     * @param string $content Replacement or rollback bytes.
     * @param int $mode Original permission bits.
     * @return void
     * @throws RuntimeException If the normal replacement fails.
     */
    protected function replaceFile(string $path, string $content, int $mode): void
    {
        if (++$this->calls === 1) {
            file_put_contents($this->externalPath, $this->externalContent);
        }
        parent::replaceFile($path, $content, $mode);
    }
}

function txRunTests(): void
{
    $tests = [];
    $tests['apply, idempotence, reverse and permissions'] = function ($root, $bundle) {
        $transaction = new Transaction(new Project($root, $bundle), $bundle);
        txAssert(Project::isReady($transaction->run('apply')), 'Apply report must be ready.');
        txAssert(file_get_contents($root . '/components/core/a.txt') === "after\n", 'Patch must change real source.');
        clearstatcache();
        txAssert((fileperms($root . '/components/core/a.txt') & 0777) === 0640, 'Permissions changed.');
        txAssert(Project::isReady($transaction->run('apply')), 'Repeated apply failed.');
        txAssert($transaction->run('reverse')['packages']['fuel/core']['state'] === 'unpatched', 'Reverse failed.');
        $transaction->run('reverse');
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Reverse must restore source.');
        txAssert(glob($root . '/.fuelphp-compat-work-*') === [], 'Successful work directory was retained.');
    };
    $tests['all packages preflight before writes'] = function ($root, $bundle) {
        file_put_contents($root . '/components/email/email.txt', "custom\n");
        txFails(function () use ($root, $bundle) {
            (new Transaction(new Project($root, $bundle), $bundle))->run('apply');
        });
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Earlier package changed before rejection.');
    };
    $tests['partial package rejected'] = function ($root, $bundle) {
        file_put_contents($root . '/components/core/a.txt', "after\n");
        txFails(function () use ($root, $bundle) {
            (new Transaction(new Project($root, $bundle), $bundle))->run('apply');
        });
        txAssert(file_get_contents($root . '/components/email/email.txt') === "before\n", 'Partial package allowed writes.');
    };
    $tests['known mixed package states'] = function ($root, $bundle) {
        file_put_contents($root . '/components/email/email.txt', "after\n");
        txAssert(Project::isReady((new Transaction(new Project($root, $bundle), $bundle))->run('apply')), 'Mixed known states rejected.');
    };
    $tests['concurrent lock rejected'] = function ($root, $bundle) {
        $lock = fopen($root . '/.fuelphp-compat.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            txFails(function () use ($root, $bundle) {
                (new Transaction(new Project($root, $bundle), $bundle))->run('apply');
            }, 'lock');
        } finally {
            fclose($lock);
        }
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Lock conflict changed source.');
    };
    $tests['symlink lock rejected'] = function ($root, $bundle) {
        symlink($root . '/components/core/a.txt', $root . '/.fuelphp-compat.lock');
        txFails(function () use ($root, $bundle) {
            (new Transaction(new Project($root, $bundle), $bundle))->run('apply');
        }, 'lock');
    };
    $tests['Git missing stops before writes'] = function ($root, $bundle) {
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        try {
            txFails(function () use ($root, $bundle) {
                (new Transaction(new Project($root, $bundle), $bundle))->run('apply');
            }, 'git');
        } finally {
            putenv('PATH=' . $path);
        }
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Missing Git allowed writes.');
    };
    $tests['patch digest checked'] = function ($root, $bundle) {
        file_put_contents($bundle . '/patches/core.patch', "invalid\n");
        txFails(function () use ($root, $bundle) {
            (new Transaction(new Project($root, $bundle), $bundle))->run('apply');
        });
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Invalid patch changed source.');
    };
    $tests['staged result digest checked'] = function ($root, $bundle) {
        $patch = str_replace('+after', '+unexpected', file_get_contents($bundle . '/patches/email.patch'));
        file_put_contents($bundle . '/patches/email.patch', $patch);
        $manifest = json_decode(file_get_contents($bundle . '/manifest.json'), true);
        $manifest['packages']['fuel/email']['variants']['1.8.2']['patch_sha256'] = hash('sha256', $patch);
        file_put_contents($bundle . '/manifest.json', json_encode($manifest));
        txFails(function () use ($root, $bundle) {
            (new Transaction(new Project($root, $bundle), $bundle))->run('apply');
        });
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Writes began before all staging checks.');
    };
    $tests['failed replacement rolls back'] = function ($root, $bundle) {
        txFails(function () use ($root, $bundle) {
            (new FailingTransaction(new Project($root, $bundle), $bundle))->run('apply');
        }, 'Injected');
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Rollback did not restore earlier file.');
        txAssert(file_get_contents($root . '/components/core/nested/b.txt') === "before\n", 'Failed file changed.');
        txAssert(glob($root . '/.fuelphp-compat-work-*') === [], 'Recovered transaction retained workspace.');
    };
    $tests['failed rollback retains recoverable backup'] = function ($root, $bundle) {
        $transaction = new FailingTransaction(new Project($root, $bundle), $bundle);
        $transaction->failRollback = true;
        $message = txFails(function () use ($transaction) {
            $transaction->run('apply');
        }, 'rollback');
        $work = glob($root . '/.fuelphp-compat-work-*');
        txAssert(count($work) === 1, 'Rollback failure discarded recovery files.');
        txAssert(strpos($message, $work[0]) !== false, 'Recovery location missing from error.');
        txAssert(is_file($work[0] . '/recovery.json'), 'Recovery file mapping missing.');
        $recovery = json_decode(file_get_contents($work[0] . '/recovery.json'), true);
        txAssert(count($recovery) >= 2, 'Recovery mapping incomplete.');
        foreach ($recovery as $entry) {
            txAssert(file_get_contents($entry['backup']) === "before\n", 'Recovery backup is not original source.');
        }
    };
    $tests['rollback preserves concurrent unknown edits'] = function ($root, $bundle) {
        txFails(function () use ($root, $bundle) {
            (new ConcurrentChangeTransaction(new Project($root, $bundle), $bundle))->run('apply');
        }, 'rollback');
        txAssert(file_get_contents($root . '/components/core/a.txt') === "external edit\n", 'Rollback overwrote a concurrent unknown edit.');
        txAssert(count(glob($root . '/.fuelphp-compat-work-*')) === 1, 'Concurrent edit lost recovery backups.');
    };
    $tests['symlink patch rejected'] = function ($root, $bundle) {
        rename($bundle . '/patches/core.patch', $bundle . '/core-original.patch');
        symlink($bundle . '/core-original.patch', $bundle . '/patches/core.patch');
        txFails(function () use ($root, $bundle) {
            (new Transaction(new Project($root, $bundle), $bundle))->run('apply');
        }, 'symlink');
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Symlink patch changed source.');
    };
    $tests['release update requires old reverse before new apply'] = function ($root, $bundle) {
        $old = new Transaction(new Project($root, $bundle), $bundle);
        $old->run('apply');
        $next = dirname($bundle) . '/next';
        mkdir($next . '/patches', 0777, true);
        $manifest = json_decode(file_get_contents($bundle . '/manifest.json'), true);
        $manifest['release'] = '1.1.0';
        foreach ($manifest['packages'] as &$package) {
            $variant =& $package['variants']['1.8.2'];
            $patch = str_replace('+after', '+next', file_get_contents($bundle . '/' . $variant['patch']));
            file_put_contents($next . '/' . $variant['patch'], $patch);
            $variant['patch_sha256'] = hash('sha256', $patch);
            foreach ($variant['files'] as &$hashes) {
                $hashes['after'] = hash('sha256', "next\n");
            }
            unset($hashes, $variant);
        }
        unset($package);
        file_put_contents($next . '/manifest.json', json_encode($manifest));
        $updated = new Transaction(new Project($root, $next), $next);
        txFails(function () use ($updated) {
            $updated->run('apply');
        });
        txAssert(file_get_contents($root . '/components/core/a.txt') === "after\n", 'New release modified old patched source.');
        $old->run('reverse');
        txAssert(Project::isReady($updated->run('apply')), 'New release failed after old reverse.');
        txAssert(file_get_contents($root . '/components/core/a.txt') === "next\n", 'New release bytes incorrect.');
    };
    $tests['malformed patch with valid digest rejected'] = function ($root, $bundle) {
        $patch = "this is not a Git patch\n";
        file_put_contents($bundle . '/patches/email.patch', $patch);
        $manifest = json_decode(file_get_contents($bundle . '/manifest.json'), true);
        $manifest['packages']['fuel/email']['variants']['1.8.2']['patch_sha256'] = hash('sha256', $patch);
        file_put_contents($bundle . '/manifest.json', json_encode($manifest));
        txFails(function () use ($root, $bundle) {
            (new Transaction(new Project($root, $bundle), $bundle))->run('apply');
        }, 'git');
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Invalid later patch allowed earlier writes.');
    };
    $tests['inherited Git repository settings cannot redirect staging'] = function ($root, $bundle) {
        $names = ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE'];
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = getenv($name);
            putenv($name . '=' . $root . '/must-not-be-used');
        }
        try {
            txAssert(Project::isReady((new Transaction(new Project($root, $bundle), $bundle))->run('apply')), 'Inherited Git variables broke staging.');
            txAssert(!file_exists($root . '/must-not-be-used'), 'Git wrote to an inherited repository path.');
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    };
    $tests['apply rejects reverted unselected package at final inspection'] = function ($root, $bundle) {
        $email = $root . '/components/email/email.txt';
        file_put_contents($email, "after\n");
        $transaction = new ChangedUnselectedPackageTransaction(new Project($root, $bundle), $bundle);
        $transaction->externalPath = $email;
        $transaction->externalContent = "before\n";
        txFails(function () use ($transaction) {
            $transaction->run('apply');
        }, 'Final');
        txAssert(file_get_contents($root . '/components/core/a.txt') === "before\n", 'Failed apply did not roll back its own files.');
        txAssert(file_get_contents($email) === "before\n", 'Failed apply overwrote an external package change.');
    };
    $tests['reverse rejects applied unselected package at final inspection'] = function ($root, $bundle) {
        file_put_contents($root . '/components/core/a.txt', "after\n");
        file_put_contents($root . '/components/core/nested/b.txt', "after\n");
        $email = $root . '/components/email/email.txt';
        $transaction = new ChangedUnselectedPackageTransaction(new Project($root, $bundle), $bundle);
        $transaction->externalPath = $email;
        $transaction->externalContent = "after\n";
        txFails(function () use ($transaction) {
            $transaction->run('reverse');
        }, 'Final');
        txAssert(file_get_contents($root . '/components/core/a.txt') === "after\n", 'Failed reverse did not roll back its own files.');
        txAssert(file_get_contents($email) === "after\n", 'Failed reverse overwrote an external package change.');
    };
    foreach ($tests as $name => $test) {
        [$base, $root, $bundle] = txFixture();
        try {
            $test($root, $bundle);
            echo "PASS: $name\n";
        } finally {
            txRemove($base);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    txRunTests();
}
