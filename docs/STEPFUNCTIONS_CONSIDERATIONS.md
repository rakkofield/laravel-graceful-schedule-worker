# Step Functions 実装考慮事項

本ドキュメントでは、Laravel スケジューラーと AWS Step Functions を統合する際に考慮すべき事項をまとめる。

---

## 目次

1. [アーキテクチャ概要](#アーキテクチャ概要)
2. [実行モデル](#実行モデル)
3. [State Machine 設計](#state-machine-設計)
4. [重複実行の防止](#重複実行の防止)
5. [エラーハンドリング](#エラーハンドリング)
6. [タイムアウト設計](#タイムアウト設計)
7. [IAM 権限設計](#iam-権限設計)
8. [コスト最適化](#コスト最適化)
9. [監視とオブザーバビリティ](#監視とオブザーバビリティ)
10. [運用考慮事項](#運用考慮事項)

---

## アーキテクチャ概要

### 責任分担

| コンポーネント | 責任 |
|--------------|------|
| Orchestrator (ECS Task) | スケジュール due 判定、Step Functions 起動、取りこぼしリカバリ |
| Step Functions | ジョブ実行の信頼性確保、リトライ、タイムアウト管理 |
| ECS Task (Worker) | 実際のジョブ処理 |

### 処理フロー

```
Orchestrator
    │
    ├─ due 判定 (ClockAware)
    │
    └─ StartExecution API
           │
           ▼
    Step Functions State Machine
           │
           ├─ ECS RunTask
           │      │
           │      ▼
           │   Worker Task (artisan command)
           │      │
           │      ▼
           │   終了 (exit code)
           │
           └─ 成功/失敗の記録
```

---

## 実行モデル

### At-least-once セマンティック

Step Functions は「少なくとも1回実行」を保証する。以下のケースで重複実行が発生しうる：

1. **Orchestrator の再起動**: 起動時リカバリで過去分を再実行
2. **Step Functions のリトライ**: ECS Task 失敗時の自動リトライ
3. **ネットワーク障害**: StartExecution API のタイムアウト後のリトライ

**対策**: アプリケーション側で冪等性を担保する必要がある。

### Exactly-once が必要な場合

Step Functions の Execution Name による重複防止は「同一 State Machine に対する同一 Execution Name の並行実行」を防ぐ。ただし：

- 異なる Execution Name であれば並行実行される
- Execution 終了後は同一 Name で再実行可能

完全な Exactly-once が必要な場合は、アプリケーション層でのロック機構が必要。

---

## State Machine 設計

### 設計方針

**推奨: シンプルな単一 Task State**

```
Start → RunEcsTask → End
```

複雑なワークフロー（分岐、並列、条件付き実行）が必要な場合は、Laravel 側で制御するか、別の State Machine として設計する。

### Task State の構成

ECS RunTask を使用する場合の考慮事項：

| 項目 | 設定指針 |
|-----|---------|
| 統合パターン | `.sync` (同期実行) - タスク完了まで待機 |
| コンテナ上書き | artisan コマンドを引数として渡す |
| 結果パス | ECS Task の終了コードを取得 |

### 入力設計

State Machine への入力として必要な情報：

```json
{
  "command": "reports:generate",
  "arguments": ["--date=2024-01-01"],
  "dueAt": "2024-01-01T03:00:00Z",
  "mutexName": "schedule-reports:generate",
  "isRecovery": false
}
```

- `command`: 実行する artisan コマンド
- `arguments`: コマンド引数
- `dueAt`: 本来の due 時刻（冪等性チェックに使用可能）
- `mutexName`: Laravel のイベント識別子
- `isRecovery`: リカバリ実行かどうか

---

## 重複実行の防止

### Execution Name 戦略

Execution Name は Step Functions 内で一意である必要がある。

**推奨フォーマット**:

```
{mutexName}-{dueAtTimestamp}
```

例: `schedule-reports-generate-1704067200`

**メリット**:
- 同一 due 時刻のイベントは1回だけ実行される
- Orchestrator が複数回 StartExecution を呼んでも安全
- 実行履歴から due 時刻が判別可能

**注意**:
- Execution Name は最大 80 文字
- 使用可能文字: `a-z`, `A-Z`, `0-9`, `-`, `_`
- mutexName に特殊文字が含まれる場合はハッシュ化が必要

### ExecutionAlreadyExists エラー

同一 Execution Name で StartExecution を呼ぶと `ExecutionAlreadyExists` エラーが返る。

**対応方針**:

1. **成功として扱う**: 既に実行中/完了なので、Orchestrator は正常終了とみなす
2. **ログ記録**: 重複呼び出しが発生した事実を記録
3. **ExecutionTracker 更新**: 実行済みとしてマーク

---

## エラーハンドリング

### エラーの分類

| エラー種別 | 例 | 対応 |
|-----------|---|------|
| 一時的エラー | ECS 容量不足、ネットワークタイムアウト | リトライ |
| 永続的エラー | コンテナイメージ不正、権限エラー | リトライせず失敗 |
| アプリケーションエラー | 終了コード 1 | ビジネス要件による |

### リトライ設計

```
ECS Task 失敗
    │
    ├─ 一時的エラー → 最大 3 回リトライ（バックオフ付き）
    │
    └─ 永続的エラー → 即時失敗
```

**リトライ間隔の目安**:
- 初回: 30 秒後
- 2回目: 2 分後
- 3回目: 5 分後

### 失敗通知

Step Functions の失敗は以下の方法で検知：

1. **CloudWatch Events**: State Machine の状態変化をトリガー
2. **SNS 通知**: 失敗時にアラート
3. **CloudWatch Logs Insights**: 失敗パターンの分析

---

## タイムアウト設計

### タイムアウトの階層

```
Step Functions Execution Timeout
    │
    └─ Task State Timeout
           │
           └─ ECS Task Stop Timeout
                  │
                  └─ コンテナ SIGTERM → SIGKILL
```

各層のタイムアウトは外側 > 内側 の関係にする。

### 推奨設定

| 層 | 設定値 | 説明 |
|---|-------|------|
| Execution Timeout | ジョブ最大時間 + 5分 | リトライを含む全体の上限 |
| Task Timeout | ジョブ最大時間 + 1分 | 単一実行の上限 |
| ECS Stop Timeout | 30秒 | graceful shutdown の猶予 |
| Heartbeat | 5分ごと | 長時間ジョブの生存確認 |

### Heartbeat の活用

長時間実行ジョブでは Heartbeat を使用：

- ECS Task 内から定期的に `SendTaskHeartbeat` を送信
- Heartbeat 停止 = 異常として検知
- `HeartbeatTimeout` 超過で自動キャンセル

---

## IAM 権限設計

### 最小権限の原則

| ロール | 必要な権限 |
|-------|----------|
| Orchestrator Task Role | `states:StartExecution`, `states:DescribeExecution` |
| Step Functions Execution Role | `ecs:RunTask`, `ecs:DescribeTask`, `logs:*` |
| Worker Task Role | アプリケーション固有の権限 |

### リソースベースの制限

- Orchestrator は特定の State Machine のみ実行可能
- Step Functions は特定の ECS クラスター/タスク定義のみ使用可能
- 条件キーで VPC やタグによる制限を追加

---

## コスト最適化

### Step Functions の料金モデル

- Standard Workflow: 状態遷移ごとに課金
- Express Workflow: 実行回数と時間で課金

**選択基準**:

| 条件 | 推奨 |
|-----|------|
| 実行時間 5 分以内 | Express（コスト効率良） |
| 実行時間 5 分超 | Standard |
| 実行履歴が必要 | Standard（90 日保持） |
| 高頻度実行 | Express |

### ECS Fargate Spot の活用

リトライ可能なジョブでは Fargate Spot を検討：

- 最大 70% のコスト削減
- 中断時は Step Functions が自動リトライ
- 中断に弱いジョブには使用しない

---

## 監視とオブザーバビリティ

### メトリクス

監視すべき主要メトリクス：

| メトリクス | 意味 | アラート閾値 |
|-----------|-----|------------|
| ExecutionsFailed | 失敗した実行数 | > 0 |
| ExecutionTime | 実行時間 | 予想時間の 2 倍 |
| ExecutionsTimedOut | タイムアウト数 | > 0 |
| ThrottledEvents | スロットリング数 | > 0 |

### ログ設計

Step Functions の実行ログは CloudWatch Logs に出力可能：

- `ALL`: 全状態遷移を記録
- `ERROR`: エラーのみ
- `FATAL`: 致命的エラーのみ
- `OFF`: 無効

**推奨**: 本番環境では `ERROR` 以上、デバッグ時は `ALL`

### トレーシング

X-Ray 統合により、以下を可視化：

- Orchestrator → Step Functions → ECS Task の呼び出しチェーン
- 各ステップのレイテンシ
- エラー発生箇所の特定

---

## 運用考慮事項

### デプロイ戦略

State Machine の更新時：

1. **バージョン管理**: State Machine には自動バージョニングがない
2. **エイリアス**: 名前付きエイリアスで本番/ステージングを分離
3. **ロールバック**: 問題発生時は Terraform/CDK で前バージョンに戻す

### 実行中のデプロイ

実行中の State Machine を更新しても、実行中のものには影響しない。新規実行から新定義が適用される。

### クォータ管理

AWS アカウントのクォータに注意：

| リソース | デフォルト上限 |
|---------|--------------|
| 同時実行数 (Standard) | 1,000,000 |
| 状態遷移/秒 | 1,500 |
| StartExecution/秒 | 200 |
| ECS RunTask/秒 | リージョンによる |

高頻度スケジュール（毎分 × 多数のイベント）では StartExecution のレート制限に注意。

### 障害時の対応

| 障害シナリオ | 対応 |
|------------|------|
| Orchestrator 停止 | 再起動時にリカバリ実行 |
| Step Functions 障害 | 手動で再実行、または次回 due を待つ |
| ECS 容量不足 | Capacity Provider 設定、または手動スケール |
| AWS リージョン障害 | マルチリージョン構成（要設計） |

---

## 参考資料

- [AWS Step Functions Developer Guide](https://docs.aws.amazon.com/step-functions/latest/dg/)
- [Step Functions and Amazon ECS/Fargate](https://docs.aws.amazon.com/step-functions/latest/dg/connect-ecs.html)
- [Step Functions Best Practices](https://docs.aws.amazon.com/step-functions/latest/dg/sfn-best-practices.html)
- [Step Functions Service Quotas](https://docs.aws.amazon.com/step-functions/latest/dg/limits.html)