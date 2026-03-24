<?php
/**
 * Custom Roles — Camp Manager Role.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.78
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AS_CAI_Roles {

	/** @var AS_CAI_Roles|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Check if role needs to be installed/updated on admin_init.
		add_action( 'admin_init', array( $this, 'maybe_install_role' ) );

		// Patch Stachethemes menu caps so Camp Manager can access Seat Planner.
		add_action( 'admin_menu', array( $this, 'patch_stachethemes_menu_caps' ), 999 );

		// Grant manage_options dynamically for Stachethemes pages only.
		add_filter( 'user_has_cap', array( $this, 'grant_stachethemes_access' ), 10, 4 );
	}

	/**
	 * Install or update the Camp Manager role if needed.
	 */
	public function maybe_install_role() {
		$installed_version = get_option( 'as_cai_role_version', '' );
		if ( $installed_version !== AS_CAI_VERSION ) {
			$this->install();
			update_option( 'as_cai_role_version', AS_CAI_VERSION );
		}
	}

	/**
	 * Create or update the Camp Manager role.
	 */
	public function install() {
		$caps = self::get_camp_manager_caps();

		// Remove existing role first (to update caps).
		remove_role( 'camp_manager' );

		add_role( 'camp_manager', 'Camp Manager', $caps );
	}

	/**
	 * Get all capabilities for the Camp Manager role.
	 *
	 * @return array
	 */
	public static function get_camp_manager_caps() {
		return array(
			// WordPress Basis.
			'read'                   => true,
			'upload_files'           => true,
			'edit_posts'             => true,
			'edit_published_posts'   => true,
			'delete_posts'           => true,
			'publish_posts'          => true,

			// WooCommerce — Shop Management.
			'manage_woocommerce'          => true,
			'view_woocommerce_reports'    => true,

			// WooCommerce — Orders.
			'edit_shop_orders'            => true,
			'read_shop_orders'            => true,
			'delete_shop_orders'          => true,
			'edit_others_shop_orders'     => true,
			'publish_shop_orders'         => true,
			'edit_published_shop_orders'  => true,
			'delete_published_shop_orders' => true,

			// WooCommerce — Products.
			'edit_products'               => true,
			'read_products'               => true,
			'delete_products'             => true,
			'publish_products'            => true,
			'edit_others_products'        => true,
			'edit_published_products'     => true,
			'delete_published_products'   => true,

			// WooCommerce — Coupons.
			'edit_shop_coupons'           => true,
			'read_shop_coupons'           => true,
			'publish_shop_coupons'        => true,
			'edit_others_shop_coupons'    => true,
			'edit_published_shop_coupons' => true,
			'delete_shop_coupons'         => true,
			'delete_published_shop_coupons' => true,

			// WooCommerce — Customers.
			'list_users'                  => true,
			'edit_users'                  => false,

			// Stachethemes Seat Planner + Unser Plugin:
			// manage_woocommerce deckt beides ab.
		);
	}

	/**
	 * Patch Stachethemes Seat Planner menu capabilities.
	 *
	 * Stachethemes hardcodes 'manage_options' for its admin menu,
	 * but all AJAX handlers use 'manage_woocommerce'. We patch the
	 * menu globals so Camp Manager (with manage_woocommerce) can
	 * see the Seat Planner menu items.
	 *
	 * @since 1.3.79
	 */
	public function patch_stachethemes_menu_caps() {
		global $menu, $submenu;

		// Hauptmenü: Stachethemes Seat Planner.
		foreach ( $menu as $position => $item ) {
			if ( isset( $item[2] ) && 'stachesepl' === $item[2] ) {
				$menu[ $position ][1] = 'manage_woocommerce';
				break;
			}
		}

		// Submenüs.
		if ( isset( $submenu['stachesepl'] ) ) {
			foreach ( $submenu['stachesepl'] as $index => $item ) {
				$submenu['stachesepl'][ $index ][1] = 'manage_woocommerce';
			}
		}
	}

	/**
	 * Dynamically grant 'manage_options' to users with 'manage_woocommerce'
	 * when they are on a Stachethemes Seat Planner admin page or AJAX call.
	 *
	 * This is needed because Stachethemes checks manage_options in its
	 * page rendering and settings handler. We scope this tightly to
	 * Stachethemes context only — Camp Manager will NOT gain access to
	 * WordPress Settings, Permalinks, or other manage_options pages.
	 *
	 * @since 1.3.79
	 *
	 * @param array $allcaps All capabilities of the user.
	 * @param array $caps    Required capabilities for the check.
	 * @param array $args    Additional arguments (capability name, user ID, etc.).
	 * @param WP_User $user  The user object.
	 * @return array
	 */
	public function grant_stachethemes_access( $allcaps, $caps, $args, $user = null ) {
		// Only intervene when manage_options is being checked.
		if ( ! in_array( 'manage_options', $caps, true ) ) {
			return $allcaps;
		}

		// User already has manage_options — nothing to do.
		if ( ! empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}

		// Only grant for users with manage_woocommerce.
		if ( empty( $allcaps['manage_woocommerce'] ) ) {
			return $allcaps;
		}

		// Check if we're in a Stachethemes context.
		$is_stachethemes = false;

		// Admin page: ?page=stachesepl or subpages.
		if ( is_admin() && isset( $_GET['page'] ) && 'stachesepl' === $_GET['page'] ) {
			$is_stachethemes = true;
		}

		// AJAX calls from Stachethemes.
		if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && strpos( $_REQUEST['action'], 'stachesepl' ) === 0 ) {
			$is_stachethemes = true;
		}

		// Menu rendering: check if we're building the admin menu.
		if ( is_admin() && doing_action( 'admin_menu' ) ) {
			$is_stachethemes = true;
		}

		if ( $is_stachethemes ) {
			$allcaps['manage_options'] = true;
		}

		return $allcaps;
	}
}
