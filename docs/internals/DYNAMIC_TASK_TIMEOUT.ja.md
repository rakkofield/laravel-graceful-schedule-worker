# Laravel Graceful Schedule Worker - Task タイムアウトの動的化 設計ドキュメント

> **Note**: Step Functions の実装詳細は [STEPFUNCTIONS_IMPLEMENTATION.md](./STEPFUNCTIONS_IMPLEMENTATION.md)、
> アーキテクチャ設計は [ARCHITECTURE.md](./ARCHITECTURE.md) を参照

| 項目 | 内容 |
|---|---|
| ステータス | ライブラリ側は実装済み（`timeoutSeconds` を常に payload に載せる）。ステートマシンを `TimeoutSecondsPath` に切り替えるのは利用者側の作業（[移行手順](#移行手順)参照） |
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

`PayloadBuilder::build()`

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

**本設計の実装前**、`Payload::toJson()` が出力するキーは以下の 6 つで、
タイムアウトに相当するフィールドはありませんでした。

```
command / mutexName / dueAt / lockKey / expiresAt / dispatchedAt
```

現在は `timeoutSeconds` が加わって 7 キーです。

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

> **実装後の追記**: 当初この表は案A を優位に評価していましたが、実装を経て 3 セルが
> 事実と違うことが判明しました。**下の表は訂正後の内容です**（元の評価は
> [当初の評価がどう外れたか](#当初の評価がどう外れたか)に記録）。
> 結論として案A と案B は排他ではなく、**両方を実装しました**（案A が既定、案B が明示指定）。

| | 案A: ロック TTL から導出 | 案B: 専用 fluent API | 案C: config マップ |
|---|:--:|:--:|:--:|
| アプリ側の記述 | 不要（`withoutOverlapping` を流用） | `->timeoutAfter(1800)` | なし（config に列挙） |
| TTL と独立に決められる | ✗ | 短くする方向のみ ✓ | ✓ |
| 新 API 表面 | config 2 キー + 値オブジェクト | `ClockAwareEvent` に 1 メソッド | config スキーマ |
| スケジュール定義との近さ | ◎ | ◎ | ✗ |
| 危険な破れ方（ロック生存中の追い越し）を防げるか | 構造的に防ぐ | 定義時 + 導出時の検証で防ぐ | 利用者任せ |
| 既存の記述の意味を変えるか | **変える**（`withoutOverlapping` に第二の意味） | 変えない | 変えない |
| `dispatch=local` で無視される設定が生えるか | **生える**（既存設定なので気付きにくい） | 生える（新設定なので気付ける） | 生える |

### 案C を採らない理由

マッチングルール（完全一致 / 前方一致 / ワイルドカード）を作り込む必要がある割に、
スケジュール定義と設定が離れて不整合を起こしやすいためです。

### 案A を既定にする理由

既存のスケジュール定義に手を入れずに、全ジョブがロック寿命と整合したタイムアウトを得られるためです。
利用者が何も書かなくても、最長ジョブに合わせた静的値の共有という当初の問題は解消します。

### 当初の評価がどう外れたか

当初この表は次の 3 点で案A を優位としていました。実装後の実態は以下のとおりです。

| 当初の評価 | 実態 |
|---|---|
| 新 API 表面: **なし** | `lock_release_buffer` / `min_task_timeout` の 2 キーと `StepFunctionsTimeoutSettings` が生えた |
| TTL > timeout: **構造的に保証** | 局面1 のみ。局面2 は意図的に破る（[局面2](#局面2-ロックが実行を収容できないときは追従をやめる)） |
| 案B の却下理由: 「TTL との大小関係を利用者が壊せるため、結局は案A 相当の制約を検証ロジックとして後付けすることになる」 | **その後付けは案A でも必要になった。** 検証ルール 5 つ、下限フォールバック、移行時の全件監査 |

案B の却下理由 1（「`dispatch=local` のときに無視される設定が生える」）についても、
案A は同じ問題を抱えており、**性質はより悪い**と評価を改めます。
案B は新しい設定が片方のモードで無視されるだけですが、案A は既に使われている
`withoutOverlapping($minutes)` に第二の意味を後付けするため、
**利用者が何も変えていないのに意味が変わります**（[移行手順の監査項目](#手順-3-スケジュール定義の監査必須)が必要になった理由）。

残った差は「破れ方の危険度」です。案B の破れ（`timeoutAfter` > ロック寿命）は
**ロックが生きている状態での追い越し**＝二重起動そのものですが、
案A の局面2 の破れはロックが既に失効した後にしか起きません。
この差を保つため、案B の実装では `timeoutAfter()` が**短くする方向にしか効かない**ようにしています。

---

## 採用案: ロック TTL からの導出（+ 明示指定）

**`expiresAt` と `dispatchedAt` からタイムアウト値を導出し、payload に常に含める。
`timeoutAfter($seconds)` による明示指定があればそちらを優先する（ただし短くする方向のみ）。**

### 根拠

[背景と課題](#背景と課題)で述べたとおり、タイムアウトとロック TTL は独立に決められる値ではありません。
本案はこの依存関係を**導出式の構造として固定**します。

結果として、アプリ作者が考える値は 1 つだけになります。

> このジョブは最悪どれくらいかかるか

それを `withoutOverlapping($minutes)` に書けば、ECS 側のタイムアウトが自動的に追従します。

```php
$schedule->command('batch:heavy')->hourly()->withoutOverlapping(730); // 43800秒
```

### 明示指定（案B）

`ClockAwareEvent::timeoutAfter($seconds)` で、そのジョブの持ち時間を直接宣言できます。

```php
$schedule->command('batch:heavy')->hourly()
    ->withoutOverlapping(730)   // ロック寿命 43800 秒
    ->timeoutAfter(43200)       // このジョブは最大 12 時間
    ->dispatchVia('stepfunctions');
```

導出との関係は次のとおりです。

| | 挙動 |
|---|---|
| 未指定 | 導出値（残りロック寿命 − `lock_release_buffer`、局面2 ならフォールバック） |
| 指定あり・ロック寿命に収まる | 宣言値をそのまま使う |
| 指定あり・残りロック寿命を超える | **残りロック寿命側で打ち切る**（局面1）。宣言値は短くする方向にしか効かない |
| 指定あり・ロックが既に失効（局面2） | 宣言値をそのまま使う（打ち切る相手が存在しない。`min_task_timeout` も適用しない） |

`withoutOverlapping($minutes)` と矛盾する指定（`timeoutAfter` >= ロック寿命）は、
**スケジュール定義時に例外で弾きます**。`timeoutAfter()` と `withoutOverlapping()` の
どちらを先に書いても検出されます（後者は検証のためにオーバーライドしています）。

`withoutOverlapping` が無いイベントでは、上限は config の `lock_ttl` なので定義時には判定できません。
この場合はディスパッチ時に打ち切ります（例外は投げません。1 イベントの記述ミスで
ワーカー全体が停止するのを避けるため）。

**`dispatch=local` では無視されます。** ローカル実行にタイムアウト機構が無いためで、
案B の既知の弱点（[検討した案](#検討した案)参照）です。

---

## 導出式

```php
$expiresAt = $dueAt->getTimestamp() + $lockTtl;
$usable    = $expiresAt - $dispatchedAt->getTimestamp() - $lockReleaseBuffer;

$timeoutSeconds = $usable >= $minTaskTimeout
    ? $usable                                              // 局面1: ロックがこの実行を収容できる
    : max($minTaskTimeout, $lockTtl - $lockReleaseBuffer);  // 局面2: 収容できない
```

導出は `StepFunctionsTimeoutSettings::deriveTimeoutSeconds()` が持ちます。
`PayloadBuilder::build()` は `$dueAt` と `$dispatchedAt` の両方を既に受け取っているため、追加の入力は不要です。

### 局面1: なぜ「残りロック寿命」を基準にするか

単純な `$lockTtl - $lockReleaseBuffer` ではなく `$expiresAt - $dispatchedAt - $lockReleaseBuffer` とする理由は、
**ディスパッチ遅延を吸収するため**です。

`expiresAt` の起点は `dueAt`（スケジュール上の予定時刻）であり、実際のディスパッチはそれより後になります。
`$lockTtl - $lockReleaseBuffer` だと、この遅延分だけタスクがロックより長生きしうるのに対し、
残りロック寿命を基準にすれば、遅れて起動した分だけタイムアウトが縮みます。
これで**ディスパッチ遅延に起因する追い越しは起こりません**（ただし追い越しが一切起こらないわけではない。
[バッファが負担するもの](#バッファが負担するもの)を参照）。

### 局面2: ロックが実行を収容できないときは追従をやめる

残りロック寿命が `min_task_timeout` を下回る局面では、**何を返してもロックはこの実行を排他できません**。
ここで下限 1 秒に潰すと、守るものが無いままタスクを殺すだけになります。該当するのは主に 2 つです。

1. **リカバリ起動** — `DefaultScheduleOrchestrator::recoverMissedEvent()` は `dueAt` に過去のミス実行時刻、
   `dispatchedAt` に復旧時のウォールクロックを渡します。`expiresAt` は既に過去なので、
   追従を続けると**リカバリ起動が毎回即タイムアウトで死にます**
2. **`withoutOverlapping($minutes)` の窓がバッファと同程度** — `withoutOverlapping(1)` は寿命 60 秒で、
   既定バッファ 60 秒を引くと 0 になります

この局面では、イベント自身が宣言した寿命（`$lockTtl - $lockReleaseBuffer`）にフォールバックし、
`min_task_timeout` を下回らせません。

**このフォールバックは `expiresAt` を越えるタイムアウトを返しえます。** 意図的なトレードオフです。
ロックはすでに並行実行を排除できておらず、1 秒で殺されたタスクはディスパッチされた仕事を何ひとつ行えません。

### パラメータ

| 項目 | 既定値 | 備考 |
|---|---|---|
| `stepfunctions.lock_release_buffer` | 60 秒 | タイムアウトとロック失効の間に残す間隔。ロック解放をロック保持中に走らせるための余裕であり、ジョブに与える余裕ではない（導出値は残りロック寿命から**引いた**値になる） |
| `stepfunctions.min_task_timeout` | 60 秒 | 導出値がこれを下回ったら局面2 に切り替える下限 |

名前が重要な箇所です。`lock_release_buffer` はジョブの想定所要時間に足す余裕ではありません。
ロック寿命が固定なら、タイムアウトの上限もそこで固定されるため、この値にできるのは引くことだけです。
足す形にするには「このジョブは何分かかる想定か」という別の入力が必要で、それは案B（[将来の拡張](#将来の拡張-案b専用-fluent-api)）になります。

### バッファが負担するもの

`TimeoutSeconds` は **Task ステート入室時刻からの相対値**です。導出式が吸収するのは StartExecution までの遅延で、
それ以降は `lock_release_buffer` だけが余裕になります。この 60 秒が負担するのは次の 3 つです。

1. StartExecution → `AcquireLock` → `RunEcsTask` 入室までのレイテンシ（`AcquireLock` に `Retry` を置くなら指数バックオフ分も）
2. `States.Timeout` 後、`.sync` 統合が StopTask を投げてから**コンテナが実際に消えるまで**（ECS の `stopTimeout` は既定 30 秒・最大 120 秒）
3. ロック解放パス（`Catch` → ロック削除）の実行時間

守るべきは「Task ステートがタイムアウトすること」ではなく「コンテナが `expiresAt` より前に消えていること」なので、
2 は無視できません。**本ライブラリの存在意義が SIGTERM 後の graceful shutdown であることを踏まえると、
`stopTimeout` を長く設定している場合は `lock_release_buffer` も併せて広げてください。**

### 検証の範囲

`StepFunctionsTimeoutSettings` は 4 つのルールを持ちます
（`lock_ttl < 1` / `lock_release_buffer < 1` / `min_task_timeout < 1` / `lock_ttl <= lock_release_buffer` /
`min_task_timeout > lock_ttl - lock_release_buffer` を拒否）。

**この検証が見るのは config 由来の値だけです。** イベントが `withoutOverlapping($minutes)` で宣言した寿命は
`ClockAwareEvent::resolveLockTtlSeconds()` から直接来るため、この型の検証は通りません（導出そのものは通ります）。
イベント単位で例外を投げる案は取れません。`DefaultScheduleOrchestrator` は
「`dispatchEvent` の例外は意図的に伝播させる」設計であり、1 イベントの記述ミスでワーカー全体が停止するためです。
イベント単位の値は `min_task_timeout` によるフォールバックで「即死しない」ところまでを担保し、
それ以上は[移行手順](#移行手順)の監査項目とドキュメントで扱います。

---

## 変更範囲

| ファイル | 内容 | 後方互換 |
|---|---|---|
| `src/Dispatcher/StepFunctions/Payload.php` | 第 7 引数 `int $timeoutSeconds`（**必須**）+ getter + `toJson()` にキー追加 | 破壊的変更（[後述](#timeoutseconds-を必須引数にする)） |
| `src/Dispatcher/StepFunctions/PayloadBuilder.php` | settings の受け取りと導出の委譲 | コンストラクタ第 2 引数は省略可（既定値で組み立てる） |
| `src/Dispatcher/StepFunctions/StepFunctionsTimeoutSettings.php` | 新規。3 つの設定を検証し、導出そのものを持つ | 新規ファイル |
| `src/Scheduling/ClockAwareEvent.php` | `timeoutAfter()` 追加。`withoutOverlapping()` は矛盾検出のためオーバーライド | メソッド追加のみ |
| `src/Providers/StepFunctionsServiceProvider.php` | config から settings を 1 度だけ組み立て、`PayloadBuilder` と入力ファクトリの双方がそこから読む。秒設定は非数値を拒否 | — |
| `config/graceful-scheduler.php` の `stepfunctions` | `lock_release_buffer` と `min_task_timeout` を追加 | — |

### テストの追随

| ファイル | 内容 |
|---|---|
| `PayloadTest`（PY.4 / PY.5） | `$expectedKeys` にキーを追加。値の独立性と `>= 1` 検証 |
| `StepFunctionsDispatcherTest`（SFD.4） | キー集合アサーションを追随 |
| `PayloadBuilderTest`（PB.14〜24） | 導出ケース（遅延の有無、下限境界、局面2 のフォールバック、リカバリ形、`withoutOverlapping(1)`） |
| `StepFunctionsTimeoutSettingsTest`（STS.1〜22、新規） | 検証ルールと導出そのもの |
| `StepFunctionsServiceProviderTest`（SFP.13〜22） | config 読み出しと配線（どちらの設定がどちらの消費者に届くか） |
| `ProviderBootIntegrationTest`（GPI.8） | 出荷 config の既定値と定数の一致 |

### 既存利用者への影響

**破壊的変更は 3 つあります。**

| 変更 | 誰が影響を受けるか |
|---|---|
| `Payload` 第 7 引数の必須化 | `Payload` を直接 `new` しているカスタム `PayloadBuilder`（[後述](#timeoutseconds-を必須引数にする)） |
| `lock_ttl <= lock_release_buffer` を起動時に拒否 | `SCHEDULE_SF_LOCK_TTL` を 60 以下にしている利用者。従来は無害（`withoutOverlapping` の無いイベントは `dueAt` 込みのロックキーなので TTL が効かない）だったが、ワーカーが起動しなくなる。`CompositeDispatcher` は `state_machine_arn` が非空なら Step Functions dispatcher を解決するので、`dispatch=local` 運用でも発火する |
| `withoutOverlapping($minutes)` が ECS のタイムアウトを決めるようになる | ステートマシンを `TimeoutSecondsPath` に切り替えた利用者全員。[移行手順](#移行手順)の監査項目を必ず実施すること |

payload 自体はキーが 1 つ増えるだけで、
`TimeoutSecondsPath` を書いていないステートマシンはこのフィールドを無視するため無害で、
Step Functions の入力サイズ上限（256KB）に対しても無視できる増加量です。

### timeoutSeconds を必須引数にする

`Payload` の第 7 引数は**必須の `int`** とし、`?int = null` にはしません。

省略可にすれば既存の直接生成を壊さずに済みますが、
**null のときキーを出力しない挙動と `TimeoutSecondsPath` の組み合わせは、本設計が消そうとしている失敗モードそのものです。**
解決できないパスは `States.Runtime` となり、これは非リトライアブルで必ず実行失敗になります。
「型としては null を許すが、実際には常に値が入っているので大丈夫」という状態を作らず、
**`Payload` が存在するなら `timeoutSeconds` が JSON に必ず載る**ことを型で保証します。
加えて、キーの存在だけでは足りないため `timeoutSeconds >= 1` も同時に検証します
（0 や負値は解決時に `States.Runtime` になるため、必須引数化の論拠がそのまま値の検証を要求します）。

#### 影響範囲

リポジトリ内で `new Payload(` を呼んでいるのは以下だけです。

| 箇所 | 対応 |
|---|---|
| `PayloadBuilder::build()` | 導出値を渡す |
| `tests/Unit/Dispatcher/StepFunctions/PayloadTest.php`（4 箇所） | 引数を追加 |

外部への影響は、`Payload` を直接 `new` しているカスタム `PayloadBuilder` がある場合に限られます。
`PayloadInterface` を実装した独自 Payload を返している場合は影響を受けませんが、
その場合は「`TimeoutSecondsPath` を使うならこのフィールドは必須」という要請が
ドキュメント上の約束として残ります（型では強制できないため）。

#### バッファ引数を省略可にする理由との違い

`PayloadBuilder` のコンストラクタに追加する第 2 引数（`?StepFunctionsTimeoutSettings`）は省略可とします。
未指定なら既定値で組み立てるため妥当な値に落ち、`timeoutSeconds` のように
「未設定が実行時失敗に直結する」性質を持たないからです。

なお、ここを素の `int $lockReleaseBuffer` にはしません。負値を渡せてしまい、
タイムアウトが残りロック寿命を**超える**（＝タスクがロックを追い越す）状態を型が許すことになるためです。
検証済みの値オブジェクトを受け取れば、その状態は構築できません。

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
3. **スケジュール定義を監査する**（下記）
4. **その後でステートマシンを更新する** — `TimeoutSeconds` を `TimeoutSecondsPath` に差し替え、
   トップレベルの `TimeoutSeconds` を削除する

### 手順 3: スケジュール定義の監査（必須）

切り替えると `withoutOverlapping($minutes)` の意味が変わります。
Laravel でのこの引数は「mutex が腐ったと見なすまでの猶予」で、小さめに書いてもほぼ無害でした。
切り替え後は**そのジョブの kill 期限（-`lock_release_buffer`）**になります。
harm の方向が逆なので、**値を小さめに書いていた人ほど強く影響を受けます**。

| 記述 | 切り替え後のタイムアウト |
|---|---|
| `withoutOverlapping(1)` | 60 秒（`min_task_timeout` によるフォールバック） |
| `withoutOverlapping(5)` | 240 秒 |
| `withoutOverlapping(10)` | 540 秒 |
| `withoutOverlapping()`（既定 1440 分） | 86340 秒 |
| 指定なし | `lock_ttl - lock_release_buffer`（既定 3540 秒） |

監査すべきこと。

- スケジュール内の**全 `withoutOverlapping($minutes)`** を洗い出し、`$minutes * 60 - lock_release_buffer` が
  そのジョブの最悪所要時間を上回っているか確認する。危険域は「通常所要時間の少し上」— まさに人がこの数字を選ぶ基準
- `withoutOverlapping` を持たない最長のジョブが `lock_ttl - lock_release_buffer` に収まるか確認する。
  収まらないなら `lock_ttl` を上げる（3 時間のバッチは既定 3600 では殺されます）
- 通常所要時間に対して余裕が薄いジョブは、**負荷が高い日だけ落ちる非決定的な失敗**になる。
  移行との因果が最も追いにくい形なので、ここは保守的に広げておくこと

「ステートマシンを先に変えて後からアプリを直す」という順序は取れません。

---

## テスト戦略

`TimeoutSecondsPath` が実行時に効くかは AWS の責務であり、
**ライブラリの責務は「payload に正しい値が載ること」**です。
この切り分けに沿って、CI で守る範囲を次のとおりとします。

| レイヤ | 手段 | 検証内容 |
|---|---|---|
| 単体 | `StepFunctionsTimeoutSettingsTest` / `PayloadBuilderTest` | 導出式（遅延の有無、下限境界、局面2 のフォールバック）、`toJson()` のキー集合 |
| 単体 | `StepFunctionsServiceProviderTest` | config のどの値がどの消費者に届くか（配線の取り違えを検出する） |
| 統合 | moto の `CreateStateMachine` | `TimeoutSecondsPath` 入りの ASL が受理されること。実ディスパッチ入力の `timeoutSeconds` が期待値と一致すること |
| 実行時 | **実 AWS のみ** | 値が実際に反映されてタイムアウトすること |

実行時の検証をローカルで代替できない理由は次のとおりです。

- moto は Step Functions の ECS 統合を実行できない
  （`StartExecution` が HTTP 500。`moto/stepfunctions/parser/models.py:175` の `copy.deepcopy` が失敗する）
- MiniStack は `ecs:runTask.sync` を実 Docker で完走させられるが、
  **コンテナが非ゼロ終了しても Task を失敗させない**ため、タイムアウト／失敗系の検証には使えない

---

## 既存ドキュメントへの影響

[STEPFUNCTIONS_IMPLEMENTATION.ja.md](./STEPFUNCTIONS_IMPLEMENTATION.ja.md) の「タイムアウト設計」節は、
本設計に合わせて**更新済み**です（英語版も同様）。変更内容は次のとおりです。

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
| `lock_release_buffer` の既定値 | 60 秒。ディスパッチ遅延は導出式が吸収するが、Task 入室までのレイテンシと ECS `stopTimeout` はこの値が負担する（[バッファが負担するもの](#バッファが負担するもの)） |
| `min_task_timeout` の既定値 | 60 秒。局面2 の下限。短すぎると即死を防げず、長すぎると局面1 の追従が早く打ち切られる |
| 実 AWS での動作確認 | `TimeoutSecondsPath` の実行時挙動を確認する手段と担当を決める必要がある |

### 実装済み: 案B（専用 fluent API）

当初は将来の拡張としていましたが、案A が案B の想定コスト（検証ロジックの後付け）を
結局払ったため、案B の固有利点だけが未実現という状態になり、実装しました。
詳細は[明示指定（案B）](#明示指定案b)。当時挙げた 2 つの検討事項の結論は次のとおりです。

- **`dispatch=local` で無視される点**: ドキュメント記載に留める。ローカル実行にタイムアウト機構が無く、
  `LocalDispatcher` に導入するのは本設計の対象外
- **`timeoutSeconds >= lockTtl` を弾く検証の置き場所**: 定義時（`ClockAwareEvent`、
  `withoutOverlapping` が既知の場合）とディスパッチ時の打ち切り（`StepFunctionsTimeoutSettings`）の 2 段
