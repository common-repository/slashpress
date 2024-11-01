<?php

namespace SlashPress;

defined('ABSPATH') or die;

add_action(
	'admin_menu'
	, function() {
		$slug_admin = ADMIN_BASE . '-admin';
		$slug_settings = ADMIN_BASE . '-settings';
		$title = esc_html_x('SlashPress', 'page-title', 'slashpress');
		$options = null;

		add_options_page(
			$title
			, esc_html_x('SlashPress', 'settings-menu-title', 'slashpress')
			, 'administrator'
			, $slug_admin
			, function() use (&$options, $title, $slug_admin, $slug_settings) {
				$options = get_option(ADMIN_BASE);
				?>
				<div class="wrap">
					<h1><?= $title ?></h1>
					<form action="options.php" method=post>
						<?php
						settings_fields($slug_settings);
						do_settings_sections($slug_admin);
						submit_button();
						?>
					</form>
				</div>
				<?php
			}
		);

		add_action(
			'admin_init'
			, function() use (&$options, $slug_admin, $slug_settings) {
				register_setting(
					$slug_settings
					, ADMIN_BASE
					, [
						'sanitize_callback' => function($inputs) {
							$options = [];
							foreach ($inputs as $option => $val) {
								switch ($option) {
									case 'visible':
										$options[$option] = (bool) $val;
										break;
									case 'help_word':
										$val = trim($val);
										if ('' !== $val) {
											$options[$option] = $val;
										}
										break;
									case 'tokens':
										foreach ($val[$option] as $i => $token) {
											$token = trim($token);
											$service_id = preg_replace('#[\s/]+#', '', $val['service_ids'][$i]);
											if ('' != "$token$service_id") {
												$options[$option][$token][] = $service_id;
											}
										}
										if (isset($options[$option])) {
											$options[$option] = array_map('array_unique', $options[$option]);
										}
										break;
									case 'secrets':
										foreach ($val[$option] as $i => $secret) {
											$secret = trim($secret);
											$service_id = preg_replace('#[\s/]+#', '', $val['service_ids'][$i]);
											if ('' != "$secret$service_id") {
												$options[$option][$service_id][] = $secret; # keyed opposite to tokens for lookup
											}
										}
										if (isset($options[$option])) {
											$options[$option] = array_map('array_unique', $options[$option]);
										}
										break;
								}
							}
							return $options;
						},
					]
				);

				$slug_sect = ADMIN_BASE . '-general';
				add_settings_section(
					$slug_sect
					, esc_html_x('General', 'settings-section', 'slashpress')
					, '__return_null'
					, $slug_admin
				);
				$name = 'visible';
				add_settings_field(
					$name
					, esc_html_x('Visibility', 'setting', 'slashpress')
					, function() use (&$options, $name) {
						printf(
							'<input type=hidden name="%1$s[%2$s]" value=1>'
								 . '<label><input type=checkbox name="%1$s[%2$s]" value=0%3$s> %4$s</label>'
							, ADMIN_BASE
							, $name
							, empty($options[$name]) ? ' checked' : ''
							, esc_html__('Conceal plugin presence from REST API index.', 'slashpress')
						);
					}
					, $slug_admin
					, $slug_sect
				);
				$name = 'help_word';
				add_settings_field(
					$name
					, esc_html_x('Help command keyword', 'setting', 'slashpress')
					, function() use (&$options, $name) {
						printf(
							'<input type=text class=regular-text name="%1$s[%2$s]" value="%3$s" placeholder="%4$s">'
							, ADMIN_BASE
							, $name
							, $options[$name] ?? ''
							, HELP_WORD_DEFAULT
						);
					}
					, $slug_admin
					, $slug_sect
				);

				$slug_sect = ADMIN_BASE . '-auth';
				add_settings_section(
					$slug_sect
					, esc_html_x('Authentication', 'settings-section', 'slashpress')
					, function() {
						?>
						<p><?= __('Create service identifiers to tell you who is sending a command, without relying on any ID internal to a service. This ID is passed to the hooks to which your plugin attaches for permissions, attribution, etc. You can use the same ID for multiple services to group them as a single actor.') ?></p>
						<?php
					}
					, $slug_admin
				);
				$name = 'tokens';
				add_settings_field(
					$name
					, esc_html_x('With tokens', 'setting', 'slashpress')
					, function() use (&$options, $name) {
						$tokens = ($options[$name] ?? []) + ['' => []];
						$tokens[''][] = '';
						?>
						<table>
							<thead>
								<tr>
									<th><?= esc_html_x('Service ID', 'setting', 'slashpress') ?></th>
									<th><?= esc_html_x('Token', 'setting', 'slashpress') ?></th>
								</tr>
							</thead>
							<tbody>
						<?php foreach ($tokens as $token => $service_ids) {
							foreach ($service_ids as $service_id) {
								printf(
									<<<'EOHTML'
								<tr>
									<td><input type=text class=regular-text name="%1$s[%2$s][service_ids][]" value="%3$s" pattern="\s*[^\s/]+\s*" title="%4$s"></td>
									<td><input type=text class=regular-text name="%1$s[%2$s][%2$s][]" value="%5$s" pattern="\s*\S+\s*" title="%6$s"></td>
								</tr>

EOHTML
									, ADMIN_BASE
									, $name
									, esc_attr($service_id)
									, esc_attr__('Must not contain spaces or forward slashes.', 'slashpress')
									, esc_attr($token)
									, esc_attr__('The token Mattermost or Slack provides after creation of the slash command integration or app.', 'slashpress')
								);
							}
						} ?>
							</tbody>
						</table>
						<p class=description><?= sprintf(
							/* translators: 1: Slash command endpoint URL; 2: the URL parameter placeholder */
							esc_html__('Endpoint for token-authenticated commands is %1$s where %2$s is replaced with an ID you have set, above.', 'slashpress')
							, '<code>' . esc_html(rest_url(ENDPOINT_NS . ENDPOINT_BY_TOKEN_PRETTY)) . '</code>'
							, '<code>' . esc_html(ENDPOINT_SERVICE_ID_PRETTY) . '</code>'
						) ?></p>

						<?php
					}
					, $slug_admin
					, $slug_sect
				);
				$name = 'secrets';
				add_settings_field(
					$name
					, esc_html_x('With signatures', 'setting', 'slashpress')
					, function() use (&$options, $name) {
						$service_ids = ($options[$name] ?? []) + ['' => []];
						$service_ids[''][] = '';
						?>
						<table>
							<thead>
								<tr>
									<th><?= esc_html_x('Service ID', 'setting', 'slashpress') ?></th>
									<th><?= esc_html_x('Signing secret', 'setting', 'slashpress') ?></th>
								</tr>
							</thead>
							<tbody>
						<?php foreach ($service_ids as $service_id => $secrets) {
							foreach ($secrets as $secret) {
								printf(
									<<<'EOHTML'
								<tr>
									<td><input type=text class=regular-text name="%1$s[%2$s][service_ids][]" value="%3$s" pattern="\s*[^\s/]+\s*" title="%4$s"></td>
									<td><input type=text class=regular-text name="%1$s[%2$s][%2$s][]" value="%5$s" pattern="\s*\S+\s*" title="%6$s"></td>
								</tr>

EOHTML
									, ADMIN_BASE
									, $name
									, esc_attr($service_id)
									, esc_attr__('Must not contain spaces or forward slashes.', 'slashpress')
									, esc_attr($secret)
									, esc_attr__('The signing secret Slack provides after creation of the app.', 'slashpress')
								);
							}
						} ?>
							</tbody>
						</table>
						<p class=description><?= sprintf(
							/* translators: 1: Slash command endpoint URL; 2: the URL parameter placeholder */
							esc_html__('Endpoint for signature-authenticated commands is %1$s where %2$s is replaced with an ID you have set, above.', 'slashpress')
							, '<code>' . esc_html(rest_url(ENDPOINT_NS . ENDPOINT_BY_SIG_PRETTY)) . '</code>'
							, '<code>' . esc_html(ENDPOINT_SERVICE_ID_PRETTY) . '</code>'
						) ?></p>

						<?php
					}
					, $slug_admin
					, $slug_sect
				);
			}
		);
	},
	9999
);
add_filter(
	'plugin_action_links_' . PLUGIN_BASENAME
	, function ($links) {
		array_unshift(
			$links
			, '<a href="' . admin_url('options-general.php?page=' . ADMIN_BASE . '-admin') . '">'
				. esc_html_x('Settings', 'plugins-page-link', 'slashpress') . '</a>'
		);
		return $links;
	}
);
