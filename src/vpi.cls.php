<?php
/**
 * The viewport image class.
 *
 * @since      	4.7
 */
namespace LiteSpeed;
defined( 'WPINC' ) || exit;

class VPI extends Base {
	const LOG_TAG = '[VPI]';

	const TYPE_GEN = 'gen';
	const TYPE_CLEAR_Q = 'clear_q';

	/**
	 * Init
	 *
	 * @since  4.7
	 */
	public function __construct() {
		$this->_summary = self::get_summary();
	}

	/**
	 * The VPI content of the current page
	 *
	 * @since  4.7
	 */
	public function add_to_queue() {
		$is_mobile = $this->_separate_mobile();

		global $wp;
		$request_url = home_url( $wp->request );

		$ua = ! empty( $_SERVER[ 'HTTP_USER_AGENT' ] ) ? $_SERVER[ 'HTTP_USER_AGENT' ] : '';

		// Store it to prepare for cron
		$this->_queue = $this->load_queue( 'vpi' );

		if ( count( $this->_queue ) > 500 ) {
			self::debug( 'Queue is full - 500' );
			return;
		}

		if ( ! is_singular() ) {
			self::debug( 'not single post ID' );
			return;
		}

		$post_id = get_the_ID();

		$queue_k = ( $is_mobile ? 'mobile' : '' ) . ' ' . $request_url;
		if ( ! empty( $this->_queue[ $queue_k ] ) ) {
			self::debug( 'queue k existed ' . $queue_k );
			return;
		}

		$this->_queue[ $queue_k ] = array(
			'url'			=> apply_filters( 'litespeed_vpi_url', $request_url ),
			'post_id' 		=> $post_id,
			'user_agent'	=> substr( $ua, 0, 200 ),
			'is_mobile'		=> $this->_separate_mobile(),
		); // Current UA will be used to request
		$this->save_queue( 'vpi', $this->_queue );
		self::debug( 'Added queue_vpi [url] ' . $queue_k . ' [UA] ' . $ua );

		// Prepare cache tag for later purge
		Tag::add( 'VPI.' . md5( $queue_k ) );

		return null;
	}

	/**
	 * Notify finished from server
	 * @since 4.7
	 */
	public function notify() {
		$post_data = json_decode(file_get_contents('php://input'), true);
		if( is_null( $post_data ) ) {
			$post_data = $_POST;
		}
		self::debug( 'notify() data', $post_data );

		$this->_queue = $this->load_queue( 'vpi' );

		// Validate key
		if ( empty( $post_data[ 'domain_key' ] ) || $post_data[ 'domain_key' ] !== md5( $this->conf( self::O_API_KEY ) ) ) {
			self::debug( '❌ notify wrong key' );
			self::save_summary( array( 'notify_ts_err' => time() ) );
			return Cloud::err( 'wrong_key' );
		}

		list( $post_data ) = $this->cls( 'Cloud' )->extract_msg( $post_data, 'vpi' );

		global $wpdb;

		$notified_data = $post_data[ 'data' ];
		if ( empty( $notified_data ) || ! is_array( $notified_data ) ) {
			self::debug( '❌ notify exit: no notified data' );
			return Cloud::err( 'no notified data' );
		}

		// Check if its in queue or not
		$valid_i = 0;
		foreach ( $notified_data as $v ) {
			if ( empty( $v[ 'request_url' ] ) ) {
				self::debug( '❌ notify bypass: no request_url', $v );
				continue;
			}
			$is_mobile = !empty( $v[ 'is_mobile' ] );
			$queue_k = ( $is_mobile ? 'mobile' : '' ) . ' ' . $v[ 'request_url' ];

			if ( empty( $this->_queue[ $queue_k ] ) ) {
				self::debug( '❌ notify bypass: no this queue [q_k]' . $queue_k );
				continue;
			}

			// Save data
			if ( ! empty( $v[ 'data' ] ) ) {
				$post_id = $this->_queue[ $queue_k ][ 'post_id' ];
				$name = $is_mobile ? 'litespeed_vpi_list_mobile' : 'litespeed_vpi_list';
				$this->cls( 'Metabox' )->save( $post_id, $name, $v[ 'data' ], true );

				$valid_i ++;
			}

			unset( $this->_queue[ $queue_k ] );
			self::debug( 'notify data handled, unset queue [q_k] ' . $queue_k );
		}
		$this->save_queue( 'vpi', $this->_queue );

		self::debug( 'notified' );

		return Cloud::ok( array( 'count' => $valid_i ) );
	}

	/**
	 * Cron
	 *
	 * @since  4.7
	 */
	public static function cron( $continue = false ) {
		$_instance = self::cls();
		return $_instance->_cron_handler( $continue );
	}

	/**
	 * Cron generation
	 *
	 * @since  4.7
	 */
	private function _cron_handler( $continue = false ) {
		self::debug( 'cron start' );
		$this->_queue = $this->load_queue( 'vpi' );

		if ( empty( $this->_queue ) ) {
			return;
		}

		// For cron, need to check request interval too
		if ( ! $continue ) {
			if ( ! empty( $this->_summary[ 'curr_request_vpi' ] ) && time() - $this->_summary[ 'curr_request_vpi' ] < 300 && ! $this->conf( self::O_DEBUG ) ) {
				self::debug( 'Last request not done' );
				return;
			}
		}

		$i = 0;
		foreach ( $this->_queue as $k => $v ) {
			if ( ! empty( $v[ '_status' ] ) ) {
				continue;
			}

			self::debug( 'cron job [tag] ' . $k . ' [url] ' . $v[ 'url' ] . ( $v[ 'is_mobile' ] ? ' 📱 ' : '' ) . ' [UA] ' . $v[ 'user_agent' ] );

			$i ++;
			$res = $this->_send_req( $v[ 'url' ], $k, $v[ 'user_agent' ], $v[ 'is_mobile' ] );
			if ( ! $res ) { // Status is wrong, drop this this->_queue
				unset( $this->_queue[ $k ] );
				$this->save_queue( 'vpi', $this->_queue );

				if ( ! $continue ) {
					return;
				}

				if ( $i > 3 ) {
					GUI::print_loading( count( $this->_queue ), 'VPI' );
					return Router::self_redirect( Router::ACTION_VPI, self::TYPE_GEN );
				}

				continue;
			}

			// Exit queue if out of quota
			if ( $res === 'out_of_quota' ) {
				return;
			}

			$this->_queue[ $k ][ '_status' ] = 'requested';
			$this->save_queue( 'vpi', $this->_queue );
			self::debug( 'Saved to queue [k] ' . $k );

			// only request first one
			if ( ! $continue ) {
				return;
			}

			if ( $i > 3 ) {
				GUI::print_loading( count( $this->_queue ), 'VPI' );
				return Router::self_redirect( Router::ACTION_VPI, self::TYPE_GEN );
			}
		}
	}

	/**
	 * Prepare HTML from URL
	 *
	 * @since  4.7
	 */
	public function prepare_html( $request_url, $user_agent ) {
		$html = $this->cls( 'Crawler' )->self_curl( $request_url, $user_agent );
		self::debug2( 'self_curl result....', $html );


		$html = $this->cls( 'Optimizer' )->html_min( $html, true );
		// Drop <noscript>xxx</noscript>
		$html = preg_replace( '#<noscript>.*</noscript>#isU', '', $html );

		return $html;
	}

	/**
	 * Send to QC API to generate VPI
	 *
	 * @since  4.7
	 * @access private
	 */
	private function _send_req( $request_url, $queue_k, $user_agent, $is_mobile ) {
		$svc = Cloud::SVC_VPI;
		// Check if has credit to push or not
		$err = false;
		$allowance = $this->cls( 'Cloud' )->allowance( $svc, $err );
		if ( ! $allowance ) {
			self::debug( '❌ No credit: ' . $err );
			$err && Admin_Display::error( Error::msg( $err ) );
			return 'out_of_quota';
		}

		set_time_limit( 120 );

		// Update css request status
		self::save_summary( array( 'curr_request_vpi' => time() ), true );

		// Gather guest HTML to send
		$html = $this->prepare_html( $request_url, $user_agent );

		if ( ! $html ) {
			return false;
		}

		// Parse HTML to gather all CSS content before requesting
		$css = false;
		list( $css, $html ) = $this->cls( 'CSS' )->prepare_css( $html );

		if ( ! $css ) {
			self::debug( '❌ No css' );
			return false;
		}

		// Generate critical css
		$data = array(
			// 'type'			=> strtoupper( $type ), // Backward compatibility for v4.1-
			'url'			=> $request_url,
			'queue_k'		=> $queue_k,
			'user_agent'	=> $user_agent,
			'is_mobile'		=> $is_mobile ? 1 : 0, // todo:compatible w/ tablet
			'html'			=> $html,
			'css'			=> $css,
		);
		self::debug( 'Generating: ', $data );

		$json = Cloud::post( $svc, $data, 30 );
		if ( ! is_array( $json ) ) {
			return false;
		}

		// Unknown status, remove this line
		if ( $json[ 'status' ] != 'queued' ) {
			return false;
		}

		// Save summary data
		self::reload_summary();
		$this->_summary[ 'last_spent_vpi' ] = time() - $this->_summary[ 'curr_request_vpi' ];
		$this->_summary[ 'last_request_vpi' ] = $this->_summary[ 'curr_request_vpi' ];
		$this->_summary[ 'curr_request_vpi' ] = 0;
		self::save_summary();

		return true;
	}

	/**
	 * Handle all request actions from main cls
	 *
	 * @since  4.7
	 */
	public function handler() {
		$type = Router::verify_type();

		switch ( $type ) {
			case self::TYPE_GEN:
				self::cron( true );
				break;

			case self::TYPE_CLEAR_Q:
				$this->clear_q( 'vpi' );
				break;

			default:
				break;
		}

		Admin::redirect();
	}

}
