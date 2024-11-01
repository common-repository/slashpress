=== SlashPress ===
Contributors: lev0
Tags: Mattermost,Slack,slash commands,ChatOps
Requires at least: 4.7.1
Tested up to: 6.1.1
Stable tag: 1.1.0
Requires PHP: 7.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A conduit between your chat service and your WordPress sites.

== Description ==

It's very easy to create a [custom slash command on Mattermost][mm-sc], or [a private app on Slack][slack-app] that has [the slash command feature][slack-sc-help]. This plugin turns that convenient chat interface into a subscribable event using standard WordPress filter & action hooks. This enables automation of tasks that need to be run on-demand, and provision of interactive help for them.

The hooks provided are as follows:

* `slashpress_command_${command}`
* `slashpress_command`
* `slashpress_help_${command}`
* `slashpress_help`

The subscribed events receive a helper object representing the sent slash command, with methods to respond using Markdown or a rich response object the chat service can render. Plugins may listen for a specific slash command or a site-wide one, and respond based on the command content. Long-running tasks (> 3 seconds) can provide an immediate acknowledgement response, then later a result response; this is easily achieved by ensuring a [proper cron invocation][wp-cron] for the site, then passing the helper object to `wp_schedule_single_event()` to run the task in the background and POST a status message back upon completion.

By itself, this plugin doesn't do anything. It is aimed at developers and maintainers to abstract away the boring plumbing and authentication, allowing you to keep your code DRY. It supports authentication by both tokens and HMAC signatures. There is no limit on the number of such integrations this endpoint can handle. Only POST method requests are accepted and sent so access logs are kept clean. The interactive help keyword is configurable.

There is no logging, metrics, analytics, nags, or anything that would violate your privacy or GDPR obligations contained in this plugin. It is not freemium; there is no 'Pro' version.

[mm-sc]: https://docs.mattermost.com/developer/slash-commands.html#custom-slash-command
[slack-app]: https://api.slack.com/apps
[slack-sc-help]: https://api.slack.com/interactivity/slash-commands
[wp-cron]: https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/

== Installation ==

1. Upload the entire, extracted plugin directory to the `wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen directly.
1. Activate the plugin through the Plugins screen.
1. Go to *Settings > SlashPress*, add a service ID, and save.
1. Note the endpoint URLs provided and insert them into your chat service integration.
1. Copy the authentication credential from your service to the plugin settings and save again.
1. Test the integration by entering your slash command followed by `help`.
1. Subscribe to the hooks in your own code.

== Frequently Asked Questions ==

= What is this good for? =

Running any task any task that you want to start immediately from the comfort of your chat app. It's great for providing instant summaries, triggering actions like backups, clearing/preloading caches of optimisation plugins, updating copies of remote data.

Your code can respond with anything you need, from a simple `OK` to a full tabulated response using Markdown:

	|Order stat|Count|
	|:---|---:|
	|New orders today|27|
	|Orders to fulfil|8|
	|Unpaid orders|2|

= So how do I use this thing? =

A simple example is probably best:

	add_action(
		'slashpress_help'
		, function(SlashPress\Command $slash, string $help_terms) {
			$slash->addHelp('flubbers', '`flubbers` Gets the latest map of nearby flubbers.')
				->addHelp('gronks', '`gronks` Updates the list of the top 100 gronks and their values.')
				->addHelp('uncache', 'Site content not looking quite right? Use `uncache` to clear the out the generated pages.');
		}
		, 100
		, 2
	);

	add_filter(
		'slashpress_command'
		, function($initial_response, SlashPress\Command $slash) {
			if (!$slash->known) {
				$text = trim($slash->data['text']);
				switch ($text) {
					case 'flubbers':
					case 'gronks':
						$slash->handled = true;
						wp_schedule_single_event(time(), 'big_data_fetch_cron_event_hook', [$text, $slash]);
						return 'Big data fetch queued.';
					case 'uncache':
						$slash->handled = true;
						if (function_exists('w3tc_flush_posts')) {
							w3tc_flush_posts();
							return 'Cleared the post cache.';
						}
						return 'No cache found to clear.';
				}
			}
			return $initial_response;
		}
		, 10
		, 2
	);

	add_action(
		'big_data_fetch_cron_event_hook'
		, function(string $what = null, SlashPress\Command $slash = null) {
			$results_bad = $results = [];
			if (null == $what || 'flubbers' == $what) {
				if (fetch_flubbers()) {
					$results[] = 'Flubbers fetched.';
				}
				else {
					$results_bad[] = $results[] = 'Could not fetch the flubbers.';
				}
			}
			if (null == $what || 'gronks' == $what) {
				if (fetch_gronks()) {
					$results[] = 'Gronks fetched.';
				}
				else {
					$results_bad[] = $results[] = 'Could not fetch the gronks.';
				}
			}
			if ($slash) {
				if ($slash->canRespondDelayed()) {
					$slash->respondDelayed(implode("  \n", $results));
				}
			}
			elseif ($results_bad) {
				echo implode("  \n", $results_bad);
			}
		}
	);
