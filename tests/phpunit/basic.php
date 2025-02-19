<?php
namespace tests\framework;

class Basic_TestCase extends \WP_UnitTestCase {
  function test_framework() {
    $this->assertTrue( class_exists( 'tangible\\framework' ) );
  }
}
