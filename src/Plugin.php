<?php
/**
 * @package Nera\AllInOne
 */

namespace Nera\AllInOne;

use Nera\AllInOne\Entitlements\Entitlements;
use Nera\AllInOne\License\License_Client;
use Nera\AllInOne\License\License_Store;
use Nera\AllInOne\Modules\Module_Registry;
use Nera\AllInOne\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Container. Wires the engine (license → entitlements → registry), registers
 * feature modules, and boots the admin surface. Module registration + wiring is
 * intentionally centralised here (the integration point).
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var License_Client */
	public $license;

	/** @var Entitlements */
	public $entitlements;

	/** @var Module_Registry */
	public $registry;

	/** @var bool */
	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Engine. These classes land across Wave B; guard so partial deploys
		// during development never fatal the site.
		if ( ! class_exists( License_Store::class ) || ! class_exists( License_Client::class )
			|| ! class_exists( Entitlements::class ) || ! class_exists( Module_Registry::class ) ) {
			Logger::log( 'engine classes missing; booting inert' );
			return;
		}

		$store              = new License_Store();
		$this->license      = new License_Client( $store );
		$this->entitlements = new Entitlements( $this->license );
		$this->registry     = new Module_Registry( $this->entitlements );

		$this->register_modules();

		// Boot modules after dependency plugins (WC @ default, age-gate legacy @20).
		add_action( 'plugins_loaded', array( $this->registry, 'boot_all' ), 30 );

		if ( is_admin() ) {
			$this->boot_admin();
		}
	}

	/**
	 * Register all feature modules with the registry. Each registration is
	 * guarded so a not-yet-delivered module doesn't fatal the engine.
	 */
	private function register_modules() {
		// Pro demo feature (first-party, PSR-4 autoloaded).
		$demo_pro = '\\Nera\\AllInOne\\DemoPro\\Module';
		if ( class_exists( $demo_pro ) ) {
			$this->registry->register( new $demo_pro() );
		}
	}

	/**
	 * Instantiate admin screens. Each takes ( License_Client, Entitlements,
	 * Module_Registry ) and exposes init().
	 */
	private function boot_admin() {
		$screens = array(
			'\\Nera\\AllInOne\\Admin\\Dashboard',
			'\\Nera\\AllInOne\\Admin\\Licensing',
			'\\Nera\\AllInOne\\Admin\\Modules_Screen',
			'\\Nera\\AllInOne\\Admin\\Module_Toggle',
			'\\Nera\\AllInOne\\Admin\\Install_Controller',
			'\\Nera\\AllInOne\\Admin\\Plugin_Details',
		);
		foreach ( $screens as $fqcn ) {
			if ( class_exists( $fqcn ) ) {
				$screen = new $fqcn( $this->license, $this->entitlements, $this->registry );
				if ( method_exists( $screen, 'init' ) ) {
					$screen->init();
				}
			}
		}
	}
}
