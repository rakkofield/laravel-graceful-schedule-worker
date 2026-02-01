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

### Step Functions テスト（LocalStack 使用）

```bash
# AWS SDK をインストール（初回のみ）
composer require aws/aws-sdk-php --dev

# LocalStack 起動 & テスト実行（一括）
composer test:stepfunctions

# 個別操作
composer stepfunctions:up      # LocalStack 起動
composer stepfunctions:down    # LocalStack 停止
composer stepfunctions:setup   # 起動 + State Machine 作成
```

**前提条件:**
- Docker がインストールされていること
- AWS CLI がインストールされていること（State Machine 作成用）

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
PHP_BINARY=/path/to/php8.1 composer phpstan
```

### タスク完了時

タスクが完了したら、確認を待たずに以下を実行する：

1. `composer phpstan` で静的解析
2. `composer phpcs` でコーディング規約チェック
3. `composer test` でテスト実行
4. すべて通ればコミットを作成

## PHP バージョン

- 最小要件: PHP 7.2.5
- nullable types (`?string`) は PHP 7.1 からサポートされているため使用可能

## 設計ドキュメント

- `docs/DESIGN.md` - 詳細な設計仕様
- `.claude/plans/` - 実装計画

## ディレクトリ構成

```
src/
├── Clock/           # 時刻抽象化（ClockInterface, SystemClock）
├── Console/         # Artisan コマンド
├── Dispatcher/      # タスクディスパッチャー
├── Providers/       # ServiceProvider
└── Scheduling/      # ClockAwareSchedule, ClockAwareEvent

tests/
├── Unit/            # ユニットテスト
├── Integration/     # 統合テスト（skeleton 使用）
└── Helper/          # テストヘルパー（FixedClock 等）

skeleton/            # テスト用 Laravel アプリケーション
```

## 重要な注意事項

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

## 作業方針

- 複数タスクがある場合は TaskCreate でタスクリストを作成して管理
- 独立した作業は並列実行する（複数の Write/Edit を同時に実行など）
- プラン作成時は、実装タスクを TaskCreate で登録してから ExitPlanMode を呼ぶ
