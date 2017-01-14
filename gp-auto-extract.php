<?php
/**
 * Plugin Name: GP Auto Extract
 * Plugin URI: http://glot-o-matic.com/gp-auto-extract
 * Description: Automatically extract source strings from a remote repo.
 * Version: 0.7
 * Author: Greg Ross
 * Author URI: http://toolstack.com
 * Tags: glotpress, glotpress plugin, translate
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package GlotPress
 */

/**
 * Ok, we're going to cheat a little bit here and create our main class as an extension of the GP_Route_Main class.
 *
 * This let's us use it for both the main class of our plugin AND the class to handle the route for the front end
 * menu item in the projects menu.
 *
 * If we didn't do this we'd either have to create a second class to use to extend GP_Route_Main or create several
 * stub functions to mimic the GP_Route class that the GP Router needs to function correctly.
 */
class GP_Auto_Extract extends GP_Route_Main {
	/**
	 * Id of instance.
	 *
	 * @access public
	 * @var String $id
	 */
	public $id = 'gp-auto-extract';

	/**
	 * All supported source types.
	 *
	 * @access private
	 * @var Array $source_types
	 */
	private $source_types;

	/**
	 * Url templates for source types.
	 *
	 * @access private
	 * @var Array $source_type_templates
	 */
	private $source_type_templates;

	/**
	 * Cache of username and password for HTTP Basic Authentication filter.
	 *
	 * @access private
	 * @var Array $url_credentials
	 */
	private $url_credentials;

	/**
	 * Encryption key for passwords.
	 *
	 * @access private
	 * @var String $encryption_key
	 */
	private $encryption_key;

	/**
	 * Encryption cypher for passwords.
	 *
	 * @access private
	 * @var String $encryption_cypher
	 */
	private $encryption_cypher;

	/**
	 * Initialize the variables, hooks and setup GlotPress API routes.
	 */
	public function __construct() {
		$this->source_types = array( 'none' => __( 'none' ), 'github' => __( 'GitHub' ), 'wordpress' => __( 'WordPress.org' ), 'custom' => __( 'Custom' ) );
		$this->source_type_templates = array(
			'none'      => '',
			'github'    => 'https://github.com/%1$s/archive/%2$s.zip',
			'wordpress' => 'https://downloads.wordpress.org/plugin/%1$s.zip',
			'custom'    => '%1$s',
		);
		$this->url_credentials = array();

		if ( defined( 'GP_AUTO_EXTRACT_ENCRYPT_KEY' ) ) {
			$this->encryption_key = GP_AUTO_EXTRACT_ENCRYPT_KEY;
		} else {
			$this->encryption_key = substr( wp_salt(), 0, 32 );
		}

		if ( defined( 'GP_AUTO_EXTRACT_CYPHER' ) ) {
			$this->encryption_cypher = GP_AUTO_EXTRACT_CYPHER;
		} else {
			$this->encryption_cypher = 'aes-256-cbc';
		}

		// Add the admin page to the WordPress settings menu.
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'load_custom_wp_admin_style' ) );

		// If the user has write permissions to the projects, add the auto extract option to the projects menu.
		if ( GP::$permission->user_can( wp_get_current_user(), 'write', 'project' ) ) {
			add_action( 'gp_project_actions', array( $this, 'gp_project_actions' ), 10, 2 );
		}

		// We can't use the filter in the defaults route code because plugins don't load until after
		// it has already run, so instead add the routes directly to the global GP_Router object.
		GP::$router->add( '/auto-extract/(.+?)', array( $this, 'auto_extract' ), 'get' );
		GP::$router->add( '/auto-extract/(.+?)', array( $this, 'auto_extract' ), 'post' );
	}

	/**
	 * Enqueues the admin assets.
	 *
	 * @param String $hook Admin page hook name.
	 */
	public function load_custom_wp_admin_style( $hook ) {
		// Load only on ?page=gp-auto-extract.php.
		if ( 'settings_page_gp-auto-extract' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'gp-auto-extract-css', plugins_url( 'assets/css/gp-auto-extract.css', __FILE__ ) );

		wp_register_script( 'gp-auto-extract-js', plugins_url( 'assets/js/gp-auto-extract.js', __FILE__ ) );
		$translation_array = array(
			'passwords' => array(
				'none' => '',
				'github' => esc_attr__( 'or Personal Access Token' ),
				'wordpress' => '',
				'custom' => '',
			),
			'settings' => array(
				'none' => '',
				'github' => esc_attr__( 'username/repository' ),
				'wordpress' => esc_attr__( 'plugin-or-theme-slug' ),
				'custom' => esc_attr__( 'url for a valid archive with source files' ),
			),
		);
		wp_localize_script( 'gp-auto-extract-js', 'gpae', $translation_array );
		wp_enqueue_script( 'gp-auto-extract-js' );

		add_thickbox();
	}

	/**
	 * This function handles the actual auto extract passed in by the router for the projects menu.
	 *
	 * @param String $project_path The url path to the project.
	 */
	public function auto_extract( $project_path ) {
		// First let's ensure we have decoded the project path for use later.
		$project_path = urldecode( $project_path );

		// Get the URL to the project for use later.
		$url = gp_url_project( $project_path );

		// Create a project class to use to get the project object.
		$project_class = new GP_Project;

		// Get the project object from the project path that was passed in.
		$project_obj = $project_class->by_path( $project_path );

		if ( GP::$permission->user_can( wp_get_current_user(), 'write', 'project', $project_obj->id ) ) {
			// Get the project settings.
			$project_settings = (array) get_option( 'gp_auto_extract', array() );

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

	/**
	 * This function adds the "Auto Extract" option to the projects menu.
	 *
	 * @param Array      $actions Array containing project actions.
	 * @param GP_Project $project The GlotPress Project.
	 * @return Array Processed actions.
	 */
	public function gp_project_actions( $actions, $project ) {
		$project_settings = (array) get_option( 'gp_auto_extract', array() );

		if ( is_array( $project_settings ) && array_key_exists( $project->id, $project_settings ) && is_array( $project_settings[ $project->id ] ) && array_key_exists( 'type',  $project_settings[ $project->id ] ) && 'none' !== $project_settings[ $project->id ]['type'] ) {
			$actions[] .= gp_link_get( gp_url( 'auto-extract/' . $project->slug ), __( 'Auto Extract' ) );
		}

		return $actions;
	}

	/**
	 * Removes a folder and all its content recursively.
	 *
	 * @access private
	 *
	 * @param String $dir Folder to remove.
	 * @return Bool True if folder was removed, false if not.
	 */
	private function delTree( $dir ) {
		if ( ! gp_startswith( $dir, sys_get_temp_dir() ) ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );

		foreach ( $files as $file ) {
			if ( is_dir( "$dir/$file" ) ) {
				$this->delTree( "$dir/$file" );
			} else {
				unlink( "$dir/$file" );
			}
		}

		return rmdir( $dir );
	}

	/**
	 * This function adds the admin settings page to WordPress.
	 */
	public function admin_menu() {
		add_options_page( __( 'GP Auto Extract' ), __( 'GP Auto Extract' ), 'manage_options', basename( __FILE__ ), array( $this, 'admin_page' ) );
	}

	/**
	 * Downloads the source files and import the translations.
	 *
	 * @since 0.8.0
	 *
	 * @param Array $r   Original Request.
	 * @param Array $url Url being requested.
	 * @return Array Processed request.
	 */
	public function authenticate_download( $r, $url ) {
		if ( array_key_exists( $url, $this->url_credentials ) ) {
			if ( ! is_array( $r['headers'] ) ) {
				$r['headers'] = array();
			};
			$r['headers']['Authorization'] = 'Basic ' . base64_encode( $this->url_credentials[ $url ] );
			$r['redirection'] = 1;
		}
		return $r;
	}

	/**
	 * Downloads the source files and import the translations.
	 *
	 * @access private
	 *
	 * @param GP_Project $project          The GlotPress Project to extract.
	 * @param Array      $project_settings Array of Project Settings.
	 * @param Array      $format_message   Optional. If the message should be wrapped by html. Default true.
	 * @return String Message.
	 */
	private function extract_project( $project, $project_settings, $format_message = true ) {
		$url_name = sprintf(
			$this->source_type_templates[ $project_settings[ $project->id ]['type'] ],
			$project_settings[ $project->id ]['setting'],
			$project_settings[ $project->id ]['branch'] ?: 'master'
		);

		$current_project = $project_settings[ $project->id ];

		$use_http_basic_auth = array_key_exists( 'use_http_basic_auth', $current_project ) ? $current_project['use_http_basic_auth'] : '';

		if ( 'on' === $use_http_basic_auth ) {
			$http_auth_username  = array_key_exists( 'http_auth_username', $current_project ) ? $current_project['http_auth_username'] : '';
			$http_auth_password  = array_key_exists( 'http_auth_password', $current_project ) ? $current_project['http_auth_password'] : '';

			if ( ! empty( $http_auth_password ) ) {
				$http_auth_password = $this->decrypt_data( $http_auth_password );
			}

			$this->url_credentials[ $url_name ] = $http_auth_username . ':' . $http_auth_password;

			add_filter( 'http_request_args', array( $this, 'authenticate_download' ), 10, 2 );
		}

		$source_file = download_url( $url_name );

		if ( $use_http_basic_auth ) {
			remove_filter( 'http_request_args', array( $this, 'authenticate_download' ), 10, 2 );
		}

		$message = '';

		if ( ! is_wp_error( $source_file ) ) {

			include( dirname( __FILE__ ) . '/include/extract/makepot.php' );

			// Get a temporary file, use gpa as the first four letters of it.
			$temp_dir = tempnam( sys_get_temp_dir(), 'gpa' );
			$temp_pot = tempnam( sys_get_temp_dir(), 'gpa' );

			// Now delete the file and recreate it as a directory.
			unlink( $temp_dir );
			mkdir( $temp_dir );

			$zip = new ZipArchive;
			if ( true === $zip->open( $source_file ) ) {
				$zip->extractTo( $temp_dir );
				$zip->close();

				unlink( $source_file );
			} else {
				unlink( $source_file );

				return '<div class="notice error"><p>' . sprintf( __( 'Failed to extract zip file: "%s".' ), $source_file ) . '</p></div>';
			}

			$src_dir = $temp_dir;

			// Check to see if there is only a single directory in the resulting zip extract root directory, if so, make it the root of the makepot call.
			$src_files = scandir( $src_dir );

			// If there are exactly three files in the list ( '.', '..' and something else ) then check the third one and if it's a directory, make it he new $src_dir.
			if ( 3 === count( $src_files ) ) {
				if ( is_dir( $src_dir . '/' . $src_files[2] ) ) {
					$src_dir .= '/' . $src_files[2];
				}
			}

			$skip_makepot  = array_key_exists( 'skip_makepot', $current_project ) ? $current_project['skip_makepot'] : '';
			$import_format = array_key_exists( 'import_format', $current_project ) ? $current_project['import_format'] : '';
			$import_file   = array_key_exists( 'import_file', $current_project ) ? $current_project['import_file'] : '';

			if ( 'on' === $skip_makepot ) {

				$file_path = $src_dir . ( '/' === $import_file[0] ? '' : '/' ) . $import_file;

				$format = gp_get_import_file_format( $import_format ?: 'auto', $file_path );

				if ( ! $format ) {
					if ( true === $format_message ) {
						$message .= '<div class="notice error"><p>';
					}

					$message .= sprintf( __( 'Failed to detect format for "%s".' ), $import_file ) . '</p></div>';

					if ( true === $format_message ) {
						$message .= '</p></div>';
					}

					return $message;
				}

				$pot_file = $file_path;

			} else {

				$makepot = new MakePOT;

				// Fudge the project name and version so the makepot call doesn't generate warnings about them.
				$makepot->meta['generic']['package-name'] = $project->name;
				$makepot->meta['generic']['package-version'] = 'trunk';

				$makepot->generic( $src_dir, $temp_pot );

				$format = gp_array_get( GP::$formats, gp_post( 'format', 'po' ), null );

				$pot_file = $temp_pot;

			}

			$translations = $format->read_originals_from_file( $pot_file );

			$this->delTree( $temp_dir );
			unlink( $temp_pot );

			if ( false === $translations ) {
				return '<div class="notice error"><p>' . __( 'Failed to read strings from source code.' ) . '</p></div>';
			}

			list( $originals_added, $originals_existing, $originals_fuzzied, $originals_obsoleted ) = GP::$original->import_for_project( $project, $translations );

			if ( true === $format_message ) {
				$message .= '<div class="notice updated"><p>';
			}

			$message .= sprintf(
				__( '%1$s new strings added, %2$s updated, %3$s fuzzied, and %4$s obsoleted in the "%5$s" project.' ),
				$originals_added,
				$originals_existing,
				$originals_fuzzied,
				$originals_obsoleted,
				$project->name
			);

			if ( true === $format_message ) {
				$message .= '</p></div>';
			}
		} else {
			if ( true === $format_message ) {
				$message .= '<div class="notice error"><p>';
			}

			$message .= sprintf( __( 'Failed to download "%s".' ), $url_name ) . '</p></div>';

			if ( true === $format_message ) {
				$message .= '</p></div>';
			}
		}

		return $message;
	}

	/**
	 * This function encrypts data.
	 *
	 * @param String $data The data to be encrypted.
	 * @return String Encrypted data.
	 */
	private function encrypt_data( $data ) {
		$iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $this->encryption_cypher ) );
		$encrypted = openssl_encrypt( $data, $this->encryption_cypher, $this->encryption_key, 0, $iv ) . ':' . $iv;
		return base64_encode( $encrypted );
	}

	/**
	 * This function decrypts data.
	 *
	 * @param String $data The data to be decrypted.
	 * @return String Decrypted data.
	 */
	private function decrypt_data( $data ) {
		$parts = explode( ':', base64_decode( $data ), 2 );
		if ( 2 !== count( $parts ) ) {
			return $data;
		}

		$decrypted = openssl_decrypt( $parts[0], $this->encryption_cypher, $this->encryption_key, 0, $parts[1] );
		return $decrypted;
	}

	/**
	 * This function decrypts and return masked data.
	 *
	 * @param String $data The data to be decrypted and masked.
	 * @param String $char Optional. The data to be decrypted and masked. Default '*'.
	 * @return String Masked data.
	 */
	private function masked_data( $data, $char = '*' ) {
		$decrypted = $this->decrypt_data( $data );
		$masked = str_repeat( $char , strlen( $decrypted ) );
		return $masked;
	}

	/**
	 * This function displays the admin settings page in WordPress.
	 */
	public function admin_page() {
		// If the current user can't manage options, display a message and return immediately.
		if ( ! current_user_can( 'manage_options' ) ) {
			_e( 'You do not have permissions to this page!' );
			return;
		}

		$empty_project = array(
			'type' => 'none',
			'setting' => '',
			'branch' => '',
			'use_http_basic_auth' => false,
			'http_auth_username' => '',
			'http_auth_password' => '',
			'skip_makepot' => false,
			'import_format' => '',
			'import_file' => '',
		);

		$projects = GP::$project->all();

		$message = '';

		$project_settings = (array) get_option( 'gp_auto_extract', array() );

		if ( 'POST' === filter_var( $_SERVER['REQUEST_METHOD'] ) ) {
			check_admin_referer( 'gp-auto-extract' );

			foreach ( $projects as $project ) {

				if ( array_key_exists( 'save_' . $project->id, $_POST ) ) {
					$project_settings[ $project->id ]['type']                = filter_input( INPUT_POST, 'source_type_' . $project->id );
					$project_settings[ $project->id ]['setting']             = filter_input( INPUT_POST, 'setting_' . $project->id );
					$project_settings[ $project->id ]['branch']              = filter_input( INPUT_POST, 'branch_' . $project->id );

					$use_http_basic_auth = filter_input( INPUT_POST, 'use_http_basic_auth_' . $project->id );
					if ( 'on' === $use_http_basic_auth ) {
						$http_auth_username  = filter_input( INPUT_POST, 'http_auth_username_' . $project->id );
						$http_auth_password  = filter_input( INPUT_POST, 'http_auth_password_' . $project->id );

						$masked = $this->masked_data( $project_settings[ $project->id ]['http_auth_password'] );

						if ( $http_auth_password === $masked ) {
							$http_auth_password = $project_settings[ $project->id ]['http_auth_password'];
						} elseif ( empty( $http_auth_password ) ) {
							$http_auth_password = '';
						} else {
							$http_auth_password = $this->encrypt_data( $http_auth_password );
						}
					} else {
						// Do not save any credentials if we don't need to use authentication.
						$http_auth_username = '';
						$http_auth_password = '';
					}

					$project_settings[ $project->id ]['use_http_basic_auth'] = $use_http_basic_auth;
					$project_settings[ $project->id ]['http_auth_username']  = $http_auth_username;
					$project_settings[ $project->id ]['http_auth_password']  = $http_auth_password;

					$project_settings[ $project->id ]['skip_makepot']        = filter_input( INPUT_POST, 'skip_makepot_' . $project->id );
					$project_settings[ $project->id ]['import_format']       = filter_input( INPUT_POST, 'import_format_' . $project->id );
					$project_settings[ $project->id ]['import_file']         = filter_input( INPUT_POST, 'import_file_' . $project->id );

					update_option( 'gp_auto_extract', $project_settings );
				}

				if ( array_key_exists( 'delete_' . $project->id, $_POST ) ) {
					$project_settings[ $project->id ] = $empty_project;

					update_option( 'gp_auto_extract', $project_settings );
				}

				if ( array_key_exists( 'extract_' . $project->id, $_POST ) ) {
					if ( 'none' !== $project_settings[ $project->id ]['type'] ) {
						$message = $this->extract_project( $project, $project_settings );
					} else {
						$message = '<div class="notice error"><p>' . sprintf( __( 'No source type selected for project "%s".' ), $project->name ) . '</p></div>';
					}
				}
			}
		}
	?>
<div class="wrap">
	<?php echo $message; ?>

	<h2><?php _e( 'GP Auto Extract Settings' ); ?></h2>

	<br />

	<form method="post" id="gp-auto-extract" action="options-general.php?page=gp-auto-extract.php" >
		<?php wp_nonce_field( 'gp-auto-extract' ); ?>

		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php _e( 'Project' ); ?></th>
				<th><?php _e( 'Source Type' ); ?></th>
				<th><?php _e( 'Setting' ); ?></th>
				<th><?php _e( 'Branch' ); ?></th>
				<th><?php _e( 'Authorization' ); ?></th>
			</tr>
			</thead>

			<tbody id="the-list">

	<?php
	foreach ( $projects as $project ) {
		$source_type = 'none';
		$setting = '';

		if ( array_key_exists( $project->id, $project_settings ) ) {
			$current_project = $project_settings[ $project->id ];
		} else {
			$current_project = $empty_project;
		}

		$source_type         = array_key_exists( 'type', $current_project ) ? $current_project['type'] : 'none';
		$setting             = array_key_exists( 'setting', $current_project ) ? $current_project['setting'] : '';
		$branch              = array_key_exists( 'branch', $current_project ) ? $current_project['branch'] : '';
		$use_http_basic_auth = array_key_exists( 'use_http_basic_auth', $current_project ) ? $current_project['use_http_basic_auth'] : '';
		$http_auth_username  = array_key_exists( 'http_auth_username', $current_project ) ? $current_project['http_auth_username'] : '';
		$http_auth_password  = array_key_exists( 'http_auth_password', $current_project ) ? $current_project['http_auth_password'] : '';
		$skip_makepot        = array_key_exists( 'skip_makepot', $current_project ) ? $current_project['skip_makepot'] : '';
		$import_format       = array_key_exists( 'import_format', $current_project ) ? $current_project['import_format'] : '';
		$import_file         = array_key_exists( 'import_file', $current_project ) ? $current_project['import_file'] : '';

		$row_actions = '';
		$row_actions .= sprintf(
			'<span class="edit"><a href="" class="editinline" data-project-id="%s" aria-label="%s">%s</a></span>',
			esc_attr( $project->id ),
			/* translators: %s: project name */
			esc_attr( sprintf( __( 'Edit project &#8220;%s&#8221;' ), $project->name ) ),
			__( 'Edit' )
		);

		if ( is_array( $project_settings ) && array_key_exists( $project->id, $project_settings ) && is_array( $project_settings[ $project->id ] ) && array_key_exists( 'type',  $project_settings[ $project->id ] ) && 'none' !== $project_settings[ $project->id ]['type'] ) {
			$row_actions .= sprintf(
				//' | <span class="trash"><a href="" class="submitdelete reset-project" id="delete_%s" aria-label="%s">%s</a></span>',
				' | <span class="trash"><a href="#TB_inline;height=130;width=380;inlineId=confirm-reset-%1$s" class="submitdelete thickbox" aria-label="%2$s" title="%3$s">%4$s</a></span>',
				esc_attr( $project->id ),
				/* translators: %s: project name */
				esc_attr( sprintf( __( 'Reset &#8220;%s&#8221;' ), $project->name ) ),
				__( 'Are you sure?' ),
				__( 'Reset' )
			);

			$row_actions .= sprintf(
				' | <span class="extract"><a href="" class="extract-project" id="extract_%s" aria-label="%s">%s</a></span>',
				esc_attr( $project->id ),
				/* translators: %s: project name */
				esc_attr( sprintf( __( 'Extract &#8220;%s&#8221;' ), $project->name ) ),
				__( 'Extract' )
			);
		}

		if ( 'github' === $source_type ) {
			$branch_label = $branch ?: 'master';
			$use_http_basic_auth_label = $use_http_basic_auth ? __( 'Enabled' ) : __( 'Disabled' );
		} elseif ( 'wordpress' === $source_type ) {
			$branch_label = __( 'N/A' );
			$use_http_basic_auth_label = __( 'N/A' );
		} elseif ( 'custom' === $source_type ) {
			$branch_label = __( 'N/A' );
			$use_http_basic_auth_label = $use_http_basic_auth ? __( 'Enabled' ) : __( 'Disabled' );
		} else {
			$branch_label = '';
			$use_http_basic_auth_label = '';
		}

		?>
				<tr id="project-<?php echo esc_attr( $project->id ); ?>">
					<td class="title column-title has-row-actions column-primary">
						<strong><?php echo esc_html( $project->name ); ?></strong>
						<div class="row-actions">
							<?php
							echo $row_actions;
							?>
							<div id="confirm-reset-<?php echo esc_attr( $project->id ); ?>" style="display:none;">
								<p><?php printf( __( 'You will lose all project "%s" settings.' ), $project->name ); ?></p>
								<p>
									<button type="submit" class="button button-primary reset-project" id="delete_<?php echo esc_attr( $project->id ); ?>"><?php _e( 'Reset' ); ?></button>
									<button type="button" class="button close" data-project-id="<?php echo esc_attr( $project->id ); ?>"><?php _e( 'Cancel' ); ?></button>
								</p>
							</div>
						</div>
					</td>
					<td><?php echo $this->source_types[ $source_type ]; ?></td>
					<td><?php echo esc_html( $setting ); ?></td>
					<td><?php echo $branch_label; ?></td>
					<td><?php echo $use_http_basic_auth_label; ?></td>
				</tr>
				<tr class="hidden"></tr>
				<tr id="edit-project-<?php echo esc_attr( $project->id ); ?>" class="source-type-<?php echo esc_attr( $source_type ); ?> hidden inline-edit-row inline-edit-row-page inline-edit-page quick-edit-row quick-edit-row-page inline-edit-page inline-editor">
					<td colspan="5" class="colspanchange">
						<fieldset class="inline-edit-col-left">
							<legend class="inline-edit-legend"><?php _e( 'Edit' ); ?></legend>
							<div class="inline-edit-col">
								<label>
									<span class="title"><?php _e( 'Project' ); ?></span>
									<span class="input-text-wrap"><strong><?php echo esc_html( $project->name ); ?></strong></span>
								</label>
								<label>
									<span class="title"><?php _e( 'Source Type' ); ?></span>
									<select class="source_type" name="source_type_<?php echo esc_attr( $project->id ); ?>" id="source_type_<?php echo esc_attr( $project->id ); ?>">
									<?php foreach ( $this->source_types as $id => $type ) { ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php echo selected( $source_type, $id ); ?>><?php echo $type; ?></option>;
									<?php } ?>
									</select>
								</label>
								<label class="hide-if-none">
									<span class="title"><?php _e( 'Setting' ); ?></span>
									<span class="input-text-wrap"><input type="text" class="gpae-setting" name="setting_<?php echo esc_attr( $project->id ); ?>" value="<?php echo esc_attr( $setting ); ?>"></span>
								</label>
								<div class="inline-edit-group wp-clearfix show-if-github">
									<label class="alignleft">
										<span class="title"><?php _e( 'Branch/Tag' ); ?></span>
										<span class="input-text-wrap"><input type="text" name="branch_<?php echo esc_attr( $project->id ); ?>" class="inline-edit-password-input" value="<?php echo esc_attr( $branch ); ?>" placeholder="master"></span>
									</label>
								</div>
								<div class="inline-edit-group wp-clearfix hide-if-none hide-if-wordpress">
									<label class="alignleft">
										<input type="checkbox" name="use_http_basic_auth_<?php echo esc_attr( $project->id ); ?>" <?php echo checked( $use_http_basic_auth, 'on' ); ?> class="group-toggle" data-group="httpauth-<?php echo esc_attr( $project->id ); ?>">
										<span class="checkbox-title"><?php _e( 'Use HTTP Basic Authentication' ); ?></span>
									</label>
								</div>
								<div class="inline-edit-group wp-clearfix hide-if-none hide-if-wordpress hidden group-httpauth-<?php echo esc_attr( $project->id ); ?>">
									<label class="alignleft">
										<span class="title"><?php _e( 'Username' ); ?></span>
										<span class="input-text-wrap"><input type="text" name="http_auth_username_<?php echo esc_attr( $project->id ); ?>" value="<?php echo esc_attr( $http_auth_username ); ?>"></span>
									</label>
									<label class="alignleft">
										<span class="title"><?php _e( 'Password' ); ?></span>
										<span class="input-text-wrap"><input type="password" class="gpae-password" name="http_auth_password_<?php echo esc_attr( $project->id ); ?>" class="inline-edit-password-input" data-masked-value="<?php echo esc_attr( $this->masked_data( $http_auth_password, '*' ) ); ?>"></span>
									</label>
								</div>
							</div>
						</fieldset>
						<fieldset class="inline-edit-col-right">
							<div class="inline-edit-col">
								<div class="inline-edit-group wp-clearfix hide-if-none">
									<label class="alignleft">
										<input type="checkbox" name="skip_makepot_<?php echo esc_attr( $project->id ); ?>" <?php echo checked( $skip_makepot, 'on' ); ?> class="group-toggle" data-group="makepot-<?php echo esc_attr( $project->id ); ?>">
										<span class="checkbox-title"><?php _e( 'Import from existing file' ); ?></span>
									</label>
								</div>
								<div class="inline-edit-group wp-clearfix hide-if-none hidden group-makepot-<?php echo esc_attr( $project->id ); ?>">
									<label class="alignleft">
										<span class="title"><?php _e( 'Format' ); ?></span>
										<?php
										$format_options = array();
										$format_options['auto'] = __( 'Auto Detect' );
										foreach ( GP::$formats as $slug => $format ) {
											$format_options[ $slug ] = $format->name;
										}
										echo gp_select( 'import_format_' . $project->id, $format_options, $import_format ?: 'auto' );
										?>
									</label>
								</div>
								<div class="inline-edit-group wp-clearfix hide-if-none hidden group-makepot-<?php echo esc_attr( $project->id ); ?>">
									<label>
										<span class="title"><?php _e( 'File' ); ?></span>
										<span class="input-text-wrap"><input type="text" name="import_file_<?php echo esc_attr( $project->id ); ?>" value="<?php echo esc_attr( $import_file ); ?>" placeholder="<?php esc_attr_e( 'path of file to import relative to repository or archive root' ); ?>"></span>
									</label>
								</div>
							</div>
						</fieldset>
						<p class="submit inline-edit-save">
							<button type="button" class="button cancel alignleft" data-project-id="<?php echo esc_attr( $project->id ); ?>"><?php _e( 'Cancel' ); ?></button>
							<input type="submit" name="save_<?php echo esc_attr( $project->id ); ?>" class="button button-primary save alignright" value="<?php esc_attr_e( 'Save' ); ?>"/>
							<br class="clear">
						</p>
					</td>
				</tr>
		<?php
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

/**
 * This function creates the plugin.
 */
function gp_auto_extract_init() {
	global $gp_auto_extract;

	$gp_auto_extract = new GP_Auto_Extract;
}
