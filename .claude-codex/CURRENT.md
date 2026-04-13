# 現在の作業状態

> 最終更新: 2026-04-13

---

## 直近で完了したこと
初回実装完了（18コミット）

- プロジェクトスキャフォールディング（git init, .htaccess）
- JSONデータ層（data.php, logger.php）
- ユーザー/設定ローダー（user.php, config.php）
- タイマーAPI（api/timer.php） — start/pause/complete/update_meta/get_state/set_break/notify_80min/notify_100min
- Hookシステム（api/hook.php） — GET/POST cURL dispatch
- メインUI（index.php + timer.css + timer.js） — 6コマグリッド、進捗バー、超過表示
- 統計ページ（stats.php） — 日/週/月/プロジェクト別タブ
- マークダウン出力API（api/output.php） — コピー＆ダウンロード
- 設定ページ（settings.php） — Hook URL/method/enable＋テスト送信
- ヘルプページ（help.php）
- 埋め込みページ（embed.php）

## 次にやること
ユーザーの動作確認を待つ。バグや要望があれば対応。

## 注意事項・既知の仕様
- break_notify hookはコマ完了時に即発火（「10分後に叩く」遅延はサーバー側で未実装 — hookのAPIが遅延対応する想定）
- project_id履歴はdatalist補完のみ（サーバーサイドfetch補完なし）
- 日本語エンコード: JSON_UNESCAPED_UNICODE で全ファイルに統一
