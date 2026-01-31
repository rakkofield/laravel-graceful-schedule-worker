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

### skeleton 依存更新

`src/` 配下のコードを変更した場合、Integration テスト前に skeleton の依存を更新する：

```bash
composer skeleton:update
```

### タスク完了時

タスクが完了したら、確認を待たずに以下を実行する：

1. `composer test` でテスト実行
2. テストが通ればコミットを作成

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
- Fake クラスは `tests/Helper/` に配置
