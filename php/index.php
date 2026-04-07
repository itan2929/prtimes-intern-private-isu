<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;
use DI\Container;

require 'vendor/autoload.php';

$_SERVER += ['PATH_INFO' => $_SERVER['REQUEST_URI']];
$_SERVER['SCRIPT_NAME'] = '/' . basename($_SERVER['SCRIPT_FILENAME']);
$file = dirname(__DIR__) . '/public' . $_SERVER['REQUEST_URI'];
if (is_file($file)) {
    if (PHP_SAPI == 'cli-server') return false;
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

session_start();

// dependency
$container = new Container();
$container->set('settings', function() {
    return [
        'public_folder' => dirname(dirname(__FILE__)) . '/public',
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

        public function __construct($c) {
            $this->db = $c->get('db');
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
        }

        public function fetch_first($query, ...$params) {
            $db = $this->db();
            $ps = $db->prepare($query);
            $ps->execute($params);
            $result = $ps->fetch();
            $ps->closeCursor();
            return $result;
        }

        public function try_login($account_name, $password) {
            $user = $this->fetch_first('SELECT * FROM users WHERE account_name = ? AND del_flg = 0', $account_name);
            if ($user !== false && calculate_passhash($user['account_name'], $password) == $user['passhash']) {
                return $user;
            } elseif ($user) {
                return null;
            } else {
                return null;
            }
        }

        public function get_session_user() {
            if (!isset($_SESSION['user'], $_SESSION['user']['id'])) {
                return null;
            }

            $user = $this->fetch_first('SELECT * FROM `users` WHERE `id` = ?', $_SESSION['user']['id']);

            return $user ?: null;
        }

        public function make_posts(array $results, $options = []) {
            $options += ['all_comments' => false];
            $all_comments = (bool)$options['all_comments'];

            if (count($results) === 0) {
                return [];
            }

            $db = $this->db();

            // 1) post owner を一括取得
            $postUserIds = array_values(array_unique(array_map(fn($p) => (int)$p['user_id'], $results)));
            $postUsers = [];
            if (count($postUserIds) > 0) {
                $ph = implode(',', array_fill(0, count($postUserIds), '?'));
                $ps = $db->prepare("SELECT * FROM `users` WHERE `id` IN ($ph)");
                $ps->execute($postUserIds);
                foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $u) {
                    $postUsers[(int)$u['id']] = $u;
                }
                $ps->closeCursor();
            }

            // 2) 旧挙動同様、results順で del_flg=0 の投稿だけ採用し、POSTS_PER_PAGE で打ち切り
            $selectedPosts = [];
            foreach ($results as $post) {
                $owner = $postUsers[(int)$post['user_id']] ?? null;
                if ($owner !== null && (int)$owner['del_flg'] === 0) {
                    $post['user'] = $owner;
                    $selectedPosts[] = $post;
                    if (count($selectedPosts) >= POSTS_PER_PAGE) {
                        break;
                    }
                }
            }
            if (count($selectedPosts) === 0) {
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

            // 4) comments 一括取得（DESCで取得して、最後に reverse で旧挙動へ）
            $ps = $db->prepare("
                SELECT * FROM `comments`
                WHERE `post_id` IN ($postPh)
                ORDER BY `post_id` ASC, `created_at` DESC
            ");
            $ps->execute($postIds);
            $fetchedComments = $ps->fetchAll(PDO::FETCH_ASSOC);
            $ps->closeCursor();

            // 5) comment user 一括取得
            $commentUserIds = array_values(array_unique(array_map(fn($c) => (int)$c['user_id'], $fetchedComments)));
            $commentUsers = [];
            if (count($commentUserIds) > 0) {
                $uph = implode(',', array_fill(0, count($commentUserIds), '?'));
                $ps = $db->prepare("SELECT * FROM `users` WHERE `id` IN ($uph)");
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
                if (!$all_comments) {
                    $desc = array_slice($desc, 0, 3);
                }

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
    } else if ($post['mime'] === 'image/png') {
        $ext = '.png';
    } else if ($post['mime'] === 'image/gif') {
        $ext = '.gif';
    }
    return "/image/{$post['id']}{$ext}";
}

function validate_user($account_name, $password) {
    if (!(preg_match('/\A[0-9a-zA-Z_]{3,}\z/', $account_name) && preg_match('/\A[0-9a-zA-Z_]{6,}\z/', $password))) {
        return false;
    }
    return true;
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
        $_SESSION['user'] = [
            'id' => $user['id'],
        ];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        return redirect($response, '/', 302);
    } else {
        $this->get('flash')->addMessage('notice', 'アカウント名かパスワードが間違っています');
        return redirect($response, '/login', 302);
    }
});

$app->get('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    return $this->get('view')->render($response, 'register.php', [
        'me' => null,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});


$app->post('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user()) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    $account_name = $params['account_name'];
    $password = $params['password'];

    $validated = validate_user($account_name, $password);
    if (!$validated) {
        $this->get('flash')->addMessage('notice', 'アカウント名は3文字以上、パスワードは6文字以上である必要があります');
        return redirect($response, '/register', 302);
    }

    $user = $this->get('helper')->fetch_first('SELECT 1 FROM users WHERE `account_name` = ?', $account_name);
    if ($user) {
        $this->get('flash')->addMessage('notice', 'アカウント名がすでに使われています');
        return redirect($response, '/register', 302);
    }

    $db = $this->get('db');
    $ps = $db->prepare('INSERT INTO `users` (`account_name`, `passhash`) VALUES (?,?)');
    $ps->execute([
        $account_name,
        calculate_passhash($account_name, $password)
    ]);
    $_SESSION['user'] = [
        'id' => $db->lastInsertId(),
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    return redirect($response, '/', 302);
});

$app->get('/logout', function (Request $request, Response $response) {
    unset($_SESSION['user']);
    unset($_SESSION['csrf_token']);
    return redirect($response, '/', 302);
});

$app->get('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    $db = $this->get('db');
    $ps = $db->prepare('
        SELECT p.id, p.user_id, p.body, p.mime, p.created_at
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        WHERE u.del_flg = 0
        ORDER BY p.created_at DESC
        LIMIT ?
    ');
    $ps->bindValue(1, POSTS_PER_PAGE, PDO::PARAM_INT);    $ps->execute();
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
        SELECT p.id, p.user_id, p.body, p.mime, p.created_at
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
    $ps = $db->prepare('SELECT * FROM `posts` WHERE `id` = ?');
    $ps->execute([$args['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results, ['all_comments' => true]);

    if (count($posts) == 0) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $post = $posts[0];

    $me = $this->get('helper')->get_session_user();

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

    if ($_FILES['file']) {
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

        if (strlen(file_get_contents($_FILES['file']['tmp_name'])) > UPLOAD_LIMIT) {
            $this->get('flash')->addMessage('notice', 'ファイルサイズが大きすぎます');
            return redirect($response, '/', 302);
        }

        $db = $this->get('db');
        $query = 'INSERT INTO `posts` (`user_id`, `mime`, `imgdata`, `body`) VALUES (?,?,?,?)';
        $ps = $db->prepare($query);
        $ps->execute([
          $me['id'],
          $mime,
          file_get_contents($_FILES['file']['tmp_name']),
          $params['body'],
        ]);
        $pid = $db->lastInsertId();
        return redirect($response, "/posts/{$pid}", 302);
    } else {
        $this->get('flash')->addMessage('notice', '画像が必須です');
        return redirect($response, '/', 302);
    }
});

$app->get('/image/{id}.{ext}', function (Request $request, Response $response, $args) {
    if ($args['id'] == 0) {
        return $response;
    }

    $post = $this->get('helper')->fetch_first('SELECT * FROM `posts` WHERE `id` = ?', $args['id']);

    if (($args['ext'] == 'jpg' && $post['mime'] == 'image/jpeg') ||
        ($args['ext'] == 'png' && $post['mime'] == 'image/png') ||
        ($args['ext'] == 'gif' && $post['mime'] == 'image/gif')) {
        $response->getBody()->write($post['imgdata']);
        return $response->withHeader('Content-Type', $post['mime']);
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
    $query = 'UPDATE `users` SET `del_flg` = ? WHERE `id` = ?';
    foreach ($params['uid'] as $id) {
        $ps = $db->prepare($query);
        $ps->execute([1, $id]);
    }

    return redirect($response, '/admin/banned', 302);
});

$app->get('/@{account_name}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $user = $this->get('helper')->fetch_first('SELECT * FROM `users` WHERE `account_name` = ? AND `del_flg` = 0', $args['account_name']);

    if ($user === false) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `created_at`, `mime` FROM `posts` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT ' . POSTS_PER_PAGE);
    $ps->execute([$user['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    $comment_count = $this->get('helper')->fetch_first('SELECT COUNT(*) AS count FROM `comments` WHERE `user_id` = ?', $user['id'])['count'];

    $ps = $db->prepare('SELECT `id` FROM `posts` WHERE `user_id` = ?');
    $ps->execute([$user['id']]);
    $post_ids = array_column($ps->fetchAll(PDO::FETCH_ASSOC), 'id');
    $post_count = count($post_ids);

    $commented_count = 0;
    if ($post_count > 0) {
        $placeholder = implode(',', array_fill(0, count($post_ids), '?'));
        $commented_count = $this->get('helper')->fetch_first("SELECT COUNT(*) AS count FROM `comments` WHERE `post_id` IN ({$placeholder})", ...$post_ids)['count'];
    }

    $me = $this->get('helper')->get_session_user();

    return $this->get('view')->render($response, 'user.php', ['posts' => $posts, 'user' => $user, 'post_count' => $post_count, 'comment_count' => $comment_count, 'commented_count'=> $commented_count, 'me' => $me]);
});

$app->run();
