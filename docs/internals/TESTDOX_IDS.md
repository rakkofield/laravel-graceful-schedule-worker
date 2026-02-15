# Testdox ID Inventory

## ID Format

```
@testdox {Prefix}.{seq} Description text
```

- **Prefix**: A unique 2-4 character uppercase English abbreviation per test file
- **seq**: Sequential number starting from 1 (sub-numbers `{seq}.{sub}` are also allowed)
- Description text: Japanese or English

## Design Principles

1. Use an abbreviation of the target class name as the prefix
2. Unit tests: Class name abbreviation only (e.g., `LD`)
3. Integration tests: Class name abbreviation + `I` (e.g., `CTI`)
4. E2E tests: Fixed as `E2E`
5. When adding a new prefix, verify there is no overlap with existing prefixes

## Full Prefix List

### Unit Tests

| Test File | Prefix | Test Count |
|---|---|---|
| Clock/SystemClockTest | `SC` | 2 |
| Clock/SleeperTest | `SL` | 4 |
| Clock/FreezableClockTest | `FC` | 7 |
| Scheduling/ClockAwareScheduleTest | `CS` | 10 |
| Scheduling/ClockAwareEventTest | `CE` | 21 |
| Scheduling/ProcessCommandBuilderTest | `PCB` | 7 |
| Scheduling/ClockAwareEventCompatibilityTest | `CEC` | 29 |
| Scheduling/ClockAwareScheduleCompatibilityTest | `CSC` | 10 |
| Console/ExceptionReporterTest | `ER` | 4 |
| Console/LegacyExceptionReporterTest | `LER` | 4 |
| Console/GracefulScheduleWorkCommandTest | `GC` | 5 |
| Console/UsesClockAwareScheduleTest | `UCS` | 11 |
| Dispatcher/LocalDispatcherTest | `LD` | 37 |
| Dispatcher/CompositeDispatcherTest | `CD` | 13 |
| Dispatcher/TrackingDispatcherTest | `TD` | 20 |
| Dispatcher/StepFunctionsDispatcherTest | `SFD` | 17 |
| Dispatcher/Result/StartedLocalDispatchResultTest | `SLR` | 16 |
| Dispatcher/Result/FailedLocalDispatchResultTest | `FLR` | 8 |
| Dispatcher/Result/SkippedDispatchResultTest | `SD` | 9 |
| Dispatcher/Result/StartedStepFunctionsDispatchResultTest | `SSR` | 8 |
| Dispatcher/Result/FailedStepFunctionsDispatchResultTest | `FSR` | 8 |
| Dispatcher/Result/AlreadyRunningStepFunctionsDispatchResultTest | `ARR` | 7 |
| Dispatcher/StepFunctions/AwsSfnClientAdapterTest | `SCA` | 3 |
| Dispatcher/StepFunctions/ExecutionNameGeneratorTest | `ENG` | 9 |
| Dispatcher/StepFunctions/PayloadTest | `PY` | 3 |
| Dispatcher/StepFunctions/StartExecutionResultTest | `SER` | 3 |
| ExceptionFormatterTest | `EF` | 3 |
| Logging/PrefixedLoggerTest | `PL` | 4 |
| Providers/GracefulScheduleWorkerProviderTest | `GP` | 18 |
| Orchestrator/DefaultScheduleOrchestratorTest | `DO` | 28 |
| Tracker/CacheExecutionTrackerTest | `CT` | 18 |
| Tracker/NullExecutionTrackerTest | `NT` | 4 |

### Integration Tests

| Test File | Prefix | Test Count |
|---|---|---|
| Orchestrator/OrchestratorFlowIntegrationTest | `TI` | 4 |
| Orchestrator/OrchestratorFiltersPassIntegrationTest | `TI` | 6 |
| Scheduling/ScheduleRunCompatibilityIntegrationTest | `TI` | 7 |
| Dispatcher/StepFunctionsDispatcherIntegrationTest | `SFI` | 3 |
| Dispatcher/TrackingDispatcherRedisIntegrationTest | `TDI` | 6 |
| Tracker/CacheExecutionTrackerRedisTest | `CTI` | 7 |
| Providers/ProviderBootIntegrationTest | `GPI` | 7 |
| Providers/ProviderWiringIntegrationTest | `PWI` | 4 |

### E2E Tests

| Test File | Prefix | Test Count |
|---|---|---|
| GracefulScheduleWorkerCommandTest | `E2E` | 1 |
| BackgroundCommandOutputTest | `E2E` | 2 |

## TI Prefix Number Ranges

The `TI` prefix is shared across Orchestrator Integration files, with number ranges for separation:

| Test File | Number Range |
|---|---|
| OrchestratorFlowIntegrationTest | TI.1 - TI.4, TI.20 |
| OrchestratorFiltersPassIntegrationTest | TI.5 - TI.10 |
| ScheduleRunCompatibilityIntegrationTest | TI.11 - TI.15, TI.21 - TI.22 |
