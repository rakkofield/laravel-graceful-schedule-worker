# Laravel Graceful Schedule Worker

## Project Overview

A library for gracefully executing Laravel schedule tasks.
Supports safe shutdown via signal handling and AWS Step Functions integration.

## Development Workflow

### Running Tests

```bash
# All tests
composer test

# Coverage (text)
composer test:coverage

# Coverage (HTML)
composer test:coverage-html
```

### Initial Setup

```bash
composer install
composer tools:install
composer skeleton:update
```

### Step Functions & Redis Tests

When running `composer test`, moto and Redis start automatically, and Step Functions tests and Redis integration tests are also executed.

```bash
# Run tests (moto & Redis start automatically)
composer test

# Individual operations
composer stepfunctions:up      # Start all services (moto & Redis, waits for healthcheck)
composer stepfunctions:down    # Stop all services
composer redis:up              # Start Redis only
composer redis:down            # Stop Redis only
```

**Prerequisites:**
- Docker must be installed

### Updating Skeleton Dependencies

When modifying code under `src/`, update skeleton dependencies before running integration tests:

```bash
composer skeleton:update
```

### Static Analysis

```bash
composer tools:install  # First time only
composer phpstan        # Static analysis (requires PHP 8.1+)
composer phpcs          # Coding standards check
composer phpcbf         # Auto-fix

# To specify a PHP interpreter
PHP_SA_BINARY=/path/to/php8.1 composer phpstan
```

### On Task Completion

When a task is completed, run the following without waiting for confirmation:

1. `composer phpstan` for static analysis
2. `composer phpcs` for coding standards check
3. `composer test` to run tests
4. If all pass, create a commit

## PHP Version

- Minimum requirement: PHP 7.2.5
- `ext-pcntl` required (for signal handling)
- Nullable types (`?string`) are available since PHP 7.1

## Design Documents

- `docs/internals/DESIGN.md` - Main design specification (read first)
- `docs/internals/` - Internal design documents (ARCHITECTURE, SCHEDULER_COMPATIBILITY, etc.)
- `docs/guide/` - User-facing guides (QUICKSTART, MIGRATION, etc.)
- `.claude/plans/` - Implementation plans

## Directory Structure

```
src/
├── Clock/                # Clock & sleep abstraction (ClockInterface, SleeperInterface)
├── Console/              # Artisan commands (GracefulScheduleWorkCommand)
├── Dispatcher/           # Task dispatchers
│   ├── Result/           # Dispatch result types (Started, Failed, Skipped, AlreadyRunning)
│   └── StepFunctions/    # Step Functions client, exceptions, name generation
├── Orchestrator/         # Schedule execution coordination (DefaultScheduleOrchestrator)
├── Providers/            # ServiceProvider
├── Scheduling/           # ClockAwareSchedule, ClockAwareEvent
└── Tracker/              # Execution tracking (CacheExecutionTracker, NullExecutionTracker)

tests/
├── Unit/                 # Unit tests (mirrors src/ directory structure)
├── Integration/          # Integration tests (uses skeleton, requires Redis)
├── E2E/                  # E2E tests
├── Helper/               # Test helpers (Fake, Stub, Spy, etc.)
└── StepFunctions/        # Step Functions test configuration (state-machine.json)

skeleton/                 # Test Laravel application
```

## Important Notes

- Component dependencies: Command → Orchestrator → Dispatcher → Result
- Dispatcher uses the decorator pattern: CompositeDispatcher, TrackingDispatcher wrap ScheduleDispatcherInterface
- As a library, do not directly depend on Laravel facades like `Log::`
- Do not use Laravel helpers like `base_path()`
- Do not extend Schedule in ServiceProvider (consumers can opt into ClockAwareSchedule)

## Test Style

- Method naming: `test` prefix + camelCase (e.g., `testReturnsCurrentTime`)
- Test IDs: Written as `@testdox {Prefix}.{seq} Description text` in PHPDoc
  - Prefix is a unique 2-4 character uppercase abbreviation per test file
  - Unit tests: Abbreviation of the target class name (e.g., LocalDispatcher → `LD`)
  - Integration tests: Class name abbreviation + `I` (e.g., CacheTracker Integration → `CTI`)
  - E2E tests: Fixed as `E2E`
  - See `docs/internals/TESTDOX_IDS.md` for the full prefix list
  - When adding new prefixes, ensure no duplicates with existing prefixes
- `declare(strict_types=1)` required (both src/ and tests/)
- Call `Container::setInstance(null)` in tearDown (for tests that use Container)
- No mocks: Use Fake for interfaces, Stub/Spy for concrete classes
- Test helper classes (Fake, Stub, Spy, Testable, etc.) go in `tests/Helper/` (one class per file)