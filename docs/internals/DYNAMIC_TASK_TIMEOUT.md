# Laravel Graceful Schedule Worker - Per-Job Task Timeout Design

> **Note**: For Step Functions implementation details see [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md),
> for architecture see [ARCHITECTURE.md](./ARCHITECTURE.md)

| Item | Value |
|---|---|
| Status | Implemented on the library side (`timeoutSeconds` is always in the payload). Switching the state machine to `TimeoutSecondsPath` is the consumer's step, see [Migration Order](#migration-order) |
| Created | 2026-07-28 |
| Scope | Making the Step Functions Task state timeout vary per job |

## Table of Contents

1. [Background and Problem](#background-and-problem)
2. [Current State](#current-state)
3. [AWS Constraints](#aws-constraints)
4. [Options Considered](#options-considered)
5. [Chosen Option: Derive from Lock TTL](#chosen-option-derive-from-lock-ttl)
6. [Derivation](#derivation)
7. [Change Surface](#change-surface)
8. [Required State Machine Changes](#required-state-machine-changes)
9. [Migration Order](#migration-order)
10. [Test Strategy](#test-strategy)
11. [Impact on Existing Documentation](#impact-on-existing-documentation)
12. [Open Questions and Future Work](#open-questions-and-future-work)

---

## Background and Problem

`TimeoutSeconds` in Step Functions is a static value embedded in the state machine definition and shared by every
execution. When one state machine handles jobs of differing lengths, **every job shares a value sized for the longest
one**.

That produces an asymmetry:

- A job that should finish in 10 minutes can hang undetected until the value sized for the longest job (say, 12 hours)
  elapses
- Meanwhile the DynamoDB lock stays held, so every subsequent dispatch is turned away as `AlreadyRunning`

In other words, **the detection granularity for short jobs is dictated by the longest job**. Removing that coupling is
the purpose of this document.

### The Underlying Constraint

The timeout value cannot be chosen in isolation. It sits in this dependency:

```
Lock TTL (expiresAt)
    +-- RunEcsTask's TimeoutSeconds   <- exceeding this makes the task outlive the lock
```

If an ECS task keeps running past the lock TTL, a re-dispatch reclaims the lock and **two ECS tasks run for the same
job**. The ownership guard on `ReleaseLock` (`executionArn = :arn`) prevents deleting the wrong lock, but it does not
prevent the duplicate run.

So "vary the timeout per job" is really the problem of "**give each job a timeout that is consistent with its lock
TTL**".

---

## Current State

### Where the Timeout Lives

`RunEcsTask`'s `TimeoutSeconds` is hard-coded in the state machine definition and cannot be influenced by the
application.

### How the Lock TTL Is Decided

The lock TTL, by contrast, is decided per event.

`PayloadBuilder::build()`

```php
$lockTtl   = $event->resolveLockTtlSeconds($lockTtlSeconds);
$expiresAt = $dueAt->getTimestamp() + $lockTtl;
```

`ClockAwareEvent::resolveLockTtlSeconds()` (`src/Scheduling/ClockAwareEvent.php:286-293`) converts Laravel's
`$expiresAt` (**minutes**) to seconds only when `withoutOverlapping` is enabled; otherwise it returns the configured
default (`graceful-scheduler.stepfunctions.lock_ttl`, default 3600 seconds) unchanged.

```php
$schedule->command('batch:heavy')->hourly()->withoutOverlapping(60 * 8); // 8 hours
```

### Important: Mutual Exclusion Requires withoutOverlapping

`LockKeyGenerator::generate()` (`src/Dispatcher/StepFunctions/LockKeyGenerator.php:40-44`) produces a lock key
containing `dueAt` for events without `withoutOverlapping`. The key therefore differs on every dispatch and
**no mutual exclusion happens at all**.

Jobs that need mutual exclusion must declare `withoutOverlapping()`, and in that case the configured `lock_ttl` is
not used.

### The Payload Today

**Before this design was implemented**, `Payload::toJson()` emitted these six keys, none of which corresponds to
a timeout.

```
command / mutexName / dueAt / lockKey / expiresAt / dispatchedAt
```

It now emits seven, including `timeoutSeconds`.

---

## AWS Constraints

Amazon States Language behaviour this design depends on.
(Sources: [Task workflow state](https://docs.aws.amazon.com/step-functions/latest/dg/state-task.html) /
[State machine structure](https://docs.aws.amazon.com/step-functions/latest/dg/statemachine-structure.html))

| Item | Detail |
|---|---|
| Applicable states | `TimeoutSeconds` / `TimeoutSecondsPath` are **`Task`-state only**. `Pass` / `Map` / `Parallel` do not have them |
| Notation | `TimeoutSecondsPath` is **JSONPath-mode only**; it takes a Reference Path |
| Resolved value | Must be a **positive integer**. null, 0, negative, fractional, or string values raise `States.Runtime` |
| Mutual exclusion | **`TimeoutSeconds` and `TimeoutSecondsPath` cannot both be specified** |
| Evaluated against | The **state input**, not the payload reshaped by `Parameters` |
| Default when unset | `TimeoutSeconds` defaults to 99,999,999 seconds (effectively unbounded) |
| On timeout | `States.Timeout` error, catchable via `Retry` / `Catch` |
| Top level | The state machine's overall `TimeoutSeconds` has **no path variant** (static only) |

### Verified

Confirmed against `CreateStateMachine` on moto 5.1.20:

| Definition | Result |
|---|---|
| Replace `TimeoutSeconds` with `TimeoutSecondsPath` | Accepted |
| Specify both | **Rejected** (`Redefinition of type 'Timeout'`) |

Whether `TimeoutSecondsPath` **behaves correctly at runtime** is unverified. moto cannot execute the ECS integration
and MiniStack does not reproduce `.sync` failures, so this is **only verifiable against real AWS**.

### Why "Evaluated Against the State Input" Holds

The official dynamic-timeout example references a field that is absent from `Parameters`:

```json
{
  "GlueJobTask": {
    "Type": "Task",
    "Resource": "arn:aws:states:::glue:startJobRun.sync",
    "Parameters": { "JobName": "myGlueJob" },
    "TimeoutSecondsPath": "$.params.maxTime"
  }
}
```

So even in a state whose `Parameters` narrows the fields, `TimeoutSecondsPath` resolves against the input before that
narrowing.

---

## Options Considered

> **Added after implementation**: this table originally scored A more favourably; three cells turned
> out not to match reality. **The version below is corrected** - the original scoring is recorded under
> [Where the original scoring was wrong](#where-the-original-scoring-was-wrong). A and B turned out not
> to be exclusive, and **both are implemented** (A as the default, B as an explicit override).

| | A: Derive from lock TTL | B: Dedicated fluent API | C: Config map |
|---|:--:|:--:|:--:|
| Application-side code | None (reuses `withoutOverlapping`) | `->timeoutAfter(1800)` | None (listed in config) |
| Can be set independently of TTL | No | Downwards only | Yes |
| New API surface | Two config keys + a value object | One method on `ClockAwareEvent` | Config schema |
| Proximity to the schedule definition | Best | Best | Poor |
| Prevents the dangerous breach (overtaking a live lock) | Structurally | Definition-time and dispatch-time checks | Up to the user |
| Changes what existing code means | **Yes** (second meaning for `withoutOverlapping`) | No | No |
| Adds a setting ignored under `dispatch=local` | **Yes** (an existing setting, easy to miss) | Yes (a new setting, noticeable) | Yes |

### Why Not C

It requires building matching rules (exact / prefix / wildcard) while separating the setting from the schedule
definition, which invites inconsistency.

### Why A Is the Default

Existing schedule definitions get a lock-coherent timeout without being touched. Even when the user writes nothing,
the original problem — every job sharing one static value sized for the longest one — is solved.

### Where the Original Scoring Was Wrong

The table originally favoured A on three counts. What actually happened:

| Original scoring | Reality |
|---|---|
| New API surface: **none** | `lock_release_buffer`, `min_task_timeout` and `StepFunctionsTimeoutSettings` all appeared |
| TTL > timeout: **structurally guaranteed** | Regime 1 only; regime 2 breaks it deliberately ([regime 2](#regime-2-stop-tracking-when-the-lock-cannot-host-the-run)) |
| B rejected because "the constraint that option A gets for free would have to be bolted on as validation logic anyway" | **That bolting-on happened to A too**: five validation rules, a floor with a fallback regime, and a migration-time audit of every schedule entry |

The other reason for rejecting B — a setting silently ignored under `dispatch=local` — applies to A as well, and the
assessment is now **that A's version is worse**. B grows a *new* setting one mode ignores; A attaches a second
meaning to the *existing* `withoutOverlapping($minutes)`, so **the meaning changes without the user changing
anything**. That is why [the migration audit](#step-3-auditing-the-schedule-definition-required) became necessary.

What remains is the severity of the breach. B's breach (`timeoutAfter` > lock lifetime) is **overtaking a live
lock** — duplicate execution itself — while A's regime-2 breach only happens once the lock has already expired. To
keep that distinction, B is implemented so `timeoutAfter()` can only ever *shorten* the window.

---

## Chosen Option: Derive from Lock TTL (plus an Explicit Override)

**Derive the timeout from `expiresAt` and `dispatchedAt` and always include it in the payload; an explicit
`timeoutAfter($seconds)` wins, but only in the shortening direction.**

### Rationale

As described in [Background and Problem](#background-and-problem), the timeout and the lock TTL are not independent
values. This option **fixes that dependency in the structure of the derivation**.

The result is that the application author reasons about exactly one number:

> What is the worst-case duration of this job?

Writing that into `withoutOverlapping($minutes)` makes the ECS-side timeout follow automatically.

```php
$schedule->command('batch:heavy')->hourly()->withoutOverlapping(730); // 43,800 seconds
```

### The Explicit Override (Option B)

`ClockAwareEvent::timeoutAfter($seconds)` declares a job's budget directly.

```php
$schedule->command('batch:heavy')->hourly()
    ->withoutOverlapping(730)   // lock lifetime 43800s
    ->timeoutAfter(43200)       // this job may run for 12 hours
    ->dispatchVia('stepfunctions');
```

How it interacts with the derivation:

| | Behaviour |
|---|---|
| Not declared | The derived value (remaining lock lifetime − `lock_release_buffer`, or the regime-2 fallback) |
| Declared, fits the lock lifetime | The declared value is used as is |
| Declared, exceeds the remaining lock lifetime | **Capped at the remaining lifetime** (regime 1). The declaration only ever shortens |
| Declared, lock already expired (regime 2) | Used as is - there is nothing left to cap against, and `min_task_timeout` does not apply |

A declaration contradicting `withoutOverlapping($minutes)` (`timeoutAfter` >= the lock lifetime) is **rejected at
schedule-definition time**, in either chaining order (`withoutOverlapping` is overridden to check as well).

For events without `withoutOverlapping`, the ceiling comes from the configured `lock_ttl`, which the event cannot
know at definition time; those are capped at dispatch time instead. No exception is thrown there, because one
mis-declared event must not stop the whole worker.

**It is ignored under `dispatch=local`**, since local execution has no timeout mechanism. That is option B's known
weakness, see [Options Considered](#options-considered).

---

## Derivation

```php
$expiresAt = $dueAt->getTimestamp() + $lockTtl;
$usable    = $expiresAt - $dispatchedAt->getTimestamp() - $lockReleaseBuffer;

$timeoutSeconds = $usable >= $minTaskTimeout
    ? $usable                                              // Regime 1: the lock can host this run
    : max($minTaskTimeout, $lockTtl - $lockReleaseBuffer);  // Regime 2: it cannot
```

`StepFunctionsTimeoutSettings::deriveTimeoutSeconds()` owns this. `PayloadBuilder::build()` already receives
both `$dueAt` and `$dispatchedAt`, so no additional input is needed.

### Regime 1: Why Base It on Remaining Lock Lifetime

The reason for `$expiresAt - $dispatchedAt - $lockReleaseBuffer` rather than a plain
`$lockTtl - $lockReleaseBuffer` is that it **absorbs dispatch latency**.

`expiresAt` is anchored to `dueAt` (the scheduled time), while the actual dispatch happens later. With
`$lockTtl - $lockReleaseBuffer`, the task can outlive the lock by exactly that delay. Basing it on the remaining
lock lifetime shrinks the timeout by however late the dispatch was, so **overtaking caused by dispatch latency
cannot happen** - which is not the same as no overtaking at all, see
[What the buffer pays for](#what-the-buffer-pays-for).

### Regime 2: Stop Tracking When the Lock Cannot Host the Run

Once the remaining lock lifetime falls below `min_task_timeout`, **no return value lets the lock exclude a
concurrent run**. Clamping to one second there would only kill the task while protecting nothing. Two shapes reach
this regime:

1. **A recovery dispatch.** `DefaultScheduleOrchestrator::recoverMissedEvent()` passes the past missed slot as
   `dueAt` and recovery wallclock as `dispatchedAt`, so `expiresAt` is already behind us. Continuing to track it
   would make **every recovery dispatch die on an immediate timeout**.
2. **A `withoutOverlapping($minutes)` window near the buffer.** `withoutOverlapping(1)` declares a 60-second
   lifetime, which the default 60-second buffer consumes entirely.

In this regime the derivation falls back to the lifetime the event itself declared
(`$lockTtl - $lockReleaseBuffer`), never below `min_task_timeout`.

**That fallback can return a timeout outlasting `expiresAt`.** It is a deliberate trade: the lock has already lost
the ability to exclude a concurrent run, and a task killed after one second cannot do the work it was dispatched
for.

### Parameters

| Item | Default | Notes |
|---|---|---|
| `stepfunctions.lock_release_buffer` | 60 seconds | The margin kept between the Task timeout and the lock expiry, so the lock-release path still runs while the lock is ours. It reserves room for the lock, not for the job: the derived timeout is the remaining lock lifetime *minus* this value |
| `stepfunctions.min_task_timeout` | 60 seconds | The floor at which the derivation switches to regime 2 |

The name matters here. `lock_release_buffer` is not headroom added on top of an expected job duration - with the
lock lifetime fixed, the timeout's ceiling is fixed too, so the only thing this value can do is subtract. Adding
headroom instead would require a separate "how long does this job take" input, which is option B
([future work](#future-work-option-b-dedicated-fluent-api)).

### What the Buffer Pays For

`TimeoutSeconds` is measured **from Task-state entry**. The derivation absorbs latency up to StartExecution;
past that point `lock_release_buffer` is the only margin left, and it pays for three things:

1. StartExecution -> `AcquireLock` -> `RunEcsTask` entry latency (plus exponential backoff if `AcquireLock` retries)
2. The time between `States.Timeout` making the `.sync` integration call StopTask and **the container actually
   going away** (ECS `stopTimeout` defaults to 30s, up to 120s)
3. The lock-release path (`Catch` -> delete the lock item)

The invariant to protect is not "the Task state times out" but "the container is gone before `expiresAt`", so (2)
cannot be ignored. **Given that this library exists for long graceful shutdowns after SIGTERM, raise
`lock_release_buffer` alongside any raised `stopTimeout`.**

### Scope of the Validation

`StepFunctionsTimeoutSettings` holds five rules: it rejects `lock_ttl < 1`, `lock_release_buffer < 1`,
`min_task_timeout < 1`, `lock_ttl <= lock_release_buffer`, and `min_task_timeout > lock_ttl - lock_release_buffer`.

**The validation only sees config-supplied values.** A lifetime an event declares through
`withoutOverlapping($minutes)` comes straight from `ClockAwareEvent::resolveLockTtlSeconds()` and never passes
through the type's constructor (the derivation does). Throwing per event is not available: the orchestrator
deliberately lets `dispatchEvent` errors propagate, so one mis-declared event would stop the whole worker.
For event-supplied lifetimes, `min_task_timeout` guarantees only that the run is not killed instantly; the rest is
an audit step in [Migration Order](#migration-order) and a documented consequence.

---

## Change Surface

| File | Change | Backwards compatible |
|---|---|---|
| `src/Dispatcher/StepFunctions/Payload.php` | 7th constructor argument `int $timeoutSeconds` (**required**), getter, and a key in `toJson()` | Breaking ([see below](#making-timeoutseconds-a-required-argument)) |
| `src/Dispatcher/StepFunctions/PayloadBuilder.php` | Accept the settings and delegate the derivation | Constructor's 2nd argument is optional (built from the defaults) |
| `src/Dispatcher/StepFunctions/StepFunctionsTimeoutSettings.php` | New: validates the three settings and owns the derivation | New file |
| `src/Scheduling/ClockAwareEvent.php` | Adds `timeoutAfter()`; `withoutOverlapping()` is overridden to detect contradictions | Method addition only |
| `src/Providers/StepFunctionsServiceProvider.php` | Bind the settings once from config; `PayloadBuilder` and the input factory both read them from there. Non-numeric second values are rejected | — |
| `config/graceful-scheduler.php` `stepfunctions` | Add `lock_release_buffer` and `min_task_timeout` | — |

### Test Updates

| File | Change |
|---|---|
| `PayloadTest` (PY.4 / PY.5) | Add the key to `$expectedKeys`; value independence and the `>= 1` guard |
| `StepFunctionsDispatcherTest` (SFD.4) | Update the key-set assertion |
| `PayloadBuilderTest` (PB.14-24) | Derivation cases: delay present/absent, the floor boundary, the regime-2 fallback, recovery shape, `withoutOverlapping(1)` |
| `StepFunctionsTimeoutSettingsTest` (STS.1-22, new) | The validation rules and the derivation itself |
| `StepFunctionsServiceProviderTest` (SFP.13-22) | Config reading and wiring (which setting reaches which consumer) |
| `ProviderBootIntegrationTest` (GPI.8) | The shipped config defaults match the constants |

### Impact on Existing Consumers

**There are three breaking changes.**

| Change | Who is affected |
|---|---|
| `Payload`'s 7th argument becomes required | Custom `PayloadBuilder`s that `new Payload(...)` directly ([below](#making-timeoutseconds-a-required-argument)) |
| `lock_ttl <= lock_release_buffer` is rejected at startup | Anyone with `SCHEDULE_SF_LOCK_TTL` at 60 or below. It used to be harmless (events without `withoutOverlapping` get a `dueAt`-scoped lock key, so the TTL had no effect); now the worker refuses to start. `CompositeDispatcher` resolves the Step Functions dispatcher whenever `state_machine_arn` is non-empty, so `dispatch=local` deployments are caught too |
| `withoutOverlapping($minutes)` starts deciding the ECS timeout | Everyone who switches their state machine to `TimeoutSecondsPath`. Do the audit in [Migration Order](#migration-order) |

The execution input itself gains one key. State machines that do not declare `TimeoutSecondsPath` ignore it, and the
size increase is negligible against the Step Functions input limit (256 KB).

### Making timeoutSeconds a Required Argument

The 7th argument of `Payload` is a **required `int`**, not `?int = null`.

Making it optional would avoid breaking existing direct instantiation, but **the combination of "omit the key when
null" and `TimeoutSecondsPath` is precisely the failure mode this design sets out to remove.** An unresolvable path
raises `States.Runtime`, which is non-retriable and always fails the execution. Rather than living with "the type
allows null but in practice a value is always present", we guarantee at the type level that
**if a `Payload` exists, `timeoutSeconds` is in the JSON**.
Presence alone is not enough either, so the constructor also validates `timeoutSeconds >= 1`: zero and negative
values resolve to `States.Runtime`, so the argument for making it required applies to the value as well.

#### Blast Radius

`new Payload(` is called in only these places in the repository:

| Location | Action |
|---|---|
| `PayloadBuilder::build()` | Pass the derived value |
| `tests/Unit/Dispatcher/StepFunctions/PayloadTest.php` (4 call sites) | Add the argument |

External impact is limited to custom `PayloadBuilder` implementations that instantiate `Payload` directly. Those
returning their own `PayloadInterface` implementation are unaffected, but for them the requirement
"this field is mandatory if you use `TimeoutSecondsPath`" remains a documentation-level promise, since the type
system cannot enforce it.

#### Why the Buffer Argument Stays Optional

The second `PayloadBuilder` constructor argument (`?StepFunctionsTimeoutSettings`) is optional; an unset value
falls back to a sensible default, so unlike `timeoutSeconds` it does not have the property that "unset leads directly
to a runtime failure".

---

## Required State Machine Changes

### RunEcsTask

Because the two fields are mutually exclusive, remove one and add the other.

```diff
   "RunEcsTask": {
     "Type": "Task",
     "Resource": "arn:aws:states:::ecs:runTask.sync",
-    "TimeoutSeconds": 43200,
+    "TimeoutSecondsPath": "$.timeoutSeconds",
```

`AcquireLock` / `ReleaseLock` keep their static `TimeoutSeconds` (around 10 seconds). Those do not vary per job, so
there is no reason to make them dynamic.

### Do Not Set a Top-Level TimeoutSeconds

The state machine's overall `TimeoutSeconds` has no path variant, so only a static value can be placed there. Once
only `RunEcsTask` becomes dynamic, the value chosen by the application **can invert the "top level > task"
ordering**.

When inverted, the execution-level timeout fires first. That one is **not catchable**, so `ReleaseLock` is never
reached and the lock stays until `expiresAt`.

The remedy adopted here is to **not set a top-level `TimeoutSeconds` at all**.

```diff
 {
   "Comment": "...",
   "StartAt": "AcquireLock",
-  "TimeoutSeconds": 43740,
   "States": {
```

This is safe for three reasons:

1. **The execution is already bounded by per-state timeouts** — every Task state has one and every failure path is
   caught
2. **Per-state timeouts are catchable** — `Catch(States.ALL)` -> `MarkFailed` -> `ReleaseLock` reclaims the lock
3. **A final backstop exists elsewhere** — the Standard Workflow execution limit (1 year) and TTL reclamation via
   `expiresAt` in DynamoDB

#### Record the Intent in a Comment

An absent top-level timeout is indistinguishable from an oversight during review. Left unexplained it will be
"restored" in good faith, silently reinstating the uncatchable lock-leak path. State the reason in `Comment`.

```json
{
  "Comment": "Intentionally has no top-level TimeoutSeconds: an execution-level timeout is not catchable and would skip ReleaseLock, leaking the lock until expiresAt. The run is bounded by RunEcsTask's TimeoutSecondsPath and recovered via Catch -> MarkFailed -> ReleaseLock.",
  "StartAt": "AcquireLock"
}
```

#### If a Static Ceiling Is Mandatory

If policy requires an explicit ceiling, use a **static value comfortably above the maximum the application can
request**. Calling `withoutOverlapping()` with no argument yields Laravel's default of 1440 minutes (86,400 seconds),
so if that remains possible, 90,000 or more is the guideline.

### Retries on Long-Running States

Per-state timeouts apply **per attempt**, so `MaxAttempts: 3` stretches the worst case to 4 x `timeoutSeconds`.

`ECS.AmazonECSException` / `ECS.ServiceException` normally surface right after RunTask is submitted, and re-running a
12-hour `.sync` from scratch is rarely the intent. Keep `MaxAttempts` small on long-running states.

---

## Migration Order

**The order matters.** Until the application is deployed, `$.timeoutSeconds` is absent from the payload, and an
unresolvable path fails the execution immediately with a non-retriable `States.Runtime`.

1. **Deploy the application first** — so the payload carries `timeoutSeconds`
   (harmless at this point, since the state machine simply ignores it)
2. Confirm that deployed execution inputs contain `timeoutSeconds`
3. **Audit the schedule definition** (below)
4. **Then update the state machine** — swap `TimeoutSeconds` for `TimeoutSecondsPath` and drop the top-level
   `TimeoutSeconds`

Updating the state machine first and fixing the application afterwards is not a viable order.

### Step 3: Auditing the Schedule Definition (Required)

The switch changes what `withoutOverlapping($minutes)` means. In Laravel that argument is the grace period before a
mutex is considered stale, and setting it low was nearly harmless. After the switch it becomes **that job's kill
deadline** (minus `lock_release_buffer`). The harm runs in the opposite direction, so **whoever wrote the smaller,
more careful numbers is hit hardest**.

| Declaration | Timeout after the switch |
|---|---|
| `withoutOverlapping(1)` | 60s (the `min_task_timeout` fallback) |
| `withoutOverlapping(5)` | 240s |
| `withoutOverlapping(10)` | 540s |
| `withoutOverlapping()` (1440 min default) | 86340s |
| Not declared | `lock_ttl - lock_release_buffer` (3540s by default) |

What to audit:

- List **every `withoutOverlapping($minutes)`** in the schedule and confirm `$minutes * 60 - lock_release_buffer`
  exceeds that job's worst-case duration. The dangerous band is "slightly above the normal duration" - which is
  exactly how people pick this number.
- Confirm the longest job *without* `withoutOverlapping` fits in `lock_ttl - lock_release_buffer`. If it does not,
  raise `lock_ttl` (a three-hour batch dies under the 3600 default).
- Jobs with thin headroom become **failures that only appear on busy days**, the hardest shape to trace back to
  this migration. Be generous here.

---

## Test Strategy

Whether `TimeoutSecondsPath` takes effect at runtime is AWS's responsibility; **the library's responsibility is that
the payload carries the right value**. Following that split, CI covers:

| Layer | Mechanism | Verifies |
|---|---|---|
| Unit | `StepFunctionsTimeoutSettingsTest` / `PayloadBuilderTest` | The derivation (with/without delay, the floor boundary, the regime-2 fallback) and the `toJson()` key set |
| Unit | `StepFunctionsServiceProviderTest` | Which config value reaches which consumer (catches a swapped wiring) |
| Integration | moto `CreateStateMachine` | An ASL definition containing `TimeoutSecondsPath` is accepted, and a real dispatch's `timeoutSeconds` matches the expected value |
| Runtime | **Real AWS only** | The value is actually honoured and the task times out |

Runtime verification cannot be substituted locally because:

- moto cannot execute the Step Functions ECS integration
  (`StartExecution` returns HTTP 500; `copy.deepcopy` at `moto/stepfunctions/parser/models.py:175` fails)
- MiniStack does run `ecs:runTask.sync` to completion with real Docker containers, but
  **a non-zero container exit does not fail the Task**, so it cannot verify timeout or failure behaviour

---

## Impact on Existing Documentation

The "Timeout Design" section of [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md) has been
**updated** to match this design (the Japanese edition likewise). The changes are:

| Existing statement | Treatment here |
|---|---|
| "Each layer's timeout should follow the relationship: outer > inner" | Kept. Omitting the top level makes the relationship impossible to break statically |
| "Execution Timeout: Max job time + 5 minutes" | **Withdrawn**. No top-level `TimeoutSeconds` is set |
| "Task Timeout: Max job time + 1 minute" | Replaced by derivation from `withoutOverlapping` |
| "Heartbeat: Every 5 minutes" | Out of scope here. That is ASL `HeartbeatSeconds` (an activity worker sending `SendTaskHeartbeat`), which is a different mechanism from extending the DynamoDB lock's `expiresAt` |

---

## Open Questions and Future Work

### Open

| Item | Detail |
|---|---|
| Default for `lock_release_buffer` | 60 seconds. The derivation absorbs dispatch latency, but Task-entry latency and the ECS `stopTimeout` are paid for out of this value ([what the buffer pays for](#what-the-buffer-pays-for)) |
| Default for `min_task_timeout` | 60 seconds. The regime-2 floor: too low fails to prevent instant death, too high cuts regime-1 tracking short |
| Verification against real AWS | The means and owner for confirming `TimeoutSecondsPath` runtime behaviour still need to be decided |

### Implemented: Option B (Dedicated Fluent API)

Originally listed as future work. Since A ended up paying B's projected cost (bolted-on validation logic) anyway,
B's own advantage was the only thing left unrealised, so it was implemented - see
[The Explicit Override](#the-explicit-override-option-b). The two points listed as open at the time resolved as:

- **Ignored under `dispatch=local`**: documented, not warned. Local execution has no timeout mechanism, and adding
  one to `LocalDispatcher` is out of scope for this design.
- **Where to validate `timeoutSeconds >= lockTtl`**: two places - definition time (`ClockAwareEvent`, when
  `withoutOverlapping` is already known) and a dispatch-time cap (`StepFunctionsTimeoutSettings`).
