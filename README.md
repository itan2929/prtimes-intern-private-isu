# PR TIMES インターン課題

PR TIMES エンジニアインターンの技術課題として
private-isu を用いたパフォーマンス改善を行うリポジトリです。

## 環境

- Docker / Docker Compose
- PHP
- MySQL
- Memcached

## 手順

1. アプリケーションを起動
2. ベンチマークを実行
3. ボトルネックを調査
4. 改善をPull Request単位で実装

## ベンチマーク

※ 当初ベンチマークは他のアプリケーションが起動した状態で取ってしまっていたところを、
改めて Ubuntu + Docker のみを起動した状態で実行し、
スコアのばらつきを考慮するため各状態について3回測定を行った。

| 状態 | Score(最高) |
|-----|------|
| Baseline | 6440 |
| make_posts N+1解消 | 6473 |
| DBインデックス追加 | 17482 |
| commentsインデックス最適化 | 19338 |
| `/@account_name` 最適化 | 19373 |

### 補足
- GET /, POST /login, POST /register で timeout が発生
- まず login/register の外部コマンド実行を削減する

## 改善内容

PRごとに記録する。
改善は slow query log とクエリ実行計画を確認しながら段階的に行った。

### 1. password digest 計算の修正 (perf/replace-shell-digest)

login/register 処理において password digest の計算に外部コマンドが使用されていた。
外部プロセスの起動はオーバーヘッドが大きいため、アプリケーション内で計算するよう修正した。

- 外部コマンド呼び出しを削減

---

### 2. make_posts の N+1 クエリ解消 (perf/replace-shell-digest)

投稿一覧生成処理 `make_posts` において、コメント数およびユーザー情報を
投稿ごとに取得する N+1 クエリが発生していた。

そのため以下のデータ取得をまとめて取得するよう変更した。

- コメント取得
- ユーザー取得

これによりデータベースアクセス回数を削減した。

---

### 3. `/` `/posts` クエリ最適化 (perf/add-db-indexes)

投稿一覧取得時にアプリケーション側でフィルタリングを行っていたのを削減するため、
SQL 側で処理するよう変更した。

- `users.del_flg = 0` を SQL 条件に追加
- `LIMIT POSTS_PER_PAGE` を追加

これにより不要なデータ取得を削減した。

---

### 4. MySQL インデックス追加 (perf/add-db-indexes)

クエリ実行計画および slow query log を確認した結果、
投稿取得およびコメント取得クエリにインデックスが存在していなかった。

そのため `sql/indexes.sql` を追加し、以下のインデックスを作成した。

- posts(created_at)
- posts(user_id)
- comments(post_id, created_at)

---

### 5. インデックス構成の再検証 (perf/slow-query-analysis)

slow query log を再度確認したところ、
投稿表示時のコメント取得処理が主要なボトルネックであることが分かった。

そのためインデックス構成を再検証した結果、
`comments(post_id, created_at)` のみを追加した構成でも
より高いスコアが得られることを確認した。

posts テーブルは投稿処理による書き込みが多いため、
インデックス追加による INSERT コスト増加が
ベンチマークのスコア低下につながる可能性があると判断した。

---

### 6. `/@account_name` 投稿取得クエリの最適化 (perf/account-posts-limit)

ユーザーページ `/@account_name` において、
該当ユーザーの投稿を全件取得してからアプリケーション側で表示件数を制御していた。

この処理を以下のように変更した。

- `LIMIT POSTS_PER_PAGE` を追加

これにより不要な投稿取得を削減し、
データベース負荷の軽減を図った。

## 追加で試した最適化

コメント数取得の高速化のため、
memcached を利用した comment count のキャッシュも試した。

しかしベンチマーク環境ではスコアの改善が確認できなかったため、
今回の提出では採用しなかった。

考えられる理由としては以下がある。

- 既にコメント取得クエリがインデックス最適化されており高速である
- memcached アクセスのオーバーヘッドが相対的に大きい
- ベンチマークのワークロードではキャッシュヒットの効果が限定的

そのため今回は SQL 最適化とインデックス改善を中心に対応した。

---

## 蛇足

インターンには一度落ちたが、なんとなくやる気が湧いたので弄っていく。
提出段階でChatGPT Plusを契約しているにも関わらず、Codexを使わずにチャットで会話しコピペするような古い手法を取っていた。
今回はようやくCodexに手を出す気になったので、好奇心の赴くまま、Codexに行けるところまで行ってもらうことにした。

### 7. PHPコード整理と動作確認 (codex/php-short-tags)

ここからは性能改善そのものではなく、
PHP実装の可読性や保守性を損なっていた細かなノイズを整理した。

具体的には以下のような修正を行った。

- view の短縮PHPタグを通常の `<?php ... ?>` に統一
- 未使用 import / 未使用変数 / 未使用設定の削除
- 冗長な if/else や一時変数の整理
- `==` で問題ないが意図が明確な箇所を `===` に統一
- 配列の空判定を `count(...) == 0` から `empty(...)` に整理

また整理の途中で、
`Psr\\Http\\Message\\ResponseInterface` の import を消したことにより
Slim の view 描画で Fatal Error が発生し、
ベンチマークが以下のようなエラーで失敗する状態になった。

- CSRFトークンが取得できません
- ユーザー名が表示されていません
- ログインエラーメッセージが表示されていません

原因を調査したところ、
匿名クラスの `render()` メソッドの戻り値型解決に
`use Psr\\Http\\Message\\ResponseInterface;` が必要だった。

そのため import を復旧し、
Docker Compose で app コンテナを再ビルド後に `initialize` と benchmarker を再実行した。

```sh
docker compose up -d --build app nginx
curl http://127.0.0.1:8080/initialize
cd ../benchmarker
./bin/benchmarker -t "http://127.0.0.1:8080" -u ./userdata
```

この時点で benchmarker は再び `pass: true` となり、
スコアは `12017` を確認した。

ただしこの整理ブランチの目的は主にコード整備であり、
性能向上そのものはほとんど期待していない。
今後スコアをさらに伸ばすには、
画像配信や投稿処理、コメント取得など
I/O コストの大きい処理を中心に改善する必要がある。

### 8. 画像レスポンスのローカルディスクキャッシュ (perf/filesystem-images)

`/image/{id}.{ext}` では、
リクエストのたびに `posts.imgdata` を DB から読み出して画像を返していた。

この処理を以下のように変更した。

- 画像キャッシュ保存先として `/tmp/isuconp-image` を利用
- `POST /` のアップロード時に DB 保存と同時にキャッシュファイルも保存
- `GET /image/{id}.{ext}` では、まずローカルキャッシュを参照
- キャッシュがなければ DB から 1 回だけ読み出してキャッシュを生成
- `/initialize` では全件 export を行わず、キャッシュ削除のみに変更
- アップロード時の `file_get_contents()` 二重読みを解消

最初は nginx から直接配信できるよう
共有 `public/` 配下へ画像を書き出す方式も試したが、
Docker Compose 構成との整合や初期化時の全件 export コストが悪く、
今回は PHP コンテナ内で完結するローカルキャッシュ方式に切り替えた。

確認は以下の手順で行った。

```sh
php -l php/index.php
docker compose up -d --build app nginx
curl http://127.0.0.1:8080/initialize
docker compose exec -T app sh -lc 'ls -ld /tmp/isuconp-image && find /tmp/isuconp-image -maxdepth 1 -type f | head'
cd ../benchmarker
./bin/benchmarker -t "http://127.0.0.1:8080" -u ./userdata
```

この変更により benchmarker は `pass: true` を維持しつつ、
スコアは `12500` から `12931` へ改善した。

### 9. 一覧表示のコメント取得を最新3件に制限 (perf/limit-post-comments)

投稿一覧系では、
各投稿についてコメントを全件取得した後に
PHP 側で `array_slice(..., 0, 3)` を使って表示件数を削っていた。

この処理を以下のように変更した。

- 一覧表示 (`all_comments = false`) の場合のみ、
  MySQL 8 の `ROW_NUMBER()` を使って各投稿ごとの最新3件だけを取得
- 単票表示 (`/posts/{id}`) では従来どおり全件取得を維持
- これにより PHP 側の `array_slice(..., 0, 3)` を不要化

もともとコメント数は別クエリで集計しているため、
一覧で必要なのは「表示用の最新3件」だけである。
そのため SQL 側で最初から絞るほうが無駄が少ない。

確認は以下の手順で行った。

```sh
php -l php/index.php
docker compose up -d --build app nginx
curl http://127.0.0.1:8080/initialize
cd ../benchmarker
./bin/benchmarker -t "http://127.0.0.1:8080" -u ./userdata
```

この変更により benchmarker は `pass: true` を維持しつつ、
スコアは `12931` から `13412` へ改善した。

### 10. 単票表示 `/posts/{id}` の取得処理を専用化 (perf/single-post-path)

単票表示 `/posts/{id}` では、
投稿 1 件だけを表示するにもかかわらず
一覧用の `make_posts()` を経由してデータを組み立てていた。

この処理を以下のように変更した。

- 投稿本体を `posts` と `users` の JOIN で直接取得
- コメント数を専用クエリで取得
- コメント一覧を `comments` と `users` の JOIN で直接取得
- 一覧用の `make_posts()` を通さず、単票表示用にその場で `post` 構造を組み立てる

一覧表示と単票表示では必要なデータ量と組み立て方が異なるため、
単票表示だけ専用経路に分けることで
余計な汎用処理を減らした。

確認は以下の手順で行った。

```sh
php -l php/index.php
docker compose up -d --build app nginx
curl http://127.0.0.1:8080/initialize
cd ../benchmarker
./bin/benchmarker -t "http://127.0.0.1:8080" -u ./userdata
```

この変更により benchmarker は `pass: true` を維持しつつ、
スコアは `13412` から `14361` へ改善した。

### 11. ユーザーページ集計の簡略化と画像キャッシュ保存の安定化 (perf/user-page-path)

ユーザーページ `/@account_name` では、
投稿一覧自体は `LIMIT` 付きで取得している一方で、
投稿数や被コメント数の集計のために
全投稿 ID を一度取り出してから PHP 側で組み立てる処理が残っていた。

また、画像キャッシュ保存は単純な `file_put_contents()` だったため、
同時アクセス時に書き込み途中のファイルを読まれるリスクがあった。

この処理を以下のように変更した。

- `post_count` を `COUNT(*) FROM posts WHERE user_id = ?` に変更
- `commented_count` を `comments JOIN posts` の集計クエリに変更
- `comment_count` も明示的に整数化
- 画像キャッシュ保存を一時ファイル経由の atomic rename に変更

これにより、
ユーザーページ集計時の全投稿 ID 取得と `IN (...)` 構築を削減しつつ、
画像キャッシュの整合性も改善した。

確認は以下の手順で行った。

```sh
php -l php/index.php
docker compose up -d --build app nginx
curl http://127.0.0.1:8080/initialize
cd ../benchmarker
./bin/benchmarker -t "http://127.0.0.1:8080" -u ./userdata
```

この変更では benchmarker を 3 回実施して比較し、
最高スコアは `14530` を確認した。

### 12. user lookup の memcached キャッシュ (perf/user-lookup-cache)

セッションユーザー取得やログイン判定、
`/@account_name` でのユーザー解決では、
同じユーザー情報を何度も DB から読み直していた。

そこで、ユーザー情報だけを小さく memcached に載せるようにした。

この処理を以下のように変更した。

- `get_session_user()` を user id ベースの memcached キャッシュ経由に変更
- `try_login()` と `@account_name` のユーザー解決を account_name ベースの memcached キャッシュ経由に変更
- `initialize` では cache namespace を切り替えるように変更
- `register` と `admin/banned` では該当ユーザーの cache を invalidate するように変更

以前、コメント情報まで memcached に載せる案は試したが、
warming コストと get/set のオーバーヘッドが勝ってしまい悪化した。
今回は対象を user lookup だけに絞ることで、
低リスクな範囲で再挑戦した。

確認は以下の手順で行った。

```sh
php -l php/index.php
docker compose up -d --build app nginx
curl http://127.0.0.1:8080/initialize
cd ../benchmarker
./bin/benchmarker -t "http://127.0.0.1:8080" -u ./userdata
```

この変更では benchmarker を 3 回実施して比較し、
最高スコアは `14010` を確認した。

### 13. タイムラインの投稿ユーザー再利用 (perf/lean-post-users)

`make_posts()` では投稿一覧を組み立てるたびに、
post owner を `users` テーブルへ取り直していた。

しかし `/` と `/posts` では、
一覧取得時点で `users` と JOIN しており、
owner 情報の一部はすでに結果セットに含まれていた。
また `/@account_name` では、
ページ対象のユーザー情報をすでに取得済みだった。

この処理を以下のように変更した。

- `/` と `/posts` の投稿取得で必要な user 情報を一緒に取得
- `make_posts()` では JOIN 済みの owner 情報を優先的に再利用
- `/@account_name` では既知の profile user を各 post にそのまま適用
- post owner / comment user の一括取得は `SELECT *` ではなく必要列だけに限定

これにより、
タイムライン組み立て時の無駄な user 再取得を減らしつつ、
`users` から読む payload も少し削減した。

確認は以下の手順で行った。

```sh
php -l php/index.php
docker compose up -d --build app nginx
curl http://127.0.0.1:8080/initialize
cd ../benchmarker
./bin/benchmarker -t "http://127.0.0.1:8080" -u ./userdata
```

この変更では benchmarker を 3 回実施して比較し、
最高スコアは `14440` を確認した。

### 14. PHP セッションの lazy start (perf/lazy-php-session)

これまではアプリケーション起動時に `session_start()` を行っていたため、
ログインしていないユーザーの `GET /image` や `GET /posts` でも
毎回 session を開いていた。

この処理を以下のように変更した。

- グローバルな `session_start()` を廃止
- session が必要なときだけ `ensure_session_started()` で開始するよう変更
- session cookie を持たない guest の `get_session_user()` では session を開かずに `null` を返すよう変更
- `GET /image` と `GET /posts` は guest アクセス時に session を開かないまま応答するよう変更
- flash を使う `GET /` や login/register のメッセージ処理では従来どおり session を開始

これにより、
公開 GET リクエストで不要な memcached session I/O と `Set-Cookie` 発行を減らした。

確認は以下の手順で行った。

```sh
php -l php/index.php
docker compose up -d --build app nginx
curl http://127.0.0.1:8080/initialize
cd ../benchmarker
./bin/benchmarker -t "http://127.0.0.1:8080" -u ./userdata
```

この変更では benchmarker を 3 回実施して比較し、
最高スコアは `14837` を確認した。
