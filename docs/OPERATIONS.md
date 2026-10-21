# Installation, updates and recovery

This document describes compatibility release 1.0.0. Commands assume the application's root directory and Composer's default binary path. Use the actual binary path and `--root=path` when your layout differs.

## Before changing dependencies

Keep the application code, `composer.json`, `composer.lock`, compatibility package and persistent-data backups together as a recoverable deployment. Check the [supported versions](../README.md#supported-packages), [behavior changes](BEHAVIOR_CHANGES.md) and the application's subclass overrides before choosing a target lock.

Enable ICU and `ext-intl` in Web and CLI PHP. The tool's extension check does not inspect PHP-FPM, Apache or other PHP installations. Verify their loaded configuration separately. Run `composer check-platform-reqs` in the actual deployment runtime; a `config.platform.php` value is not proof that the runtime meets its dependencies.

Stop Web requests, queue workers, scheduled jobs and other processes that load the target files. The CLI uses the persistent `.fuelphp-compat.lock` in the project root to lock concurrent operations by this tool, but does not stop the application or arbitrary external writers. File replacement across several files is not an atomic OS transaction.

Add the tool's runtime files to the application's `.gitignore`:

```gitignore
/.fuelphp-compat.lock
/.fuelphp-compat-work-*/
```

Ignoring a recovery directory does not authorize deleting it after a failure. Retain its backups until recovery is complete. If dependencies are tracked in the application's Git repository, applying patches changes those tracked files; a successful check does not mean the working tree is clean.

## First installation and Composer hooks

Add the VCS repository and exact release requirement shown in the [README](../README.md#installation). Preserve the application's existing scripts. In `post-install-cmd`, place compatibility `apply` and `check` before an existing `oil r install` and other Fuel-loading tasks. Add `apply` and `check` to `post-update-cmd` without adding installation tasks that were not already intended to run on updates.

Composer runs the root project's scripts. Scripts defined only in an installed library do not supply this integration. If deployment disables scripts, its build stage must run the commands explicitly:

```sh
composer install --no-scripts
php vendor/bin/fuelphp-compat apply
php vendor/bin/fuelphp-compat check
composer check-platform-reqs
```

Only after all commands succeed should the build run any existing Oil installation task and application tests. A failed check must fail the build. Review hooks that run before `post-install-cmd` or `post-update-cmd`; any earlier application bootstrap would precede patch application.

The CLI reads the root `composer.json` and Composer 2's `composer/installed.json`, using `vendor-dir` and each package's `install-path` to locate targets. It does not assume a fixed Fuel directory and does not execute the unpatched dependency code. A path repository pointing outside the project, or a symbolic link in a target path, is refused.

## Reading states

```sh
php vendor/bin/fuelphp-compat status --json
php vendor/bin/fuelphp-compat check --json
```

| State | Meaning | Effect |
| --- | --- | --- |
| `unpatched` | All recorded target files match the known original hashes | `status` succeeds; `check` fails; `apply` can change it |
| `patched` | All recorded target files match this release's patched hashes | `check` accepts it; `reverse` can change it |
| `upstream` | The supported official version needs no patch and its recorded files match | Accepted; never changed by apply/reverse |
| `not_installed` | An optional target package is absent | Accepted; never installed by the tool |
| `unsupported` | Installed version or source reference is unsupported | Inspection fails; no writes |
| `modified` | Source contents do not match known hashes | Inspection fails; no writes |
| `partial` | One package contains both original and patched target files | Inspection fails; no writes |
| `missing` | A required package or target file is missing | Inspection fails; no writes |
| `unsafe` | A target location violates the path restrictions | Inspection fails; no writes |

Project metadata errors can stop inspection before package states are produced. A mixture of fully patched packages and fully pristine packages is accepted by `apply`; it changes only the pristine packages. A mixture within one package is rejected. Files outside the manifest are not a compatibility assessment of the rest of the application.

`status` and `check` read without applying patches. `check` means ready for patched operation, not ready for reversal. Therefore, after a successful reversal, `check` is expected to fail on `unpatched` packages; use `status` to verify that reversal restored known originals.

## Applying and reversing

```sh
php vendor/bin/fuelphp-compat apply
php vendor/bin/fuelphp-compat check
```

Before writing targets, the tool inspects every installed target, stages patch application with Git and checks the result against the manifest. An unknown modification or unsupported version anywhere stops the operation before target files are changed. Do not edit hashes or force a patch over local modifications. Preserve the modified source and review how to port those changes separately.

```sh
php vendor/bin/fuelphp-compat reverse
php vendor/bin/fuelphp-compat status --json
```

After reversal, confirm that patchable packages are `unpatched` and Upload 2.0.10 remains `upstream`, when installed. Reversal restores known original target bytes; it does not restore application files, a dependency lock, a database, sessions or caches. It is not a procedure for running unpatched FuelPHP on PHP 8.3. Even unpatched PHP 7.4 can produce pre-existing deprecation diagnostics.

Repeated application or reversal is a no-op once the requested state is present. Upload 2.0.10 is always a no-op after source verification.

## Updating the compatibility set or upstream dependencies

Keep the old compatibility package, its manifest and patches until reversal and verification have finished. A new package cannot be assumed to recognize old patched hashes, including changes that only translated comments.

1. Stop all application execution and back up the current deployment and data. Record the old tool's `status --json`; resolve any unknown changes before continuing.
2. With the old tool still installed, run `reverse`, then run that same tool's `status --json`. Verify `unpatched` for every installed patchable package and `upstream` for any official no-patch version. This checks the restored files against the old manifest's original hashes. Do not use a failing old `check` as a reversal failure: `check` requires patches to be present.
3. Change the compatibility requirement or upstream requirements and resolve the reviewed target lock with Composer scripts disabled. Keep the old package outside the replacement deployment if it is needed for recovery. Do not run application code in the intermediate unpatched state.
4. Use the newly installed tool to run `status`, `apply`, then `check`. An upstream version absent from its manifest must be reviewed and supported in a new compatibility release before use.
5. Check platform requirements and run the application's tests, including persistence and subclass behavior. Resume traffic only after the final code and data agree.

To roll back an upgrade, reverse with the new tool while it is still present, verify the new originals, restore the old dependency lock and compatibility package, then apply and check using the old tool. Restoring code does not undo data changes. If the update only changes comments and no unpatched code runs in between, that comment change alone does not require session or cache invalidation.

## Failed writes and interruption

If replacement fails, the tool attempts to restore files already changed in that operation. A restoration failure remains an error; preserve the backup/work area and the paths reported by the CLI. Keep the application stopped, save the diagnostics, and compare every target against the appropriate manifest before deciding how to recover. The private `.fuelphp-compat-work-<hex>` directory contains backups and `recovery.json`, which records target paths, backup paths and modes. Successful operations or successful rollback remove this work directory; failed rollback retains it. Do not delete recovery files merely to make another invocation run, and do not unlink a live `.fuelphp-compat.lock`.

Abrupt process termination, host failure or storage failure can leave a partial deployment. Use a known deployment backup or a clean dependency install from the saved lock and then apply the matching compatibility release. Reinspect with `status` and `check` before resuming execution. The lock prevents overlapping tool operations; it does not make multi-file replacement crash-atomic.

## Model_Crud data when removing patches

PHP's `serialize($model)` changes from Serializable's C format to the magic-method O format. Patched PHP 7.4 and 8.3 read both forms. Unpatched PHP 7.4 cannot read the new O form. Merely changing PHP 8.3 to patched PHP 7.4 does not require removing patches.

| Writer and stored form | Unpatched PHP 7.4.33 | Patched PHP 7.4.33 | Patched PHP 8.3.24 |
| --- | --- | --- | --- |
| Unpatched PHP 7.4.33, C form | Reads | Reads | Reads |
| Patched PHP 7.4.33, O form | Fails | Reads | Reads |
| Patched PHP 8.3.24, O form | Fails | Reads | Reads |

This matrix covers the standard tested model. It does not validate every custom Serializable subclass or restoring into unpatched PHP 8.3. Calling the model's `serialize()` method directly retains its existing string format.

Before removing patches, find model objects in sessions, cached arrays and objects, and persistent database/file values. Stop all writers before restoring code or data. Choose the operation per storage type:

| Storage | Recovery before running unpatched code | Consequence |
| --- | --- | --- |
| Sessions containing models | Invalidate the affected sessions and reissue cookies/server state using the application's procedure | Users may be logged out and lose unfinished input |
| Regenerable caches | Invalidate only the affected keys or namespace and regenerate using the restored code | Account for regeneration load |
| Persistent models or non-regenerable caches | Restore a pre-patch backup readable by old code, preserving and reconciling later updates separately | A backup restore can discard later changes |

Identify the application's actual keys and paths. Do not delete an entire shared directory or database to invalidate sessions. Test restoration and required writes in a disposable copy, then confirm session issuance, cache regeneration and persistent reads before reopening the application.

If only new O-form persistent data remains and there is no usable pre-patch backup, reversal alone cannot recover it. Keep a patched PHP environment available to read and export it using an application-specific migration. Replacing the `O` marker with `C` in serialized text is not a conversion.

Custom `serialize()`/`unserialize()` implementations can lose shared-reference identity; recursive reentry is rejected with `RuntimeException`. Review custom `__serialize()`/`__unserialize()` implementations and their handling of older stored forms. The existing `_is_new` restoration issue remains: test whether a restored model will insert or update when saved.
