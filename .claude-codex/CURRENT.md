# 現在の作業状態

> 最終更新: 2026-06-21 — **v2.0.0 リリース**

---

## v2.0.0 でリリースした内容

| # | 種別 | 内容 |
|---|---|---|
| #F-002 | feat | 出力形式をObsidian用に変更（`**時間(分)**` 行追加、ヘッダーに規定時間固定表記） |
| #I-001 | feat | ライトテーマ実装（ヘッダーボタンで切り替え、config.json に保存） |
| #I-002 | fix  | 昨日のコマ完了時に `completed_at` が今日付になる → セグメント end 時刻を使用 |
| #I-003 | fix  | 昨日に未完コマがあると今日の同スロットが非表示になる → slot-hiding 条件を削除 |
| #I-004 | feat | 100分自動完了機能を廃止（hook 発火のみ残す） |
| #I-005 | feat | 完了コマを100分に丸めるボタンを追加 |
| #F-001 | feat | タイマー画面下部に履歴表示 + 空きコマへのコピーボタン |
| #I-006 | fix  | テーマ設定がリロードで元に戻る → `set_theme` を slot バリデーション前に移動 |

詳細は `.claude-codex/change/` 配下の各変更ログを参照。

---

## 次にやること

- Xserverデプロイ後にcronジョブを設定する（`/api/cron_check.php` を2〜5分ごと）— `#T-001`
- それ以外はユーザーの指示を待つ

---

## 注意事項・既知の仕様

### API
- `api/timer.php` の全アクションは `date` パラメータを受け付ける（省略時は今日）
  - 前日コマ操作時はJSが `date` を付けてリクエストする
- スロット上限は `SLOT_MAX = 20`（config.jsonのkoma_countとは別）
- `auto_close_old_komas()` は `get_state` 呼び出し時に毎回走る（2日以上前のみ対象、軽量）
- `set_theme` / `get_state` は slot バリデーション前に処理する（slot 不要なアクション）
- `reset` アクションは `segments` が空のコマ専用。開始済みコマには適用不可
- `round_to_100min` アクションは完了済み + 100分超過コマのみ対象

### データ
- コマのステータス全種: `idle` / `running` / `paused` / `overtime` / `completed` / `closed` / `auto_closed`
  - `overtime_max` は廃止（既存データの互換性のためステータス定義は残る）
- `closed`・`auto_closed` は統計・マークダウン出力でも「完了扱い」（チェックボックス `[x]`）
- `break_notify` hookはコマ完了時に即発火（「10分後に叩く」遅延はhook受け取り側で実装する想定）
- project_id履歴はdatalist補完のみ（サーバーサイドfetch補完なし）
- JSONエンコード: `JSON_UNESCAPED_UNICODE` で統一

### フロントエンド
- `prevKey(date, slot)` は `"YYYYMMDD s スロット番号"` 形式（例: `"20260418s7"`）— CSSセレクター用
- `CFG.komaCount` はページロード時に PHP 側の `$renderSlotCount` で初期化。動的追加のたびにJSで更新される
- 前日コマエリアはJSで動的生成（PHPは `prevIncomplete` 配列をCFGに渡すだけ）
- 「リセット」ボタン（`btn-reset-{slot}`）は `completed` + `segments.length === 0` のときのみ表示
- 「100分に丸める」ボタン（`btn-round-{slot}`）は `done && elapsed > maxDurationSec` のときのみ表示
- 履歴エリアは過去14日・最大40件の完了コマを表示。「コピー」で先頭の idle スロットに転記

### テーマ
- テーマ設定は `data/config.json` の `theme` フィールドに保存（`"dark"` / `"light"`）
- `api/timer.php` の `set_theme` アクションで保存（slot バリデーション前に配置）
- 全ページの `<html data-theme="...">` を PHP が config から出力

### インフラ
- git7.local: XAMPP virtualhost（ローカル開発）
- Xserver: 本番（GitHub Actions FTP デプロイ）
- `logs/error.log`: JSON Lines形式でエラー記録
