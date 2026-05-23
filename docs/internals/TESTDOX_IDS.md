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
| Scheduling/ClockAwareScheduleTest | `CS` | 27 |
| Scheduling/ClockAwareEventTest | `CE` | 36 |
| Scheduling/ProcessCommandBuilderTest | `PCB` | 7 |
| Scheduling/ClockAwareTimeFilterTest | `TF` | 13 |
| Scheduling/TimezoneResolverTest | `TR` | 6 |
| Scheduling/ClockAwareEventCompatibilityTest | `CEC` | 29 |
| Scheduling/ClockAwareScheduleCompatibilityTest | `CSC` | 10 |
| Console/ExceptionReporterTest | `ER` | 4 |
| Console/LegacyExceptionReporterTest | `LER` | 4 |
| Console/GracefulScheduleWorkCommandTest | `GC` | 5 |
| Console/UsesClockAwareScheduleTest | `UCS` | 11 |
| Dispatcher/LocalDispatcherTest | `LD` | 41 |
| Dispatcher/CompositeDispatcherTest | `CD` | 14 |
| Dispatcher/TrackingDispatcherTest | `TD` | 20 |
| Dispatcher/RunningProcessManagerTest | `RPM` | 14 |
| Dispatcher/StepFunctionsDispatcherTest | `SFD` | 23 |
| Dispatcher/Result/AbstractDispatchResultTest | `ADR` | 6 |
| Dispatcher/Result/StartedLocalDispatchResultTest | `SLR` | 17 |
| Dispatcher/Result/FailedLocalDispatchResultTest | `FLR` | 8 |
| Dispatcher/Result/SkippedDispatchResultTest | `SD` | 9 |
| Dispatcher/Result/StartedStepFunctionsDispatchResultTest | `SSR` | 9 |
| Dispatcher/Result/FailedStepFunctionsDispatchResultTest | `FSR` | 9 |
| Dispatcher/Result/AlreadyRunningStepFunctionsDispatchResultTest | `ARR` | 8 |
| Dispatcher/Result/LocalDispatchResultFactoryTest | `LRF` | 7 |
| Dispatcher/Result/SkippedDispatchResultFactoryTest | `SDF` | 6 |
| Dispatcher/Result/StepFunctionsDispatchResultFactoryTest | `SRF` | 7 |
| Dispatcher/StepFunctions/AwsSfnClientAdapterTest | `SCA` | 3 |
| Dispatcher/StepFunctions/ExecutionNameGeneratorTest | `ENG` | 9 |
| Dispatcher/StepFunctions/MutexNameSanitizerTest | `MNS` | 19 |
| Dispatcher/StepFunctions/LockKeyGeneratorTest | `LKG` | 9 |
| Dispatcher/StepFunctions/PayloadTest | `PY` | 4 |
| Dispatcher/StepFunctions/PayloadBuilderTest | `PB` | 13 |
| Dispatcher/StepFunctions/StartExecutionInputFactoryTest | `SEIF` | 3 |
| Dispatcher/StepFunctions/StartExecutionInputTest | `SEI` | 4 |
| Dispatcher/StepFunctions/StartExecutionResultTest | `SER` | 3 |
| Logging/PrefixedLoggerTest | `PL` | 4 |
| Providers/DispatcherServiceRegistrarTest | `DSR` | 5 |
| Providers/OrchestratorServiceRegistrarTest | `OSR` | 5 |
| Providers/TrackerServiceRegistrarTest | `TSR` | 5 |
| Providers/StepFunctionsServiceProviderTest | `SFP` | 12 |
| Providers/GracefulScheduleWorkerProviderTest | `GP` | 20 |
| Orchestrator/DefaultScheduleOrchestratorTest | `DO` | 28 |
| Tracker/CacheExecutionTrackerTest | `CT` | 22 |
| Tracker/NullExecutionTrackerTest | `NT` | 4 |

### Integration Tests

| Test File | Prefix | Test Count |
|---|---|---|
| Orchestrator/OrchestratorFlowIntegrationTest | `TI` | 5 |
| Orchestrator/OrchestratorFiltersPassIntegrationTest | `TI` | 6 |
| Scheduling/ScheduleRunCompatibilityIntegrationTest | `TI` | 7 |
| Dispatcher/StepFunctionsDispatcherIntegrationTest | `SFI` | 5 |
| Dispatcher/TrackingDispatcherRedisIntegrationTest | `TDI` | 6 |
| Tracker/CacheExecutionTrackerRedisTest | `CTI` | 7 |
| Providers/ProviderBootIntegrationTest | `GPI` | 7 |
| Providers/ProviderWiringIntegrationTest | `PWI` | 4 |

### E2E Tests

| Test File | Prefix | Test Count |
|---|---|---|
| GracefulScheduleWorkerCommandTest | `E2E` | 1 |
| BackgroundCommandOutputTest | `E2E` | 2 |
| StepFunctionsExecutionE2ETest | `E2E` | 4 |
| StepFunctionsLambdaTaskE2ETest | `E2E` | 1 |

## TI Prefix Number Ranges

The `TI` prefix is shared across Orchestrator Integration files, with number ranges for separation:

| Test File | Number Range |
|---|---|
| OrchestratorFlowIntegrationTest | TI.1 - TI.4, TI.20 |
| OrchestratorFiltersPassIntegrationTest | TI.5 - TI.10 |
| ScheduleRunCompatibilityIntegrationTest | TI.11 - TI.15, TI.21 - TI.22 |

## E2E Prefix Number Ranges

The `E2E` prefix is shared across all end-to-end test files. Number ranges keep IDs unique per file:

| Test File | Number Range |
|---|---|
| GracefulScheduleWorkerCommandTest | E2E.1 |
| BackgroundCommandOutputTest | E2E.2 - E2E.3 |
| StepFunctionsExecutionE2ETest | E2E.10 - E2E.12, E2E.14 |
| StepFunctionsLambdaTaskE2ETest | E2E.13 |
