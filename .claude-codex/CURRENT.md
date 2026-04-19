# 現在の作業状態

> 最終更新: 2026-04-19

---

## 直近で完了したこと
（詳細: `.claude-codex/change/バグ修正と機能追加.md`）

- **[fix]** `get_current_user()` → `get_koma_user()` にリネーム（PHP組み込み関数と名前衝突でFatal error）
- **[feat]** `api/cron_check.php` — 100分超過コマのサーバー側自動完了スクリプト
- **[fix]** 前日コマのスロット混入バグ修正（前日コマが今日のスロットを上書きしていた）
- **[feat]** `closed`（手動中止）・`auto_closed`（2日以上前の自動中止）ステータス追加
- **[feat]** `get_state` に `prev_incomplete`（前日の未完了コマ一覧）を追加、`close` アクション追加
- **[feat]** メインページに「前日の未完了コマ」エリア — 再開/停止/完了/中止ボタン付きカード
- **[feat]** 動的コマ追加ボタン（7コマ以上対応、最大20コマ、セッション保存済みスロットはリロード後も復元）
- **[fix]** `prevKey` のCSSセレクター不正バグ修正（`:` → `s` 区切り）、前日コマカードが表示されない問題を解消

---

## 次にやること
- Xserverデプロイ後にcronジョブを設定する（`/api/cron_check.php` を2〜5分ごと）
- それ以外はユーザーの指示を待つ

---

## 注意事項・既知の仕様

### API
- `api/timer.php` の全アクションは `date` パラメータを受け付ける（省略時は今日）
  - 前日コマ操作時はJSが `date` を付けてリクエストする
- スロット上限は `SLOT_MAX = 20`（config.jsonのkoma_countとは別）
- `auto_close_old_komas()` は `get_state` 呼び出し時に毎回走る（2日以上前のみ対象、軽量）

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

### インフラ
- git7.local: XAMPP virtualhost（ローカル開発）
- Xserver: 本番（cronジョブ設定待ち）
- `logs/error.log`: JSON Lines形式でエラー記録
