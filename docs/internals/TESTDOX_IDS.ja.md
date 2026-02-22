# Testdox ID インベントリ

## ID フォーマット

```
@testdox {Prefix}.{seq} 説明テキスト
```

- **Prefix**: テストファイルごとに一意の 2-4 文字英大文字
- **seq**: 1 始まりの連番（サブ番号 `{seq}.{sub}` も許可）
- 説明テキスト: 日本語 or 英語

## 設計原則

1. テスト対象クラス名の略語をプレフィックスとする
2. Unit テスト: クラス名略語のみ（例: `LD`）
3. Integration テスト: クラス名略語 + `I`（例: `CTI`）
4. E2E テスト: `E2E` 固定
5. 新規プレフィックス追加時は既存プレフィックスとの重複がないことを確認する

## 全プレフィックス一覧

### Unit Tests

| テストファイル | Prefix | テスト数 |
|---|---|---|
| Clock/SystemClockTest | `SC` | 2 |
| Clock/SleeperTest | `SL` | 4 |
| Clock/FreezableClockTest | `FC` | 7 |
| Scheduling/ClockAwareScheduleTest | `CS` | 10 |
| Scheduling/ClockAwareEventTest | `CE` | 21 |
| Scheduling/ProcessCommandBuilderTest | `PCB` | 5 |
| Scheduling/ClockAwareEventCompatibilityTest | `CEC` | 29 |
| Scheduling/ClockAwareScheduleCompatibilityTest | `CSC` | 10 |
| Console/ExceptionReporterTest | `ER` | 3 |
| Console/LegacyExceptionReporterTest | `LER` | 3 |
| Console/GracefulScheduleWorkCommandTest | `GC` | 5 |
| Console/UsesClockAwareScheduleTest | `UCS` | 11 |
| Dispatcher/LocalDispatcherTest | `LD` | 32 |
| Dispatcher/CompositeDispatcherTest | `CD` | 13 |
| Dispatcher/TrackingDispatcherTest | `TD` | 17 |
| Dispatcher/StepFunctionsDispatcherTest | `SFD` | 20 |
| Dispatcher/Result/StartedLocalDispatchResultTest | `SLR` | 11 |
| Dispatcher/Result/FailedLocalDispatchResultTest | `FLR` | 8 |
| Dispatcher/Result/SkippedDispatchResultTest | `SD` | 9 |
| Dispatcher/Result/StartedStepFunctionsDispatchResultTest | `SSR` | 8 |
| Dispatcher/Result/FailedStepFunctionsDispatchResultTest | `FSR` | 8 |
| Dispatcher/Result/AlreadyRunningStepFunctionsDispatchResultTest | `ARR` | 7 |
| Dispatcher/StepFunctions/AwsSfnClientAdapterTest | `SCA` | 3 |
| Dispatcher/StepFunctions/ExecutionNameGeneratorTest | `ENG` | 9 |
| Dispatcher/StepFunctions/MutexNameSanitizerTest | `MNS` | 7 |
| Dispatcher/StepFunctions/LockKeyGeneratorTest | `LKG` | 9 |
| Dispatcher/StepFunctions/PayloadTest | `PY` | 4 |
| Dispatcher/StepFunctions/StartExecutionResultTest | `SER` | 3 |
| Providers/GracefulScheduleWorkerProviderTest | `GP` | 16 |
| Orchestrator/DefaultScheduleOrchestratorTest | `DO` | 23 |
| Tracker/CacheExecutionTrackerTest | `CT` | 16 |
| Tracker/NullExecutionTrackerTest | `NT` | 4 |

### Integration Tests

| テストファイル | Prefix | テスト数 |
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

| テストファイル | Prefix | テスト数 |
|---|---|---|
| GracefulScheduleWorkerCommandTest | `E2E` | 1 |
| BackgroundCommandOutputTest | `E2E` | 1 |

## TI プレフィックスの番号範囲

`TI` は Orchestrator Integration ファイルで共有し、番号範囲で分離する：

| テストファイル | 番号範囲 |
|---|---|
| OrchestratorFlowIntegrationTest | TI.1〜TI.4, TI.20 |
| OrchestratorFiltersPassIntegrationTest | TI.5〜TI.10 |
| ScheduleRunCompatibilityIntegrationTest | TI.11〜TI.15, TI.21〜TI.22 |
