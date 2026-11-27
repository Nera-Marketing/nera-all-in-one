=== Nera All In One ===
Contributors: nera
Tags: nera, licensing, plugin manager, competitions
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.3
License: Proprietary

Unified manager for Nera internal plugins + licensing. Loads feature modules gated by license tier.

== Description ==

**Nera All In One** is the hub for installing, updating, and managing Nera internal plugins, with license-tier gating for feature modules.

* Catalog install/update of Nera plugins from GitHub releases
* License / entitlement management
* Admin dashboard for managers

== Installation ==

1. Upload and activate **Nera All In One**.
2. Open the Nera All In One admin screens to manage catalog plugins and licensing.

== Changelog ==

= 1.0.3 =
* Added the Credit Page plugin to the catalog.
* Plugin details popup: an "Edit page" button for plugins with a hidden editor, shown while the plugin is active.

= 1.0.2 =
* Added a member-voting plugin to the catalog.

= 1.0.1 =
* Added the plugin logo and release tooling updates.

= 1.0.0 =
* "Check for updates" button on the Manage screen, plus a twice-daily automatic check.
* The Plugins list now hides only the plugins this site manages.
* Added a skill-quiz plugin to the catalog.

= 0.1.2 =
* Detect installed plugins under renamed/non-canonical folders (match by main file or Plugin Name).
* Harden inactive-installed detection: filesystem fallback + plugins cache refresh on Manage/Overview.
* Install short-circuit when the plugin folder already exists — activate only, no re-download.
* Toggle/remove/status use the resolved plugin file so Off→On works for renamed installs.

= 0.1.1 =
* Always show Nera All In One on the WordPress Plugins page (matched by real install path).
* Allow normal Deactivate/Delete of AIO from Plugins; keep other nera-* plugins hidden.
* Install short-circuit: activate already-installed catalog plugins instead of re-downloading.
* Overview/Manage status labels: Active / Inactive for installed plugins.

= 0.1.0 =
* Initial release tooling: GitHub Plugin Update Checker, release zip workflow.
