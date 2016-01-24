<?php
/*
Plugin Name: GP Auto Extract
Plugin URI: http://glot-o-matic.com/gp-auto-extract
Description: Automatically extract source strings from a remote repo.
Version: 0.5
Author: gregross
Author URI: http://toolstack.com
Tags: glotpress, glotpress plugin, translate 
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

class GP_Auto_Extract {
	public $id = 'gp-auot-extract';

	public function __construct() {
		// Add the admin page to the WordPress settings menu.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 10, 1 );

	}
	
	// This function adds the admin settings page to WordPress.
	public function admin_menu() {
		add_options_page( __('GP Auto Extract'), __('GP Auto Extract'), 'manage_options', basename( __FILE__ ), array( $this, 'admin_page' ) );
	}
	
	// This function displays the admin settings page in WordPress.
	public function admin_page() {
		// If the current user can't manage options, display a message and return immediately.
		if( ! current_user_can( 'manage_options' ) ) { _e('You do not have permissions to this page!'); return; }
		
		// If the user has saved the settings, commit them to the database.
		if( array_key_exists( 'save_gp_auto_extract', $_POST ) ) {
			include( dirname( __FILE__ ) . '/include/extract/makepot.php' );
			
			$output = 'c:/temp/output.po';
			$dir = 'c:/users/greg/Source Trees/Just Writing/trunk';
			$makepot = new MakePOT;
			
			$makepot->generic( $dir, $output );
		}

	?>	
<div class="wrap">
	<h2><?php _e('GP Auto Extract Settings');?></h2>

	<form method="post" action="options-general.php?page=gp-auto-extract.php" >	
		
		<?php submit_button( __('Extract!'), 'primary', 'save_gp_auto_extract' ); ?>
		
	</form>
	
</div>
<?php		
	}
}

// Add an action to WordPress's init hook to setup the plugin.  Don't just setup the plugin here as the GlotPress plugin may not have loaded yet.
add_action( 'gp_init', 'gp_auto_extract_init' );

// This function creates the plugin.
function gp_auto_extract_init() {
	GLOBAL $gp_auto_extract;
	
	$gp_auto_extract = new GP_Auto_Extract;
}
