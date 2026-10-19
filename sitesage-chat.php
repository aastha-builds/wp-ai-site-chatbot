<?php
/**
 * Plugin Name:       SiteSage Chat
 * Plugin URI:        https://github.com/aastha-builds/wp-ai-site-chatbot
 * Description:       An AI chatbot that answers visitor questions from your own pages, FAQs and PDFs. Works with OpenAI or Anthropic Claude, and runs in demo mode without any API key.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            aastha-builds
 * Author URI:        https://github.com/aastha-builds
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       sitesage-chat
 * Domain Path:       /languages
 *
 * @package SiteSage
 */

defined( 'ABSPATH' ) || exit;

define( 'SITESAGE_VERSION', '1.0.0' );
define( 'SITESAGE_DB_VERSION', '1' );
define( 'SITESAGE_PLUGIN_FILE', __FILE__ );
define( 'SITESAGE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SITESAGE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SITESAGE_PLUGIN_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'SiteSage\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SiteSage\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SiteSage\\Plugin', 'instance' ) );
