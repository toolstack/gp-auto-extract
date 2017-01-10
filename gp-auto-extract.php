<?php
/*
Plugin Name: GP Auto Extract
Plugin URI: http://glot-o-matic.com/gp-auto-extract
Description: Automatically extract source strings from a remote repo.
Version: 0.6
Author: Greg Ross
Author URI: http://toolstack.com
Tags: glotpress, glotpress plugin, translate 
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

class GP_Auto_Extract {
	public $id = 'gp-auot-extract';

	private	$source_types;
	private $source_type_templates;

	public function __construct() {
		$this->source_types = array( 'none' => __( 'none' ), 'github' => __( 'GitHub' ), 'wordpress' => __( 'WordPress.org' ), 'custom' => __( 'Custom' ) );
		$this->source_type_templates = array( 
											'none' 		=> '',
											'github' 	=> 'https://github.com/%s/archive/master.zip',
											'wordpress' => 'https://downloads.wordpress.org/plugin/%s.zip',
											'custom' 	=> '%s',
										);

		// Add the admin page to the WordPress settings menu.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 10, 1 );
		
		// If the user has write permissions to the projects, add the auto extract option to the projects menu.
		if( GP::$permission->user_can( wp_get_current_user(), 'write', 'project' ) ) {
			add_action( 'gp_project_actions', array( $this, 'gp_project_actions'), 10, 2 );
		}

		// We can't use the filter in the defaults route code because plugins don't load until after
		// it has already run, so instead add the routes directly to the global GP_Router object.
		GP::$router->add( "/auto-extract/(.+?)", array( $this, 'auto_extract' ), 'get' );
		GP::$router->add( "/auto-extract/(.+?)", array( $this, 'auto_extract' ), 'post' );
	}
	
	// This function is here as placeholder to support adding the auto extract option to the router.
	// Without this placeholder there is a fatal error generated.
	public function before_request() {
	}

	// This function handles the actual auto extract passed in by the router for the projects menu.
	public function auto_extract( $project_path ) {
		// First let's ensure we have decoded the project path for use later.
		$project_path = urldecode( $project_path );
		
		// Get the URL to the project for use later.
		$url = gp_url_project( $project_path );

		// Create a project class to use to get the project object.
		$project_class = new GP_Project;
		
		// Get the project object from the project path that was passed in.
		$project_obj = $project_class->by_path( $project_path );

		if( GP::$permission->user_can( wp_get_current_user(), 'write', 'project', $project->id ) ) {
			// Get the project settings.
			$project_settings = (array)get_option( 'gp_auto_extract', array() );

			// Since we're running on the front end we need to load the download_url() function from the wp-admin/includes directory.
			include( ABSPATH . 'wp-admin/includes/file.php' );

			// Extract the strings, the third parameter disables HTML formating of the returned messages as GP doesn't need them.
			$message = $this->extract_project( $project_obj, $project_settings, false );
		} else {
			$message = 'You do not have rights to auto extract originals!';
		}

		gp_notice_set( $message );
		
		// Redirect back to the project home.
		wp_redirect( $url );
	}
	
	// This function is here as placeholder to support adding the auto extract option to the router.
	// Without this placeholder there is a fatal error generated.
	public function after_request() {
	}

	// This function adds the "Auto Extract" option to the projects menu.
	public function gp_project_actions( $actions, $project ) {
		$project_settings = (array)get_option( 'gp_auto_extract', array() );
		
		if( 'none' != $project_settings[ $project->id ][ 'type' ] ) {
			$actions[] .= gp_link_get( gp_url( 'auto-extract/' . $project->slug), __('Auto Extract') );
		}
		
		return $actions;
	}
	
	private function delTree( $dir ) {
		if( ! gp_startswith( $dir, sys_get_temp_dir() ) ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		
		foreach ( $files as $file ) {
			if( is_dir( "$dir/$file" ) ) {
				$this->delTree("$dir/$file");
			} else {
				unlink("$dir/$file");
			}
		}
    
		return rmdir( $dir );
	} 
	
	// This function adds the admin settings page to WordPress.
	public function admin_menu() {
		add_options_page( __('GP Auto Extract'), __('GP Auto Extract'), 'manage_options', basename( __FILE__ ), array( $this, 'admin_page' ) );
	}

	private function extract_project( $project, $project_settings, $format_message = true ) {
		$url_name = sprintf( $this->source_type_templates[ $project_settings[ $project->id ][ 'type' ] ], $project_settings[ $project->id ][ 'setting' ] );

		$source_file = download_url( $url_name );

		if( ! is_wp_error( $source_file ) ) {
			
			include( dirname( __FILE__ ) . '/include/extract/makepot.php' );

			// Get a temporary file, use gpa as the first four letters of it.
			$temp_dir = tempnam( sys_get_temp_dir(), 'gpa');
			$temp_pot = tempnam( sys_get_temp_dir(), 'gpa');
			
			// Now delete the file and recreate it as a directory.
			unlink( $temp_dir );
			mkdir( $temp_dir );

			$zip = new ZipArchive;
			if ( $zip->open( $source_file ) === TRUE ) {
				$zip->extractTo( $temp_dir );
				$zip->close();
				
				unlink( $source_file );
			} else {
				unlink( $source_file );
				
				return '<div class="notice updated"><p>' . sprintf( __('Failed to extract zip file: "%s".' ), $source_file ) . '</p></div>';
			}

			$src_dir = $temp_dir;
			
			// Check to see if there is only a single directory in the resulting zip extract root directory, if so, make it the root of the makepot call.
			$src_files = scandir( $src_dir );
			
			// If there are exactly three files in the list ( '.', '..' and something else ) then check the third one and if it's a directory, make it he new $src_dir.
			if( count( $src_files ) == 3 ) {
				if( is_dir( $src_dir . '/' . $src_files[2] ) ) {
					$src_dir .= '/' . $src_files[2];
				}
			}
			
			$makepot = new MakePOT;
			
			$makepot->generic( $src_dir, $temp_pot );
			
			$format = gp_array_get( GP::$formats, gp_post( 'format', 'po' ), null );

			$translations = $format->read_originals_from_file( $temp_pot );

			$this->delTree( $temp_dir );
			unlink( $temp_pot );
			
			if( FALSE === $translations ) {
				return '<div class="notice updated"><p>' . __( 'Failed to read strings from source code.' ) . '</p></div>';
			}
			
			list( $originals_added, $originals_existing, $originals_fuzzied, $originals_obsoleted ) = GP::$original->import_for_project( $project, $translations );

			if( true === $format_message ) {
				$message = '<div class="notice updated"><p>';
			}
			
			$message .= sprintf(
				__( '%1$s new strings added, %2$s updated, %3$s fuzzied, and %4$s obsoleted in the "%5$s" project.' ),
				$originals_added,
				$originals_existing,
				$originals_fuzzied,
				$originals_obsoleted,
				$project->name
			);
			
			if( true === $format_message ) {
				$message .= '</p></div>';
			}
		} else {
			if( true === $format_message ) {
				$message = '<div class="notice updated"><p>';
			}

			$message .= sprintf( __('Failed to download "%s".' ), $url_name ) . '</p></div>';

			if( true === $format_message ) {
				$message .= '</p></div>';
			}
		}

		return $message;
	}
	
	// This function displays the admin settings page in WordPress.
	public function admin_page() {
		// If the current user can't manage options, display a message and return immediately.
		if( ! current_user_can( 'manage_options' ) ) { _e('You do not have permissions to this page!'); return; }

		$projects = GP::$project->all();
	
		$message = '';
		
		$project_settings = (array)get_option( 'gp_auto_extract', array() );
		
		foreach( $projects as $project ) {
			if( array_key_exists( 'save_' . $project->id, $_POST ) ) {
				$project_settings[ $project->id ][ 'type' ] = $_POST[ 'source_type_' . $project->id ];
				$project_settings[ $project->id ][ 'setting' ] = $_POST[ 'setting_' . $project->id ];
				
				update_option( 'gp_auto_extract', $project_settings );
			}
			
			if( array_key_exists( 'delete_' . $project->id, $_POST ) ) {
				$project_settings[ $project->id ][ 'type' ] = 'none';
				$project_settings[ $project->id ][ 'setting' ] = '';
				
				update_option( 'gp_auto_extract', $project_settings );
			}

			if( array_key_exists( 'extract_' . $project->id, $_POST ) ) {
				if( 'none' != $project_settings[ $project->id ][ 'type' ] ) {
					$message = $this->extract_project( $project, $project_settings );
				} else {
					$message = '<div class="notice error"><p>' . sprintf( __('No source type selected for project "%s".' ), $project->name ) . '</p></div>';
				}
			}
		}
		
	?>	
<div class="wrap">
	<?php echo $message; ?>

	<h2><?php _e('GP Auto Extract Settings');?></h2>

	<br />
	
	<form method="post" action="options-general.php?page=gp-auto-extract.php" >	
	
		<table class="widefat">
			<thead>
			<tr>
				<th><?php _e( 'Project' ); ?></td>
				<th><?php _e( 'Source Type' ); ?></td>
				<th><?php _e( 'Setting' ); ?></td>
				<th><?php _e( 'Options' ); ?></td>
			</tr>
			</thead>

			<tbody>

<?php
	foreach( $projects as $project ) {
		$source_type = 'none';
		$setting = '';
		
		if( array_key_exists( $project->id, $project_settings ) ) {
			$source_type = $project_settings[ $project->id ][ 'type' ];
			$setting = $project_settings[ $project->id ][ 'setting' ];
		}
		
		$buttons = '';
		$buttons .= get_submit_button( __('Save'), 'primary', 'save_' . $project->id, false ) . '&nbsp;';
		
		if( is_array( $project_settings ) && array_key_exists( $project->id, $project_settings) && is_array( $project_settings[ $project->id ] ) && array_key_exists( 'type',  $project_settings[ $project->id ] ) && 'none' != $project_settings[ $project->id ][ 'type' ] ) {
			$buttons .= get_submit_button( __('Delete'), 'delete', 'delete_' . $project->id, false ) . '&nbsp;';
			$buttons .= get_submit_button( __('Extract'), 'secondary', 'extract_' . $project->id, false ) . '&nbsp;';
		}
		
		$source_type_selector = '<select name="source_type_' . $project->id . '" id="source_type_' . $project->id . '">';
		
		foreach( $this->source_types as $id => $type ) {
			$id == $source_type ? $id_selected  = ' SELECTED' : $id_selected = '';
			$source_type_selector .= '<option value="' . $id . '"' . $id_selected . '>' . $type . '</option>';
		}
		
		$source_type_selector .= '</select>';
		
		echo '<tr><td>' . $project->name . '</td><td>' . $source_type_selector . '</td><td><input name="setting_' . $project->id . '" id="setting_' . $project->id . '" type="text" size="45" value="' . $setting . '"></input></td><td> ' . $buttons . '</td></tr>';
	}
?>	
	
			</tbody>
		</table>		
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
