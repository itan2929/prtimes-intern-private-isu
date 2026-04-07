<?php
use Psr\Http\Message\ResponseInterface;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;
use DI\Container;

require 'vendor/autoload.php';

$_SERVER += ['PATH_INFO' => $_SERVER['REQUEST_URI']];
$_SERVER['SCRIPT_NAME'] = '/' . basename($_SERVER['SCRIPT_FILENAME']);
$file = dirname(__DIR__) . '/public' . $_SERVER['REQUEST_URI'];
if (is_file($file)) {
    if (PHP_SAPI === 'cli-server') return false;
    $mimetype = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'ico' => 'image/vnd.microsoft.icon',
    ][pathinfo($file, PATHINFO_EXTENSION)] ?? false;
    if ($mimetype) {
        header("Content-Type: {$mimetype}");
        echo file_get_contents($file); exit;
    }
}

const POSTS_PER_PAGE = 20;
const UPLOAD_LIMIT = 10 * 1024 * 1024;

// memcached session
$memd_addr = '127.0.0.1:11211';
if (isset($_SERVER['ISUCONP_MEMCACHED_ADDRESS'])) {
    $memd_addr = $_SERVER['ISUCONP_MEMCACHED_ADDRESS'];
}
ini_set('session.save_handler', 'memcached');
ini_set('session.save_path', $memd_addr);

function ensure_session_started(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function has_session_cookie(): bool {
    return isset($_COOKIE[session_name()]);
}

// dependency
$container = new Container();
$container->set('settings', function() {
    [$memcached_host, $memcached_port] = array_pad(explode(':', $GLOBALS['memd_addr'] ?? '127.0.0.1:11211', 2), 2, null);
    return [
        'image_cache_dir' => sys_get_temp_dir() . '/isuconp-image',
        'memcached' => [
            'host' => $memcached_host ?: '127.0.0.1',
            'port' => (int)($memcached_port ?: 11211),
        ],
        'db' => [
            'host' => $_SERVER['ISUCONP_DB_HOST'] ?? 'localhost',
            'port' => $_SERVER['ISUCONP_DB_PORT'] ?? 3306,
            'username' => $_SERVER['ISUCONP_DB_USER'] ?? 'root',
            'password' => $_SERVER['ISUCONP_DB_PASSWORD'] ?? null,
            'database' => $_SERVER['ISUCONP_DB_NAME'] ?? 'isuconp',
        ],
    ];
});
$container->set('db', function ($c) {
    $config = $c->get('settings');
    return new PDO(
        "mysql:dbname={$config['db']['database']};host={$config['db']['host']};port={$config['db']['port']};charset=utf8mb4",
        $config['db']['username'],
        $config['db']['password']
    );
});
$container->set('memcached', function ($c) {
    $config = $c->get('settings')['memcached'];
    $memcached = new Memcached();
    $memcached->addServer($config['host'], $config['port']);
    return $memcached;
});

$container->set('view', function ($c) {
    return new class(__DIR__ . '/views/') extends \Slim\Views\PhpRenderer {
        public function render(\Psr\Http\Message\ResponseInterface $response, string $template, array $data = []): ResponseInterface {
            $data += ['view' => $template];
            return parent::render($response, 'layout.php', $data);
        }
    };
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages;
});

$container->set('helper', function ($c) {
    return new class($c) {
        public PDO $db;
        public Memcached $memcached;
        public string $image_cache_dir;
        public int $cache_version;
        public int $user_cache_ttl = 3600;

        public function __construct($c) {
            $this->db = $c->get('db');
            $this->memcached = $c->get('memcached');
            $this->image_cache_dir = $c->get('settings')['image_cache_dir'];
            $this->cache_version = $this->load_cache_version();
        }

        public function db() {
            return $this->db;
        }

        public function db_initialize() {
            $db = $this->db();
            $sql = [];
            $sql[] = 'DELETE FROM users WHERE id > 1000';
            $sql[] = 'DELETE FROM posts WHERE id > 10000';
            $sql[] = 'DELETE FROM comments WHERE id > 100000';
            $sql[] = 'UPDATE users SET del_flg = 0';
            $sql[] = 'UPDATE users SET del_flg = 1 WHERE id % 50 = 0';
            foreach($sql as $s) {
                $db->query($s);
            }

            $this->clear_image_cache();
            $this->bump_cache_version();
        }

        public function load_cache_version(): int {
            $version = $this->memcached->get('isuconp:cache_version');
            if ($version === false) {
                $this->memcached->add('isuconp:cache_version', 1);
                return 1;
            }

            return (int)$version;
        }

        public function bump_cache_version(): void {
            $version = $this->memcached->increment('isuconp:cache_version', 1, 1);
            if ($version === false) {
                $this->cache_version = $this->load_cache_version();
                return;
            }

            $this->cache_version = (int)$version;
        }

        public function user_cache_key_by_id(int $id): string {
            return "isuconp:v{$this->cache_version}:user:id:{$id}";
        }

        public function user_cache_key_by_account_name(string $account_name): string {
            return "isuconp:v{$this->cache_version}:user:account_name:{$account_name}";
        }

        public function image_path(int $post_id, string $mime): string {
            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                default => '',
            };

            return $this->image_cache_dir . "/{$post_id}.{$ext}";
        }

        public function ensure_image_dir(): string {
            $dir = $this->image_cache_dir;
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            return $dir;
        }

        public function clear_image_cache() {
            $dir = $this->ensure_image_dir();
            foreach (glob($dir . '/*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        public function save_post_image(int $post_id, string $mime, string $imgdata) {
            $dir = $this->ensure_image_dir();
            $path = $this->image_path($post_id, $mime);
            $tmp = tempnam($dir, 'img-');
            if ($tmp === false) {
                return;
            }

            $written = file_put_contents($tmp, $imgdata);
            if ($written === false || $written !== strlen($imgdata)) {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
                return;
            }

            if (!rename($tmp, $path) && is_file($tmp)) {
                unlink($tmp);
            }
        }

        public function fetch_first($query, ...$params) {
            $db = $this->db();
            $ps = $db->prepare($query);
            $ps->execute($params);
            $result = $ps->fetch();
            $ps->closeCursor();
            return $result;
        }

        public function cache_user(array $user): void {
            $user = array_filter($user, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->memcached->setMulti([
                $this->user_cache_key_by_id((int)$user['id']) => $user,
                $this->user_cache_key_by_account_name($user['account_name']) => $user,
            ], $this->user_cache_ttl);
        }

        public function fetch_user_by_account_name(string $account_name) {
            $cached = $this->memcached->get($this->user_cache_key_by_account_name($account_name));
            if (is_array($cached)) {
                return $cached;
            }

            $user = $this->fetch_first('SELECT * FROM users WHERE account_name = ? AND del_flg = 0', $account_name);
            if ($user !== false) {
                $this->cache_user($user);
            }
            return $user;
        }

        public function fetch_user_by_id(int $id) {
            $cached = $this->memcached->get($this->user_cache_key_by_id($id));
            if (is_array($cached)) {
                return $cached;
            }

            $user = $this->fetch_first('SELECT * FROM `users` WHERE `id` = ? AND `del_flg` = 0', $id);
            if ($user !== false) {
                $this->cache_user($user);
            }
            return $user;
        }

        public function delete_user_cache(int $id, string $account_name): void {
            $this->memcached->deleteMulti([
                $this->user_cache_key_by_id($id),
                $this->user_cache_key_by_account_name($account_name),
            ]);
        }

        public function try_login($account_name, $password) {
            $user = $this->fetch_user_by_account_name($account_name);
            if ($user !== false && calculate_passhash($user['account_name'], $password) === $user['passhash']) {
                return $user;
            }
            return null;
        }

        public function get_session_user() {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                if (!has_session_cookie()) {
                    return null;
                }
                ensure_session_started();
            }

            if (!isset($_SESSION['user'], $_SESSION['user']['id'])) {
                return null;
            }

            $user = $this->fetch_user_by_id((int)$_SESSION['user']['id']);

            return $user === false ? null : $user;
        }

        public function make_posts(array $results, $options = []) {
            $options += ['all_comments' => false, 'default_post_user' => null];
            $all_comments = (bool)$options['all_comments'];
            $default_post_user = $options['default_post_user'];
            if ($default_post_user !== null) {
                $default_post_user = [
                    'id' => isset($default_post_user['id']) ? (int)$default_post_user['id'] : null,
                    'account_name' => $default_post_user['account_name'] ?? null,
                    'authority' => $default_post_user['authority'] ?? null,
                    'del_flg' => $default_post_user['del_flg'] ?? null,
                    'created_at' => $default_post_user['created_at'] ?? null,
                ];
            }

            if (empty($results)) {
                return [];
            }

            $db = $this->db();

            $postUsers = [];
            $postUserIds = [];
            foreach ($results as $post) {
                if ($default_post_user !== null) {
                    continue;
                }

                if (isset($post['user_account_name'])) {
                    $postUsers[(int)$post['user_id']] = [
                        'id' => (int)$post['user_id'],
                        'account_name' => $post['user_account_name'],
                        'authority' => $post['user_authority'],
                        'del_flg' => $post['user_del_flg'],
                        'created_at' => $post['user_created_at'],
                    ];
                    continue;
                }

                $postUserIds[] = (int)$post['user_id'];
            }

            $postUserIds = array_values(array_unique($postUserIds));
            if (!empty($postUserIds)) {
                $ph = implode(',', array_fill(0, count($postUserIds), '?'));
                $ps = $db->prepare("
                    SELECT `id`, `account_name`, `authority`, `del_flg`, `created_at`
                    FROM `users`
                    WHERE `id` IN ($ph)
                ");
                $ps->execute($postUserIds);
                foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $u) {
                    $postUsers[(int)$u['id']] = $u;
                }
                $ps->closeCursor();
            }

            // 2) 旧挙動同様、results順で del_flg=0 の投稿だけ採用し、POSTS_PER_PAGE で打ち切り
            $selectedPosts = [];
            foreach ($results as $post) {
                $owner = $default_post_user ?? ($postUsers[(int)$post['user_id']] ?? null);
                if ($owner !== null && (int)$owner['del_flg'] === 0) {
                    $post['user'] = $owner;
                    unset(
                        $post['user_account_name'],
                        $post['user_authority'],
                        $post['user_del_flg'],
                        $post['user_created_at']
                    );
                    $selectedPosts[] = $post;
                    if (count($selectedPosts) >= POSTS_PER_PAGE) {
                        break;
                    }
                }
            }
            if (empty($selectedPosts)) {
                return [];
            }

            $postIds = array_map(fn($p) => (int)$p['id'], $selectedPosts);
            $postPh = implode(',', array_fill(0, count($postIds), '?'));

            // 3) comment_count 一括取得
            $commentCounts = [];
            $ps = $db->prepare("
                SELECT `post_id`, COUNT(*) AS `count`
                FROM `comments`
                WHERE `post_id` IN ($postPh)
                GROUP BY `post_id`
            ");
            $ps->execute($postIds);
            foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $commentCounts[(int)$row['post_id']] = (int)$row['count'];
            }
            $ps->closeCursor();

            // 4) comments 一括取得（一覧は各投稿の最新3件だけ取得）
            $commentQuery = $all_comments
                ? "
                    SELECT * FROM `comments`
                    WHERE `post_id` IN ($postPh)
                    ORDER BY `post_id` ASC, `created_at` DESC, `id` DESC
                "
                : "
                    SELECT `id`, `post_id`, `user_id`, `comment`, `created_at`
                    FROM (
                        SELECT
                            `id`,
                            `post_id`,
                            `user_id`,
                            `comment`,
                            `created_at`,
                            ROW_NUMBER() OVER (
                                PARTITION BY `post_id`
                                ORDER BY `created_at` DESC, `id` DESC
                            ) AS `comment_rank`
                        FROM `comments`
                        WHERE `post_id` IN ($postPh)
                    ) AS ranked_comments
                    WHERE `comment_rank` <= 3
                    ORDER BY `post_id` ASC, `created_at` DESC, `id` DESC
                ";
            $ps = $db->prepare($commentQuery);
            $ps->execute($postIds);
            $fetchedComments = $ps->fetchAll(PDO::FETCH_ASSOC);
            $ps->closeCursor();

            // 5) comment user 一括取得
            $commentUserIds = array_values(array_unique(array_map(fn($c) => (int)$c['user_id'], $fetchedComments)));
            $commentUsers = [];
            if (count($commentUserIds) > 0) {
                $uph = implode(',', array_fill(0, count($commentUserIds), '?'));
                $ps = $db->prepare("
                    SELECT `id`, `account_name`, `authority`, `del_flg`, `created_at`
                    FROM `users`
                    WHERE `id` IN ($uph)
                ");
                $ps->execute($commentUserIds);
                foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $u) {
                    $commentUsers[(int)$u['id']] = $u;
                }
                $ps->closeCursor();
            }

            // 6) post_id ごとに comments を集約（DESC）
            $commentsDescByPost = [];
            foreach ($fetchedComments as $c) {
                $pid = (int)$c['post_id'];
                $c['user'] = $commentUsers[(int)$c['user_id']] ?? false; // 旧 fetch_first の false に寄せる
                $commentsDescByPost[$pid][] = $c;
            }

            // 7) 組み立て（all_comments=false は最新3件、最後に reverse で昇順表示）
            $posts = [];
            foreach ($selectedPosts as $post) {
                $pid = (int)$post['id'];
                $desc = $commentsDescByPost[$pid] ?? [];

                $post['comment_count'] = $commentCounts[$pid] ?? 0;
                $post['comments'] = array_reverse($desc);
                $posts[] = $post;
            }

            return $posts;
        }

    };
});

AppFactory::setContainer($container);
$app = AppFactory::create();

// ------- helper method for view

function escape_html($h) {
    return htmlspecialchars($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function redirect(Response $response, $location, $status) {
    return $response->withStatus($status)->withHeader('Location', $location);
}

function image_url($post) {
    $ext = '';
    if ($post['mime'] === 'image/jpeg') {
        $ext = '.jpg';
    } elseif ($post['mime'] === 'image/png') {
        $ext = '.png';
    } elseif ($post['mime'] === 'image/gif') {
        $ext = '.gif';
    }
    return "/image/{$post['id']}{$ext}";
}

function validate_user($account_name, $password) {
    return preg_match('/\A[0-9a-zA-Z_]{3,}\z/', $account_name)
        && preg_match('/\A[0-9a-zA-Z_]{6,}\z/', $password);
}

function digest($src) {
    return hash('sha512', $src);
}

function calculate_salt($account_name) {
    return digest($account_name);
}

function calculate_passhash($account_name, $password) {
    $salt = calculate_salt($account_name);
    return digest("{$password}:{$salt}");
}

// --------

$app->get('/initialize', function (Request $request, Response $response) {
    $this->get('helper')->db_initialize();
    return $response;
});

$app->get('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    ensure_session_started();
    return $this->get('view')->render($response, 'login.php', [
        'me' => null,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->post('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    $user = $this->get('helper')->try_login($params['account_name'], $params['password']);

    if ($user) {
        ensure_session_started();
        $_SESSION['user'] = [
            'id' => $user['id'],
        ];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        return redirect($response, '/', 302);
    }

    ensure_session_started();
    $this->get('flash')->addMessage('notice', 'アカウント名かパスワードが間違っています');
    return redirect($response, '/login', 302);
});

$app->get('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    ensure_session_started();
    return $this->get('view')->render($response, 'register.php', [
        'me' => null,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->post('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    $account_name = $params['account_name'];
    $password = $params['password'];

    if (!validate_user($account_name, $password)) {
        ensure_session_started();
        $this->get('flash')->addMessage('notice', 'アカウント名は3文字以上、パスワードは6文字以上である必要があります');
        return redirect($response, '/register', 302);
    }

    $user = $this->get('helper')->fetch_first('SELECT 1 FROM users WHERE `account_name` = ?', $account_name);
    if ($user) {
        ensure_session_started();
        $this->get('flash')->addMessage('notice', 'アカウント名がすでに使われています');
        return redirect($response, '/register', 302);
    }

    $db = $this->get('db');
    $ps = $db->prepare('INSERT INTO `users` (`account_name`, `passhash`) VALUES (?,?)');
    $ps->execute([
        $account_name,
        calculate_passhash($account_name, $password)
    ]);
    $user_id = (int)$db->lastInsertId();
    $this->get('helper')->delete_user_cache($user_id, $account_name);
    ensure_session_started();
    $_SESSION['user'] = [
        'id' => $user_id,
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    return redirect($response, '/', 302);
});

$app->get('/logout', function (Request $request, Response $response) {
    if (has_session_cookie()) {
        ensure_session_started();
        unset($_SESSION['user']);
        unset($_SESSION['csrf_token']);
    }
    return redirect($response, '/', 302);
});

$app->get('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();
    ensure_session_started();

    $db = $this->get('db');
    $ps = $db->prepare('
        SELECT p.id, p.user_id, p.body, p.mime, p.created_at,
               u.account_name AS user_account_name,
               u.authority AS user_authority,
               u.del_flg AS user_del_flg,
               u.created_at AS user_created_at
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        WHERE u.del_flg = 0
        ORDER BY p.created_at DESC
        LIMIT ?
    ');
    $ps->bindValue(1, POSTS_PER_PAGE, PDO::PARAM_INT);
    $ps->execute();
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    return $this->get('view')->render($response, 'index.php', [
        'posts' => $posts,
        'me' => $me,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->get('/posts', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $max_created_at = $params['max_created_at'] ?? null;
    $db = $this->get('db');
    $ps = $db->prepare('
        SELECT p.id, p.user_id, p.body, p.mime, p.created_at,
               u.account_name AS user_account_name,
               u.authority AS user_authority,
               u.del_flg AS user_del_flg,
               u.created_at AS user_created_at
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        WHERE p.created_at <= ? AND u.del_flg = 0
        ORDER BY p.created_at DESC
        LIMIT ?
    ');
    $ps->bindValue(1, $max_created_at); // 既存変数名に合わせる
    $ps->bindValue(2, POSTS_PER_PAGE, PDO::PARAM_INT);
    $ps->execute();
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    return $this->get('view')->render($response, 'posts.php', ['posts' => $posts]);
});

$app->get('/posts/{id}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $ps = $db->prepare('
        SELECT p.id, p.user_id, p.body, p.mime, p.created_at,
               u.account_name AS user_account_name,
               u.authority AS user_authority,
               u.del_flg AS user_del_flg,
               u.created_at AS user_created_at
        FROM `posts` p
        INNER JOIN `users` u ON u.id = p.user_id
        WHERE p.id = ? AND u.del_flg = 0
    ');
    $ps->execute([$args['id']]);
    $row = $ps->fetch(PDO::FETCH_ASSOC);
    $ps->closeCursor();

    if ($row === false) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $post = [
        'id' => $row['id'],
        'user_id' => $row['user_id'],
        'body' => $row['body'],
        'mime' => $row['mime'],
        'created_at' => $row['created_at'],
        'user' => [
            'id' => $row['user_id'],
            'account_name' => $row['user_account_name'],
            'authority' => $row['user_authority'],
            'del_flg' => $row['user_del_flg'],
            'created_at' => $row['user_created_at'],
        ],
    ];

    $ps = $db->prepare('SELECT COUNT(*) AS `count` FROM `comments` WHERE `post_id` = ?');
    $ps->execute([$args['id']]);
    $post['comment_count'] = (int)$ps->fetch(PDO::FETCH_ASSOC)['count'];
    $ps->closeCursor();

    $ps = $db->prepare('
        SELECT c.id, c.post_id, c.user_id, c.comment, c.created_at,
               u.account_name AS user_account_name,
               u.authority AS user_authority,
               u.del_flg AS user_del_flg,
               u.created_at AS user_created_at
        FROM `comments` c
        INNER JOIN `users` u ON u.id = c.user_id
        WHERE c.post_id = ?
        ORDER BY c.created_at ASC, c.id ASC
    ');
    $ps->execute([$args['id']]);
    $post['comments'] = array_map(function ($comment) {
        $comment['user'] = [
            'id' => $comment['user_id'],
            'account_name' => $comment['user_account_name'],
            'authority' => $comment['user_authority'],
            'del_flg' => $comment['user_del_flg'],
            'created_at' => $comment['user_created_at'],
        ];
        unset(
            $comment['user_account_name'],
            $comment['user_authority'],
            $comment['user_del_flg'],
            $comment['user_created_at']
        );
        return $comment;
    }, $ps->fetchAll(PDO::FETCH_ASSOC));
    $ps->closeCursor();

    $me = $this->get('helper')->get_session_user();
    ensure_session_started();

    return $this->get('view')->render($response, 'post.php', ['post' => $post, 'me' => $me]);
});

$app->post('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    if (!$_FILES['file']) {
        $this->get('flash')->addMessage('notice', '画像が必須です');
        return redirect($response, '/', 302);
    }

    $mime = '';
    // 投稿のContent-Typeからファイルのタイプを決定する
    if (strpos($_FILES['file']['type'], 'jpeg') !== false) {
        $mime = 'image/jpeg';
    } elseif (strpos($_FILES['file']['type'], 'png') !== false) {
        $mime = 'image/png';
    } elseif (strpos($_FILES['file']['type'], 'gif') !== false) {
        $mime = 'image/gif';
    } else {
        $this->get('flash')->addMessage('notice', '投稿できる画像形式はjpgとpngとgifだけです');
        return redirect($response, '/', 302);
    }

    $imgdata = file_get_contents($_FILES['file']['tmp_name']);
    if ($imgdata === false) {
        $this->get('flash')->addMessage('notice', '画像の読み込みに失敗しました');
        return redirect($response, '/', 302);
    }

    if (strlen($imgdata) > UPLOAD_LIMIT) {
        $this->get('flash')->addMessage('notice', 'ファイルサイズが大きすぎます');
        return redirect($response, '/', 302);
    }

    $db = $this->get('db');
    $query = 'INSERT INTO `posts` (`user_id`, `mime`, `imgdata`, `body`) VALUES (?,?,?,?)';
    $ps = $db->prepare($query);
    $ps->execute([
      $me['id'],
      $mime,
      $imgdata,
      $params['body'],
    ]);
    $pid = $db->lastInsertId();
    $this->get('helper')->save_post_image((int)$pid, $mime, $imgdata);
    return redirect($response, "/posts/{$pid}", 302);
});

$app->get('/image/{id}.{ext}', function (Request $request, Response $response, $args) {
    if ($args['id'] == 0) {
        return $response;
    }

    $mime = match ($args['ext']) {
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        default => null,
    };
    if ($mime === null) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $helper = $this->get('helper');
    $image_path = $helper->image_path((int)$args['id'], $mime);
    if (is_file($image_path)) {
        $response->getBody()->write(file_get_contents($image_path));
        return $response->withHeader('Content-Type', $mime);
    }

    $post = $helper->fetch_first('SELECT `mime`, `imgdata` FROM `posts` WHERE `id` = ?', $args['id']);
    if ($post !== false && $post['mime'] === $mime) {
        $helper->save_post_image((int)$args['id'], $mime, $post['imgdata']);
        $response->getBody()->write($post['imgdata']);
        return $response->withHeader('Content-Type', $mime);
    }
    $response->getBody()->write('404');
    return $response->withStatus(404);
});

$app->post('/comment', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    if (!preg_match('/\A[0-9]+\z/', $params['post_id'])) {
        $response->getBody()->write('post_idは整数のみです');
        return $response;
    }
    $post_id = $params['post_id'];

    $query = 'INSERT INTO `comments` (`post_id`, `user_id`, `comment`) VALUES (?,?,?)';
    $ps = $this->get('db')->prepare($query);
    $ps->execute([
        $post_id,
        $me['id'],
        $params['comment']
    ]);

    return redirect($response, "/posts/{$post_id}", 302);
});

$app->get('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/login', 302);
    }

    if ($me['authority'] == 0) {
        $response->getBody()->write('403');
        return $response->withStatus(403);
    }

    $db = $this->get('db');
    $ps = $db->prepare('SELECT * FROM `users` WHERE `authority` = 0 AND `del_flg` = 0 ORDER BY `created_at` DESC');
    $ps->execute();
    $users = $ps->fetchAll(PDO::FETCH_ASSOC);

    return $this->get('view')->render($response, 'banned.php', ['users' => $users, 'me' => $me]);
});

$app->post('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    if ($me['authority'] == 0) {
        $response->getBody()->write('403');
        return $response->withStatus(403);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    $db = $this->get('db');
    $ids = array_values(array_unique(array_map('intval', (array)($params['uid'] ?? []))));
    if (empty($ids)) {
        return redirect($response, '/admin/banned', 302);
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));

    $ps = $db->prepare('SELECT `id`, `account_name` FROM `users` WHERE `id` IN (' . $placeholders . ')');
    $ps->execute($ids);
    $users = $ps->fetchAll(PDO::FETCH_ASSOC);

    $ps = $db->prepare('UPDATE `users` SET `del_flg` = ? WHERE `id` IN (' . $placeholders . ')');
    $ps->execute(array_merge([1], $ids));

    foreach ($users as $user) {
        $this->get('helper')->delete_user_cache((int)$user['id'], $user['account_name']);
    }

    return redirect($response, '/admin/banned', 302);
});

$app->get('/@{account_name}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $user = $this->get('helper')->fetch_user_by_account_name($args['account_name']);

    if ($user === false) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `created_at`, `mime` FROM `posts` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT ' . POSTS_PER_PAGE);
    $ps->execute([$user['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results, ['default_post_user' => $user]);

    $comment_count = (int)$this->get('helper')->fetch_first(
        'SELECT COUNT(*) AS count FROM `comments` WHERE `user_id` = ?',
        $user['id']
    )['count'];
    $post_count = (int)$this->get('helper')->fetch_first(
        'SELECT COUNT(*) AS count FROM `posts` WHERE `user_id` = ?',
        $user['id']
    )['count'];
    $commented_count = (int)$this->get('helper')->fetch_first(
        'SELECT COUNT(*) AS count FROM `comments` c INNER JOIN `posts` p ON p.id = c.post_id WHERE p.user_id = ?',
        $user['id']
    )['count'];

    $me = $this->get('helper')->get_session_user();

    return $this->get('view')->render($response, 'user.php', ['posts' => $posts, 'user' => $user, 'post_count' => $post_count, 'comment_count' => $comment_count, 'commented_count'=> $commented_count, 'me' => $me]);
});

$app->run();
