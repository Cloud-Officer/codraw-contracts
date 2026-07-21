# Code Review: codraw/contracts

## Fixes applied (2026-07-20)

- **composer.json:** PHP version constraint changed from unbounded `>=8.5` to `^8.5` (version-compatibility debt: prevents a future PHP 9 from installing against this package; no effect on any currently existing PHP version).
- **Finding 1** (`composer.json`): added a `require` section with `"php": ">=8.5"` (repo convention) and `"psr/container": "^1.0 || ^2.0"` (hard-referenced by `Messenger/TransportRepositoryInterface`), plus a `suggest` section for `symfony/messenger` (Messenger contracts) and `symfony/process` (`ProcessFactoryInterface`), following the sibling-package `suggest` style. `composer validate --no-check-publish` passes.
- **Finding 4** (`Application/ConfigurationRegistryInterface.php`): reworded the `set()` docblock from "The value is expected to be json_encoded" to state the value must be JSON-serializable, with encoding being the implementation's responsibility — removing the double-encode ambiguity.
- **Finding 6** (`Messenger/EnvelopeFinderInterface.php`): fixed the `findByTags()` docblock — "envelop" typo corrected, and the redundant `array|string[]` / `array|Envelope[]` unions replaced with `string[]` / `Envelope[]`.
- **Validation pass (2026-07-20):** added a `require-dev` section with `symfony/messenger: ^6.4.0` and `symfony/process: ^6.4.0` (sibling-package version convention) so PHPStan can resolve the Symfony classes type-hinted by the interfaces. Without it, PHPStan reported 8 pre-existing `class.notFound`/`interface.notFound` errors (verified present before the fixes via `git stash`); with it, PHPStan level 5 passes with no errors. Runtime dependencies remain optional (`suggest` only), preserving the Finding 1 design. `composer validate --no-check-publish` still passes. No PHPUnit suite exists in this package (CI treats missing `vendor/bin/phpunit` as a skip); markdownlint reports 0 errors.

Not fixed (deliberately, to avoid disrupting consumers): Finding 2 (adding `: mixed` to `get()` is a signature change that fatals implementations omitting the return type), Finding 3 (constructor/API addition — design decision), Finding 5 (adding native or phpdoc types changes the contract surface for implementations and consumer static analysis), Finding 7 (root PSR-4 layout is a deliberate suite convention).

## Overall Assessment

`codraw/contracts` is a minimal abstractions package (9 PHP files: 6 interfaces, 3 exceptions) extracted from the larger Draw/codraw component suite, covering application configuration/versioning, Messenger envelope handling, and process creation. The code itself is clean, small, and appropriately scoped for a contracts package — there is no executable logic, so there is essentially no attack surface and no runtime-bug potential inside this package. The real issues are all in packaging metadata: `composer.json` declares **no dependencies at all** — no PHP version constraint, no `psr/container`, and no `require`/`suggest` entries for the Symfony components whose classes are type-hinted directly in the interfaces. That makes the package installable in environments where its interfaces cannot actually be implemented or even loaded, and it silently permits installation on PHP versions that do not support the syntax used (`mixed`, trailing commas in parameter lists). A few minor API-design inconsistencies exist in the interfaces themselves.

Grade: **B** — good, clean contracts with packaging defects that should be fixed.

## Findings

### High

#### 1. **[FIXED]** `composer.json` declares no dependencies whatsoever (no PHP constraint, no psr/container, no Symfony requirements)

- File: `composer.json:1-29`

The package has no `require` section at all, yet its source code hard-depends on:

- `Symfony\Component\Messenger\Envelope` (`Messenger/EnvelopeFilterInterface.php:5`, `Messenger/EnvelopeFinderInterface.php:6`)
- `Symfony\Component\Messenger\Transport\TransportInterface` (`Messenger/TransportRepositoryInterface.php:6`)
- `Symfony\Component\Messenger\Exception\ExceptionInterface` (`Messenger/Exception/MessageNotFoundException.php:5`) — this one is a *compile-time* dependency: the class `implements` it, so simply autoloading `MessageNotFoundException` fatals if `symfony/messenger` is absent
- `Psr\Container\NotFoundExceptionInterface` (`Messenger/TransportRepositoryInterface.php:5`)
- `Symfony\Component\Process\Process` (`Process/ProcessFactoryInterface.php:5`)

At minimum the package should `require` `"php": ">=8.1"` (or whichever floor the suite targets — `Application/ConfigurationRegistryInterface.php:14` uses `mixed`, which requires PHP 8.0+, and multi-line parameter lists with trailing commas require 8.0+) and `psr/container`, and should list `symfony/messenger` and `symfony/process` in `suggest` (or split-require them), following the pattern used by `symfony/contracts` packages. As written, `composer require codraw/contracts` succeeds on PHP 7.x and on projects without Messenger, producing fatal errors only when a class is first loaded.

### Medium

#### 2. `ConfigurationRegistryInterface::get()` has no return type

- File: `Application/ConfigurationRegistryInterface.php:14`

`public function get(string $name, mixed $default = null);` declares `mixed` on the parameter but omits the return type entirely. It should be `: mixed` for consistency and so that implementations are contractually bound (an implementation could currently declare `: void` or any other type and still satisfy the interface, since an omitted return type is covariant-compatible with anything). Every other method in the package declares its return type; this is the lone gap.

#### 3. `MessageNotFoundException` cannot carry a previous exception

- File: `Messenger/Exception/MessageNotFoundException.php:9-12`

The constructor signature is `__construct(string $messageId)` with no `$previous` parameter. Finder implementations that catch a lower-level exception (transport failure, decode error) and rethrow as `MessageNotFoundException` lose the causal chain, hurting debuggability. Contrast with `ConfigurationIsNotAccessibleException`, which correctly exposes `?\Throwable $previous`. Suggested: `__construct(string $messageId, ?\Throwable $previous = null)`. Also note the exception does not expose the `$messageId` it was constructed with (no getter/readonly property), forcing consumers to parse the message string if they need it.

#### 4. **[FIXED]** Confusing/incorrect docblock on `ConfigurationRegistryInterface::set()`

- File: `Application/ConfigurationRegistryInterface.php:17-24`

"The value is expected to be json_encoded" contradicts the `mixed $value` signature — callers will reasonably pass raw values (arrays, scalars), and implementations in the suite json-encode internally. If the docblock is taken literally, callers double-encode. The docblock should say the value must be JSON-*serializable* (or specify the actual constraint). For a contracts package, ambiguous documentation is a real interoperability defect since the docblock *is* the specification.

### Low

#### 5. `ProcessFactoryInterface` parameters under-specified

- File: `Process/ProcessFactoryInterface.php:9-17`

`$input` has no type declaration or docblock on either method (Symfony accepts `string|int|float|bool|resource|\Traversable|null`), and `array $command` / `?array $env` lack `@param` value-type annotations (`array<string>` / `array<string,string>`). Implementations and static analysis get no guidance. Additionally, `create()` uses a single-line signature while `createFromShellCommandLine()` uses multi-line — trivial, but the missing types are worth fixing since the interface mirrors `Symfony\Component\Process\Process::__construct` and could simply copy its phpdoc.

#### 6. **[FIXED]** Sloppy docblock types and typo in `EnvelopeFinderInterface`

- File: `Messenger/EnvelopeFinderInterface.php:15-22`

`@param array|string[] $tags` and `@return array|Envelope[]` are redundant unions (`array|string[]` collapses to `array`; the intended types are `string[]` and `Envelope[]` alone, or `list<string>` / `list<Envelope>`). Also "Return all envelop that match all tags" — typo ("envelopes"), and it is worth stating explicitly whether matching is AND-semantics across tags (the wording suggests AND; make it unambiguous since this is a contract).

#### 7. PSR-4 root mapped to package root

- File: `composer.json:16-20`

`"Draw\\Contracts\\": ""` maps the namespace to the repository root, so non-source directories (`docs/`, `.github/`) live inside the autoload root and are scanned when generating optimized/authoritative classmaps. Harmless today, but a `.php` file dropped anywhere in the repo becomes autoloadable. A `src/`-less layout is a deliberate convention in the Draw suite, so this is noted only as a trade-off; consider `"exclude-from-classmap"` for `docs/` if the layout is kept.

## Strengths

- **Correctly scoped contracts package**: pure interfaces and exceptions, no logic, no state — exactly what a `*/contracts` package should be. Zero security surface.
- **Consistent exception design (mostly)**: domain exceptions extend appropriate SPL bases (`\RuntimeException` for accessibility failures, `\Exception` + Messenger's `ExceptionInterface` for the Messenger domain, enabling `catch (Symfony\Component\Messenger\Exception\ExceptionInterface)` interop).
- **`@throws` annotations are present and accurate** on every method that can fail (`ConfigurationRegistryInterface`, `VersionVerificationInterface`, `EnvelopeFinderInterface`, `TransportRepositoryInterface::get()`), which is the most valuable documentation a contract can carry.
- **`TransportRepositoryInterface` is a well-designed repository abstraction**: `has()`/`get()`/`getTransportNames()`/`findAll()` with `iterable<string,TransportInterface>` for lazy iteration, and `get()` documented to throw the PSR-11 `NotFoundExceptionInterface` rather than inventing a bespoke exception.
- **`ProcessFactoryInterface` signatures faithfully mirror** `Process::__construct` / `Process::fromShellCommandline` defaults (including `?float $timeout = 60`), making decorator/factory implementations drop-in.
- **Clean static-analysis posture**: PHPStan level 5 configured (`phpstan.dist.neon`) with an *empty* baseline (`phpstan-baseline.neon`) — no suppressed debt.
- **Proper package migration handling**: `replace: {"draw/contracts": "self.version"}` plus branch-alias lets the renamed package substitute the original without breaking downstream constraints.

## Test Coverage

There is no `Tests/` directory and no test tooling (`require-dev` is absent, no PHPUnit configuration). For a package containing only interfaces and three trivial exceptions this is largely defensible — there is almost no behavior to test. The only unit-testable behavior is `MessageNotFoundException`'s message formatting (`Message id [%s] not found`) and the default message of `ConfigurationIsNotAccessibleException`; neither is covered. The meaningful "tests" for a contracts package are (a) an installability/smoke check that every class can be autoloaded when the suggested dependencies are present, and (b) the downstream packages (`codraw-messenger`, `codraw-application`, `codraw-process`) exercising the interfaces via their implementations — coverage therefore effectively lives outside this repository. Recommend at least a CI job that runs `composer validate` and PHPStan against the lowest supported PHP/Symfony versions once dependency constraints (Finding 1) are added.
