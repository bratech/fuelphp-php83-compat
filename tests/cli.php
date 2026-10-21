<?php

require_once __DIR__ . '/transaction.php';

function cliRun(string $binary, array $arguments, string $directory, bool $noExtensions = false): array
{
    $command = [PHP_BINARY];
    if ($noExtensions) {
        $command[] = '-n';
    }
    $command[] = $binary;
    $process = proc_open(array_merge($command, $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

[$base, $root, $bundle] = txFixture();
try {
    mkdir($bundle . '/src');
    mkdir($bundle . '/bin');
    foreach (['src/Project.php', 'src/Transaction.php', 'bin/fuelphp-compat'] as $file) {
        txAssert(is_file(__DIR__ . '/../' . $file), 'Missing implementation: ' . $file);
        copy(__DIR__ . '/../' . $file, $bundle . '/' . $file);
    }
    $binary = $bundle . '/bin/fuelphp-compat';
    foreach ([[], ['unknown'], ['status', '--bad'], ['apply', '--json'], ['status', '--root'], ['status', '--root='], ['status', '--root=x', '--root=y']] as $arguments) {
        [$code, $out, $err] = cliRun($binary, $arguments, $root);
        txAssert($code === 2, 'Invalid arguments must exit 2: ' . json_encode($arguments) . ': ' . $out . $err);
        txAssert(strpos($out . $err, '1.0.0') !== false, 'Error omits compatibility release.');
    }
    [$code, $out, $err] = cliRun($binary, ['status', '--json'], $root);
    txAssert($code === 0 && $err === '', 'Known unpatched status must succeed: ' . $out . $err);
    $report = json_decode($out, true);
    txAssert($report['release'] === '1.0.0' && $report['packages']['fuel/core']['state'] === 'unpatched', 'Invalid JSON status.');
    [$code] = cliRun($binary, ['check', '--root', $root, '--json'], $base);
    txAssert($code === 1, 'Unpatched check must fail.');
    foreach (['apply', 'apply', 'check'] as $command) {
        [$code, $out, $err] = cliRun($binary, [$command, '--root=' . $root], $base);
        txAssert($code === 0, $command . ' failed: ' . $out . $err);
        txAssert(strpos($out, 'Compatibility release: 1.0.0') !== false && strpos($out, 'fuel/core') !== false, 'Text report incomplete.');
    }
    foreach (['reverse', 'reverse', 'status'] as $command) {
        [$code, $out, $err] = cliRun($binary, [$command], $root);
        txAssert($code === 0, $command . ' failed: ' . $out . $err);
    }
    [$code] = cliRun($binary, ['check'], $root);
    txAssert($code === 1, 'Reversed check must fail.');
    $installedPath = $root . '/vendor/composer/installed.json';
    $installed = json_decode(file_get_contents($installedPath), true);
    array_pop($installed['packages']);
    file_put_contents($installedPath, json_encode($installed));
    [$code, $out] = cliRun($binary, ['status', '--json'], $root);
    txAssert($code === 0 && json_decode($out, true)['packages']['fuel/email']['state'] === 'not_installed', 'Optional absence rejected.');
    file_put_contents($root . '/components/core/a.txt', "modified\n");
    [$code, $out] = cliRun($binary, ['status', '--json'], $root);
    txAssert($code === 1 && json_decode($out, true)['packages']['fuel/core']['state'] === 'modified', 'Modified status must fail.');
    [$code, $out, $err] = cliRun($binary, ['apply'], $root);
    txAssert($code === 1 && strpos($out . $err, '1.0.0') !== false, 'Transaction failure exit/report incorrect.');
    [$code, $out] = cliRun($binary, ['status', '--json', '--root=' . $base . '/missing'], $root);
    txAssert($code === 1 && json_decode($out, true)['release'] === '1.0.0', 'JSON configuration error is invalid.');
    [$code, $out, $err] = cliRun($binary, ['status'], $root, true);
    txAssert($code === 1 && strpos($out . $err, 'intl') !== false, 'Missing intl must fail before project inspection.');
    echo "PASS: CLI arguments, reports, states, operations, and intl requirement\n";
} finally {
    txRemove($base);
}
