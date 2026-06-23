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
            'host' => $_SERVER['ISUCONP_DB_HOST'] ?? '127.0.0.1',
            'port' => $_SERVER['ISUCONP_DB_PORT'] ?? 3306,
            'username' => $_SERVER['ISUCONP_DB_USER'] ?? 'root',
            'password' => $_SERVER['ISUCONP_DB_PASSWORD'] ?? null,
            'database' => $_SERVER['ISUCONP_DB_NAME'] ?? 'isuconp',
        ],
    ];
});
$container->set('memcached', function () use ($memd_addr) {
    $mc = new Memcached('isucon');
    if (!$mc->getServerList()) {
        [$host, $port] = explode(':', $memd_addr);
        $mc->addServer($host, (int)$port);
    }
    return $mc;
});

$container->set('db', function ($c) {
    $config = $c->get('settings');
    return new PDO(
        "mysql:dbname={$config['db']['database']};host={$config['db']['host']};port={$config['db']['port']};charset=utf8mb4",
        $config['db']['username'],
        $config['db']['password'],
        []
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
            // インデックスを作成（存在確認してからDROP→CREATE）
            $indexes = [
                ['posts',    'idx_created_at',          'CREATE INDEX `idx_created_at` ON `posts` (`created_at` DESC)'],
                ['posts',    'idx_user_id_created_at',  'CREATE INDEX `idx_user_id_created_at` ON `posts` (`user_id`, `created_at` DESC)'],
                ['comments', 'idx_post_id_created_at',  'CREATE INDEX `idx_post_id_created_at` ON `comments` (`post_id`, `created_at`)'],
                ['comments', 'idx_user_id',             'CREATE INDEX `idx_user_id` ON `comments` (`user_id`)'],
            ];
            foreach ($indexes as [$table, $name, $create]) {
                $exists = $db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '{$table}' AND index_name = '{$name}'")->fetchColumn();
                if ($exists) {
                    $db->query("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
                $db->query($create);
            }
            // 画像キャッシュディレクトリをクリア（ファイルはリクエスト時にDBから遅延生成する）
            $image_dir = dirname(dirname(__FILE__)) . '/../public/image/';
            if (is_dir($image_dir)) {
                array_map('unlink', glob($image_dir . '*') ?: []);
            } else {
                mkdir($image_dir, 0755, true);
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
            $user = $this->fetch_first('SELECT `id`, `account_name`, `passhash`, `authority`, `del_flg`, `created_at` FROM users WHERE account_name = ? AND del_flg = 0', $account_name);
            if ($user !== false && calculate_passhash($user['account_name'], $password) == $user['passhash']) {
                unset($user['passhash']);
                return $user;
            }
            return null;
        }

        public function get_session_user() {
            if (!isset($_SESSION['user']['id'])) {
                return null;
            }
            // del_flgチェックは10秒に1回だけDBに問い合わせる
            $now = time();
            if (!isset($_SESSION['user']['_checked_at']) || $now - $_SESSION['user']['_checked_at'] > 10) {
                $row = $this->fetch_first('SELECT `del_flg` FROM `users` WHERE `id` = ?', $_SESSION['user']['id']);
                if (!$row || $row['del_flg'] != 0) {
                    unset($_SESSION['user']);
                    return null;
                }
                $_SESSION['user']['_checked_at'] = $now;
            }
            return $_SESSION['user'];
        }

        public function make_posts(array $results, $options = []) {
            $options += ['all_comments' => false];
            $all_comments = $options['all_comments'];

            if (empty($results)) {
                return [];
            }

            $db = $this->db();
            $post_ids = array_column($results, 'id');
            $placeholder = implode(',', array_fill(0, count($post_ids), '?'));

            // コメント数を一括集計
            $ps = $db->prepare("SELECT `post_id`, COUNT(*) AS `count` FROM `comments` WHERE `post_id` IN ($placeholder) GROUP BY `post_id`");
            $ps->execute($post_ids);
            $comment_counts = array_column($ps->fetchAll(PDO::FETCH_ASSOC), 'count', 'post_id');

            // コメントをJOINでユーザー情報込みで一括取得（ROW_NUMBER()でLIMIT相当を実現）
            $rn_filter = $all_comments ? '' : 'AND `rn` <= 3';
            $ps = $db->prepare("
                SELECT c.`id`, c.`post_id`, c.`user_id`, c.`comment`, c.`created_at`,
                       u.`id` AS `u_id`, u.`account_name`, u.`authority`, u.`del_flg`, u.`created_at` AS `u_created_at`
                FROM (
                    SELECT *, ROW_NUMBER() OVER (PARTITION BY `post_id` ORDER BY `created_at` DESC, `id` DESC) AS `rn`
                    FROM `comments` WHERE `post_id` IN ($placeholder)
                ) c
                JOIN `users` u ON c.`user_id` = u.`id`
                WHERE 1=1 $rn_filter
                ORDER BY c.`post_id`, c.`created_at` ASC
            ");
            $ps->execute($post_ids);
            $comments_by_post = [];
            foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $comment = [
                    'id'         => $row['id'],
                    'post_id'    => $row['post_id'],
                    'user_id'    => $row['user_id'],
                    'comment'    => $row['comment'],
                    'created_at' => $row['created_at'],
                    'user'       => [
                        'id'           => $row['u_id'],
                        'account_name' => $row['account_name'],
                        'authority'    => $row['authority'],
                        'del_flg'      => $row['del_flg'],
                        'created_at'   => $row['u_created_at'],
                    ],
                ];
                $comments_by_post[$row['post_id']][] = $comment;
            }

            // 投稿ユーザーを一括取得
            $user_ids = array_values(array_unique(array_column($results, 'user_id')));
            $uph = implode(',', array_fill(0, count($user_ids), '?'));
            $ps = $db->prepare("SELECT `id`, `account_name`, `authority`, `del_flg`, `created_at` FROM `users` WHERE `id` IN ($uph) AND `del_flg` = 0");
            $ps->execute($user_ids);
            $users_by_id = array_column($ps->fetchAll(PDO::FETCH_ASSOC), null, 'id');

            $posts = [];
            foreach ($results as $post) {
                $post['comment_count'] = $comment_counts[$post['id']] ?? 0;
                $post['comments'] = $comments_by_post[$post['id']] ?? [];
                $post['user'] = $users_by_id[$post['user_id']] ?? null;
                if ($post['user'] !== null && $post['user']['del_flg'] == 0) {
                    $posts[] = $post;
                }
                if (count($posts) >= POSTS_PER_PAGE) {
                    break;
                }
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
    $this->get('memcached')->flush();
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
        $_SESSION['user'] = $user;
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
    $new_id = $db->lastInsertId();
    $new_user = $this->get('helper')->fetch_first('SELECT `id`, `account_name`, `authority`, `del_flg`, `created_at` FROM `users` WHERE `id` = ?', $new_id);
    $_SESSION['user'] = $new_user;
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

    $mc = $this->get('memcached');
    $posts = $mc->get('posts_top');
    if ($posts === false) {
        $db = $this->get('db');
        $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` ORDER BY `created_at` DESC LIMIT 30');
        $ps->execute();
        $results = $ps->fetchAll(PDO::FETCH_ASSOC);
        $posts = $this->get('helper')->make_posts($results);
        $mc->set('posts_top', $posts, 5);
    }

    return $this->get('view')->render($response, 'index.php', [
        'posts' => $posts,
        'me' => $me,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->get('/posts', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $max_created_at = $params['max_created_at'] ?? null;
    $mc = $this->get('memcached');

    $cache_key = 'posts_' . ($max_created_at ?? 'top');
    $posts = $mc->get($cache_key);
    if ($posts === false) {
        $db = $this->get('db');
        if ($max_created_at === null) {
            $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` ORDER BY `created_at` DESC LIMIT 30');
            $ps->execute();
        } else {
            $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` WHERE `created_at` <= ? ORDER BY `created_at` DESC LIMIT 30');
            $ps->execute([$max_created_at]);
        }
        $results = $ps->fetchAll(PDO::FETCH_ASSOC);
        $posts = $this->get('helper')->make_posts($results);
        $mc->set($cache_key, $posts, 5);
    }

    return $this->get('view')->render($response, 'posts.php', ['posts' => $posts]);
});

$app->get('/posts/{id}', function (Request $request, Response $response, $args) {
    $mc = $this->get('memcached');
    $cache_key = 'post_' . $args['id'];
    $posts = $mc->get($cache_key);
    if ($posts === false) {
        $db = $this->get('db');
        $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` WHERE `id` = ?');
        $ps->execute([$args['id']]);
        $results = $ps->fetchAll(PDO::FETCH_ASSOC);
        $posts = $this->get('helper')->make_posts($results, ['all_comments' => true]);
        if (count($posts) > 0) {
            $mc->set($cache_key, $posts, 10);
        }
    }

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

        if (filesize($_FILES['file']['tmp_name']) > UPLOAD_LIMIT) {
            $this->get('flash')->addMessage('notice', 'ファイルサイズが大きすぎます');
            return redirect($response, '/', 302);
        }

        $imgdata = file_get_contents($_FILES['file']['tmp_name']);
        $db = $this->get('db');
        $query = 'INSERT INTO `posts` (`user_id`, `mime`, `imgdata`, `body`) VALUES (?,?,?,?)';
        $ps = $db->prepare($query);
        $ps->execute([
          $me['id'],
          $mime,
          '',
          $params['body'],
        ]);
        $pid = $db->lastInsertId();
        $image_dir = dirname(dirname(__FILE__)) . '/../public/image/';
        $ext_map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
        if (!is_dir($image_dir)) {
            mkdir($image_dir, 0755, true);
        }
        file_put_contents($image_dir . $pid . '.' . $ext_map[$mime], $imgdata);
        $mc = $this->get('memcached');
        $mc->delete('posts_top');
        $mc->delete('user_posts_' . $me['id']);
        $mc->delete('user_stats_' . $me['id']);
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

    $image_dir = dirname(dirname(__FILE__)) . '/../public/image/';
    $file = $image_dir . $args['id'] . '.' . $args['ext'];
    $mime_map = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    $mime = $mime_map[$args['ext']] ?? null;
    if ($mime === null) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    if (file_exists($file)) {
        $stream = new \Slim\Psr7\Stream(fopen($file, 'rb'));
        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'max-age=86400, public');
    }

    $post = $this->get('helper')->fetch_first('SELECT `id`, `mime`, `imgdata` FROM `posts` WHERE `id` = ?', $args['id']);

    if ($post === false) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    if ($post['mime'] !== $mime) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    if (!is_dir($image_dir)) {
        mkdir($image_dir, 0755, true);
    }
    file_put_contents($file, $post['imgdata']);
    $stream = new \Slim\Psr7\Stream(fopen($file, 'rb'));
    return $response
        ->withBody($stream)
        ->withHeader('Content-Type', $post['mime'])
        ->withHeader('Cache-Control', 'max-age=86400, public');
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

    $db = $this->get('db');
    $query = 'INSERT INTO `comments` (`post_id`, `user_id`, `comment`) VALUES (?,?,?)';
    $ps = $db->prepare($query);
    $ps->execute([
        $post_id,
        $me['id'],
        $params['comment']
    ]);

    $mc = $this->get('memcached');
    $mc->delete('posts_top');
    $mc->delete('post_' . $post_id);
    // コメント先の投稿オーナーのcommented_countキャッシュも無効化
    $owner = $this->get('helper')->fetch_first('SELECT `user_id` FROM `posts` WHERE `id` = ?', $post_id);
    if ($owner) {
        $mc->delete('user_stats_' . $owner['user_id']);
    }
    $mc->delete('user_stats_' . $me['id']);

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
    $ps = $db->prepare('SELECT `id`, `account_name` FROM `users` WHERE `authority` = 0 AND `del_flg` = 0 ORDER BY `created_at` DESC');
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
    $ids = array_values(array_filter($params['uid'] ?? [], fn($id) => preg_match('/\A[0-9]+\z/', (string)$id)));
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE `users` SET `del_flg` = 1 WHERE `id` IN ($ph)")->execute($ids);
    }
    $this->get('memcached')->delete('posts_top');

    return redirect($response, '/admin/banned', 302);
});

$app->get('/@{account_name}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $mc = $this->get('memcached');

    $user = $this->get('helper')->fetch_first('SELECT `id`, `account_name`, `authority`, `del_flg`, `created_at` FROM `users` WHERE `account_name` = ? AND `del_flg` = 0', $args['account_name']);

    if ($user === false) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $posts = $mc->get('user_posts_' . $user['id']);
    if ($posts === false) {
        $ps = $db->prepare('SELECT p.`id`, p.`user_id`, p.`body`, p.`created_at`, p.`mime` FROM `posts` p JOIN `users` u ON p.`user_id` = u.`id` WHERE p.`user_id` = ? AND u.`del_flg` = 0 ORDER BY p.`created_at` DESC LIMIT 20');
        $ps->execute([$user['id']]);
        $results = $ps->fetchAll(PDO::FETCH_ASSOC);
        $posts = $this->get('helper')->make_posts($results);
        $mc->set('user_posts_' . $user['id'], $posts, 5);
    }

    $stats = $mc->get('user_stats_' . $user['id']);
    if ($stats === false) {
        $ps = $db->prepare('
            SELECT
                (SELECT COUNT(*) FROM `posts` WHERE `user_id` = ?) AS post_count,
                (SELECT COUNT(*) FROM `comments` WHERE `user_id` = ?) AS comment_count,
                (SELECT COUNT(*) FROM `comments` c JOIN `posts` p ON c.`post_id` = p.`id` WHERE p.`user_id` = ?) AS commented_count
        ');
        $ps->execute([$user['id'], $user['id'], $user['id']]);
        $stats = $ps->fetch(PDO::FETCH_ASSOC);
        $mc->set('user_stats_' . $user['id'], $stats, 5);
    }

    $me = $this->get('helper')->get_session_user();

    return $this->get('view')->render($response, 'user.php', [
        'posts'           => $posts,
        'user'            => $user,
        'post_count'      => $stats['post_count'],
        'comment_count'   => $stats['comment_count'],
        'commented_count' => $stats['commented_count'],
        'me'              => $me,
    ]);
});

$app->run();
