<?php
/**
 * Error raised anywhere in the AI pipeline, carrying a machine code and a safe message.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

/**
 * The message is written for editors and is safe to show in wp-admin.
 */
final class AiException extends \RuntimeException {

	/**
	 * Machine-readable code (e.g. invalid_output, http_error).
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * HTTP status the REST layer should use.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Extra detail for logs only (never sent to the browser).
	 *
	 * @var string
	 */
	private string $detail;

	/**
	 * Constructor.
	 *
	 * @param string $error_code Machine code.
	 * @param string $message    Editor-facing message.
	 * @param int    $status     HTTP status for the REST response.
	 * @param string $detail     Log-only detail.
	 */
	public function __construct( string $error_code, string $message, int $status = 502, string $detail = '' ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->status     = $status;
		$this->detail     = $detail;
	}

	/**
	 * Machine code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * HTTP status.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Log-only detail.
	 */
	public function detail(): string {
		return $this->detail;
	}
}
