# コマタイマー

## 目的
80分を1コマとした作業単位でタスクを計測・記録するWebタイマーツール。
1日6コマ（8時間）を管理し、統計・hook通知・マークダウン出力ができる。

## 設計・環境
- **PHP 8.2** / XAMPP (git7.local virtualhost)、本番: Xserver
- **SQL不使用** — JSONファイルで全データ管理
- **ユーザー**: motiハードコード（多ユーザー対応設計済み、実装は未）
- **iframe対応**: embed.php でヘッダーなし埋め込み可能
- **対象ユーザー**: もちツールズ使用者

## ディレクトリ構成
```
/
├── index.php         # メインタイマーページ（header付き）
├── embed.php         # 埋め込み用（EMBED_MODE=true）
├── stats.php         # 統計ページ（日/週/月/プロジェクト別）
├── settings.php      # Hook・基本設定ページ
├── help.php          # 使い方詳細
├── api/
│   ├── timer.php     # タイマー操作API（start/pause/complete/update_meta等）
│   ├── hook.php      # Hook発火処理（cURL dispatch）
│   └── output.php    # マークダウン出力API
├── includes/
│   ├── header.php / footer.php
│   ├── config.php    # 設定ローダー
│   ├── data.php      # JSON読み書き（ファイルロック付き）
│   ├── user.php      # ユーザーコンテキスト
│   └── logger.php    # エラーログ（logs/error.log）
├── data/
│   ├── config.json
│   ├── users/moti.json
│   └── sessions/YYYY/MM-DD/data.json
├── assets/css/timer.css
└── assets/js/timer.js
```

## Hook イベント
| event | タイミング |
|---|---|
| koma_start | コマ開始 |
| koma_complete | コマ完了（手動・100分自動） |
| koma_80min | 80分経過 |
| koma_100min | 100分経過（自動完了） |
| break_notify | 完了後10分（break_afterフラグがオンの場合） |

---

## ドキュメント管理ルール

### ファイルの役割分担

| ファイル | 役割 | 読むタイミング |
|---|---|---|
| `CLAUDE.md` | プロジェクトルール＋アクティブな todo/issue のみ | 毎チャット必読 |
| `.claude-codex/CURRENT.md` | 直近の完了内容・次にやること・注意事項 | 毎チャット必読 |
| `.claude-codex/change/*.md` | 完了した作業の詳細ログ | 必要なときだけ |

### todo のルール
- `[ ]` のみここに記載する
- `[x]` にしたら**その行を削除**する（記録は change/ の変更ログが担う）

### issue のルール
- バグ・不具合・改善点が見つかったら、**まずここに `[ ]` で登録してから対処**する
- 対処完了後は `[x]` にしてから**その行を削除**する

### 変更ログのルール
- 作業の変更内容は `.claude-codex/change/作業内容.md` に記録する
- 記録タイミング：todo/issue を消化したとき・変更に一区切りがついたとき

### セッション引き継ぎのルール
- チャット終了時に `.claude-codex/CURRENT.md` を最新状態に更新する
- 新チャット開始時は **CLAUDE.md → CURRENT.md** の順に読めば文脈が揃う状態を保つ

---

## issue
- [ ] ライトテーマ未実装 — 現在はダークテーマのみ。header にテーマ切り替えボタンを追加し、選択状態は data/config.json（または既存の config）に保存する

## todo
- [ ] Xserverデプロイ後にcronジョブを設定する（`/api/cron_check.php` を2〜5分ごとに実行）
