<?php
/**
 * Fixed-window rate limiter backed by transients.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Support;

/**
 * Counts hits per key within a window. The counting rule is a pure static method so it can be
 * unit-tested with a fake clock; hit() only adds transient storage around it.
 */
final class RateLimiter {

	/**
	 * Bucket name (e.g. ai, finder).
	 *
	 * @var string
	 */
	private string $bucket;

	/**
	 * Allowed hits per window.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Window length in seconds.
	 *
	 * @var int
	 */
	private int $window;

	/**
	 * Clock.
	 *
	 * @var callable(): int
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param string        $bucket Bucket name.
	 * @param int           $limit  Hits per window.
	 * @param int           $window Window seconds.
	 * @param callable|null $now    Clock returning a Unix timestamp.
	 */
	public function __construct( string $bucket, int $limit, int $window, ?callable $now = null ) {
		$this->bucket = $bucket;
		$this->limit  = max( 1, $limit );
		$this->window = max( 1, $window );
		$this->now    = $now ?? 'time';
	}

	/**
	 * Record a hit for a key and report whether it is allowed.
	 *
	 * @param string $key Identifier (user ID or hashed IP).
	 * @return array{allowed: bool, remaining: int, retry_after: int, limit: int}
	 */
	public function hit( string $key ): array {
		$transient = 'spa_ai_rl_' . $this->bucket . '_' . md5( $key );
		$state     = get_transient( $transient );
		$result    = self::evaluate( is_array( $state ) ? $state : null, (int) call_user_func( $this->now ), $this->limit, $this->window );

		if ( $result['allowed'] ) {
			$ttl = max( 1, $result['state']['start'] + $this->window - (int) call_user_func( $this->now ) );
			set_transient( $transient, $result['state'], $ttl );
		}

		return array(
			'allowed'     => $result['allowed'],
			'remaining'   => $result['remaining'],
			'retry_after' => $result['retry_after'],
			'limit'       => $this->limit,
		);
	}

	/**
	 * Pure counting rule.
	 *
	 * @param array{count: int, start: int}|null $state  Stored state or null.
	 * @param int                                $now    Current timestamp.
	 * @param int                                $limit  Hits per window.
	 * @param int                                $window Window seconds.
	 * @return array{allowed: bool, remaining: int, retry_after: int, state: array{count: int, start: int}}
	 */
	public static function evaluate( ?array $state, int $now, int $limit, int $window ): array {
		$start = (int) ( $state['start'] ?? 0 );
		$count = (int) ( $state['count'] ?? 0 );

		if ( null === $state || $now >= $start + $window || $now < $start ) {
			$start = $now;
			$count = 0;
		}

		if ( $count >= $limit ) {
			return array(
				'allowed'     => false,
				'remaining'   => 0,
				'retry_after' => max( 1, $start + $window - $now ),
				'state'       => array(
					'count' => $count,
					'start' => $start,
				),
			);
		}

		++$count;
		return array(
			'allowed'     => true,
			'remaining'   => $limit - $count,
			'retry_after' => 0,
			'state'       => array(
				'count' => $count,
				'start' => $start,
			),
		);
	}

	/**
	 * Privacy-friendly key for anonymous visitors: a salted hash of the IP, never the IP itself.
	 */
	public static function visitor_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return wp_hash( $ip, 'nonce' );
	}
}
