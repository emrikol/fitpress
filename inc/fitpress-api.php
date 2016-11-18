<?php
/**
 * FitBit_API_Client
 *
 * @category Class
 * @package  WordPress
 */

/**
 * Generic functions to interact with the FitBit API
 *
 * @param string $auth_token The oAuth2 Authorization token.
 */
class FitBit_API_Client {
	const SITE_ROOT = 'https://www.fitbit.com';
	const API_ROOT = 'https://api.fitbit.com';

	/**
	 * FitBit oAuth2 Authorization token.
	 *
	 * @access private
	 * @var string
	 */
	private $auth_token = '';

	/**
	 * Sets up object properties.
	 *
	 * @access public
	 *
	 * @param string $auth_token The oAuth2 Authorization token.
	 *
	 * @return void
	 */
	public function __construct( $auth_token ) {
		$this->auth_token = $auth_token;
	}

	/**
	 * Retrieves information of the currently authorized FitBit user.
	 *
	 * @access public
	 *
	 * @return Object User data.
	 */
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

	/**
	 * Retrieves heart rate information.
	 *
	 * @access public
	 *
	 * @param string $date           The end date of the period specified in the format yyyy-MM-dd or 'today'.
	 * @param string $fitbit_user_id The Fitbit encoded User ID.
	 *
	 * @return Object Heart rate data.
	 */
	public function get_heart_rate( $date, $fitbit_user_id = '-' ) {
		if ( '-' === $fitbit_user_id ) {
			$auth_token = $this->auth_token;
		} else {
			$auth_token = $this->user_id_to_auth_token( $this->get_wordpress_user_id( $fitbit_user_id ) );
		}

		$cache_key = md5( 'fitpress:get_heart_rate:' . $date . ':' . $auth_token );
		$data = get_transient( $cache_key );

		if ( false === $data ) {
			$data = $this->get( '/1/user/' . $fitbit_user_id . '/activities/heart/date/' . rawurlencode( $date ) . '/1d.json', null, $auth_token );

			// Do not cache WP_Error.
			if ( isset( $data->errors ) ) {
				return new WP_Error( $data->errors[0]->errorType, $data->errors[0]->message );
			}

			$diff_date = new DateTime( $date );
			$diff_today = new DateTime();

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

	/**
	 * Retrieves information about a time series.
	 *
	 * @access public
	 *
	 * @param string $resource_path The resource path; see options in the "Resource Path Options" FitBit API Documentation.
	 * @param string $end_date      The end date of the period specified in the format yyyy-MM-dd or 'today'.
	 * @param string $period        The range for which data will be returned. Options are 1d, 7d, 30d, 1w, 1m, 3m, 6m, 1y.
	 *
	 * @return Object Heart rate data.
	 */
	public function get_time_series( $resource_path, $end_date, $period ) {
		$cache_key = md5( 'fitpress:get_time_series:' . $resource_path . ':' . $end_date . ':' . $period . ':' . $this->auth_token );
		$data = get_transient( $cache_key );

		if ( false === $data ) {
			$data = $this->get( '/1/user/-/activities/' . rawurlencode( $resource_path ) . '/date/' . rawurlencode( $end_date ) . '/' . rawurlencode( $period ) . '.json' );

			// Do not cache WP_Error.
			if ( isset( $data->errors ) ) {
				return new WP_Error( $data->errors[0]->errorType, $data->errors[0]->message );
			}

			$diff_date = new DateTime( $end_date );
			$diff_today = new DateTime();

			// Default cache forever.
			$expiry = 0;
			if ( 'today' === $end_date || $diff_today->diff( $diff_date )->format( '%a' ) > 2 ) {
				// Cache "today" for a short time.
				$expiry = MINUTE_IN_SECONDS * 30;
			}

			set_transient( $cache_key, $data, $expiry );
		}
		return $data->{"activities-$resource_path"};
	}

	/**
	 * Sends a POST request to the FitBit API.
	 *
	 * @access public
	 *
	 * @param string $endpoint The API endpoint to be requested.
	 * @param string $fields   Any necessary arguments to the API endpoint.
	 *
	 * @return Object|WP_Error API Object on success.  WP_Error on fail.
	 */
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
			$return = $response;
		} else {
			$return = json_decode( $response['body'] );
			$return->http_response_code = $response['response']['code'];
		}

		return $return;
	}

	/**
	 * Sends a GET request to the FitBit API.
	 *
	 * @access public
	 *
	 * @param string $endpoint   The API endpoint to be requested.
	 * @param string $query      Any necessary arguments to the API endpoint.
	 * @param string $auth_token The FitBit Auth token.
	 *
	 * @return Object|WP_Error API Object on success.  WP_Error on fail.
	 */
	public function get( $endpoint, $query = null, $auth_token = null ) {
		if ( null === $auth_token ) {
			$auth_token = $this->auth_token;
		}

		$query = ( is_array( $query ) ) ? http_build_query( $query ) : $query;

		$url = self::API_ROOT . $endpoint;

		if ( $query ) {
			$url = $url . '?' . $query;
		}

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $auth_token,
			),
		);

		$response = wp_remote_get( $url, $args ); // @codingStandardsIgnoreLine.
		if ( is_wp_error( $response ) ) {
			return $response;
		} else {
			$return = json_decode( $response['body'] );
			$return->http_response_code = $response['response']['code'];

			// Expired token?  Refresh and retry.
			if ( isset( $return->errors ) && is_array( $return->errors ) && 'expired_token' === $return->errors[0]->errorType ) {
				$this->check_token_refresh( $auth_token );
				$secondary_response = wp_remote_get( $url, $args ); // @codingStandardsIgnoreLine.
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

	/**
	 * Retrieves a WordPress user ID from a FitBit API token.
	 *
	 * @access public
	 *
	 * @param string $auth_token The oAuth2 Authorization token.
	 *
	 * @return bool|int WordPress user ID on success, false on failure.
	 */
	public function auth_token_to_user_id( $auth_token ) {
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

	/**
	 * Retrieves a WordPress user ID from a FitBit API token.
	 *
	 * @access public
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return mixed $auth_token The oAuth2 Authorization token on success, false on failure.
	 */
	public function user_id_to_auth_token( $user_id ) {
		$fitpress_credentials = FitPress::fitpress_get_user_meta( $user_id, 'fitpress_credentials' );

		if ( isset( $fitpress_credentials['token'] ) ) {
			return $fitpress_credentials['token'];
		}

		return false;
	}

	/**
	 * Retrieves a FitBit User ID from a WordPress ID.
	 *
	 * @access public
	 *
	 * @param int $user_id The WordPress User ID.
	 *
	 * @return string|false An encoded FitBit User ID on success, false on failure.
	 */
	public static function get_fitbit_user_id( $user_id ) {
		$fitpress_user_profile = FitPress::fitpress_get_user_meta( $user_id, 'fitpress_user_profile' );

		if ( isset( $fitpress_user_profile->encodedId ) ) { // @codingStandardsIgnoreLine.
			return $fitpress_user_profile->encodedId; // @codingStandardsIgnoreLine.
		}

		return false;
	}

	/**
	 * Retrieves a WordPress ID from a FitBit User ID.
	 *
	 * @access public
	 *
	 * @param string $encoded_id An encoded FitBit User ID.
	 *
	 * @return mixed $user_id The WordPress User ID on success, false on failure.
	 */
	public static function get_wordpress_user_id( $encoded_id ) {
		$fitpress_options = get_option( 'fitpress' );

		if ( isset( $fitpress_options['user_meta'] ) && is_array( $fitpress_options['user_meta'] ) ) {
			foreach ( $fitpress_options['user_meta'] as $user_id => $user_meta ) {
				if ( isset( $user_meta['fitpress_user_profile']->encodedId ) && $user_meta['fitpress_user_profile']->encodedId === $encoded_id ) {
					return $user_id;
				}
			}
		}

		return false;
	}

	/**
	 * Checks if an expired auth token can be refreshed.
	 *
	 * @access public
	 *
	 * @param string $auth_token The oAuth2 Authorization token.
	 *
	 * @return void
	 */
	public function check_token_refresh( $auth_token ) {
		require_once( 'fitpress-oauth2-client.php' );
		$user_id = $this->auth_token_to_user_id( $auth_token );
		$oauth_client = FitPress::get_fitbit_oauth2_client();
		$oauth_client->refresh_access_token( $auth_token, $user_id );
	}
}
