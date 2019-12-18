<?php
/**
 * Plugin Name: WP Redirect Loop
 * Plugin URI: https://beapi.fr
 * Description: Prevent redirect loops with wp_redirect() function
 * Version: 1.0.1
 * Author: Be API
 * Author URI: http://beapi.fr
 */

/**
 * WP Redirect Loop plugin class.
 */
class WP_Redirect_Loop {

	/**
	 * Plugin instance.
	 *
	 * @var
	 */
	public static $instance = null;

	/**
	 * Plugin instance creator.
	 *
	 * @return WP_Redirect_Loop
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->hooks();
		}

		return self::$instance;
	}

	/**
	 * Register plugin's hooks
	 */
	public function hooks() {
		add_filter( 'wp_redirect', [ $this, 'wp_redirect' ], 10 );
	}

	/**
	 * Detect infinite loop by comparing redirect location with current location.
	 *
	 * @param string $location
	 *
	 * @return string
	 */
	public function wp_redirect( $location ) {
		if ( untrailingslashit( $location ) === untrailingslashit( $this->get_current_url() ) ) {
			$this->redirect_loop_handler( $location );
		}

		return $location;
	}

	/**
	 * Handle redirection loop error.
	 *
	 * @param string $location
	 */
	protected function redirect_loop_handler( $location ) {
		$loop_initiator = $this->find_redirect_loop_initiator();

		if ( defined( 'WP_DEBUG' ) && true === (bool) WP_DEBUG ) {
			$html = '<h2>Redirect loop detected</h2>' . PHP_EOL;
			$html .= '<p>The loop happened on the url : ' . esc_url( $location ) . '</p>' . PHP_EOL;
			$html .= '<p>Here the details on what might be causing a infinite redirect :</p>' . PHP_EOL;

			if ( ! empty( $loop_initiator ) ) {
				$html .= '<pre>' . var_export( $loop_initiator, true ) . '</pre>' . PHP_EOL;
			} else {
				$html .= '<p><em>We could not detect which part of the code is causing a redirect.</em></p>';
			}

			wp_die( $html, 'Redirect loop aborted' );
		}

		$msg = sprintf( 'Redirect loop detected on the URL %s.', esc_url( $location ) );
		$msg .= ( ! empty( $loop_initiator ) )
			? sprintf( ' The loop might be cause by %s:%d.', wp_normalize_path( $loop_initiator['file'] ), (int) $loop_initiator['line'] )
			: '';
		error_log( $msg );
	}

	/**
	 * Attempt to find the redirect loop initiator.
	 *
	 * Basically going through the backtrace to find the last code that call the function
	 * `wp_redirect` or `wp_safe_redirect`.
	 *
	 * @return array
	 */
	protected function find_redirect_loop_initiator() {
		$traces         = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		$loop_initiator = [];
		foreach ( $traces as $k => $trace ) {
			// we loop through the stack until we find the call to apply_filter by the wp_redirect function
			if ( false === stripos( $trace['file'], 'pluggable.php' ) || 'apply_filters' !== $trace['function'] ) {
				continue;
			}

			// at this point we don't know if the user has used the wp_redirect or wp_safe_redirect function
			$loop_initiator = $traces[ $k + 1 ];

			// if the current function is wp_redirect and the caller pluggable.php that mean the wp_safe_redirect
			// function was used and the loop initiator should be the previous caller in the stack
			if (
				false !== stripos( $loop_initiator['file'], 'pluggable.php' )
				&& 'wp_redirect' === $loop_initiator['function']
			) {
				$loop_initiator = $traces[ $k + 2 ];
			}

			break;
		}

		// Return only relative path
		$loop_initiator = array_map( array( $this, 'normalize_path' ), $loop_initiator );

		return $loop_initiator;
	}

	/**
	 * Replace full path by relative version
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 * @see : wp_debug_backtrace_summary()
	 */
	protected function normalize_path( $value ) {
		static $truncate_paths;

		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( ! isset( $truncate_paths ) ) {
			$truncate_paths = array(
				wp_normalize_path( WP_CONTENT_DIR ),
				wp_normalize_path( ABSPATH ),
			);
		}

		return str_replace( $truncate_paths, '', $value );
	}

	/**
	 * Get the current script URL including the request_uri.
	 *
	 * This is just a helper method.
	 *
	 * @return string
	 */
	protected function get_current_url() {
		return $this->full_url( $_SERVER, true );
	}

	/**
	 * Get the current URL including the request_uri
	 *
	 * @param array $s
	 * @param bool $use_forwarded_host
	 *
	 * @return string
	 * @see https://stackoverflow.com/a/8891890
	 */
	protected function full_url( $s, $use_forwarded_host = false ) {
		return $this->url_origin( $s, $use_forwarded_host ) . $s['REQUEST_URI'];
	}

	/**
	 * Get the current origin URL.
	 *
	 * @param array $s
	 * @param bool $use_forwarded_host
	 *
	 * @return string
	 * @see https://stackoverflow.com/a/8891890
	 */
	protected function url_origin( $s, $use_forwarded_host = false ) {
		$ssl      = ( ! empty( $s['HTTPS'] ) && 'on' === $s['HTTPS'] );
		$sp       = strtolower( $s['SERVER_PROTOCOL'] );
		$protocol = substr( $sp, 0, strpos( $sp, '/' ) ) . ( ( $ssl ) ? 's' : '' );
		$port     = $s['SERVER_PORT'];
		$port     = ( ( ! $ssl && '80' === $port ) || ( $ssl && '443' === $port ) ) ? '' : ':' . $port;
		$host     = ( $use_forwarded_host && isset( $s['HTTP_X_FORWARDED_HOST'] ) ) ? $s['HTTP_X_FORWARDED_HOST'] : ( isset( $s['HTTP_HOST'] ) ? $s['HTTP_HOST'] : null );
		$host     = isset( $host ) ? $host : $s['SERVER_NAME'] . $port;

		return $protocol . '://' . $host;
	}
}

// init plugin
WP_Redirect_Loop::instance();
