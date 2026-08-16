<?php
/**
 * Admin screen.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Settings → MCP Connector screen.
 *
 * Split across five tabs rather than one long scroll. The original single page
 * ran past two thousand pixels and mixed "connect a client" with "revoke a
 * credential", which are different jobs done at different times: one is setup,
 * the other is maintenance. Tabs let each one be a short, finishable screen.
 *
 * Each tab's form declares the settings keys it owns via a `_fields` list, so
 * saving one tab never resets another. See WPMCP_Settings::sanitize().
 */
class WPMCP_Admin {

	const PAGE = 'wp-mcp-connector';

	/**
	 * A token issued during this request, shown once and then forgotten.
	 *
	 * @var string
	 */
	private $new_token = '';

	/**
	 * Hooks the admin screen.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPMCP_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the settings page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'MCP Connector', 'wp-mcp-connector' ),
			__( 'MCP Connector', 'wp-mcp-connector' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Loads styles and behaviour, on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpmcp-admin', WPMCP_URL . 'assets/admin.css', array(), WPMCP_VERSION );
		wp_enqueue_script( 'wpmcp-admin', WPMCP_URL . 'assets/admin.js', array(), WPMCP_VERSION, true );
	}

	/**
	 * Adds a Settings link on the plugins list.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'wp-mcp-connector' ) . '</a>' );

		return $links;
	}

	/**
	 * The tab definitions.
	 *
	 * @return array<string,string>
	 */
	private function tabs() {
		return array(
			'connect'     => __( 'Connect', 'wp-mcp-connector' ),
			'permissions' => __( 'Permissions', 'wp-mcp-connector' ),
			'security'    => __( 'Security', 'wp-mcp-connector' ),
			'credentials' => __( 'Credentials', 'wp-mcp-connector' ),
			'activity'    => __( 'Activity', 'wp-mcp-connector' ),
		);
	}

	/**
	 * The tab currently being viewed.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'connect';
	}

	/**
	 * Builds a URL for a tab.
	 *
	 * @param string $tab Tab key.
	 * @return string
	 */
	private function tab_url( $tab ) {
		return admin_url( 'options-general.php?page=' . self::PAGE . '&tab=' . $tab );
	}

	/**
	 * Handles form submissions.
	 *
	 * @return void
	 */
	public function handle_post() {
		if ( empty( $_POST['wpmcp_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['wpmcp_action'] ) );
		check_admin_referer( 'wpmcp_' . $action );

		switch ( $action ) {
			case 'save_settings':
				$raw = isset( $_POST['wpmcp'] ) ? wp_unslash( $_POST['wpmcp'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				wpmcp()->settings()->save( (array) $raw );
				add_settings_error( 'wpmcp', 'saved', __( 'Settings saved.', 'wp-mcp-connector' ), 'success' );
				break;

			case 'issue_token':
				$user_id = isset( $_POST['token_user'] ) ? (int) $_POST['token_user'] : 0;
				$label   = isset( $_POST['token_label'] ) ? sanitize_text_field( wp_unslash( $_POST['token_label'] ) ) : '';
				$ttl     = isset( $_POST['token_ttl'] ) ? (int) $_POST['token_ttl'] : 0;
				$scope   = isset( $_POST['token_scope'] ) ? sanitize_key( wp_unslash( $_POST['token_scope'] ) ) : '';

				$issued = WPMCP_Auth::issue_token( $user_id, $label, $ttl, $scope );

				if ( is_wp_error( $issued ) ) {
					add_settings_error( 'wpmcp', 'token', $issued->get_error_message(), 'error' );
				} else {
					$this->new_token = $issued['token'];
				}
				break;

			case 'revoke_token':
				$id = isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '';

				if ( WPMCP_Auth::revoke_token( $id ) ) {
					add_settings_error( 'wpmcp', 'token', __( 'Token revoked. Any client using it stops working immediately.', 'wp-mcp-connector' ), 'success' );
				}
				break;

			case 'register_client':
				$name = isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
				$raw  = isset( $_POST['client_redirects'] ) ? wp_unslash( $_POST['client_redirects'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

				$uris = array();

				foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $uri ) {
					$uri = esc_url_raw( trim( $uri ) );

					if ( '' !== $uri && WPMCP_OAuth::is_valid_redirect_uri( $uri ) ) {
						$uris[] = $uri;
					}
				}

				if ( ! $uris ) {
					add_settings_error( 'wpmcp', 'client', __( 'At least one valid redirect URI is required. It must be https, or http on 127.0.0.1 or localhost.', 'wp-mcp-connector' ), 'error' );
					break;
				}

				$client = WPMCP_OAuth::register_client( $name ? $name : __( 'Unnamed client', 'wp-mcp-connector' ), $uris, false );

				add_settings_error(
					'wpmcp',
					'client',
					sprintf(
						/* translators: %s: generated client ID. */
						__( 'Client registered. Client ID: %s', 'wp-mcp-connector' ),
						$client['client_id']
					),
					'success'
				);
				break;

			case 'delete_client':
				$id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

				if ( WPMCP_OAuth::delete_client( $id ) ) {
					add_settings_error( 'wpmcp', 'client', __( 'Client removed, along with every connection it held.', 'wp-mcp-connector' ), 'success' );
				}
				break;

			case 'update_grant':
				$id    = isset( $_POST['grant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_id'] ) ) : '';
				$scope = isset( $_POST['grant_scope'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_scope'] ) ) : '';

				if ( WPMCP_OAuth::update_grant_scope( $id, $scope ) ) {
					add_settings_error(
						'wpmcp',
						'grant',
						sprintf(
							/* translators: %s: scope name. */
							__( 'Access level changed to %s. It applies to the client\'s next call; there is no need to reconnect.', 'wp-mcp-connector' ),
							$scope
						),
						'success'
					);
				}
				break;

			case 'revoke_grant':
				$id = isset( $_POST['grant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['grant_id'] ) ) : '';

				if ( WPMCP_OAuth::revoke_grant( $id ) ) {
					add_settings_error( 'wpmcp', 'grant', __( 'Access revoked. That connection stops working immediately.', 'wp-mcp-connector' ), 'success' );
				}
				break;

			case 'clear_log':
				WPMCP_Logger::clear();
				add_settings_error( 'wpmcp', 'log', __( 'Activity log cleared.', 'wp-mcp-connector' ), 'success' );
				break;
		}
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render() {
		$settings = wpmcp()->settings();
		$all      = $settings->all();
		$tab      = $this->current_tab();

		?>
		<div class="wrap wpmcp">
			<div class="wpmcp-head">
				<h1><?php esc_html_e( 'MCP Connector', 'wp-mcp-connector' ); ?></h1>
				<?php if ( $all['enabled'] ) : ?>
					<span class="wpmcp-status is-on"><?php esc_html_e( 'Serving', 'wp-mcp-connector' ); ?></span>
				<?php else : ?>
					<span class="wpmcp-status is-off"><?php esc_html_e( 'Switched off', 'wp-mcp-connector' ); ?></span>
				<?php endif; ?>
				<p><?php esc_html_e( 'Lets AI clients read and manage this site over the Model Context Protocol.', 'wp-mcp-connector' ); ?></p>
			</div>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'MCP Connector sections', 'wp-mcp-connector' ); ?>">
				<?php foreach ( $this->tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $key ) ); ?>"
						class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>"
						<?php echo $key === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div style="margin-top:18px;">
				<?php settings_errors( 'wpmcp' ); ?>

				<?php if ( ! $all['enabled'] && 'connect' === $tab ) : ?>
					<div class="notice notice-warning inline" style="margin:0 0 18px;">
						<p>
							<?php esc_html_e( 'The server is switched off, so clients receive a 503. Turn it on under Permissions once you have chosen an access level.', 'wp-mcp-connector' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<?php
				switch ( $tab ) {
					case 'permissions':
						$this->render_permissions( $all );
						break;
					case 'security':
						$this->render_security( $all );
						break;
					case 'credentials':
						$this->render_credentials( $all );
						break;
					case 'activity':
						$this->render_activity();
						break;
					default:
						$this->render_connect( $all );
				}
				?>
			</div>

			<div id="wpmcp-live" class="screen-reader-text" role="status" aria-live="polite"></div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: Connect
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the Connect tab.
	 *
	 * @param array<string,mixed> $all Settings.
	 * @return void
	 */
	private function render_connect( array $all ) {
		$platforms = WPMCP_Platforms::all();
		$methods   = WPMCP_Platforms::methods();

		?>
		<div class="wpmcp-panel">
			<h2><?php esc_html_e( 'Connect a client', 'wp-mcp-connector' ); ?></h2>
			<div class="wpmcp-panel-body">
				<p class="wpmcp-note">
					<?php esc_html_e( 'Pick the client you are setting up. Each one needs the same endpoint; what differs is how the credential gets there.', 'wp-mcp-connector' ); ?>
				</p>

				<div class="wpmcp-platforms" role="tablist" aria-label="<?php esc_attr_e( 'AI clients', 'wp-mcp-connector' ); ?>" data-wpmcp-platforms>
					<?php foreach ( $platforms as $id => $platform ) : ?>
						<button type="button"
							role="tab"
							id="wpmcp-tab-<?php echo esc_attr( $id ); ?>"
							class="wpmcp-platform"
							data-platform="<?php echo esc_attr( $id ); ?>"
							aria-controls="wpmcp-recipe-<?php echo esc_attr( $id ); ?>"
							aria-selected="false"
							tabindex="-1">
							<?php echo WPMCP_Platforms::icon( $platform['method'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authored inline SVG. ?>
							<span>
								<span class="wpmcp-platform-name"><?php echo esc_html( $platform['name'] ); ?></span>
								<span class="wpmcp-platform-method"><?php echo esc_html( $methods[ $platform['method'] ]['label'] ); ?></span>
							</span>
						</button>
					<?php endforeach; ?>
				</div>

				<?php foreach ( $platforms as $id => $platform ) : ?>
					<div class="wpmcp-recipe"
						id="wpmcp-recipe-<?php echo esc_attr( $id ); ?>"
						role="tabpanel"
						aria-labelledby="wpmcp-tab-<?php echo esc_attr( $id ); ?>"
						tabindex="0"
						hidden>

						<h3 class="wpmcp-recipe-head"><?php echo esc_html( $platform['name'] ); ?></h3>
						<p class="wpmcp-recipe-summary"><?php echo esc_html( $platform['summary'] ); ?></p>

						<?php if ( ! empty( $platform['caveat'] ) ) : ?>
							<div class="wpmcp-callout is-warn">
								<?php echo WPMCP_Platforms::icon( 'oauth' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<p><?php echo esc_html( $platform['caveat'] ); ?></p>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $platform['steps'] ) ) : ?>
							<ol class="wpmcp-steps">
								<?php foreach ( $platform['steps'] as $step ) : ?>
									<li><?php echo esc_html( $step ); ?></li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>

						<?php if ( ! empty( $platform['facts'] ) ) : ?>
							<dl class="wpmcp-facts">
								<?php foreach ( WPMCP_Platforms::facts() as $fact ) : ?>
									<div class="wpmcp-fact">
										<dt><?php echo esc_html( $fact['label'] ); ?></dt>
										<dd class="<?php echo $fact['mono'] ? 'is-mono' : ''; ?>"><?php echo esc_html( $fact['value'] ); ?></dd>
									</div>
								<?php endforeach; ?>
							</dl>
						<?php endif; ?>

						<?php
						foreach ( (array) $platform['blocks'] as $index => $block ) {
							$this->render_code_block( $id . '-' . $index, $block );
						}
						?>

						<?php if ( ! empty( $platform['tip'] ) ) : ?>
							<div class="wpmcp-callout is-tip">
								<?php echo WPMCP_Platforms::icon( 'command' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<p><?php echo esc_html( $platform['tip'] ); ?></p>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $platform['fallback'] ) ) : ?>
							<div class="wpmcp-sub">
								<h4><?php echo esc_html( $platform['fallback']['label'] ); ?></h4>
								<p class="wpmcp-note"><?php echo esc_html( $platform['fallback']['note'] ); ?></p>
								<?php
								foreach ( (array) $platform['fallback']['blocks'] as $index => $block ) {
									$this->render_code_block( $id . '-fb-' . $index, $block );
								}
								?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="wpmcp-panel">
			<h2><?php esc_html_e( 'Before you connect', 'wp-mcp-connector' ); ?></h2>
			<div class="wpmcp-panel-body">
				<dl class="wpmcp-facts">
					<div class="wpmcp-fact">
						<dt><?php esc_html_e( 'Application Password', 'wp-mcp-connector' ); ?></dt>
						<dd>
							<?php
							printf(
								/* translators: %s: link to the user profile screen. */
								esc_html__( 'Generate one on %s. It is shown once. It is not your WordPress password, and it can be revoked on its own.', 'wp-mcp-connector' ),
								'<a href="' . esc_url( admin_url( 'profile.php#application-passwords-section' ) ) . '">' . esc_html__( 'your profile', 'wp-mcp-connector' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							);
							?>
						</dd>
					</div>
					<div class="wpmcp-fact">
						<dt><?php esc_html_e( 'Which account', 'wp-mcp-connector' ); ?></dt>
						<dd><?php esc_html_e( 'An Application Password reaches the whole REST API, not just this plugin. An administrator one can install plugins and read user emails. A dedicated Editor account is the single biggest risk reduction available.', 'wp-mcp-connector' ); ?></dd>
					</div>
					<div class="wpmcp-fact">
						<dt><?php esc_html_e( 'Hosted clients', 'wp-mcp-connector' ); ?></dt>
						<dd><?php esc_html_e( 'Grok and ChatGPT store the credential on their own servers. Use OAuth for those, never an Application Password: an OAuth token is refused everywhere except this plugin\'s endpoints.', 'wp-mcp-connector' ); ?></dd>
					</div>
					<div class="wpmcp-fact">
						<dt><?php esc_html_e( 'Currently enabled', 'wp-mcp-connector' ); ?></dt>
						<dd><?php echo esc_html( implode( ', ', WPMCP_Platforms::active_auth_methods() ) ); ?></dd>
					</div>
				</dl>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders one copyable code block.
	 *
	 * @param string              $id    Unique fragment for the element id.
	 * @param array<string,mixed> $block Block definition.
	 * @return void
	 */
	private function render_code_block( $id, array $block ) {
		$element_id = 'wpmcp-code-' . sanitize_html_class( $id );

		?>
		<div class="wpmcp-code">
			<div class="wpmcp-code-head">
				<span class="wpmcp-code-label"><?php echo esc_html( $block['label'] ); ?></span>
				<button type="button"
					class="wpmcp-copy"
					data-wpmcp-copy="<?php echo esc_attr( $element_id ); ?>"
					data-done-label="<?php esc_attr_e( 'Copied', 'wp-mcp-connector' ); ?>"
					data-fail-label="<?php esc_attr_e( 'Select it', 'wp-mcp-connector' ); ?>"
					data-done-announce="<?php esc_attr_e( 'Copied to clipboard', 'wp-mcp-connector' ); ?>"
					data-fail-announce="<?php esc_attr_e( 'Could not copy. The text is selected, press the copy shortcut.', 'wp-mcp-connector' ); ?>">
					<svg class="wpmcp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
						<rect x="9" y="9" width="11" height="11" rx="2"/>
						<path d="M5 15V6a2 2 0 0 1 2-2h9"/>
					</svg>
					<span data-label><?php esc_html_e( 'Copy', 'wp-mcp-connector' ); ?></span>
				</button>
			</div>
			<pre><code id="<?php echo esc_attr( $element_id ); ?>"><?php echo esc_html( $block['code'] ); ?></code></pre>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: Permissions
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the Permissions tab.
	 *
	 * @param array<string,mixed> $all Settings.
	 * @return void
	 */
	private function render_permissions( array $all ) {
		$registry = wpmcp()->registry();
		$tools    = $registry->all();
		$groups   = array();

		foreach ( $tools as $tool ) {
			$groups[ $tool['group'] ][] = $tool;
		}

		// Tool counts per profile, so the choice is made against real numbers
		// rather than an adjective.
		$counts = array();

		foreach ( array_keys( WPMCP_Settings::profiles() ) as $profile ) {
			$counts[ $profile ] = 0;

			foreach ( $tools as $tool ) {
				if ( WPMCP_Settings::profile_allows( $tool, $profile, (array) $all['enabled_tools'] ) ) {
					++$counts[ $profile ];
				}
			}
		}

		?>
		<form method="post" action="<?php echo esc_url( $this->tab_url( 'permissions' ) ); ?>">
			<?php wp_nonce_field( 'wpmcp_save_settings' ); ?>
			<input type="hidden" name="wpmcp_action" value="save_settings" />
			<?php $this->owned_fields( array( 'enabled', 'profile', 'enabled_tools', 'capability' ) ); ?>

			<div class="wpmcp-panel">
				<h2><?php esc_html_e( 'Access level', 'wp-mcp-connector' ); ?></h2>
				<div class="wpmcp-panel-body">
					<p style="margin:0 0 16px;">
						<label>
							<input type="checkbox" name="wpmcp[enabled]" value="1" <?php checked( $all['enabled'] ); ?> />
							<strong><?php esc_html_e( 'Serve this site over MCP', 'wp-mcp-connector' ); ?></strong>
						</label>
					</p>

					<div class="wpmcp-profiles">
						<?php foreach ( WPMCP_Settings::profiles() as $slug => $profile ) : ?>
							<label class="wpmcp-profile">
								<input type="radio" name="wpmcp[profile]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $all['profile'], $slug ); ?> />
								<span class="wpmcp-profile-name"><?php echo esc_html( $profile['label'] ); ?></span>
								<span class="wpmcp-profile-count">
									<?php
									printf(
										/* translators: %d: number of tools. */
										esc_html( _n( '%d tool', '%d tools', $counts[ $slug ], 'wp-mcp-connector' ) ),
										(int) $counts[ $slug ]
									);
									?>
								</span>
								<span class="wpmcp-profile-desc"><?php echo esc_html( $profile['description'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<p class="wpmcp-note" style="margin:14px 0 0;">
						<?php esc_html_e( 'A profile decides which tools are offered. The connected WordPress account still needs the matching capability, so a profile can never grant more than the account already has.', 'wp-mcp-connector' ); ?>
					</p>

					<h3><?php esc_html_e( 'Minimum capability', 'wp-mcp-connector' ); ?></h3>
					<p class="wpmcp-note"><?php esc_html_e( 'What an account must hold to reach the endpoints at all.', 'wp-mcp-connector' ); ?></p>
					<input type="text" class="regular-text code" name="wpmcp[capability]" value="<?php echo esc_attr( $all['capability'] ); ?>" />
				</div>
			</div>

			<div class="wpmcp-panel">
				<h2><?php esc_html_e( 'Tools', 'wp-mcp-connector' ); ?></h2>
				<div class="wpmcp-panel-body">
					<p class="wpmcp-note">
						<?php
						printf(
							/* translators: %d: total number of tools. */
							esc_html__( '%d tools in total. The checkboxes apply when the access level above is set to Custom; otherwise the profile decides and these are a reference.', 'wp-mcp-connector' ),
							count( $tools )
						);
						?>
					</p>

					<?php foreach ( $groups as $group => $group_tools ) : ?>
						<div class="wpmcp-toolgroup">
							<h4>
								<span><?php echo esc_html( $group ); ?></span>
								<span><?php echo esc_html( count( $group_tools ) ); ?></span>
							</h4>
							<?php foreach ( $group_tools as $tool ) : ?>
								<label class="wpmcp-tool">
									<input type="checkbox" name="wpmcp[enabled_tools][]" value="<?php echo esc_attr( $tool['name'] ); ?>" <?php checked( in_array( $tool['name'], (array) $all['enabled_tools'], true ) ); ?> />
									<span>
										<code><?php echo esc_html( $tool['name'] ); ?></code>
										<span class="wpmcp-tool-title"><?php echo esc_html( $tool['title'] ); ?></span>
									</span>
									<?php if ( ! empty( $tool['annotations']['destructiveHint'] ) ) : ?>
										<span class="wpmcp-tag is-destructive"><?php esc_html_e( 'destructive', 'wp-mcp-connector' ); ?></span>
									<?php elseif ( ! empty( $tool['annotations']['readOnlyHint'] ) ) : ?>
										<span class="wpmcp-tag is-read"><?php esc_html_e( 'read only', 'wp-mcp-connector' ); ?></span>
									<?php else : ?>
										<span class="wpmcp-tag"><?php esc_html_e( 'writes', 'wp-mcp-connector' ); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: Security
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the Security tab.
	 *
	 * @param array<string,mixed> $all Settings.
	 * @return void
	 */
	private function render_security( array $all ) {
		?>
		<form method="post" action="<?php echo esc_url( $this->tab_url( 'security' ) ); ?>">
			<?php wp_nonce_field( 'wpmcp_save_settings' ); ?>
			<input type="hidden" name="wpmcp_action" value="save_settings" />
			<?php
			$this->owned_fields(
				array(
					'allow_app_passwords',
					'allow_bearer',
					'oauth_enabled',
					'oauth_dynamic_registration',
					'oauth_default_scope',
					'require_https',
					'sse_enabled',
					'sse_max_duration',
					'rate_limit_requests',
					'rate_limit_window',
					'max_upload_bytes',
					'allowed_option_keys',
					'allowed_media_hosts',
					'log_enabled',
				)
			);
			?>

			<div class="wpmcp-panel">
				<h2><?php esc_html_e( 'How clients authenticate', 'wp-mcp-connector' ); ?></h2>
				<div class="wpmcp-panel-body">
					<p style="margin:0 0 10px;">
						<label>
							<input type="checkbox" name="wpmcp[allow_app_passwords]" value="1" <?php checked( $all['allow_app_passwords'] ); ?> />
							<strong><?php esc_html_e( 'Application Passwords', 'wp-mcp-connector' ); ?></strong>
							&mdash; <span class="description"><?php esc_html_e( 'best for clients running on your own machine', 'wp-mcp-connector' ); ?></span>
						</label>
					</p>
					<p style="margin:0 0 10px;">
						<label>
							<input type="checkbox" name="wpmcp[allow_bearer]" value="1" <?php checked( $all['allow_bearer'] ); ?> />
							<strong><?php esc_html_e( 'Bearer tokens', 'wp-mcp-connector' ); ?></strong>
							&mdash; <span class="description"><?php esc_html_e( 'issued by you, refused outside this plugin\'s endpoints', 'wp-mcp-connector' ); ?></span>
						</label>
					</p>
					<p style="margin:0 0 10px;">
						<label>
							<input type="checkbox" name="wpmcp[oauth_enabled]" value="1" <?php checked( $all['oauth_enabled'] ); ?> />
							<strong><?php esc_html_e( 'OAuth 2.1', 'wp-mcp-connector' ); ?></strong>
							&mdash; <span class="description"><?php esc_html_e( 'required by hosted clients such as Grok and ChatGPT', 'wp-mcp-connector' ); ?></span>
						</label>
					</p>

					<div style="margin:14px 0 0 24px;">
						<p style="margin:0 0 10px;">
							<label>
								<input type="checkbox" name="wpmcp[oauth_dynamic_registration]" value="1" <?php checked( $all['oauth_dynamic_registration'] ); ?> />
								<?php esc_html_e( 'Let clients register themselves', 'wp-mcp-connector' ); ?>
							</label>
						</p>
						<p style="margin:0 0 6px;">
							<label>
								<strong><?php esc_html_e( 'New connections get', 'wp-mcp-connector' ); ?></strong><br />
								<select name="wpmcp[oauth_default_scope]">
									<?php foreach ( WPMCP_OAuth::scopes() as $scope => $mapped ) : ?>
										<option value="<?php echo esc_attr( $scope ); ?>" <?php selected( $all['oauth_default_scope'], $scope ); ?>>
											<?php echo esc_html( '' === $mapped ? $scope . '  ' . __( '(whatever the access level allows)', 'wp-mcp-connector' ) : $scope ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label>
						</p>
						<p class="wpmcp-note" style="margin:6px 0 0;">
							<?php esc_html_e( 'Most clients request every scope the site advertises, which is a request for the default rather than a preference. A client asking for exactly one narrower scope still gets the narrower one; nothing a client asks for can exceed this.', 'wp-mcp-connector' ); ?>
						</p>
					</div>

					<h3><?php esc_html_e( 'Transport', 'wp-mcp-connector' ); ?></h3>
					<p style="margin:0 0 10px;">
						<label>
							<input type="checkbox" name="wpmcp[require_https]" value="1" <?php checked( $all['require_https'] ); ?> />
							<?php esc_html_e( 'Require HTTPS', 'wp-mcp-connector' ); ?>
							<span class="description"><?php esc_html_e( '(local environments are always exempt)', 'wp-mcp-connector' ); ?></span>
						</label>
					</p>
					<p style="margin:0 0 6px;">
						<label>
							<input type="checkbox" name="wpmcp[sse_enabled]" value="1" <?php checked( $all['sse_enabled'] ); ?> />
							<?php esc_html_e( 'Serve the legacy HTTP+SSE transport', 'wp-mcp-connector' ); ?>
						</label>
					</p>
					<p style="margin:0 0 0 24px;">
						<label>
							<?php esc_html_e( 'Hold each stream open for', 'wp-mcp-connector' ); ?>
							<input type="number" min="5" max="300" class="small-text" name="wpmcp[sse_max_duration]" value="<?php echo esc_attr( $all['sse_max_duration'] ); ?>" />
							<?php esc_html_e( 'seconds', 'wp-mcp-connector' ); ?>
						</label>
						<span class="description"><?php esc_html_e( 'Each open stream occupies a PHP worker for that long.', 'wp-mcp-connector' ); ?></span>
					</p>
				</div>
			</div>

			<div class="wpmcp-panel">
				<h2><?php esc_html_e( 'Limits', 'wp-mcp-connector' ); ?></h2>
				<div class="wpmcp-panel-body">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Rate limit', 'wp-mcp-connector' ); ?></th>
							<td>
								<input type="number" min="1" class="small-text" name="wpmcp[rate_limit_requests]" value="<?php echo esc_attr( $all['rate_limit_requests'] ); ?>" />
								<?php esc_html_e( 'tool calls per', 'wp-mcp-connector' ); ?>
								<input type="number" min="1" class="small-text" name="wpmcp[rate_limit_window]" value="<?php echo esc_attr( $all['rate_limit_window'] ); ?>" />
								<?php esc_html_e( 'seconds, per user', 'wp-mcp-connector' ); ?>
								<p class="description"><?php esc_html_e( 'An agent looping on a failing tool hits this instead of your database.', 'wp-mcp-connector' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Maximum upload', 'wp-mcp-connector' ); ?></th>
							<td>
								<input type="number" min="1024" class="regular-text" name="wpmcp[max_upload_bytes]" value="<?php echo esc_attr( $all['max_upload_bytes'] ); ?>" />
								<p class="description"><?php echo esc_html( size_format( (int) $all['max_upload_bytes'] ) ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Readable options', 'wp-mcp-connector' ); ?></th>
							<td>
								<textarea name="wpmcp[allowed_option_keys]" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $all['allowed_option_keys'] ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One option name per line. Anything not listed is refused.', 'wp-mcp-connector' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Media download hosts', 'wp-mcp-connector' ); ?></th>
							<td>
								<textarea name="wpmcp[allowed_media_hosts]" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $all['allowed_media_hosts'] ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One hostname per line. Empty allows any public host; private and loopback addresses are always blocked.', 'wp-mcp-connector' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Activity log', 'wp-mcp-connector' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="wpmcp[log_enabled]" value="1" <?php checked( $all['log_enabled'] ); ?> />
									<?php esc_html_e( 'Record the last 100 tool calls', 'wp-mcp-connector' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: Credentials
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the Credentials tab.
	 *
	 * @param array<string,mixed> $all Settings.
	 * @return void
	 */
	private function render_credentials( array $all ) {
		$tokens  = WPMCP_Auth::get_tokens();
		$clients = WPMCP_OAuth::get_clients();
		$grants  = WPMCP_OAuth::get_grants();

		if ( $this->new_token ) :
			?>
			<div class="wpmcp-issued">
				<p><strong><?php esc_html_e( 'Copy this token now. It is not shown again.', 'wp-mcp-connector' ); ?></strong></p>
				<?php
				$this->render_code_block(
					'new-token',
					array(
						'label' => __( 'Bearer token', 'wp-mcp-connector' ),
						'code'  => $this->new_token,
					)
				);
				?>
			</div>
			<?php
		endif;
		?>

		<div class="wpmcp-panel">
			<h2><?php esc_html_e( 'Authorized connections', 'wp-mcp-connector' ); ?></h2>
			<div class="wpmcp-panel-body">
				<p class="wpmcp-note"><?php esc_html_e( 'Clients you approved through OAuth. Changing an access level applies to the client\'s next call; revoking stops it immediately.', 'wp-mcp-connector' ); ?></p>

				<?php if ( ! $grants ) : ?>
					<div class="wpmcp-facts">
						<div class="wpmcp-empty">
							<strong><?php esc_html_e( 'Nothing authorized yet', 'wp-mcp-connector' ); ?></strong>
							<?php esc_html_e( 'When a client connects through OAuth and you approve it, the connection appears here.', 'wp-mcp-connector' ); ?>
						</div>
					</div>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Client', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'User', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'Access level', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'Last used', 'wp-mcp-connector' ); ?></th>
								<th class="check-column"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $grants as $grant ) : ?>
								<?php $grant_user = get_user_by( 'id', $grant['user_id'] ); ?>
								<tr>
									<td><strong><?php echo esc_html( $grant['client_name'] ); ?></strong></td>
									<td><?php echo esc_html( $grant_user ? $grant_user->user_login : __( '(deleted user)', 'wp-mcp-connector' ) ); ?></td>
									<td>
										<form method="post" class="wpmcp-inline-form" action="<?php echo esc_url( $this->tab_url( 'credentials' ) ); ?>">
											<?php wp_nonce_field( 'wpmcp_update_grant' ); ?>
											<input type="hidden" name="wpmcp_action" value="update_grant" />
											<input type="hidden" name="grant_id" value="<?php echo esc_attr( $grant['id'] ); ?>" />
											<label class="screen-reader-text" for="scope-<?php echo esc_attr( $grant['id'] ); ?>"><?php esc_html_e( 'Access level', 'wp-mcp-connector' ); ?></label>
											<select name="grant_scope" id="scope-<?php echo esc_attr( $grant['id'] ); ?>">
												<?php foreach ( array_keys( WPMCP_OAuth::scopes() ) as $scope ) : ?>
													<option value="<?php echo esc_attr( $scope ); ?>" <?php selected( $grant['scope'], $scope ); ?>><?php echo esc_html( $scope ); ?></option>
												<?php endforeach; ?>
											</select>
											<button type="submit" class="button button-small"><?php esc_html_e( 'Change', 'wp-mcp-connector' ); ?></button>
										</form>
									</td>
									<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $grant['last_used'] ) ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( $this->tab_url( 'credentials' ) ); ?>" style="margin:0;">
											<?php wp_nonce_field( 'wpmcp_revoke_grant' ); ?>
											<input type="hidden" name="wpmcp_action" value="revoke_grant" />
											<input type="hidden" name="grant_id" value="<?php echo esc_attr( $grant['id'] ); ?>" />
											<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Revoke', 'wp-mcp-connector' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<div class="wpmcp-panel">
			<h2><?php esc_html_e( 'Bearer tokens', 'wp-mcp-connector' ); ?></h2>
			<div class="wpmcp-panel-body">
				<p class="wpmcp-note"><?php esc_html_e( 'For a client that cannot use OAuth or HTTP Basic auth. A token acts as the chosen account but is refused everywhere except this plugin\'s endpoints.', 'wp-mcp-connector' ); ?></p>

				<?php if ( ! $all['allow_bearer'] ) : ?>
					<div class="wpmcp-callout is-warn">
						<?php echo WPMCP_Platforms::icon( 'oauth' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p><?php esc_html_e( 'Bearer tokens are switched off under Security, so any token issued here is refused until you enable them.', 'wp-mcp-connector' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $tokens ) : ?>
					<table class="widefat striped" style="margin-bottom:16px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Label', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'User', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'Scope', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'Last used', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'Expires', 'wp-mcp-connector' ); ?></th>
								<th class="check-column"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $tokens as $token ) : ?>
								<?php
								$token_user = get_user_by( 'id', $token['user_id'] );
								$profiles   = WPMCP_Settings::profiles();
								$scope      = ! empty( $token['scope_profile'] ) ? $token['scope_profile'] : '';
								?>
								<tr>
									<td><strong><?php echo esc_html( $token['label'] ? $token['label'] : __( 'Unlabelled', 'wp-mcp-connector' ) ); ?></strong></td>
									<td><?php echo esc_html( $token_user ? $token_user->user_login : __( '(deleted user)', 'wp-mcp-connector' ) ); ?></td>
									<td>
										<?php if ( $scope && isset( $profiles[ $scope ] ) ) : ?>
											<?php echo esc_html( $profiles[ $scope ]['label'] ); ?>
										<?php else : ?>
											<span class="description"><?php esc_html_e( 'site default', 'wp-mcp-connector' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $token['last_used'] ? wp_date( 'Y-m-d H:i', $token['last_used'] ) : __( 'never', 'wp-mcp-connector' ) ); ?></td>
									<td><?php echo esc_html( $token['expires'] ? wp_date( 'Y-m-d', $token['expires'] ) : __( 'never', 'wp-mcp-connector' ) ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( $this->tab_url( 'credentials' ) ); ?>" style="margin:0;">
											<?php wp_nonce_field( 'wpmcp_revoke_token' ); ?>
											<input type="hidden" name="wpmcp_action" value="revoke_token" />
											<input type="hidden" name="token_id" value="<?php echo esc_attr( $token['id'] ); ?>" />
											<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Revoke', 'wp-mcp-connector' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<form method="post" class="wpmcp-inline-form" action="<?php echo esc_url( $this->tab_url( 'credentials' ) ); ?>">
					<?php wp_nonce_field( 'wpmcp_issue_token' ); ?>
					<input type="hidden" name="wpmcp_action" value="issue_token" />
					<label class="screen-reader-text" for="wpmcp-token-label"><?php esc_html_e( 'Token label', 'wp-mcp-connector' ); ?></label>
					<input type="text" id="wpmcp-token-label" name="token_label" class="regular-text" placeholder="<?php esc_attr_e( 'Label, e.g. Claude Desktop', 'wp-mcp-connector' ); ?>" />
					<?php
					wp_dropdown_users(
						array(
							'name'     => 'token_user',
							'selected' => get_current_user_id(),
						)
					);
					?>
					<select name="token_scope">
						<option value=""><?php esc_html_e( 'Scope: site default', 'wp-mcp-connector' ); ?></option>
						<?php foreach ( WPMCP_Settings::profiles() as $slug => $profile ) : ?>
							<?php if ( 'custom' === $slug ) { continue; } ?>
							<option value="<?php echo esc_attr( $slug ); ?>">
								<?php
								/* translators: %s: profile label. */
								echo esc_html( sprintf( __( 'Scope: %s', 'wp-mcp-connector' ), $profile['label'] ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<select name="token_ttl">
						<option value="90"><?php esc_html_e( 'Expires in 90 days', 'wp-mcp-connector' ); ?></option>
						<option value="30"><?php esc_html_e( 'Expires in 30 days', 'wp-mcp-connector' ); ?></option>
						<option value="365"><?php esc_html_e( 'Expires in 1 year', 'wp-mcp-connector' ); ?></option>
						<option value="0"><?php esc_html_e( 'No expiry', 'wp-mcp-connector' ); ?></option>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Issue token', 'wp-mcp-connector' ); ?></button>
				</form>
			</div>
		</div>

		<div class="wpmcp-panel">
			<h2><?php esc_html_e( 'OAuth clients', 'wp-mcp-connector' ); ?></h2>
			<div class="wpmcp-panel-body">
				<?php if ( ! $all['oauth_enabled'] ) : ?>
					<div class="wpmcp-callout is-warn">
						<?php echo WPMCP_Platforms::icon( 'oauth' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p><?php esc_html_e( 'OAuth is switched off under Security. Hosted clients cannot connect until it is on.', 'wp-mcp-connector' ); ?></p>
					</div>
				<?php else : ?>
					<dl class="wpmcp-facts">
						<div class="wpmcp-fact">
							<dt><?php esc_html_e( 'Authorization endpoint', 'wp-mcp-connector' ); ?></dt>
							<dd class="is-mono"><?php echo esc_html( home_url( '/' . WPMCP_OAuth::BASE . '/authorize' ) ); ?></dd>
						</div>
						<div class="wpmcp-fact">
							<dt><?php esc_html_e( 'Token endpoint', 'wp-mcp-connector' ); ?></dt>
							<dd class="is-mono"><?php echo esc_html( home_url( '/' . WPMCP_OAuth::BASE . '/token' ) ); ?></dd>
						</div>
						<div class="wpmcp-fact">
							<dt><?php esc_html_e( 'Client secret', 'wp-mcp-connector' ); ?></dt>
							<dd><?php esc_html_e( 'None. These are public clients using PKCE, so leave any client secret field empty.', 'wp-mcp-connector' ); ?></dd>
						</div>
						<div class="wpmcp-fact">
							<dt><?php esc_html_e( 'Scopes', 'wp-mcp-connector' ); ?></dt>
							<dd class="is-mono"><?php echo esc_html( implode( '  ', array_keys( WPMCP_OAuth::scopes() ) ) ); ?></dd>
						</div>
					</dl>
				<?php endif; ?>

				<?php if ( $clients ) : ?>
					<table class="widefat striped" style="margin-bottom:16px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Client', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'Client ID', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'Redirect URIs', 'wp-mcp-connector' ); ?></th>
								<th class="check-column"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $clients as $client ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $client['client_name'] ); ?></strong>
										<?php if ( ! empty( $client['dynamic'] ) ) : ?>
											<br /><span class="description"><?php esc_html_e( 'registered itself', 'wp-mcp-connector' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="wpmcp-mono"><?php echo esc_html( $client['client_id'] ); ?></td>
									<td class="wpmcp-mono"><?php echo esc_html( implode( ', ', (array) $client['redirect_uris'] ) ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( $this->tab_url( 'credentials' ) ); ?>" style="margin:0;">
											<?php wp_nonce_field( 'wpmcp_delete_client' ); ?>
											<input type="hidden" name="wpmcp_action" value="delete_client" />
											<input type="hidden" name="client_id" value="<?php echo esc_attr( $client['client_id'] ); ?>" />
											<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Remove', 'wp-mcp-connector' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="wpmcp-note"><?php esc_html_e( 'Removing a client also revokes every connection it holds. Duplicate entries with the same name are usually failed setup attempts and are safe to remove once a working connection is listed above.', 'wp-mcp-connector' ); ?></p>
				<?php endif; ?>

				<h3><?php esc_html_e( 'Register a client by hand', 'wp-mcp-connector' ); ?></h3>
				<p class="wpmcp-note"><?php esc_html_e( 'Only needed when a client cannot register itself. Copy its redirect URI exactly; it is matched character for character.', 'wp-mcp-connector' ); ?></p>
				<form method="post" action="<?php echo esc_url( $this->tab_url( 'credentials' ) ); ?>">
					<?php wp_nonce_field( 'wpmcp_register_client' ); ?>
					<input type="hidden" name="wpmcp_action" value="register_client" />
					<p>
						<label class="screen-reader-text" for="wpmcp-client-name"><?php esc_html_e( 'Client name', 'wp-mcp-connector' ); ?></label>
						<input type="text" id="wpmcp-client-name" name="client_name" class="regular-text" placeholder="<?php esc_attr_e( 'Client name', 'wp-mcp-connector' ); ?>" />
					</p>
					<p>
						<label class="screen-reader-text" for="wpmcp-client-redirects"><?php esc_html_e( 'Redirect URIs', 'wp-mcp-connector' ); ?></label>
						<textarea id="wpmcp-client-redirects" name="client_redirects" rows="2" class="large-text code" placeholder="<?php esc_attr_e( 'Redirect URIs, one per line', 'wp-mcp-connector' ); ?>"></textarea>
					</p>
					<button type="submit" class="button"><?php esc_html_e( 'Register client', 'wp-mcp-connector' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tab: Activity
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the Activity tab.
	 *
	 * @return void
	 */
	private function render_activity() {
		$entries = WPMCP_Logger::entries();

		?>
		<div class="wpmcp-panel">
			<h2><?php esc_html_e( 'Recent activity', 'wp-mcp-connector' ); ?></h2>
			<div class="wpmcp-panel-body">
				<?php if ( ! $entries ) : ?>
					<div class="wpmcp-facts">
						<div class="wpmcp-empty">
							<strong><?php esc_html_e( 'Nothing recorded yet', 'wp-mcp-connector' ); ?></strong>
							<?php esc_html_e( 'Every tool call a client makes is listed here with the account and credential that made it.', 'wp-mcp-connector' ); ?>
						</div>
					</div>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:160px;"><?php esc_html_e( 'When', 'wp-mcp-connector' ); ?></th>
								<th style="width:200px;"><?php esc_html_e( 'Tool', 'wp-mcp-connector' ); ?></th>
								<th style="width:180px;"><?php esc_html_e( 'By', 'wp-mcp-connector' ); ?></th>
								<th><?php esc_html_e( 'Result', 'wp-mcp-connector' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $entries, 0, 50 ) as $entry ) : ?>
								<?php $entry_user = get_user_by( 'id', $entry['user'] ); ?>
								<tr>
									<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $entry['time'] ) ); ?></td>
									<td class="wpmcp-mono"><?php echo esc_html( $entry['tool'] ); ?></td>
									<td>
										<?php echo esc_html( $entry_user ? $entry_user->user_login : '—' ); ?>
										<br /><span class="description"><?php echo esc_html( $entry['auth'] ); ?></span>
									</td>
									<td>
										<?php if ( $entry['success'] ) : ?>
											<span class="wpmcp-result-ok"><?php esc_html_e( 'ok', 'wp-mcp-connector' ); ?></span>
										<?php else : ?>
											<span class="wpmcp-result-bad"><?php echo esc_html( $entry['message'] ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<form method="post" action="<?php echo esc_url( $this->tab_url( 'activity' ) ); ?>" style="margin-top:14px;">
						<?php wp_nonce_field( 'wpmcp_clear_log' ); ?>
						<input type="hidden" name="wpmcp_action" value="clear_log" />
						<button type="submit" class="button"><?php esc_html_e( 'Clear log', 'wp-mcp-connector' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Declares which settings keys the current form owns.
	 *
	 * @param string[] $fields Setting keys.
	 * @return void
	 */
	private function owned_fields( array $fields ) {
		foreach ( $fields as $field ) {
			echo '<input type="hidden" name="wpmcp[_fields][]" value="' . esc_attr( $field ) . '" />';
		}
	}
}
