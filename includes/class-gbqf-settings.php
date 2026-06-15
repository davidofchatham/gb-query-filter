<?php
namespace GBQF;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION_KEY     = 'gbqf_enable_metabox_integration';
	const OPTION_KEY_ACF = 'gbqf_enable_acf_integration';

	/**
	 * Register admin hooks. Call once from plugin bootstrap.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	// ---------------------------------------------------------------------------
	// Admin menu
	// ---------------------------------------------------------------------------

	/**
	 * Register submenu page under the GenerateBlocks top-level menu.
	 */
	public static function add_admin_menu() {
		add_submenu_page(
			'generateblocks',
			__( 'Query Filters', 'gb-query-filters' ),
			__( 'Query Filters', 'gb-query-filters' ),
			'manage_options',
			'gb-query-filter',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	// ---------------------------------------------------------------------------
	// Settings API registration
	// ---------------------------------------------------------------------------

	/**
	 * Register all settings, sections, and fields.
	 */
	public static function register_settings() {
		// ---- General section ------------------------------------------------
		add_settings_section(
			'gbqf_general',
			__( 'General', 'gb-query-filters' ),
			'__return_false',
			'gb-query-filter'
		);

		register_setting( 'gb-query-filter', 'gbqf_filter_priority', [
			'sanitize_callback' => [ __CLASS__, 'sanitize_priority' ],
			'default'           => 20,
		] );
		add_settings_field(
			'gbqf_filter_priority',
			__( 'Hook Priority', 'gb-query-filters' ),
			[ __CLASS__, 'render_field_priority' ],
			'gb-query-filter',
			'gbqf_general'
		);

		register_setting( 'gb-query-filter', 'gbqf_preserve_search', [
			'sanitize_callback' => [ __CLASS__, 'sanitize_bool' ],
			'default'           => '0',
		] );
		add_settings_field(
			'gbqf_preserve_search',
			__( 'Merge Search Terms', 'gb-query-filters' ),
			[ __CLASS__, 'render_field_preserve_search' ],
			'gb-query-filter',
			'gbqf_general'
		);

		register_setting( 'gb-query-filter', 'gbqf_enable_debug_logging', [
			'sanitize_callback' => [ __CLASS__, 'sanitize_bool' ],
			'default'           => '0',
		] );
		add_settings_field(
			'gbqf_enable_debug_logging',
			__( 'Debug Logging', 'gb-query-filters' ),
			[ __CLASS__, 'render_field_debug_logging' ],
			'gb-query-filter',
			'gbqf_general'
		);

		// ---- Integrations section (only if at least one is available) -------
		$metabox_active = class_exists( 'RWMB_Loader' );
		$acf_active     = function_exists( 'acf_get_field_groups' );

		if ( $metabox_active || $acf_active ) {
			add_settings_section(
				'gbqf_integrations',
				__( 'Integrations', 'gb-query-filters' ),
				[ __CLASS__, 'render_integrations_intro' ],
				'gb-query-filter'
			);
		}

		if ( $metabox_active ) {
			register_setting( 'gb-query-filter', self::OPTION_KEY, [
				'sanitize_callback' => [ __CLASS__, 'sanitize_bool' ],
				'default'           => '1',
			] );
			add_settings_field(
				self::OPTION_KEY,
				__( 'Meta Box Integration', 'gb-query-filters' ),
				[ __CLASS__, 'render_field_metabox' ],
				'gb-query-filter',
				'gbqf_integrations'
			);
		}

		if ( $acf_active ) {
			register_setting( 'gb-query-filter', self::OPTION_KEY_ACF, [
				'sanitize_callback' => [ __CLASS__, 'sanitize_bool' ],
				'default'           => '1',
			] );
			add_settings_field(
				self::OPTION_KEY_ACF,
				__( 'ACF Integration', 'gb-query-filters' ),
				[ __CLASS__, 'render_field_acf' ],
				'gb-query-filter',
				'gbqf_integrations'
			);
		}
	}

	// ---------------------------------------------------------------------------
	// Field renderers
	// ---------------------------------------------------------------------------

	public static function render_field_priority() {
		$value = absint( get_option( 'gbqf_filter_priority', 20 ) );
		printf(
			'<input type="number" id="gbqf_filter_priority" name="gbqf_filter_priority" value="%d" min="1" class="small-text" />
			<p class="description">%s</p>',
			$value,
			esc_html__( 'Controls when GBQF runs relative to other plugins that modify Query Loop queries. Higher numbers run later. Default: 20.', 'gb-query-filters' )
		);
	}

	public static function render_field_preserve_search() {
		$checked = (bool) get_option( 'gbqf_preserve_search', false );
		printf(
			'<label><input type="checkbox" id="gbqf_preserve_search" name="gbqf_preserve_search" value="1" %s /> %s</label>
			<p class="description">%s</p>',
			checked( $checked, true, false ),
			esc_html__( 'Enable', 'gb-query-filters' ),
			esc_html__( 'Combine GBQF search with search terms applied by other plugins, instead of replacing them.', 'gb-query-filters' )
		);
	}

	public static function render_field_debug_logging() {
		$checked = (bool) get_option( 'gbqf_enable_debug_logging', false );
		printf(
			'<label><input type="checkbox" id="gbqf_enable_debug_logging" name="gbqf_enable_debug_logging" value="1" %s /> %s</label>
			<p class="description">%s</p>',
			checked( $checked, true, false ),
			esc_html__( 'Enable', 'gb-query-filters' ),
			esc_html__( 'Log query arguments before and after GBQF modifications to the PHP error log, and enable browser console output on the frontend.', 'gb-query-filters' )
		);
	}

	public static function render_integrations_intro() {
		echo '<p>' . esc_html__( 'Enable or disable GBQF integration with third-party field plugins.', 'gb-query-filters' ) . '</p>';
	}

	public static function render_field_metabox() {
		$checked = self::is_metabox_enabled();
		printf(
			'<label><input type="checkbox" id="%s" name="%s" value="1" %s /> %s</label>',
			esc_attr( self::OPTION_KEY ),
			esc_attr( self::OPTION_KEY ),
			checked( $checked, true, false ),
			esc_html__( 'Enable Meta Box field filtering', 'gb-query-filters' )
		);
	}

	public static function render_field_acf() {
		$checked = self::is_acf_enabled();
		printf(
			'<label><input type="checkbox" id="%s" name="%s" value="1" %s /> %s</label>',
			esc_attr( self::OPTION_KEY_ACF ),
			esc_attr( self::OPTION_KEY_ACF ),
			checked( $checked, true, false ),
			esc_html__( 'Enable ACF field filtering', 'gb-query-filters' )
		);
	}

	// ---------------------------------------------------------------------------
	// Settings page renderer
	// ---------------------------------------------------------------------------

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'gb-query-filter' );
				do_settings_sections( 'gb-query-filter' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Sanitize callbacks
	// ---------------------------------------------------------------------------

	/**
	 * Sanitize a hook priority: positive integer, minimum 1.
	 *
	 * @param mixed $value
	 * @return int
	 */
	public static function sanitize_priority( $value ) {
		$int = absint( $value );
		return max( 1, $int );
	}

	/**
	 * Sanitize a boolean option stored as '1' / '0'.
	 *
	 * @param mixed $value
	 * @return string '1' or '0'
	 */
	public static function sanitize_bool( $value ) {
		return $value ? '1' : '0';
	}

	// ---------------------------------------------------------------------------
	// Static accessors
	// ---------------------------------------------------------------------------

	/**
	 * Check if Meta Box integration is enabled.
	 *
	 * @return bool
	 */
	public static function is_metabox_enabled() {
		return (bool) get_option( self::OPTION_KEY, '1' );
	}

	/**
	 * Check if ACF integration is enabled.
	 *
	 * @return bool
	 */
	public static function is_acf_enabled() {
		return (bool) get_option( self::OPTION_KEY_ACF, '1' );
	}

	/**
	 * Check if debug logging is enabled.
	 *
	 * @return bool
	 */
	public static function is_debug_enabled() {
		$enabled = (bool) get_option( 'gbqf_enable_debug_logging', false );
		return (bool) apply_filters( 'gbqf_enable_debug_logging', $enabled );
	}

	/**
	 * Get filter priority setting.
	 * Default: 20 (runs after most query-modifying plugins).
	 *
	 * @return int
	 */
	public static function get_filter_priority() {
		$priority = (int) get_option( 'gbqf_filter_priority', 20 );
		return (int) apply_filters( 'gbqf_filter_priority', $priority );
	}

	/**
	 * Get filter scope setting.
	 * Default: 'targeted' (only filters Query Loops with matching anchors).
	 * Not exposed in the admin UI — use gbqf_filter_scope filter to override.
	 *
	 * @return string 'all' or 'targeted'
	 */
	public static function get_filter_scope() {
		$scope = get_option( 'gbqf_filter_scope', 'targeted' );
		return apply_filters( 'gbqf_filter_scope', $scope );
	}

	/**
	 * Check if search term preservation is enabled.
	 * Default: false (replaces existing search terms).
	 *
	 * @return bool
	 */
	public static function should_preserve_search() {
		$preserve = (bool) get_option( 'gbqf_preserve_search', false );
		return (bool) apply_filters( 'gbqf_preserve_search', $preserve );
	}
}
