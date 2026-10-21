# Behavior changes and application checks

These notes apply to the package versions and exact sources in [manifest.json](../manifest.json). Review them against the application's formats, stored objects, subclass overrides and error handling before deployment. [Verification scope](VERIFICATION.md) records what was tested.


## Failure cleanup in release 1.0.1

ORM save/delete and observer failures now run rollback/unfreeze cleanup for Error as well as Exception. Transaction use remains opt-in; nested rollback retains the existing whole-transaction scope. Database rollback does not restore a model's in-memory values, new-object flag or caches. Do not assume a failed model instance can be reused without reloading it.

Request, Presenter and View restore their caller context on the tested failure paths, including a null active Request and failures in request-completion events. Presenter creation accepts a request without a module. PHP View cleanup preserves caller buffers; Smarty owns its own buffers and Fuel no longer closes an already-cleaned caller buffer.

Filtering failures release changed sanitization state. Failed rendering and sending preserve the original error when output/sanitization cleanup or SMTP QUIT also fails. Secondary cleanup errors are not separately logged by this change. If rendering succeeds but sanitization cleanup fails, the cleanup error still propagates. Other payloads are released where possible.

PHP cannot remove a buffer created without the removable flag. The cleanup loop stops rather than looping indefinitely or replacing the original error. A custom object whose unsanitize operation fails may remain sanitized. These cases do not guarantee complete state restoration.

Application catch conditions and input validation still need review. There is no blanket replacement of Exception catches with Throwable and no change to business-error fallback values. See [remaining known failures](VERIFICATION.md#release-101-cleanup-verification).

## Runtime requirements

ICU and `ext-intl` are mandatory in both Web and CLI PHP. No non-ICU fallback is provided. The underlying patch tests used Linux/glibc, PHP 7.4.33 and 8.3.24, and ICU 67.1. Other operating systems and ICU versions may have different locale/calendar data; retest the application's date output after changing them.

## Date formatting and Japanese eras

`Date::format()` still accepts strftime-style format strings. Ordinary formats, weekday/month names and separators use the OS `LC_TIME` locale; era and alternative-number output uses ICU. For `ja_JP.UTF-8`, `Asia/Tokyo` and `%EY`, observed differences include:

| Date | Original PHP 7.4 | Patched output |
| --- | --- | --- |
| 2019-05-01 | 令和元年 | 令和01年 |
| 2020-01-01 | 令和02年 | 令和02年 |
| 2026-10-21 | 令和08年 | 令和08年 |
| 1868-01-01 | 西暦1868年 | 慶応04年 |

Check displays and comparisons that depend on `元年`. `%EC`, `%Ey`, `%Ec` and `%Ex` can also reflect ICU's Japanese calendar. The patch does not carry a fixed era table. For very early dates, ICU can express years before its first era as negative era years: year 1 changed from `西暦01年` to `大化-643年` in the tested environment. Applications handling historical dates can use Gregorian `%Y-%m-%d` when appropriate.

`%_EY` space padding and `%-EY` unpadded output remain available. Era years retain a minimum width of two digits and are not truncated at 100. Alternative digits use Japanese numerals for the tested locale's values 0–99; values of 100 or more retain ordinary decimal digits.

`Date::format()` sets the output timezone on a datetime object without changing PHP's global timezone. The old implementation reset that global setting to `Fuel::$timezone`; applications that depended on that side effect must set their required timezone explicitly.

`%s` follows the prior libc interpretation and depends on the output timezone and process `TZ`. Use `Date::get_timestamp()` when the timestamp itself is required. IANA names and POSIX fixed offsets were tested. POSIX daylight-saving rule strings and zoneinfo file paths are unsupported for `%s` and cause `RuntimeException`; use an IANA name.

## Date parsing

`Date::create_from_string()` no longer uses deprecated strptime. The following parsing differences are individually tested:

| Input / format | Original result | Patched result |
| --- | --- | --- |
| `令和01年05月01日` / `%EY%m月%d日` | Parse failure | 2019-05-01 |
| `令和` / `%EC` | 2019-12-31 | 2018-12-31 |
| `2019 366` / `%Y %j` | 2021-01-31 | `UnexpectedValueException` |
| `2020 00 1` / `%Y %W %w` | 2018-11-29 | `UnexpectedValueException` |

Specify year, month and day explicitly when the missing-field behavior is unsuitable. Avoid day 366 in a non-leap year and week/day combinations outside the requested year. Existing Fuel behavior that treats parsed years before 1900 as 2000 and does not apply the parsed UTC offset to the final datetime is retained. This patch is not a general redesign of date parsing.

## Model_Crud serialization

PHP `serialize($model)` emits the magic-method O form instead of Serializable's C form. Patched PHP 7.4 and 8.3 can read both forms. Direct calls to the model's `serialize()` method retain the existing string format. Standard-model tests cover shared references with external objects and cyclic references.

Unpatched PHP 7.4 fails to restore the new O form with Warning/Notice diagnostics. Reversing the patch does not convert data. Before returning to unpatched code, distinguish model-containing sessions, regenerable caches and persistent stored models, then follow the [data recovery procedure](OPERATIONS.md#model_crud-data-when-removing-patches).

Subclasses with custom `serialize()`/`unserialize()` methods retain delegation to those methods. Their custom format can lose identity for references shared with external objects. Recursive reentry into the same model's serialization throws `RuntimeException` to prevent infinite recursion. Subclasses needing these references should implement and test `__serialize()`/`__unserialize()` while retaining support for old data as needed. New return types are not imposed on existing untyped magic-method overrides.

The pre-existing assignment to `$this->is_new` in `unserialize()` does not update internal `_is_new`. A restored previously saved model can still report `is_new() === true`. This is not fixed by the patch; test subsequent inserts/updates in the application.

## Database results and memory

PDO SQLite non-cached SELECT results are buffered in PHP immediately because PDO `rowCount()` cannot supply their row count. MySQLi non-cached results are also fully buffered and the native result is freed. This permits counts, initial `current()`, repeated iteration and subsequent queries on the same connection. These fix problems that also occurred on PHP 7.4.

Result classes and array/object row types are retained. However, MySQLi and PDO SQLite non-cached SELECTs consume memory proportional to the full result. Limit selected columns and use conditions or pagination for large data sets. A non-cached result is not a constant-memory streaming contract.

PDO MySQL keeps rows as they are read and `cached()` reads any remaining rows. Calling `cached()` after partial reading still returns all rows from the start. PDO and MySQLi sanitization acts on copies of returned values, preserving the stored original rows and avoiding repeated conversion by `get()` or stale sanitized values after `unsanitize()`.

SQLite can reuse an ID allocated by a rolled-back INSERT; MariaDB AUTO_INCREMENT can leave a gap. This is an existing database-product difference. The fixed test sequence ended with ID `7` on MariaDB and `6` on SQLite, with matching results between PHP versions on each database configuration. Do not rely on consecutive IDs or a particular next ID after rollback.

## SMTP timeout exceptions

An SMTP response read timeout now raises `Email\SmtpTimeoutException`. Previously, a false result from `fgets()` could bypass the timeout check and raise `Email\SmtpCommandFailureException`. Review callers that catch only the latter. This also fixes a PHP 7.4 issue.

Connection refusal, authentication refusal and timeout are failures. Local SMTP tests checked that authentication refusal does not send the message body and that the same instance can recover for a subsequent successful send. External SMTP services were not tested.

## Upload 2.0.10

Upload 2.0.10 already contains the relevant `ReturnTypeWillChange` attributes. Its official source is checked and left unchanged. Do not apply the 2.0.6 patch to it.

The tested valid array/null arguments behave consistently across PHP 7.4 and 8.3. Invalid arguments to `new Fuel\Upload\Upload('invalid')` and `processFiles('invalid')` differ between package versions: patched 2.0.6 raises `TypeError` with code 0; official 2.0.10 emits `E_USER_WARNING`. In the tested Fuel error handler this becomes `Fuel\Core\PhpErrorException` with code 512. That exception is not an unconditional behavior of standalone Upload. Review code that catches only `TypeError` before updating Upload.

The 2.0.10 checks covered file upload boundaries and selected public APIs with Fuel's other patches present. They do not establish unpatched FuelPHP compatibility or an application's complete dependency upgrade. `dir_depth`, FTP, custom callbacks and all MIME/extension combinations were not covered.

## Other visible changes

- Declared `Request::$body`, `Request::$lang` and `Controller_Rest::$response` appear in `property_exists()` and property enumeration before their normal use.
- Public `Crypt::$legacy_crypter` and `Crypt::$legacy_hasher` are declared to avoid dynamic property creation. Their existence is visible before initialization.
- Oil's unknown-task suggestions compare task-name distances. Unrelated suggestions previously admitted by PHP 7.4 type coercion no longer appear; valid task execution is unchanged.
- PHP 8.3 changes syntax-highlighting HTML in development exception pages. Tests check status, exception type and fixed message for the intentional failure page instead of requiring identical development HTML.
