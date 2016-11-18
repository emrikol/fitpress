<?php
/**
 * FitBit_OAuth2_Client
 *
 * @category Class
 * @package  WordPress
 */

/**
 * Generic functions to interact with the FitBit API
 *
 * @param string $client_id           FitBit Application Client ID
 * @param string $client_secret       FitBit Application Client Secret
 * @param string $client_redirect_uri FitBit Application Callback URI
 * @param string $state_key           A secret (not shared with anyone) used to encode the session state
 * @param int    $time_window_length  About how long the session state is valid for in seconds.
 *                                    That is, the time interval users have between clicking
 *                                    the "Connect to FitBit" button and coming back to our site.
 */
class FitBit_OAuth2_Client {
	const AUTHORIZATION_URL = FitBit_API_Client::SITE_ROOT . '/oauth2/authorize';
	const TOKEN_URL = FitBit_API_Client::API_ROOT . '/oauth2/token';

	const EMPTY_CODE = 1;
	const EMPTY_STATE = 2;
	const INVALID_STATE = 4;
	const DEFAULT_TIME_WINDOW = HOUR_IN_SECONDS * 6;
	const OAUTH_SCOPES = [ 'activity', 'heartrate', 'location', 'profile', 'settings', 'sleep', 'social', 'weight' ];

	/**
	 * FitBit Application Client ID.
	 *
	 * @access private
	 * @var string
	 */
	private $id = '';

	/**
	 * FitBit Application Client Secret.
	 *
	 * @access private
	 * @var string
	 */
	private $secret = '';

	/**
	 * FitBit Application Callback URI.
	 *
	 * @access private
	 * @var string
	 */
	private $redirect_uri = '';

	/**
	 * A secret (not shared with anyone) used to encode the session state.
	 *
	 * @access private
	 * @var string
	 */
	private $state_key = '';

	/**
	 * About how long the session state is valid for in seconds.
	 *
	 * That is, the time interval users have between clicking
	 * the "Connect to FitBit" button and coming back to our site.
	 *
	 * @access private
	 * @var int
	 */
	private $time_window_length = 0;

	/**
	 * Time in seconds to wait for HTTP response for token request.
	 *
	 * @access public
	 * @var int
	 */
	public $http_timeout = 6;

	/**
	 * Sets up object properties.
	 *
	 * @access public
	 *
	 * @param string $client_id           FitBit Application Client ID.
	 * @param string $client_secret       FitBit Application Client Secret.
	 * @param string $client_redirect_uri FitBit Application Callback URI.
	 * @param string $state_key           A secret (not shared with anyone) used to encode the session state.
	 * @param int    $time_window_length  About how long the session state is valid for in seconds.
	 *                                    That is, the time interval users have between clicking
	 *                                    the "Connect to FitBit" button and coming back to our site.
	 *
	 * @return void
	 */
	public function __construct( $client_id, $client_secret, $client_redirect_uri, $state_key, $time_window_length = self::DEFAULT_TIME_WINDOW ) {
		require_once( 'fitpress-api.php' );

		$this->id = $client_id;
		$this->secret = $client_secret;
		$this->redirect_uri = $client_redirect_uri;
		$this->state_key = $state_key;
		$this->time_window_length = $time_window_length;
	}

	/**
	 * Generates Authorization URL
	 *
	 * @access public
	 *
	 * @param string $user_id Some unique identifier for the user or session.
	 *
	 * @return string FitBit Authorization URL.
	 */
	public function generate_authorization_url( $user_id ) {
		$query = http_build_query( array(
			'response_type' => 'code',
			'client_id' => $this->id,
			'state' => $this->generate_state( $user_id ),
			'redirect_uri' => $this->redirect_uri,
			'scope' => join( ' ', self::OAUTH_SCOPES ),
		) );

		return sprintf(
			'%s?%s',
			self::AUTHORIZATION_URL,
			$query
		);
	}

	/**
	 * Process the authorization grant request.
	 *
	 * @access public
	 *
	 * @param string $user_id Some unique identifier for the user or session.
	 *
	 * @throws Exception If the expected GET parameters are missing or if the encoded session state is invalid.
	 *
	 * @return Object|WP_Error API Object on success.  WP_Error on fail.
	 */
	public function process_authorization_grant_request( $user_id ) {
		$request = $this->get_request();

		if ( empty( $request['code'] ) ) {
			throw new Exception( 'Missing Authorization Code', self::EMPTY_CODE );
		}

		if ( empty( $request['state'] ) ) {
			throw new Exception( 'Missing Authorization State', self::EMPTY_STATE );
		}

		if ( ! $this->verify_state( $user_id, $request['state'] ) ) {
			throw new Exception( 'Incorrect Authorization State', self::INVALID_STATE );
		}

		$token_response = $this->get_access_token( $request['code'] );

		if ( 200 !== $token_response->http_response_code ) {
			return new WP_Error( $token_response->errors[0]->errorType, $token_response->errors[0]->message );
		} else {
			return $token_response;
		}
	}

	/**
	 * Generates the state key.
	 *
	 * @access private
	 *
	 * @param string $user_id            Some unique identifier for the user or session.
	 * @param string $time_window_offset Time window offset.
	 *
	 * @return string Hashed state key.
	 */
	private function generate_state( $user_id, $time_window_offset = 0 ) {
		return hash_hmac(
			'md5',
			sprintf(
				'%s|%d',
				$user_id,
				floor( time() / $this->time_window_length ) - $time_window_offset
			),
			$this->state_key
		);
	}

	/**
	 * Verifies the state for the user.
	 *
	 * @access private
	 *
	 * @param string $user_id Some unique identifier for the user or session.
	 * @param string $state   The state to be verified.
	 *
	 * @return bool True if the state is verfied, otherwise false.
	 */
	private function verify_state( $user_id, $state ) {
		$verified = 0;
		$verified |= (int) hash_equals( $state, $this->generate_state( $user_id ) );
		$verified |= (int) hash_equals( $state, $this->generate_state( $user_id, 1 ) );
		return (bool) $verified;
	}

	/**
	 * Normalizes the $_GET variable.
	 *
	 * @access private
	 *
	 * @return var The normalized $_GET variable.
	 */
	private function get_request() {
		// If this is running inside WordPress, we need to unslash $_GET.
		if ( function_exists( 'wp_unslash' ) ) {
			return wp_unslash( $_GET ); // Input Var Ok.
		}

		return $_GET; // Input Var Ok.
	}

	/**
	 * Retrieves access token.
	 *
	 * @access private
	 *
	 * @param string $authorization_code The oAuth2 Authorization Code from the authorization grant request.
	 *
	 * @return String| Access token on success, API Object.  WP_Error on fail.
	 */
	private function get_access_token( $authorization_code ) {
		$args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->id . ':' . $this->secret ),
			),
			'body' => array(
				'client_id' => $this->id,
				'client_secret' => $this->secret,
				'redirect_uri' => $this->redirect_uri,
				'code' => $authorization_code,
				'grant_type' => 'authorization_code',
			),
		);

		$response = wp_remote_post( self::TOKEN_URL, $args );

		if ( is_wp_error( $response ) ) {
			$return = $response;
		} else {
			$return = json_decode( $response['body'] );
			$return->http_response_code = $response['response']['code'];
		}

		return $return;
	}

	/**
	 * Retrieves access token.
	 *
	 * @access public
	 *
	 * @param string $auth_token The oAuth2 Authorization token.
	 * @param string $user_id    Some unique identifier for the user or session.
	 *
	 * @return Object|WP_Error API Object on success.  WP_Error on fail.
	 */
	public function refresh_access_token( $auth_token, $user_id ) {
		$fitpress_credentials = FitPress::fitpress_get_user_meta( $user_id, 'fitpress_credentials' );
		$refresh_token = $fitpress_credentials['refresh_token'];

		$args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->id . ':' . $this->secret ),
			),
			'body' => array(
				'grant_type' => 'refresh_token',
				'refresh_token' => $refresh_token,
			),
		);

		$response = wp_remote_post( self::TOKEN_URL, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		} else {
			$return = json_decode( $response['body'] );

			$fitpress_credentials['token'] = $return->access_token;
			$fitpress_credentials['refresh_token'] = $return->refresh_token;

			FitPress::fitpress_update_user_meta( $user_id, 'fitpress_credentials', $fitpress_credentials );

			$return->http_response_code = $response['response']['code'];
		}

		return $return;
	}
}
