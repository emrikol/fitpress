<?php

class FitBit_API_Client {
	const SITE_ROOT = 'https://www.fitbit.com';
	const API_ROOT = 'https://api.fitbit.com';
	private $auth_token = '';

	public function __construct( $auth_token ) {
		$this->auth_token = $auth_token;
	}

	public function get_current_user_info() {
		$data = $this->get( '/1/user/-/profile.json' );

		if ( isset( $data->errors ) ) {
			return new WP_Error( $data->errors[0]->errorType, $data->errors[0]->message );
		}

		return $data->user;
	}

	public function get_heart_rate( $date ) {
		$data = $this->get( '/1/user/-/activities/heart/date/' . rawurlencode( $date ) . '/1d.json' );
		if ( isset( $data->errors ) ) {
			return new WP_Error( $data->errors[0]->errorType, $data->errors[0]->message );
		}
		return $data->{'activities-heart'}[0];
	}

	public function get_time_series( $series_type, $end_date, $range ) {
		$data = $this->get( '/1/user/-/activities/' . rawurlencode( $series_type ) . '/date/' . rawurlencode( $end_date ) . '/' . rawurlencode( $range ) . '.json' );
		if ( isset( $data->errors ) ) {
			return new WP_Error( $data->errors[0]->errorType, $data->errors[0]->message );
		}
		return $data->{"activities-$series_type"};
	}

	public function post( $endpoint, $fields = array() ) {
		$url = self::API_ROOT . $endpoint;

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->auth_token,
			),
			'body' => $fields,
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			echo 'Something went wrong: ' . esc_html( $error_message );
			$return = (object) array();
		} else {
			$return = json_decode( $response['body'] );
			$return->http_response_code = $response['response']['code'];
		}

		return $return;
	}

	/**
	 * @param string $endpoint, e.g. "/me"
	 * @param array $query, e.g. array('fields' => 'ID,title')
	 */
	public function get( $endpoint, $query = null ) {
		$query = ( is_array( $query ) ) ? http_build_query( $query ) : $query;

		$url = self::API_ROOT . $endpoint;

		if ( $query ) {
			$url = $url . '?' . $query;
		}

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->auth_token,
			),
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			echo 'Something went wrong: ' . esc_html( $error_message );
			$return = (object) array();
		} else {
			$return = json_decode( $response['body'] );
			$return->http_response_code = $response['response']['code'];
		}

		return $return;
	}
}

class FitBit_OAuth2_Client {
	const AUTHORIZATION_URL = FitBit_API_Client::SITE_ROOT . '/oauth2/authorize';
	const TOKEN_URL = FitBit_API_Client::API_ROOT . '/oauth2/token';

	const EMPTY_CODE = 1;
	const EMPTY_STATE = 2;
	const INVALID_STATE = 4;
	const DEFAULT_TIME_WINDOW = HOUR_IN_SECONDS * 6;
	const OAUTH_SCOPES = [ 'activity', 'heartrate', 'location', 'profile', 'settings', 'sleep', 'social', 'weight' ];

	private $id = '';
	private $secret = '';
	private $redirect_uri = '';
	private $state_key = '';
	private $time_window_length = 0;

	/**
	 * @var int Time in seconds to wait for HTTP response for token request
	 */
	public $http_timeout = 6;

	/**
	 * @param string $client_id
	 * @param string $client_secret
	 * @param string $client_redirect_uri
	 * @param string $state_key A secret (not shared with anyone) used to encode the session state
	 * @param    int $time_window_length About how long the session state is valid for in seconds.
	 *                                   That is, the time interval users have between clicking
	 *                                   the "Connect to FitBit" button and coming back to our site.
	 */
	public function __construct( $client_id, $client_secret, $client_redirect_uri, $state_key, $time_window_length = self::DEFAULT_TIME_WINDOW ) {
		$this->id = $client_id;
		$this->secret = $client_secret;
		$this->redirect_uri = $client_redirect_uri;
		$this->state_key = $state_key;
		$this->time_window_length = $time_window_length;
	}

	/**
	 * @param string $user_id Some unique identifier for the user or session.
	 * @return string
	 */
	public function encode_state( $user_id ) {
		return $this->generate_state( $user_id );
	}

	/**
	 * @param string $user_id Some unique identifier for the user or session.
	 * @return string FitBit Authorization URL
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
	 * @param string $user_id Some unique identifier for the user or session.
	 * @throws Exception if the expected GET parameters are missing or if the encoded session state is invalid.
	 * @return object
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

	private function verify_state( $user_id, $state ) {
		$verified = 0;
		$verified |= (int) hash_equals( $state, $this->generate_state( $user_id ) );
		$verified |= (int) hash_equals( $state, $this->generate_state( $user_id, 1 ) );
		return (bool) $verified;
	}

	private function get_request() {
		// If this is running inside WordPress, we need to unslash $_GET
		if ( function_exists( 'wp_unslash' ) ) {
			return wp_unslash( $_GET ); // Input Var Ok.
		}

		return $_GET; // Input Var Ok.
	}

	private function get_access_token( $authorization_code ) {
		$args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( "$this->id:$this->secret" ),
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
			$error_message = $response->get_error_message();
			echo 'Something went wrong: ' . esc_html( $error_message );
			$return = (object) array();
		} else {
			$return = json_decode( $response['body'] );
			$return->http_response_code = $response['response']['code'];
		}

		return $return;
	}
}
