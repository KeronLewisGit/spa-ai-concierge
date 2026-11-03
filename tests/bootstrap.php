<?php
/**
 * PHPUnit bootstrap: Composer autoload plus the WordPress constants the pure classes touch.
 *
 * @package SpaAiConcierge
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'SPA_AI_CONCIERGE_VERSION' ) || define( 'SPA_AI_CONCIERGE_VERSION', 'test' );
