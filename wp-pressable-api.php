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

        /**
         * Get a specific collaborator by their user ID.
         *
         * @param string $collaborator_id The ID of the collaborator.
         * @return array|WP_Error A single collaborator object.
         */
        public function get_collaborator( $collaborator_id ) {
            return $this->make_request( "collaborators/{$collaborator_id}" );
        }

        /**
         * Update account-level access for a collaborator.
         *
         * @param array $data The data for the collaborator access update.
         * @return array|WP_Error Response from the API.
         */
        public function update_collaborator_account_access( $data ) {
            $args = array( 'body' => $data );
            return $this->make_request( 'collaborators/update-account-level-access', $args, 'PUT' );
        }

        /**
         * Batch create collaborators on multiple sites.
         *
         * @param array $data The data for the batch creation.
         * @return array|WP_Error Response from the API.
         */
        public function batch_create_collaborators( $data ) {
            $args = array( 'body' => $data );
            return $this->make_request( 'collaborators/batch_create', $args, 'POST' );
        }

        /**
         * Batch destroy collaborators on multiple sites.
         *
         * @param array $data The data for the batch destruction.
         * @return array|WP_Error Response from the API.
         */
        public function batch_destroy_collaborators( $data ) {
            $args = array( 'body' => $data );
            return $this->make_request( 'collaborators/batch_destroy', $args, 'POST' );
        }

        /**
         * Get sites a collaborator has access to by email.
         *
         * @param string $email_address The email address of the collaborator.
         * @return array|WP_Error A list of site objects.
         */
        public function get_collaborator_sites_by_email( $email_address ) {
            return $this->make_request( "collaborators/{$email_address}/sites" );
        }

        /**
         * Check if a collaborator has access to a specific site.
         *
         * @param string $email_address The email address of the collaborator.
         * @param string $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function get_collaborator_site_access( $email_address, $site_id ) {
            return $this->make_request( "collaborators/{$email_address}/sites/{$site_id}" );
        }

        /**
         * Mark a site as a favorite for a collaborator.
         *
         * @param string $email_address The email address of the collaborator.
         * @param string $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function favorite_collaborator_site( $email_address, $site_id ) {
            return $this->make_request( "collaborators/{$email_address}/sites/{$site_id}/favorite", array(), 'POST' );
        }

        /**
         * Unmark a site as a favorite for a collaborator.
         *
         * @param string $email_address The email address of the collaborator.
         * @param string $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function unfavorite_collaborator_site( $email_address, $site_id ) {
            return $this->make_request( "collaborators/{$email_address}/sites/{$site_id}/favorite", array(), 'DELETE' );
        }

        /**
         * Set the default PHP version for new sites on the account.
         *
         * @param string $php_version The desired PHP version (e.g., '8.2', '8.3').
         * @return array|WP_Error Response from the API.
         */
        public function set_account_php_version( $php_version ) {
            $args = array(
                'body' => array( 'php_version' => $php_version ),
            );
            return $this->make_request( 'account', $args, 'PUT' );
        }

        /**
         * Set the default datacenter for new sites on the account.
         *
         * @param string|null $datacenter_code The datacenter code (e.g., 'BUR') or null to remove the default.
         * @return array|WP_Error Response from the API.
         */
        public function set_account_datacenter( $datacenter_code ) {
            $args = array(
                'body' => array( 'datacenter_code' => $datacenter_code ),
            );
            return $this->make_request( 'account', $args, 'PUT' );
        }

        /**
         * Get account activity logs.
         *
         * @param array $data The request data containing page, per_page, and filters.
         * @return array|WP_Error A list of activity log objects.
         */
        public function get_account_activity_logs( $data ) {
            $args = array( 'body' => $data );
            return $this->make_request( 'account/logs/activity', $args, 'POST' );
        }

        /**
         * Get a list of filterable actions for account activity logs.
         *
         * @return array|WP_Error A list of available actions.
         */
        public function get_account_activity_log_actions() {
            return $this->make_request( 'activity/account-actions' );
        }

        /**
         * Get a list of all available account add-ons.
         *
         * @return array|WP_Error A list of available add-on objects.
         */
        public function get_available_addons() {
            return $this->make_request( 'account/addons' );
        }

        /**
         * Get a list of add-ons currently attached to the account.
         *
         * @return array|WP_Error A list of attached add-on objects.
         */
        public function get_attached_addons() {
            return $this->make_request( 'account/addons/attached' );
        }

        /**
         * Update the quantity of an add-on attached to the account.
         *
         * @param int $addon_id The ID of the add-on to update.
         * @param int $quantity The total desired quantity for the add-on.
         * @return array|WP_Error Response from the API.
         */
        public function update_attached_addons( $addon_id, $quantity ) {
            $args = array(
                'body' => array(
                    'addon_id' => $addon_id,
                    'quantity' => $quantity,
                ),
            );
            return $this->make_request( 'account/addons', $args, 'PUT' );
        }

        /**
         * Get the zones attached to your account.
         *
         * @return array|WP_Error A list of zone objects.
         */
        public function get_zones() {
            return $this->make_request( 'zones' );
        }

        /**
         * Get all the DNS records attached to a zone.
         *
         * @param int $zone_id The ID of the zone.
         * @return array|WP_Error An object containing zone details and its records.
         */
        public function get_zone_records( $zone_id ) {
            return $this->make_request( "zones/{$zone_id}/records" );
        }

        /**
         * Create a new DNS record in a zone.
         *
         * @param int   $zone_id     The ID of the zone.
         * @param array $record_data Data for the new DNS record. See Pressable docs for requirements.
         * @return array|WP_Error The zone object with the updated list of records.
         */
        public function create_zone_record( $zone_id, $record_data ) {
            $args = array(
                'body' => $record_data,
            );
            return $this->make_request( "zones/{$zone_id}/records", $args, 'POST' );
        }

        /**
         * Delete a DNS record from a zone.
         *
         * @param int $zone_id   The ID of the zone.
         * @param int $record_id The ID of the DNS record to delete.
         * @return array|WP_Error The zone object with the updated list of records.
         */
        public function delete_zone_record( $zone_id, $record_id ) {
            return $this->make_request( "zones/{$zone_id}/records/{$record_id}", array(), 'DELETE' );
        }

        /**
         * Generate all the email records for a popular provider and attach them to the zone.
         *
         * @param int    $zone_id  The ID of the zone.
         * @param string $provider The name of the provider (e.g., 'gsuite', 'office365').
         * @return array|WP_Error Response from the API.
         */
        public function generate_email_provider_records( $zone_id, $provider ) {
            $args = array(
                'body' => array( 'provider' => $provider ),
            );
            return $this->make_request( "zones/{$zone_id}/providers", $args, 'POST' );
        }

        /**
         * Get the SSL certificate details for an A Record.
         *
         * @param int $zone_id   The ID of the zone.
         * @param int $record_id The ID of the A record.
         * @return array|WP_Error SSL certificate details.
         */
        public function get_ssl_details( $zone_id, $record_id ) {
            return $this->make_request( "zones/{$zone_id}/records/{$record_id}/ssl" );
        }

        /**
         * Force a retry of SSL certificate provisioning for an A Record.
         *
         * @param int $zone_id   The ID of the zone.
         * @param int $record_id The ID of the A record.
         * @return array|WP_Error Response from the API.
         */
        public function retry_ssl_provisioning( $zone_id, $record_id ) {
            return $this->make_request( "zones/{$zone_id}/records/{$record_id}/ssl", array(), 'PUT' );
        }

        /**
         * Get the current status of the edge cache for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error An object with the edge cache status.
         */
        public function get_edge_cache_status( $site_id ) {
            return $this->make_request( "sites/{$site_id}/edge-cache" );
        }

        /**
         * Enable or disable the edge cache for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function toggle_edge_cache( $site_id ) {
            return $this->make_request( "sites/{$site_id}/edge-cache", array(), 'PUT' );
        }

        /**
         * Purge the edge cache for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function purge_edge_cache( $site_id ) {
            return $this->make_request( "sites/{$site_id}/edge-cache", array(), 'DELETE' );
        }

        /**
         * Set the Edge Cache Defensive Mode for a site for a specific duration.
         *
         * @param int         $site_id  The ID of the site.
         * @param string|null $duration The duration to enable defensive mode (e.g., '1-hour', '7-days') or null to disable.
         * @return array|WP_Error Response from the API.
         */
        public function set_edge_cache_defensive_mode( $site_id, $duration ) {
            $args = array(
                'body' => array( 'duration' => $duration ),
            );
            return $this->make_request( "sites/{$site_id}/edge-cache/defensive-mode", $args, 'PUT' );
        }

        /**
         * Get a list of Must-Use plugins.
         *
         * @param int $page     The page of results to retrieve.
         * @param int $per_page The number of results per page (max: 50).
         * @return array|WP_Error A list of mu-plugin objects.
         */
        public function get_mu_plugins( $page = 1, $per_page = 20 ) {
            $args = array(
                'body' => array(
                    'page'     => $page,
                    'per_page' => $per_page,
                ),
            );
            // Note: The API docs show this as a GET with a body, which is unusual.
            // wp_remote_request handles this, but it might be interpreted as POST by some servers.
            // If issues arise, this might need to be changed to a POST request if the API allows it.
            return $this->make_request( 'mu-plugins', $args, 'GET' );
        }

        /**
         * Get details for a specific Must-Use plugin.
         *
         * @param int $plugin_id The ID of the mu-plugin.
         * @return array|WP_Error A single mu-plugin object.
         */
        public function get_mu_plugin( $plugin_id ) {
            return $this->make_request( "mu-plugins/{$plugin_id}" );
        }

        /**
         * Create or install a new Must-Use plugin.
         *
         * @param string $path           The public URL to the single .php file for the plugin.
         * @param bool   $existing_sites Set to true to install on all existing sites.
         * @param bool   $future_sites   Set to true to install on all future sites.
         * @return array|WP_Error The newly created mu-plugin object.
         */
        public function create_mu_plugin( $path, $existing_sites, $future_sites ) {
            $args = array(
                'body' => array(
                    'path'           => $path,
                    'existing_sites' => $existing_sites,
                    'future_sites'   => $future_sites,
                ),
            );
            return $this->make_request( 'mu-plugins', $args, 'POST' );
        }

        /**
         * Update an existing Must-Use plugin. This refreshes the plugin from its path.
         *
         * @param int $plugin_id The ID of the mu-plugin to update.
         * @return array|WP_Error The updated mu-plugin object.
         */
        public function update_mu_plugin( $plugin_id ) {
            return $this->make_request( "mu-plugins/{$plugin_id}", array(), 'PUT' );
        }

        /**
         * Delete a Must-Use plugin from all sites.
         *
         * @param int $plugin_id The ID of the mu-plugin to delete.
         * @return array|WP_Error Response from the API.
         */
        public function delete_mu_plugin( $plugin_id ) {
            return $this->make_request( "mu-plugins/{$plugin_id}", array(), 'DELETE' );
        }

        /**
         * ========================================================================
         * On-Demand Backups
         * ========================================================================
         */

        /**
         * Get a list of on-demand backups for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of on-demand backup objects.
         */
        public function get_ondemand_backups( $site_id ) {
            return $this->make_request( "sites/{$site_id}/ondemand-backups" );
        }

        /**
         * Create an on-demand backup for a site.
         *
         * @param int    $site_id     The ID of the site.
         * @param string $backup_type The type of backup to create ('fs' or 'db').
         * @return array|WP_Error Response from the API.
         */
        public function create_ondemand_backup( $site_id, $backup_type ) {
            $args = array(
                'body' => array( 'backup_type' => $backup_type ),
            );
            return $this->make_request( "sites/{$site_id}/ondemand-backups", $args, 'POST' );
        }

        /**
         * Get details for a specific on-demand backup.
         *
         * @param int    $site_id   The ID of the site.
         * @param string $backup_id The ID of the on-demand backup.
         * @return array|WP_Error A single on-demand backup object.
         */
        public function get_ondemand_backup( $site_id, $backup_id ) {
            return $this->make_request( "sites/{$site_id}/ondemand-backups/{$backup_id}" );
        }

        /**
         * Get the download URL for a specific on-demand backup.
         *
         * @param int    $site_id   The ID of the site.
         * @param string $backup_id The ID of the on-demand backup.
         * @return array|WP_Error The response containing the download link.
         */
        public function download_ondemand_backup( $site_id, $backup_id ) {
            return $this->make_request( "sites/{$site_id}/ondemand-backups/{$backup_id}/download" );
        }

        /**
         * Delete a specific on-demand backup.
         *
         * @param int    $site_id   The ID of the site.
         * @param string $backup_id The ID of the on-demand backup to delete.
         * @return array|WP_Error Response from the API.
         */
        public function delete_ondemand_backup( $site_id, $backup_id ) {
            return $this->make_request( "sites/{$site_id}/ondemand-backups/{$backup_id}", array(), 'DELETE' );
        }

        /**
         * ========================================================================
         * Plugins
         * ========================================================================
         */

        /**
         * Get a list of plugins for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of plugin objects.
         */
        public function get_plugins( $site_id ) {
            return $this->make_request( "sites/{$site_id}/plugins" );
        }

        /**
         * Install and activate plugins on a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $plugins An array of plugin objects to install.
         * @return array|WP_Error Response from the API.
         */
        public function install_plugins( $site_id, $plugins ) {
            $args = array(
                'body' => array( 'plugins' => $plugins ),
            );
            return $this->make_request( "sites/{$site_id}/plugins", $args, 'POST' );
        }

        /**
         * Update plugins on a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $plugins An array of plugin objects to update.
         * @return array|WP_Error Response from the API.
         */
        public function update_plugins( $site_id, $plugins ) {
            $args = array(
                'body' => array( 'plugins' => $plugins ),
            );
            return $this->make_request( "sites/{$site_id}/plugins", $args, 'PUT' );
        }

        /**
         * Deactivate plugins on a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $plugins An array of plugin objects to deactivate.
         * @return array|WP_Error Response from the API.
         */
        public function deactivate_plugins( $site_id, $plugins ) {
            $args = array(
                'body' => array( 'plugins' => $plugins ),
            );
            return $this->make_request( "sites/{$site_id}/plugins/deactivate", $args, 'PUT' );
        }

        /**
         * Activate plugins on a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $plugins An array of plugin objects to activate.
         * @return array|WP_Error Response from the API.
         */
        public function activate_plugins( $site_id, $plugins ) {
            $args = array(
                'body' => array( 'plugins' => $plugins ),
            );
            return $this->make_request( "sites/{$site_id}/plugins/activate", $args, 'PUT' );
        }

        /**
         * Delete plugins from a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $plugins An array of plugin objects to delete.
         * @return array|WP_Error Response from the API.
         */
        public function delete_plugins( $site_id, $plugins ) {
            $args = array(
                'body' => array( 'plugins' => $plugins ),
            );
            return $this->make_request( "sites/{$site_id}/plugins", $args, 'DELETE' );
        }

        /**
         * ========================================================================
         * Themes
         * ========================================================================
         */

        /**
         * Get a list of themes for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of theme objects.
         */
        public function get_themes( $site_id ) {
            return $this->make_request( "sites/{$site_id}/themes" );
        }

        /**
         * Install and activate themes on a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $themes An array of theme objects to install.
         * @return array|WP_Error Response from the API.
         */
        public function install_themes( $site_id, $themes ) {
            $args = array(
                'body' => array( 'themes' => $themes ),
            );
            return $this->make_request( "sites/{$site_id}/themes", $args, 'POST' );
        }

        /**
         * Update themes on a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $themes An array of theme objects to update.
         * @return array|WP_Error Response from the API.
         */
        public function update_themes( $site_id, $themes ) {
            $args = array(
                'body' => array( 'themes' => $themes ),
            );
            return $this->make_request( "sites/{$site_id}/themes", $args, 'PUT' );
        }

        /**
         * Activate a theme on a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $themes An array containing one theme object to activate.
         * @return array|WP_Error Response from the API.
         */
        public function activate_theme( $site_id, $themes ) {
            $args = array(
                'body' => array( 'themes' => $themes ),
            );
            return $this->make_request( "sites/{$site_id}/themes/activate", $args, 'PUT' );
        }

        /**
         * Delete themes from a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $themes An array of theme objects to delete.
         * @return array|WP_Error Response from the API.
         */
        public function delete_themes( $site_id, $themes ) {
            $args = array(
                'body' => array( 'themes' => $themes ),
            );
            return $this->make_request( "sites/{$site_id}/themes", $args, 'DELETE' );
        }


        /**
         * ========================================================================
         * Security Alerts
         * ========================================================================
         */

        /**
         * Get a list of plugins with security issues for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of plugin security alerts.
         */
        public function get_plugin_security_alerts( $site_id ) {
            return $this->make_request( "sites/{$site_id}/security-alerts/plugins" );
        }

        /**
         * Get a list of themes with security issues for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of theme security alerts.
         */
        public function get_theme_security_alerts( $site_id ) {
            return $this->make_request( "sites/{$site_id}/security-alerts/themes" );
        }

        /**
         * ========================================================================
         * Site Management
         * ========================================================================
         */

        /**
         * Update a site's details.
         *
         * @param int   $site_id   The ID of the site.
         * @param array $site_data Data to update (e.g., name, php_version, wp_environment_type).
         * @return array|WP_Error The updated site object.
         */
        public function update_site( $site_id, $site_data ) {
            $args = array(
                'body' => $site_data,
            );
            return $this->make_request( "sites/{$site_id}", $args, 'PUT' );
        }

        /**
         * Validate a site name before creation.
         *
         * @param string $name The site name to validate.
         * @return array|WP_Error Validation result.
         */
        public function validate_site_name( $name ) {
            $args = array(
                'body' => array( 'name' => $name ),
            );
            return $this->make_request( 'sites/validate', $args, 'POST' );
        }

        /**
         * Search for sites based on filters.
         *
         * @param array $filters  Array of filters (key-value pairs).
         * @param int   $page     The page number for pagination.
         * @param int   $per_page The number of items per page.
         * @return array|WP_Error A list of matching site objects.
         */
        public function search_sites( $filters, $page = 1, $per_page = 20 ) {
            $args = array(
                'body' => array(
                    'filters'  => $filters,
                    'page'     => $page,
                    'per_page' => $per_page,
                ),
            );
            return $this->make_request( 'sites/search', $args, 'POST' );
        }

        /**
         * Get available install options for site creation.
         *
         * @return array|WP_Error A list of install options.
         */
        public function get_site_install_options() {
            return $this->make_request( 'sites/install-options' );
        }

        /**
         * Get available datacenters for site creation.
         *
         * @return array|WP_Error A list of datacenter objects.
         */
        public function get_available_datacenters() {
            return $this->make_request( 'sites/datacenters' );
        }

        /**
         * Get valid PHP versions available.
         *
         * @return array|WP_Error A list of valid PHP versions.
         */
        public function get_valid_php_versions() {
            return $this->make_request( 'sites/php-versions' );
        }

        /**
         * Disable a site.
         *
         * @param int $site_id The ID of the site to disable.
         * @return array|WP_Error The updated site object.
         */
        public function disable_site( $site_id ) {
            return $this->make_request( "sites/{$site_id}/disable", array(), 'PUT' );
        }

        /**
         * Enable a site.
         *
         * @param int $site_id The ID of the site to enable.
         * @return array|WP_Error The updated site object.
         */
        public function enable_site( $site_id ) {
            return $this->make_request( "sites/{$site_id}/enable", array(), 'PUT' );
        }

        /**
         * Convert a site (e.g., staging to live).
         *
         * @param int $site_id The ID of the site to convert.
         * @return array|WP_Error The updated site object.
         */
        public function convert_site( $site_id ) {
            return $this->make_request( "sites/{$site_id}/convert", array(), 'PUT' );
        }

        /**
         * Convert a site to a sandbox environment.
         *
         * @param int $site_id The ID of the site to convert.
         * @return array|WP_Error The updated site object.
         */
        public function convert_site_to_sandbox( $site_id ) {
            return $this->make_request( "sites/{$site_id}/convert/sandbox", array(), 'PUT' );
        }

        /**
         * Convert a site to a live environment.
         *
         * @param int $site_id The ID of the site to convert.
         * @return array|WP_Error The updated site object.
         */
        public function convert_site_to_live( $site_id ) {
            return $this->make_request( "sites/{$site_id}/convert/live", array(), 'PUT' );
        }

        /**
         * Convert a site to a staging environment.
         *
         * @param int $site_id The ID of the site to convert.
         * @return array|WP_Error The updated site object.
         */
        public function convert_site_to_staging( $site_id ) {
            return $this->make_request( "sites/{$site_id}/convert/staging", array(), 'PUT' );
        }

        /**
         * Flush the object cache for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function flush_object_cache( $site_id ) {
            return $this->make_request( "sites/{$site_id}/object-cache", array(), 'DELETE' );
        }

        /**
         * Toggle lightweight 404 handling for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function toggle_light_weight_404( $site_id ) {
            return $this->make_request( "sites/{$site_id}/light_weight_404", array(), 'PUT' );
        }

        /**
         * Toggle multisite support for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function toggle_multisite_support( $site_id ) {
            return $this->make_request( "sites/{$site_id}/multisite-support", array(), 'PUT' );
        }

        /**
         * Toggle maintenance mode for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function toggle_maintenance_mode( $site_id ) {
            return $this->make_request( "sites/{$site_id}/maintenance-mode", array(), 'PUT' );
        }

        /**
         * Toggle basic authentication for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function toggle_basic_authentication( $site_id ) {
            return $this->make_request( "sites/{$site_id}/basic-authentication", array(), 'PUT' );
        }

        /**
         * Favorite a site.
         *
         * @param int $site_id The ID of the site to favorite.
         * @return array|WP_Error Response from the API.
         */
        public function favorite_site( $site_id ) {
            return $this->make_request( "sites/{$site_id}/favorites", array(), 'POST' );
        }

        /**
         * Unfavorite a site.
         *
         * @param int $site_id The ID of the site to unfavorite.
         * @return array|WP_Error Response from the API.
         */
        public function unfavorite_site( $site_id ) {
            return $this->make_request( "sites/{$site_id}/favorites", array(), 'DELETE' );
        }

        /**
         * Create a clone of a site.
         *
         * @param int   $site_id   The ID of the site to clone.
         * @param array $clone_data Data for the new cloned site.
         * @return array|WP_Error The newly created site object.
         */
        public function clone_site( $site_id, $clone_data ) {
            $args = array(
                'body' => $clone_data,
            );
            return $this->make_request( "sites/{$site_id}/clone", $args, 'POST' );
        }

        /**
         * ========================================================================
         * Regular Backups & Restores
         * ========================================================================
         */

        /**
         * Get a list of available backups for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of backup objects.
         */
        public function get_backups( $site_id ) {
            return $this->make_request( "sites/{$site_id}/backups" );
        }

        /**
         * Get a list of available filesystem backups for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of filesystem backup objects.
         */
        public function get_filesystem_backups( $site_id ) {
            return $this->make_request( "sites/{$site_id}/backups/fs" );
        }

        /**
         * Get a list of available database backups for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of database backup objects.
         */
        public function get_database_backups( $site_id ) {
            return $this->make_request( "sites/{$site_id}/backups/db" );
        }

        /**
         * Download a specific site backup.
         *
         * @param int $site_id   The ID of the site.
         * @param int $backup_id The ID of the backup to download.
         * @return array|WP_Error The backup file stream.
         */
        public function download_backup( $site_id, $backup_id ) {
            return $this->make_request( "sites/{$site_id}/backups/{$backup_id}" );
        }

        /**
         * Restore a site from a backup.
         *
         * @param int   $site_id      The ID of the site to restore.
         * @param array $restore_data Data for the restore (filesystem_id, database_id, restore_on_site_id).
         * @return array|WP_Error Response from the API.
         */
        public function restore_from_backup( $site_id, $restore_data ) {
            $args = array(
                'body' => $restore_data,
            );
            return $this->make_request( "sites/{$site_id}/restores", $args, 'POST' );
        }

        /**
         * ========================================================================
         * Data Sync
         * ========================================================================
         */

        /**
         * Sync data from a source site to a destination site.
         *
         * @param array $sync_data The data for the sync operation.
         * @return array|WP_Error Response from the API.
         */
        public function sync_site_data( $sync_data ) {
            $args = array(
                'body' => $sync_data,
            );
            return $this->make_request( 'sites/sync', $args, 'POST' );
        }

        /**
         * ========================================================================
         * Site Collaborators
         * ========================================================================
         */

        /**
         * Get a list of collaborators for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of collaborator objects.
         */
        public function get_site_collaborators( $site_id ) {
            return $this->make_request( "sites/{$site_id}/collaborators" );
        }

        /**
         * Add a collaborator to a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $data    Collaborator data (e.g., email, roles).
         * @return array|WP_Error The new collaborator object.
         */
        public function create_site_collaborator( $site_id, $data ) {
            $args = array(
                'body' => $data,
            );
            return $this->make_request( "sites/{$site_id}/collaborators", $args, 'POST' );
        }

        /**
         * Get a specific collaborator for a site.
         *
         * @param int $site_id         The ID of the site.
         * @param int $collaborator_id The ID of the collaborator.
         * @return array|WP_Error A single collaborator object.
         */
        public function get_site_collaborator( $site_id, $collaborator_id ) {
            return $this->make_request( "sites/{$site_id}/collaborators/{$collaborator_id}" );
        }

        /**
         * Remove a collaborator from a site.
         *
         * @param int  $site_id         The ID of the site.
         * @param int  $collaborator_id The ID of the collaborator.
         * @param bool $delete_wp_user  Whether to also delete the WordPress user.
         * @return array|WP_Error Response from the API.
         */
        public function delete_site_collaborator( $site_id, $collaborator_id, $delete_wp_user = false ) {
             $endpoint = "sites/{$site_id}/collaborators/{$collaborator_id}";
            if ( $delete_wp_user ) {
                $endpoint .= '?delete_wp_user=true';
            }
            return $this->make_request( $endpoint, array(), 'DELETE' );
        }

        /**
         * Reset the WP-Admin password for a site collaborator.
         *
         * @param int $site_id         The ID of the site.
         * @param int $collaborator_id The ID of the collaborator.
         * @return array|WP_Error The new password.
         */
        public function reset_collaborator_wp_password( $site_id, $collaborator_id ) {
            return $this->make_request( "sites/{$site_id}/collaborators/{$collaborator_id}/wp-password-reset", array(), 'PUT' );
        }

        /**
         * ========================================================================
         * Site Domains
         * ========================================================================
         */

        /**
         * Get a list of domains for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of domain objects.
         */
        public function get_domains( $site_id ) {
            return $this->make_request( "sites/{$site_id}/domains" );
        }

        /**
         * Add a domain to a site.
         *
         * @param int    $site_id The ID of the site.
         * @param string $name    The domain name to add.
         * @return array|WP_Error A list of domain objects.
         */
        public function add_domain( $site_id, $name ) {
            $args = array(
                'body' => array( 'name' => $name ),
            );
            return $this->make_request( "sites/{$site_id}/domains", $args, 'POST' );
        }

        /**
         * Get a specific domain for a site.
         *
         * @param int $site_id   The ID of the site.
         * @param int $domain_id The ID of the domain.
         * @return array|WP_Error A single domain object.
         */
        public function get_domain( $site_id, $domain_id ) {
            return $this->make_request( "sites/{$site_id}/domains/{$domain_id}" );
        }

        /**
         * Remove a domain from a site.
         *
         * @param int $site_id   The ID of the site.
         * @param int $domain_id The ID of the domain to delete.
         * @return array|WP_Error Response from the API.
         */
        public function delete_domain( $site_id, $domain_id ) {
            return $this->make_request( "sites/{$site_id}/domains/{$domain_id}", array(), 'DELETE' );
        }

        /**
         * Set a domain as the primary domain for a site.
         *
         * @param int $site_id   The ID of the site.
         * @param int $domain_id The ID of the domain to set as primary (-1 to reset to staging domain).
         * @return array|WP_Error The primary domain object.
         */
        public function set_primary_domain( $site_id, $domain_id ) {
            return $this->make_request( "sites/{$site_id}/domains/{$domain_id}/primary", array(), 'PUT' );
        }

        /**
         * ========================================================================
         * SFTP
         * ========================================================================
         */

        /**
         * Get a list of SFTP users for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of SFTP user objects.
         */
        public function get_sftp_users( $site_id ) {
            return $this->make_request( "sites/{$site_id}/ftp" );
        }

        /**
         * Get a specific SFTP user for a site.
         *
         * @param int    $site_id  The ID of the site.
         * @param string $username The username of the SFTP user.
         * @return array|WP_Error A single SFTP user object.
         */
        public function get_sftp_user( $site_id, $username ) {
            return $this->make_request( "sites/{$site_id}/ftp/{$username}" );
        }

        /**
         * Reset the password for an SFTP user.
         *
         * @param int    $site_id  The ID of the site.
         * @param string $username The username of the SFTP user.
         * @return array|WP_Error The new password.
         */
        public function reset_sftp_user_password( $site_id, $username ) {
            return $this->make_request( "sites/{$site_id}/ftp/password/{$username}", array(), 'POST' );
        }

        /**
         * ========================================================================
         * Jetpack
         * ========================================================================
         */

        /**
         * Get Jetpack license information for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Jetpack license information.
         */
        public function get_jetpack_license( $site_id ) {
            return $this->make_request( "sites/{$site_id}/jetpack" );
        }

        /**
         * Set the Jetpack license for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function set_jetpack_license( $site_id ) {
            return $this->make_request( "sites/{$site_id}/jetpack", array(), 'POST' );
        }

        /**
         * ========================================================================
         * Reports, Statistics & Notes
         * ========================================================================
         */

        /**
         * Get statistics for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Site statistics.
         */
        public function get_site_statistics( $site_id ) {
            return $this->make_request( "sites/{$site_id}/statistics" );
        }

        /**
         * Add tags to a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $tags    An array of tag objects to add.
         * @return array|WP_Error The updated site object.
         */
        public function add_tags_to_site( $site_id, $tags ) {
            $args = array(
                'body' => array( 'tags' => $tags ),
            );
            return $this->make_request( "sites/{$site_id}/tags", $args, 'POST' );
        }

        /**
         * Remove tags from a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $tag_ids An array of tag IDs to remove.
         * @return array|WP_Error The updated site object.
         */
        public function remove_tags_from_site( $site_id, $tag_ids ) {
            $args = array(
                'body' => array( 'tag_ids' => $tag_ids ),
            );
            return $this->make_request( "sites/{$site_id}/tags", $args, 'DELETE' );
        }

        /**
         * Get notes for a site.
         *
         * @param int $site_id  The ID of the site.
         * @param int $page     The page number.
         * @param int $per_page The number of items per page.
         * @return array|WP_Error A list of note objects.
         */
        public function get_notes( $site_id, $page = 1, $per_page = 20 ) {
            $args = array(
                'body' => array(
                    'page'     => $page,
                    'per_page' => $per_page,
                ),
            );
            return $this->make_request( "sites/{$site_id}/notes", $args, 'GET' );
        }

        /**
         * Add a note to a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $data    The note data (subject and body).
         * @return array|WP_Error The new note object.
         */
        public function add_note( $site_id, $data ) {
            $args = array( 'body' => $data );
            return $this->make_request( "sites/{$site_id}/notes", $args, 'POST' );
        }

        /**
         * Get a specific note for a site.
         *
         * @param int $site_id The ID of the site.
         * @param int $note_id The ID of the note.
         * @return array|WP_Error A single note object.
         */
        public function get_note( $site_id, $note_id ) {
            return $this->make_request( "sites/{$site_id}/notes/{$note_id}" );
        }

        /**
         * Update a note for a site.
         *
         * @param int   $site_id The ID of the site.
         * @param int   $note_id The ID of the note.
         * @param array $data    The updated note data.
         * @return array|WP_Error The updated note object.
         */
        public function update_note( $site_id, $note_id, $data ) {
            $args = array( 'body' => $data );
            return $this->make_request( "sites/{$site_id}/notes/{$note_id}", $args, 'PUT' );
        }

        /**
         * Delete a note from a site.
         *
         * @param int $site_id The ID of the site.
         * @param int $note_id The ID of the note to delete.
         * @return array|WP_Error Response from the API.
         */
        public function delete_note( $site_id, $note_id ) {
            return $this->make_request( "sites/{$site_id}/notes/{$note_id}", array(), 'DELETE' );
        }

        /**
         * Get the latest performance report for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error The performance report data.
         */
        public function get_latest_performance_report( $site_id ) {
            return $this->make_request( "sites/{$site_id}/reports/performance/latest" );
        }

        /**
         * Get the usage report for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error The usage report data.
         */
        public function get_site_usage_report( $site_id ) {
            return $this->make_request( "sites/{$site_id}/reports/usage" );
        }

        /**
         * Retrieve time series metrics for a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $data    The metrics query data.
         * @return array|WP_Error The metrics data.
         */
        public function get_site_metrics( $site_id, $data ) {
            $args = array( 'body' => $data );
            return $this->make_request( "sites/{$site_id}/metrics", $args, 'POST' );
        }

        /**
         * ========================================================================
         * WordPress Management
         * ========================================================================
         */

        /**
         * Reset the site owner's WP-Admin password.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error The new password.
         */
        public function reset_wp_admin_password( $site_id ) {
            return $this->make_request( "sites/{$site_id}/wordpress/password-reset", array(), 'PUT' );
        }

        /**
         * Get a time-limited phpMyAdmin URL for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error The phpMyAdmin URL.
         */
        public function get_phpmyadmin_url( $site_id ) {
            return $this->make_request( "sites/{$site_id}/wordpress/phpmyadmin" );
        }

        /**
         * Update the WordPress version for a site.
         *
         * @param int    $site_id The ID of the site.
         * @param string $version The version to update to (e.g., 'latest').
         * @return array|WP_Error The updated site object.
         */
        public function update_wordpress_version( $site_id, $version = 'latest' ) {
            $args = array(
                'body' => array( 'version' => $version ),
            );
            return $this->make_request( "sites/{$site_id}/wordpress/version", $args, 'PUT' );
        }

        /**
         * Get a one-time login URL for a collaborator.
         *
         * @param int    $site_id    The ID of the site.
         * @param string $email      The collaborator's email.
         * @param string $user_agent The user agent of the browser that will use the URL.
         * @return array|WP_Error The one-time login URL.
         */
        public function get_one_press_login_url( $site_id, $email, $user_agent ) {
            $args = array(
                'body' => array(
                    'email'      => $email,
                    'user_agent' => $user_agent,
                ),
            );
            return $this->make_request( "sites/{$site_id}/one_press_login", $args, 'POST' );
        }

        /**
         * Get a list of WordPress users for a site.
         *
         * @param int $site_id The ID of the site.
         * @param int $page    The page number.
         * @param int $per_page The number of items per page.
         * @return array|WP_Error A list of WordPress user objects.
         */
        public function get_wordpress_users($site_id, $page = 1, $per_page = 200) {
            $args = array(
                'body' => array(
                    'page'     => $page,
                    'per_page' => $per_page,
                ),
            );
            return $this->make_request( "sites/{$site_id}/wordpress/users", $args, 'GET' );
        }

        /**
         * Create a new WordPress user on a site.
         *
         * @param int $site_id The ID of the site.
         * @param string $email The email for the new user.
         * @return array|WP_Error The new user's details including password.
         */
        public function create_wordpress_user($site_id, $email) {
            $args = array(
                'body' => array('email' => $email),
            );
            return $this->make_request( "sites/{$site_id}/wordpress/users", $args, 'POST' );
        }

        /**
         * Update a WordPress user's password.
         *
         * @param int $site_id The ID of the site.
         * @param string $user The user's login, email, or ID.
         * @param string|null $password The new password. A random one is generated if null.
         * @return array|WP_Error The new password.
         */
        public function update_wordpress_user_password($site_id, $user, $password = null) {
            $body = array('user' => $user);
            if ( ! is_null($password) ) {
                $body['password'] = $password;
            }
            $args = array('body' => $body);
            return $this->make_request( "sites/{$site_id}/wordpress/users/password-reset", $args, 'PUT' );
        }

        /**
         * Run WP-CLI commands on a site.
         *
         * @param int   $site_id  The ID of the site.
         * @param array $commands An array of WP-CLI commands to run.
         * @return array|WP_Error Response from the API.
         */
        public function run_wp_cli_commands( $site_id, $commands ) {
            $args = array(
                'body' => array( 'commands' => $commands ),
            );
            return $this->make_request( "sites/{$site_id}/wordpress/wpcli", $args, 'POST' );
        }
        
        /**
         * Run Bash commands on a site.
         *
         * @param int   $site_id  The ID of the site.
         * @param array $commands An array of Bash commands to run.
         * @return array|WP_Error Response from the API.
         */
        public function run_bash_commands( $site_id, $commands ) {
            $args = array(
                'body' => array( 'commands' => $commands ),
            );
            return $this->make_request( "sites/{$site_id}/wordpress/commands", $args, 'POST' );
        }

        /**
         * ========================================================================
         * Logs
         * ========================================================================
         */

        /**
         * Get PHP error logs for a site.
         *
         * @param int         $site_id   The ID of the site.
         * @param array       $params    Optional parameters like scroll_id and status.
         * @return array|WP_Error The PHP error logs.
         */
        public function get_php_error_logs( $site_id, $params = array() ) {
            $args = array( 'body' => $params );
            return $this->make_request( "sites/{$site_id}/logs/php", $args, 'GET' );
        }

        /**
         * Get web server logs for a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $params  Optional parameters like scroll_id, status, etc.
         * @return array|WP_Error The web server logs.
         */
        public function get_webserver_logs( $site_id, $params = array() ) {
            $args = array( 'body' => $params );
            return $this->make_request( "sites/{$site_id}/logs/webserver", $args, 'GET' );
        }

        /**
         * Get activity logs for a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $data    The request data containing page, per_page, and filters.
         * @return array|WP_Error A list of activity log objects.
         */
        public function get_site_activity_logs( $site_id, $data ) {
            $args = array( 'body' => $data );
            return $this->make_request( "sites/{$site_id}/logs/activity", $args, 'POST' );
        }

        /**
         * Get a list of filterable actions for site activity logs.
         *
         * @return array|WP_Error A list of available actions.
         */
        public function get_site_activity_log_actions() {
            return $this->make_request( 'activity/site-actions' );
        }

        /**
         * ========================================================================
         * Site Configuration & Firewall
         * ========================================================================
         */

        /**
         * Disconnect all SSH connections for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function disconnect_ssh( $site_id ) {
            return $this->make_request( "sites/{$site_id}/disconnect-ssh" );
        }

        /**
         * Set PHP filesystem permissions for a site.
         *
         * @param int    $site_id    The ID of the site.
         * @param string $permission The permission to set ('rw', 'ro', 'loggedin').
         * @return array|WP_Error Response from the API.
         */
        public function set_php_filesystem_permissions( $site_id, $permission ) {
            $args = array(
                'body' => array( 'php_fs_permission' => $permission ),
            );
            return $this->make_request( "sites/{$site_id}/php_fs_permissions", $args, 'PUT' );
        }

        /**
         * List the egress firewall rules for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error A list of firewall rule objects.
         */
        public function list_firewall_rules( $site_id ) {
            return $this->make_request( "sites/{$site_id}/firewall_rules" );
        }

        /**
         * Get a specific egress firewall rule for a site.
         *
         * @param int $site_id The ID of the site.
         * @param int $rule_id The ID of the firewall rule.
         * @return array|WP_Error A single firewall rule object.
         */
        public function get_firewall_rule( $site_id, $rule_id ) {
            return $this->make_request( "sites/{$site_id}/firewall_rules/{$rule_id}" );
        }

        /**
         * Create an egress firewall rule for a site.
         *
         * @param int   $site_id The ID of the site.
         * @param array $data    The rule data (destination, port, protocol).
         * @return array|WP_Error The new firewall rule object.
         */
        public function create_firewall_rule( $site_id, $data ) {
            $args = array( 'body' => $data );
            return $this->make_request( "sites/{$site_id}/firewall_rules/create", $args, 'POST' );
        }

        /**
         * Delete an egress firewall rule for a site.
         *
         * @param int $site_id The ID of the site.
         * @param int $rule_id The ID of the firewall rule to delete.
         * @return array|WP_Error Response from the API.
         */
        public function delete_firewall_rule( $site_id, $rule_id ) {
            return $this->make_request( "sites/{$site_id}/firewall_rules/{$rule_id}", array(), 'DELETE' );
        }

        /**
         * Refresh the egress firewall rules for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function refresh_firewall_rules( $site_id ) {
            return $this->make_request( "sites/{$site_id}/firewall_rules/refresh", array(), 'POST' );
        }
        
        /**
         * ========================================================================
         * APM (Application Performance Monitoring)
         * ========================================================================
         */

        /**
         * Activate APM for a site.
         *
         * @param int $site_id  The ID of the site.
         * @param int $duration The duration in minutes (5, 10, or 30).
         * @return array|WP_Error Response from the API.
         */
        public function activate_apm( $site_id, $duration ) {
            $args = array(
                'body' => array( 'apm_duration' => $duration ),
            );
            return $this->make_request( "sites/{$site_id}/apm/activate", $args, 'POST' );
        }

        /**
         * Deactivate APM for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error Response from the API.
         */
        public function deactivate_apm( $site_id ) {
            return $this->make_request( "sites/{$site_id}/apm/deactivate", array(), 'POST' );
        }

        /**
         * Get the APM trace URL for a site.
         *
         * @param int $site_id The ID of the site.
         * @return array|WP_Error The APM URL data.
         */
        public function get_apm_url( $site_id ) {
            return $this->make_request( "sites/{$site_id}/apm/url" );
        }

        /**
         * ========================================================================
         * Site Usage Limits
         * ========================================================================
         */

        /**
         * Set the filesystem usage limit for a site.
         *
         * @param int $site_id          The ID of the site.
         * @param int $limit            The maximum usage in bytes.
         * @param int $warning_headroom The warning threshold in bytes from the limit.
         * @return array|WP_Error The updated site object.
         */
        public function set_filesystem_usage_limit( $site_id, $limit, $warning_headroom ) {
            $args = array(
                'body' => array(
                    'limit'           => $limit,
                    'warning_headroom' => $warning_headroom,
                ),
            );
            return $this->make_request( "sites/{$site_id}/usage/filesystem", $args, 'PUT' );
        }

        /**
         * Set the database usage limit for a site.
         *
         * @param int $site_id          The ID of the site.
         * @param int $limit            The maximum usage in bytes.
         * @param int $warning_headroom The warning threshold in bytes from the limit.
         * @return array|WP_Error The updated site object.
         */
        public function set_database_usage_limit( $site_id, $limit, $warning_headroom ) {
            $args = array(
                'body' => array(
                    'limit'           => $limit,
                    'warning_headroom' => $warning_headroom,
                ),
            );
            return $this->make_request( "sites/{$site_id}/usage/database", $args, 'PUT' );
        }
    }
}
