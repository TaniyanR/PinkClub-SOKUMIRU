# PinkClub-SOKUMIRU

SOKUMIRUアフィリエイトの商品を紹介する、PHP + MySQL/MariaDB製のサイトです。
画面構成、管理、SEO、アクセス解析、RSS、相互リンク等はPinkClub-FANZAをベースにし、API部分をSOKUMIRU WEBサービスへ置き換えています。

## 対応API

- 商品検索API: `https://sokmil-ad.com/api/v1/Item`
- 出演者検索API: `https://sokmil-ad.com/api/v1/Actor`
- カテゴリはアダルト動画（`av`）固定
- グラビア（`idol`）は取得しません

ジャンル、メーカー、シリーズ、レーベル、監督は商品レスポンスの`iteminfo`から自動登録します。SOKUMIRUに存在しないFANZAフロアAPI・作者API等は使用しません。
女優名とIDも商品レスポンスの`iteminfo.actor`から登録し、出演者検索APIによる画像・プロフィールの補完は商品取得と同じ`auto_import.php` cronの内部ジョブで自動実行します。管理画面に女優API専用の手動取得画面は設けません。

## セットアップ

1. PHP 8.1以降、MySQL 8.0またはMariaDB、cURL・PDO MySQL・mbstringを用意します。
2. `config.local.php`にDB接続情報を設定します。
3. `php scripts/init_db.php`を実行するか、初回画面からDBを初期化します。
4. 管理画面の「商品情報API設定」でSOKUMIRUのAPI KEYとアフィリエイトIDを保存します。
5. テスト取得後、自動設定を有効にします。

### 本番配置

1. リポジトリをサーバーへ配置し、公開ディレクトリはこのリポジトリのルートにします。Apacheでは`mod_rewrite`と`.htaccess`の上書きを有効にしてください。
2. 本番用の`config.local.php`をサーバー上で作成します。このファイルはGitへ追加しません。
3. `storage/cache`、`storage/locks`、`logs`、`public/uploads`をPHP実行ユーザーが読み書きできる状態にします。通常はディレクトリ`0755`または`0775`、秘密情報を含む`config.local.php`は`0600`を基準に、サーバーの所有者・グループ構成に合わせてください。
4. `php scripts/init_db.php`を一度実行し、スキーマとマイグレーションを適用します。
5. HTTPSで管理画面へログインし、API設定のテスト取得後に自動更新を有効にします。

Webサーバーから`config`、`lib`、`sql`、`storage`、`logs`へ直接アクセスさせないでください。付属の`.htaccess`はこれらを拒否しますが、Apache以外では同等の拒否設定が必要です。

### cron

10分ごとの実行例です。PHP CLIのパスと設置先は実サーバーに合わせて変更してください。

```cron
*/10 * * * * /usr/bin/php /path/to/PinkClub-SOKUMIRU/scripts/auto_import.php >> /path/to/PinkClub-SOKUMIRU/logs/cron.log 2>&1
```

`auto_import.php`は多重起動をロックし、商品自動更新、RSS更新、表示用キャッシュ更新、既存ログ・キャッシュの保守処理を実行します。HTTPからcronを呼び出す構成にはしないでください。

### 更新手順

1. 保守画面またはアクセスの少ない時間帯を選びます。
2. Gitの変更を取得します。
3. `php scripts/init_db.php`を実行して未適用マイグレーションを反映します。
4. 書き込みディレクトリと`config.local.php`の所有者・権限が維持されていることを確認します。
5. 下記の確認項目を実施します。

### デプロイ後の確認

- `/`、`/items.php`、商品詳細、女優・ジャンル・メーカー・シリーズ一覧、固定ページ、検索、404が表示される
- 管理画面のログイン・ログアウト、設定保存、APIテスト取得が動作する
- `robots.txt`と`sitemap.php`がHTTPSの正規URLを返す
- cronを手動で一度実行して終了コードと`logs/cron.log`を確認する
- ブラウザのConsole、Network、モバイル幅で重大エラー・Mixed Content・横スクロールがないことを確認する
- 外部広告やRSSが停止していても本文とフッターが先に表示されることを確認する

API認証情報はリポジトリへ保存しないでください。環境変数を利用する場合は、`SOKUMIRU_API_KEY`と`SOKUMIRU_AFFILIATE_ID`を指定できます。

APIリクエストには管理画面「サイト設定」のURLをRefererとして送信します。cronでURLを自動判定できない環境では、登録済みサイトURLを`SOKUMIRU_REFERER`環境変数へ設定してください。リクエストはプロセス間で1秒以上の間隔を空け、HTTPSかつ`sokmil-ad.com`配下への転送だけを許可します。

## APIデータの対応

| SOKUMIRU | 保存先 |
|---|---|
| `id` | 商品ID・重複判定キー |
| `title` | 商品名 |
| `URL` / `affiliateURL` | 商品URL / アフィリエイトURL |
| `imageURL` | 商品画像 |
| `sampleImageURL` | サンプル画像 |
| `sampleMovieURL.url` | サンプル動画 |
| `prices` | 価格 |
| `date` | 配信開始日 |
| `iteminfo.actor` | 女優・出演者 |
| `iteminfo.genre` | ジャンル |
| `iteminfo.maker` | メーカー |
| `iteminfo.series` | シリーズ |
| `iteminfo.label` | レーベル |
| `iteminfo.director` | 監督 |

## クレジット

公開ページのフッターにSOKUMIRU指定の`WEB SERVICE BY SOKMIL`クレジットを表示します。

## セキュリティ

- API KEYとアフィリエイトIDはAPIログでマスクします。
- APIリクエストはHTTPS、タイムアウト、HTTPステータス、JSONステータスを検証します。
- 商品リンクの中継先は`*.sokmil.com`だけを許可します。
- 管理画面POSTはCSRF検証を行います。
