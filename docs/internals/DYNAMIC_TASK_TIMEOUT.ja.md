# Laravel Graceful Schedule Worker - Task タイムアウトの動的化 設計ドキュメント

> **Note**: Step Functions の実装詳細は [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md)、
> アーキテクチャ設計は [ARCHITECTURE.md](./ARCHITECTURE.md) を参照

| 項目 | 内容 |
|---|---|
| ステータス | 設計提案（未実装） |
| 作成日 | 2026-07-28 |
| 対象範囲 | Step Functions Task ステートのタイムアウトをジョブごとに変える仕組み |

## 目次

1. [背景と課題](#背景と課題)
2. [現状](#現状)
3. [AWS 側の制約](#aws-側の制約)
4. [検討した案](#検討した案)
5. [採用案: ロック TTL からの導出](#採用案-ロック-ttl-からの導出)
6. [導出式](#導出式)
7. [変更範囲](#変更範囲)
8. [ステートマシン側の必要変更](#ステートマシン側の必要変更)
9. [移行手順](#移行手順)
10. [テスト戦略](#テスト戦略)
11. [既存ドキュメントへの影響](#既存ドキュメントへの影響)
12. [未決事項と将来の拡張](#未決事項と将来の拡張)

---

## 背景と課題

Step Functions の `TimeoutSeconds` はステートマシン定義に埋め込む静的な値で、全実行で共通です。
ひとつのステートマシンで長さの異なるジョブを扱うと、**最長ジョブに合わせた値を全ジョブが共有する**ことになります。

その結果、次の非対称が生じます。

- 10分で終わるはずのジョブがハングしても、最長ジョブに合わせた値（例: 12時間）まで異常が検知されない
- その間 DynamoDB のロックも保持され続け、後続のディスパッチが `AlreadyRunning` で落ち続ける

つまり**短いジョブの異常検知の粒度が、最長ジョブの都合で決まってしまう**。これを解消するのが本ドキュメントの目的です。

### 前提となる制約

タイムアウト値は単独では決められません。次の依存関係があります。

```
ロック TTL (expiresAt)
    └─ RunEcsTask の TimeoutSeconds   ← これを超えるとタスクがロックより長生きする
```

ロック TTL を超えて ECS タスクが走り続けると、再ディスパッチがロックを奪取し、**同じジョブの ECS タスクが二重に起動します**。
`ReleaseLock` の所有権ガード（`executionArn = :arn`）はロックの誤削除を防ぎますが、二重起動そのものは防ぎません。

したがって「タイムアウトをジョブごとに変える」は、実質的に「**ロック TTL と整合したタイムアウトをジョブごとに与える**」という問題です。

---

## 現状

### タイムアウト値の所在

`RunEcsTask` の `TimeoutSeconds` はステートマシン定義に直書きされており、アプリケーションからは制御できません。

### ロック TTL の決まり方

一方、ロック TTL はイベントごとに決まります。

`src/Dispatcher/StepFunctions/PayloadBuilder.php:42-43`

```php
$lockTtl   = $event->resolveLockTtlSeconds($lockTtlSeconds);
$expiresAt = $dueAt->getTimestamp() + $lockTtl;
```

`ClockAwareEvent::resolveLockTtlSeconds()`（`src/Scheduling/ClockAwareEvent.php:286-293`）は、
`withoutOverlapping` が有効なときだけ Laravel の `$expiresAt`（**分**）を秒に変換して返し、
そうでなければ config の既定値（`graceful-scheduler.stepfunctions.lock_ttl`、既定 3600 秒）をそのまま返します。

```php
$schedule->command('batch:heavy')->hourly()->withoutOverlapping(60 * 8); // 8時間
```

### 重要: 排他は withoutOverlapping が前提

`LockKeyGenerator::generate()`（`src/Dispatcher/StepFunctions/LockKeyGenerator.php:40-44`）は、
`withoutOverlapping` が無いイベントに対しては `dueAt` を含むロックキーを生成します。
このためディスパッチごとにキーが変わり、**排他が一切効きません**。

排他が必要なジョブでは `withoutOverlapping()` の指定が必須であり、
その場合 config の `lock_ttl` は使われません。

### payload の現状

`Payload::toJson()`（`src/Dispatcher/StepFunctions/Payload.php:108-117`）が出力するキーは以下の 6 つで、
タイムアウトに相当するフィールドはありません。

```
command / mutexName / dueAt / lockKey / expiresAt / dispatchedAt
```

---

## AWS 側の制約

本設計の前提となる Amazon States Language の仕様です。
（出典: [Task workflow state](https://docs.aws.amazon.com/step-functions/latest/dg/state-task.html) /
[State machine structure](https://docs.aws.amazon.com/step-functions/latest/dg/statemachine-structure.html)）

| 項目 | 内容 |
|---|---|
| 対象ステート | `TimeoutSeconds` / `TimeoutSecondsPath` は **`Task` ステート専用**。`Pass` / `Map` / `Parallel` にはない |
| 記法 | `TimeoutSecondsPath` は **JSONPath モード専用**。Reference Path を書く |
| 解決値 | **正の整数**であること。null・0・負数・小数・文字列は `States.Runtime` |
| 排他制約 | **`TimeoutSeconds` と `TimeoutSecondsPath` の同時指定は不可** |
| 評価対象 | `Parameters` による再構成後ではなく、**ステートの入力**に対して解決される |
| 未指定時の既定 | `TimeoutSeconds` の既定は 99,999,999 秒（実質無制限） |
| タイムアウト時 | `States.Timeout` エラー。`Retry` / `Catch` で捕捉可能 |
| トップレベル | ステートマシン全体の `TimeoutSeconds` に**パス版は存在しない**（静的のみ） |

### 検証済みの事項

moto 5.1.20 の `CreateStateMachine` で以下を確認しました。

| 定義 | 結果 |
|---|---|
| `TimeoutSeconds` を消して `TimeoutSecondsPath` にする | 受理 |
| 両方を指定する | **拒否**（`Redefinition of type 'Timeout'`） |

`TimeoutSecondsPath` が**実行時に正しく機能するか**は未検証です。
moto は ECS 統合を実行できず、MiniStack は `.sync` の失敗系を再現しないため、
**実 AWS でのみ確認可能**です。

### 評価対象がステートの入力である根拠

AWS 公式の動的タイムアウトの例では、`Parameters` に含まれないフィールドを `TimeoutSecondsPath` が参照しています。

```json
"GlueJobTask": {
  "Type": "Task",
  "Resource": "arn:aws:states:::glue:startJobRun.sync",
  "Parameters": { "JobName": "myGlueJob" },
  "TimeoutSecondsPath": "$.params.maxTime"
}
```

このため、`Parameters` でフィールドを絞り込んでいるステートでも、
`TimeoutSecondsPath` は絞り込み前の入力に対して解決されます。

---

## 検討した案

| | 案A: ロック TTL から導出 | 案B: 専用 fluent API | 案C: config マップ |
|---|:--:|:--:|:--:|
| アプリ側の記述 | 不要（`withoutOverlapping` を流用） | `->timeoutAfter(1800)` | なし（config に列挙） |
| TTL と独立に決められる | ✗ | ✓ | ✓ |
| 新 API 表面 | なし | `ClockAwareEvent` に 1 メソッド | config スキーマ |
| スケジュール定義との近さ | ◎ | ◎ | ✗ |
| TTL > timeout の保証 | 構造的に保証 | 利用者任せ（検証が必要） | 利用者任せ |

### 案C を採らない理由

マッチングルール（完全一致 / 前方一致 / ワイルドカード）を作り込む必要がある割に、
スケジュール定義と設定が離れて不整合を起こしやすいためです。

### 案B を先行させない理由

2 点あります。

1. `ClockAwareEvent` は local ディスパッチでも使う共通クラスであり、
   `dispatch=local` のときに無視される設定が生えます（「設定したのに効かない」の典型）。
2. TTL との大小関係を利用者が壊せるため、結局は案A 相当の制約を検証ロジックとして後付けすることになります。

---

## 採用案: ロック TTL からの導出

**`expiresAt` と `dispatchedAt` からタイムアウト値を導出し、payload に常に含める。アプリ側の新しい API は追加しない。**

### 根拠

[背景と課題](#背景と課題)で述べたとおり、タイムアウトとロック TTL は独立に決められる値ではありません。
本案はこの依存関係を**導出式の構造として固定**します。

結果として、アプリ作者が考える値は 1 つだけになります。

> このジョブは最悪どれくらいかかるか

それを `withoutOverlapping($minutes)` に書けば、ECS 側のタイムアウトが自動的に追従します。

```php
$schedule->command('batch:heavy')->hourly()->withoutOverlapping(730); // 43800秒
```

### 一方通行ではない

案A を採用しても、案B は後方互換に追加できます。
`PayloadBuilder` が「明示指定があればそれを使い、無ければ導出する」という順序で見るだけです。
**TTL と独立に決めたい具体的な要求が出てから案B を足す**という判断が可能です。

---

## 導出式

```php
$expiresAt      = $dueAt->getTimestamp() + $lockTtl;
$timeoutSeconds = max(1, $expiresAt - $dispatchedAt->getTimestamp() - $buffer);
```

`PayloadBuilder::build()` は `$dueAt` と `$dispatchedAt` の両方を既に受け取っているため、追加の入力は不要です。

### なぜ「残りロック寿命」を基準にするか

単純な `$lockTtl - $buffer` ではなく `$expiresAt - $dispatchedAt - $buffer` とする理由は、
**ディスパッチ遅延を自動的に吸収するため**です。

`expiresAt` の起点は `dueAt`（スケジュール上の予定時刻）であり、実際のディスパッチはそれより後になります。
`$lockTtl - $buffer` だと、この遅延分だけタスクがロックより長生きしうるのに対し、
残りロック寿命を基準にすれば、遅れて起動した分だけタイムアウトが縮み、
**タスクがロックを追い越すことが構造的に起こらなくなります**。

### パラメータ

| 項目 | 既定値 | 備考 |
|---|---|---|
| `stepfunctions.timeout_buffer` | 60 秒 | ロック解放に要する余裕。config で変更可能にする |
| クランプ | `max(1, ...)` | 負値・0 は `States.Runtime` を引き起こすため下限を設ける |

`max(1, ...)` でのクランプは設定ミスを黙って飲み込むため、
`StepFunctionsServiceProvider` の起動時に `lock_ttl <= timeout_buffer` を検証して弾く方針とします。
`PayloadBuilder` にロガーを注入するより軽い対処です。

---

## 変更範囲

| ファイル | 内容 | 後方互換 |
|---|---|---|
| `src/Dispatcher/StepFunctions/Payload.php` | 第 7 引数 `int $timeoutSeconds`（**必須**）+ getter + `toJson()` にキー追加 | 破壊的変更（[後述](#timeoutseconds-を必須引数にする)） |
| `src/Dispatcher/StepFunctions/PayloadBuilder.php` | バッファの受け取りと導出 | コンストラクタ第 2 引数は省略可（既定 60） |
| `src/Providers/StepFunctionsServiceProvider.php:117` | config からバッファを読んで注入。起動時の値検証 | — |
| `config/graceful-scheduler.php:32` 付近 | `timeout_buffer` を追加 | — |

### テストの追随

| ファイル | 内容 |
|---|---|
| `tests/Unit/Dispatcher/StepFunctions/PayloadTest.php:99` | `$expectedKeys` にキーを追加 |
| `tests/Unit/Dispatcher/StepFunctionsDispatcherTest.php:146` | SFD.4 のキー集合アサーションを追随 |
| `tests/Unit/Dispatcher/StepFunctions/PayloadBuilderTest.php` | 導出ロジックのケースを追加（ディスパッチ遅延あり / なし、クランプ境界） |

### 既存利用者への影響

入力 JSON にキーが 1 つ増えるだけです。
`TimeoutSecondsPath` を書いていないステートマシンはこのフィールドを無視するため無害で、
Step Functions の入力サイズ上限（256KB）に対しても無視できる増加量です。

### timeoutSeconds を必須引数にする

`Payload` の第 7 引数は**必須の `int`** とし、`?int = null` にはしません。

省略可にすれば既存の直接生成を壊さずに済みますが、
**null のときキーを出力しない挙動と `TimeoutSecondsPath` の組み合わせは、本設計が消そうとしている失敗モードそのものです。**
解決できないパスは `States.Runtime` となり、これは非リトライアブルで必ず実行失敗になります。
「型としては null を許すが、実際には常に値が入っているので大丈夫」という状態を作らず、
**`Payload` が存在するなら `timeoutSeconds` が JSON に必ず載る**ことを型で保証します。

#### 影響範囲

リポジトリ内で `new Payload(` を呼んでいるのは以下だけです。

| 箇所 | 対応 |
|---|---|
| `src/Dispatcher/StepFunctions/PayloadBuilder.php:45` | 導出値を渡す |
| `tests/Unit/Dispatcher/StepFunctions/PayloadTest.php`（4 箇所） | 引数を追加 |

外部への影響は、`Payload` を直接 `new` しているカスタム `PayloadBuilder` がある場合に限られます。
`PayloadInterface` を実装した独自 Payload を返している場合は影響を受けませんが、
その場合は「`TimeoutSecondsPath` を使うならこのフィールドは必須」という要請が
ドキュメント上の約束として残ります（型では強制できないため）。

#### バッファ引数を省略可にする理由との違い

`PayloadBuilder` のコンストラクタに追加するバッファは省略可（既定 60）とします。
こちらはどんな整数値でも壊れず、未指定でも妥当な既定に落ちるため、
`timeoutSeconds` のように「未設定が実行時失敗に直結する」性質を持たないからです。

---

## ステートマシン側の必要変更

### RunEcsTask

`TimeoutSeconds` との同時指定は不可のため、削除して差し替えます。

```diff
   "RunEcsTask": {
     "Type": "Task",
     "Resource": "arn:aws:states:::ecs:runTask.sync",
-    "TimeoutSeconds": 43200,
+    "TimeoutSecondsPath": "$.timeoutSeconds",
```

`AcquireLock` / `ReleaseLock` の `TimeoutSeconds`（10 秒程度）は静的のままとします。
ジョブによって変わる値ではないため、動的化する理由がありません。

### トップレベルの TimeoutSeconds は設定しない

ステートマシン全体の `TimeoutSeconds` にはパス版が存在しないため、静的な値しか置けません。
`RunEcsTask` 側だけが動的になると、アプリが指定した値によって
**「トップレベル > Task」の大小関係が反転しうる**という問題が生じます。

反転した場合、実行レベルのタイムアウトが先に発火します。
これは **`Catch` で捕捉できない**ため `ReleaseLock` に到達せず、ロックが `expiresAt` まで残ります。

対処として、**トップレベルの `TimeoutSeconds` は設定しない**方針を採ります。

```diff
 {
   "Comment": "...",
   "StartAt": "AcquireLock",
-  "TimeoutSeconds": 43740,
   "States": {
```

これで問題ない理由は 3 点です。

1. **実行全体はすでに state 単位のタイムアウトで縛られている** —
   全 Task ステートにタイムアウトがあり、失敗経路も `Catch` されている
2. **state 単位のタイムアウトは捕捉できる** —
   `Catch(States.ALL)` → `MarkFailed` → `ReleaseLock` でロックが回収される
3. **最終防波堤は別にある** —
   Standard ワークフローの実行時間上限（1 年）と、DynamoDB 側の `expiresAt` による TTL 回収

#### 意図をコメントに残す

トップレベルにタイムアウトが無い状態は、レビュー時に設定漏れと区別が付きません。
放置すると善意で復元され、捕捉不可能なロック漏れ経路が黙って復活します。
`Comment` に理由を明記してください。

```json
{
  "Comment": "Intentionally has no top-level TimeoutSeconds: an execution-level timeout is not catchable and would skip ReleaseLock, leaking the lock until expiresAt. The run is bounded by RunEcsTask's TimeoutSecondsPath and recovered via Catch -> MarkFailed -> ReleaseLock.",
  "StartAt": "AcquireLock"
}
```

#### 静的な上限をどうしても置く場合

組織のポリシー等で明示的な上限が必要な場合は、
**アプリが指定しうる最大値を確実に上回る静的値**にしてください。
`withoutOverlapping()` を引数なしで呼ぶと Laravel 既定の 1440 分（86400 秒）になるため、
その可能性を残すなら 90000 以上が目安です。

### 長時間ステートのリトライ

state 単位のタイムアウトは**試行ごと**に適用されるため、
`MaxAttempts: 3` が効くと最悪 4 × `timeoutSeconds` まで実行が伸びます。

`ECS.AmazonECSException` / `ECS.ServiceException` は RunTask 送信直後に発生するのが通常であり、
長時間走る `.sync` を丸ごとやり直す動作は通常意図したものではありません。
長時間ステートでは `MaxAttempts` を絞ることを推奨します。

---

## 移行手順

**順序が重要です。** `$.timeoutSeconds` は現在の payload に存在せず、
解決できないパスは非リトライアブルな `States.Runtime` で即座に実行失敗となります。

1. **アプリ側を先にデプロイする** — payload に `timeoutSeconds` が載るようにする
   （この時点ではステートマシンが無視するだけなので無害）
2. デプロイ済みの実行入力に `timeoutSeconds` が含まれていることを確認する
3. **その後でステートマシンを更新する** — `TimeoutSeconds` を `TimeoutSecondsPath` に差し替え、
   トップレベルの `TimeoutSeconds` を削除する

「ステートマシンを先に変えて後からアプリを直す」という順序は取れません。

---

## テスト戦略

`TimeoutSecondsPath` が実行時に効くかは AWS の責務であり、
**ライブラリの責務は「payload に正しい値が載ること」**です。
この切り分けに沿って、CI で守る範囲を次のとおりとします。

| レイヤ | 手段 | 検証内容 |
|---|---|---|
| 単体 | `PayloadBuilderTest` | 導出式（遅延の有無、クランプ境界）、`toJson()` のキー集合 |
| 統合 | moto の `CreateStateMachine` | `TimeoutSecondsPath` 入りの ASL が受理されること |
| 実行時 | **実 AWS のみ** | 値が実際に反映されてタイムアウトすること |

実行時の検証をローカルで代替できない理由は次のとおりです。

- moto は Step Functions の ECS 統合を実行できない
  （`StartExecution` が HTTP 500。`moto/stepfunctions/parser/models.py:175` の `copy.deepcopy` が失敗する）
- MiniStack は `ecs:runTask.sync` を実 Docker で完走させられるが、
  **コンテナが非ゼロ終了しても Task を失敗させない**ため、タイムアウト／失敗系の検証には使えない

---

## 既存ドキュメントへの影響

[STEPFUNCTIONS_IMPLEMENTATION.ja.md](./STEPFUNCTIONS_IMPLEMENTATION.ja.md) の「タイムアウト設計」節と整合を取る必要があります。

| 既存の記述 | 本設計での扱い |
|---|---|
| 「各層のタイムアウトは外側 > 内側 の関係にします」 | 方針は維持。ただしトップレベルを置かないことで、この関係を静的に破れなくする |
| 「Execution Timeout: ジョブ最大時間 + 5分」 | **撤回**。トップレベルの `TimeoutSeconds` は設定しない |
| 「Task Timeout: ジョブ最大時間 + 1分」 | `withoutOverlapping` からの導出に置き換え |
| 「Heartbeat: 5分ごと」 | 本設計の対象外。ASL の `HeartbeatSeconds`（Activity ワーカーが `SendTaskHeartbeat` を送る仕組み）であり、DynamoDB ロックの `expiresAt` 延長とは別物である点に注意 |

---

## 未決事項と将来の拡張

### 未決

| 項目 | 内容 |
|---|---|
| `timeout_buffer` の既定値 | 60 秒を提案。ディスパッチ遅延は導出式が吸収するため、この値が担うのはロック解放分の余裕のみ |
| 実 AWS での動作確認 | `TimeoutSecondsPath` の実行時挙動を確認する手段と担当を決める必要がある |

### 将来の拡張: 案B（専用 fluent API）

TTL と独立にタイムアウトを決めたい要求が出た場合、`PayloadBuilder` に手を入れて
「明示指定 > 導出」の優先順で解決するようにします。
`build()` には `ClockAwareEvent` が渡っているため、後方互換に追加できます。

その際は以下を併せて検討します。

- `dispatch=local` で無視される設定になる点をどう扱うか（警告するか、ドキュメントに留めるか）
- `timeoutSeconds >= lockTtl` となる指定を弾く検証をどこに置くか
