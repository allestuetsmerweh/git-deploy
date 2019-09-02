<?php

// Script to update git branch from Bitbucket/Github POST hook.

$config_dir = dirname(realpath(__FILE__));
while (true) {
    if (is_file($config_dir . '/git-deploy.config.json')) break;
    $config_dir = dirname($config_dir);
}
$config = json_decode(file_get_contents($config_dir . '/git-deploy.config.json'), true);

function get_path($path) {
    global $config_dir;
    if (is_dir(realpath($config_dir . '/' . $path))) {
        return realpath($config_dir . '/' . $path);
    } else if (is_dir(realpath($path))) {
        return realpath($path);
    }
    throw new Exception('No such path: ' . $path);
}

$hook_password = $config['password'];
$public_root = get_path($config['public-root']);
$git_root = get_path($config['git-root']);
$secrets_root = get_path($config['secrets-root']);
$email_webmaster = $config['admin-email'];
$email_headers = $config['email-headers'];

function prevent_abuse() {
    global $hook_password, $email_webmaster, $email_headers;
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != 'on') {
        header('HTTP/1.1 403 Forbidden');
        mail($email_webmaster, "deploy.php Abuse Detected", "Tried to access " . json_encode(__FILE__) . " over non-HTTPS connection", $email_headers);
        die("Nope. Only HTTPS.");
    }
    if (!isset($_GET) || !isset($_GET['password']) || $_GET['password'] != $hook_password) {
        header('HTTP/1.1 403 Forbidden');
        mail($email_webmaster, "deploy.php Abuse Detected", "Tried to access " . json_encode(__FILE__) . " without correct password, but " . json_encode($_GET['password']), $email_headers);
        die("Nope. Only with correct password.");
    }
}

prevent_abuse();

session_write_close();

function die_bad_request($msg) {
    header('HTTP/1.1 400 Bad Request');
    die($msg);
}
function pretty_execute($cmd) {
    exec($cmd, $tmp);
    return "\n\n" . $cmd . "\n" . str_repeat('-', strlen($cmd)) . "\n" . implode("\n", $tmp);
}
$res = preg_match('/^([a-zA-Z0-9\.\-\_]+)$/', $_GET['site'], $matches);
$site_ident = $matches[1];
if (!$res) die_bad_request("Nope. site parameter must be [a-zA-Z0-9\.\-\_]+");
$_JSON = json_decode(file_get_contents('php://input'), true);
if (json_last_error() != JSON_ERROR_NONE) $_JSON = false;
if (!isset($_JSON)) $_JSON = false;
$log = '';
if (!$_JSON) {
    $log .= "Manual mode not possible.";
}
if (isset($_GET['github'])) {
    // Github
    if (!isset($_JSON['repository']['owner']['login'])) die_bad_request("No repo owner username");
    $repo_owner = $_JSON['repository']['owner']['login'];
    if (!isset($_JSON['repository']['name'])) die_bad_request("No repo name");
    $repo_name = $_JSON['repository']['name'];
    $log .= "Repository: " . $repo_owner . "/" . $repo_name . "\n";

    if (!$_JSON['ref']) {
        die_bad_request("JSON malformed: no ref");
    }

    $branch = substr($_JSON['ref'], strrpos($_JSON['ref'], '/') + 1);
    $git_path = $git_root . '/' . $site_ident;
    if (!is_dir($git_path)) {
        pretty_execute('git clone git@github.com:' . $repo_owner . '/' . $repo_name . '.git ' . $git_path);
    }
    chdir($git_path);
    $output = '';

    $backup_branch_name = 'backup_' . date('Y-m-d_H:i:s') . '_' . md5(json_encode($_JSON));
    $cmds = array(
        'git checkout -b ' . $backup_branch_name,
        'git add .',
        'git commit -m \'online changes\'',
        'git checkout ' . $branch,
        'git fetch',
        'git reset --hard origin/' . $branch,
        'git submodule update --init --recursive',
    );

    for ($i = 0; $i < count($cmds); $i++) {
        $output .= pretty_execute($cmds[$i]);
    }

    if (is_file('build.sh')) {
        $cmd = 'sh build.sh --branch "' . $branch . '" --public-root "' . $public_root . '/' . $site_ident . '" --secrets-root "' . $secrets_root . '/' . $site_ident . '"';
        $output .= pretty_execute($cmd);
    }

    $log .= "\n\n### " . $branch . " (Git Path:" . $git_path . ") ###\n" . $output . "\n";

} else {
    // Bitbucket
    if (!isset($_JSON['repository']['owner']['username'])) die_bad_request("No repo owner username");
    $repo_owner = $_JSON['repository']['owner']['username'];
    if (!isset($_JSON['repository']['name'])) die_bad_request("No repo name");
    $repo_name = $_JSON['repository']['name'];
    $log .= "Repository: " . $repo_owner . "/" . $repo_name . "\n";

    if (!isset($_JSON['push']['changes'])) {
        die_bad_request("JSON malformed: no changes");
    }
    $changes = $_JSON['push']['changes'];
    for ($i = 0; $i < count($changes); $i++) {
        if ($changes[$i]['new']['type'] == 'branch') {
            $branch = $changes[$i]['new']['name'];
            $git_path = $git_root . '/' . $site_ident;
            if (!is_dir($git_path)) {
                pretty_execute('git clone git@bitbucket.org:' . $repo_owner . '/' . $repo_name . '.git ' . $git_path);
            }
            chdir($git_path);
            $output = '';

            $backup_branch_name = 'backup_' . date('Y-m-d_H:i:s') . '_' . md5(json_encode($changes));
            $cmds = array(
                'git checkout -b ' . $backup_branch_name,
                'git add .',
                'git commit -m \'online changes\'',
                'git checkout ' . $branch,
                'git fetch',
                'git reset --hard origin/' . $branch,
                'git submodule update --init --recursive',
            );

            for ($i = 0; $i < count($cmds); $i++) {
                $output .= pretty_execute($cmds[$i]);
            }

            if (is_file('build.sh')) {
                $cmd = 'sh build.sh --branch "' . $branch . '" --public-root "' . $public_root . '/' . $site_ident . '" --secrets-root "' . $secrets_root . '/' . $site_ident . '"';
                $output .= pretty_execute($cmd);
            }

            $log .= "\n\n### " . $branch . " (Git Path:" . $git_path . ") ###\n" . $output . "\n";
        }
    }
}
mail($email_webmaster, "Bitbucket Repo " . json_encode($repo_owner . "/" . $repo_name) . " updated from JSON Webhook", $log, $email_headers);
header('Content-Type:text/plain;charset=utf-8');
echo $log;

?>
