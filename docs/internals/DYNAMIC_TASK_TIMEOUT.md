# Laravel Graceful Schedule Worker - Per-Job Task Timeout Design

> **Note**: For Step Functions implementation details see [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md),
> for architecture see [ARCHITECTURE.md](./ARCHITECTURE.md)

| Item | Value |
|---|---|
| Status | Design proposal (not implemented) |
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

`src/Dispatcher/StepFunctions/PayloadBuilder.php:42-43`

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

`Payload::toJson()` (`src/Dispatcher/StepFunctions/Payload.php:108-117`) emits these six keys. None of them
corresponds to a timeout.

```
command / mutexName / dueAt / lockKey / expiresAt / dispatchedAt
```

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

| | A: Derive from lock TTL | B: Dedicated fluent API | C: Config map |
|---|:--:|:--:|:--:|
| Application-side code | None (reuses `withoutOverlapping`) | `->timeoutAfter(1800)` | None (listed in config) |
| Can be set independently of TTL | No | Yes | Yes |
| New API surface | None | One method on `ClockAwareEvent` | Config schema |
| Proximity to the schedule definition | Best | Best | Poor |
| Guarantees TTL > timeout | Structurally | Up to the user (needs validation) | Up to the user |

### Why Not C

It requires building matching rules (exact / prefix / wildcard) while separating the setting from the schedule
definition, which invites inconsistency.

### Why Not B First

Two reasons:

1. `ClockAwareEvent` is shared with local dispatch, so this would introduce a setting that is silently ignored when
   `dispatch=local` — the classic "I set it but it does nothing" trap.
2. Users could break the ordering against the TTL, so the constraint that option A gets for free would have to be
   bolted on as validation logic anyway.

---

## Chosen Option: Derive from Lock TTL

**Derive the timeout from `expiresAt` and `dispatchedAt`, always include it in the payload, and add no new
application-facing API.**

### Rationale

As described in [Background and Problem](#background-and-problem), the timeout and the lock TTL are not independent
values. This option **fixes that dependency in the structure of the derivation**.

The result is that the application author reasons about exactly one number:

> What is the worst-case duration of this job?

Writing that into `withoutOverlapping($minutes)` makes the ECS-side timeout follow automatically.

```php
$schedule->command('batch:heavy')->hourly()->withoutOverlapping(730); // 43,800 seconds
```

### Not a One-Way Door

Adopting A does not preclude B; B can be added backwards-compatibly later. `PayloadBuilder` would simply resolve in
the order "explicit value if present, otherwise derived". **Option B can wait for a concrete need to set the timeout
independently of the TTL.**

---

## Derivation

```php
$expiresAt      = $dueAt->getTimestamp() + $lockTtl;
$timeoutSeconds = max(1, $expiresAt - $dispatchedAt->getTimestamp() - $buffer);
```

`PayloadBuilder::build()` already receives both `$dueAt` and `$dispatchedAt`, so no additional input is needed.

### Why Base It on Remaining Lock Lifetime

The reason for `$expiresAt - $dispatchedAt - $buffer` rather than a plain `$lockTtl - $buffer` is that it
**absorbs dispatch latency automatically**.

`expiresAt` is anchored to `dueAt` (the scheduled time), while the actual dispatch happens later. With
`$lockTtl - $buffer`, the task can outlive the lock by exactly that delay. Basing it on the remaining lock lifetime
shrinks the timeout by however late the dispatch was, so **the task overtaking the lock becomes structurally
impossible**.

### Parameters

| Item | Default | Notes |
|---|---|---|
| `stepfunctions.timeout_buffer` | 60 seconds | Headroom for releasing the lock. Configurable |
| Clamp | `max(1, ...)` | A floor is needed because zero or negative values raise `States.Runtime` |

Clamping with `max(1, ...)` silently swallows misconfiguration, so `StepFunctionsServiceProvider` should reject
`lock_ttl <= timeout_buffer` at boot. That is lighter than injecting a logger into `PayloadBuilder`.

---

## Change Surface

| File | Change | Backwards compatible |
|---|---|---|
| `src/Dispatcher/StepFunctions/Payload.php` | 7th constructor argument `int $timeoutSeconds` (**required**), getter, and a key in `toJson()` | Breaking ([see below](#making-timeoutseconds-a-required-argument)) |
| `src/Dispatcher/StepFunctions/PayloadBuilder.php` | Accept the buffer and derive the value | Constructor's 2nd argument is optional (default 60) |
| `src/Providers/StepFunctionsServiceProvider.php:117` | Read the buffer from config and inject it; validate at boot | — |
| `config/graceful-scheduler.php:32` area | Add `timeout_buffer` | — |

### Test Updates

| File | Change |
|---|---|
| `tests/Unit/Dispatcher/StepFunctions/PayloadTest.php:99` | Add the key to `$expectedKeys` |
| `tests/Unit/Dispatcher/StepFunctionsDispatcherTest.php:146` | Update the key-set assertion in SFD.4 |
| `tests/Unit/Dispatcher/StepFunctions/PayloadBuilderTest.php` | Add derivation cases (with and without dispatch delay, clamp boundary) |

### Impact on Existing Consumers

The execution input gains one key. State machines that do not declare `TimeoutSecondsPath` ignore it, and the size
increase is negligible against the Step Functions input limit (256 KB).

### Making timeoutSeconds a Required Argument

The 7th argument of `Payload` is a **required `int`**, not `?int = null`.

Making it optional would avoid breaking existing direct instantiation, but **the combination of "omit the key when
null" and `TimeoutSecondsPath` is precisely the failure mode this design sets out to remove.** An unresolvable path
raises `States.Runtime`, which is non-retriable and always fails the execution. Rather than living with "the type
allows null but in practice a value is always present", we guarantee at the type level that
**if a `Payload` exists, `timeoutSeconds` is in the JSON**.

#### Blast Radius

`new Payload(` is called in only these places in the repository:

| Location | Action |
|---|---|
| `src/Dispatcher/StepFunctions/PayloadBuilder.php:45` | Pass the derived value |
| `tests/Unit/Dispatcher/StepFunctions/PayloadTest.php` (4 call sites) | Add the argument |

External impact is limited to custom `PayloadBuilder` implementations that instantiate `Payload` directly. Those
returning their own `PayloadInterface` implementation are unaffected, but for them the requirement
"this field is mandatory if you use `TimeoutSecondsPath`" remains a documentation-level promise, since the type
system cannot enforce it.

#### Why the Buffer Argument Stays Optional

The buffer added to `PayloadBuilder`'s constructor is optional (default 60). Any integer works and an unset value
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

**The order matters.** `$.timeoutSeconds` does not exist in the payload today, and an unresolvable path fails the
execution immediately with a non-retriable `States.Runtime`.

1. **Deploy the application first** — so the payload carries `timeoutSeconds`
   (harmless at this point, since the state machine simply ignores it)
2. Confirm that deployed execution inputs contain `timeoutSeconds`
3. **Then update the state machine** — swap `TimeoutSeconds` for `TimeoutSecondsPath` and drop the top-level
   `TimeoutSeconds`

Updating the state machine first and fixing the application afterwards is not a viable order.

---

## Test Strategy

Whether `TimeoutSecondsPath` takes effect at runtime is AWS's responsibility; **the library's responsibility is that
the payload carries the right value**. Following that split, CI covers:

| Layer | Mechanism | Verifies |
|---|---|---|
| Unit | `PayloadBuilderTest` | The derivation (with/without delay, clamp boundary) and the `toJson()` key set |
| Integration | moto `CreateStateMachine` | An ASL definition containing `TimeoutSecondsPath` is accepted |
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
| Default for `timeout_buffer` | 60 seconds proposed. Since the derivation absorbs dispatch latency, this value only covers lock-release headroom |
| Verification against real AWS | The means and owner for confirming `TimeoutSecondsPath` runtime behaviour still need to be decided |

### Future Work: Option B (Dedicated Fluent API)

If a need arises to set the timeout independently of the TTL, change `PayloadBuilder` to resolve in the order
"explicit value, then derived". `build()` already receives the `ClockAwareEvent`, so this can be added
backwards-compatibly.

Points to settle at that time:

- How to handle the fact that the setting is ignored under `dispatch=local` (warn, or document only)
- Where to place validation rejecting `timeoutSeconds >= lockTtl`
