# CLAUDE.md — samurai-sample (music-psychology)

## プロジェクト概要

Local by Flywheel で動作する WordPress サイト。
テーマは Lightning + 子テーマ（lightning-child）を使用。

## ディレクトリ構成

```
samurai-sample/
├── app/
│   └── public/            # WordPress ルート
│       └── wp-content/
│           ├── themes/
│           │   ├── lightning/          # 親テーマ（編集禁止）
│           │   └── lightning-child/    # カスタマイズはここ
│           └── plugins/
│               ├── vk-all-in-one-expansion-unit/
│               ├── vk-block-patterns/
│               └── vk-blocks/
├── conf/                  # サーバー設定（nginx / php / mysql）
├── logs/                  # ログファイル
└── CLAUDE.md
```

## 開発環境

- ローカル環境: Local by Flywheel
- CMS: WordPress
- 親テーマ: Lightning（ExUnit）
- 子テーマ: lightning-child

## Git 運用ルール

**コードを変更するたびに必ず GitHub へプッシュすること。**

```bash
git add -p                          # 変更内容を確認しながらステージング
git commit -m "変更内容の説明"
git push origin main
```

- リモートリポジトリ: https://github.com/test-cloud-2026/music-psychology.git
- メインブランチ: `main`
- コミットメッセージは日本語でも英語でも可。変更内容が明確になるように書く。
- `wp-config.php`（DB 認証情報を含む）は**絶対にコミットしない**。
- WordPress コアファイル（`wp-admin/`、`wp-includes/`）は**コミットしない**。

## カスタマイズの主な作業場所

- テーマカスタマイズ: `app/public/wp-content/themes/lightning-child/`
- 関数追加: `app/public/wp-content/themes/lightning-child/functions.php`
- スタイル: `app/public/wp-content/themes/lightning-child/style.css`
