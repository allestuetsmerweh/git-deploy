<?php

// Script to update git branch from Bitbucket/Github POST hook.

$config_filename = 'git-deploy.config.json';

function find_file_in_directory_or_parents($filename, $directory) {
    while (true) {
        if (is_file($directory . '/' . $filename)) {
            return $directory;
        }
        if ($directory === '/') {
            return null;
        }
        $directory = dirname($directory);
    }
}

function get_absolute_or_relative_path($path, $root_dir='/') {
    if (file_exists(realpath($root_dir . '/' . $path))) {
        return realpath($root_dir . '/' . $path);
    }
    if (file_exists(realpath($path))) {
        return realpath($path);
    }
    return null;
}

function parse_github_request($json_body) {
    if (!isset($json_body['repository']['owner']['login'])) {
        return null;
    }
    $repo_owner = $json_body['repository']['owner']['login'];

    if (!isset($json_body['repository']['name'])) {
        return null;
    };
    $repo_name = $json_body['repository']['name'];

    if (!$json_body['ref']) {
        return null;
    }
    $branch = substr($json_body['ref'], strrpos($json_body['ref'], '/') + 1);

    return array(
        array(
            'repo_url' => 'git@github.com:' . $repo_owner . '/' . $repo_name . '.git',
            'branch' => $branch,
        ),
    );
}

function parse_bitbucket_request($json_body) {
    if (!isset($json_body['repository']['owner']['username'])) {
        return null;
    }
    $repo_owner = $json_body['repository']['owner']['username'];
    if (!isset($json_body['repository']['name'])) {
        return null;
    }
    $repo_name = $json_body['repository']['name'];

    if (!isset($json_body['push']['changes'])) {
        return null;
    }
    $branch_changes = array_values(array_filter(
        $json_body['push']['changes'],
        function ($change) {
            return (
                isset($change['new']['type'])
                && $change['new']['type'] == 'branch'
                && isset($change['new']['name'])
            );
        },
    ));

    return array_map(
        function ($branch_change) use ($repo_owner, $repo_name) {
            return array(
                'repo_url' => 'git@bitbucket.org:' . $repo_owner . '/' . $repo_name . '.git',
                'branch' => $branch_change['new']['name'],
            );
        },
        $branch_changes,
    );
}

function handle_request($config, $request_get, $request_server, $request_body, $send_mail, $execute_command) {
    if (
        !isset($config['config-dir'])
        || !isset($config['password'])
        || !isset($config['public-root'])
        || !isset($config['git-root'])
        || !isset($config['secrets-root'])
        || !isset($config['admin-email'])
        || !isset($config['email-headers'])
    ) {
        return array(500, 'Invalid config');
    }
    $config_dir = $config['config-dir'];
    $hook_password = $config['password'];
    $public_root = get_absolute_or_relative_path($config['public-root'], $config_dir);
    $git_root = get_absolute_or_relative_path($config['git-root'], $config_dir);
    $secrets_root = get_absolute_or_relative_path($config['secrets-root'], $config_dir);
    $email_webmaster = $config['admin-email'];
    $email_headers = $config['email-headers'];

    if (!isset($request_server['HTTPS']) || $request_server['HTTPS'] != 'on') {
        $send_mail($email_webmaster, "deploy.php Abuse Detected", "Tried to access " . json_encode(__FILE__) . " over non-HTTPS connection", $email_headers);
        return array(500, "Nope. Only HTTPS.");
    }

    if (
        !isset($request_get['password'])
        || !isset($request_get['site'])
    ) {
        return array(400, 'Invalid get');
    }
    if ($request_get['password'] != $hook_password) {
        $send_mail($email_webmaster, "deploy.php Abuse Detected", "Tried to access " . json_encode(__FILE__) . " without correct password.", $email_headers);
        return array(403, "Nope. Only with correct password.");
    }

    session_write_close();

    $res = preg_match('/^([a-zA-Z0-9\.\-\_]+)$/', $request_get['site'], $matches);
    $site_ident = $matches[1];
    if (!$res) {
        return array(400, "Nope. site parameter must be [a-zA-Z0-9\.\-\_]+");
    }
    $_JSON = $request_body;
    if (json_last_error() != JSON_ERROR_NONE) $_JSON = false;
    if (!isset($_JSON)) $_JSON = false;
    $log = '';
    if (!$_JSON) {
        $log .= "Manual mode not possible.";
    }

    $git_heads = null;
    if (isset($request_get['github'])) {
        $git_heads = parse_github_request($_JSON);
    } else {
        $git_heads = parse_bitbucket_request($_JSON);
    }
    if (!$git_heads) {
        return array(400, 'No git heads available.');
    }

    $git_path = $git_root . '/' . $site_ident;

    foreach ($git_heads as $_git_head_key => $git_head) {
        $repo_url = $git_head['repo_url'];
        $branch = $git_head['branch'];
        $log .= "Repository: " . $repo_url . "\n";
        $log .= "    Branch: " . $branch . "\n";
        $backup_branch_name = 'backup_' . date('Y-m-d_H:i:s') . '_' . md5(json_encode($_JSON));
        $output = '';
        if (!is_dir($git_path)) {
            $execute_command('git clone ' . $repo_url . ' ' . $git_path);
        }
        chdir($git_path);

        $cmds = array(
            'git checkout -b ' . $backup_branch_name,
            'git add .',
            'git commit -m \'online changes\'',
            'git checkout ' . $branch,
            'git fetch',
            'git reset --hard origin/' . $branch,
            'git submodule update --init --recursive',
        );

        foreach ($cmds as $_cmd_key => $cmd) {
            $output .= $execute_command($cmd);
        }

        if (is_file('build.sh')) {
            $cmd = (
                'sh build.sh' .
                ' --branch "' . $branch . '"' .
                ' --public-root "' . $public_root . '/' . $site_ident . '"' .
                ' --secrets-root "' . $secrets_root . '/' . $site_ident . '"'
            );
            $output .= $execute_command($cmd);
        }

        $log .= "\n\n### " . $branch . " (Site:" . $site_ident . ") ###\n" . $output . "\n";
    }

    //
    // if (isset($_GET['github'])) {
    //     // Github
    //     // if (!isset($_JSON['repository']['owner']['login'])) die_bad_request("No repo owner username");
    //     // $repo_owner = $_JSON['repository']['owner']['login'];
    //     // if (!isset($_JSON['repository']['name'])) die_bad_request("No repo name");
    //     // $repo_name = $_JSON['repository']['name'];
    //     // $log .= "Repository: " . $repo_owner . "/" . $repo_name . "\n";
    //     //
    //     // if (!$_JSON['ref']) {
    //     //     die_bad_request("JSON malformed: no ref");
    //     // }
    //     //
    //     // $branch = substr($_JSON['ref'], strrpos($_JSON['ref'], '/') + 1);
    //     $output = '';
    //
    //     $backup_branch_name = 'backup_' . date('Y-m-d_H:i:s') . '_' . md5(json_encode($_JSON));
    //     $cmds = array(
    //         'git checkout -b ' . $backup_branch_name,
    //         'git add .',
    //         'git commit -m \'online changes\'',
    //         'git checkout ' . $branch,
    //         'git fetch',
    //         'git reset --hard origin/' . $branch,
    //         'git submodule update --init --recursive',
    //     );
    //
    //     for ($i = 0; $i < count($cmds); $i++) {
    //         $output .= pretty_execute($cmds[$i]);
    //     }
    //
    //     if (is_file('build.sh')) {
    //         $cmd = 'sh build.sh --branch "' . $branch . '" --public-root "' . $public_root . '/' . $site_ident . '" --secrets-root "' . $secrets_root . '/' . $site_ident . '"';
    //         $output .= pretty_execute($cmd);
    //     }
    //
    //     $log .= "\n\n### " . $branch . " (Git Path:" . $git_path . ") ###\n" . $output . "\n";
    //
    // } else {
    //     // Bitbucket
    //     // if (!isset($_JSON['repository']['owner']['username'])) die_bad_request("No repo owner username");
    //     // $repo_owner = $_JSON['repository']['owner']['username'];
    //     // if (!isset($_JSON['repository']['name'])) die_bad_request("No repo name");
    //     // $repo_name = $_JSON['repository']['name'];
    //     // $log .= "Repository: " . $repo_owner . "/" . $repo_name . "\n";
    //     //
    //     // if (!isset($_JSON['push']['changes'])) {
    //     //     die_bad_request("JSON malformed: no changes");
    //     // }
    //     // $changes = $_JSON['push']['changes'];
    //     // for ($i = 0; $i < count($changes); $i++) {
    //     //     if ($changes[$i]['new']['type'] == 'branch') {
    //     //         $branch = $changes[$i]['new']['name'];
    //     //         $git_path = $git_root . '/' . $site_ident;
    //             if (!is_dir($git_path)) {
    //                 pretty_execute('git clone git@bitbucket.org:' . $repo_owner . '/' . $repo_name . '.git ' . $git_path);
    //             }
    //             chdir($git_path);
    //             $output = '';
    //
    //             $backup_branch_name = 'backup_' . date('Y-m-d_H:i:s') . '_' . md5(json_encode($changes));
    //             $cmds = array(
    //                 'git checkout -b ' . $backup_branch_name,
    //                 'git add .',
    //                 'git commit -m \'online changes\'',
    //                 'git checkout ' . $branch,
    //                 'git fetch',
    //                 'git reset --hard origin/' . $branch,
    //                 'git submodule update --init --recursive',
    //             );
    //
    //             for ($i = 0; $i < count($cmds); $i++) {
    //                 $output .= pretty_execute($cmds[$i]);
    //             }
    //
    //             if (is_file('build.sh')) {
    //                 $cmd = 'sh build.sh --branch "' . $branch . '" --public-root "' . $public_root . '/' . $site_ident . '" --secrets-root "' . $secrets_root . '/' . $site_ident . '"';
    //                 $output .= pretty_execute($cmd);
    //             }
    //
    //             $log .= "\n\n### " . $branch . " (Git Path:" . $git_path . ") ###\n" . $output . "\n";
    //         }
    //     }
    // }


    $send_mail(
        $email_webmaster,
        "Repo updated from JSON Webhook",
        $log,
        $email_headers,
    );
    header('Content-Type:text/plain;charset=utf-8');
    return array(200, $log);
}

function pretty_execute($cmd) {
    exec($cmd, $tmp);
    return "\n\n" . $cmd . "\n" . str_repeat('-', strlen($cmd)) . "\n" . implode("\n", $tmp);
}

function die_bad_request($msg) {
    header('HTTP/1.1 400 Bad Request');
    die($msg);
}

function main() {
    global $config_filename;
    $config_dir = find_file_in_directory_or_parents(
        $config_filename,
        dirname(realpath(__FILE__)),
    );
    if ($config_dir === null) {
        return;
    }
    $config = json_decode(file_get_contents($config_dir . '/' . $config_filename), true);
    $config['config-dir'] = $config_dir;
    $request_body = json_decode(file_get_contents('php://input'), true);

    $response = handle_request(
        $config,
        $_GET,
        $_SERVER,
        $request_body,
        'mail',
        'pretty_execute',
    );
    header('HTTP/1.1 '.$request[0]);
    echo $response[1];
}

main();

?>
