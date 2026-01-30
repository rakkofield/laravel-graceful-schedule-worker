# Laravel Graceful Schedule Worker 実装計画

## 概要

DESIGN.md に基づき、6つのフェーズに分けて段階的に実装を進める。
TDD アプローチを採用し、各実装の前にテストを作成する。

---

## Phase 1: 基盤リファクタリング（ClockAware 導入）

**目的**: Clock パターンを導入し、時刻依存を外部化する

### タスク一覧

| # | タスク | 成果物 | TDD対応テスト |
|---|--------|--------|---------------|
| 1.1 | ClockInterface を作成 | `src/Clock/ClockInterface.php` | - |
| 1.2 | Clock のユニットテスト作成 | `tests/Clock/SystemClockTest.php`, `tests/Clock/FixedClockTest.php` | T1.1〜T1.3 |
| 1.3 | SystemClock を実装 | `src/Clock/SystemClock.php` | - |
| 1.4 | FixedClock を実装 | `src/Clock/FixedClock.php` | - |
| 1.5 | ClockAwareEvent のユニットテスト作成 | `tests/Scheduling/ClockAwareEventTest.php` | T1.4〜T1.7 |
| 1.6 | ClockAwareEvent を実装 | `src/Scheduling/ClockAwareEvent.php` | - |
| 1.7 | ClockAwareSchedule のユニットテスト作成 | `tests/Scheduling/ClockAwareScheduleTest.php` | T1.8〜T1.10 |
| 1.8 | ClockAwareSchedule を実装 | `src/Scheduling/ClockAwareSchedule.php` | - |
| 1.9 | ServiceProvider で Clock をバインド | `src/Providers/GracefulScheduleWorkerProvider.php` (更新) | - |
| 1.10 | 既存テストの確認・修正 | 全テスト通過確認 | - |

### 検証

- [ ] Phase 1 の全ユニットテスト（T1.1〜T1.10）が通過
- [ ] `FixedClock` を使用したテストが動作すること
- [ ] 既存の動作が維持されること

---

## Phase 2: Dispatcher 分離（後方互換維持）

**目的**: 既存のロジックを Dispatcher パターンに移行し、後方互換性を維持する

### タスク一覧

| # | タスク | 成果物 | TDD対応テスト |
|---|--------|--------|---------------|
| 2.1 | ScheduleDispatcherInterface を作成 | `src/Dispatcher/ScheduleDispatcherInterface.php` | - |
| 2.2 | LocalDispatcher のユニットテスト作成 | `tests/Unit/Dispatcher/LocalDispatcherTest.php` | T2.1〜T2.2 |
| 2.3 | LocalDispatcher を実装（既存ロジック抽出） | `src/Dispatcher/LocalDispatcher.php` | - |
| 2.4 | CompositeDispatcher のユニットテスト作成 | `tests/Unit/Dispatcher/CompositeDispatcherTest.php` | T2.5〜T2.8 |
| 2.5 | CompositeDispatcher を実装 | `src/Dispatcher/CompositeDispatcher.php` | - |
| 2.6 | 設定ファイルを追加 | `config/graceful-scheduler.php` | - |
| 2.7 | GracefulScheduleWorkCommand をリファクタリング | `src/Console/GracefulScheduleWorkCommand.php` (更新) | - |
| 2.8 | ServiceProvider で Dispatcher をバインド | `src/Providers/GracefulScheduleWorkerProvider.php` (更新) | - |
| 2.9 | ServiceProvider テスト更新 | `tests/Unit/Providers/GracefulScheduleWorkerProviderTest.php` (更新) | - |
| 2.10 | 既存テストの確認・修正 | 全テスト通過確認 | - |
| 2.11 | カバレッジ確認（100%目標） | `composer test:coverage` | - |
| 2.12 | `/pr-review-toolkit:review-pr` でレビュー実施 | レビュー結果 | - |
| 2.13 | レビューフィードバック対応 | 修正・テスト追加 | - |

### 検証

- [ ] Phase 2 の全ユニットテスト（T2.1〜T2.2, T2.5〜T2.8）が通過
- [ ] ServiceProvider テストが更新され通過すること
- [ ] 既存のテストが全て通過すること
- [ ] デモアプリケーションで動作確認
- [ ] config による切り替えが動作すること
- [ ] 新規実装コードのカバレッジ 100%
- [ ] `/pr-review-toolkit:review-pr` レビュー完了
- [ ] フィードバック対応完了・再テスト通過

---

## Phase 3: Orchestrator

**目的**: スケジュール実行の調整層を導入し、責務を分離する

### タスク一覧

| # | タスク | 成果物 | TDD対応テスト | 状態 |
|---|--------|--------|---------------|------|
| 3.1 | ScheduleOrchestratorInterface を作成 | `src/Orchestrator/ScheduleOrchestratorInterface.php` | - | - |
| 3.2 | ScheduleOrchestrator のユニットテスト作成 | `tests/Unit/Orchestrator/ScheduleOrchestratorTest.php` | T3.1〜T3.4 | - |
| 3.3 | DefaultScheduleOrchestrator を実装 | `src/Orchestrator/DefaultScheduleOrchestrator.php` | - | - |
| ~~3.4~~ | ~~ClockAwareEvent に dispatchVia() 追加~~ | - | - | ✅ Phase 1 で完了 |
| 3.5 | GracefulScheduleWorkCommand を更新 | `src/Console/GracefulScheduleWorkCommand.php` (更新) | - | - |
| 3.6 | ServiceProvider を更新 | `src/Providers/GracefulScheduleWorkerProvider.php` (更新) | - | - |
| 3.7 | ServiceProvider テスト更新 | `tests/Unit/Providers/GracefulScheduleWorkerProviderTest.php` (更新) | - | - |
| 3.8 | 既存テストの確認・修正 | 全テスト通過確認 | - | - |
| 3.9 | カバレッジ確認（100%目標） | `composer test:coverage` | - | - |
| 3.10 | `/pr-review-toolkit:review-pr` でレビュー実施 | レビュー結果 | - | - |
| 3.11 | レビューフィードバック対応 | 修正・テスト追加 | - | - |

### 検証

- [ ] Phase 3 の全ユニットテスト（T3.1〜T3.4）が通過
- [ ] ServiceProvider テストが更新され通過すること
- [ ] イベント単位でDispatcher指定が動作すること
- [ ] デフォルト設定が正しく適用されること
- [ ] 新規実装コードのカバレッジ 100%
- [ ] `/pr-review-toolkit:review-pr` レビュー完了
- [ ] フィードバック対応完了・再テスト通過

---

## Phase 4: Step Functions 対応

**目的**: AWS Step Functions を使用した外部実行機能を追加

### タスク一覧

| # | タスク | 成果物 | TDD対応テスト |
|---|--------|--------|---------------|
| 4.1 | StepFunctionsDispatcher のユニットテスト作成 | `tests/Unit/Dispatcher/StepFunctionsDispatcherTest.php` | T2.3〜T2.4 |
| 4.2 | StepFunctionsDispatcher を実装 | `src/Dispatcher/StepFunctionsDispatcher.php` | - |
| 4.3 | Execution Name による重複防止実装 | StepFunctionsDispatcher 内 | - |
| 4.4 | ServiceProvider で DI 設定追加 | `src/Providers/GracefulScheduleWorkerProvider.php` (更新) | - |
| 4.5 | ServiceProvider テスト更新 | `tests/Unit/Providers/GracefulScheduleWorkerProviderTest.php` (更新) | - |
| 4.6 | composer.json に aws-sdk-php を suggest 追加 | `composer.json` (更新) | - |
| 4.7 | カバレッジ確認（100%目標） | `composer test:coverage` | - |
| 4.8 | `/pr-review-toolkit:review-pr` でレビュー実施 | レビュー結果 | - |
| 4.9 | レビューフィードバック対応 | 修正・テスト追加 | - |

### 検証

- [ ] Phase 4 の全ユニットテスト（T2.3〜T2.4）が通過
- [ ] ServiceProvider テストが更新され通過すること
- [ ] モックテストでエラーハンドリングを確認
- [ ] 同じ ExecutionName での重複起動が拒否されること
- [ ] 新規実装コードのカバレッジ 100%
- [ ] `/pr-review-toolkit:review-pr` レビュー完了
- [ ] フィードバック対応完了・再テスト通過

---

## Phase 5: At-least-once 対応（ExecutionTracker）

**目的**: 取りこぼしタスクの検出とリカバリ機能を追加

### タスク一覧

| # | タスク | 成果物 | TDD対応テスト |
|---|--------|--------|---------------|
| 5.1 | ExecutionTrackerInterface を作成 | `src/Tracker/ExecutionTrackerInterface.php` | - |
| 5.2 | CacheExecutionTracker のユニットテスト作成 | `tests/Unit/Tracker/CacheExecutionTrackerTest.php` | T4.1〜T4.7 |
| 5.3 | CacheExecutionTracker を実装 | `src/Tracker/CacheExecutionTracker.php` | - |
| 5.4 | cron-expression ライブラリ統合 | `composer.json` (更新) | - |
| 5.5 | DefaultScheduleOrchestrator に Tracker 統合 | `src/Orchestrator/DefaultScheduleOrchestrator.php` (更新) | - |
| 5.6 | StepFunctionsDispatcher に Tracker 統合 | `src/Dispatcher/StepFunctionsDispatcher.php` (更新) | - |
| 5.7 | 起動時リカバリロジック実装 | `src/Orchestrator/DefaultScheduleOrchestrator.php` (更新) | - |
| 5.8 | ServiceProvider で Tracker をバインド | `src/Providers/GracefulScheduleWorkerProvider.php` (更新) | - |
| 5.9 | ServiceProvider テスト更新 | `tests/Unit/Providers/GracefulScheduleWorkerProviderTest.php` (更新) | - |
| 5.10 | 統合テスト作成 | `tests/Integration/` 配下 | T5.1〜T5.7 |
| 5.11 | カバレッジ確認（100%目標） | `composer test:coverage` | - |
| 5.12 | `/pr-review-toolkit:review-pr` でレビュー実施 | レビュー結果 | - |
| 5.13 | レビューフィードバック対応 | 修正・テスト追加 | - |

### 検証

- [ ] Phase 5 の全ユニットテスト（T4.1〜T4.7）が通過
- [ ] ServiceProvider テストが更新され通過すること
- [ ] 統合テスト（T5.1〜T5.7）が通過
- [ ] Redis を使用した統合テスト
- [ ] 取りこぼしシナリオのテスト
- [ ] 起動時リカバリが動作すること
- [ ] ロック機構による重複実行防止が動作すること
- [ ] 新規実装コードのカバレッジ 100%
- [ ] `/pr-review-toolkit:review-pr` レビュー完了
- [ ] フィードバック対応完了・再テスト通過

---

## Phase 6: 拡張機能（Grace Period）

**目的**: リカバリ制御の拡張メソッドと ExecutionTracker との連携を完成

### タスク一覧

| # | タスク | 成果物 | TDD対応テスト |
|---|--------|--------|---------------|
| 6.1 | Grace Period 考慮ロジックのテスト追加 | `tests/Unit/Tracker/CacheExecutionTrackerTest.php` (更新) | - |
| 6.2 | ExecutionTracker で Grace Period 考慮 | `src/Tracker/CacheExecutionTracker.php` (更新) | - |
| 6.3 | ClockAwareEvent の Grace Period テスト強化 | `tests/Unit/Scheduling/ClockAwareEventTest.php` (更新) | - |
| 6.4 | 統合テスト追加 | `tests/Integration/GracePeriodIntegrationTest.php` | - |
| 6.5 | カバレッジ確認（100%目標） | `composer test:coverage` | - |
| 6.6 | `/pr-review-toolkit:review-pr` でレビュー実施 | レビュー結果 | - |
| 6.7 | レビューフィードバック対応 | 修正・テスト追加 | - |

### 検証

- [ ] デフォルトでリカバリが無効であること
- [ ] withGracePeriod(30) で30分の猶予期間が設定されること
- [ ] withGracePeriod(null) と enableRecovery() が同じ動作をすること
- [ ] リカバリはタイムスタンプ順（古い順）に実行されること
- [ ] 新規実装コードのカバレッジ 100%
- [ ] `/pr-review-toolkit:review-pr` レビュー完了
- [ ] フィードバック対応完了・再テスト通過

---

## 進捗管理

| Phase | 状態 | 開始条件 |
|-------|------|----------|
| Phase 1 | ✅ 完了 | - |
| Phase 1.5 | ✅ 完了 | レビューフィードバック対応 |
| Phase 1.6 | ✅ 完了 | カバレッジ改善（100%達成） |
| Phase 2 | 🔜 次 | Phase 1.6 完了 |
| Phase 3 | 未着手 | Phase 2 の全テストが通過、デモアプリで動作確認 |
| Phase 4 | 未着手 | Phase 3 の全テストが通過 |
| Phase 5 | 未着手 | Phase 4 の全テストが通過 |
| Phase 6 | 未着手 | Phase 5 の全テストが通過 |

---

## Phase 1.6 完了サマリー

**達成したカバレッジ**: 100%（Phase 1 実装コード）

| クラス | Methods | Lines |
|--------|---------|-------|
| SystemClock | 100% | 100% |
| ClockAwareEvent | 100% | 100% |
| ClockAwareSchedule | 100% | 100% |
| GracefulScheduleWorkerProvider (register) | 100% | 100% |

**テスト結果**: 28 tests, 56 assertions

**追加されたテスト**:
- `tests/Unit/Providers/GracefulScheduleWorkerProviderTest.php` (6 tests)
- dispatchVia/getDispatcherType テスト (3 tests)
- パラメータ付き exec/command テスト (2 tests)

**追加された composer scripts**:
- `composer test` - テスト実行
- `composer test:coverage` - カバレッジ（テキスト）
- `composer test:coverage-html` - カバレッジ（HTML）

---

## Phase 1〜1.6 振り返り（Lessons Learned）

### 確立されたパターン

1. **テスト構造**
   ```
   tests/
   ├── Unit/           # ユニットテスト
   ├── Integration/    # 統合テスト
   └── Helper/         # テスト専用コード（FixedClock等）
   ```

2. **モック設定パターン**
   ```php
   $this->app->bind(EventMutex::class, function () {
       return $this->createMock(CacheEventMutex::class);
   });
   $this->app->bind(SchedulingMutex::class, function () {
       return $this->createMock(CacheSchedulingMutex::class);
   });
   ```

3. **ServiceProvider テストの制約**
   - `Container` では `runningInConsole()` がないため `boot()` のテストが困難
   - `register()` のテストは `Container` のみで可能
   - 完全なテストには実際の Laravel Application が必要

### 今後のフェーズへの影響

1. **Phase 3 タスク 3.4 は完了済み**
   - `dispatchVia()` と `getDispatcherType()` は Phase 1 で実装済み

2. **各フェーズで ServiceProvider テストを更新**
   - 新しいバインディングを追加するたびにテスト追加

3. **カバレッジ目標**
   - 各フェーズで新規実装コードは 100% を目指す

4. **レビュープロセスの標準化**
   - 各フェーズ完了後に `/pr-review-toolkit:review-pr` でレビュー実施
   - フィードバック対応後に次フェーズへ進む
   - Phase 1.5, 1.6 はレビューフィードバック対応から生まれた
   - レビュー結果に応じて追加タスク（Phase X.5 等）が発生する可能性あり

---

## Phase 1 レビュー修正計画

**Opusレビューで検出された4件のIssueに対する修正**

### 修正タスク一覧（TDDアプローチ）

| 順序 | Issue | ファイル | 作業内容 |
|------|-------|----------|----------|
| 1 | Issue 3 | `tests/Clock/FixedClockTest.php` | T1.3 setTime()テスト追加 |
| 2 | Issue 1 | `src/Clock/FixedClock.php` | setTime()メソッド実装 |
| 3 | Issue 4 | `tests/Scheduling/ClockAwareEventTest.php` | プロパティ検証テスト追加 |
| 4 | Issue 2 | `src/Scheduling/ClockAwareEvent.php` | isRecoverable(), getGracePeriod()実装 |

### Step 1: FixedClockTest に setTime() テスト追加

```php
/**
 * T1.3: FixedClock::setTime() で時刻を変更できる
 * @test
 */
public function it_can_change_fixed_time_with_setTime()
{
    $initialTime = new DateTimeImmutable('2024-01-01 12:00:00');
    $newTime = new DateTimeImmutable('2024-01-01 15:00:00');
    $clock = new FixedClock($initialTime);

    $this->assertEquals($initialTime, $clock->now());
    $clock->setTime($newTime);
    $this->assertEquals($newTime, $clock->now());
}
```

### Step 2: FixedClock に setTime() 実装

```php
/**
 * Set a new fixed time.
 * @param DateTimeImmutable $time
 * @return void
 */
public function setTime(DateTimeImmutable $time): void
{
    $this->fixedTime = $time;
}
```

### Step 3: ClockAwareEventTest にプロパティ検証テスト追加

追加するテスト:
- `withGracePeriod_sets_recoverable_to_true()` - T1.5拡張
- `withGracePeriod_sets_correct_interval()` - T1.6拡張
- `withGracePeriod_with_null_sets_unlimited()` - 無制限猶予検証
- `enableRecovery_sets_recoverable_with_unlimited_grace()` - T1.7拡張
- `recoverable_is_false_by_default()` - デフォルト値検証

### Step 4: ClockAwareEvent に getter メソッド実装

```php
/**
 * リカバリが有効かどうかを取得
 * @return bool
 */
public function isRecoverable()
{
    return $this->recoverable;
}

/**
 * 猶予期間を取得
 * @return DateInterval|null
 */
public function getGracePeriod()
{
    return $this->gracePeriod;
}
```

### 検証手順

```bash
# Step 1-2 後
./vendor/bin/phpunit tests/Clock/FixedClockTest.php

# Step 3-4 後
./vendor/bin/phpunit tests/Scheduling/ClockAwareEventTest.php

# 最終確認
./vendor/bin/phpunit --testdox
```

---

## 重要ファイル一覧

### 新規作成ファイル

```
src/
├── Clock/
│   ├── ClockInterface.php
│   ├── SystemClock.php
│   └── FixedClock.php
├── Scheduling/
│   ├── ClockAwareSchedule.php
│   └── ClockAwareEvent.php
├── Orchestrator/
│   ├── ScheduleOrchestratorInterface.php
│   └── DefaultScheduleOrchestrator.php
├── Dispatcher/
│   ├── ScheduleDispatcherInterface.php
│   ├── CompositeDispatcher.php
│   ├── LocalDispatcher.php
│   └── StepFunctionsDispatcher.php
└── Tracker/
    ├── ExecutionTrackerInterface.php
    └── CacheExecutionTracker.php

config/
└── graceful-scheduler.php

tests/
├── Unit/
│   ├── Clock/
│   │   └── SystemClockTest.php
│   ├── Scheduling/
│   │   ├── ClockAwareScheduleTest.php
│   │   └── ClockAwareEventTest.php
│   ├── Providers/
│   │   └── GracefulScheduleWorkerProviderTest.php
│   ├── Orchestrator/
│   │   └── ScheduleOrchestratorTest.php
│   ├── Dispatcher/
│   │   ├── CompositeDispatcherTest.php
│   │   ├── LocalDispatcherTest.php
│   │   └── StepFunctionsDispatcherTest.php
│   └── Tracker/
│       └── CacheExecutionTrackerTest.php
├── Helper/
│   └── FixedClock.php
└── Integration/
    └── GracePeriodIntegrationTest.php
```

### 更新ファイル

- `src/Console/GracefulScheduleWorkCommand.php`
- `src/Providers/GracefulScheduleWorkerProvider.php`
- `composer.json`

---

## Phase 1.5: レビューフィードバック対応（リファクタリング）

**目的**: コード品質とテスト構造を改善

### タスク一覧

| # | タスク | 成果物 | 説明 |
|---|--------|--------|------|
| 1.5.1 | Unit ディレクトリ作成・移動 | `tests/Unit/Clock/`, `tests/Unit/Scheduling/` | テスト構造整理 |
| 1.5.2 | FixedClock を tests/Helper に移動 | `tests/Helper/FixedClock.php` | テスト専用コードの分離 |
| 1.5.3 | phpunit.xml.dist 更新 | `phpunit.xml.dist` | testsuite パス更新 |
| 1.5.4 | ServiceProvider リファクタリング | `src/Providers/GracefulScheduleWorkerProvider.php` | callable 除去 |
| 1.5.5 | テスト内の use 文・namespace 更新 | 各テストファイル | パス変更対応 |
| 1.5.6 | 全テスト通過確認 | - | 動作確認 |

### 詳細

#### 1.5.1: テストディレクトリ移動

```
変更前:
tests/
├── Clock/
│   ├── FixedClockTest.php
│   └── SystemClockTest.php
├── Scheduling/
│   ├── ClockAwareEventTest.php
│   └── ClockAwareScheduleTest.php

変更後:
tests/
├── Unit/
│   ├── Clock/
│   │   └── SystemClockTest.php
│   └── Scheduling/
│       ├── ClockAwareEventTest.php
│       └── ClockAwareScheduleTest.php
├── Helper/
│   └── FixedClock.php
```

#### 1.5.2: FixedClock の移動

- ファイル: `src/Clock/FixedClock.php` → `tests/Helper/FixedClock.php`
- namespace: `RakkoInc\LaravelGracefulScheduleWorker\Clock` → `RakkoInc\LaravelGracefulScheduleWorker\Helper`
- FixedClockTest は削除（テストヘルパーにはテスト不要）

#### 1.5.3: phpunit.xml.dist 更新

```xml
<testsuite name="Unit">
    <directory suffix="Test.php">./tests/Unit</directory>
</testsuite>
```

#### 1.5.4: ServiceProvider リファクタリング

```php
// callable を使わない形式に変更
$this->app->singleton(ClockInterface::class, SystemClock::class);
$this->app->singleton(ClockAwareSchedule::class);
```

#### 1.5.5: テスト内 use 文・namespace 更新

```php
// FixedClock の import 変更
// 変更前
use RakkoInc\LaravelGracefulScheduleWorker\Clock\FixedClock;

// 変更後
use RakkoInc\LaravelGracefulScheduleWorker\Helper\FixedClock;

// テストの namespace 変更（Unit ディレクトリ対応）
// 変更前
namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Clock;

// 変更後
namespace RakkoInc\LaravelGracefulScheduleWorker\Tests\Unit\Clock;
```

### 検証

```bash
composer dump-autoload
./vendor/bin/phpunit --testdox
```

---

## Phase 1.6: カバレッジ改善

**目的**: テストカバレッジを向上させ、コード品質を確保する

### 調査結果

#### カバレッジ未達部分

| ファイル | メソッド | 状況 |
|---------|---------|------|
| ClockAwareEvent | `dispatchVia()` | 未テスト |
| ClockAwareEvent | `getDispatcherType()` | 未テスト |
| ClockAwareSchedule | `command()` class_exists分岐 | 未テスト |

#### オーバーライドの必要性確認

| メソッド | 結論 | 理由 |
|---------|------|------|
| `command()` | **不要（削除）** | 親クラスと完全同一。`$this->exec()` は子クラスの `exec()` を呼ぶ |
| `exec()` | **必要** | `ClockAwareEvent` を生成し `$clock` を注入するため |

### タスク一覧

| # | タスク | 成果物 |
|---|--------|--------|
| 1.6.1 | `command()` メソッドを削除 | `src/Scheduling/ClockAwareSchedule.php` |
| 1.6.2 | dispatchVia/getDispatcherType テスト追加 | `tests/Unit/Scheduling/ClockAwareEventTest.php` |
| 1.6.3 | カバレッジ計測・確認 | 80%以上を目標 |

### 追加テストケース

#### ClockAwareEventTest に追加

```php
/**
 * @test
 */
public function dispatchVia_returns_self_for_method_chaining()
{
    $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
    $result = $event->dispatchVia('local');
    $this->assertSame($event, $result);
}

/**
 * @test
 */
public function getDispatcherType_returns_null_by_default()
{
    $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
    $this->assertNull($event->getDispatcherType());
}

/**
 * @test
 */
public function dispatchVia_sets_dispatcher_type()
{
    $event = new ClockAwareEvent($this->mutex, 'php artisan test', $clock);
    $event->dispatchVia('stepfunctions');
    $this->assertEquals('stepfunctions', $event->getDispatcherType());
}
```

#### ClockAwareSchedule の変更

`command()` メソッドを削除。親クラスの `command()` が `$this->exec()` を呼ぶため、
オーバーライドした `ClockAwareSchedule::exec()` が正しく呼ばれる。

### 検証

```bash
XDEBUG_MODE=coverage php -dzend_extension=xdebug.so ./vendor/bin/phpunit --coverage-text
```

- [ ] ClockAwareEvent: 90%以上
- [ ] ClockAwareSchedule: 80%以上（class_exists分岐除く）
- [ ] 全体: 50%以上

---

## 次のアクション

**Phase 2 から開始**: Dispatcher 分離

### Phase 2 開始前チェックリスト

- [x] Phase 1.6 完了（カバレッジ 100%）
- [x] 全テスト通過（28 tests, 56 assertions）
- [x] composer scripts 追加済み
- [ ] DESIGN.md の Dispatcher 設計を確認
- [ ] GracefulScheduleWorkCommand の現在の実装を確認
