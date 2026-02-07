# Laravel Graceful Schedule Worker

## プロジェクト概要

Laravel のスケジュールタスクを graceful に実行するためのライブラリ。
シグナルハンドリングによる安全な停止と、Step Functions 連携をサポート。

## 開発ワークフロー

### テスト実行

```bash
# 全テスト
composer test

# カバレッジ（テキスト）
composer test:coverage

# カバレッジ（HTML）
composer test:coverage-html
```

### 初回セットアップ

```bash
composer install
composer tools:install
composer skeleton:update
```

### Step Functions・Redis テスト

`composer test` 実行時に moto と Redis が自動起動し、Step Functions テスト・Redis 統合テストも実行されます。

```bash
# テスト実行（moto・Redis は自動起動）
composer test

# 個別操作
composer stepfunctions:up      # 全サービス起動（moto・Redis、healthcheck で待機）
composer stepfunctions:down    # 全サービス停止
composer redis:up              # Redis のみ起動
composer redis:down            # Redis のみ停止
```

**前提条件:**
- Docker がインストールされていること

### skeleton 依存更新

`src/` 配下のコードを変更した場合、Integration テスト前に skeleton の依存を更新する：

```bash
composer skeleton:update
```

### 静的解析

```bash
composer tools:install  # 初回のみ
composer phpstan        # 静的解析（PHP 8.1+ が必要）
composer phpcs          # コーディング規約チェック
composer phpcbf         # 自動修正

# PHP インタープリターを指定する場合
PHP_SA_BINARY=/path/to/php8.1 composer phpstan
```

### タスク完了時

タスクが完了したら、確認を待たずに以下を実行する：

1. `composer phpstan` で静的解析
2. `composer phpcs` でコーディング規約チェック
3. `composer test` でテスト実行
4. すべて通ればコミットを作成

## PHP バージョン

- 最小要件: PHP 7.2.5
- `ext-pcntl` 必須（シグナルハンドリング）
- nullable types (`?string`) は PHP 7.1 からサポートされているため使用可能

## 設計ドキュメント

- `docs/DESIGN.md` - メイン設計仕様（最初に参照）
- `docs/` - その他の設計ドキュメント（ARCHITECTURE, STEPFUNCTIONS_IMPLEMENTATION 等）
- `.claude/plans/` - 実装計画

## ディレクトリ構成

```
src/
├── Clock/                # 時刻・Sleep 抽象化（ClockInterface, SleeperInterface）
├── Console/              # Artisan コマンド（GracefulScheduleWorkCommand）
├── Dispatcher/           # タスクディスパッチャー
│   ├── Result/           # ディスパッチ結果型（Started, Failed, Skipped, AlreadyRunning）
│   └── StepFunctions/    # Step Functions クライアント・例外・名前生成
├── Orchestrator/         # スケジュール実行調整（DefaultScheduleOrchestrator）
├── Providers/            # ServiceProvider
├── Scheduling/           # ClockAwareSchedule, ClockAwareEvent
└── Tracker/              # 実行履歴追跡（CacheExecutionTracker, NullExecutionTracker）

tests/
├── Unit/                 # ユニットテスト（src/ と同じディレクトリ構成）
├── Integration/          # 統合テスト（skeleton 使用、Redis 必要）
├── E2E/                  # E2E テスト
├── Helper/               # テストヘルパー（Fake, Stub, Spy 等）
└── StepFunctions/        # Step Functions テスト用設定（state-machine.json）

skeleton/                 # テスト用 Laravel アプリケーション
```

## 重要な注意事項

- コンポーネント依存: Command → Orchestrator → Dispatcher → Result
- Dispatcher はデコレータパターン: CompositeDispatcher, TrackingDispatcher が ScheduleDispatcherInterface をラップ
- ライブラリなので `Log::` などの Laravel ファサードに直接依存しない
- `base_path()` などの Laravel ヘルパーも使用しない
- ServiceProvider で Schedule を extend しない（利用側が ClockAwareSchedule を選択可能）

## テストスタイル

- メソッド命名: `test` prefix + camelCase（例: `testReturnsCurrentTime`）
- テストID: `@testdox T1.1` 形式で PHPDoc に記述
- `declare(strict_types=1)` 必須（src/ と tests/ 両方）
- tearDown で `Container::setInstance(null)` を呼ぶ（Container を使用するテスト）
- Mock 禁止: Interface には Fake、実クラスには Stub/Spy を使用
- テスト用ヘルパークラス（Fake, Stub, Spy, Testable 等）は `tests/Helper/` に配置（1ファイル1クラスを維持）

