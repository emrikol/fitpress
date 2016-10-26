<?php
/**
 * Plugin Name: FitPress
 * Version: 0.2-alpha
 * Description: Publish your FitBit statistics on your WordPress blog
 * Author: Daniel Walmsley
 * Author URI: http://danwalmsley.com
 * Plugin URI: http://github.com/gravityrail/fitpress
 * Text Domain: fitpress
 * Domain Path: /languages
 *
 * @package  WordPress
 */

define( 'FITPRESS_CLIENT_STATE_KEY', 'this is super secret' );

/**
 * Primary FitPress class.
 */
class FitPress {
	/**
	 * FitPress class instance.
	 *
	 * @access protected
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Gets FitPress class instance.
	 *
	 * @access public
	 *
	 * @return object The FitPress class instance.
	 */
	public static function get_instance() {
		null === self::$instance and self::$instance = new self;
		return self::$instance;
	}

	/**
	 * Adds plugin init action.
	 *
	 * @access public
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Sets up plugin.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'fitpress_settings_page' ) );
		add_action( 'admin_init', array( $this, 'fitpress_register_settings' ) );
		add_action( 'show_user_profile', array( $this, 'fitpress_linked_accounts' ) );
		add_action( 'admin_post_fitpress_auth', array( $this, 'fitpress_auth' ) );
		add_action( 'admin_post_fitpress_auth_callback', array( $this, 'fitpress_auth_callback' ) );
		add_action( 'admin_post_fitpress_auth_unlink', array( $this, 'fitpress_auth_unlink' ) );
		add_shortcode( 'heartrate', array( $this, 'fitpress_shortcode_heartrate' ) );
		add_shortcode( 'steps', array( $this, 'fitpress_shortcode_steps' ) );
		wp_register_script( 'jsapi', 'https://www.google.com/jsapi' );
		add_filter( 'allowed_redirect_hosts' , array( $this, 'fitpress_allowed_redirect_hosts' ) , 10 );
	}

	/**
	 * Sets up object properties.
	 *
	 * @access public
	 *
	 * @param array $hosts Allowed redirect hosts.
	 *
	 * @return array Merged array of allowed hosts.
	 */
	public function fitpress_allowed_redirect_hosts( $hosts ) {
		$hosts[] = 'www.fitbit.com';
		$hosts[] = 'api.fitbit.com';
		return $hosts;
	}

	/**
	 * Registers Heartrate shortcode.
	 *
	 * @access public
	 *
	 * @param array $atts Shortcode attributes.
	 *
	 * @return string Shortcode HTML output.
	 */
	public function fitpress_shortcode_heartrate( $atts ) {
		$atts = shortcode_atts( array(
			'date' => null,
		), $atts );

		if ( null === $atts['date'] ) {
			$post = get_post( get_the_ID() );
			$atts['date'] = new DateTime( $post->post_date );
		}

		$fitbit = $this->fitbit_api();

		$result = $fitbit->get_heart_rate( $atts['date'] );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		$output = '<dl>';
		foreach ( $result->value->heartRateZones as $heart_rate_zone ) {
			$name = $heart_rate_zone->name;
			$minutes = $heart_rate_zone->minutes; // @codingStandardsIgnoreLine.
			$output .= '<dt>' . esc_html( $name ) . '</dt><dd>' . esc_html( $minutes ) . 'minutes</dd>';
		}
		$output .= '</dl>';
		return $output;
	}

	/**
	 * Registers Steps shortcode.
	 *
	 * @access public
	 *
	 * @param array $atts Shortcode attributes.
	 *
	 * @return string Shortcode HTML output.
	 */
	public function fitpress_shortcode_steps( $atts ) {
		// We need a unique ID just in case the shortcode is used more than once on a page.
		static $instance = 1;

		$atts = shortcode_atts( array(
			'date' => null,
			'period' => '7d',
		), $atts, 'steps' );

		if ( null === $atts['date'] ) {
			$post = get_post( get_the_ID() );
			$atts['date'] = new DateTime( $post->post_date );
		}

		$fitbit = $this->fitbit_api();

		$steps = $fitbit->get_time_series( 'steps', $atts['date'], $atts['period'] );

		if ( is_wp_error( $steps ) ) {
			return $steps->get_error_message();
		}

		array_walk( $steps, function ( &$v, $k ) {
			$v = array(
				$v->dateTime, // @codingStandardsIgnoreLine.
				intval( $v->value ),
			);
		} );

		// Add header.
		array_unshift( $steps, array( 'Date', 'Steps' ) );

		$steps_json = wp_json_encode( $steps );

		$inline_script = <<<ENDHTML
google.load('visualization', '1.0', {'packages':['corechart', 'bar']});
google.setOnLoadCallback(function() {
	var data = google.visualization.arrayToDataTable({$steps_json});
	var options = {
		title: 'Steps per day',
		hAxis: {
			title: 'Date',
			format: 'Y-m-d'
		},
		vAxis: {
			title: 'Steps'
		}
	};
	var chart = new google.visualization.ColumnChart(document.getElementById('chart_div-{$instance}'));
	chart.draw(data, options);
});
ENDHTML;
		wp_enqueue_script( 'jsapi' );
		wp_add_inline_script( 'jsapi', $inline_script );
		$div_container = '<div id="chart_div-' . esc_attr( $instance ) . '"></div>';

		$instance++;
		return $div_container;
	}

	/**
	 * Displays the user account linking status.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function fitpress_linked_accounts() {
		$user_id = get_current_user_id();

		$fitpress_credentials = $this->fitpress_get_user_meta( $user_id, 'fitpress_credentials' );
		$last_error = $this->fitpress_get_user_meta( $user_id, 'fitpress_last_error' );

		// list the wpoa_identity records:.
		echo '<div id="fitpress-linked-accounts">';
		echo '<h3>FitBit Account</h3>';
		if ( ! $fitpress_credentials ) {
			echo '<p>You have not linked your FitBit account.</p>';
			echo wp_kses_post( $this->fitpress_login_button() );
		} else {
			$unlink_url = admin_url( 'admin-post.php?action=fitpress_auth_unlink' );
			$name = $fitpress_credentials['name'];
			echo '<p>Linked account ' . esc_html( $name ) . ' - <a href="' . esc_url( $unlink_url ) . '">Unlink</a>';
		}
		if ( $last_error ) {
			echo '<p><strong>ERROR: </strong> There was an error connecting your account: ' . wp_kses_post( $last_error ) . '</p>';
		}

		echo '</div>';

		// Error was shown, delete.
		$this->fitpress_delete_user_meta( $user_id, 'fitpress_last_error' );
	}

	/**
	 * Gets FitBit oAuth2 client.
	 *
	 * @access public
	 *
	 * @return FitBit_OAuth2_Client
	 */
	public static function get_fitbit_oauth2_client() {
		require_once( 'inc/fitpress-oauth2-client.php' );
		$user_id = get_current_user_id();
		$redirect_url = admin_url( 'admin-post.php?action=fitpress_auth_callback' );
		return new FitBit_OAuth2_Client( get_option( 'fitpress_api_id' ), get_option( 'fitpress_api_secret' ), esc_url_raw( $redirect_url ), FITPRESS_CLIENT_STATE_KEY );
	}

	/**
	 * Gets FitBit API client.
	 *
	 * @access public
	 *
	 * @param string $auth_token The oAuth2 Authorization token.
	 *
	 * @return FitBit_API_Client
	 */
	public function fitbit_api( $auth_token = null ) {
		require_once( 'inc/fitpress-api.php' );
		$user_id = get_current_user_id();
		$fitpress_credentials = $this->fitpress_get_user_meta( $user_id, 'fitpress_credentials' );

		if ( ! $auth_token && $fitpress_credentials ) {
			$auth_token = $fitpress_credentials['token'];
		}

		$client = new FitBit_API_Client( $auth_token );

		return $client;
	}

	/**
	 * Redirects to FitBit Authorization URL.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function fitpress_auth() {
		$oauth_client = $this->get_fitbit_oauth2_client();
		$auth_url = $oauth_client->generate_authorization_url( get_current_user_id() );
		wp_safe_redirect( $auth_url );
		exit;
	}

	/**
	 * Redirects to FitBit Unauthorization URL.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function fitpress_auth_unlink() {
		$user_id = get_current_user_id();
		$this->fitpress_delete_user_meta( $user_id, 'fitpress_credentials' );
		$this->redirect_to_user( $user_id );
	}

	/**
	 * Retrieves FitBit user metadata.
	 *
	 * @access public
	 *
	 * @param int    $user_id  The WordPress User ID.
	 * @param string $meta_key The metadata key to be returned.
	 *
	 * @return mixed Metadata on success, false on failure.
	 */
	public static function fitpress_get_user_meta( $user_id, $meta_key ) {
		$fitpress_options = get_option( 'fitpress' );

		if ( isset( $fitpress_options['user_meta'] ) && is_array( $fitpress_options['user_meta'] ) ) {
			$fitpress_user_meta = $fitpress_options['user_meta'];

			if ( isset( $fitpress_user_meta[ $user_id ] ) && is_array( $fitpress_user_meta[ $user_id ] ) ) {
				if ( isset( $fitpress_user_meta[ $user_id ][ $meta_key ] ) ) {
					return $fitpress_user_meta[ $user_id ][ $meta_key ];
				}
			}
		}
		return false;
	}

	/**
	 * Updates FitBit user metadata.
	 *
	 * @access public
	 *
	 * @param int    $user_id    The WordPress User ID.
	 * @param string $meta_key   The metadata key to store.
	 * @param mixed  $meta_value The metadata value to store.
	 *
	 * @return bool True if option value has changed, false if not or if update failed.
	 */
	public static function fitpress_update_user_meta( $user_id, $meta_key, $meta_value ) {
		$fitpress_options = get_option( 'fitpress' );

		if ( isset( $fitpress_options['user_meta'] ) && is_array( $fitpress_options['user_meta'] ) ) {
			$fitpress_user_meta = $fitpress_options['user_meta'];
		} else {
			$fitpress_user_meta = array();
		}

		$fitpress_user_meta[ $user_id ][ $meta_key ] = $meta_value;

		$fitpress_options['user_meta'] = $fitpress_user_meta;

		return update_option( 'fitpress', $fitpress_options, false );
	}

	/**
	 * Delets FitBit user metadata.
	 *
	 * @access public
	 *
	 * @param int    $user_id  The WordPress User ID.
	 * @param string $meta_key The metadata key to delete.
	 *
	 * @return bool True if option value has changed, false if not or if update failed.
	 */
	public static function fitpress_delete_user_meta( $user_id, $meta_key ) {
		$fitpress_options = get_option( 'fitpress' );

		if ( isset( $fitpress_options['user_meta'] ) && is_array( $fitpress_options['user_meta'] ) ) {
			$fitpress_user_meta = $fitpress_options['user_meta'];
		} else {
			$fitpress_user_meta = array();
		}

		unset( $fitpress_user_meta[ $user_id ][ $meta_key ] );

		$fitpress_options['user_meta'] = $fitpress_user_meta;

		return update_option( 'fitpress', $fitpress_options, false );
	}

	/**
	 * Callback function for the FitBit API authorization.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function fitpress_auth_callback() {
		$user_id = get_current_user_id();
		$oauth_client = $this->get_fitbit_oauth2_client();
		$auth_response = $oauth_client->process_authorization_grant_request( $user_id );

		if ( is_wp_error( $auth_response ) ) {
			die( wp_kses_post( $auth_response->get_error_message() ) );
		}

		$access_token = $auth_response->access_token;
		$user_info = $this->fitbit_api( $access_token )->get_current_user_info();

		if ( is_wp_error( $user_info ) ) {
			$this->fitpress_update_user_meta( $user_id, 'fitpress_last_error', $user_info->get_error_message() );
			$this->redirect_to_user( $user_id );
		}

		$auth_meta = array(
			'token' => $access_token,
			'refresh_token' => $auth_response->refresh_token,
			'name' => $user_info->fullName, // @codingStandardsIgnoreLine.
		);

		$this->fitpress_update_user_meta( $user_id, 'fitpress_credentials', $auth_meta );

		$this->redirect_to_user( $user_id );
	}

	/**
	 * Generates and returns a login button for FitPress.
	 *
	 * @access public
	 *
	 * @return string The HTML for the login button.
	 */
	public function fitpress_login_button() {
		$url = admin_url( 'admin-post.php?action=fitpress_auth' );

		$html = '';
		$html .= '<a id="fitpress-login-fitbit" class="fitpress-login-button" href="' . esc_url( $url ) . '">';
		$html .= 'Link my FitBit account';
		$html .= '</a>';
		return $html;
	}

	/**
	 * Registers all settings that have been defined at the top of the plugin.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function fitpress_register_settings() {
		register_setting( 'fitpress_settings', 'fitpress_api_id' );
		register_setting( 'fitpress_settings', 'fitpress_api_secret' );
		register_setting( 'fitpress_settings', 'fitpress_token_override' );
	}

	/**
	 * Add the main settings page.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function fitpress_settings_page() {
		add_options_page( 'FitPress Options', 'FitPress', 'manage_options', 'FitPress', array( $this, 'fitpress_settings_page_content' ) );
	}

	/**
	 * Render the main settings page content.
	 *
	 * @access public
	 *
	 * @return void
	 */
	public function fitpress_settings_page_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
		}
		$blog_url = rtrim( site_url(), '/' ) . '/';
		include( 'inc/fitpress-settings.php' );
	}

	/**
	 * Redirects to the edit user page.
	 *
	 * @access private
	 *
	 * @param int $user_id The WordPress User ID.
	 *
	 * @return void
	 */
	private function redirect_to_user( $user_id ) {
		wp_safe_redirect( get_edit_user_link( $user_id ), 301 );
		exit;
	}
}

FitPress::get_instance();
