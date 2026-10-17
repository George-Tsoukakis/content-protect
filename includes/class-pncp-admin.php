<?php
/**
 * Settings page in wp-admin (Settings → ScrapeLock), with tabs.
 *
 * @package ScrapeLock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI. Settings go through the Settings API (options.php checks nonce and capability).
 * Actions (profiles, token, bans, image tool) go through admin-post / AJAX with a nonce.
 */
final class PNCP_Admin {

	const PAGE  = 'scrapelock';
	const GROUP = 'pncp_settings_group';

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PNCP_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_pncp_profile', array( __CLASS__, 'handle_profile' ) );
		add_action( 'admin_post_pncp_token', array( __CLASS__, 'handle_token' ) );
		add_action( 'admin_post_pncp_log', array( __CLASS__, 'handle_log' ) );
		add_action( 'wp_ajax_pncp_wm_batch', array( __CLASS__, 'ajax_wm_batch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'upgrade_notices' ) );
	}

	/**
	 * Adds the entry to the Settings menu.
	 */
	public static function menu() {
		add_options_page(
			__( 'ScrapeLock', 'scrapelock' ),
			__( 'ScrapeLock', 'scrapelock' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Registers the option with the Settings API.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			PNCP_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'PNCP_Options', 'sanitize' ),
				'default'           => PNCP_Options::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * CSS and JS only on the plugin page.
	 *
	 * @param string $hook Page hook.
	 */
	public static function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pncp-admin', PNCP_URL . 'assets/css/admin.css', array(), PNCP_VERSION );
		wp_enqueue_script( 'pncp-admin', PNCP_URL . 'assets/js/admin.js', array(), PNCP_VERSION, true );
		wp_localize_script(
			'pncp-admin',
			'pncpAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pncp_wm_batch' ),
				'i18n'    => array(
					'confirmApply'  => __( 'All images in the library will be regenerated with a watermark. Do you have a backup of the uploads folder? Continue?', 'scrapelock' ),
					'confirmRemove' => __( 'The watermark will be removed from all images. Continue?', 'scrapelock' ),
					/* translators: 1: images done, 2: total */
					'progress'      => __( 'Processed %1$s of %2$s images…', 'scrapelock' ),
					/* translators: 1: total images, 2: errors */
					'done'          => __( 'Done: %1$s images. Errors: %2$s.', 'scrapelock' ),
					'error'         => __( 'Stopped because of an error. Click the button again to resume where it stopped.', 'scrapelock' ),
					'copied'        => __( 'Copied', 'scrapelock' ),
				),
			)
		);
	}

	/**
	 * "Settings" link in the plugins list.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( PNCP_Status::settings_url() ) . '">' . esc_html__( 'Settings', 'scrapelock' ) . '</a>' );
		return $links;
	}

	/**
	 * The tabs.
	 *
	 * @return array<string, string>
	 */
	private static function tabs() {
		return array(
			'general' => __( 'On the page', 'scrapelock' ),
			'bots'    => __( 'Bots and limits', 'scrapelock' ),
			'content' => __( 'Feeds and API', 'scrapelock' ),
			'images'  => __( 'Images', 'scrapelock' ),
			'rules'   => __( 'Server rules', 'scrapelock' ),
			'log'     => __( 'Log', 'scrapelock' ),
		);
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		return array_key_exists( $tab, self::tabs() ) ? $tab : 'general';
	}

	/* ---------- Fields ---------- */

	/**
	 * Checkbox row.
	 *
	 * @param array<string, mixed> $s     Settings.
	 * @param string               $key   Key.
	 * @param string               $label Label text.
	 * @param string               $help  Help text (optional).
	 */
	private static function checkbox( $s, $key, $label, $help = '' ) {
		$id = 'pncp-' . $key;
		printf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1"%4$s%5$s> %6$s</label>',
			esc_attr( $id ),
			esc_attr( PNCP_OPTION ),
			esc_attr( $key ),
			checked( ! empty( $s[ $key ] ), true, false ),
			$help ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : '',
			esc_html( $label )
		);
		if ( $help ) {
			printf( '<p class="description" id="%s">%s</p>', esc_attr( $id . '-help' ), esc_html( $help ) );
		}
		echo '<br>';
	}

	/**
	 * Radio group.
	 *
	 * @param array<string, mixed>  $s       Settings.
	 * @param string                $key     Key.
	 * @param string                $legend  Legend.
	 * @param array<string, string> $options value => label.
	 */
	private static function radios( $s, $key, $legend, $options ) {
		echo '<fieldset><legend class="screen-reader-text">' . esc_html( $legend ) . '</legend>';
		foreach ( $options as $value => $label ) {
			$id = 'pncp-' . $key . '-' . $value;
			printf(
				'<label for="%1$s"><input type="radio" id="%1$s" name="%2$s[%3$s]" value="%4$s"%5$s> %6$s</label><br>',
				esc_attr( $id ),
				esc_attr( PNCP_OPTION ),
				esc_attr( $key ),
				esc_attr( $value ),
				checked( (string) $s[ $key ], (string) $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Select.
	 *
	 * @param array<string, mixed>  $s       Settings.
	 * @param string                $key     Key.
	 * @param array<string, string> $options value => label.
	 * @param string                $help    Help text.
	 */
	private static function select( $s, $key, $options, $help = '' ) {
		$id = 'pncp-' . $key;
		printf( '<select id="%1$s" name="%2$s[%3$s]"%4$s>', esc_attr( $id ), esc_attr( PNCP_OPTION ), esc_attr( $key ), $help ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : '' );
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( (string) $s[ $key ], (string) $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		if ( $help ) {
			printf( '<p class="description" id="%s">%s</p>', esc_attr( $id . '-help' ), esc_html( $help ) );
		}
	}

	/**
	 * Number field with label and help text.
	 *
	 * @param array<string, mixed> $s     Settings.
	 * @param string               $key   Key.
	 * @param string               $label Label text.
	 * @param string               $help  Help text.
	 */
	private static function number( $s, $key, $label, $help = '' ) {
		$range = PNCP_Options::int_ranges()[ $key ];
		$id    = 'pncp-' . $key;
		printf(
			'<p><label for="%1$s">%2$s</label> <input type="number" class="small-text" id="%1$s" name="%3$s[%4$s]" value="%5$d" min="%6$d"%7$s%8$s></p>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( PNCP_OPTION ),
			esc_attr( $key ),
			(int) $s[ $key ],
			(int) $range[0],
			PHP_INT_MAX === $range[1] ? '' : ' max="' . (int) $range[1] . '"',
			$help ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : ''
		);
		if ( $help ) {
			printf( '<p class="description" id="%s">%s</p>', esc_attr( $id . '-help' ), esc_html( $help ) );
		}
	}

	/**
	 * Textarea with label.
	 *
	 * @param array<string, mixed> $s     Settings.
	 * @param string               $key   Key.
	 * @param string               $label Label.
	 * @param string               $help  Help text.
	 * @param int                  $rows  Rows.
	 */
	private static function textarea( $s, $key, $label, $help = '', $rows = 4 ) {
		$id = 'pncp-' . $key;
		printf( '<p><label for="%s">%s</label></p>', esc_attr( $id ), esc_html( $label ) );
		printf(
			'<textarea class="large-text code" rows="%1$d" id="%2$s" name="%3$s[%4$s]"%5$s>%6$s</textarea>',
			(int) $rows,
			esc_attr( $id ),
			esc_attr( PNCP_OPTION ),
			esc_attr( $key ),
			$help ? ' aria-describedby="' . esc_attr( $id . '-help' ) . '"' : '',
			esc_textarea( (string) $s[ $key ] )
		);
		if ( $help ) {
			printf( '<p class="description" id="%s">%s</p>', esc_attr( $id . '-help' ), esc_html( $help ) );
		}
	}

	/**
	 * Opens a tab's settings form.
	 *
	 * @param string $section Tab.
	 */
	private static function form_open( $section ) {
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		printf( '<input type="hidden" name="%s[_sections][]" value="%s">', esc_attr( PNCP_OPTION ), esc_attr( $section ) );
		echo '<table class="form-table" role="presentation">';
	}

	/**
	 * Closes the settings form.
	 */
	private static function form_close() {
		echo '</table>';
		submit_button( __( 'Save changes', 'scrapelock' ) );
		echo '</form>';
	}

	/**
	 * admin-post form with nonce (for actions).
	 *
	 * @param string $action  Action.
	 * @param string $nonce   Nonce action.
	 * @param string $classes CSS classes.
	 */
	private static function action_form_open( $action, $nonce, $classes = '' ) {
		printf( '<form method="post" action="%s" class="%s">', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( $classes ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		wp_nonce_field( $nonce );
	}

	/* ---------- Page ---------- */

	/**
	 * The settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = self::current_tab();
		?>
		<div class="wrap pncp-admin">
			<h1><?php esc_html_e( 'ScrapeLock', 'scrapelock' ); ?></h1>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only displays the confirmation.
			if ( isset( $_GET['pncp_confirm'] ) && PNCP_Status::is_enabled() ) {
				self::render_disable_confirm();
			}
			?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'scrapelock' ); ?>">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( PNCP_Status::settings_url( array( 'tab' => $key ) ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			$method = 'tab_' . $tab;
			self::$method( PNCP_Options::stored() );
			?>
		</div>
		<?php
	}

	/**
	 * Disable confirmation (from the admin bar or the widget).
	 */
	private static function render_disable_confirm() {
		?>
		<div class="notice notice-warning pncp-confirm">
			<p><strong><?php esc_html_e( 'Disable protection on the whole site?', 'scrapelock' ); ?></strong></p>
			<p><?php esc_html_e( 'Until you enable it again, bots and visitors will be able to copy texts, images and feeds freely.', 'scrapelock' ); ?></p>
			<?php self::action_form_open( PNCP_Status::ACTION, PNCP_Status::ACTION ); ?>
				<input type="hidden" name="state" value="off">
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Yes, disable', 'scrapelock' ); ?></button>
					<a class="button" href="<?php echo esc_url( PNCP_Status::settings_url() ); ?>"><?php esc_html_e( 'Cancel', 'scrapelock' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * "On the page" tab: status, profiles, Emergency Mode and what is blocked in the browser.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private static function tab_general( $s ) {
		self::render_profiles( $s );
		self::form_open( 'general' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'scrapelock' ); ?></th>
			<td><?php self::checkbox( $s, 'enabled', __( 'Protection is active', 'scrapelock' ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Where it applies', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::checkbox( $s, 'scope_posts', __( 'Posts', 'scrapelock' ) );
				self::checkbox( $s, 'scope_pages', __( 'Pages', 'scrapelock' ) );
				self::checkbox( $s, 'scope_home', __( 'Home, categories, search and archives', 'scrapelock' ) );
				self::checkbox( $s, 'scope_products', __( 'WooCommerce products', 'scrapelock' ) );
				self::checkbox( $s, 'skip_woo_pages', __( 'Do not apply on cart, checkout and “My account”', 'scrapelock' ), __( 'Recommended: customers must be able to copy order numbers and coupon codes.', 'scrapelock' ) );
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pncp-excluded_ids"><?php esc_html_e( 'Exceptions', 'scrapelock' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="pncp-excluded_ids" name="<?php echo esc_attr( PNCP_OPTION ); ?>[excluded_ids]" value="<?php echo esc_attr( $s['excluded_ids'] ); ?>" aria-describedby="pncp-excluded_ids-help" inputmode="numeric">
				<p class="description" id="pncp-excluded_ids-help"><?php esc_html_e( 'Comma-separated page or post IDs, e.g. 12, 45. For a single piece of text (phone, email, code), give it the CSS class pncp-allow.', 'scrapelock' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'What is blocked', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::checkbox( $s, 'block_selection', __( 'Text selection with the mouse or finger', 'scrapelock' ) );
				self::checkbox( $s, 'block_context_menu', __( 'Right click', 'scrapelock' ) );
				self::checkbox( $s, 'block_shortcuts', __( 'Shortcuts Ctrl/Cmd + A, C, X, S, U', 'scrapelock' ), __( 'Keyboard navigation (Tab, arrows, Enter) is never affected.', 'scrapelock' ) );
				self::checkbox( $s, 'block_devtools', __( 'Developer tools shortcuts (F12, Ctrl+Shift+I/J/C/K, Cmd+Opt+I/J/C/U)', 'scrapelock' ), __( 'Cosmetic: it only blocks the shortcuts. It does not detect DevTools and does not “break” the page.', 'scrapelock' ) );
				self::checkbox( $s, 'block_image_drag', __( 'Dragging images and “Save image” with a long press on mobile', 'scrapelock' ) );
				self::checkbox( $s, 'block_text_drag', __( 'Dragging selected text to another window', 'scrapelock' ) );
				self::checkbox( $s, 'blur_on_capture', __( 'Blur the page when Print Screen or a screenshot shortcut is pressed', 'scrapelock' ), __( 'Optional. It only catches the shortcuts the browser sees (Print Screen, Win+Shift+S, Cmd+Shift+3/4/5). It does not stop tools outside the browser or a photo taken with a phone.', 'scrapelock' ) );
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'When someone copies', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::radios(
					$s,
					'copy_mode',
					__( 'When someone copies', 'scrapelock' ),
					array(
						'block'       => __( 'Block copying', 'scrapelock' ),
						'attribution' => __( 'Allow, with the source and link appended at the end', 'scrapelock' ),
						'allow'       => __( 'Allow normally', 'scrapelock' ),
					)
				);
				self::number( $s, 'attribution_min_chars', __( 'Append the source from this many characters and up:', 'scrapelock' ), __( 'Shorter snippets (e.g. a word or a phone number) are copied without the source. 0 = always.', 'scrapelock' ) );
				?>
				<p class="description"><?php esc_html_e( 'The source option makes sense when text selection is allowed.', 'scrapelock' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Printing', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::radios(
					$s,
					'print_mode',
					__( 'Printing', 'scrapelock' ),
					array(
						'source' => __( 'Allowed, with the page link at the bottom (recommended)', 'scrapelock' ),
						'allow'  => __( 'Allowed normally', 'scrapelock' ),
						'block'  => __( 'Not allowed', 'scrapelock' ),
					)
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pncp-exempt_cap"><?php esc_html_e( 'Who is excluded', 'scrapelock' ); ?></label></th>
			<td>
				<?php
				self::select(
					$s,
					'exempt_cap',
					array(
						'edit_posts'        => __( 'Logged-in authors and administrators', 'scrapelock' ),
						'edit_others_posts' => __( 'Logged-in editors and administrators', 'scrapelock' ),
						'manage_options'    => __( 'Administrators only', 'scrapelock' ),
						'none'              => __( 'Nobody (for testing the protection)', 'scrapelock' ),
					),
					__( 'Applies to the on-page protection and to the server checks (bots, limits, blocks). Customers with an account are not excluded.', 'scrapelock' )
				);
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Message to the visitor', 'scrapelock' ); ?></th>
			<td>
				<?php self::checkbox( $s, 'show_message', __( 'Show a message when something is blocked', 'scrapelock' ) ); ?>
				<label for="pncp-message" class="screen-reader-text"><?php esc_html_e( 'Message text', 'scrapelock' ); ?></label>
				<input type="text" class="large-text" id="pncp-message" name="<?php echo esc_attr( PNCP_OPTION ); ?>[message]" value="<?php echo esc_attr( $s['message'] ); ?>" maxlength="200">
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Iframe and AI', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::checkbox( $s, 'block_framing', __( 'Pages do not open inside another site\'s iframe', 'scrapelock' ), __( 'X-Frame-Options and CSP frame-ancestors headers. WordPress embeds (oEmbed) keep working.', 'scrapelock' ) );
				self::checkbox( $s, 'tdm_reservation', __( 'Opt-out declaration from AI training and text and data mining (TDM)', 'scrapelock' ), __( 'tdm-reservation header and meta, /.well-known/tdmrep.json and noai/noimageai. Legal basis: Directive (EU) 2019/790, Article 4(3): reservation of rights in a machine-readable format.', 'scrapelock' ) );
				?>
				<p><label for="pncp-tdm_policy_url"><?php esc_html_e( 'Page with the licensing terms (optional):', 'scrapelock' ); ?></label>
				<input type="url" class="regular-text code" id="pncp-tdm_policy_url" name="<?php echo esc_attr( PNCP_OPTION ); ?>[tdm_policy_url]" value="<?php echo esc_attr( $s['tdm_policy_url'] ); ?>" placeholder="https://"></p>
			</td>
		</tr>
		<?php
		self::form_close();
	}

	/**
	 * Profiles and Emergency Mode.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private static function render_profiles( $s ) {
		$current  = PNCP_Options::current_profile( $s );
		$profiles = array(
			'balanced' => array( __( 'Balanced', 'scrapelock' ), __( 'Copy with source, limit 60 pages/minute, block after 3 violations. For most sites.', 'scrapelock' ) ),
			'strict'   => array( __( 'Strict', 'scrapelock' ), __( 'No text selection or copying, limit 40 pages/minute, 3-hour block after 2 violations.', 'scrapelock' ) ),
			'maximum'  => array( __( 'Maximum', 'scrapelock' ), __( 'Also no printing and no RSS, browsers only, blur on screenshot, limit 30/minute, 12-hour block after 2 violations. May annoy some visitors.', 'scrapelock' ) ),
		);
		$until    = PNCP_Options::emergency_until();
		?>
		<div class="pncp-cards">
			<section class="pncp-card" aria-labelledby="pncp-profiles-title">
				<h2 id="pncp-profiles-title"><?php esc_html_e( 'Presets', 'scrapelock' ); ?></h2>
				<p class="description"><?php esc_html_e( 'One click changes the protection and limit settings. Lists, feeds, IPs and watermark do not change. You can adjust anything afterwards.', 'scrapelock' ); ?></p>
				<p><?php esc_html_e( 'Current:', 'scrapelock' ); ?> <strong><?php echo esc_html( '' === $current ? __( 'Custom', 'scrapelock' ) : $profiles[ $current ][0] ); ?></strong></p>
				<?php self::action_form_open( 'pncp_profile', 'pncp_profile', 'pncp-profile-form' ); ?>
					<?php foreach ( $profiles as $key => $profile ) : ?>
						<div class="pncp-profile<?php echo $current === $key ? ' is-current' : ''; ?>">
							<button type="submit" name="profile" value="<?php echo esc_attr( $key ); ?>" class="button<?php echo 'balanced' === $key ? ' button-primary' : ''; ?>" aria-describedby="pncp-profile-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $profile[0] ); ?></button>
							<p class="description" id="pncp-profile-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $profile[1] ); ?></p>
						</div>
					<?php endforeach; ?>
				</form>
			</section>
			<section class="pncp-card pncp-card--emergency" aria-labelledby="pncp-emergency-title">
				<h2 id="pncp-emergency-title"><?php esc_html_e( 'Emergency Mode', 'scrapelock' ); ?></h2>
				<p class="description"><?php esc_html_e( 'For a scraping attack in progress: browsers only, limit 20 pages/minute, 6-hour block after 2 violations, REST/GraphQL without content. Your settings do not change: when it ends, they apply again as they were.', 'scrapelock' ); ?></p>
				<?php if ( $until ) : ?>
					<p class="pncp-emergency-on">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: date and time */
								__( 'Active until %s.', 'scrapelock' ),
								wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $until )
							)
						);
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( PNCP_Status::emergency_stop_url() ); ?>"><?php esc_html_e( 'End now', 'scrapelock' ); ?></a></p>
				<?php else : ?>
					<?php self::action_form_open( PNCP_Status::EMERGENCY, PNCP_Status::EMERGENCY ); ?>
						<p>
							<label for="pncp-emergency-hours"><?php esc_html_e( 'Duration:', 'scrapelock' ); ?></label>
							<select id="pncp-emergency-hours" name="hours">
								<option value="1"><?php esc_html_e( '1 hour', 'scrapelock' ); ?></option>
								<option value="6"><?php esc_html_e( '6 hours', 'scrapelock' ); ?></option>
								<option value="24" selected><?php esc_html_e( '24 hours', 'scrapelock' ); ?></option>
								<option value="72"><?php esc_html_e( '3 days', 'scrapelock' ); ?></option>
							</select>
							<button type="submit" class="button pncp-button-danger"><?php esc_html_e( 'Start Emergency Mode', 'scrapelock' ); ?></button>
						</p>
					</form>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * "Bots and limits" tab.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private static function tab_bots( $s ) {
		self::form_open( 'bots' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Scrapers and AI bots', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::checkbox( $s, 'block_bots', __( 'Block (403) the User-Agents in the list', 'scrapelock' ) );
				self::checkbox( $s, 'block_empty_ua', __( 'Block requests without a User-Agent', 'scrapelock' ) );
				self::checkbox( $s, 'block_fake_crawlers', __( 'Block fake Googlebot, Bingbot, Applebot and Yandex', 'scrapelock' ), __( 'The IP is checked against the official Google and Bing lists and with reverse DNS. Real search engines always pass, without limits.', 'scrapelock' ) );
				self::checkbox( $s, 'require_browser_headers', __( 'Browsers only: 403 for pages without Accept-Language or without “Mozilla/” in the User-Agent', 'scrapelock' ), __( 'Stops most scripts. Link previews (Facebook, Viber, WhatsApp etc.) in the “Always allowed” list pass.', 'scrapelock' ) );
				self::checkbox( $s, 'trap_enabled', __( 'Bot trap', 'scrapelock' ), __( 'Invisible link on every page, disallowed in robots.txt. Whoever opens it is blocked temporarily, even if it pretends to be a browser. Visitors do not see it, and another site cannot trap them.', 'scrapelock' ) );
				self::textarea( $s, 'bot_list', __( 'User-Agents to block (one per line, matches anywhere in the User-Agent, case-insensitive):', 'scrapelock' ), __( 'It contains two AI categories: training crawlers (GPTBot, ClaudeBot, CCBot, Google-Extended etc.) and AI search / assistants (OAI-SearchBot, ChatGPT-User, Claude-SearchBot, PerplexityBot etc.). You can allow the second one with the option below.', 'scrapelock' ), 10 );
				self::checkbox( $s, 'allow_ai_search', __( 'Allow AI search engines and assistants (not training crawlers)', 'scrapelock' ), sprintf( /* translators: %s: list of User-Agents */ __( 'So the site appears in AI search answers with a link, without being given for model training. Allowed: %s. They are also removed from the robots.txt Disallow and from the server rules. The page limit still applies, because the User-Agent can be spoofed.', 'scrapelock' ), implode( ', ', PNCP_Options::ai_search_agents() ) ) );
				self::textarea( $s, 'allow_list', __( 'Always allowed User-Agents (link previews, price comparison platforms, monitoring):', 'scrapelock' ), __( 'They override the bot list and “Browsers only”. The page limit applies to them too, because the User-Agent is easy to spoof. To accept an entry only from specific IPs, add @ and the addresses, e.g. Skroutz@203.0.113.0/24.', 'scrapelock' ), 5 );
				self::textarea( $s, 'allow_ips', __( 'Always allowed IPs / CIDR (office, ERP, monitoring), one per line:', 'scrapelock' ), __( 'They are never checked, never count towards limits and are never blocked.', 'scrapelock' ), 3 );
				self::checkbox( $s, 'robots_ai_block', __( 'Declare AI crawlers in robots.txt (Disallow)', 'scrapelock' ), __( 'Applies to the virtual robots.txt of WordPress. If a physical robots.txt file exists, it takes precedence.', 'scrapelock' ) );
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Limits per IP', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::number( $s, 'rate_limit', __( 'Pages per minute (0 = off):', 'scrapelock' ), __( 'Starting value: 60. Above the limit it returns 429. Be careful with networks that share an IP (companies, mobile carriers) and with CDNs: set the “Real visitor IP” correctly first.', 'scrapelock' ) );
				self::number( $s, 'rate_limit_search', __( 'Searches (?s=) per minute (0 = off):', 'scrapelock' ), __( 'Search is heavy for the server and a favourite of scrapers. Starting value: 20.', 'scrapelock' ) );
				self::number( $s, 'rate_limit_rest', __( 'REST API (/wp-json/) and GraphQL requests per minute (0 = off):', 'scrapelock' ), __( 'Only read requests from visitors. Cart, checkout and forms (POST) do not count. Starting value: 60.', 'scrapelock' ) );
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Temporary block', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::number( $s, 'ban_strikes', __( 'Block after this many violations within 10 minutes (0 = never):', 'scrapelock' ), __( 'Violation: every minute in which the IP exceeded a limit, and every fake Googlebot. The trap blocks immediately.', 'scrapelock' ) );
				self::number( $s, 'ban_minutes', __( 'Block duration (minutes):', 'scrapelock' ) );
				?>
				<p><label for="pncp-log_retention"><?php esc_html_e( 'Keep the block log for:', 'scrapelock' ); ?></label>
				<?php
				self::select(
					$s,
					'log_retention',
					array(
						'1'  => __( '1 day', 'scrapelock' ),
						'7'  => __( '7 days', 'scrapelock' ),
						'30' => __( '30 days', 'scrapelock' ),
					),
					__( 'The log keeps IP and User-Agent, i.e. personal data (GDPR). It is deleted automatically.', 'scrapelock' )
				);
				?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pncp-ip_header"><?php esc_html_e( 'Real visitor IP', 'scrapelock' ); ?></label></th>
			<td>
				<?php
				self::select(
					$s,
					'ip_header',
					array(
						'remote_addr'      => __( 'REMOTE_ADDR (no CDN/proxy)', 'scrapelock' ),
						'cf_connecting_ip' => 'CF-Connecting-IP (Cloudflare)',
						'x_forwarded_for'  => 'X-Forwarded-For',
						'x_real_ip'        => 'X-Real-IP',
						'true_client_ip'   => 'True-Client-IP',
					),
					__( 'Behind a CDN or reverse proxy all visitors appear with the CDN\'s IP. Choose the header with the real IP.', 'scrapelock' )
				);
				?>
				<p><strong><?php esc_html_e( 'Trusted Proxy:', 'scrapelock' ); ?></strong> <?php esc_html_e( 'the header is read only when the request comes from a proxy you trust. Otherwise anyone could write any IP they want and bypass limits and blocks. CF-Connecting-IP: only from Cloudflare. X-Real-IP / X-Forwarded-For: also from the server itself or a private network (Nginx in front of Apache). True-Client-IP: only from the proxies in the list.', 'scrapelock' ); ?></p>
				<?php
				self::checkbox( $s, 'trust_cloudflare', __( 'Trust Cloudflare addresses', 'scrapelock' ), __( 'The official Cloudflare list is refreshed every day.', 'scrapelock' ) );
				if ( ! empty( $s['trust_any_proxy'] ) ) {
					echo '<div class="pncp-inline-warning">';
					self::checkbox( $s, 'trust_any_proxy', __( 'Trust every request (1.4 behaviour, insecure)', 'scrapelock' ), __( 'On because this is how version 1.4 worked. Fill in your proxies below and uncheck it. Once turned off, it cannot be turned on again.', 'scrapelock' ) );
					echo '</div>';
				}
				self::textarea( $s, 'trusted_proxies', __( 'Other trusted proxies / load balancers (IP or CIDR, one per line):', 'scrapelock' ), '', 3 );
				?>
				<p class="description">
					<?php
					esc_html_e( 'What the server sees on this request:', 'scrapelock' );
					echo '<br><code>REMOTE_ADDR = ' . esc_html( PNCP_Ip::remote_addr() ) . '</code>';
					foreach ( PNCP_Ip::ip_headers() as $key ) {
						if ( ! empty( $_SERVER[ $key ] ) ) {
							echo '<br><code>' . esc_html( $key . ' = ' . sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) . '</code>';
						}
					}
					echo '<br>' . esc_html__( 'Trusted proxy:', 'scrapelock' ) . ' <code>' . esc_html( PNCP_Ip::is_trusted_proxy( PNCP_Ip::remote_addr(), $s, (string) $s['ip_header'] ) ? __( 'yes', 'scrapelock' ) : __( 'no', 'scrapelock' ) ) . '</code>';
					echo '<br>' . esc_html__( 'IP the plugin uses now:', 'scrapelock' ) . ' <code>' . esc_html( PNCP_Ip::client_ip() ) . '</code>';
					?>
				</p>
			</td>
		</tr>
		<?php
		self::form_close();
		self::render_ip_lists();
	}

	/**
	 * Status of the official IP lists.
	 */
	private static function render_ip_lists() {
		$updated = PNCP_Ip::lists_updated();
		$names   = array(
			'google'     => 'Google',
			'bing'       => 'Bing',
			'cloudflare' => 'Cloudflare',
		);
		?>
		<h2><?php esc_html_e( 'Official IP lists', 'scrapelock' ); ?></h2>
		<ul class="pncp-list-status">
			<?php foreach ( $names as $key => $label ) : ?>
				<li>
					<strong><?php echo esc_html( $label ); ?>:</strong>
					<?php
					if ( empty( $updated[ $key ] ) ) {
						echo esc_html( 'cloudflare' === $key ? __( 'built-in list (not downloaded yet)', 'scrapelock' ) : __( 'not downloaded yet, only DNS is used', 'scrapelock' ) );
					} else {
						echo esc_html(
							sprintf(
								/* translators: 1: number of addresses, 2: date */
								__( '%1$s addresses, updated %2$s', 'scrapelock' ),
								number_format_i18n( count( PNCP_Ip::list_of( $key ) ) ),
								wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $updated[ $key ] )
							)
						);
					}
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php self::action_form_open( 'pncp_log', 'pncp_log' ); ?>
			<input type="hidden" name="op" value="refresh_lists">
			<?php submit_button( __( 'Refresh now', 'scrapelock' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * "Feeds and API" tab.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private static function tab_content( $s ) {
		self::render_tokens( $s );
		self::form_open( 'content' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Protected feeds', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::textarea( $s, 'private_paths', __( 'Paths that are not public (one per line, * means “anything”), e.g. /skroutz.xml, /feeds/*.xml:', 'scrapelock' ) );
				self::textarea( $s, 'private_ips', __( 'Or allowed IPs / CIDR of the service (one per line):', 'scrapelock' ), __( 'Without a valid token or an allowed IP it returns 403. Applies to feeds generated by PHP. For static .xml files see the “Server rules” tab.', 'scrapelock' ), 3 );
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'REST API', 'scrapelock' ); ?></th>
			<td>
				<?php self::checkbox( $s, 'rest_hide_content', __( 'Hide the text (content, excerpt) of posts, pages and products from visitors', 'scrapelock' ), __( 'REST stays open for forms, oEmbed, SEO plugins and your own endpoints. Users who can write posts see the text normally. Exceptions from code: filter pncp_rest_hide_content.', 'scrapelock' ) ); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'GraphQL', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::checkbox( $s, 'graphql_hide_content', __( 'Hide the text in WPGraphQL (content, excerpt, product descriptions)', 'scrapelock' ) );
				echo '<p class="description">' . esc_html( PNCP_Graphql::active() ? __( 'WPGraphQL is active: the bot check, blocks and the REST / GraphQL limit apply too.', 'scrapelock' ) : __( 'WPGraphQL is not installed; the setting will apply if you install it.', 'scrapelock' ) ) . '</p>';
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'RSS / Atom feeds', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::radios(
					$s,
					'feed_mode',
					__( 'RSS / Atom feeds', 'scrapelock' ),
					array(
						'excerpt'  => __( 'Short excerpt only, with a link to the post (recommended)', 'scrapelock' ),
						'full'     => __( 'Full text', 'scrapelock' ),
						'disabled' => __( 'Disabled', 'scrapelock' ),
					)
				);
				?>
			</td>
		</tr>
		<?php
		self::form_close();
	}

	/**
	 * Token, per-service URLs and feed statistics.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private static function render_tokens( $s ) {
		$data  = PNCP_Private_Urls::token_data();
		$key   = 'pncp_new_token_' . get_current_user_id();
		$fresh = (string) get_transient( $key );
		if ( '' !== $fresh ) {
			delete_transient( $key ); // Shown only once.
		}
		$paths = PNCP_Options::parse_list( $s['private_paths'] );
		$stats = PNCP_Private_Urls::stats();
		?>
		<section class="pncp-card pncp-card--wide" aria-labelledby="pncp-token-title">
			<h2 id="pncp-token-title"><?php esc_html_e( 'Access token', 'scrapelock' ); ?></h2>
			<?php if ( '' !== $fresh ) : ?>
				<div class="notice notice-success inline pncp-fresh-token">
					<p><strong><?php esc_html_e( 'New token. Copy it now: it will not be shown again.', 'scrapelock' ); ?></strong></p>
					<p><code id="pncp-token-value" class="pncp-token"><?php echo esc_html( $fresh ); ?></code> <button type="button" class="button pncp-copy" data-target="pncp-token-value"><?php esc_html_e( 'Copy', 'scrapelock' ); ?></button></p>
					<p><?php esc_html_e( 'Header for ERP / API:', 'scrapelock' ); ?> <code>X-ScrapeLock-Token: <?php echo esc_html( $fresh ); ?></code> <?php esc_html_e( 'or', 'scrapelock' ); ?> <code>Authorization: Bearer <?php echo esc_html( $fresh ); ?></code></p>
					<?php if ( $paths ) : ?>
						<p><?php esc_html_e( 'URL for Skroutz, BestPrice, Google Merchant (they accept only URLs):', 'scrapelock' ); ?></p>
						<ul>
							<?php foreach ( $paths as $i => $pattern ) : ?>
								<li><code id="pncp-url-<?php echo (int) $i; ?>"><?php echo esc_html( PNCP_Private_Urls::example_url( $pattern, $fresh ) ); ?></code> <button type="button" class="button button-small pncp-copy" data-target="pncp-url-<?php echo (int) $i; ?>"><?php esc_html_e( 'Copy', 'scrapelock' ); ?></button></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<p>
				<?php
				if ( '' === $data['hash'] ) {
					esc_html_e( 'There is no token. Create one to give the services access.', 'scrapelock' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: 1: last 4 characters, 2: date */
							__( 'Active token ending in …%1$s, since %2$s. Only its hash is stored, so it cannot be shown again.', 'scrapelock' ),
							$data['hint'],
							$data['created'] ? wp_date( get_option( 'date_format' ), $data['created'] ) : '—'
						)
					);
				}
				?>
			</p>
			<?php if ( '' !== $data['prev_hash'] && $data['prev_until'] > time() ) : ?>
				<p class="pncp-inline-warning">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: last 4 characters, 2: date */
							__( 'The previous token (…%1$s) is still valid until %2$s, so you have time to change the URL in the services.', 'scrapelock' ),
							$data['prev_hint'],
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $data['prev_until'] )
						)
					);
					?>
				</p>
				<?php self::action_form_open( 'pncp_token', 'pncp_token' ); ?>
					<input type="hidden" name="op" value="revoke_prev">
					<?php submit_button( __( 'Revoke the previous one now', 'scrapelock' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<?php self::action_form_open( 'pncp_token', 'pncp_token', 'pncp-token-form' ); ?>
				<input type="hidden" name="op" value="rotate">
				<p>
					<?php if ( '' !== $data['hash'] ) : ?>
						<label for="pncp-grace"><?php esc_html_e( 'Keep the current token valid for:', 'scrapelock' ); ?></label>
						<select id="pncp-grace" name="grace">
							<option value="0"><?php esc_html_e( 'Not at all (revoke immediately)', 'scrapelock' ); ?></option>
							<option value="1"><?php esc_html_e( '1 day', 'scrapelock' ); ?></option>
							<option value="7" selected><?php esc_html_e( '7 days', 'scrapelock' ); ?></option>
							<option value="30"><?php esc_html_e( '30 days', 'scrapelock' ); ?></option>
						</select>
					<?php endif; ?>
					<button type="submit" class="button button-primary"><?php echo esc_html( '' === $data['hash'] ? __( 'Create token', 'scrapelock' ) : __( 'New token (rotate)', 'scrapelock' ) ); ?></button>
				</p>
			</form>
			<p class="description"><?php esc_html_e( 'Header for ERP and your own integrations (not visible in logs and history). Token in the URL for Skroutz, BestPrice and Google Merchant, which accept only URLs.', 'scrapelock' ); ?></p>

			<?php if ( $paths ) : ?>
				<h3><?php esc_html_e( 'Access per feed', 'scrapelock' ); ?></h3>
				<table class="widefat striped pncp-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Path', 'scrapelock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Successes', 'scrapelock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last access', 'scrapelock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Via', 'scrapelock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Denials', 'scrapelock' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$via_labels = array(
							'header' => __( 'header', 'scrapelock' ),
							'url'    => __( 'token in URL', 'scrapelock' ),
							'ip'     => __( 'allowed IP', 'scrapelock' ),
							'admin'  => __( 'administrator', 'scrapelock' ),
						);
						foreach ( $paths as $pattern ) :
							$row = isset( $stats[ strtolower( $pattern ) ] ) ? $stats[ strtolower( $pattern ) ] : array();
							?>
							<tr>
								<td><code><?php echo esc_html( $pattern ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( isset( $row['ok'] ) ? (int) $row['ok'] : 0 ) ); ?></td>
								<td><?php echo esc_html( ! empty( $row['last_ok'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['last_ok'] ) . ' · ' . $row['last_ip'] : __( 'Never', 'scrapelock' ) ); ?></td>
								<td><?php echo esc_html( ! empty( $row['last_via'] ) && isset( $via_labels[ $row['last_via'] ] ) ? $via_labels[ $row['last_via'] ] : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( isset( $row['denied'] ) ? (int) $row['denied'] : 0 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * "Images" tab.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private static function tab_images( $s ) {
		self::form_open( 'images' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Watermark', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::checkbox( $s, 'watermark_enabled', __( 'Watermark new images', 'scrapelock' ), __( 'It is added to the sizes WordPress generates (thumbnails, medium, large). For existing images, use the tool below. The site icon, the logo and the watermark itself never get it.', 'scrapelock' ) );
				if ( ! PNCP_Watermark::available() ) {
					echo '<p class="pncp-inline-warning"><strong>' . esc_html__( 'The PHP GD library is not available on the server, so the watermark will not be applied.', 'scrapelock' ) . '</strong></p>';
				}
				self::checkbox( $s, 'watermark_full', __( 'Watermark the full-size image too', 'scrapelock' ), __( 'Without this, the full image stays clean and can be downloaded from its URL. The clean original is kept in a hidden folder, so it can always be restored.', 'scrapelock' ) );
				self::checkbox( $s, 'watermark_products', __( 'Watermark WooCommerce product images too', 'scrapelock' ) );
				?>
				<p class="pncp-inline-warning" id="pncp-products-warning"><?php esc_html_e( 'Not recommended: Google Merchant Center, Skroutz and BestPrice reject product images with a watermark, and feeds usually show the full-size image. For products, hotlink protection (“Server rules” tab) is a better choice. If a watermarked image later becomes a product image, logo or icon, the watermark is removed automatically.', 'scrapelock' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Appearance', 'scrapelock' ); ?></th>
			<td>
				<?php
				self::number( $s, 'watermark_image_id', __( 'PNG image ID from the Media Library (0 = the site\'s domain as text):', 'scrapelock' ), __( 'A PNG with a transparent background is recommended (e.g. the logo). The ID appears in the address when you open the image in the Library.', 'scrapelock' ) );
				?>
				<p><label for="pncp-watermark_position"><?php esc_html_e( 'Position:', 'scrapelock' ); ?></label>
				<?php
				self::select(
					$s,
					'watermark_position',
					array(
						'br'     => __( 'Bottom right', 'scrapelock' ),
						'bl'     => __( 'Bottom left', 'scrapelock' ),
						'tr'     => __( 'Top right', 'scrapelock' ),
						'tl'     => __( 'Top left', 'scrapelock' ),
						'center' => __( 'Centre', 'scrapelock' ),
						'tiled'  => __( 'Repeat diagonally across the whole image (hard to remove)', 'scrapelock' ),
					)
				);
				?>
				</p>
				<?php
				self::number( $s, 'watermark_size', __( 'Size (% of the image width):', 'scrapelock' ), __( 'With diagonal repeat, the size of each repetition.', 'scrapelock' ) );
				self::number( $s, 'watermark_opacity', __( 'Opacity (%):', 'scrapelock' ) );
				self::number( $s, 'watermark_min_width', __( 'Only on sizes with width from (px):', 'scrapelock' ), __( 'Small thumbnails stay clean.', 'scrapelock' ) );
				?>
			</td>
		</tr>
		<?php
		self::form_close();
		self::render_watermark_tool( $s );
	}

	/**
	 * Apply / remove tool for existing images.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private static function render_watermark_tool( $s ) {
		$total = self::image_count();
		?>
		<section class="pncp-card pncp-card--wide" aria-labelledby="pncp-tool-title">
			<h2 id="pncp-tool-title"><?php esc_html_e( 'Existing images', 'scrapelock' ); ?></h2>
			<p><?php echo esc_html( sprintf( /* translators: %s: count */ __( 'JPEG / PNG / WebP images in the library: %s.', 'scrapelock' ), number_format_i18n( $total ) ) ); ?></p>
			<p class="description"><?php esc_html_e( 'Each image is regenerated from the clean original, so a watermark is never added twice, not even on images watermarked by version 1.x. If the page closes midway, click again: it resumes where it stopped. Keep a backup of the uploads folder before you start.', 'scrapelock' ); ?></p>
			<?php if ( empty( $s['watermark_enabled'] ) ) : ?>
				<p class="pncp-inline-warning"><?php esc_html_e( 'The watermark is off: “Apply” will leave the images clean. Enable it and save first.', 'scrapelock' ); ?></p>
			<?php endif; ?>
			<p class="pncp-tool-buttons">
				<button type="button" class="button button-primary pncp-wm-run" data-mode="apply" data-total="<?php echo (int) $total; ?>"<?php disabled( ! PNCP_Watermark::available() ); ?>><?php esc_html_e( 'Apply watermark', 'scrapelock' ); ?></button>
				<button type="button" class="button pncp-wm-run" data-mode="remove" data-total="<?php echo (int) $total; ?>"><?php esc_html_e( 'Remove watermark', 'scrapelock' ); ?></button>
			</p>
			<div class="pncp-progress" hidden>
				<progress max="100" value="0" aria-labelledby="pncp-progress-text"></progress>
				<p id="pncp-progress-text" role="status" aria-live="polite"></p>
			</div>
		</section>
		<?php
	}

	/**
	 * How many images the library has.
	 *
	 * @return int
	 */
	private static function image_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Admin screen.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp')" );
	}

	/**
	 * AJAX: one batch of images. The cursor is the last completed ID.
	 */
	public static function ajax_wm_batch() {
		check_ajax_referer( 'pncp_wm_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'scrapelock' ) ), 403 );
		}
		$mode   = isset( $_POST['mode'] ) && 'remove' === $_POST['mode'] ? 'remove' : 'apply';
		$cursor = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$other  = 'apply' === $mode ? 'remove' : 'apply';
		if ( 0 === $cursor ) {
			// New run: if the same action was interrupted, it resumes where it stopped. The other
			// action forgets its position (after "Remove", "Apply" starts from the beginning).
			$cursor = (int) get_option( 'pncp_wm_cursor_' . $mode, 0 );
			delete_option( 'pncp_wm_cursor_' . $other );
		}

		global $wpdb;
		$batch = (int) apply_filters( 'pncp_watermark_batch_size', 3 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tool batch.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp') AND ID > %d ORDER BY ID ASC LIMIT %d", $cursor, max( 1, $batch ) ) );

		$errors = 0;
		foreach ( $ids as $id ) {
			if ( 'error' === PNCP_Watermark::rebuild( (int) $id, 'apply' === $mode ) ) {
				++$errors;
			}
			$cursor = (int) $id;
			update_option( 'pncp_wm_cursor_' . $mode, $cursor, false );
		}
		$done = count( $ids ) < max( 1, $batch );
		if ( $done ) {
			delete_option( 'pncp_wm_cursor_' . $mode );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Tool progress.
		$position = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp') AND ID <= %d", $cursor ) );
		wp_send_json_success(
			array(
				'cursor'   => $cursor,
				'position' => $position,
				'errors'   => $errors,
				'done'     => $done,
			)
		);
	}

	/**
	 * "Server rules" tab.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private static function tab_rules( $s ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Same signature as the other tabs.
		?>
		<p><?php esc_html_e( 'The plugin only checks requests that reach PHP. Pages from page cache, images and static files are served directly by the server or the CDN. The rules below are generated for your site, with the current settings. Test them on staging first.', 'scrapelock' ); ?></p>
		<p class="description"><?php esc_html_e( 'The plugin does not keep a Cloudflare API token in the database: you add the rules yourself, where you already have access.', 'scrapelock' ); ?></p>

		<h2><?php esc_html_e( 'Cloudflare', 'scrapelock' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Security → WAF → Custom rules → Create rule → Edit expression. Paste the expression and choose the action shown.', 'scrapelock' ); ?></p>
		<?php foreach ( PNCP_Rules::cloudflare() as $key => $rule ) : ?>
			<h3><?php echo esc_html( $rule['title'] ); ?> <span class="pncp-badge"><?php echo esc_html( $rule['action'] ); ?></span></h3>
			<?php self::code_block( 'pncp-cf-' . $key, $rule['expression'], 3 ); ?>
		<?php endforeach; ?>

		<h2><?php esc_html_e( 'Nginx', 'scrapelock' ); ?></h2>
		<p class="description"><?php esc_html_e( '1) Inside http { }, e.g. in the file /etc/nginx/conf.d/scrapelock.conf:', 'scrapelock' ); ?></p>
		<?php self::code_block( 'pncp-nginx-http', PNCP_Rules::nginx_http(), 8 ); ?>
		<p class="description"><?php esc_html_e( '2) Inside the site\'s server { }, before the other location blocks. Then: nginx -t and reload.', 'scrapelock' ); ?></p>
		<?php self::code_block( 'pncp-nginx-server', PNCP_Rules::nginx_server(), 18 ); ?>

		<h2><?php esc_html_e( 'Apache (.htaccess, above # BEGIN WordPress)', 'scrapelock' ); ?></h2>
		<?php self::code_block( 'pncp-apache', PNCP_Rules::apache(), 14 ); ?>
		<?php
	}

	/**
	 * Read-only code field with a copy button.
	 *
	 * @param string $id   ID.
	 * @param string $code Code.
	 * @param int    $rows Rows.
	 */
	private static function code_block( $id, $code, $rows ) {
		printf(
			'<div class="pncp-code"><textarea id="%1$s" class="large-text code" rows="%2$d" readonly aria-label="%3$s">%4$s</textarea><p><button type="button" class="button pncp-copy" data-target="%1$s">%5$s</button></p></div>',
			esc_attr( $id ),
			(int) $rows,
			esc_attr__( 'Rules to copy', 'scrapelock' ),
			esc_textarea( $code ),
			esc_html__( 'Copy', 'scrapelock' )
		);
	}

	/**
	 * "Log" tab: 24-hour summary, active bans, recent events.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	private static function tab_log( $s ) {
		$summary = PNCP_Log::summary( 24 );
		$reasons = PNCP_Log::reasons();
		$bans    = PNCP_Log::active_bans();
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<h2><?php esc_html_e( 'Last 24 hours', 'scrapelock' ); ?></h2>
		<p class="pncp-big-number"><?php echo esc_html( sprintf( /* translators: %s: count */ __( '%s blocked requests', 'scrapelock' ), number_format_i18n( $summary['total'] ) ) ); ?></p>
		<div class="pncp-cards">
			<section class="pncp-card">
				<h3><?php esc_html_e( 'By reason', 'scrapelock' ); ?></h3>
				<?php self::count_list( $summary['reasons'], $reasons ); ?>
			</section>
			<section class="pncp-card">
				<h3><?php esc_html_e( 'Top IPs', 'scrapelock' ); ?></h3>
				<?php self::count_list( $summary['ips'] ); ?>
			</section>
			<section class="pncp-card">
				<h3><?php esc_html_e( 'Top User-Agents', 'scrapelock' ); ?></h3>
				<?php self::count_list( $summary['uas'] ); ?>
			</section>
		</div>

		<h2><?php esc_html_e( 'IPs blocked now', 'scrapelock' ); ?></h2>
		<?php if ( ! $bans ) : ?>
			<p><?php esc_html_e( 'None.', 'scrapelock' ); ?></p>
		<?php else : ?>
			<table class="widefat striped pncp-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'IP', 'scrapelock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'scrapelock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Expires', 'scrapelock' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Action', 'scrapelock' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $bans, 0, 200, true ) as $ip => $ban ) : ?>
						<tr>
							<td><code><?php echo esc_html( $ip ); ?></code></td>
							<td><?php echo esc_html( isset( $reasons[ $ban['reason'] ] ) ? $reasons[ $ban['reason'] ] : $ban['reason'] ); ?></td>
							<td><?php echo esc_html( wp_date( $format, (int) $ban['until'] ) ); ?></td>
							<td>
								<?php self::action_form_open( 'pncp_log', 'pncp_log', 'pncp-inline-form' ); ?>
									<input type="hidden" name="op" value="unban">
									<input type="hidden" name="ip" value="<?php echo esc_attr( $ip ); ?>">
									<button type="submit" class="button button-small"><?php esc_html_e( 'Unblock', 'scrapelock' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::action_form_open( 'pncp_log', 'pncp_log' ); ?>
				<input type="hidden" name="op" value="unban_all">
				<?php submit_button( __( 'Unblock all', 'scrapelock' ), 'secondary', 'submit', true ); ?>
			</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Latest events', 'scrapelock' ); ?></h2>
		<?php $rows = PNCP_Log::recent( 100 ); ?>
		<?php if ( ! $rows ) : ?>
			<p><?php esc_html_e( 'No events.', 'scrapelock' ); ?></p>
		<?php else : ?>
			<table class="widefat striped pncp-table pncp-log-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'scrapelock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'IP', 'scrapelock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'scrapelock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Requests', 'scrapelock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Path', 'scrapelock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'User-Agent', 'scrapelock' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( $format, (int) strtotime( $row['created'] . ' UTC' ) ) ); ?></td>
							<td><code><?php echo esc_html( $row['ip'] ); ?></code></td>
							<td><?php echo esc_html( ( isset( $reasons[ $row['reason'] ] ) ? $reasons[ $row['reason'] ] : $row['reason'] ) . ' (' . $row['code'] . ')' ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['hits'] ) ); ?></td>
							<td><code><?php echo esc_html( $row['path'] ); ?></code></td>
							<td class="pncp-ua"><?php echo esc_html( $row['ua'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: days */
					__( 'The log is deleted automatically after %s days (setting in the “Bots and limits” tab).', 'scrapelock' ),
					$s['log_retention']
				)
			);
			?>
		</p>
		<?php self::action_form_open( 'pncp_log', 'pncp_log' ); ?>
			<input type="hidden" name="op" value="clear_log">
			<?php submit_button( __( 'Delete log', 'scrapelock' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * "value — count" list.
	 *
	 * @param array<string, int>    $counts Counts.
	 * @param array<string, string> $labels Labels (optional).
	 */
	private static function count_list( $counts, $labels = array() ) {
		if ( ! $counts ) {
			echo '<p>' . esc_html__( 'Nothing.', 'scrapelock' ) . '</p>';
			return;
		}
		echo '<ol class="pncp-count-list">';
		foreach ( $counts as $key => $count ) {
			printf( '<li><span>%s</span> <strong>%s</strong></li>', esc_html( isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key ), esc_html( number_format_i18n( $count ) ) );
		}
		echo '</ol>';
	}

	/* ---------- Actions ---------- */

	/**
	 * Capability and nonce check for the actions.
	 *
	 * @param string $nonce Nonce action.
	 */
	private static function verify( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change this setting.', 'scrapelock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Redirects back to a tab with a notice.
	 *
	 * @param string $tab     Tab.
	 * @param string $message Notice code.
	 */
	private static function back( $tab, $message ) {
		wp_safe_redirect(
			PNCP_Status::settings_url(
				array(
					'tab'          => $tab,
					'pncp_message' => $message,
				)
			)
		);
		exit;
	}

	/**
	 * Applies a profile.
	 */
	public static function handle_profile() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is checked in verify().
		self::verify( 'pncp_profile' );
		$profile  = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
		$profiles = PNCP_Options::profiles();
		if ( ! isset( $profiles[ $profile ] ) ) {
			self::back( 'general', 'error' );
		}
		update_option( PNCP_OPTION, array_merge( PNCP_Options::stored(), $profiles[ $profile ] ) );
		PNCP_Options::flush();
		self::back( 'general', 'profile' );
		// phpcs:enable
	}

	/**
	 * Token: new / rotate / revoke the previous one.
	 */
	public static function handle_token() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is checked in verify().
		self::verify( 'pncp_token' );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( 'rotate' === $op ) {
			$grace = isset( $_POST['grace'] ) ? absint( $_POST['grace'] ) : 0;
			$grace = in_array( $grace, array( 0, 1, 7, 30 ), true ) ? $grace : 7;
			$token = PNCP_Private_Urls::rotate( $grace );
			// Shown once, only to this user, for a few minutes.
			set_transient( 'pncp_new_token_' . get_current_user_id(), $token, 10 * MINUTE_IN_SECONDS );
			self::back( 'content', 'token' );
		}
		if ( 'revoke_prev' === $op ) {
			PNCP_Private_Urls::revoke_previous();
			self::back( 'content', 'revoked' );
		}
		self::back( 'content', 'error' );
		// phpcs:enable
	}

	/**
	 * Log and bans.
	 */
	public static function handle_log() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is checked in verify().
		self::verify( 'pncp_log' );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		switch ( $op ) {
			case 'unban':
				$ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					PNCP_Log::unban( $ip );
				}
				self::back( 'log', 'unbanned' );
				break;
			case 'unban_all':
				PNCP_Log::unban_all();
				self::back( 'log', 'unbanned' );
				break;
			case 'clear_log':
				PNCP_Log::clear();
				self::back( 'log', 'cleared' );
				break;
			case 'refresh_lists':
				$result = PNCP_Ip::refresh_lists();
				self::back( 'bots', in_array( 0, $result, true ) ? 'lists_partial' : 'lists' );
				break;
		}
		self::back( 'general', 'error' );
		// phpcs:enable
	}

	/**
	 * Notices after actions, and upgrade notices from 1.4.
	 */
	public static function upgrade_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours   = $screen && 'settings_page_' . self::PAGE === $screen->id;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only displays a notice.
		$message = $ours && isset( $_GET['pncp_message'] ) ? sanitize_key( wp_unslash( $_GET['pncp_message'] ) ) : '';
		$texts   = array(
			'profile'       => array( 'success', __( 'Preset applied.', 'scrapelock' ) ),
			'token'         => array( 'success', __( 'New token created.', 'scrapelock' ) ),
			'revoked'       => array( 'success', __( 'Previous token revoked.', 'scrapelock' ) ),
			'unbanned'      => array( 'success', __( 'Block lifted.', 'scrapelock' ) ),
			'cleared'       => array( 'success', __( 'Log deleted.', 'scrapelock' ) ),
			'lists'         => array( 'success', __( 'IP lists refreshed.', 'scrapelock' ) ),
			'lists_partial' => array( 'warning', __( 'Some IP lists could not be downloaded; their previous version was kept. It will try again tomorrow.', 'scrapelock' ) ),
			'error'         => array( 'error', __( 'The action was not completed.', 'scrapelock' ) ),
		);
		if ( isset( $texts[ $message ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $message ][0] ), esc_html( $texts[ $message ][1] ) );
		}

		// The 1.x token is still valid but exists only as a hash: it cannot be shown.
		if ( $ours && get_option( 'pncp_legacy_token_migrated' ) ) {
			delete_option( 'pncp_legacy_token_migrated' );
			printf(
				'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Since 2.1 the feed token is stored only as a hash. The old token is still valid but can no longer be shown. If you have not saved it, create a new token.', 'scrapelock' ),
				esc_url( PNCP_Status::settings_url( array( 'tab' => 'feeds' ) ) ),
				esc_html__( 'Feeds and token', 'scrapelock' )
			);
		}
		if ( ! empty( PNCP_Options::stored()['trust_any_proxy'] ) ) {
			printf(
				'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'ScrapeLock: the real IP is read from a header without checking the proxy (as in 1.4). This allows IP spoofing.', 'scrapelock' ),
				esc_url( PNCP_Status::settings_url( array( 'tab' => 'bots' ) ) ),
				esc_html__( 'Trusted Proxy settings', 'scrapelock' )
			);
		}
	}
}
