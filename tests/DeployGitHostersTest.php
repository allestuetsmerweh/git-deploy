<?php

$DIRNAME = dirname(realpath(__FILE__));
require_once($DIRNAME . '/../web/deploy.php');

class ParseGithubRequestTest extends \Codeception\Test\Unit {
    public function testParsesEmptyRequest() {
        $this->assertEquals(
          parse_github_request(array()),
          null,
        );
    }

    public function testParsesCompleteRequest() {
        $this->assertEquals(
          parse_github_request(array(
              'repository' => array(
                  'name' => 'repo1',
                  'owner' => array(
                      'login' => 'user',
                  ),
              ),
              'ref' => 'origin/master',
          )),
          array(
              array(
                  'repo_url' => 'git@github.com:user/repo1.git',
                  'branch' => 'master',
              ),
          ),
        );
    }
}

class ParseBitbucketRequestTest extends \Codeception\Test\Unit {
    public function testParsesEmptyRequest() {
        $this->assertEquals(
          parse_bitbucket_request(array()),
          null,
        );
    }

    public function testParsesCompleteRequest() {
        $this->assertEquals(
          parse_bitbucket_request(array(
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
                      array(
                          'new' => array(
                              'type' => 'branch',
                              'name' => 'develop',
                          ),
                      ),
                      array(
                          'new' => array(
                              'type' => 'branch',
                          ),
                      ),
                      array(
                          'new' => array(
                              'type' => 'not_branch',
                          ),
                      ),
                      array(
                          'new' => array(
                          ),
                      ),
                      array(
                          'not_new' => array(
                          ),
                      ),
                  ),
              )
          )),
          array(
              array(
                  'repo_url' => 'git@bitbucket.org:user/repo1.git',
                  'branch' => 'master',
              ),
              array(
                  'repo_url' => 'git@bitbucket.org:user/repo1.git',
                  'branch' => 'develop',
              ),
          ),
        );
    }
}

?>
