# Schedule Execution Semantics - Theoretical Analysis of the Missed Execution Problem

**Created**: 2026-01-24
**Subject**: Theoretical foundation for execution guarantees in the Laravel Graceful Schedule Worker extension design

---

## Table of Contents

1. [Problem Definition](#problem-definition)
2. [Theoretical Foundation of Execution Semantics](#theoretical-foundation-of-execution-semantics)
3. [Root Causes of the Problem](#root-causes-of-the-problem)
4. [Requirements for Achieving At-least-once](#requirements-for-achieving-at-least-once)
5. [Tradeoff Analysis](#tradeoff-analysis)
6. [Industry Precedents](#industry-precedents)
7. [Clarifying Responsibility Distribution](#clarifying-responsibility-distribution)
8. [Next Steps](#next-steps)

---

## Problem Definition

### Desired Goal

**At-least-once semantics**: Guarantee that scheduled jobs are executed at least once

### Current State

**At-most-once semantics**: Jobs are executed at most once, but may not execute at all

**Specific problems**:
- When ECS tasks are swapped, the scheduler becomes absent, and jobs during that gap are not executed
- Laravel's `schedule:run` only checks the "current time," so it cannot detect past missed executions
- Tasks are lost during graceful shutdown

---

## Theoretical Foundation of Execution Semantics

Execution guarantees in distributed systems are classified into the following three categories.

### 1. At-most-once

**Definition**: A job may or may not be executed. Duplicate execution never occurs.

**Characteristics**:
- Also known as "Fire-and-forget"
- Simplest implementation
- Used when data loss is acceptable

**Implementation example**:
```
1. Add job to queue
2. Proceed to next processing without waiting for completion
3. Accept the possibility that the queue may be lost
```

**Use cases**:
- Log collection where data loss is acceptable
- Metrics submission (some data point loss is tolerable)
- Best-effort notifications

**Current Laravel Graceful Worker**:
```php
// Current behavior of GracefulScheduleWorkCommand
while ($running) {
    if (Carbon::now()->second === 0) {
        // Check if current time matches the schedule
        // Past unexecuted jobs are not detected
        $process = Process::fromShellCommandline('schedule:run');
        $process->start();
    }
}
// -> At-most-once semantics
```

### 2. At-least-once

**Definition**: A job is retried until it succeeds. Due to ACK (acknowledgment) loss, duplicate execution may rarely occur.

**Characteristics**:
- Default for cloud-native systems
- Requires a mechanism to wait for execution confirmation (ACK)
- Accepts the possibility of duplicate execution

**Implementation example**:
```
1. Add job to queue
2. Retry until execution completion ACK is received
3. If ACK is lost, the job is executed again
   -> Duplicate execution may occur
```

**Use cases**:
- General batch processing
- Data pipelines
- Transaction processing (when idempotency is guaranteed)

**Duplicate execution scenario**:
```
[01:00:00] Start executing Job A
[01:00:30] Job A completes
[01:00:31] Send ACK
[01:00:31] ACK doesn't arrive due to network failure
[01:00:35] Scheduler determines "timeout"
[01:00:36] Re-execute Job A (duplicate execution)
```

### 3. Exactly-once

**Definition**: A job is executed without duplication and without omission.

**Characteristics**:
- Ideal but extremely expensive in distributed systems
- True exactly-once is theoretically impossible (Two Generals' Problem)
- In practice, implemented as "At-least-once + idempotency"

**Implementation methods**:

#### A. Transaction-based
```
1. Manage job execution with distributed transactions
2. Guarantee consistency with two-phase commit (2PC)
3. Extremely high cost (performance degradation)
```

#### B. Achieved through idempotency (common approach)
```
1. Implement with at-least-once (accept duplicate execution)
2. Design the job itself to be idempotent
3. Achieve "the same state as if executed exactly once" as a result
```

**Use cases**:
- Financial transactions
- Payment processing
- Database migrations

**Idempotency implementation example**:

```php
// Non-idempotent example (dangerous)
DB::table('balances')->increment('amount', 100);
// -> If executed twice, the balance increases by 200

// Idempotent example (safe)
DB::table('transactions')
    ->updateOrInsert(
        [
            'job_id' => $jobId,
            'execution_date' => $dueAt,  // <- Unique key
        ],
        [
            'amount' => 100,
            'processed_at' => now(),
        ]
    );
// -> If executed twice, only the same record is updated
```

### Key Insight

> **Execution guarantees in cloud-native job schedulers, in most cases, are synonymous with "providing a reliable retry mechanism and state convergence, premised on idempotent job design"**

In other words:
- **Scheduler's responsibility**: Reliably provide at-least-once (always start as long as resources are available, restart if crashed)
- **Application's responsibility**: Guarantee exactly-once results through idempotency (data is not corrupted even if executed twice with the same input)

---

## Root Causes of the Problem

### 1. Design Constraints of the Laravel Scheduler

Laravel's `schedule:run` is designed based on the "current time."

**Laravel source code (conceptual)**:

```php
// Illuminate/Console/Scheduling/Schedule.php
public function dueEvents($app)
{
    return collect($this->events)->filter->isDue($app);
}

// Illuminate/Console/Scheduling/Event.php
protected function expressionPasses()
{
    $date = Date::now();  // <- Current time only
    return CronExpression::factory($this->expression)->isDue($date);
}
```

**Constraints**:
- No mechanism to detect past missed executions
- Does not record or reference execution history
- Each execution is independent and unaware of the previous state

**Contrast: Kubernetes declarative model**:

```
Kubernetes approach:
- Declare the "desired state"
- Continuously monitor the "actual state" persistently
- Operate to reconcile differences (Reconciliation Loop)

Laravel approach:
- Imperative
- Schedule determination based on current time only
- Does not record past state
```

### 2. Gap During Container Swaps

In ECS/Kubernetes environments, there are moments during deployment when the scheduler is absent.

**Timeline example**:

```
00:00:00 - schedule:run executes on old container (hourly job runs)
00:30:00 - Deployment starts, SIGTERM sent to old container
         - $running set to false
         - New schedule:run is not started (graceful shutdown in progress)

01:00:00 - Scheduler absent (hourly job's execution time)
         - Old container has already terminated
         - New container has not yet started
         - 01:00 hourly job is not executed by anyone X

01:05:00 - New container starts, schedule:run begins
         - isDue() evaluates at 01:05
         - hourly (0 * * * *) is only true during 01:00~01:00:59
         - At 01:05, it returns false
         - 01:00 hourly job is permanently not executed X

02:00:00 - Next hourly job executes normally
```

**Essence of the problem**:
- A period of scheduler absence occurs
- Schedules during the absence period are not detected
- No mechanism to detect past missed executions after recovery

### 3. CronExpression "Minute-level" Evaluation

The `dragonmantank/cron-expression` library's `isDue()` method truncates seconds and evaluates at the "minute" level.

**Behavior example**:

```php
use Cron\CronExpression;

$cron = CronExpression::factory('0 * * * *'); // hourly

// During 01:00:00~01:00:59
$cron->isDue('2024-01-01 01:00:00'); // -> true
$cron->isDue('2024-01-01 01:00:30'); // -> true
$cron->isDue('2024-01-01 01:00:59'); // -> true

// After 01:01:00
$cron->isDue('2024-01-01 01:01:00'); // -> false
$cron->isDue('2024-01-01 01:01:01'); // -> false
```

In other words, **if that minute (60-second window) is missed, it is never detected again**.

**Impact on Laravel**:

```php
// GracefulScheduleWorkCommand
if (Carbon::now()->second === 0) {
    // Only checked at the moment second === 0 (1-second window)
    // Hourly job is only detected during the 1 second at 01:00:00
    $process->start();
}
```

In reality, even without `second === 0`, `isDue()` returns `true` during 01:00:00~01:00:59, but the current implementation only checks when the second is 0.

### 4. Missed Executions During Graceful Shutdown

**Scenario**:

```
00:59:50 - schedule:graceful-work running
00:59:55 - SIGTERM received
         - $running set to false
         - New schedule:run is not started

01:00:00 - Hourly job should execute at this time
         - However, while ($running) is false, so the loop exits
         - 01:00 job is not executed X

01:00:05 - Process exits

01:05:00 - New container starts
         - 01:00 job is permanently not executed X
```

**Root cause**:
- New jobs are not started during graceful shutdown
- The job for the last minute before exit is lost
- Past missed executions are not detected on next startup

---

## Requirements for Achieving At-least-once

### Requirement 1: Persistent Execution History

"When and what was executed" needs to be recorded.

**Required information**:

```php
[
    'event_id' => 'hash(command + cron_expression)',  // Unique event identifier
    'last_executed_due' => '2024-01-01 01:00:00',    // Last execution time (based on scheduled due time)
    'next_due' => '2024-01-01 02:00:00',             // Next scheduled execution time
]
```

**Implementation example (Redis)**:

```php
// Record execution
$cache->put(
    "schedule:executed:{$eventId}",
    $dueAt->timestamp,
    now()->addDays(7)
);

// Get last execution time
$lastDueTimestamp = $cache->get("schedule:executed:{$eventId}");
$lastDue = Carbon::createFromTimestamp($lastDueTimestamp);
```

### Requirement 2: Missed Execution Detection

Detect "jobs that should have been executed but were not" at startup.

**Detection logic**:

```
1. Get last execution time (last_executed_due)
2. Calculate next scheduled execution time from cron expression (next_due)
3. Compare with current time (now)
4. If next_due < now, it's a missed execution
```

**Implementation example**:

```php
public function wasMissed(Event $event, Carbon $now): bool
{
    $lastDue = $this->getLastExecutedDue($event);

    if ($lastDue === null) {
        return false; // First execution
    }

    // Calculate next scheduled execution time from cron expression
    $nextDue = $this->calculateNextDue($event, $lastDue);

    // Missed if current time has passed the next scheduled execution time
    return $nextDue && $now->greaterThan($nextDue->addMinutes(1));
}
```

**Calculation example**:

```
For hourly job (0 * * * *):

Last execution: 2024-01-01 01:00:00
Next scheduled: 2024-01-01 02:00:00
Current time:   2024-01-01 02:05:00

-> 02:05 > 02:00 -> Missed execution detected
```

### Requirement 3: Efficient Filtering

Detect missed executions quickly even when there are many schedules.

**Inefficient implementation (O(n))**:

```php
// Loop through all events and check
foreach ($schedule->events() as $event) {
    if ($this->wasMissed($event, $now)) {
        $this->recover($event);
    }
}
```

**Efficient implementation (O(log n + m))**:

Using Redis Sorted Set:

```php
// Set score as next scheduled time upon execution
$redis->zadd(
    'schedule:next_due',
    $nextDue->timestamp,
    $eventId
);

// Missed execution detection (get only schedules before current time)
$missedEvents = $redis->zrangebyscore(
    'schedule:next_due',
    '-inf',
    $now->timestamp
);
// -> Fast extraction using index
```

**Performance comparison**:

| Number of schedules | O(n) | O(log n + m) |
|--------------------|------|--------------|
| 10 | 10ms | 1ms |
| 100 | 100ms | 5ms |
| 1,000 | 1,000ms | 10ms |
| 10,000 | 10,000ms | 20ms |

### Requirement 4: Ensuring Idempotency (Application Side)

Since at-least-once allows the possibility of duplicate execution, jobs themselves must be idempotent.

**Non-idempotent example (dangerous)**:

```php
// Increment balance each time -> Double charge on duplicate execution
class PaymentJob
{
    public function handle()
    {
        DB::table('balances')->increment('amount', 100);
    }
}
```

**Idempotent example (safe)**:

```php
// Deduplication using transaction ID
class PaymentJob
{
    private $transactionId;
    private $executionDate;

    public function handle()
    {
        DB::table('transactions')
            ->updateOrInsert(
                [
                    'job_id' => $this->transactionId,
                    'execution_date' => $this->executionDate,
                ],
                [
                    'amount' => 100,
                    'processed_at' => now(),
                ]
            );
        // -> Even if executed twice, only the same record is updated
    }
}
```

**Deterministic naming**:

```php
// Generate a unique ID per job execution
$executionId = hash('sha256', $command . $dueAt->toIso8601String());

// Use as Step Functions execution name
$this->client->startExecution([
    'stateMachineArn' => $this->stateMachineArn,
    'name' => $executionId,  // <- Same name for the same input
    // ...
]);
// -> AWS prevents duplicate execution (execution with the same name is rejected)
```

### Requirement 5: Duplicate Execution Prevention Mechanism

A mechanism equivalent to Kubernetes Finalizers or optimistic locking.

#### Finalizers Pattern (Kubernetes)

```
1. Acquire lock before execution starts
2. Update status after execution completes
3. Confirm that status update has been persisted
4. Release lock
```

**Implementation example (Redis Lock)**:

```php
public function executeWithLock(Event $event, Carbon $dueAt): void
{
    $lockKey = "schedule:lock:{$eventId}:{$dueAt->timestamp}";

    // Acquire lock (30 seconds)
    $lock = Cache::lock($lockKey, 30);

    if ($lock->get()) {
        try {
            // Execute job
            $this->dispatchEvent($event, $dueAt);

            // Record execution
            $this->tracker->markExecuted($event, $dueAt);
        } finally {
            // Release lock
            $lock->release();
        }
    } else {
        // Another instance is executing
        Log::info("Job already running: {$eventId}");
    }
}
```

#### Optimistic Locking (ResourceVersion)

Kubernetes etcd approach:

```
1. Include the version at the time of read in the request
2. If another client has updated first, a conflict error occurs
3. Retry and re-fetch
```

**Implementation example (DynamoDB conditional write)**:

```php
// Save execution record with version
$dynamodb->putItem([
    'TableName' => 'schedule_executions',
    'Item' => [
        'event_id' => $eventId,
        'due_at' => $dueAt->timestamp,
        'version' => $currentVersion + 1,
    ],
    'ConditionExpression' => 'version = :current_version',
    'ExpressionAttributeValues' => [
        ':current_version' => $currentVersion,
    ],
]);
// -> ConditionalCheckFailedException if another instance has already updated
```

---

## Tradeoff Analysis

### Complexity vs. Reliability

| Approach | Complexity | Reliability | Use Case |
|----------|-----------|-------------|----------|
| Status quo<br>(At-most-once) | Low | Low | Missed execution acceptable<br>Log collection, metrics submission |
| Check at startup only<br>(Partial at-least-once) | Medium | Medium | Minimize deployment gap<br>General batch processing |
| Continuous checking<br>(Full at-least-once) | High | High | Data pipelines<br>Financial transactions |

### Check Frequency vs. Cost

| Frequency | Cost | Benefits | Drawbacks |
|-----------|------|----------|-----------|
| Startup only | Low | Simple<br>Detects deployment-time missed executions | Cannot detect downtime during runtime |
| Every minute | Medium | Detects runtime downtime as well | Impacts main loop |
| Asynchronous (every 5 minutes) | Medium | No impact on main process | Detection delay |

**Recommendation**: Check at startup only (balance of cost and reliability)

**Reasons**:
- The deployment gap is the primary problem
- Runtime downtime can be detected by ECS/Kubernetes health checks
- Simple and easy to understand

### Number of Schedules vs. Data Structure

| Number of Schedules | Recommended Data Structure | Reason |
|--------------------|---------------------------|--------|
| ~100 | Simple key-value<br>(loop each time) | Simple, low overhead |
| 100~1,000 | Redis Sorted Set<br>(indexed) | O(log n) fast search |
| 1,000~ | Dedicated DB + GSI<br>(DynamoDB) | Persistence, query optimization |

### Consistency Model vs. Performance

| Model | Consistency | Performance | Example | Tradeoff |
|-------|------------|-------------|---------|----------|
| Optimistic locking | Medium | High | Kubernetes etcd | Retry required on conflict |
| Pessimistic locking | High | Low | Airflow row locks | Throughput reduction from lock waits |
| Eventual consistency | Low | High | Eventually converges | Accepts temporary inconsistencies |

**Recommendation**: Optimistic locking (Redis Lock or DynamoDB conditional write)

---

## Industry Precedents

### Comparison Table

| System | Missed Execution Strategy | Consistency Model | Data Structure | Implementation Difficulty |
|--------|--------------------------|-------------------|----------------|--------------------------|
| **Kubernetes CronJob** | startingDeadlineSeconds<br>(grace period) | etcd + optimistic locking | etcd (distributed KVS) | Medium |
| **AWS EventBridge** | Retry policy + DLQ | Internally managed<br>(managed) | Internally managed | Low |
| **Apache Airflow** | catchup + backfill | DB row locks<br>(pessimistic) | PostgreSQL/MySQL | High |
| **Argo Workflows** | Memoization + Finalizers | etcd + optimistic locking | etcd (distributed KVS) | High |
| **Celery Beat** | None<br>(single point of failure) | None | None | Low (basic)<br>High (HA) |

### Airflow catchup (Closest to At-least-once)

**Characteristics**:
- Persists execution history in DB
- Detects unexecuted periods at startup
- Sequential recovery execution

**Behavior example**:

```python
# DAG definition
dag = DAG(
    'hourly_report',
    schedule_interval='0 * * * *',
    start_date=datetime(2024, 1, 1, 0, 0),
    catchup=True,  # <- Important
)

# Deployment time: 2024-01-10 15:00
# -> Sequentially executes everything from 2024-01-01 00:00 to 2024-01-10 14:00
```

**Application to Laravel**:

```php
// ExecutionTracker implementation
public function checkMissedEvents(Schedule $schedule, Carbon $now): void
{
    foreach ($schedule->events() as $event) {
        $lastDue = $this->getLastExecutedDue($event);

        if ($lastDue === null) {
            continue; // First execution
        }

        // Calculate all scheduled execution times from last execution to now
        $missedDues = $this->calculateMissedDues($event, $lastDue, $now);

        foreach ($missedDues as $missedDue) {
            // Sequential recovery execution
            $this->dispatchEvent($event, $missedDue);
        }
    }
}
```

### Argo Workflows Memoization

**Characteristics**:
- Uses hash of step input parameters as cache key
- Avoids duplicate execution during failure recovery
- "By skipping computation with side effects, reproduces the same state as if executed exactly once"

**Implementation example**:

```yaml
apiVersion: argoproj.io/v1alpha1
kind: Workflow
metadata:
  name: hourly-report
spec:
  entrypoint: main
  templates:
  - name: main
    memoize:
      key: "{{inputs.parameters.execution-date}}"
      cache:
        configMap:
          name: workflow-memoization
    inputs:
      parameters:
      - name: execution-date
    container:
      image: my-app:latest
      command: ["php", "artisan", "report:hourly"]
```

**Behavior**:

```
1st execution:
- execution-date: 2024-01-01 01:00
- Cache key: hash("2024-01-01 01:00")
- Cache miss -> Execute
- Save result to cache

2nd execution (retry):
- execution-date: 2024-01-01 01:00
- Cache key: hash("2024-01-01 01:00")
- Cache hit -> Skip (return result from cache)
```

**Application to Laravel**:

```php
// Use deterministic execution name
$executionName = hash('sha256', $event->command . $dueAt->toIso8601String());

// Execute via Step Functions
try {
    $this->client->startExecution([
        'stateMachineArn' => $this->stateMachineArn,
        'name' => $executionName,  // <- Same name is rejected
        'input' => json_encode([/* ... */]),
    ]);
} catch (ExecutionAlreadyExistsException $e) {
    // Already executed -> Skip
    Log::info("Execution already exists: {$executionName}");
}
```

### Kubernetes Finalizers

**Characteristics**:
- When deleting a resource, deletion is deferred until specific processing completes
- Guarantees reliable execution of cleanup processing

**Behavior**:

```yaml
apiVersion: v1
kind: Pod
metadata:
  name: my-pod
  finalizers:
  - cleanup.example.com/finalizer
spec:
  containers:
  - name: app
    image: my-app:latest
```

```
1. Pod deletion request
2. metadata.deletionTimestamp is set
3. Pod is not deleted while Finalizers remain
4. Controller executes cleanup processing
5. Remove Finalizer
6. Pod is deleted
```

**Application to Laravel**:

```php
// Add Finalizer at execution start
$this->tracker->addFinalizer($event, $dueAt, 'execution');

try {
    // Execute job
    $this->dispatchEvent($event, $dueAt);

    // Record completion
    $this->tracker->markExecuted($event, $dueAt);
} finally {
    // Remove Finalizer
    $this->tracker->removeFinalizer($event, $dueAt, 'execution');
}

// Detect jobs with remaining Finalizers at startup
$pendingJobs = $this->tracker->getJobsWithFinalizers();
foreach ($pendingJobs as $job) {
    // Re-execute incomplete jobs
    $this->dispatchEvent($job['event'], $job['due_at']);
}
```

---

## Clarifying Responsibility Distribution

### Shift-Left Approach

Delegate execution guarantee responsibility not only to the scheduler but also to the application design side.

```
+-------------------------------------------------------------+
|                    Scheduler's Responsibility                 |
|                                                               |
|  - Reliably provide at-least-once                            |
|  - Always start as long as resources are available           |
|  - Restart if crashed                                         |
|  - Detect missed executions and perform recovery             |
|  - Persist execution history                                  |
|  - Prevent duplicate execution (lock mechanism)              |
|                                                               |
+-------------------------------------------------------------+
                            |
+-------------------------------------------------------------+
|                 Application's Responsibility                  |
|                                                               |
|  - Ensure idempotency                                        |
|  - Data not corrupted even if executed twice with same input |
|  - Deterministic naming (deduplication)                      |
|  - Proper transaction boundary design                        |
|  - Business logic consistency guarantee                      |
|                                                               |
+-------------------------------------------------------------+
```

### Scheduler's Responsibility

#### 1. Providing At-least-once

```php
// Detect missed executions at startup
public function boot(): void
{
    $now = Carbon::now();

    foreach ($this->schedule->events() as $event) {
        if ($this->tracker->wasMissed($event, $now)) {
            // Re-execute missed executions
            $missedDue = $this->tracker->getLastExecutedDue($event);
            $this->dispatchEvent($event, $missedDue);
        }
    }
}
```

#### 2. Persistent Execution History

```php
// Record after execution
public function dispatchEvent(Event $event, Carbon $dueAt): void
{
    $this->client->startExecution([/* ... */]);

    // Record execution
    $this->tracker->markExecuted($event, $dueAt);
}
```

#### 3. Preventing Duplicate Execution

```php
// Use locking
public function dispatchEvent(Event $event, Carbon $dueAt): void
{
    $lock = Cache::lock("schedule:{$eventId}:{$dueAt->timestamp}", 30);

    if ($lock->get()) {
        try {
            $this->client->startExecution([/* ... */]);
            $this->tracker->markExecuted($event, $dueAt);
        } finally {
            $lock->release();
        }
    }
}
```

### Application's Responsibility

#### 1. Ensuring Idempotency

```php
// Bad example
class ReportJob
{
    public function handle()
    {
        // Count up each time -> Double count on duplicate execution
        DB::table('stats')->increment('count');
    }
}

// Good example
class ReportJob
{
    private $executionDate;

    public function handle()
    {
        // updateOrInsert with execution date as key -> Idempotent
        DB::table('daily_reports')
            ->updateOrInsert(
                ['date' => $this->executionDate],
                ['count' => $this->calculateCount()]
            );
    }
}
```

#### 2. Transaction Boundary Design

```php
class PaymentJob
{
    public function handle()
    {
        DB::transaction(function () {
            // 1. Duplicate check
            $exists = DB::table('transactions')
                ->where('transaction_id', $this->transactionId)
                ->exists();

            if ($exists) {
                Log::info("Transaction already processed: {$this->transactionId}");
                return;
            }

            // 2. Record transaction
            DB::table('transactions')->insert([
                'transaction_id' => $this->transactionId,
                'amount' => $this->amount,
                'processed_at' => now(),
            ]);

            // 3. Update balance
            DB::table('balances')->increment('amount', $this->amount);
        });
    }
}
```

#### 3. Business Logic Consistency

```php
class InvoiceGenerationJob
{
    public function handle()
    {
        // Guarantee uniqueness per period
        $invoiceId = hash('sha256', $this->customerId . $this->period);

        DB::table('invoices')
            ->updateOrInsert(
                [
                    'invoice_id' => $invoiceId,
                    'customer_id' => $this->customerId,
                    'period' => $this->period,
                ],
                [
                    'amount' => $this->calculateAmount(),
                    'generated_at' => now(),
                ]
            );
    }
}
```

---

## Next Steps

### Decisions to Make

#### 1. Check Frequency

- **Recommendation**: Startup only (balance of cost and reliability)
- **Alternative**: Periodic background check (every 5 minutes)

#### 2. Data Structure

**For a small number of schedules (~100)**:
```php
// Simple key-value
$cache->put("schedule:executed:{$eventId}", $dueAt->timestamp);
```

**For a moderate number of schedules (100~1,000)**:
```php
// Redis Sorted Set
$redis->zadd('schedule:next_due', $nextDue->timestamp, $eventId);
```

**For a very large number of schedules (1,000~)**:
```php
// DynamoDB + GSI
// GSI: next_due-index
```

#### 3. Storage

| Storage | Benefits | Drawbacks | Recommended Scenario |
|---------|----------|-----------|---------------------|
| Redis | Fast, simple | Persistence concerns | General use cases |
| DynamoDB | Persistence, scalability | Cost, latency | Large-scale, long-term storage |
| PostgreSQL | ACID, complex queries | Overhead | Data pipelines |

**Recommendation**: Redis (ElastiCache)

#### 4. Idempotency Requirements

**Option A**: Document explicitly

```markdown
# Important: Job Idempotency

Laravel Graceful Schedule Worker provides at-least-once semantics.
Since duplicate execution is possible, design all scheduled jobs to be idempotent.

See the [Idempotency Guide](../guide/IDEMPOTENCY_GUIDE.md) for details.
```

**Option B**: Enforce through implementation

```php
// Require IdempotencyKey from jobs
interface IdempotentJob
{
    public function getIdempotencyKey(): string;
}

// Validate in Dispatcher
if (! $job instanceof IdempotentJob) {
    throw new \RuntimeException('Job must be idempotent');
}
```

**Recommendation**: Option A (document explicitly)
- Higher flexibility
- Compatibility with existing jobs

### Additional Topics to Consider

#### 1. Zombie Task Detection

Detect tasks that crashed during execution.

```php
// Heartbeat approach
public function dispatchEvent(Event $event, Carbon $dueAt): void
{
    // Record execution start
    $this->tracker->markStarted($event, $dueAt);

    $this->client->startExecution([/* ... */]);

    // Record execution completion
    $this->tracker->markCompleted($event, $dueAt);
}

// Monitor in a separate process
public function detectZombies(): void
{
    $zombies = $this->tracker->getStartedButNotCompleted();

    foreach ($zombies as $zombie) {
        // Re-execute zombies that have exceeded timeout
        if ($zombie['started_at']->addMinutes(30)->isPast()) {
            $this->dispatchEvent($zombie['event'], $zombie['due_at']);
        }
    }
}
```

#### 2. Leader Election

When running multiple scheduler instances, elect a leader.

```php
// Leader election using Redis lock
public function electLeader(): bool
{
    $lock = Cache::lock('schedule:leader', 30);

    if ($lock->get()) {
        // Operate as leader
        $this->runAsLeader($lock);
        return true;
    }

    // Wait as follower
    return false;
}

public function runAsLeader(Lock $lock): void
{
    while ($this->running) {
        // Execute schedule
        $this->runSchedule();

        // Renew lock (maintain leadership)
        $lock->block(30);
    }

    $lock->release();
}
```

#### 3. Backoff Strategy

Exponential backoff for retries.

```php
public function retryWithBackoff(Event $event, Carbon $dueAt, int $attempt = 0): void
{
    try {
        $this->dispatchEvent($event, $dueAt);
    } catch (\Exception $e) {
        if ($attempt >= 5) {
            // Maximum retry count reached
            Log::error("Failed to dispatch event: {$event->command}", [
                'exception' => $e,
                'attempts' => $attempt,
            ]);
            return;
        }

        // Exponential backoff: wait 2^attempt seconds
        $delay = pow(2, $attempt);
        sleep($delay);

        // Retry
        $this->retryWithBackoff($event, $dueAt, $attempt + 1);
    }
}
```

---

## Summary

### Key Insights

1. **At-least-once is the industry standard**: Cloud-native systems provide at-least-once, and the common responsibility distribution is for applications to guarantee idempotency

2. **Complete guarantees are difficult**: Exactly-once is theoretically impossible and is achieved through "at-least-once + idempotency"

3. **Understanding tradeoffs**: It is important to understand the balance between complexity and reliability and make choices appropriate to the use case

4. **Incremental implementation**: A phased approach of Phase 1 (refactoring) -> Phase 2 (StepFunctions) -> Phase 3 (ExecutionTracker) is realistic

### Recommended Approach

**Laravel Graceful Schedule Worker extension design**:

1. **ExecutionTracker**: Persistent execution history using Redis
2. **Startup check**: Detect and recover missed executions during deployment
3. **Idempotency guide**: Provide best practices through documentation
4. **StepFunctions integration**: Resource isolation and scalability

This design enables providing **at-least-once semantics** with an implementation that follows industry-standard best practices.

---

**References**:
- [SCHEDULER_COMPARISON.md](./SCHEDULER_COMPARISON.md) - Industry scheduler comparison
- [Two Generals' Problem](https://en.wikipedia.org/wiki/Two_Generals%27_Problem) - Theoretical constraints of distributed systems
- [Idempotence - AWS Well-Architected Framework](https://docs.aws.amazon.com/wellarchitected/latest/framework/rel_tracking_change_management_use_automation.html)

---

**Last Updated**: 2026-01-24
**Version**: 1.0.0
**Author**: Laravel Graceful Schedule Worker Team
