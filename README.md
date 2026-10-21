# FuelPHP PHP 8.3 compatibility set

[日本語](docs/README.ja.md)

`bratech/fuelphp-php83-compat` distributes compatibility patches and a PHP CLI for selected FuelPHP 1.8.2 dependencies. It checks the installed version, source reference and file SHA-256 hashes before applying or reversing changes. The compatibility release number is independent of FuelPHP's version.

**Release 1.0.0 is UNRELEASED.** The `main` branch is available on GitHub, and Composer installation using `dev-main` has been verified on PHP 7.4 and 8.3. No release tag has been published. The `1.0.0` installation example below is for use after that tag is published; installation from a release tag remains unverified.

## Requirements

- PHP `^7.4 || ~8.3.0`, Composer 2 and Git available to the CLI.
- ICU and PHP `ext-intl` enabled in both CLI and Web runtimes. The CLI can check only its own runtime. There is no fallback without ICU.
- An existing Composer project with `fuel/core` 1.8.2 and the exact supported sources listed in [manifest.json](manifest.json).
- A writable project whose target package directories and files are inside the project and are not symbolic links.

Consumers do not need Python. This is a library with an explicit CLI, not a Composer plugin. It does not bootstrap the application during inspection or install optional Fuel packages.

The PHP constraint covers the 7.4 and 8.3 series; PHP 8.0–8.2 and 8.4+ are not declared supported. Review the application's own PHP requirement and `config.platform.php`. This package does not change upstream constraints or make an incompatible dependency resolution valid.

## Supported packages

| Package | Version | Handling |
| --- | --- | --- |
| `fuel/core` | 1.8.2 | Required; patches 21 files |
| `fuel/email` | 1.8.2 | Patches 1 file when installed |
| `fuel/oil` | 1.8.2 | Patches 3 files when installed |
| `fuel/orm` | 1.8.2 | Patches 1 file when installed |
| `fuel/parser` | 1.8.2 | Patches 1 file when installed |
| `fuelphp/upload` | 2.0.6 | Patches 2 files when installed |
| `fuelphp/upload` | 2.0.10 | Checks known official sources; applies no patch |
| `michelf/php-markdown` | 1.7.0 | Patches 2 files when installed |

These are seven packages and eight supported package/version combinations. Other versions of these packages are rejected. Packages outside this table are not assessed. An absent optional package has JSON state `not_installed` and is not added. Upload 2.0.10's `upstream` state refers to the checked Upload sources, not to all FuelPHP functionality.

## Installation after publication

First read [behavior changes](docs/BEHAVIOR_CHANGES.md), especially the Model_Crud serialization and database result changes. Back up code, dependency locks and persistent data, then stop Web requests, workers and scheduled CLI tasks during changes.

Merge the following entries into the application's root `composer.json`. Keep existing requirements, repositories and scripts. This example assumes Composer's default `vendor` directory and binary location; adjust those paths for your project.

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/bratech/fuelphp-php83-compat"
    }
  ],
  "require": {
    "bratech/fuelphp-php83-compat": "1.0.0",
    "ext-intl": "*"
  },
  "scripts": {
    "post-install-cmd": [
      "@php vendor/bin/fuelphp-compat apply",
      "@php vendor/bin/fuelphp-compat check",
      "@php oil r install"
    ],
    "post-update-cmd": [
      "@php vendor/bin/fuelphp-compat apply",
      "@php vendor/bin/fuelphp-compat check"
    ]
  }
}
```

The `oil r install` line represents an existing application install hook: retain it only if your project already requires it. Place `apply` and `check` before that hook and before any other hook that loads Fuel. Do not add Oil to `post-update-cmd` merely because it appears in `post-install-cmd`. Review earlier Composer events separately; post-install hooks cannot protect code run before dependency installation finishes.

For a first installation into supported pristine dependencies, resolve and review the lock in a development copy:

```sh
composer update bratech/fuelphp-php83-compat --with-dependencies --no-scripts
php vendor/bin/fuelphp-compat status
php vendor/bin/fuelphp-compat apply
php vendor/bin/fuelphp-compat check
composer check-platform-reqs
```

After `check` succeeds, run the application's existing install tasks if required, then its regression tests. Commit the application's reviewed `composer.json` and `composer.lock`. Use `composer install` for subsequent builds from that lock. Dependencies that already contain an older patch need the [update procedure](docs/OPERATIONS.md#updating-the-compatibility-set-or-upstream-dependencies) before any Composer update.

With scripts enabled, the root hooks above run after installation or update. With `--no-scripts`, run `apply` and `check` explicitly before Oil or application execution; installing this package alone does not patch dependencies. A failed `check` must stop deployment.

## Commands

Run from the application's root, or pass `--root=path`. `--json` is available only for read operations.

```sh
php vendor/bin/fuelphp-compat status --root=.
php vendor/bin/fuelphp-compat check --root=. --json
php vendor/bin/fuelphp-compat apply --root=.
php vendor/bin/fuelphp-compat reverse --root=.
```

`status` accepts known pristine sources and reports their state. `check` succeeds only when every installed target is patched or verified as upstream. Both reject unsupported or inconsistent sources. Repeating `apply` or `reverse` does not change files already in the requested state. Every operation checks actual source hashes; it does not rely on an earlier success marker.

Exit codes are `0` for success, `1` for inspection or operation failure, and `2` for invalid commands or arguments. [Operations](docs/OPERATIONS.md) explains state handling, rollback and recovery.

## Compatibility scope and security

The underlying patch set was exercised on PHP 7.4.33 and 8.3.24 with ICU 67.1, using MySQLi/MariaDB, PDO MySQL/MariaDB and PDO SQLite. This does not cover every FuelPHP API or an application's custom code. See [verification scope](docs/VERIFICATION.md) for the tested configurations, exclusions and the distinction between patch validation and distribution validation.

The recorded dependency audit on 2026-10-21 retained one medium phpseclib advisory and exited with code 1. The tested Fuel encryption paths did not reach the reported implementation; this is not a security guarantee for phpseclib or other application paths. See [the audit scope](docs/VERIFICATION.md#dependency-audit).

## Licensing

New tool code and documentation are licensed under [MIT](LICENSE). Patch portions derived from FuelPHP and Upload retain MIT terms; PHP Markdown portions retain BSD-3-Clause terms. See [NOTICE](NOTICE) and [LICENSES](LICENSES). Upstream libraries themselves are obtained through the application's dependency installation.
