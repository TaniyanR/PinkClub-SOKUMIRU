# PinkClub-SOKUMIRU

SOKUMIRUアフィリエイトの商品を紹介する、PHP + MySQL/MariaDB製のサイトです。
画面構成、管理、SEO、アクセス解析、RSS、相互リンク等はPinkClub-FANZAをベースにし、API部分をSOKUMIRU WEBサービスへ置き換えています。

## 対応API

- 商品検索API: `https://sokmil-ad.com/api/v1/Item`
- 出演者検索API: `https://sokmil-ad.com/api/v1/Actor`
- カテゴリはアダルト動画（`av`）固定
- 出演者は女性（`f`）固定
- グラビア（`idol`）は取得しません

ジャンル、メーカー、シリーズ、レーベル、監督は商品レスポンスの`iteminfo`から自動登録します。SOKUMIRUに存在しないFANZAフロアAPI・作者API等は使用しません。

## セットアップ

1. PHP 8.1以降、MySQL 8.0またはMariaDB、cURL・PDO MySQL・mbstringを用意します。
2. `config.local.php`にDB接続情報を設定します。
3. `php scripts/init_db.php`を実行するか、初回画面からDBを初期化します。
4. 管理画面の「商品情報API設定」でSOKUMIRUのAPI KEYとアフィリエイトIDを保存します。
5. テスト取得後、自動設定を有効にします。

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
