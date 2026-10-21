# Verification scope

## Release validation

This document records the tests used to prepare compatibility release 1.0.0. The distribution checks and underlying patch tests below passed on 2026-10-21, including GitHub VCS retrieval using `dev-main`. These branch-based results were recorded before tagging `v1.0.0`.

## Distribution checks

On both PHP 7.4.33 and 8.3.24, the inspector passed 38 cases, the transaction handler passed 18 cases, and the CLI scenario suite passed. Syntax checks and Composer metadata validation also passed. The tests cover unknown versions and hashes, partial application, path and symlink rejection, malformed configuration, idempotence, lock contention, missing Git, invalid patches, failed writes, failed restoration, concurrent unknown edits and old-release reversal before a new set is applied.

All seven generated patches were applied to the verified originals. The resulting 31 files matched the existing patched-source SHA-256 hashes. The five official Upload 2.0.10 source files were checked separately without a patch.

Composer 2.8.1 installation was tested in four disposable projects: both PHP versions with standard paths and Upload 2.0.6, and both versions with changed vendor/installer paths, Upload 2.0.10 and no email package. Composer fetched this package from its local Git repository; upstream dependencies came from local ZIP archives of the fixed sources. That initial pass checked local VCS behavior. After the public branch was pushed, the same four configurations passed using `https://github.com/bratech/fuelphp-php83-compat` as the VCS repository and `dev-main` as the requirement. Composer downloaded this package from GitHub without credentials; all 27 package files matched commit `6590c49d72ada57d9f12a3b7a66c686507794af9`. The published documentation was then updated to record these results; implementation and patches were unchanged. Upstream dependencies still came from the fixed local ZIP archives, so this was not a fresh-download test of every upstream dependency or a new dependency audit.

Each project exercised script-disabled update, status/check, apply, repeated apply, reverse and repeated reverse, followed by a clean install from its lock. The install hooks ran apply and check before the existing Oil install task. The alternate layout also passed Oil startup and 28 Upload checks per PHP version.

The standard-layout projects ran the existing 20 HTTP and 20 direct-API cases, database 24, MySQLi result 68, PDO result 118, Model_Crud 46, Security 12, core boundary 15, date formatting 24, parsing 65, system-timezone 13 and Upload 28 checks per PHP version. Their main database used PDO SQLite; dedicated MySQLi/PDO MySQL checks used MariaDB. This distribution pass did not repeat the entire twelve-run matrix described below.

Independent review found two issues that were reproduced and fixed before the final local installation pass: malformed Composer configuration could fall back to default paths, and final transaction checks covered only selected packages. Invalid configuration now fails, and final verification covers every installed target. Failures still restore only files changed by that transaction.

## Underlying patch tests

The recorded environment was Linux/glibc, PHP 7.4.33 and 8.3.24, and ICU 67.1. The following six PHP/database combinations ran twice each, for twelve complete runs:

| Fuel connection | Database | Confirmed scope |
| --- | --- | --- |
| `type=mysqli` | MariaDB 10.11.19 | Major HTTP/API, CRUD, Query Builder, ORM belongs_to, authentication/ACL, browser, transactions and exception cases |
| `type=pdo`, MySQL DSN | MariaDB 10.11.19 | Same major suite, plus explicit PDO attributes and fetched types |
| `type=pdo`, SQLite DSN | SQLite 3.34.1 | Same major suite, plus non-cached row counts, repeated iteration and cache conversion |

PHP-to-PHP and repeat-run comparisons passed for those configurations. Database-product comparisons allowed only the fixed expected ID difference after a rolled-back insert, described in [behavior changes](BEHAVIOR_CHANGES.md#database-results-and-memory). Oracle MySQL was not the database used for the MySQL-family connections.

Each full run included 20 HTTP cases, 20 direct-API cases, 46 Model_Crud cases, 12 Security callable cases, 10 Oil cases, and five local SMTP failure/authentication cases. Dedicated result tests checked 68 MySQLi items and 118 PDO MySQL/SQLite items. Date differential checks compared 447,120 cases: 447,070 matched and 50 were fixed accepted differences, with no unresolved cases in that corpus. The date parser's four specific differences and the era-format changes are documented separately; dates are not excluded wholesale from comparison.

Separate sanitizer comparison covered 1,212,677 cases against PHP 7.4's original filter. A selected upstream-test run passed 56 tests with 59 assertions plus four ported Crypt tests on each PHP version. This was not the entire FuelPHP upstream suite. Serialization migration tests confirmed seven successful and two expected failing combinations in the [storage matrix](OPERATIONS.md#model_crud-data-when-removing-patches).

Upload 2.0.6 with patches and official 2.0.10 each passed two HTTP tests and 28 public-API checks on each PHP version. HTTP checks included valid content, prohibited extensions, zero bytes, no selection, the exact 1,024-byte limit and a 1,025-byte rejection. Other Fuel dependencies remained patched during these checks.

## Unverified and retained limitations

The results do not guarantee all FuelPHP APIs, all library versions or a production application's own code. The following are outside the recorded coverage:

- Native `mysql`, native `sqlite`, `sqlsrv`, `dblib` and other PDO backends including PostgreSQL; database session drivers.
- FTP, the legacy MongoDB extension, Redis/Memcached, OrmAuth, ORM nestedset/temporal/softdelete, additional template engines and Windows.
- All GD image formats/operations, Oil file generation and external package operations, external SMTP services, HTTPS Secure Cookie transmission and browser pixel comparisons.
- Custom persistence/subclass behavior beyond the listed cases, upgrades from Smarty 2/3, and complete dependency upgrades for existing applications.

Sessions in the recorded main suite used the file driver. Seven optional-parameter diagnostics in the legacy MongoDB driver remain outside the executable compatibility claim. The existing Model_Crud `_is_new` restoration issue also remains. An ICU update or a different locale/OS requires retesting the application's affected date behavior.

## Dependency audit

The recorded locked dependency audit at 2026-10-21 11:36:56 UTC returned exit code 1: one medium advisory for `phpseclib/phpseclib` 2.0.55, `PKSA-12sz-bcny-pk4m` / `CVE-2026-84308` / `GHSA-q97c-8qh3-fpc6`. No abandoned packages were reported in that run. These are dated findings for the tested dependency lock, not a current audit of every consumer's installation. Run and review `composer audit` for the application's own lock.

The [upstream advisory](https://github.com/phpseclib/phpseclib/security/advisories/GHSA-q97c-8qh3-fpc6) names 3.0.56/4.0.0; the recorded [GitHub Advisory Database entry](https://github.com/advisories/GHSA-q97c-8qh3-fpc6) and Composer range also included 2.0.55. That mismatch was not used to suppress the finding or declare it a false positive.

Source review was limited to phpseclib 2.0.55 reference `d73c9e019a895be83b18a2ccccfa7e2b0a648743` and the tested Fuel crypt/session call paths. Fuel `Crypt::encode/decode` and encrypted session cookies used sodium stream XOR/generichash, not X25519. `Crypt::legacy_decode` used phpseclib AES and Hash; fixed legacy ciphertext decryption was exercised on both PHP versions. The specific pure-PHP Montgomery/PrimeField classes named in the advisory were absent from that locked 2.0.55 source.

The source review also found that this 2.0.55 SSH Curve25519 path requires sodium and creates a new random secret for each exchange. SSH communication itself was not tested and is not called by the tested Fuel functionality. No claim is made about all phpseclib algorithms, separate application crypto calls, other installed major versions or long-lived X25519 keys.

The audit finding remains. No affected path was identified among the tested Fuel functions, but that is not a package-wide safety guarantee or a zero-advisory result. Moving to phpseclib 3.x requires separate compatibility work because Fuel's `^2.0.0` requirement and `phpseclib\Crypt\AES/Hash` references do not directly match the 3.x namespace. Reassess any additional encryption paths and current upstream fixes before changing dependencies.
