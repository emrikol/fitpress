<?php
/*
Plugin Name: FitPress
Version: 0.2-alpha
Description: Publish your FitBit statistics on your WordPress blog
Author: Daniel Walmsley
Author URI: http://danwalmsley.com
Plugin URI: http://github.com/gravityrail/fitpress
Text Domain: fitpress
Domain Path: /languages
*/

define( 'FITPRESS_CLIENT_STATE_KEY', 'this is super secret' );

class FitPress {
	// singleton class pattern:
	protected static $instance = null;
	public static function get_instance() {
		null === self::$instance and self::$instance = new self;
		return self::$instance;
	}

	function __construct() {
		// hook activation and deactivation for the plugin
		add_action( 'init', array( $this, 'init' ) );
	}

	function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'fitpress_init_styles' ) );
		add_action( 'admin_menu', array( $this, 'fitpress_settings_page' ) );
		add_action( 'admin_init', array( $this, 'fitpress_register_settings' ) );
		add_action( 'show_user_profile', array( $this, 'fitpress_linked_accounts' ) );
		add_action( 'admin_post_fitpress_auth', array( $this, 'fitpress_auth' ) );
		add_action( 'admin_post_fitpress_auth_callback', array( $this, 'fitpress_auth_callback' ) );
		add_action( 'admin_post_fitpress_auth_unlink', array( $this, 'fitpress_auth_unlink' ) );
		add_shortcode( 'heartrate', array( $this, 'fitpress_shortcode_heartrate' ) );
		add_shortcode( 'steps', array( $this, 'fitpress_shortcode_steps' ) );
		wp_register_script( 'jsapi', 'https://www.google.com/jsapi' );
		add_action( 'wp_enqueue_scripts', array( $this, 'fitpress_scripts' ) );
	}

	function fitpress_scripts() {
		wp_enqueue_script( 'jsapi' );
	}

	/**
	 * Shortcodes
	 **/
	function fitpress_shortcode_heartrate( $atts ) {
		$atts = shortcode_atts( array(
			'date' => 'today',
		), $atts );

		$atts = $this->fitpress_shortcode_base( $atts );

		$fitbit = $this->get_fitbit_client();

		$result = $fitbit->get_heart_rate( $atts['date'] );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		$output = '<dl>';
		foreach ( $result->value->heartRateZones as $heartRateZone ) {
			$name = $heartRateZone->name;
			$minutes = $heartRateZone->minutes; // @codingStandardsIgnoreLine
			$output .= '<dt>' . esc_html( $name ) . '</dt><dd>' . esc_html( $minutes ) . 'minutes</dd>';
		}
		$output .= '</dl>';
		return $output;
	}

	function fitpress_shortcode_steps( $atts ) {
		$atts = shortcode_atts( array(
			'date' => 'today',
		), $atts );

		$atts = $this->fitpress_shortcode_base( $atts );

		$fitbit = $this->get_fitbit_client();

		$steps = $fitbit->get_time_series( 'steps', $atts['date'], '7d' );

		if ( is_wp_error( $steps ) ) {
			return $steps->get_error_message();
		}

		array_walk( $steps, function ( &$v, $k ) { $v = array( $v->dateTime, intval( $v->value ) ); } );

		// add header
		array_unshift( $steps, array( 'Date', 'Steps' ) );

		$steps_json = json_encode( $steps );

		$output = '';
		$output .= <<<ENDHTML
<script type="text/javascript">
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
		var chart = new google.visualization.ColumnChart(document.getElementById('chart_div'));
		chart.draw(data, options);
	});

</script>
<div id="chart_div"></div>
ENDHTML;

		return $output;
	}

	// common functionality for shortcodes
	function fitpress_shortcode_base( $atts ) {
		$atts = shortcode_atts( array(
		    'date' => null,
		), $atts );

		// we only compute this if not supplied because it's expensive to compute
		if ( null === $atts['date'] ) {
			$post = get_post( get_the_ID() );
			$atts['date'] = new DateTime( $post->post_date );
		}

		return $atts;
	}


	/**
	 * CSS and javascript
	 **/

	function fitpress_init_styles() {
		wp_enqueue_style( 'fitpress-style', plugin_dir_url( __FILE__ ) . 'fitpress.css', array() );
	}

	/**
	 * User profile buttons
	 **/

	function fitpress_linked_accounts() {
		$user_id = get_current_user_id();

		$fitpress_credentials = get_user_meta( $user_id, 'fitpress_credentials', true );
		$last_error = get_user_meta( $user_id, 'fitpress_last_error', true );

		// list the wpoa_identity records:
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

		// Error was shown, delete
		delete_user_meta( $user_id, 'fitpress_last_error' );
	}

	private function get_fitbit_oauth2_client() {
		require_once( 'fitpress-oauth2-client.php' );
		$user_id = get_current_user_id();
		$redirect_url = admin_url( 'admin-post.php?action=fitpress_auth_callback' );
		return new FitBit_OAuth2_Client( get_option( 'fitpress_api_id' ), get_option( 'fitpress_api_secret' ), esc_url_raw( $redirect_url ), FITPRESS_CLIENT_STATE_KEY );
	}

	function get_fitbit_client( $access_token = null ) {
		require_once( 'fitpress-oauth2-client.php' );
		$user_id = get_current_user_id();
		$fitpress_credentials = get_user_meta( $user_id, 'fitpress_credentials', true );

		if ( ! $access_token && $fitpress_credentials ) {
			$access_token = $fitpress_credentials['token'];
		}

		$client = new FitBit_API_Client( $access_token );

		return $client;
	}

	//redirect out to FitBit authorization URL
	function fitpress_auth() {
		$oauth_client = $this->get_fitbit_oauth2_client();
		$auth_url = $oauth_client->generate_authorization_url( get_current_user_id() );
		wp_redirect( $auth_url );
		exit;
	}

	//delete stored fitbit token
	function fitpress_auth_unlink() {
		$user_id = get_current_user_id();
		delete_user_meta( $user_id, 'fitpress_credentials' );
		$this->redirect_to_user( $user_id );
	}

	function fitpress_auth_callback() {
		$user_id = get_current_user_id();
		$oauth_client = $this->get_fitbit_oauth2_client();
		$auth_response = $oauth_client->process_authorization_grant_request( $user_id );

		if ( is_wp_error( $auth_response ) ) {
			die( wp_kses_post( $auth_response->get_error_message() ) );
		}

		$access_token = $auth_response->access_token;
		$user_info = $this->get_fitbit_client( $access_token )->get_current_user_info();

		if ( is_wp_error( $user_info ) ) {
			update_user_meta( $user_id, 'fitpress_last_error', $user_info->get_error_message() );
			$this->redirect_to_user( $user_id );
		}

		update_user_meta( $user_id, 'fitpress_credentials', array( 'token' => $access_token, 'name' => $user_info->fullName ) );

		$this->redirect_to_user( $user_id );
	}

	function fitpress_login_button() {
		$url = admin_url( 'admin-post.php?action=fitpress_auth' );

		// generates and returns a login button for FitPress:
		$html = '';
		$html .= '<a id="fitpress-login-fitbit" class="fitpress-login-button" href="' . esc_url( $url ) . '">';
		$html .= 'Link my FitBit account';
		$html .= '</a>';
		return $html;
	}

	/**
	 * Plugin settings
	 **/

	// registers all settings that have been defined at the top of the plugin:
	function fitpress_register_settings() {
		register_setting( 'fitpress_settings', 'fitpress_api_id' );
		register_setting( 'fitpress_settings', 'fitpress_api_secret' );
		register_setting( 'fitpress_settings', 'fitpress_token_override' );
	}

	// add the main settings page:
	function fitpress_settings_page() {
		add_options_page( 'FitPress Options', 'FitPress', 'manage_options', 'FitPress', array( $this, 'fitpress_settings_page_content' ) );
	}

	// render the main settings page content:
	function fitpress_settings_page_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.' ) );
		}
		$blog_url = rtrim( site_url(), '/' ) . '/';
		include( 'fitpress-settings.php' );
	}

	/**
	 * Private functions
	 */

	private function redirect_to_user( $user_id ) {
		wp_safe_redirect( get_edit_user_link( $user_id ), 301 );
		exit;
	}
}

FitPress::get_instance();
