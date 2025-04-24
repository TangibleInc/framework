<?php
/**
 * Plugin Name: Framework Test Plugin
 */
namespace test;

function basic_messages() {

  post_message_to_js(json_encode( 123 ));
  post_message_to_js(json_encode( 'hi' ));
  post_message_to_js(json_encode([
    'key' => 'value',
  ]));  
}

function basic_assertions() {

  is(123, 123);
  is('hi', 'hi');
  is(
    [
      'key' => 'value',
      'key2' => 'value2'
    ], [
      'key' => 'value',
      'key2' => 'value2'
    ]
  );
  
}