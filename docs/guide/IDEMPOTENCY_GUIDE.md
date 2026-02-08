# Laravel Graceful Schedule Worker - Idempotency Guidelines

> **Note**: For details on at-least-once semantics, see
> [ARCHITECTURE.md - Execution Guarantee Model](../internals/ARCHITECTURE.md#execution-guarantee-model)

## Table of Contents

1. [Why Idempotency Is Necessary](#why-idempotency-is-necessary)
2. [Fundamental Principles of Idempotency](#fundamental-principles-of-idempotency)
3. [Non-Idempotent Examples (Dangerous)](#non-idempotent-examples-dangerous)
4. [Idempotent Examples (Safe)](#idempotent-examples-safe)
5. [Best Practices](#best-practices)
6. [Further Reading](#further-reading)

---

## Why Idempotency Is Necessary

This package provides **at-least-once semantics**, which means that during network failures or crash recovery, duplicate executions may rarely occur. Therefore, all scheduled jobs must be designed to be idempotent.

## Fundamental Principles of Idempotency

**Definition of idempotency**: Executing the same operation multiple times with the same input produces the same result as executing it only once.

## Non-Idempotent Examples (Dangerous)

```php
// Increments balance every time -> double billing on duplicate execution
class PaymentJob
{
    public function handle()
    {
        DB::table('balances')->increment('amount', 100);
        // -> 1st run: balance +100
        // -> 2nd run: balance +200 (double billing from duplicate execution) ❌
    }
}
```

```php
// Increments count every time -> double counting on duplicate execution
class MetricsAggregateJob
{
    public function handle()
    {
        DB::table('stats')->increment('count');
        // -> 1st run: count = 1
        // -> 2nd run: count = 2 (double counting from duplicate execution) ❌
    }
}
```

## Idempotent Examples (Safe)

### Pattern 1: Deduplication with Unique Keys

```php
// updateOrInsert keyed by execution date -> idempotent
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
                ['date' => $this->executionDate],  // <- unique key
                [
                    'total_sales' => $this->calculateSales(),
                    'updated_at' => now(),
                ]
            );
        // -> 1st run: record created
        // -> 2nd run: same record updated (idempotent) ✅
    }
}
```

### Pattern 2: Duplicate Check with Transaction ID

```php
// Deduplication using transaction ID
class PaymentJob
{
    private $transactionId;
    private $executionDate;

    public function handle()
    {
        DB::transaction(function () {
            // 1. Duplicate check
            $exists = DB::table('transactions')
                ->where('transaction_id', $this->transactionId)
                ->where('execution_date', $this->executionDate)
                ->exists();

            if ($exists) {
                Log::info("Transaction already processed", [
                    'transaction_id' => $this->transactionId,
                ]);
                return;  // Skip
            }

            // 2. Record transaction
            DB::table('transactions')->insert([
                'transaction_id' => $this->transactionId,
                'execution_date' => $this->executionDate,
                'amount' => 100,
                'processed_at' => now(),
            ]);

            // 3. Update balance
            DB::table('balances')->increment('amount', 100);
        });
        // -> 1st run: transaction recorded + balance updated
        // -> 2nd run: skipped by duplicate check (idempotent) ✅
    }
}
```

### Pattern 3: Deterministic Naming

```php
// Guarantee uniqueness per period
class InvoiceGenerationJob
{
    private $customerId;
    private $period;

    public function handle()
    {
        // Generate a deterministic ID
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
        // -> Same customer and period produce the same invoice_id
        // -> Duplicate execution updates the same record (idempotent) ✅
    }
}
```

## Best Practices

1. **Pass execution time as a parameter**: Make job inputs deterministic
2. **Use unique key constraints**: Prevent duplicates at the database level
3. **Use updateOrInsert**: Perform existence check + insert/update atomically
4. **Wrap in transactions**: Execute multiple operations atomically
5. **Track with logs**: Record logs to detect duplicate executions

## Further Reading

- [SCHEDULE_EXECUTION_SEMANTICS.md](../internals/SCHEDULE_EXECUTION_SEMANTICS.md) - Theoretical background and details of execution guarantees
- [ARCHITECTURE.md](../internals/ARCHITECTURE.md) - Architecture design
- [STEPFUNCTIONS_IMPLEMENTATION.md](../internals/STEPFUNCTIONS_IMPLEMENTATION.md) - Step Functions implementation details

---

**Last Updated**: 2026-01-25
**Version**: 2.0.0
**Author**: Laravel Graceful Schedule Worker Team
