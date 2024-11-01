<?php
/*
Plugin Name: SlashPress
Description: Conduit between plugins in this WordPress installation, and your custom-defined slash commands in Mattermost or Slack chat services.
Version: 1.1.0
Author: Roy Orbison
Licence: GPLv2 or later
*/

namespace SlashPress;

defined('ABSPATH') or die;

const ADMIN_BASE = 'slashpress';
const ENDPOINT_NS = ADMIN_BASE . '/v1';
const ENDPOINT = '(?P<service_id>[^\s/]+)/(?P<auth_method>token|sig)';
const ENDPOINT_SERVICE_ID_PRETTY = '{service_id}';
const ENDPOINT_BY_TOKEN_PRETTY = '/' . ENDPOINT_SERVICE_ID_PRETTY . '/token/';
const ENDPOINT_BY_SIG_PRETTY = '/' . ENDPOINT_SERVICE_ID_PRETTY . '/sig/';
const SIG_V = 'v0';
define(__NAMESPACE__ . '\HELP_WORD_DEFAULT', _x('help', 'help-command-keyword', 'slashpress'));
define(__NAMESPACE__ . '\PLUGIN_BASENAME', plugin_basename(__FILE__));

spl_autoload_register(function ($class) {
	if (preg_match('/^' . __NAMESPACE__ . '\\\\/i', $class)) {
		require_once __DIR__ . '/classes.php';
	}
});
if (is_admin()) {
	include __DIR__ . '/admin.php';
}

add_action(
	'rest_api_init'
	, function () {
		$options = get_option(ADMIN_BASE);
		if (!isset($options['visible'])) {
			return;
		}

		register_rest_route(
			ENDPOINT_NS
			, ENDPOINT
			, [
				'show_in_index' => $options['visible'],
				'methods' => 'POST',
				'callback' => function($request) use (&$options) {
					if (isset($request['ssl_check'])) { # not a command
						exit;
					}

					$params_url = $request->get_url_params();
					$params_post = $request->get_body_params();
					try {
						if (!isset($params_url['service_id']) || !is_string($params_url['service_id'])) {
							throw new VisibleException(_x('Invalid service ID.', 'api-response', 'slashpress'));
						}
						switch ($params_url['auth_method']) {
							case 'token':
								if (empty($params_post['token']) || !is_string($params_post['token'])) {
									throw new VisibleException(_x('Invalid authentication.', 'api-response', 'slashpress'));
								}
								if (
									!isset($options['tokens'][$params_post['token']])
									|| !in_array($params_url['service_id'], $options['tokens'][$params_post['token']])
								) {
									throw new VisibleException(_x('Unknown requester.', 'api-response', 'slashpress'));
								}
								$service_id = $params_url['service_id'];
								break;
							case 'sig':
								$sig_time =  $request->get_header('X-Slack-Request-Timestamp');
								$sig = $request->get_header('X-Slack-Signature');
								if (
									!$sig_time
									|| !$sig
									|| abs(time() - $sig_time) >= 300
									|| strpos($sig, SIG_V . '=') !== 0
								) {
									throw new VisibleException(_x('Invalid authentication.', 'api-response', 'slashpress'));
								}
								if (!isset($options['secrets'][$params_url['service_id']])) {
									throw new VisibleException(_x('Unknown requester.', 'api-response', 'slashpress'));
								}
								foreach ($options['secrets'][$params_url['service_id']] as $secret) {
									$sig_calcd = SIG_V . '=' . hash_hmac(
										'sha256'
										, SIG_V . ":$sig_time:" . $request->get_body()
										, $secret
									);
									if (hash_equals($sig_calcd, $sig)) {
										$service_id = $params_url['service_id'];
										break 2;
									}
								}
								throw new VisibleException(_x('Unknown requester.', 'api-response', 'slashpress'));
							default:
								throw new VisibleException(_x('Unknown authentication method.', 'api-response', 'slashpress'));
						}
						$slash = new Command($params_post, $service_id);
					}
					catch (VisibleException $e) {
						header('Content-Type: text/plain');
						echo $e->getMessage();
						exit;
					}
					catch (\Exception $e) {
						header('Content-Type: text/plain');
						_ex('Unknown error.', 'api-response', 'slashpress');
						exit;
					}

					$initial_response = '';
					$help_word = $options['help_word'] ?? HELP_WORD_DEFAULT;
					if (
						preg_match(
							'/\A\s*' . preg_quote($help_word, '/') . '(?:\s+(\S.*?))?\s*\z/i'
							, $params_post['text']
							, $help_terms
						)
					) {
						$help_terms = isset($help_terms[1]) ? preg_replace('/\s+/', ' ', $help_terms[1]) : '';
						goto help_response;
					}

					/**
					 * Respond to specific slash command
					 *
					 * @since 1.0.0
					 *
					 * @param mixed $initial_response text or JSON rich response for immediate display to command invoker
					 * @param SlashPress\Command $slash an interactive object representing the command sent and the service ID (as set in the plugin settings)
					 */
					$initial_response = apply_filters("slashpress_command_$slash->name", $initial_response, $slash);
					if (!$slash->known) {
						/**
						 * Respond to any slash command
						 *
						 * @since 1.0.0
						 *
						 * @param mixed $initial_response text or JSON rich response for immediate display to command invoker
						 * @param SlashPress\Command $slash an interactive object representing the command sent and the service ID (as set in the plugin settings)
						 */
						$initial_response = apply_filters('slashpress_command', $initial_response, $slash);
					}

					if (!$slash->known) {
						$help_terms = $params_post['text'];
						$initial_response = sprintf(
							/* translators: %s: the help keyword setting or (pre-translated) default */
							_x("Don't know what to do with that command. Type `%s` for help.", 'api-response', 'slashpress')
							, $help_word
						);
						goto help_response;
					}
					if (!$slash->handled) {
						if (empty($initial_response)) {
							$initial_response = _x('Command was understood but no action was performed for some reason.', 'api-response', 'slashpress');
						}
						goto initial_response;
					}
					if ($initial_response && !is_scalar($initial_response)) {
						return $initial_response;
					}
					$initial_response = (string) $initial_response;
					if ('' === $initial_response) {
						$initial_response = _x('Command received.', 'api-response', 'slashpress');
					}

					initial_response: {
						header('Content-Type: text/plain');
						echo $initial_response;
						exit;
					}

					help_response: {
						$help_terms = trim(preg_replace('/\s+/', ' ', $help_terms), ' ');
						/**
						 * Provide help for a specific slash command
						 *
						 * @since 1.0.0
						 *
						 * @param SlashPress\Command $slash an interactive object representing the command sent and the service ID (as set in the plugin settings)
						 * @param string $help_terms normalised text of user's request to attempt matching of specific help topics
						 */
						do_action("slashpress_help_$slash->name", $slash, $help_terms);
						if (!$slash->help) {
							/**
							 * Provide help for any slash command
							 *
							 * @since 1.0.0
							 *
							 * @param SlashPress\Command $slash an interactive object representing the command sent and the service ID (as set in the plugin settings)
							 * @param string $help_terms normalised text of user's request to attempt matching of specific help topics
							 */
							do_action('slashpress_help', $slash, $help_terms);
						}
						if ($slash->help) {
							$help = $slash->help;
							if (isset($help[$help_terms])) {
								$help = array_intersect_key($help, [$help_terms => null]);
							}
						}
						else {
							$help = [
								'' => [
									_x('No help is available.', 'api-response', 'slashpress'),
								],
							];
						}
						$first = '' == $initial_response;
						foreach ($help as $topic => $topic_helps) {
							foreach ($topic_helps as $topic_help) {
								if ($first) {
									$first = false;
								}
								else {
									$initial_response .= "  \n";
								}
								$initial_response .= $topic_help;
							}
						}
						goto initial_response;
					}
				},
			]
		);

		if (!$options['visible']) {
			add_filter(
				'rest_index'
				, function($response) {
					$response->data['namespaces'] = array_values(array_diff(
						$response->data['namespaces']
						, [ENDPOINT_NS]
					));
					foreach ($response->data['routes'] as $path => $route) {
						if (ENDPOINT_NS === $route['namespace']) {
							unset($response->data['routes'][$path]);
						}
					}
					return $response;
				}
			);
			add_filter(
				'rest_namespace_index'
				, function($response, $request) {
					if (ENDPOINT_NS === $request['namespace']) {
						unset($response->data['routes']);
					}
					return $response;
				}
				, 10
				, 2
			);
		}
	}
);
