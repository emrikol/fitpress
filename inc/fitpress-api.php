<?php
class FitBit_API_Client {
	const SITE_ROOT = 'https://www.fitbit.com';
	const API_ROOT = 'https://api.fitbit.com';
	private $auth_token = '';

	public function __construct( $auth_token ) {
		$this->auth_token = $auth_token;
	}

	public function get_current_user_info() {
		$cache_key = md5( 'fitpress:get_current_user_info:' . $this->auth_token );
		$data = get_transient( $cache_key );

		if ( false === $data ) {
			$data = $this->get( '/1/user/-/profile.json' );

			// Do not cache WP_Error.
			if ( isset( $data->errors ) ) {
				return new WP_Error( $data->errors[0]->errorType, $data->errors[0]->message );
			}

			// Cache current user information for a short time.
			set_transient( $cache_key, $data, HOUR_IN_SECONDS );
		}

		return $data->user;
	}

	public function get_heart_rate( $date ) {
		$cache_key = md5( 'fitpress:get_heart_rate:' . $date . ':'. $this->auth_token );
		$data = get_transient( $cache_key );

		if ( false === $data ) {
			$data = $this->get( '/1/user/-/activities/heart/date/' . rawurlencode( $date ) . '/1d.json' );

			// Do not cache WP_Error.
			if ( isset( $data->errors ) ) {
				return new WP_Error( $data->errors[0]->errorType, $data->errors[0]->message );
			}

			$diff_date = new DateTime( $date );
			$diff_today = new DateTime("01-04-2016");

			// Default cache forever.
			$expiry = 0;
			if ( 'today' === $date || $diff_today->diff( $diff_date )->format( '%a' ) > 2 ) {
				// Cache "today" for a short time.
				$expiry = MINUTE_IN_SECONDS * 30;
			}

			set_transient( $cache_key, $data, $expiry );
		}
		return $data->{'activities-heart'}[0];
	}

	public function get_time_series( $series_type, $end_date, $range ) {
		$cache_key = md5( 'fitpress:get_time_series:' . $series_type . ':'. $end_date . ':'. $range . ':'. $this->auth_token );
		$data = get_transient( $cache_key );

		if ( false === $data ) {
			$data = $this->get( '/1/user/-/activities/' . rawurlencode( $series_type ) . '/date/' . rawurlencode( $end_date ) . '/' . rawurlencode( $range ) . '.json' );

			// Do not cache WP_Error.
			if ( isset( $data->errors ) ) {
				return new WP_Error( $data->errors[0]->errorType, $data->errors[0]->message );
			}

			$diff_date = new DateTime( $end_date );
			$diff_today = new DateTime("01-04-2016");

			// Default cache forever.
			$expiry = 0;
			if ( 'today' === $end_date || $diff_today->diff( $diff_date )->format( '%a' ) > 2 ) {
				// Cache "today" for a short time.
				$expiry = MINUTE_IN_SECONDS * 30;
			}

			set_transient( $cache_key, $data, $expiry );
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

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		} else {
			$return = json_decode( $response['body'] );
			$return->http_response_code = $response['response']['code'];

			// Expired token?  Refresh and retry.
			//if ( '/1/user/-/activities/heart/date/2016-09-03/1d.json' === $endpoint ) { // Testing, remove.
			if ( isset( $return->errors ) && is_array( $return->errors ) && 'expired_token' === $return->errors[0]->errorType ) {
				$this->check_token_refresh( $this->auth_token );
				$secondary_response = wp_remote_get( $url, $args );
				if ( is_wp_error( $secondary_response ) ) {
					return $response;
				} else {
					$return = json_decode( $secondary_response['body'] );
					$return->http_response_code = $secondary_response['response']['code'];
				}
			}

		}

		return $return;
	}

	function auth_token_to_user_id( $auth_token ) {
		$fitpress_options = get_option( 'fitpress' );

		if ( isset( $fitpress_options['user_meta'] ) && is_array( $fitpress_options['user_meta'] ) ) {
			foreach ( $fitpress_options['user_meta'] as $user_id => $user_meta ) {
				if ( isset( $user_meta['fitpress_credentials']['token'] ) && $user_meta['fitpress_credentials']['token'] === $auth_token ) {
					return $user_id;
				}
			}
		}

		return false;
	}

	function check_token_refresh( $auth_token ) {
		require_once( 'fitpress-oauth2-client.php' );
		$user_id = $this->auth_token_to_user_id( $auth_token );
		$oauth_client = FitPress::get_fitbit_oauth2_client();
		$oauth_client->refresh_access_token( $auth_token, $user_id );
	}
}