<?php
/**
 * Optional "Security Mode" for WordPress core auto-updates.
 *
 * Adds a Settings-API-powered settings page that lets a site admin restrict
 * WordPress core automatic updates to minor / security releases only, blocking
 * major (and development) version updates.
 *
 * The effect is achieved entirely with WordPress filters registered from the
 * plugin itself — no wp-config.php edits and no runtime file writes:
 *
 *   add_filter( 'allow_major_auto_core_updates', '__return_false' );
 *   add_filter( 'allow_minor_auto_core_updates', '__return_true' );
 *   add_filter( 'allow_dev_auto_core_updates',   '__return_false' );
 *
 * Disabling the toggle simply stops the filters from being registered on the
 * next load; the only thing persisted is the boolean plugin option.
 *
 * @package    WordPress_Plugins
 * @subpackage OS_Disable_WordPress_Updates
 * @since      2.0.0
 */

// Don't load directly.
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

/**
 * Class OSDWP_Security_Mode
 *
 * Implements the optional "Security Mode" for core auto-updates.
 *
 * @since 2.0.0
 */
class OSDWP_Security_Mode {

	/**
	 * Option name used to store the toggle state (boolean).
	 */
	const OPTION_NAME = 'osdwp_security_mode';

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'osdwp-security-mode';

	/**
	 * Settings option-group name (drives nonce action + allowed_options).
	 */
	const OPTION_GROUP = 'osdwp_security_mode_group';

	/**
	 * Whether WP_AUTO_UPDATE_CORE was already defined before this plugin loaded.
	 *
	 * Captured in the constructor (this class is instantiated *before* the main
	 * plugin class) so we can warn the admin when the constant is defined by
	 * something else (wp-config.php, a host panel, an mu-plugin, another plugin).
	 *
	 * @var bool
	 */
	protected $auto_update_core_defined_externally = false;

	/**
	 * The externally-defined value of WP_AUTO_UPDATE_CORE, if any.
	 *
	 * @var mixed
	 */
	protected $auto_update_core_external_value = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// 1) Detect an externally-defined WP_AUTO_UPDATE_CORE BEFORE the main
		//    plugin class defines it. Must run first (see disable-updates.php).
		$this->auto_update_core_defined_externally = defined( 'WP_AUTO_UPDATE_CORE' );
		if ( $this->auto_update_core_defined_externally ) {
			$this->auto_update_core_external_value = WP_AUTO_UPDATE_CORE;
		}

		// 2) Register the three core auto-update filters only when enabled.
		//    This runs on every request (admin, front-end and wp-cron) at
		//    plugin-load time, which is before WP_Automatic_Updater decides
		//    what to install.
		if ( $this->is_enabled() ) {
			$this->register_core_filters();
		}

		// 3) Settings API (page + setting/section/field).
		add_action( 'admin_menu', [$this, 'add_settings_page'] );
		add_action( 'admin_init', [$this, 'register_settings'] );

		// 4) Optional cosmetic annotation on the WordPress Updates screen.
		add_action( 'admin_notices', [$this, 'maybe_annotate_updates_screen'] );

		// 5) Recolor the admin-bar notice from red to orange while active.
		add_action( 'admin_enqueue_scripts', [$this, 'admin_bar_color_override'], 20 );
	}

	/**
	 * Whether Security Mode is currently enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_option( self::OPTION_NAME, false );
	}

	/**
	 * Register the three core auto-update filters that implement Security Mode.
	 *
	 * Priority 20 ensures these win over the base plugin's own allow_* filters
	 * (registered at the default priority 10) and over the defaults derived from
	 * the WP_AUTO_UPDATE_CORE constant.
	 *
	 * Verification: when enabled, has_filter( 'allow_minor_auto_core_updates',
	 * '__return_true' ) is truthy; when disabled it is false.
	 */
	public function register_core_filters() {
		add_filter( 'allow_minor_auto_core_updates', '__return_true', 20 );
		add_filter( 'allow_major_auto_core_updates', '__return_false', 20 );
		add_filter( 'allow_dev_auto_core_updates', '__return_false', 20 );
	}

	/**
	 * Register the Settings API page under "Settings".
	 */
	public function add_settings_page() {
		add_submenu_page(
			'options-general.php',
			__( 'Disable Updates', 'disable-wordpress-updates' ),
			__( 'Disable Updates', 'disable-wordpress-updates' ),
			'manage_options',
			self::PAGE_SLUG,
			[$this, 'render_settings_page']
		);
	}

	/**
	 * Register the setting, section and field via the Settings API.
	 *
	 * Nonce and capability checks on save are handled by the API itself:
	 * settings_fields() emits the nonce, and options.php enforces both the
	 * nonce (check_admin_referer) and the manage_options capability.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type' => 'boolean',
				'sanitize_callback' => [$this, 'sanitize_security_mode'],
				'default' => false,
			]
		);

		add_settings_section(
			'osdwp_security_mode_section',
			__( 'Security Mode', 'disable-wordpress-updates' ),
			[$this, 'render_section_intro'],
			self::PAGE_SLUG
		);

		add_settings_field(
			'osdwp_security_mode_field',
			__( 'Status', 'disable-wordpress-updates' ),
			[$this, 'render_security_mode_field'],
			self::PAGE_SLUG,
			'osdwp_security_mode_section'
		);
	}

	/**
	 * Sanitize the toggle value to a strict boolean.
	 *
	 * options.php passes null when the checkbox is unchecked, so this correctly
	 * stores false when the admin turns Security Mode off — no persistence
	 * tricks beyond the stored option.
	 *
	 * @param mixed $value Raw value submitted for the option (or null).
	 * @return bool
	 */
	public function sanitize_security_mode( $value ) {
		return (bool) $value;
	}

	/**
	 * Render the section description.
	 */
	public function render_section_intro() {
		?>
		<p>
			<?php
			esc_html_e( 'Security Mode restricts WordPress core automatic updates to minor and security releases. Major version and development (nightly) core updates are blocked from being applied automatically.', 'disable-wordpress-updates' );
			?>
		</p>
		<p class="description">
			<?php
			esc_html_e( 'Implemented with WordPress filters only — no wp-config.php edits. Plugin and theme auto-updates are not affected by this setting.', 'disable-wordpress-updates' );
			?>
		</p>
		<p class="description">
			<?php
			esc_html_e( 'Note: this setting controls which core releases are eligible for automatic updates. If the automatic updater is disabled site-wide (for example via the AUTOMATIC_UPDATER_DISABLED constant/filter, or by this plugin\'s own update-blocking features), that takes precedence and no core update will be applied automatically regardless of this toggle.', 'disable-wordpress-updates' );
			?>
		</p>
		<?php
	}

	/**
	 * Render the toggle checkbox field.
	 */
	public function render_security_mode_field() {
		$enabled = $this->is_enabled();
		?>
		<label for="osdwp_security_mode">
			<input
				type="checkbox"
				id="osdwp_security_mode"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Enable security updates only (block major updates)', 'disable-wordpress-updates' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Disable Updates', 'disable-wordpress-updates' ); ?></h1>

			<?php $this->render_constant_warning(); ?>

			<form action="options.php" method="post">
				<?php
				// settings_fields() emits the nonce + option_page hidden fields;
				// options.php verifies the nonce and the capability on save.
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a warning notice when WP_AUTO_UPDATE_CORE is defined by something
	 * other than this plugin, as that constant overrides the filters and the
	 * toggle may have no effect.
	 */
	public function render_constant_warning() {
		if ( ! $this->auto_update_core_defined_externally ) {
			return;
		}

		$value_label = $this->describe_auto_update_core_value( $this->auto_update_core_external_value );
		?>
		<div class="notice notice-warning inline">
			<p>
				<strong><?php esc_html_e( 'WP_AUTO_UPDATE_CORE is defined elsewhere', 'disable-wordpress-updates' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: WP_AUTO_UPDATE_CORE constant name wrapped in a <code> tag. */
					esc_html__( 'The %s constant is defined outside of this plugin (for example in wp-config.php, a host panel, an mu-plugin or another plugin) with the following value:', 'disable-wordpress-updates' ),
					'<code>WP_AUTO_UPDATE_CORE</code>'
				);
				?>
			</p>
			<p><code><?php echo esc_html( $value_label ); ?></code></p>
			<p>
				<?php esc_html_e( 'This constant always overrides this plugin\'s filters, so the Security Mode toggle may have no effect. To rely on Security Mode, remove or adjust that constant definition.', 'disable-wordpress-updates' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Produce a human-readable label for a WP_AUTO_UPDATE_CORE value.
	 *
	 * @param mixed $value Constant value (true|false|'minor'|other).
	 * @return string Safe for HTML output.
	 */
	protected function describe_auto_update_core_value( $value ) {
		if ( true === $value ) {
			return 'true (' . __( 'all core updates', 'disable-wordpress-updates' ) . ')';
		}
		if ( false === $value ) {
			return 'false (' . __( 'no core updates', 'disable-wordpress-updates' ) . ')';
		}
		if ( 'minor' === $value ) {
			return "'minor' (" . __( 'minor / security only', 'disable-wordpress-updates' ) . ')';
		}

		return var_export( $value, true );
	}

	/**
	 * Optional cosmetic annotation: when Security Mode is enabled, show a note on
	 * the WordPress Updates screen so an admin understands why major core updates
	 * are not being offered/applied automatically.
	 */
	public function maybe_annotate_updates_screen() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'update-core' !== $screen->id ) {
			return;
		}
		?>
		<div class="notice notice-info">
			<p>
				<?php
				esc_html_e( 'Security Mode is enabled: WordPress core automatic updates are restricted to minor and security releases. Major version updates are intentionally blocked.', 'disable-wordpress-updates' );
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Recolor the admin-bar notice icon from red to orange while Security Mode
	 * is active, so admins can tell at a glance that core updates are being
	 * restricted to security releases rather than fully disabled.
	 *
	 * The base plugin registers the red background via wp_add_inline_style on the
	 * 'admin-bar' handle at the default priority (10). This override runs later
	 * (priority 20) and uses a higher-specificity selector (#wpadminbar …) so the
	 * orange rule reliably wins regardless of source order.
	 */
	public function admin_bar_color_override() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar .wp-admin-bar-dwuos-notice { background-color: rgba(255, 140, 0, 0.5) !important; }'
		);
	}
}
