# FuelPHP PHP 8.3互換対応セット

[English](../README.md)

`bratech/fuelphp-php83-compat` は、FuelPHP 1.8.2と指定の依存ライブラリに互換パッチを適用するパッケージです。PHP製CLIがインストール済みバージョン、参照コミット、対象ファイルのSHA-256を検査してから適用・逆適用します。互換対応セットのバージョンは、FuelPHP本体とは独立して管理します。

**互換対応セットの版：1.0.1。** Gitタグ`v1.0.1`をComposerのバージョン指定`1.0.1`で取得します。この版は互換対応セットのもので、FuelPHP本体の版は1.8.2のままです。


1.0.1ではORM、Request／Presenter、View／Smarty、SMTPの障害時の後始末を修正しました。**1.0.0適用済みの場合は、パッケージを更新する前に1.0.0のツールでreverseしてください。** [更新手順](OPERATIONS.md#upgrading-from-100-to-101)と、残るProfiler・最終エラーハンドラーの問題を含む[検証範囲](VERIFICATION.md#release-101-cleanup-verification)を確認してください。

## 必須条件

PHP要件は `^7.4 || ~8.3.0` です。PHP 7.4系と8.3系を対象にしており、8.0〜8.2系や8.4以降は対応範囲に含めていません。Composer 2と、CLIから実行できるGitが必要です。利用側にPythonは不要です。

ICUとPHP拡張 `ext-intl` は必須です。アプリケーションを実行するWeb・CLIの両方で有効にしてください。ツールが検査できるのは自身を実行しているCLI環境だけで、ICUなしの代替処理はありません。

既存のComposerプロジェクトに導入します。アプリケーション側のPHP要件と `config.platform.php` も確認してください。本パッケージは、アプリケーションや上流依存のPHP要件を自動で変更しません。

## 対応パッケージ

| パッケージ | バージョン | 処理 |
| --- | --- | --- |
| `fuel/core` | 1.8.2 | 必須。23ファイルに適用 |
| `fuel/email` | 1.8.2 | 導入済みなら1ファイルに適用 |
| `fuel/oil` | 1.8.2 | 導入済みなら3ファイルに適用 |
| `fuel/orm` | 1.8.2 | 導入済みなら1ファイルに適用 |
| `fuel/parser` | 1.8.2 | 導入済みなら2ファイルに適用 |
| `fuelphp/upload` | 2.0.6 | 導入済みなら2ファイルに適用 |
| `fuelphp/upload` | 2.0.10 | 公式ソースを検査。追加パッチは不要 |
| `michelf/php-markdown` | 1.7.0 | 導入済みなら2ファイルに適用 |

7パッケージ・8バージョンの組合せを扱います。正確な参照コミットと対象ファイルは [manifest.json](../manifest.json) に記録しています。同じパッケージの未対応バージョンや、未知のファイル改変を検出した場合は、対象を書き換える前に停止します。未導入の任意パッケージはJSON出力で `not_installed` と示し、追加しません。表にない依存の互換性は判定しません。

Upload 2.0.10の `upstream` は、検査したUploadソースに追加パッチが不要であることを示します。FuelPHP全体が未適用で動くという意味ではありません。

## 導入手順

まず [挙動差](BEHAVIOR_CHANGES.md) を確認し、コード、依存のロック、永続データをバックアップしてください。適用中はWebリクエスト、キュー、定期処理など、対象コードを使う処理を停止します。

次の項目をアプリケーションのルート `composer.json` に統合します。既存のrequire、repositories、scriptsは保持してください。例は標準の `vendor` とバイナリ配置を前提にしているため、独自設定がある場合は呼出しパスを合わせます。

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/bratech/fuelphp-php83-compat"
    }
  ],
  "require": {
    "bratech/fuelphp-php83-compat": "1.0.1",
    "ext-intl": "*"
  },
  "scripts": {
    "post-install-cmd": [
      "@php vendor/bin/fuelphp-compat apply",
      "@php vendor/bin/fuelphp-compat check",
      "@php oil r install"
    ],
    "post-update-cmd": [
      "@php vendor/bin/fuelphp-compat apply",
      "@php vendor/bin/fuelphp-compat check"
    ]
  }
}
```

`oil r install` は、既存のインストール処理がある場合の配置例です。必要な既存処理だけを残し、その前に `apply` と `check` を置いてください。更新時にもOilを実行する必要がなければ、`post-update-cmd` に追加しません。ほかにもFuelを読み込むスクリプトがあれば、適用前に実行されないか確認します。インストール完了より前のイベントは、post-installの設定では保護できません。

未改変の対応依存へ初めて導入する場合は、開発用コピーで次を実行します。

```sh
composer update bratech/fuelphp-php83-compat --with-dependencies --no-scripts
php vendor/bin/fuelphp-compat status
php vendor/bin/fuelphp-compat apply
php vendor/bin/fuelphp-compat check
composer check-platform-reqs
```

`check` が成功してから、必要な既存のOil処理とアプリケーションの回帰テストを実行します。確認した `composer.json` と `composer.lock` を保存し、以後の構築ではそのロックから `composer install` します。古いパッチが適用済みの場合は、先に下記の更新手順が必要です。

scriptsを有効にした構築では、ルートに設定したフックが実行されます。`--no-scripts` を使う構築では、ビルド処理から `apply`、`check` を明示的に実行してください。パッケージのインストールだけでは依存ソースにパッチは適用されません。`check` の失敗時はデプロイを停止します。

## コマンドと状態

アプリケーションのルートで実行するか、`--root=path` を指定します。読取り用の `status` と `check` は `--json` に対応します。

```sh
php vendor/bin/fuelphp-compat status --root=.
php vendor/bin/fuelphp-compat check --root=. --json
php vendor/bin/fuelphp-compat apply --root=.
php vendor/bin/fuelphp-compat reverse --root=.
```

`status` は未適用の既知ソースも正常な検査結果として扱います。`check` は、導入済みの対象がすべて `patched` または `upstream` の場合に成功します。未導入の任意パッケージは失敗にしません。終了コードは成功が0、検査・処理の失敗が1、コマンド・引数の不備が2です。

適用済みの `apply` と未適用の `reverse` はファイルを変更しません。ツールは毎回ソースのハッシュを調べます。パッケージ単位で適用済みと未適用が混在する場合は、未適用のパッケージだけを処理できます。同一パッケージ内の部分適用は拒否します。

配置はComposer 2のメタデータから取得します。プロジェクト外の実体やシンボリックリンクを含む対象への書込みは行いません。全対象の事前検査と作業コピーでの差分検査を通過してから、対象ファイルを置換します。同時操作はロックで拒否しますが、複数ファイルの置換がOS上で一括して完了する保証はありません。途中で失敗した場合は復元を試み、復元にも失敗した場合はバックアップとエラー情報を残します。詳細は [運用・復旧手順](OPERATIONS.md) を参照してください。

## 互換対応セット・依存の更新

1. アプリケーションの実行を停止し、現状を退避します。古い互換対応セットを残した状態で、そのツールの `status --json` を確認します。
2. 古いツールで `reverse` を実行し、同じツールの `status --json` で元のハッシュに戻ったことを確認します。パッチ対象は `unpatched`、Upload 2.0.10は導入済みなら `upstream` になります。この状態で `check` が失敗するのは、適用済みでないためです。
3. その後に互換対応セットや依存を更新します。Composerのscriptsを無効にし、途中の未適用コードを実行しないでください。
4. 新しいツールで `status`、`apply`、`check` を実行します。platform要件とアプリケーションのテストを確認してから処理を再開します。

コメントだけが違う古いパッチでも、対象ソースのハッシュは異なります。新しいパッチを直接重ねず、古いツールでの逆適用と確認を先に行ってください。独自改変を検出した場合はハッシュ検査を回避せず、変更内容を確認して別途移植します。

## 保存済みモデルと逆適用

`serialize($model)` の保存形式はC形式からO形式に変わります。適用済みPHP 7.4／8.3は両方を読めますが、未適用PHP 7.4は新O形式を復元できません。`reverse` はDB・セッション・キャッシュを変換しません。

未適用コードへ戻す場合、モデルを含むセッションは対象を失効させ、再生成できるキャッシュは対象キーを失効させて再生成します。永続保存したモデルや再生成できないキャッシュは、旧コードで読める適用前バックアップからの復旧が必要です。ログアウト、入力中の状態の消失、適用後の更新を失う範囲を確認してください。共有ディレクトリやDB全体を一括削除しません。

適用前バックアップがなければ、適用済みPHP環境を保持し、アプリケーションが扱える形式への書出しと復旧を個別に計画します。独自Serializableやマジックメソッド、復元後の再保存も利用側で確認が必要です。[保存形式ごとの実測と復旧](OPERATIONS.md#model_crud-data-when-removing-patches) を参照してください。

## 検証範囲と監査

元のパッチはPHP 7.4.33／8.3.24、ICU 67.1で検証しています。DBはMySQLi＋MariaDB 10.11.19、PDO MySQL＋同MariaDB、PDO SQLite 3.34.1の構成です。Oracle MySQLや他のPDO接続先、DBセッション、Windows、独自アプリの全機能を確認した結果ではありません。新しい配布CLIの検証とGitHub経由の取得は、元パッチの検証結果とは分けて扱います。[検証範囲と対象外](VERIFICATION.md) を参照してください。

2026-10-21の固定依存の監査では、phpseclib 2.0.55にmedium通知1件が残り、終了コードは1でした。検証したFuelの暗号処理は通知対象の実装を呼んでいませんが、phpseclib全体や独自の暗号処理の安全性を保証するものではありません。通知を抑制せず、利用側のロックで `composer audit` を実行して確認してください。[監査対象と判断の範囲](VERIFICATION.md#dependency-audit) に根拠を記載しています。

## ライセンス

新規ツールと資料は [MIT](../LICENSE) です。差分に含まれるFuelPHP・Upload由来のコードはMIT、PHP Markdown由来のコードはBSD-3-Clauseの条件と著作権表示を保持します。[NOTICE](../NOTICE) と [LICENSES](../LICENSES) を参照してください。
