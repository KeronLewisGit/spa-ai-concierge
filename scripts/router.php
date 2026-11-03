<?php
/**
 * Router for `php -S` so pretty permalinks work on the local test site.
 *
 * @package SpaAiConcierge
 */

$spa_ai_root = getcwd();
$spa_ai_path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore

if ( '/' !== $spa_ai_path && file_exists( $spa_ai_root . $spa_ai_path ) && ! is_dir( $spa_ai_root . $spa_ai_path ) ) {
	return false;
}
if ( is_dir( $spa_ai_root . $spa_ai_path ) && file_exists( rtrim( $spa_ai_root . $spa_ai_path, '/' ) . '/index.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $spa_ai_path, '/' ) . '/index.php';
	require rtrim( $spa_ai_root . $spa_ai_path, '/' ) . '/index.php';
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $spa_ai_root . '/index.php';
