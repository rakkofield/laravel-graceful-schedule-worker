# Laravel Graceful Schedule Worker - Architecture Design

## Table of Contents

### Part 1: Design Overview

1. [Overview and Background](#overview-and-background)
2. [Execution Guarantee Model](#execution-guarantee-model)
3. [Approach Comparison](#approach-comparison)
4. [Problem Details](#problem-details)
5. [Solution Design](#solution-design)

### Part 2: Constraints and References

6. [Constraints and Notes](#constraints-and-notes)
7. [Glossary](#glossary)
8. [References](#references)

---

# Part 1: Design Overview

## Overview and Background

### Package Purpose

`laravel-graceful-schedule-worker` is a lightweight package for gracefully executing Laravel scheduled tasks. It runs `php artisan schedule:run` as a long-running process and properly terminates running tasks when SIGINT/SIGTERM signals are received.

**Current main features:**

- Launches `schedule:run` as a child process every minute
- Streams child process output to the console in real-time
- Graceful shutdown upon receiving SIGINT/SIGTERM
- Waits for running tasks to complete before exiting

### Motivation for Extension

**Background: Job interruption problem during ECS task termination**

When operating this package in an Amazon ECS environment, the following issues arise:

1. **ECS task termination**: ECS tasks are stopped during deployments or scale-in events
2. **Graceful period limitation**: All scheduled tasks must complete within ECS's stop timeout (default 30 seconds, maximum 120 seconds)
3. **Long-running job interruption**: Tasks with long execution times may be interrupted and not executed subsequently

### Proposed Solution Overview

This design introduces the **ClockAware Orchestrator pattern** to solve the following problems:

- **Resource isolation**: Execute scheduled tasks on separate ECS tasks/Lambda via AWS Step Functions or EventBridge Scheduler
- **Missed execution prevention**: Track execution history to detect and recover unexecuted tasks
- **Extensibility**: Works conventionally in local environments while using external orchestrators in production
- **Flexibility**: Supports dynamic conditions like `skip()` / `when()` and provides extension methods like `withGracePeriod()`

---

## Execution Guarantee Model

### At-least-once Semantics

This package provides **at-least-once semantics**.

**Execution guarantee definition**:

- **Scheduled jobs are guaranteed to execute at least once, as long as resources are available**
- **During network failures or crash recovery, duplicate execution may rarely occur**

### Responsibility Distribution

| Layer | Responsibility |
|---|---------|
| **Orchestrator (Scheduler)** | At-least-once launch guarantee, due determination, missed execution recovery, execution history persistence |
| **Step Functions (Execution Platform)** | Execution reliability (retries, timeouts), duplicate prevention via DynamoDB locks as needed |
| **Worker (Application Logic)** | Business logic idempotency, appropriate transaction boundary design |

Note: Step Functions and Worker are included in "application-side" responsibilities.

### Alignment with Industry Standards

This responsibility distribution follows the standard approach for cloud-native systems:

- **AWS EventBridge Scheduler**: At-least-once delivery guarantee (retry policy + DLQ)
- **Apache Airflow**: Automatic recovery via Catchup/Backfill
- **Kubernetes CronJob**: Limited grace period via startingDeadlineSeconds

See [SCHEDULE_EXECUTION_SEMANTICS.md](./SCHEDULE_EXECUTION_SEMANTICS.md) for details.

> **Note**: For application-side idempotency implementation, see
> [IDEMPOTENCY_GUIDE.md](../guide/IDEMPOTENCY_GUIDE.md)

---

## Approach Comparison

### Scheduler Selection: EventBridge Scheduler vs ClockAware Orchestrator

When operating Laravel scheduled tasks in production, there are two approaches for **schedule management**.

| Aspect | EventBridge Scheduler | ClockAware Orchestrator |
|------|----------------------|------------------------|
| **Schedule definition** | Managed via IaC (Terraform/CDK) | Managed in Kernel.php (PHP) |
| **Dynamic conditions (when/skip)** | Not possible<br>(cron expressions only) | Supported<br>(Laravel's flexible syntax) |
| **Delivery guarantee** | At-least-once<br>(AWS managed) | At-least-once<br>(custom implementation + ExecutionTracker) |
| **Migration cost** | High<br>(rewriting schedule definitions) | Low<br>(package installation only) |
| **Operational complexity** | Low<br>(AWS managed) | Medium<br>(Orchestrator ECS Task + Redis required) |
| **Extensibility** | Low<br>(cron expressions only) | High<br>(withGracePeriod, etc.) |
| **Testability** | Low<br>(IaC testing required) | High<br>(existing Laravel tests) |

**Important**: With either approach, **executing jobs via Step Functions is recommended**.

### Job Execution Method: Dispatcher Selection

You can choose the execution method (Dispatcher) when the scheduler invokes a job.

| Dispatcher | Execution Environment | Use Case | Resource Isolation |
|-----------|---------|------|------------|
| **LocalDispatcher** | Process spawned in the same container | Local development, lightweight jobs | None |
| **StepFunctionsDispatcher** | ECS Task/Lambda via Step Functions | Production, long-running jobs | Yes |

**Recommended configuration**:
```
ClockAware Orchestrator (schedule management)
  + StepFunctionsDispatcher (job execution)
  + ExecutionTracker (missed execution detection)
```

### Recommended Decision Criteria

**Recommend ClockAware Orchestrator when**:

- Using `skip()` / `when()` (dynamic conditions needed)
- Want to manage schedule definitions in Kernel.php
- Want to leverage Laravel's flexible scheduling syntax
- Want to reuse existing schedule definitions as-is
- Need extension features like withGracePeriod()
- **Job execution uses StepFunctionsDispatcher**

**Recommend EventBridge Scheduler when**:

- All schedules can be expressed with fixed cron expressions
- Not using dynamic conditions (skip/when)
- Prefer a fully managed service
- Want to manage schedule definitions via IaC
- **Delegate both schedule management and job execution to AWS**

**Important**: If dynamic conditions can be removed in the future, migration to EventBridge Scheduler remains an option.

### Dispatcher Selection Guidelines

Optimize cost and performance by selecting the appropriate Dispatcher per job.

| Frequency | Execution Time | Recommended Dispatcher | Reason |
|------|---------|----------------|------|
| everyMinute | < 30 seconds | Local | Overhead/cost not justified |
| everyMinute | 30 seconds - 5 minutes | Case-by-case | Decide based on importance of resource isolation |
| everyMinute | > 5 minutes | Step Functions | Resource isolation is essential |
| everyFiveMinutes or less frequent | Any | Step Functions | Cost-effective |
| Closure job | Any | Local | Cannot be serialized |

**Cost estimate example (everyMinute x 1 job)**:
- Per day: 1,440 executions
- Per month: ~43,200 executions
- Standard Workflow ($0.025/1,000 transitions):
  - Minimum configuration (Start -> Task -> End = 3 transitions): ~$3.24/month
- Express Workflow (execution count + execution time):
  - With 10-second execution time: ~$0.50/month

**Recommended approach**:
- Default to Step Functions (resource isolation benefits)
- Override high-frequency, lightweight jobs to local execution
- Closure jobs automatically use local execution

---

## Problem Details

### Problem A: Lack of Resource Isolation

**Current state**: All scheduled tasks execute within the same ECS task

- The scheduler process and actual job execution run in the same container
- Long-running jobs are forcefully terminated when the ECS task stops
- Jobs that don't complete within the graceful period are interrupted

**Impact**:

```
[00:00] schedule:graceful-work starts
[00:01] Long-running job A begins (expected execution time: 5 minutes)
[00:02] ECS task stop signal received
[00:02] Graceful period begins (max 120 seconds)
[00:04] Job A still running...
[00:04] Graceful period ends, Job A forcefully terminated ❌
```

### Problem B: Missed Execution Risk

**Current state**: Unexecuted tasks are lost during graceful shutdown

Example scenario:

```
[00:59:50] schedule:graceful-work running
[00:59:55] SIGTERM received, running = false
[01:00:00] At this point, no new schedule:run is launched
           -> Tasks scheduled for 1:00 are not executed ❌
```

**Issues**:

- No new tasks are launched after signal reception
- Scheduled tasks for that period are not executed until the next ECS task starts
- For hourly or daily tasks, a long wait until the next execution

### Problem C: Laravel Scheduler Limitations

**Laravel's `schedule:run` specification**:

- Only evaluates at "minute" granularity
- No mechanism to automatically recover past unexecuted tasks
- Does not account for graceful shutdown or missed executions

**Example**:

```php
// app/Console/Kernel.php
$schedule->command('report:daily')
    ->dailyAt('03:00');
```

- If the task is not executed at 03:00, the next execution waits until 03:00 the following day
- Even if the server restarts in between, past missed executions are not detected

---

## Solution Design

### Introducing the ClockAware Orchestrator Pattern

**Basic concept**: Extend Laravel's Schedule with due determination using an external Clock

```
+----------------------------------------------------------+
| Kernel.php (only type hint changes)                       |
|   use ClockAwareSchedule;                                |
|   protected function schedule(ClockAwareSchedule $schedule)|
|   - skip() / when() available (as before)                |
|   - withGracePeriod() to enable recovery (explicit)      |
|   - IDE completion works, PHPStan/Psalm passes           |
|   - Default: no recovery (safety first)                  |
+----------------------------------------------------------+
         |
         v
+----------------------------------------------------------+
| ClockAwareSchedule / ClockAwareEvent                      |
|   - Due determination with external Clock                 |
|   - Inherits Laravel's Schedule / Event (backward compat) |
+----------------------------------------------------------+
         |
         v
+----------------------------------------------------------+
| Orchestrator (ECS Task)                                   |
|   - Timer management per cycle                            |
|   - Due determination -> dispatch to Dispatcher           |
|   - Execution recording via ExecutionTracker              |
|   - Startup recovery                                      |
+----------------------------------------------------------+
         |
         v
+----------------------------------------------------------+
| ScheduleDispatcher                                        |
|   +-- LocalDispatcher (backward compatible)               |
|   +-- StepFunctionsDispatcher                             |
+----------------------------------------------------------+
```

### ClockAware Pattern Details

#### Clock Dependency Injection

```php
// SystemClock: Production environment
class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

// FixedClock: Test environment
class FixedClock implements ClockInterface
{
    private DateTimeImmutable $fixedTime;

    public function __construct(string $time)
    {
        $this->fixedTime = new DateTimeImmutable($time);
    }

    public function now(): DateTimeImmutable
    {
        return $this->fixedTime;
    }
}
```

#### ClockAwareEvent Extension Methods

```php
// Usage example in Kernel.php

// Default: no recovery (safe)
$schedule->command('heartbeat:send')
    ->everyMinute();

// Enable recovery for important jobs only
$schedule->command('metrics:aggregate')
    ->hourly()
    ->withGracePeriod(30);  // Recovery enabled + 30-minute grace period

$schedule->command('reports:generate')
    ->dailyAt('03:00')
    ->skip(fn() => Holiday::isToday())
    ->enableRecovery();  // Recovery enabled (no grace period = unlimited)
```

### Missed Execution Detection Timing

**Recommended**: Check at startup only (balance of cost and reliability)

```php
// GracefulScheduleWorkCommand::handle()
public function handle()
{
    // 1. Check for missed executions at startup
    $this->recoverMissedEvents();

    // 2. Normal schedule execution
    $this->runSchedule();
}

private function recoverMissedEvents(): void
{
    foreach ($this->schedule->events() as $event) {
        if ($this->tracker->wasMissed($event, now())) {
            $missedDue = $this->tracker->calculateMissedDue($event);
            $this->dispatcher->dispatch($event, $missedDue);
        }
    }
}
```

See [SCHEDULE_EXECUTION_SEMANTICS.md](./SCHEDULE_EXECUTION_SEMANTICS.md) for details.

### Architecture Flow

#### LocalDispatcher (Existing Behavior)

```
1. Scheduler: Detects tasks to execute
2. LocalDispatcher: Executes via Process::fromShellCommandline()
3. Runs as a child process within the same container
```

#### StepFunctionsDispatcher (New)

```
1. Scheduler: Detects tasks to execute
2. StepFunctionsDispatcher: Launches Step Functions via AWS SDK
   - Input: { "command": "report:daily", "options": [...] }
   - ExecutionName: hash(command + dueAt) for duplicate prevention
3. Step Functions: Executes ECS RunTask or Lambda Invoke
4. ExecutionTracker: Records execution
5. Scheduler: Proceeds to next task evaluation without waiting (Fire & Forget)
```

### Per-Job Dispatcher Selection

The default Dispatcher is specified via global configuration, and can be overridden per event.

#### Configuration File

```php
// config/graceful-scheduler.php
return [
    // Local is recommended for local development
    // Set SCHEDULE_DISPATCH=stepfunctions for production
    'dispatch' => env('SCHEDULE_DISPATCH', 'local'),
];
```

#### Usage in Kernel.php

```php
// Use default (Step Functions)
$schedule->command('heavy:job')
    ->hourly()
    ->withGracePeriod(60);

// Force local execution (high-frequency, lightweight jobs)
$schedule->command('heartbeat:send')
    ->everyMinute()
    ->dispatchVia('local');

// Closure job (automatically local)
$schedule->call(fn() => $this->cleanup())
    ->hourly();
```

#### ClockAwareEvent Extension Method

The `dispatchVia()` method allows selecting the Dispatcher per job.

```php
class ClockAwareEvent extends Event
{
    protected ?string $dispatcher = null;  // null = use global setting

    public function dispatchVia(string $dispatcher): self
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    public function getDispatcher(): ?string
    {
        return $this->dispatcher;
    }
}
```

**Implementation details**:
- When the `dispatcher` property is `null`, the global setting (`config('graceful-scheduler.dispatch')`) is used
- Closure jobs automatically fall back to `LocalDispatcher` (cannot be serialized)
- Can be explicitly specified with `dispatchVia('local')` or `dispatchVia('stepfunctions')`

**Resolution logic example**:

```php
// Resolution logic in ScheduleDispatcherFactory (conceptual)
public function resolveDispatcher(ClockAwareEvent $event): ScheduleDispatcher
{
    // 1. Closure jobs cannot be serialized, force Local
    if ($event->isClosure()) {
        return new LocalDispatcher();
    }

    // 2. Use explicit specification if present on the event
    if ($dispatcher = $event->getDispatcher()) {
        return $this->createDispatcher($dispatcher);
    }

    // 3. Use global setting
    return $this->createDispatcher(config('graceful-scheduler.dispatch'));
}
```

---

# Part 2: Step Functions Implementation

> See [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md) for details

---

# Part 2: Constraints and References

## Constraints and Notes

### Laravel Version Constraints

- **Supported versions**: Laravel 6.x, 7.x
- **PHP version**: 7.2.5 or later
- Laravel 8+ requires separate verification

### Closure Job Limitations

**Problem**: Closures cannot be serialized and thus cannot be passed to Step Functions

```php
// ❌ Cannot execute with StepFunctionsDispatcher
$schedule->call(function () {
    // ...
})->everyMinute();

// ✅ Artisan commands can be executed
$schedule->command('report:daily')->dailyAt('03:00');
```

**Countermeasure**:

- When using `StepFunctionsDispatcher`, define all scheduled tasks as Artisan commands or job classes
- If using Closures, specify `dispatch=local` in configuration

### Redis/Shared Cache Requirements

**ExecutionTracker prerequisites**:

- Shared storage such as Redis or DynamoDB is required to share execution history across multiple ECS tasks
- Local caches (file, array) cannot be shared across multiple instances

**Recommended configuration**:

```
ECS Task 1 (Scheduler) --+
ECS Task 2 (Scheduler) --+----> ElastiCache (Redis)
ECS Task 3 (Scheduler) --+
```

### AWS Permission Requirements

**When using StepFunctionsDispatcher**:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "states:StartExecution"
      ],
      "Resource": "arn:aws:states:ap-northeast-1:123456789012:stateMachine:ScheduleExecutor"
    }
  ]
}
```

### Performance Considerations

**Step Functions limitations**:

- StartExecution API rate limits:
  - Standard Workflow: 2,000 calls/second (default, can be increased)
  - Express Workflow: 100,000 calls/second (for synchronous invocation)
- Consider batch processing for a large number of scheduled tasks

**Tracker overhead**:

- Scans all events on each per-minute execution check
- Consider indexing or filtering for a large number of events

---

## Glossary

| Term | Description |
|------|------|
| **Graceful shutdown** | When a process receives SIGTERM, it waits for running tasks to complete before exiting |
| **Fire & Forget** | A pattern where an asynchronous process is launched and the caller proceeds without waiting for the result |
| **Missed execution** | A scheduled task that was not executed at its scheduled time and was skipped |
| **Orchestrator** | A component responsible for coordinating and managing multiple services or tasks |
| **ECS RunTask** | An API to launch a new task in Amazon ECS |
| **Step Functions** | An AWS serverless orchestration service |

---

## References

### Internal Documents

- [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md) - Step Functions implementation details
- [SCHEDULE_EXECUTION_SEMANTICS.md](./SCHEDULE_EXECUTION_SEMANTICS.md) - Theoretical background and responsibility distribution of execution guarantees
- [SCHEDULER_COMPARISON.md](./SCHEDULER_COMPARISON.md) - Industry scheduler comparison (Kubernetes, Airflow, EventBridge, Celery Beat)
- [IDEMPOTENCY_GUIDE.md](../guide/IDEMPOTENCY_GUIDE.md) - Idempotency guidelines

### Laravel

- [Laravel Task Scheduling](https://laravel.com/docs/7.x/scheduling) - Laravel's scheduling feature
- [Laravel Database Transactions](https://laravel.com/docs/7.x/database#database-transactions) - Transaction handling

### AWS

- [AWS Step Functions Developer Guide](https://docs.aws.amazon.com/step-functions/) - Official Step Functions guide
- [Step Functions and Amazon ECS/Fargate](https://docs.aws.amazon.com/step-functions/latest/dg/connect-ecs.html) - Step Functions and ECS/Fargate integration
- [Step Functions Best Practices](https://docs.aws.amazon.com/step-functions/latest/dg/sfn-best-practices.html) - Step Functions best practices
- [Step Functions Service Quotas](https://docs.aws.amazon.com/step-functions/latest/dg/limits.html) - Step Functions service quotas
- [Amazon ECS Task Lifecycle](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/task-lifecycle.html) - ECS task lifecycle
- [EventBridge Scheduler](https://docs.aws.amazon.com/eventbridge/latest/userguide/using-eventbridge-scheduler.html) - EventBridge Scheduler usage

### Distributed Systems Theory

- [Two Generals' Problem](https://en.wikipedia.org/wiki/Two_Generals%27_Problem) - Theoretical constraints of distributed systems
- [Idempotence - AWS Well-Architected Framework](https://docs.aws.amazon.com/wellarchitected/latest/framework/rel_tracking_change_management_use_automation.html) - AWS idempotency guide

### Other

- [Symfony Process Component](https://symfony.com/doc/current/components/process.html) - Process management
- [cron-expression Library](https://github.com/dragonmantank/cron-expression) - Cron expression parser

---

**Last Updated**: 2026-01-25
**Version**: 2.0.0 (ClockAware Orchestrator + Step Functions Integration)
**Author**: Laravel Graceful Schedule Worker Team
