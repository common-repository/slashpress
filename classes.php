<?php

namespace SlashPress;

class Command {

	protected $service_id;
	protected $name;
	protected $response_url;
	protected $data;
	protected $known = false;
	protected $handled = false;
	protected $help = [];

	function __construct(array $data, string $service_id) {
		if (empty($data['command']) || !is_string($data['command'])) {
			throw new VisibleException(_x('Invalid slash command.', 'api-response', 'slashpress'));
		}
		else {
			$this->name = preg_replace('#^/#', '', $data['command']);
			if ('' == $this->name) {
				throw new VisibleException(_x('Empty slash command.', 'api-response', 'slashpress'));
			}
		}
		if (!empty($data['response_url'])) {
			$response_url = filter_var($data['response_url'], \FILTER_VALIDATE_URL, \FILTER_FLAG_PATH_REQUIRED);
			if ($response_url && preg_match('#https?:#', $response_url)) {
				$this->response_url = $response_url;
			}
			else {
				throw new VisibleException(_x('Response URL looks malformed.', 'api-response', 'slashpress'));
			}
		}
		unset($data['token']);
		$this->data = $data;
		$this->service_id = $service_id;
	}

	function addHelp($topics, string $help_text) {
		foreach ((array) $topics as $topic) {
			$this->help[$topic][] = $help_text;
		}
		return $this;
	}
	function respondDelayed(string $response, array $args = []) {
		if ($this->canRespondDelayed()) {
			return wp_remote_post(
				$this->response_url
				, [
					'body' => $response,
				]
					+ $args
					+ [
						'httpversion' => '1.1',
					]
			);
		}
		return new \WP_Error(400, _x('No URL to which a response can be sent', 'coding-error', 'slashpress'));
	}
	function respondDelayedRich($response, array $args = []) {
		return $this->respondDelayed(json_encode($response), $args);
	}
	function canRespondDelayed() {
		return null !== $this->response_url;
	}

	function __get(string $name) {
		return $this->$name;
	}
	function __isset(string $name) {
		return isset($this->$name);
	}
	function __set(string $name, $value) {
		switch ($name) {
			case 'handled':
				if ($value) {
					$this->known = true;
				}
			case 'known':
				$this->$name = (bool) $value;
				break;
			default:
				/* translators: %s invalid variable name */
				trigger_error(sprintf(_x('Cannot set property %s', 'coding-error', 'slashpress'), $name), \E_USER_WARNING);
		}
	}
}

class VisibleException extends \Exception {
}
