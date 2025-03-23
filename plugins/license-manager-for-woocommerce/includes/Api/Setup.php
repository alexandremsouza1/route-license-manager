<?php

namespace LicenseManagerForWooCommerce\Api;

defined('ABSPATH') || exit;

class Setup
{
    /**
     * Setup class constructor.
     */
    public function __construct()
    {
        // REST API was included starting WordPress 4.4.
        if (!class_exists('\WP_REST_Server')) {
            return;
        }

        // Init REST API routes.
        add_action('rest_api_init', array($this, 'registerRoutes'), 10);

		// Init related actions and filters.
        add_filter('lmfwc_rest_api_pre_response', array($this, 'preResponse'), 1, 3);
		add_filter('lmfwc_rest_api_validation', array($this, 'validation'), 10, 3);
    }

    /**
     * Initializes the plugin API controllers.
     */
    public function registerRoutes()
    {
        $controllers = array(
            // REST API v2 controllers.
            '\LicenseManagerForWooCommerce\Api\V2\Licenses',
            '\LicenseManagerForWooCommerce\Api\V2\Generators'
        );

        foreach ($controllers as $controller) {
            $controller = new $controller();
            $controller->register_routes();
        }
    }

    /**
     * Allows developers to hook in and modify the response of any API route
     * right before being sent out.
     *
     * @param string $method Contains the HTTP method which was used in the request
     * @param string $route  Contains the request endpoint name
     * @param array  $data   Contains the response data
     *
     * @return array
     */
    public function preResponse($method, $route, $data)
    {
        if ($method === 'GET' && strpos($route, 'licenses') !== false && strpos($route, 'activate') === false) {

			//Get key from url
			$licenseKey = $data['licenseKey'];

			//run query to database for checking the ID
			$licenseId = false;
			$license = \LicenseManagerForWooCommerce\Repositories\Resources\License::instance()->findBy(
				array(
					'hash' => apply_filters('lmfwc_hash', $licenseKey)
				)
			);

			//Get license id by license key, or return error
			if ($license) {
				$licenseId = $license->getId();
			} else {
				return new WP_Error(
					'lmfwc_rest_data_error',
					'License Key ID invalid.',
					array('status' => 404)
				);
			}

			if ($data['timesActivated'] == "0") {
				$data['ipcheck'] = false;
			}

			//Get license meta by ID
			$db_ip = lmfwc_get_license_meta($licenseId, "ip", true);
			$db_port = lmfwc_get_license_meta($licenseId, "port", true);
			$db_host_ip = lmfwc_get_license_meta($licenseId, "hostname", true);

			write_log("Data in database: " . $db_ip . ":" . $db_port . " [" . $db_host_ip . "]");

			//If meta not found, only return the data
			if (!$db_ip || !$db_port) {
				return $data;
			}

			if ($db_host_ip) {
				//Get by hostname if found
				$db_host_ip = gethostbyname($db_host_ip);
			}

			//GET IP from user
			$ip = array_key_exists('HTTP_CLIENT_IP', $_SERVER) ? $_SERVER['HTTP_CLIENT_IP'] : (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']);

			write_log("Got request by: " . $ip);

			if ($ip == $db_ip) {
				//IP and DB IP are same
				$data['ipcheck'] = true;
			} else if ($db_host_ip != false && $ip == $db_host_ip) {
				//IP and DB Host IP are same
				$data['ipcheck'] = true;
			} else {
				$data['ipcheck'] = false;
			}

			$data['port'] = $db_port;

			return $data;
		}

		return $data;
    }
	
	/**
     * Allows developers to hook in and append things on activation.
     *
     * @param bool   $result   True if valid, false if not
     * @param string $server   The server
     * @param string $request  The request itself
     *
     * @return bool Valid or not?
     */
	public function validation($result, $server, $request)
	{
		$route = $request->get_route(); // Returns "/lmfwc/v2/licenses/activate/THE-PRETENDER" for example
		$method = $request->get_method(); // Returns "GET" for example

		// We now know that this is a "Activate license" request, we can now do our validation
		if ($method === 'GET' && strpos($request->get_route(), 'activate') !== false && strpos($request->get_route(), 'deactivate') == false) {
			$lic = explode('/', $request->get_route());

			//If key not found, return error
			if (!$lic) {
				return new WP_Error(
					'lmfwc_rest_data_error',
					'License Key not found.',
					array('status' => 404)
				);
			}

			//If to short, return error
			if (count($lic) != 6) {
				return new WP_Error(
					'lmfwc_rest_data_error',
					'License Key invalid.',
					array('status' => 404)
				);
			}

			//Get key from URL
			$licenseKey = sanitize_text_field($lic[5]);

			//Get ID from database
			$licenseId = false;
			/** @var \LicenseManagerForWooCommerce\Models\Resources\License $license */
			$license = \LicenseManagerForWooCommerce\Repositories\Resources\License::instance()->findBy(
				array(
					'hash' => apply_filters('lmfwc_hash', $licenseKey)
				)
			);

			if ($license) {
				$licenseId = $license->getId();
			} else {
				//ID not found, return error
				return new WP_Error(
					'lmfwc_rest_data_error',
					'License Key ID invalid.',
					array('status' => 404)
				);
			}

			//Get port from URL
			$instance = sanitize_text_field($request->get_param('port'));

			//If not found, return error
			if (!$instance) {
				return new WP_Error(
					'lmfwc_rest_data_error',
					'Port not found!',
					array('status' => 404)
				);
			}

			//Get IP
			$ip = array_key_exists('HTTP_CLIENT_IP', $_SERVER) ? $_SERVER['HTTP_CLIENT_IP'] : (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR']);
			$hostname = gethostbyaddr($ip);

			lmfwc_add_license_meta($licenseId, "ip", $ip);
			lmfwc_add_license_meta($licenseId, "port", $instance);

			if ($hostname != false) {
				lmfwc_add_license_meta($licenseId, "hostname", $hostname);
			}

		}

		return true;
	}
}