# Laravel Graceful Schedule Worker - 冪等性ガイドライン

> **Note**: At-least-once セマンティックの詳細は
> [ARCHITECTURE.md - 実行保証モデル](./ARCHITECTURE.md#実行保証モデル) を参照

## 目次

1. [なぜ冪等性が必要か](#なぜ冪等性が必要か)
2. [冪等性の基本原則](#冪等性の基本原則)
3. [冪等でない例（危険）](#冪等でない例危険)
4. [冪等な例（安全）](#冪等な例安全)
5. [ベストプラクティス](#ベストプラクティス)
6. [詳細情報](#詳細情報)

---

## なぜ冪等性が必要か

本パッケージは **At-least-once セマンティック**を提供するため、ネットワーク障害やクラッシュリカバリ時に、稀に重複実行される可能性があります。そのため、全てのスケジュールジョブは冪等に設計する必要があります。

## 冪等性の基本原則

**冪等性の定義**: 同じ入力で複数回実行しても、最初の1回だけ実行したのと同じ結果になること

## 冪等でない例（危険）

```php
// 毎回残高を増やす → 重複実行で二重課金
class PaymentJob
{
    public function handle()
    {
        DB::table('balances')->increment('amount', 100);
        // → 1回目: 残高 +100
        // → 2回目: 残高 +200 (重複実行で二重課金) ❌
    }
}
```

```php
// 毎回カウントアップ → 重複実行で二重カウント
class MetricsAggregateJob
{
    public function handle()
    {
        DB::table('stats')->increment('count');
        // → 1回目: count = 1
        // → 2回目: count = 2 (重複実行で二重カウント) ❌
    }
}
```

## 冪等な例（安全）

### パターン1: ユニークキーによる重複排除

```php
// 実行日をキーに updateOrInsert → 冪等
class DailyReportJob
{
    private $executionDate;

    public function __construct(string $executionDate)
    {
        $this->executionDate = $executionDate;
    }

    public function handle()
    {
        DB::table('daily_reports')
            ->updateOrInsert(
                ['date' => $this->executionDate],  // ← ユニークキー
                [
                    'total_sales' => $this->calculateSales(),
                    'updated_at' => now(),
                ]
            );
        // → 1回目: レコード作成
        // → 2回目: 同じレコードを更新（冪等） ✅
    }
}
```

### パターン2: トランザクション ID による重複チェック

```php
// トランザクション ID で重複排除
class PaymentJob
{
    private $transactionId;
    private $executionDate;

    public function handle()
    {
        DB::transaction(function () {
            // 1. 重複チェック
            $exists = DB::table('transactions')
                ->where('transaction_id', $this->transactionId)
                ->where('execution_date', $this->executionDate)
                ->exists();

            if ($exists) {
                Log::info("Transaction already processed", [
                    'transaction_id' => $this->transactionId,
                ]);
                return;  // スキップ
            }

            // 2. トランザクション記録
            DB::table('transactions')->insert([
                'transaction_id' => $this->transactionId,
                'execution_date' => $this->executionDate,
                'amount' => 100,
                'processed_at' => now(),
            ]);

            // 3. 残高更新
            DB::table('balances')->increment('amount', 100);
        });
        // → 1回目: トランザクション記録 + 残高更新
        // → 2回目: 重複チェックでスキップ（冪等） ✅
    }
}
```

### パターン3: 決定論的な名前付け

```php
// 期間ごとの一意性を保証
class InvoiceGenerationJob
{
    private $customerId;
    private $period;

    public function handle()
    {
        // 決定論的な ID を生成
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
        // → 同じ顧客・期間なら同じ invoice_id
        // → 重複実行でも同じレコードを更新（冪等） ✅
    }
}
```

## ベストプラクティス

1. **実行時刻をパラメータとして渡す**: ジョブの入力を決定論的にする
2. **ユニークキー制約を活用**: データベースレベルで重複を防ぐ
3. **updateOrInsert を使用**: 存在チェック + 挿入/更新を原子的に実行
4. **トランザクションで包む**: 複数の操作を原子的に実行
5. **ログで追跡**: 重複実行を検出できるようにログを記録

## 詳細情報

- [SCHEDULE_EXECUTION_SEMANTICS.md](./SCHEDULE_EXECUTION_SEMANTICS.md) - 実行保証の理論的背景と詳細
- [ARCHITECTURE.md](./ARCHITECTURE.md) - アーキテクチャ設計
- [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md) - Step Functions 実装詳細

---

**Last Updated**: 2026-01-25
**Version**: 2.0.0
**Author**: Laravel Graceful Schedule Worker Team
