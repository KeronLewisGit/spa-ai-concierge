<?php
/**
 * Rate limiter.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use Brain\Monkey\Functions;
use KeronLewis\SpaAiConcierge\Support\RateLimiter;

final class RateLimiterTest extends TestCase {

	public function test_allows_up_to_the_limit_then_blocks_until_the_window_ends(): void {
		$state = null;
		for ( $i = 1; $i <= 20; $i++ ) {
			$result = RateLimiter::evaluate( $state, 1000 + $i, 20, 3600 );
			$this->assertTrue( $result['allowed'] );
			$this->assertSame( 20 - $i, $result['remaining'] );
			$state = $result['state'];
		}

		$blocked = RateLimiter::evaluate( $state, 1100, 20, 3600 );
		$this->assertFalse( $blocked['allowed'] );
		$this->assertSame( 0, $blocked['remaining'] );
		$this->assertSame( 1001 + 3600 - 1100, $blocked['retry_after'] );

		$next_window = RateLimiter::evaluate( $state, 1001 + 3600, 20, 3600 );
		$this->assertTrue( $next_window['allowed'] );
		$this->assertSame( 19, $next_window['remaining'] );
		$this->assertSame( 1001 + 3600, $next_window['state']['start'] );
	}

	public function test_clock_going_backwards_resets_the_window(): void {
		$result = RateLimiter::evaluate( array( 'count' => 20, 'start' => 5000 ), 4000, 20, 3600 );
		$this->assertTrue( $result['allowed'] );
	}

	public function test_hit_reads_and_writes_transients(): void {
		$store = array();
		Functions\when( 'get_transient' )->alias( static fn( $key ) => $store[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $ttl ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		// Re-bind get_transient so it sees the updated store by reference.
		Functions\when( 'get_transient' )->alias( static function ( $key ) use ( &$store ) {
			return $store[ $key ] ?? false;
		} );

		$now     = 10000;
		$limiter = new RateLimiter( 'ai', 2, 60, static fn() => $now );

		$this->assertTrue( $limiter->hit( 'user-1' )['allowed'] );
		$this->assertTrue( $limiter->hit( 'user-1' )['allowed'] );
		$third = $limiter->hit( 'user-1' );
		$this->assertFalse( $third['allowed'] );
		$this->assertSame( 60, $third['retry_after'] );
		$this->assertTrue( $limiter->hit( 'user-2' )['allowed'], 'keys are independent' );
		$this->assertCount( 2, $store );
		$this->assertStringStartsWith( 'spa_ai_rl_ai_', (string) array_key_first( $store ) );
	}
}
