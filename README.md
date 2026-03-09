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

### 補足
- GET /, POST /login, POST /register で timeout が発生
- まず login/register の外部コマンド実行を削減する

## 改善内容

PRごとに記録します。
