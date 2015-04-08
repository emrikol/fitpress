<?php
/*
Plugin Name: FitPress
Version: 0.1-alpha
Description: Publish your FitBit statistics on your WordPress blog
Author: Daniel Walmsley
Author URI: http://danwalmsley.com
Plugin URI: http://github.com/gravityrail/wp-fitpress-plugin
Text Domain: fitpress
Domain Path: /languages
*/

Class FitPress {
	// singleton class pattern:
	protected static $instance = NULL;
	public static function get_instance() {
		NULL === self::$instance and self::$instance = new self;
		return self::$instance;
	}

	function __construct() {
		// hook activation and deactivation for the plugin
		add_action('init', array($this, 'init'));
	}

	function init() {
		add_action('admin_enqueue_scripts', array($this, 'fitpress_init_styles'));
		add_action('admin_menu', array($this, 'fitpress_settings_page'));
		add_action('admin_init', array($this, 'fitpress_register_settings'));
		add_action('show_user_profile', array($this, 'fitpress_linked_accounts'));
		add_action('admin_post_fitpress_auth', array($this, 'fitpress_auth'));
		add_action('admin_post_fitpress_auth_callback', array($this, 'fitpress_auth_callback'));
		add_action('admin_post_fitpress_auth_unlink', array($this, 'fitpress_auth_unlink'));
		// add_shortcode( 'fitbit', array($this, 'fitpress_shortcode') );
		add_shortcode( 'heartrate', array($this, 'fitpress_shortcode_heartrate') );
		add_shortcode( 'steps', array($this, 'fitpress_shortcode_steps') );
		wp_register_script( 'jsapi', 'https://www.google.com/jsapi' );
		add_action( 'wp_enqueue_scripts', array($this, 'fitpress_scripts') );
	}

	function fitpress_scripts() {
		wp_enqueue_script( 'jsapi' );
	}

	/**
	 * Shortcodes
	 **/ 

	//[heartrate]
	function fitpress_shortcode_heartrate( $atts ){
		$atts = $this->fitpress_shortcode_base( $atts );

		$fitbit = $this->get_fitbit_client();
		error_log(print_r($atts, true));
		try {
			$heart_rates = $fitbit->getHeartRate($atts['date'])->average[0];
			$output = '<dl>';
			foreach ($heart_rates->heartAverage as $heartAverage) {
				$title = $heartAverage->tracker->asXML();
				$value = $heartAverage->heartRate->asXML();
				$output .= "<dt>{$title}</dt><dd>{$value}</dd>";
			}
			$output .= '</dl>';
			return $output;
		} catch(Exception $e) {
			return print_r($e->getMessage(), true);
		}
	}

	//[steps]
	function fitpress_shortcode_steps( $atts ){
		$atts = $this->fitpress_shortcode_base( $atts );

		$fitbit = $this->get_fitbit_client();

		try {
			$steps = $fitbit->getTimeSeries('steps', $atts['date'], '7d');

			array_walk($steps, function (&$v, $k) { $v = array($v->dateTime, intval($v->value)); });

			// add header
			array_unshift($steps, array('Date', 'Steps'));

			$steps_json = json_encode($steps);

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

			// $output = print_r($steps, true);
			return $output;
		} catch(Exception $e) {
			return print_r($e->getMessage(), true);
		}
	}

	// common functionality for shortcodes
	function fitpress_shortcode_base( $atts ) {
		$atts = shortcode_atts( array(
		    'date' => null
		), $atts );

		// we only compute this if not supplied because it's expensive to compute
		if ( $atts['date'] == null ) {
			$post = get_post(get_the_ID());
			$atts['date'] = new DateTime($post->post_date);
			error_log("Reparsed date from ".$post->post_date." to ".print_r($atts['date'], true));
		}

		return $atts;
	}


	/**
	 * CSS and javascript
	 **/

	function fitpress_init_styles() {
		wp_enqueue_style('fitpress-style', plugin_dir_url( __FILE__ ) . 'fitpress.css', array());
	}

	/**
	 * User profile buttons
	 **/

	function fitpress_linked_accounts() {
		$user_id = get_current_user_id();

		$fitpress_credentials = get_user_meta($user_id, 'fitpress_credentials', true);
		$last_error = get_user_meta($user_id, 'fitpress_last_error', true);

		// list the wpoa_identity records:
		echo "<div id='fitpress-linked-accounts'>";
		echo "<h3>FitBit Account</h3>";
		if ( ! $fitpress_credentials ) {
			echo "<p>You have not linked your FitBit account.</p>";
			echo $this->fitpress_login_button();
		} else {
			$unlink_url = admin_url('admin-post.php?action=fitpress_auth_unlink');
			$cred_str = print_r($fitpress_credentials, true);
			echo "<p>Linked with ID {$cred_str} - <a href='{$unlink_url}'>Unlink</a>";
		}
		if ( $last_error ) {
			echo "<p>There was an error connecting your account: {$last_error}</p>";
		}

		echo "</div>";
	}

	function get_fitbit_client() {
		include_once('fitbitphp/fitbitphp.php');
		$user_id = get_current_user_id();
		$client = new FitBitPHP(get_option('fitpress_api_id'), get_option('fitpress_api_secret'));
		$fitpress_credentials = get_user_meta( $user_id, 'fitpress_credentials', true );

		// if we have credentials, use them
		if ( $fitpress_credentials ) {
			$client->setOAuthDetails($fitpress_credentials['token'], $fitpress_credentials['secret']);
		} elseif ( $client->sessionStatus() == 2 ) {
			// store new credentials
			$fitpress_credentials = array(
				'token' => $_SESSION['fitbit_Token'],
				'secret' => $_SESSION['fitbit_Secret']
			);

			update_user_meta( get_current_user_id(), 'fitpress_credentials', $fitpress_credentials );
		}
		return $client;
	}

	//redirect out to FitBit API
	function fitpress_auth() {
		try {
			$client = $this->fitpress_init_session();
			delete_user_meta( $user_id, 'fitpress_last_error' );
			if ( $client->sessionStatus() == 2 ) {
				// already authenticated
				wp_redirect( get_edit_user_link( get_current_user_id() ), 301 );
				exit;	
			}
		} catch(OAuthException $e) {
			error_log($e->getMessage());
			update_user_meta( $user_id, 'fitpress_last_error', $e->getMessage() );
			wp_redirect( get_edit_user_link( get_current_user_id() ), 301 );
			exit;
		}
	}

	function fitpress_auth_unlink() {
		$user_id = get_current_user_id();
		delete_user_meta( $user_id, 'fitpress_credentials' );
		wp_redirect( get_edit_user_link( $user_id ), 301 );
		exit;
	}

	function fitpress_init_session() {
		$redirect_url = admin_url('admin-post.php?action=fitpress_auth_callback');
		$client = $this->get_fitbit_client();
		$result = $client->initSession($redirect_url);
		if ( $result == 2 ) {

		}
		return $client;
	}

	function fitpress_auth_callback() {
		$user_id = get_current_user_id();
		$client = $this->fitpress_init_session();
		wp_redirect( get_edit_user_link( $user_id ), 301 );
		exit;
	}

	function fitpress_login_button() {
		$url = admin_url('admin-post.php?action=fitpress_auth');

		// generates and returns a login button for FitPress:
		$html = "";
		$html .= "<a id='fitpress-login-fitbit' class='fitpress-login-button' href='{$url}'>";
		$html .= "Link my FitBit account";
		$html .= "</a>";
		return $html;
	
	}

	/**
	 * Plugin settings
	 **/

	// registers all settings that have been defined at the top of the plugin:
	function fitpress_register_settings() {
		register_setting('fitpress_settings', 'fitpress_api_id');
		register_setting('fitpress_settings', 'fitpress_api_secret');
		register_setting('fitpress_settings', 'fitpress_token_override');
	}

	// add the main settings page:
	function fitpress_settings_page() {
		add_options_page( 'FitPress Options', 'FitPress', 'manage_options', 'FitPress', array($this, 'fitpress_settings_page_content') );
	}

	// render the main settings page content:
	function fitpress_settings_page_content() {
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}
		$blog_url = rtrim(site_url(), "/") . "/";
		include 'fitpress-settings.php';
	}
}

FitPress::get_instance();
?>