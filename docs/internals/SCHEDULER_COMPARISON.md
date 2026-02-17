# Scheduler System Missed Execution Strategies - Industry Survey

**Survey Date**: 2026-01-24

## Overview

In preparation for designing extensions to Laravel Graceful Schedule Worker, we surveyed how other major scheduler systems handle "missed executions."

This document provides a comparative analysis of the following systems:

1. Kubernetes CronJob
2. AWS EventBridge Scheduler
3. Apache Airflow
4. Celery Beat

---

## 1. Kubernetes CronJob

### Approach: Limited Retry via Grace Period

Kubernetes CronJob uses the `startingDeadlineSeconds` field to specify how many seconds past the scheduled time a job is still allowed to start.

#### Configuration Example

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: hourly-report
spec:
  schedule: "0 * * * *"  # Every hour at minute 0
  startingDeadlineSeconds: 300  # 5-minute grace period
  concurrencyPolicy: Forbid
  successfulJobsHistoryLimit: 3
  failedJobsHistoryLimit: 1
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: report
            image: my-app:latest
            command: ["php", "artisan", "report:hourly"]
          restartPolicy: OnFailure
```

#### Behavior Details

**Normal case**:
```
01:00:00 - Scheduled time
01:00:05 - Job started successfully ✅
```

**Delayed within grace period**:
```
01:00:00 - Scheduled time
         - Node is busy, Pod cannot start
01:02:30 - Node becomes available
         - Current time 01:02:30 < 01:00:00 + 300 seconds
         - Job started ✅ (delayed execution)
```

**Grace period exceeded**:
```
01:00:00 - Scheduled time
         - Entire cluster is down
01:05:01 - Cluster recovered
         - Current time 01:05:01 > 01:00:00 + 300 seconds
         - 01:00 Job is not executed ❌
01:00:00 - Next (02:00) Job runs normally
```

#### 100-Miss Limit

The CronJob Controller counts how many schedules were missed since the last execution time. **If it exceeds 100**, the job is not started and an error log is emitted.

```
Cannot determine if <namespace>/<cronjob> needs to be started:
Too many missed start time (> 100).
Set or decrease .spec.startingDeadlineSeconds or check clock skew.
```

#### Preventing Duplicate Execution

Controlled via `concurrencyPolicy`:

- **Allow** (default): Multiple Jobs can run concurrently
- **Forbid**: Skip new Job if the previous one is still running
- **Replace**: Stop previous Job and start new one

#### Constraints and Notes

- **Completely lost during cluster downtime**: If Kubernetes itself is down, schedules during that time are not recorded
- **"At most once" semantics**: Guarantees at most one execution, but not exactly once
- **No automatic recovery of missed executions**: Jobs that exceed the grace period are never executed
- **Clock synchronization is important**: Clock skew between nodes can cause malfunctions

**References**:
- [Kubernetes CronJob Documentation](https://kubernetes.io/docs/concepts/workloads/controllers/cron-jobs/)
- [Fix CronJob missed start time handling PR](https://github.com/kubernetes/kubernetes/pull/81557)
- [Ensure CronJob has a configured deadline - Datree](https://hub.datree.io/built-in-rules/ensure-cronjob-deadline)

---

## 2. AWS EventBridge Scheduler

### Approach: Retry Policy + Dead Letter Queue

EventBridge Scheduler focuses on retries when **target invocation fails**.

#### RetryPolicy Configuration

```json
{
  "Name": "hourly-report",
  "ScheduleExpression": "cron(0 * * * ? *)",
  "Target": {
    "Arn": "arn:aws:ecs:ap-northeast-1:123456789012:cluster/my-cluster",
    "RoleArn": "arn:aws:iam::123456789012:role/EventBridgeSchedulerRole",
    "EcsParameters": {
      "TaskDefinitionArn": "arn:aws:ecs:ap-northeast-1:123456789012:task-definition/report:1",
      "LaunchType": "FARGATE"
    },
    "RetryPolicy": {
      "MaximumRetryAttempts": 185,
      "MaximumEventAgeInSeconds": 86400
    },
    "DeadLetterConfig": {
      "Arn": "arn:aws:sqs:ap-northeast-1:123456789012:scheduler-dlq"
    }
  }
}
```

#### Behavior Details

**Retry mechanism**:
```
01:00:00 - Scheduled time
         - ECS RunTask API call
         - Error: ThrottlingException (API rate limit)

01:00:01 - 1st retry (1 second later)
         - Error: ServiceUnavailableException

01:00:03 - 2nd retry (2 seconds later, exponential backoff)
         - Error: ClusterNotFoundException

01:00:07 - 3rd retry (4 seconds later)
         - Success ✅
```

**Final failure**:
```
01:00:00 - Scheduled time
         - ECS RunTask failures continue...

[After 24 hours or 185 retries]

02:00:00 (next day) - All retries failed
                    - Event sent to DLQ (SQS)
                    - Alert triggered (CloudWatch Alarms, etc.)
```

#### Constraints and Notes

- **Cannot prevent missed schedules**:
  - 01:00 invocation fails -> 02:00 schedule is treated as a separate event
  - Even if 01:00 retries continue until 02:00, the 02:00 job is started separately

- **Targets only "execution failure"**:
  - EventBridge Scheduler itself is highly available and redundant
  - Schedule firing is guaranteed, but target invocation success is not

- **Cost considerations**:
  - Charges are incurred based on retry count
  - SQS message retention in DLQ also incurs charges

**References**:
- [EventBridge Scheduler - RetryPolicy API Reference](https://docs.aws.amazon.com/scheduler/latest/APIReference/API_RetryPolicy.html)
- [Amazon EventBridge Scheduler User Guide](https://docs.aws.amazon.com/eventbridge/latest/userguide/using-eventbridge-scheduler.html)
- [Configure EventBridge retries and DLQ](https://repost.aws/knowledge-center/eventbridge-resolve-failedinvocation-errors)

---

## 3. Apache Airflow

### Approach: Catchup (Automatic) + Backfill (Manual)

Apache Airflow is a workflow engine for data pipelines that has **mechanisms for detecting and executing past unexecuted tasks**.

#### Catchup (Automatic Recovery)

**Configuration example**:

```python
from datetime import datetime, timedelta
from airflow import DAG
from airflow.operators.bash import BashOperator

default_args = {
    'owner': 'data-team',
    'depends_on_past': False,
    'email_on_failure': True,
    'email_on_retry': False,
    'retries': 1,
    'retry_delay': timedelta(minutes=5),
}

dag = DAG(
    'hourly_report',
    default_args=default_args,
    description='Generate hourly reports',
    schedule_interval='0 * * * *',  # Every hour at minute 0
    start_date=datetime(2024, 1, 1, 0, 0),
    catchup=True,  # <- Important: default is False
    max_active_runs=1,
)

task = BashOperator(
    task_id='generate_report',
    bash_command='php /app/artisan report:hourly',
    dag=dag,
)
```

**Behavior example**:

```
2024-01-01 00:00 - DAG definition deployed
                 - start_date = 2024-01-01 00:00
                 - Current time = 2024-01-10 15:00

-> Airflow Scheduler detects:
  - 2024-01-01 00:00 to 2024-01-10 14:00 are unexecuted
  - Creates 226 DAG Runs (10 days x 24 hours - 10 hours)

-> Sequential execution (with max_active_runs=1):
  ✅ 2024-01-01 00:00
  ✅ 2024-01-01 01:00
  ✅ 2024-01-01 02:00
  ...
  ✅ 2024-01-10 14:00
  ✅ 2024-01-10 15:00 (current execution)
```

**Post-maintenance scenario**:

```
2024-01-05 03:00 - Airflow cluster stopped for maintenance
2024-01-05 05:00 - Cluster recovered

-> After Scheduler starts:
  - Last execution: 2024-01-05 02:00
  - Current time: 2024-01-05 05:00
  - Unexecuted period: 03:00, 04:00

-> With catchup=True:
  ✅ 2024-01-05 03:00 executed
  ✅ 2024-01-05 04:00 executed
  ✅ 2024-01-05 05:00 executed (current)

-> With catchup=False:
  ❌ 03:00, 04:00 are skipped
  ✅ 2024-01-05 05:00 only executed
```

#### Backfill (Manual Recovery)

**CLI execution**:

```bash
# Re-execute a specific period
airflow dags backfill \
  --start-date 2024-01-01 \
  --end-date 2024-01-10 \
  hourly_report

# Re-execute only failed tasks
airflow dags backfill \
  --start-date 2024-01-05 \
  --end-date 2024-01-05 \
  --rerun-failed-tasks \
  hourly_report

# Dry run (check without executing)
airflow dags backfill \
  --start-date 2024-01-01 \
  --end-date 2024-01-10 \
  --dry-run \
  hourly_report
```

**UI execution (Airflow 3.x)**:

1. Open the DAG page
2. Click "Trigger" button -> Select "Backfill"
3. Specify start and end dates
4. Select tasks to re-execute
5. Execute

#### Execution History Management

Airflow records the following in PostgreSQL or MySQL:

- **DagRun**: Each execution instance
- **TaskInstance**: Execution status of each task
- **execution_date**: The data period covered by the task

```sql
-- Detect unexecuted DAG Runs
SELECT execution_date, state
FROM dag_run
WHERE dag_id = 'hourly_report'
  AND execution_date >= '2024-01-01'
  AND state = 'scheduled';

-- Detect failed TaskInstances
SELECT task_id, execution_date, state
FROM task_instance
WHERE dag_id = 'hourly_report'
  AND state = 'failed';
```

#### Constraints and Notes

- **When there are many unexecuted runs**:
  - Without setting `max_active_runs`, concurrent execution can overwhelm resources
  - Sequentially executing hundreds to thousands of DAG Runs takes time

- **Database dependency**:
  - PostgreSQL or MySQL is required
  - SQLite is not recommended for production

- **Data integrity**:
  - Setting `depends_on_past=True` prevents the next run unless the past run succeeded
  - Useful for data pipelines but unnecessary for independent jobs

- **Cost**:
  - Operational cost of Airflow cluster (Scheduler, Webserver, Worker)
  - Managed services (AWS MWAA, Google Cloud Composer) are expensive

**References**:
- [Airflow DAG Runs Documentation](https://airflow.apache.org/docs/apache-airflow/stable/core-concepts/dag-run.html)
- [Airflow Catchup & Backfill - Demystified](https://medium.com/nerd-for-tech/airflow-catchup-backfill-demystified-355def1b6f92)
- [Understanding the Difference Between Backfill and Catchup](https://medium.com/@seilylook95/understanding-the-difference-between-airflows-backfill-and-catchup-cf6e830588b8)

---

## 4. Celery Beat

### Approach: No High Availability Mechanism

Celery Beat is the scheduler component of Celery (Python's distributed task queue), but it is **designed as a single-instance architecture**.

#### Basic Configuration

```python
# celery.py
from celery import Celery
from celery.schedules import crontab

app = Celery('tasks', broker='redis://localhost:6379/0')

app.conf.beat_schedule = {
    'hourly-report': {
        'task': 'tasks.generate_report',
        'schedule': crontab(minute=0),  # Every hour at minute 0
    },
}

@app.task
def generate_report():
    # Report generation logic
    pass
```

```bash
# Start Celery Worker
celery -A tasks worker --loglevel=info

# Start Celery Beat (separate process)
celery -A tasks beat --loglevel=info
```

#### Problem 1: Single Point of Failure

**Architecture**:
```
+-------------+
| Celery Beat |  <- Only one can be running
+-------------+
      |
      v
+-------------+
|   Redis     |
+-------------+
      |
      v
+-------------+  +-------------+  +-------------+
|  Worker 1   |  |  Worker 2   |  |  Worker 3   |
+-------------+  +-------------+  +-------------+
```

**Problem**:
- If Celery Beat stops, all scheduled tasks stop executing
- Workers remain running but no tasks are added to the queue

#### Problem 2: Duplicate Execution with Multiple Instances

```bash
# If multiple Beat instances are accidentally started
celery -A tasks beat --loglevel=info  # Beat 1
celery -A tasks beat --loglevel=info  # Beat 2
```

**Result**:
```
01:00:00 - Beat 1 adds hourly-report task to queue
01:00:00 - Beat 2 also adds hourly-report task to queue
         - Worker 1 executes the 1st instance
         - Worker 2 executes the 2nd instance
         -> Duplicate execution ❌
```

#### Problem 3: No Missed Execution Detection

```
01:00:00 - Beat goes down
01:30:00 - Beat recovered
         - 01:00 task is not executed ❌
         - Normal execution resumes from 02:00
         - No mechanism to detect past unexecuted tasks
```

#### Community Workarounds

##### 1. Leader Election

Use Redis or ZooKeeper to elect one leader from multiple Beat instances:

```python
# Example using redbeat
from redbeat.schedulers import RedBeatScheduler

app.conf.beat_scheduler = 'redbeat.schedulers:RedBeatScheduler'
app.conf.redbeat_redis_url = 'redis://localhost:6379/1'
app.conf.redbeat_lock_timeout = 30
```

**Behavior**:
- Multiple Beat instances start
- The instance that acquires the Redis lock becomes the leader
- If the leader goes down, another instance takes over

**Constraints**:
- Failover takes time (waits for lock timeout)
- The new leader cannot detect past unexecuted tasks

##### 2. Redlock (Distributed Lock)

All Beat instances operate, but acquire a distributed lock when adding tasks to the queue:

```python
from redis import Redis
from redlock import Redlock

redis_client = Redis(host='localhost', port=6379)
dlm = Redlock([{"host": "localhost", "port": 6379, "db": 0}])

@app.on_after_configure.connect
def setup_periodic_tasks(sender, **kwargs):
    sender.add_periodic_task(
        crontab(minute=0),
        hourly_report_with_lock.s(),
    )

@app.task
def hourly_report_with_lock():
    lock_key = 'celery:beat:hourly-report'
    lock = dlm.lock(lock_key, 60000)  # 60-second lock

    if lock:
        try:
            generate_report()
        finally:
            dlm.unlock(lock)
    else:
        # Another instance is executing
        pass
```

**Constraints**:
- Complex implementation
- Lock management overhead

##### 3. External Scheduler (Recommended)

Instead of using Celery Beat, call Celery tasks directly from Kubernetes CronJob or EventBridge:

```yaml
# Kubernetes CronJob to trigger Celery task
apiVersion: batch/v1
kind: CronJob
metadata:
  name: hourly-report
spec:
  schedule: "0 * * * *"
  jobTemplate:
    spec:
      template:
        spec:
          containers:
          - name: trigger
            image: my-app:latest
            command:
            - python
            - -c
            - |
              from tasks import generate_report
              generate_report.apply_async()
          restartPolicy: OnFailure
```

**Benefits**:
- Leverages Kubernetes high availability
- Avoids Celery Beat single point of failure

#### Constraints and Notes

- **No official high availability support**: Celery Beat is inherently single-instance
- **No missed execution detection**: No mechanism to detect past unexecuted tasks after restart
- **Community solution complexity**: redbeat and Redlock require additional learning
- **Production recommendation**: Avoid Celery Beat, use an external scheduler

**References**:
- [Celery Beat High Availability Issue #1495](https://github.com/celery/celery/issues/1495)
- [Distributed Scheduling Gone Wrong: The Celery Beat Trap](https://medium.com/@sudarshaana/distributed-scheduling-gone-wrong-the-celery-beat-trap-and-how-we-escaped-85c7e53828f6)
- [Question: missed schedules in celery beat](https://github.com/celery/celery/issues/6124)

---

## Comparison Table

| System | Missed Execution Detection | Automatic Recovery | High Availability | Implementation Difficulty | Use Case |
|--------|---------------------------|-------------------|-------------------|--------------------------|----------|
| **Kubernetes CronJob** | Partial<br>(grace period only) | None | Yes<br>(k8s redundancy) | Medium | Infrastructure tasks |
| **EventBridge Scheduler** | None<br>(retries execution failures only) | Partial<br>(execution failures only) | Yes<br>(AWS managed) | Low | Event-driven |
| **Apache Airflow** | Yes<br>(catchup) | Yes<br>(backfill) | Yes<br>(cluster configuration) | High | Data pipelines |
| **Celery Beat** | None | None | None<br>(single instance) | Low (basic)<br>High (HA) | Lightweight task queue |

---

## Conclusion

1. **Complete guarantees are difficult**: No system guarantees "exactly once" execution
2. **Tradeoffs**: Balancing implementation complexity and recovery reliability is important
3. **Choose based on use case**: Data pipelines and simple task execution have different requirements
