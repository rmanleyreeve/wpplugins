<?php
/**
 * Plugin Name: WooCommerce Customer Email Exporter
 * Description: Allows you to export all customer emails from WooCommerce into a CSV file.
 * Version: 1.2
 * Plugin URI: http://www.re-media.biz/plugins/
 * Author: RE Media
 * Author URI: http://re-media.biz/
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * ----------------------------------------------------------------------
 * Copyright (C) 2018  RE MEDIA richard@re-media.biz
 * ----------------------------------------------------------------------
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * ----------------------------------------------------------------------
 */
global $_slug;
$_slug = 'woocommerce-customer-email-exporter';

// Include WP core file
if (!function_exists('get_plugins')) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
// Check plugin active status
if (is_plugin_active('woocommerce/woocommerce.php')) {

	//delete_transient("remedia_upgrade_{$_slug}"); // FOR TESTING

	// ----------------------------------------------------------------------
	add_filter('plugins_api', 'this_plugin_info', 20, 3);
	function this_plugin_info( $res, $action, $args ){
		global $_slug;
		if( $action !== 'plugin_information' ) {
			return false;
		}
		if( $_slug !== $args->slug ) {
			return $res;
		}
		$remote = wp_remote_get("https://re-media.biz/plugins/info.json", array(
			'timeout' => 10, 'headers' => array('Accept' => 'application/json') 
			)
		);
		if (!is_wp_error($remote) && isset($remote['response']['code']) && $remote['response']['code'] == 200 && !empty($remote['body'])) {
			set_transient("remedia_upgrade_{$_slug}",$remote,43200); // 12 hours cache
		}
		if($remote) {
			$remote = json_decode( $remote['body'] );
			$res = new stdClass();
			$res->name = $remote->name;
			$res->slug = $_slug;
			$res->version = $remote->version;
			$res->tested = $remote->tested;
			$res->requires = $remote->requires;
			$res->author = '<a href="https://re-media.biz">RE Media</a>';
			$res->download_link = $remote->download_url;
			$res->trunk = $remote->download_url;
			$res->last_updated = $remote->last_updated;
			$res->sections = array(
				'description' => $remote->sections->description,
				'installation' => $remote->sections->installation,
				'changelog' => $remote->sections->changelog,
				// you can add your custom sections (tabs) here 
			);
			if( !empty( $remote->sections->screenshots ) ) {
				$res->sections['screenshots'] = $remote->sections->screenshots;
			}
			return $res;
		}
		return false;
	}
	add_filter('site_transient_update_plugins', 'this_push_update' );
	function this_push_update($transient){
		global $_slug;
		if (empty($transient->checked)) {
			return $transient;
		}
		if( false == $remote = get_transient("remedia_upgrade_{$_slug}") ) {
			// info.json is the file with the actual plugin information on your server
			$remote = wp_remote_get("https://re-media.biz/plugins/info.json", array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json'
				) )
			);
			if ( !is_wp_error( $remote ) && isset( $remote['response']['code'] ) && $remote['response']['code'] == 200 && !empty( $remote['body'] ) ) {
				set_transient("remedia_upgrade_{$_slug}",$remote,43200); // 12 hours cache
			}
		}
		$pd = get_plugin_data(__FILE__,false,false);
		$v = $pd['Version'];
		if( $remote ) {
			$remote = json_decode( $remote['body'] );
			if( $remote && version_compare( $v, $remote->version, '<' ) && version_compare($remote->requires, get_bloginfo('version'), '<' ) ) {
				$res = new stdClass();
				$res->slug = $_slug;
				$res->plugin = "{$_slug}/{$_slug}.php"; 
				$res->new_version = $remote->version;
				$res->tested = $remote->tested;
				$res->package = $remote->download_url;
				$res->url = $remote->homepage;
				$transient->response[$res->plugin] = $res;
				$transient->checked[$res->plugin] = $remote->version;
			}
		}
		return $transient;
	}
	// clear cache after update
	add_action( 'upgrader_process_complete', 'this_after_update', 10, 2 );
	function this_after_update( $upgrader_object, $options ) {
		global $_slug;
		if ( $options['action'] == 'update' && $options['type'] === 'plugin' )  {
			delete_transient("remedia_upgrade_{$_slug}");
		}
	}
	// add View Details link
	function plugin_links( $links, $plugin_file, $plugin_data ) {
		global $_slug;
		if ($plugin_file == "{$_slug}/{$_slug}.php" && !isset($plugin_data['slug']) && current_user_can('install_plugins') ) {
			$links[] = sprintf(
				'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
				esc_url( network_admin_url( 'plugin-install.php?tab=plugin-information&amp;plugin=' . $_slug . '&amp;TB_iframe=true&amp;width=600&amp;height=550' )),
				esc_attr( sprintf( __( 'More information about %s' ), $plugin_data['Name'] ) ),
        esc_attr( $plugin_data['Name'] ),
				__( 'View details' )
			);
		}
		return $links;
	}
	if ( is_admin() ) {
		add_filter( 'plugin_row_meta','plugin_links', 10, 4 );
	}
	// ----------------------------------------------------------------------

	class WC_Customer_Email_Exporter {
		public function __construct() {
			add_action( 'init', array( $this, 'generate_csv' ) );
		}
	
		private function create_csv_data($headers = true, $cnames = true, $nodupes = true) {
			global $wpdb;
			$emails = array();
			$names = array();
			$data = array();
			if($headers) {
				$data[0] = ($cnames) ? array('Customer_Email','Customer_Name') : array('Customer_Email');
			}
			$ids = $wpdb->get_col( "SELECT DISTINCT `order_id` FROM `{$wpdb->prefix}woocommerce_order_items`" );
			foreach ($ids as $id) {
				$first_name = get_post_meta($id,'_billing_first_name',true);
				$last_name = get_post_meta($id,'_billing_last_name',true);
				$email = get_post_meta($id,'_billing_email',true);
				if($email) {
					$data[] = ($cnames) ? array($email, ucwords(strtolower("$first_name $last_name"))) : array($email);
				}
			}
			if($nodupes) {
				$data = array_map('unserialize',array_unique(array_map('serialize',$data)));
			}
			return $data;
		}
	
		public function generate_csv() {
		
			if (isset($_POST['_wpnonce-customer-email-exporter'])) {
				$headers = ($_POST['headers']!='no');
				$cnames = ($_POST['cnames']!='no');
				$nodupes = ($_POST['nodupes']!='no');
				check_admin_referer( 'customer-email-exporter', '_wpnonce-customer-email-exporter' );
				$sitename = sanitize_key(get_bloginfo('name'));
				if (!empty($sitename)) {
					$sitename .= '.';
				}
				$filename = $sitename . date( 'YmdHis', current_time('timestamp')) . '.csv';
				$data = $this->create_csv_data($headers, $cnames, $nodupes);
				//print_r($data); exit;
				$this->csv_header( $filename );
				ob_start();
				$file = @fopen( 'php://output', 'w' );
				@fwrite($file,"\xEF\xBB\xBF"); // UTF8 BOM
				foreach($data as $list) {
					@fputcsv($file,$list,',');
				}
				@fclose( $file );
				ob_end_flush();
				exit();
			}
		}
	
		private function csv_header($filename) {
			send_nosniff_header();
			nocache_headers();
			@header( 'Content-Type: application/csv; charset=' . get_option( 'blog_charset' ), true );
			@header( 'Content-Type: application/force-download' );
			@header( 'Content-Description: File Transfer' );
			@header( 'Content-Disposition: attachment; filename=' . $filename );
		}

		public function get_wc_version() {
			global $woocommerce;
			$plugin_folder = get_plugins( '/' . 'woocommerce' );
			$plugin_file = 'woocommerce.php';
			$wc_version = $plugin_folder[$plugin_file]['Version'];
			return isset($wc_version) ? $wc_version : $woocommerce->version;
		}
	
	} // end class

	$export = new WC_Customer_Email_Exporter;

	function export_action() {
		if(isset($_GET['error'])) {
			echo '<div class="updated"><p><strong>No emails found.</strong></p></div>';
		}
		?>
		<form method="post" action="" enctype="multipart/form-data">
			<?php wp_nonce_field( 'customer-email-exporter', '_wpnonce-customer-email-exporter' ); ?>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row" class="titledesc"><label for="headers">Include header row?</label></th>
						<td>
							<label><input name="headers" value="yes" type="radio" checked="checked"> Yes</label>&nbsp;&nbsp;&nbsp;
							<label><input name="headers" value="no" type="radio"> No</label>
						</td>
					</tr>
					<tr>
						<th scope="row" class="titledesc"><label for="cnames">Include customer name?</label></th>
						<td>
							<label><input name="cnames" value="yes" type="radio" checked="checked"> Yes</label>&nbsp;&nbsp;&nbsp;
							<label><input name="cnames" value="no" type="radio"> No</label>
						</td>
					</tr>
					<tr>
						<th scope="row" class="titledesc"><label for="nodupes">Remove duplicate emails?</label></th>
						<td>
							<label><input name="nodupes" value="yes" type="radio"> Yes</label>&nbsp;&nbsp;&nbsp;
							<label><input name="nodupes" value="no" type="radio" checked="checked"> No</label>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<input type="hidden" name="_wp_http_referer" value="<?php echo $_SERVER['REQUEST_URI'] ?>" />
				<input type="submit" class="button-primary" value="Export CSV" />
			</p>
		</form>
	<?php
	}

	if(version_compare($export->get_wc_version(),'2.1','lt')) {
		// woocommerce version below 2.1
		function wc_version_error_notice() {
			global $current_screen;
			if ( $current_screen->parent_base == 'plugins' ) {
				echo '
				<div class="error">
				<p>The <strong>WooCommerce Customer Email Exporter</strong> plugin requires WooCommerce version 2.1 or greater in order to work. Please update WooCommerce first.</p>
				</div>
				';
			}
		}
		add_action( 'admin_notices', 'wc_version_error_notice' );
		deactivate_plugins( plugin_basename( __FILE__ ) );
	} else {
		// woocommerce version 2.1 or above
		function export_to_csv( $reports ) {
			$reports['customers']['reports']['export'] = array(
				'title'       => 'Export Customer Emails',
				'description' => 'Click on the <strong>Export CSV</strong> button to generate and download your customer emails as a CSV file.',
				'hide_title'  => true,
				'callback'    => 'export_action'
			);
			return $reports;
		}
		add_filter( 'woocommerce_admin_reports', 'export_to_csv' );
	}

} else {
	//Fallback admin notice
	function wc_error_notice() {
		global $current_screen;
		if ( $current_screen->parent_base == 'plugins' ) {
			echo '<div class="error"><p>The <strong>WooCommerce Customer Email Exporter</strong> plugin requires the <a href="http://wordpress.org/plugins/woocommerce" target="_blank">WooCommerce</a> plugin to be activated in order to work. Please <a href="'.admin_url( 'plugin-install.php?tab=search&type=term&s=WooCommerce' ).'" target="_blank">install WooCommerce</a> or <a href="'.admin_url( 'plugins.php' ).'">activate</a> first.</p></div>';
		}
	}
	add_action( 'admin_notices', 'wc_error_notice' );
}
