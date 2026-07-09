<?php
/**
 * Demo Pro feature module.
 *
 * Visible proof-of-concept that PRO-tier gating works end-to-end.
 * Renders a WP admin notice when the site is on the PRO (or higher)
 * tier and the registry boots this module.
 *
 * @package Nera\AllInOne\DemoPro
 */

namespace Nera\AllInOne\DemoPro;

use Nera\AllInOne\Modules\Abstract_Module;
use Nera\AllInOne\Entitlements\Tier;
use Nera\AllInOne\Support\Managers;

defined( 'ABSPATH' ) || exit;

/**
 * Module — Demo Pro Feature.
 *
 * Requires Tier::PRO. No external assets, no DB, no deps.
 */
final class Module extends Abstract_Module {

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	/**
	 * @return string
	 */
	public function id() {
		return 'demo-pro';
	}

	/**
	 * @return string
	 */
	public function label() {
		return 'Pro Demo Feature';
	}

	/**
	 * @return string
	 */
	public function min_tier() {
		return Tier::PRO;
	}

	// -------------------------------------------------------------------------
	// Behaviour
	// -------------------------------------------------------------------------

	/**
	 * Register the admin notice hook.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_notices', array( $this, 'render_admin_panel' ) );
	}

	/**
	 * Output a dismissible-looking WP admin notice confirming PRO status.
	 *
	 * @return void
	 */
	public function render_admin_panel() {
		// Internal proof-of-concept notice — Nera managers only.
		if ( ! Managers::is_manager() ) {
			return;
		}
		?>
		<div class="notice notice-success">
			<p>
				<?php
				printf(
					/* translators: 1: plugin name  2: module label  3: tier name */
					esc_html__( '%1$s — %2$s is active (tier: %3$s).', 'nera-all-in-one' ),
					'<strong>' . esc_html__( 'Nera All In One', 'nera-all-in-one' ) . '</strong>',
					esc_html( $this->label() ),
					'<code>' . esc_html( Tier::PRO ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
