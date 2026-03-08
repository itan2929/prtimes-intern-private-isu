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

| 状態 | Score |
|-----|------|
| Baseline | 0 |
| make_posts N+1解消 | 4908 |
| `/` `/posts` クエリ最適化 | 5312 |
| DBインデックス追加 | 12598 |
| commentsインデックス最適化 | 15627 |

### 補足
- GET /, POST /login, POST /register で timeout が発生
- まず login/register の外部コマンド実行を削減する

## 改善内容

PRごとに記録します。

### 1. password digest 計算の修正
- 外部コマンド呼び出しを削減

### 2. make_posts の N+1 クエリ解消
- コメント取得
- ユーザー取得
をまとめて取得するよう変更

### 3. `/` `/posts` クエリ最適化
- `users.del_flg = 0` を SQL 側に移動
- `LIMIT POSTS_PER_PAGE` を追加

### 4. MySQL インデックス追加
`sql/indexes.sql` を追加

初期検証では以下のインデックスを追加し、スコアは **12598** まで向上した。

- posts(created_at)
- posts(user_id)
- comments(post_id, created_at)

### 5. インデックス構成の再検証
slow query log を確認したところ、投稿表示時のコメント取得が
主なボトルネックとなっていることが分かった。

インデックス構成を再検証した結果、
`comments(post_id, created_at)` のみを追加した構成でも
より高いスコアが得られることを確認した。

この構成でのスコアは **15627** となった。
