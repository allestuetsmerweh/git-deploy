<?php

$DIRNAME = dirname(realpath(__FILE__));
require_once($DIRNAME . '/../web/deploy.php');

class FindFileInDirectoryOrParentsTest extends \Codeception\Test\Unit {
    protected function _before() {
        $this->root_path = dirname(realpath(__FILE__)) . '/find_file_in_directory_or_parents';
        $this->good_path = $this->root_path . '/good';
        $this->good_child_path = $this->root_path . '/good/child';
        $this->bad_path = $this->root_path . '/bad';
        $this->file_path = $this->root_path . '/good/test.txt';

        @mkdir($this->root_path);
        @mkdir($this->good_path);
        @mkdir($this->good_child_path);
        @mkdir($this->bad_path);
        @touch($this->file_path);
    }

    protected function _after() {
        @unlink($this->file_path);
        @rmdir($this->bad_path);
        @rmdir($this->good_child_path);
        @rmdir($this->good_path);
        @rmdir($this->root_path);
    }

    public function testFindsFileInSameDirectory() {
        $this->assertEquals(
          find_file_in_directory_or_parents('test.txt', $this->root_path . '/good'),
          $this->root_path . '/good',
        );
    }
    public function testDoesNotFindInexistentFile() {
        $this->assertEquals(
          find_file_in_directory_or_parents('inexistent.txt', $this->root_path . '/good'),
          null,
        );
    }
    public function testDoesNotFindFileInChildDirectory() {
        $this->assertEquals(
          find_file_in_directory_or_parents('test.txt', $this->root_path . ''),
          null,
        );
    }
    public function testDoesNotFindFileInSiblingDirectory() {
        $this->assertEquals(
          find_file_in_directory_or_parents('test.txt', $this->root_path . '/bad'),
          null,
        );
    }
    public function testFindsFileInParentDirectory() {
        $this->assertEquals(
          find_file_in_directory_or_parents('test.txt', $this->root_path . '/good/child'),
          $this->root_path . '/good',
        );
    }
}

class GetAbsoluteOrRelativePathTest extends \Codeception\Test\Unit {
    protected function _before() {
        $this->root_path = dirname(realpath(__FILE__)) . '/get_absolute_or_relative_path';
        $this->good_path = $this->root_path . '/good';
        $this->file_path = $this->root_path . '/good/test.txt';

        @mkdir('./tmp');
        @mkdir($this->root_path);
        @mkdir($this->good_path);
        @touch($this->file_path);
    }

    protected function _after() {
        @unlink($this->file_path);
        @rmdir($this->good_path);
        @rmdir($this->root_path);
        @rmdir('./tmp');
    }

    public function testFindsFileInAbsoluteDirectory() {
        $this->assertEquals(
          get_absolute_or_relative_path('test.txt', $this->root_path . '/good'),
          $this->root_path . '/good/test.txt',
        );
    }
    public function testFindsDirectoryInAbsoluteDirectory() {
        $this->assertEquals(
          get_absolute_or_relative_path('good', $this->root_path),
          $this->root_path . '/good',
        );
    }
    public function testFindsFileInRelativeDirectory() {
        $this->assertEquals(
          get_absolute_or_relative_path('tmp', $this->root_path),
          realpath('./tmp'),
        );
    }
    public function testDoesNotFindInexistentFile() {
        $this->assertEquals(
          get_absolute_or_relative_path('inexistent.txt', $this->root_path . '/good'),
          null,
        );
    }
}

?>
