<?php

$DIRNAME = dirname(realpath(__FILE__));
require_once($DIRNAME . '/../web/deploy.php');

$mock_mails = array();
function mockMail($to, $subject, $body, $headers) {
    $mock_mails[] = array(
        'to' => $to,
        'subject' => $subject,
        'body' => $body,
        'headers' => $headers,
    );
}

$mock_executions = array();
function mockExecute($cmd) {
    $mock_executions[] = array(
        'cmd' => $cmd,
    );
    return '.';
}

class HandleRequestTest extends \Codeception\Test\Unit {
    protected function _before() {
        $this->root_path = dirname(realpath(__FILE__)) . '/handle_request';
        $this->public_path = $this->root_path . '/public';
        $this->secrets_path = $this->root_path . '/secrets';
        $this->git_path = $this->root_path . '/git';
        $this->example_git_path = $this->root_path . '/git/example.com';

        @mkdir($this->root_path);
        @mkdir($this->public_path);
        @mkdir($this->secrets_path);
        @mkdir($this->git_path);
        @mkdir($this->example_git_path);

        $this->valid_config = array(
            'config-dir' => '/config.json',
            'password' => 'correct',
            'public-root' => $this->public_path,
            'secrets-root' => $this->secrets_path,
            'git-root' => $this->git_path,
            'admin-email' => 'admin@example.com',
            'email-headers' => 'From: noreply@example.com',
        );
        $this->invalid_config = array();
        $this->valid_github_get = array('password' => 'correct', 'site' => 'example.com', 'github' => '1');
        $this->valid_bitbucket_get = array('password' => 'correct', 'site' => 'example.com');
        $this->get_without_password = array('site' => 'example.com');
        $this->get_with_wrong_password = array('password' => 'incorrect', 'site' => 'example.com');
        $this->get_without_site = array('password' => 'correct');
        $this->valid_server = array('HTTPS' => 'on');
        $this->server_without_https = array();
        $this->valid_github_body = array(
            'repository' => array(
                'name' => 'repo1',
                'owner' => array(
                    'login' => 'user',
                ),
            ),
            'ref' => 'origin/master',
        );
        $this->valid_bitbucket_body = array(
            'repository' => array(
                'name' => 'repo1',
                'owner' => array(
                    'username' => 'user',
                ),
            ),
            'push' => array(
                'changes' => array(
                    array(
                        'new' => array(
                            'type' => 'branch',
                            'name' => 'master',
                        ),
                    ),
                ),
            )
        );
    }

    protected function _after() {
        @rmdir($this->example_git_path);
        @rmdir($this->git_path);
        @rmdir($this->secrets_path);
        @rmdir($this->public_path);
        @rmdir($this->root_path);
    }

    public function testHandlesValidGithubRequest() {
        $response = handle_request(
            $this->valid_config,
            $this->valid_github_get,
            $this->valid_server,
            $this->valid_github_body,
            'mockMail',
            'mockExecute',
        );
        $this->assertEquals(
          $response,
          array(200, "Repository: git@github.com:user/repo1.git\n    Branch: master\n\n\n### master (Site:example.com) ###\n.......\n"),
        );
    }

    public function testHandlesValidBitbucketRequest() {
        $response = handle_request(
            $this->valid_config,
            $this->valid_bitbucket_get,
            $this->valid_server,
            $this->valid_bitbucket_body,
            'mockMail',
            'mockExecute',
        );
        $this->assertEquals(
          $response,
          array(200, "Repository: git@bitbucket.org:user/repo1.git\n    Branch: master\n\n\n### master (Site:example.com) ###\n.......\n"),
        );
    }

    public function testHandlesInvalidConfig() {
        $response = handle_request(
            $this->invalid_config,
            $this->valid_github_get,
            $this->valid_server,
            $this->valid_github_body,
            'mockMail',
            'mockExecute',
        );
        $this->assertEquals(
          $response,
          array(500, "Invalid config"),
        );
    }

    public function testHandlesGetWithoutPassword() {
        $response = handle_request(
            $this->valid_config,
            $this->get_without_password,
            $this->valid_server,
            $this->valid_github_body,
            'mockMail',
            'mockExecute',
        );
        $this->assertEquals(
          $response,
          array(400, "Invalid get"),
        );
    }

    public function testHandlesGetWithWrongPassword() {
        $response = handle_request(
            $this->valid_config,
            $this->get_with_wrong_password,
            $this->valid_server,
            $this->valid_github_body,
            'mockMail',
            'mockExecute',
        );
        $this->assertEquals(
          $response,
          array(403, "Nope. Only with correct password."),
        );
    }

    public function testHandlesGetWithoutSite() {
        $response = handle_request(
            $this->valid_config,
            $this->get_without_site,
            $this->valid_server,
            $this->valid_github_body,
            'mockMail',
            'mockExecute',
        );
        $this->assertEquals(
          $response,
          array(400, "Invalid get"),
        );
    }

    public function testHandlesServerWithoutHttps() {
        $response = handle_request(
            $this->valid_config,
            $this->valid_github_get,
            $this->server_without_https,
            $this->valid_github_body,
            'mockMail',
            'mockExecute',
        );
        $this->assertEquals(
          $response,
          array(500, "Nope. Only HTTPS."),
        );
    }
}

?>
