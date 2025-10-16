<?php
/**
 * Pressable API Client for WordPress.
 *
 * This class provides a structured way to interact with the Pressable REST API,
 * handling authentication and requests.
 *
 * @version 1.0.0
 * @author  Brandon Hubbard
 */

if ( ! class_exists( 'WP_Pressable_API' ) ) {

    /**
     * Class WP_Pressable_API
     *
     * A PHP wrapper for the Pressable v1 REST API.
     */
    class WP_Pressable_API {

        /**
         * The base URL for the Pressable API.
         *
         * @var string
         */
        private $api_url = 'https://my.pressable.com/api/v1/';

        /**
         * The URL for OAuth2 token generation.
         *
         * @var string
         */
        private $auth_url = 'https://my.pressable.com/oauth2/token';

        /**
         * The Pressable API Client ID.
         *
         * @var string
         */
        private $client_id;

        /**
         * The Pressable API Client Secret.
         *
         * @var string
         */
        private $client_secret;

        /**
         * The obtained access token.
         *
         * @var string|null
         */
        private $access_token = null;

        /**
         * WP_API_Client_Pressable constructor.
         *
         * @param string $client_id     Your Pressable API Client ID.
         * @param string $client_secret Your Pressable API Client Secret.
         */
        public function __construct( $client_id, $client_secret ) {
            $this->client_id     = trim( $client_id );
            $this->client_secret = trim( $client_secret );
        }

        /**
         * Authenticates with the Pressable API to get an access token.
         *
         * @return bool|WP_Error True on success, WP_Error on failure.
         */
        public function authenticate() {
            if ( empty( $this->client_id ) || empty( $this->client_secret ) ) {
                return new WP_Error( 'missing_credentials', 'Client ID and Client Secret are required.' );
            }

            $response = wp_remote_post(
                $this->auth_url,
                array(
                    'method'  => 'POST',
                    'headers' => array(
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ),
                    'body'    => array(
                        'grant_type'    => 'client_credentials',
                        'client_id'     => $this->client_id,
                        'client_secret' => $this->client_secret,
                    ),
                )
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $http_code = wp_remote_retrieve_response_code( $response );

            if ( $http_code !== 200 || empty( $body['access_token'] ) ) {
                $error_message = isset( $body['error_description'] ) ? $body['error_description'] : 'Authentication failed. Please check your credentials.';
                return new WP_Error( 'authentication_failed', $error_message, array( 'status' => $http_code ) );
            }

            $this->access_token = $body['access_token'];

            // You might want to store the token in transients for better performance
            // set_transient( 'pressable_api_token', $this->access_token, $body['expires_in'] - 60 );

            return true;
        }

        /**
         * Generic method to make requests to the Pressable API.
         *
         * @param string $endpoint The API endpoint to call (e.g., 'sites').
         * @param array  $args     Arguments for the request (e.g., body, headers).
         * @param string $method   The HTTP method (GET, POST, PUT, DELETE).
         * @return array|WP_Error The decoded JSON response or a WP_Error object.
         */
        private function make_request( $endpoint, $args = array(), $method = 'GET' ) {
            if ( is_null( $this->access_token ) ) {
                 // Try to authenticate if not already authenticated.
                $auth_result = $this->authenticate();
                if ( is_wp_error($auth_result) ) {
                    return new WP_Error( 'not_authenticated', 'You must authenticate before making API requests.', $auth_result );
                }
            }

            $default_args = array(
                'method'  => $method,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type'  => 'application/json; charset=utf-8',
                ),
                'timeout' => 30,
            );

            // For POST/PUT requests, encode the body as JSON.
            if ( in_array( $method, array( 'POST', 'PUT' ), true ) && isset( $args['body'] ) ) {
                $args['body'] = wp_json_encode( $args['body'] );
            }

            $request_args = wp_parse_args( $args, $default_args );
            $request_url  = $this->api_url . ltrim( $endpoint, '/' );

            $response = wp_remote_request( $request_url, $request_args );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $http_code = wp_remote_retrieve_response_code( $response );

            if ( $http_code >= 400 ) {
                 $error_message = isset( $body['message'] ) ? $body['message'] : 'An API error occurred.';
                 if ( isset( $body['errors'] ) ) {
                     $error_message .= ' Details: ' . wp_json_encode($body['errors']);
                 }
                return new WP_Error( 'api_error', $error_message, array( 'status' => $http_code ) );
            }

            return $body;
        }

        /**
         * Get Account Details.
         *
         * @return array|WP_Error The account data.
         */
        public function get_account() {
            return $this->make_request( 'account' );
        }

        /**
         * Get a list of all sites on the account.
         *
         * @return array|WP_Error A list of site objects.
         */
        public function get_sites() {
            return $this->make_request( 'sites' );
        }

        /**
         * Get details for a specific site.
         *
         * @param string $site_id The ID of the site.
         * @return array|WP_Error A single site object.
         */
        public function get_site( $site_id ) {
            return $this->make_request( "sites/{$site_id}" );
        }
        
        /**
         * Create a new site.
         *
         * @param array $site_data Data for the new site.
         * e.g., ['name' => 'My New Awesome Site']
         * @return array|WP_Error The newly created site object.
         */
        public function create_site( $site_data ) {
            $args = array(
                'body' => $site_data
            );
            return $this->make_request( 'sites', $args, 'POST' );
        }

        /**
         * Delete a site.
         * Use with caution. This is a permanent action.
         *
         * @param string $site_id The ID of the site to delete.
         * @return array|WP_Error Response from the API.
         */
        public function delete_site( $site_id ) {
            return $this->make_request( "sites/{$site_id}", array(), 'DELETE' );
        }
    }
}
