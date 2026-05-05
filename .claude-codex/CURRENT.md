# 現在の作業状態

> 最終更新: 2026-04-29

---

## 直近で完了したこと
（詳細: `.claude-codex/change/完了ボタン制御とリセット機能.md`）

- **[fix]** idle 状態では完了ボタンを無効化（PHP + JS 両方で制御）
- **[feat]** `api/timer.php` に `reset` アクション追加 — segments 空の phantom completed koma を idle に戻す
- **[feat]** JS `renderKoma()` に「リセット」ボタン表示ロジック追加 — `completed` + `segments.length === 0` のときのみ表示
- **[fix]** 既存 phantom koma データを削除（04-28 id:3、04-25 id:2）

（詳細: `.claude-codex/change/スロットリセットバグ修正.md`）

- **[fix]** `index.php` の `$maxExistingSlot` 計算 — `idle` + `segments:[]` の phantom koma を除外

---

## 次にやること
- Xserverデプロイ後にcronジョブを設定する（`/api/cron_check.php` を2〜5分ごと）
- ライトテーマ実装（issue 残存）
- それ以外はユーザーの指示を待つ

---

## 注意事項・既知の仕様

### API
- `api/timer.php` の全アクションは `date` パラメータを受け付ける（省略時は今日）
  - 前日コマ操作時はJSが `date` を付けてリクエストする
- スロット上限は `SLOT_MAX = 20`（config.jsonのkoma_countとは別）
- `auto_close_old_komas()` は `get_state` 呼び出し時に毎回走る（2日以上前のみ対象、軽量）
- `update_meta` / `complete` / `close` / `set_break` はすべて `ensure_koma()` を呼ぶ
  → idle koma が JSON に作成されるのは仕様。表示カウントへの影響はなし（修正済み）
- `reset` アクションは `segments` が空のコマ専用。開始済みコマには適用不可

### データ
- コマのステータス全種: `idle` / `running` / `paused` / `overtime` / `overtime_max` / `completed` / `closed` / `auto_closed`
- `closed`・`auto_closed` は統計・マークダウン出力でも「完了扱い」（チェックボックス `[x]`）
- `break_notify` hookはコマ完了時に即発火（「10分後に叩く」遅延はhook受け取り側で実装する想定）
- project_id履歴はdatalist補完のみ（サーバーサイドfetch補完なし）
- JSONエンコード: `JSON_UNESCAPED_UNICODE` で統一

### フロントエンド
- `prevKey(date, slot)` は `"YYYYMMDD s スロット番号"` 形式（例: `"20260418s7"`）— CSSセレクター用
- `CFG.komaCount` はページロード時に PHP 側の `$renderSlotCount` で初期化。動的追加のたびにJSで更新される
- 前日コマエリアはJSで動的生成（PHPは `prevIncomplete` 配列をCFGに渡すだけ）
- 「リセット」ボタン（`btn-reset-{slot}`）は `completed` + `segments.length === 0` のときのみ表示。それ以外は `display:none`

### インフラ
- git7.local: XAMPP virtualhost（ローカル開発）
- Xserver: 本番（cronジョブ設定待ち）
- `logs/error.log`: JSON Lines形式でエラー記録
