<?php
/**
 * Plugin Name: Local Avatars
 * Description: WP Local Avatars instead of Gravatar.
 * Version: 1.0.0
 * Author: Álvaro Franz
 * GitHub Plugin URI: https://github.com/alvarofranz/afz-disable-big-images-scaling
 * Text Domain: afz-local-avatars
*/

// Afz local avatars class
class Afz_local_avatars {
	private $user_id_being_edited, $avatar_upload_error, $remove_nonce, $avatar_ratings;
	public $options;

	// Set up the hooks and default values
	public function __construct(){

		$this->options = (array) get_option( 'afz_local_avatars' );
		$this->avatar_ratings = array(
			'G' => __('G &#8212; Suitable for all audiences'),
			'PG' => __('PG &#8212; Possibly offensive, usually for audiences 13 and above'),
			'R' => __('R &#8212; Intended for adult audiences above 17'),
			'X' => __('X &#8212; Even more mature than above')
		);

		// Supplement remote avatars, but not if inside "local only" mode
		if ( empty( $this->options['only'] ) )
			add_filter( 'get_avatar', array( $this, 'get_avatar' ), 10, 5 );
		
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'show_user_profile', array( $this, 'edit_user_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'edit_user_profile' ) );
		
		add_action( 'personal_options_update', array( $this, 'edit_user_profile_update' ) );
		add_action( 'edit_user_profile_update', array( $this, 'edit_user_profile_update' ) );
		add_action( 'admin_action_remove-afz-local-avatar', array( $this, 'action_remove_afz_local_avatar' ) );
		add_action( 'wp_ajax_assign_afz_local_avatar_media', array( $this, 'ajax_assign_afz_local_avatar_media' ) );
		add_action( 'wp_ajax_remove_afz_local_avatar', array( $this, 'action_remove_afz_local_avatar' ) );
		add_action( 'user_edit_form_tag', array( $this, 'user_edit_form_tag' ) );
		
		add_filter( 'avatar_defaults', array( $this, 'avatar_defaults' ) );
	}

	/**
	 * Retrieve the local avatar for a user who provided a user ID or email address.
	 *
	 * @param string $avatar Avatar return by original function
	 * @param int|string|object $id_or_email A user ID,  email address, or comment object
	 * @param int $size Size of the avatar image
	 * @param string $default URL to a default image to use if no avatar is available
	 * @param string $alt Alternative text to use in image tag. Defaults to blank
	 * @return string <img> tag for the user's avatar
	 */
	public function get_avatar( $avatar = '', $id_or_email, $size = 96, $default = '', $alt = '' ) {
		if ( is_numeric( $id_or_email ) )
			$user_id = (int) $id_or_email;
		elseif ( is_string( $id_or_email ) && ( $user = get_user_by( 'email', $id_or_email ) ) )
			$user_id = $user->ID;
		elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) )
			$user_id = (int) $id_or_email->user_id;
		
		if ( empty( $user_id ) )
			return $avatar;

		// Fetch local avatar from meta and check if it's set
		$local_avatars = get_user_meta( $user_id, 'afz_local_avatar', true );
		if ( empty( $local_avatars['full'] ) )
			return $avatar;

		// Check rating
		$avatar_rating = get_user_meta( $user_id, 'afz_local_avatar_rating', true );
		if ( ! empty( $avatar_rating ) && 'G' != $avatar_rating && ( $site_rating = get_option( 'avatar_rating' ) ) ) {
			$ratings = array_keys( $this->avatar_ratings );
			$site_rating_weight = array_search( $site_rating, $ratings );
			$avatar_rating_weight = array_search( $avatar_rating, $ratings );
			if ( false !== $avatar_rating_weight && $avatar_rating_weight > $site_rating_weight )
				return $avatar;
		}

		// Handle "real" media
		if ( ! empty( $local_avatars['media_id'] ) ) {
			// has the media been deleted?
			if ( ! $avatar_full_path = get_attached_file( $local_avatars['media_id'] ) ) {
				// only allowed logged in users to delete bad data to mitigate performance issues
				if ( is_user_logged_in() )
					$this->avatar_delete( $user_id );

				return $avatar;
			}
		}

		$size = (int) $size;
			
		if ( empty( $alt ) )
			$alt = get_the_author_meta( 'display_name', $user_id );
			
		// generate a new size
		if ( ! array_key_exists( $size, $local_avatars ) ) {
			$local_avatars[$size] = $local_avatars['full']; // just in case of failure elsewhere

			// allow automatic rescaling to be turned off
			if ( $allow_dynamic_resizing = apply_filters( 'afz_local_avatars_dynamic_resize', true ) ) :

				$upload_path = wp_upload_dir();

				// get path for image by converting URL, unless its already been set, thanks to using media library approach
				if ( ! isset( $avatar_full_path ) )
					$avatar_full_path = str_replace( $upload_path['baseurl'], $upload_path['basedir'], $local_avatars['full'] );

				// generate the new size
				$editor = wp_get_image_editor( $avatar_full_path );
				if ( ! is_wp_error( $editor ) ) {
					$resized = $editor->resize( $size, $size, true );
					if ( ! is_wp_error( $resized ) ) {
						$dest_file = $editor->generate_filename();
						$saved = $editor->save( $dest_file );
						if ( ! is_wp_error( $saved ) )
							$local_avatars[$size] = str_replace( $upload_path['basedir'], $upload_path['baseurl'], $dest_file );
					}
				}

				// save updated avatar sizes
				update_user_meta( $user_id, 'afz_local_avatar', $local_avatars );

			endif;
		}

		if ( 'http' != substr( $local_avatars[$size], 0, 4 ) )
			$local_avatars[$size] = home_url( $local_avatars[$size] );
		
		$author_class = is_author( $user_id ) ? ' current-author' : '' ;
		$avatar = "<img alt='" . esc_attr( $alt ) . "' src='" . esc_url( $local_avatars[$size] ) . "' class='avatar avatar-{$size}{$author_class} photo' height='{$size}' width='{$size}' />";
		
		return apply_filters( 'afz_local_avatar', $avatar );
	}
	
	public function admin_init() {
		// upgrade pre 2.0 option
		if ( $old_ops = get_option( 'afz_local_avatars_caps' ) ) {
			if ( ! empty( $old_ops['afz_local_avatars_caps'] ) )
				update_option( 'afz_local_avatars', array( 'caps' => 1 ) );

			delete_option( 'afz_local_avatar_caps' );
		}

		register_setting( 'discussion', 'afz_local_avatars', array( $this, 'sanitize_options' ) );
		add_settings_field( 'afz-local-avatars-only', __('Local Avatars Only','afz-local-avatars'), array( $this, 'avatar_settings_field' ), 'discussion', 'avatars', array( 'key' => 'only', 'desc' => 'Only allow local avatars (still uses Gravatar for default avatars)' ) );
		add_settings_field( 'afz-local-avatars-caps', __('Local Upload Permissions','afz-local-avatars'), array( $this, 'avatar_settings_field' ), 'discussion', 'avatars', array( 'key' => 'caps', 'desc' => 'Only allow users with file upload capabilities to upload local avatars (Authors and above)' ) );
	}

	/**
	 * Add scripts to the profile editing page
	 *
	 * @param string $hook_suffix Page hook
	 */
	public function admin_enqueue_scripts( $hook_suffix ) {
		if ( 'profile.php' != $hook_suffix && 'user-edit.php' != $hook_suffix )
			return;

		if ( current_user_can( 'upload_files' ) )
			wp_enqueue_media();

		$user_id = ( 'profile.php' == $hook_suffix ) ? get_current_user_id() : (int) $_GET['user_id'];

		$this->remove_nonce = wp_create_nonce( 'remove_afz_local_avatar_nonce' );

		$script_name_append = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '.dev' : '';
		wp_enqueue_script( 'afz-local-avatars', plugins_url( '', __FILE__ ) . '/afz-local-avatars' . $script_name_append . '.js', array('jquery'), false, true );
		wp_localize_script( 'afz-local-avatars', 'i10n_AfzLocalAvatars', array(
			'user_id'			=> $user_id,
			'insertMediaTitle'	=> __('Choose an Avatar','afz-local-avatars'),
			'insertIntoPost'	=> __('Set as avatar','afz-local-avatars'),
			'deleteNonce'		=> $this->remove_nonce,
			'mediaNonce'		=> wp_create_nonce( 'assign_afz_local_avatar_nonce' ),
		) );
	}

	/**
	 * Sanitize new settings field before saving
	 *
	 * @param array|string $input Passed input values to sanitize
	 * @return array|string Sanitized input fields
	 */
	public function sanitize_options( $input ) {
		$new_input['caps'] = empty( $input['caps'] ) ? 0 : 1;
		$new_input['only'] = empty( $input['only'] ) ? 0 : 1;
		return $new_input;
	}

	/**
	 * Settings field for avatar upload capabilities
	 *
	 * @param array $args Field arguments
	 */
	public function avatar_settings_field( $args ) {
		$args = wp_parse_args( $args, array(
			'key' 	=> '',
			'desc'	=> '',
		) );

		if ( empty( $this->options[$args['key']] ) )
			$this->options[$args['key']] = 0;
		
		echo '
			<label for="afz-local-avatars-' . $args['key'] . '">
				<input type="checkbox" name="afz_local_avatars[' . $args['key'] . ']" id="afz-local-avatars-' . $args['key'] . '" value="1" ' . checked( $this->options[$args['key']], 1, false ) . ' />
				' . __($args['desc'],'afz-local-avatars') . '
			</label>
		';
	}

	/**
	 * Output new Avatar fields to user editing / profile screen
	 *
	 * @param object $profileuser User object
	 */
	public function edit_user_profile( $profileuser ) {
	?>
	<h3><?php _e( 'Avatar','afz-local-avatars' ); ?></h3>
	
	<table class="form-table">
		<tr>
			<th scope="row"><label for="afz-local-avatar"><?php _e('Upload Avatar','afz-local-avatars'); ?></label></th>
			<td style="width: 50px;" id="afz-local-avatar-photo">
				<?php
					add_filter( 'pre_option_avatar_rating', '__return_null' ); 	// ignore ratings here
					echo get_afz_local_avatar( $profileuser->ID );
					remove_filter( 'pre_option_avatar_rating', '__return_null' );
				?>
			</td>
			<td>
			<?php
				if ( ! $upload_rights = current_user_can('upload_files') )
					$upload_rights = empty( $this->options['caps'] );
			
				if ( $upload_rights ) {
					do_action( 'afz_local_avatar_notices' ); 
					wp_nonce_field( 'afz_local_avatar_nonce', '_afz_local_avatar_nonce', false );
					$remove_url = add_query_arg(array(
						'action'	=> 'remove-afz-local-avatar',
						'user_id'	=> $profileuser->ID,
						'_wpnonce'	=> $this->remove_nonce,
					) );
			?>
					<p style="display: inline-block; width: 26em;">
					
						<input type="file" name="afz-local-avatar" id="afz-local-avatar" class="standard-text" />
						<span class="spinner" id="afz-local-avatar-spinner"></span>
					</p>
					<p>
						<?php if ( current_user_can( 'upload_files' ) && did_action( 'wp_enqueue_media' ) ) : ?><a href="#" class="button hide-if-no-js" id="afz-local-avatar-media"><?php _e( 'Choose from Media Library', 'afz-local-avatars' ); ?></a> &nbsp;<?php endif; ?>
						<a href="<?php echo $remove_url; ?>" class="button item-delete submitdelete deletion" id="afz-local-avatar-remove"<?php if ( empty( $profileuser->afz_local_avatar ) ) echo ' style="display:none;"'; ?>><?php _e('Delete local avatar','afz-local-avatars'); ?></a>
					</p>
			<?php
				} else {
					if ( empty( $profileuser->afz_local_avatar ) )
						echo '<span class="description">' . __('No local avatar is set. Set up your avatar at Gravatar.com.','afz-local-avatars') . '</span>';
					else 
						echo '<span class="description">' . __('You do not have media management permissions. To change your local avatar, contact the blog administrator.','afz-local-avatars') . '</span>';
				}
			?>
			</td>
		</tr>
		<tr style="display:none;">
			<th scope="row"><?php _e('Rating'); ?></th>
			<td colspan="2">
				<fieldset id="afz-local-avatar-ratings" <?php disabled( empty( $profileuser->afz_local_avatar ) ); ?>>
					<legend class="screen-reader-text"><span><?php _e('Rating'); ?></span></legend>
					<?php
						if ( empty( $profileuser->afz_local_avatar_rating ) || ! array_key_exists( $profileuser->afz_local_avatar_rating, $this->avatar_ratings ) )
							$profileuser->afz_local_avatar_rating =  'G';

						foreach ( $this->avatar_ratings as $key => $rating ) :
							echo "\n\t<label><input type='radio' name='afz_local_avatar_rating' value='" . esc_attr( $key ) . "' " . checked( $profileuser->afz_local_avatar_rating, $key, false ) . "/> $rating</label><br />";
						endforeach;
					?>
					<p class="description"><?php _e( 'If the local avatar is inappropriate for this site, Gravatar will be attempted.' ); ?></p>
				</fieldset></td>
		</tr>
	</table>
	<?php
	}

	/**
	 * Ensure that the profile form has proper encoding type
	 */
	public function user_edit_form_tag() {
		echo 'enctype="multipart/form-data"';
	}

	/**
	 * Saves avatar image to a user
	 *
	 * @param int|string $url_or_media_id Local URL for avatar or ID of attachment
	 * @param int $user_id ID of user to assign image to
	 */
	private function assign_new_user_avatar( $url_or_media_id, $user_id ) {
		// delete the old avatar
		$this->avatar_delete( $user_id );	// delete old images if successful

		$meta_value = array();

		// set the new avatar
		if ( is_int( $url_or_media_id ) ) {
			$meta_value['media_id'] = $url_or_media_id;
			$url_or_media_id = wp_get_attachment_url( $url_or_media_id );
		}

		$meta_value['full'] = $url_or_media_id;

		update_user_meta( $user_id, 'afz_local_avatar', $meta_value );	// save user information (overwriting old)
	}

	/**
	 * Save any changes to the user profile
	 *
	 * @param int $user_id ID of user being updated
	 */
	public function edit_user_profile_update( $user_id ) {
		// check nonces
		if( empty( $_POST['_afz_local_avatar_nonce'] ) || ! wp_verify_nonce( $_POST['_afz_local_avatar_nonce'], 'afz_local_avatar_nonce' ) )
			return;

		// check for uploaded files
		if ( ! empty( $_FILES['afz-local-avatar']['name'] ) ) :

			// need to be more secure since low privelege users can upload
			if ( false !== strpos( $_FILES['afz-local-avatar']['name'], '.php' ) ) {
				$this->avatar_upload_error = __('For security reasons, the extension ".php" cannot be in your file name.','afz-local-avatars');
				add_action( 'user_profile_update_errors', array( $this, 'user_profile_update_errors' ) );
				return;
			}

			// front end (theme my profile etc) support
			if ( ! function_exists( 'wp_handle_upload' ) )
				require_once( ABSPATH . 'wp-admin/includes/file.php' );

			// allow developers to override file size upload limit for avatars
			add_filter( 'upload_size_limit', array( $this, 'upload_size_limit' ) );

			$this->user_id_being_edited = $user_id; // make user_id known to unique_filename_callback function
			$avatar = wp_handle_upload( $_FILES['afz-local-avatar'], array(
				'mimes' 					=> array(
					'jpg|jpeg|jpe'	=> 'image/jpeg',
					'gif'			=> 'image/gif',
					'png'			=> 'image/png',
				),
				'test_form'					=> false,
				'unique_filename_callback'	=> array( $this, 'unique_filename_callback' )
			) );

			remove_filter( 'upload_size_limit', array( $this, 'upload_size_limit' ) );

			if ( empty($avatar['file']) ) {		// handle failures
				switch ( $avatar['error'] ) {
					case 'File type does not meet security guidelines. Try another.' :
						$this->avatar_upload_error = __('Please upload a valid image file for the avatar.','afz-local-avatars');
						break;
					default :
						$this->avatar_upload_error = '<strong>' . __('There was an error uploading the avatar:','afz-local-avatars') . '</strong> ' . esc_html( $avatar['error'] );
				}

				add_action( 'user_profile_update_errors', array( $this, 'user_profile_update_errors' ) );

				return;
			}

			$this->assign_new_user_avatar( $avatar['url'], $user_id );

		endif;

		// handle rating
		if ( isset( $avatar['url'] ) || $avatar = get_user_meta( $user_id, 'afz_local_avatar', true ) ) {
			if ( empty( $_POST['afz_local_avatar_rating'] ) || ! array_key_exists( $_POST['afz_local_avatar_rating'], $this->avatar_ratings ) )
				$_POST['afz_local_avatar_rating'] = key( $this->avatar_ratings );

			update_user_meta( $user_id, 'afz_local_avatar_rating', $_POST['afz_local_avatar_rating'] );
		}
	}

	/**
	 * Allow developers to override the maximum allowable file size for avatar uploads
	 *
	 * @param int $bytes WordPress default byte size check
	 * @return int Maximum byte size
	 */
	public function upload_size_limit( $bytes ) {
		return apply_filters( 'afz_local_avatars_upload_limit', $bytes );
	}

	/**
	 * Runs when a user clicks the Remove button for the avatar
	 */
	public function action_remove_afz_local_avatar() {
		if ( ! empty( $_GET['user_id'] ) &&  ! empty( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'remove_afz_local_avatar_nonce' ) ) {
			$user_id = (int) $_GET['user_id'];

			if ( ! current_user_can('edit_user', $user_id) )
				wp_die( __('You do not have permission to edit this user.') );

			$this->avatar_delete( $user_id );	// delete old images if successful

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX )
				echo get_afz_local_avatar( $user_id );
		}

		if ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			die;
	}

	/**
	 * AJAX callback for assigning media ID fetched from media library to user
	 */
	public function ajax_assign_afz_local_avatar_media() {
		// check required information and permissions
		if ( empty( $_POST['user_id'] ) || empty( $_POST['media_id'] ) || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_user', $_POST['user_id'] ) || empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'assign_afz_local_avatar_nonce' ) )
			die;

		$media_id = (int) $_POST['media_id'];
		$user_id = (int) $_POST['user_id'];

		// ensure the media is real is an image
		if ( wp_attachment_is_image( $media_id ) )
			$this->assign_new_user_avatar( $media_id, $user_id );

		echo get_afz_local_avatar( $user_id );

		die;
	}
	
	/**
	 * remove the custom get_avatar hook for the default avatar list output on options-discussion.php
	 */
	public function avatar_defaults( $avatar_defaults ) {
		remove_action( 'get_avatar', array( $this, 'get_avatar' ) );
		return $avatar_defaults;
	}

	/**
	 * Delete avatars based on a user_id
	 *
	 * @param int $user_id
	 */
	public function avatar_delete( $user_id ) {
		$old_avatars = (array) get_user_meta( $user_id, 'afz_local_avatar', true );

		if ( empty( $old_avatars ) )
			return;

		// if it was uploaded media, don't erase the full size or try to erase an the ID
		if ( array_key_exists( 'media_id', $old_avatars ) )
			unset( $old_avatars['media_id'], $old_avatars['full'] );

		if ( ! empty( $old_avatars ) ) {
			$upload_path = wp_upload_dir();

			foreach ($old_avatars as $old_avatar ) {
				// derive the path for the file based on the upload directory
				$old_avatar_path = str_replace( $upload_path['baseurl'], $upload_path['basedir'], $old_avatar );
				if ( file_exists( $old_avatar_path ) )
					unlink( $old_avatar_path );
			}
		}

		delete_user_meta( $user_id, 'afz_local_avatar' );
		delete_user_meta( $user_id, 'afz_local_avatar_rating' );
	}

	/**
	 * Creates a unique, meaningful file name for uploaded avatars.
	 *
	 * @param string $dir Path for file
	 * @param string $name Filename
	 * @param string $ext File extension (e.g. ".jpg")
	 * @return string Final filename
	 */
	public function unique_filename_callback( $dir, $name, $ext ) {
		$user = get_user_by( 'id', (int) $this->user_id_being_edited ); 
		$name = $base_name = sanitize_file_name( $user->display_name . '_avatar_' . time() );

		// ensure no conflicts with existing file names
		$number = 1;
		while ( file_exists( $dir . "/$name$ext" ) ) {
			$name = $base_name . '_' . $number;
			$number++;
		}
				
		return $name . $ext;
	}

	/**
	 * Adds errors based on avatar upload problems.
	 *
	 * @param WP_Error $errors Error messages for user profile screen.
	 */
	public function user_profile_update_errors( WP_Error $errors ) {
		$errors->add( 'avatar_error', $this->avatar_upload_error );
	}
}

$afz_local_avatars = new Afz_local_avatars;

/**
 * more efficient to call afz local avatar directly in theme and avoid gravatar setup
 * 
 * @param int|string|object $id_or_email A user ID,  email address, or comment object
 * @param int $size Size of the avatar image
 * @param string $default URL to a default image to use if no avatar is available
 * @param string $alt Alternate text to use in image tag. Defaults to blank
 * @return string <img> tag for the user's avatar
 */
function get_afz_local_avatar( $id_or_email, $size = 96, $default = '', $alt = '' ) {
	global $afz_local_avatars;
	$avatar = $afz_local_avatars->get_avatar( '', $id_or_email, $size, $default, $alt );
	
	if ( empty ( $avatar ) ) {
		remove_action( 'get_avatar', array( $afz_local_avatars, 'get_avatar' ) );
		$avatar = get_avatar( $id_or_email, $size, $default, $alt );
		add_action( 'get_avatar', array( $afz_local_avatars, 'get_avatar' ) );
	}
	
	return $avatar;
}

if ( ! function_exists( 'get_avatar' ) && ( $afz_local_avatars_options = get_option('afz_local_avatars') ) && ! empty( $afz_local_avatars_options['only'] ) ) :

	/**
	 * Retrieve the avatar for a user who provided a user ID or email address.
	 *
	 * @param int|string|object $id_or_email A user ID,  email address, or comment object
	 * @param int $size Size of the avatar image
	 * @param string $default URL to a default image to use if no avatar is available
	 * @param string $alt Alternative text to use in image tag. Defaults to blank
	 * @return string <img> tag for the user's avatar
	 */
	function get_avatar( $id_or_email, $size = 96, $default = '', $alt = '' ) {
		global $afz_local_avatars;

		if ( ! get_option('show_avatars') )
			return false;

		$safe_alt =  empty( $alt ) ? '' : esc_attr( $alt );

		if ( !is_numeric($size) )
			$size = 96;

		if ( ! $avatar = $afz_local_avatars->get_avatar( '', $id_or_email, $size, $default, $alt ) ) :

			if ( empty($default) ) {
				$avatar_default = get_option('avatar_default');
				if ( empty($avatar_default) )
					$default = 'mystery';
				else
					$default = $avatar_default;
			}

			$host = is_ssl() ? 'https://secure.gravatar.com' : 'http://0.gravatar.com';

			if ( 'mystery' == $default )
				$default = "$host/avatar/ad516503a11cd5ca435acc9bb6523536?s={$size}"; // ad516503a11cd5ca435acc9bb6523536 == md5('unknown@gravatar.com')
			elseif ( 'blank' == $default )
				$default = includes_url( 'images/blank.gif' );
			elseif ( 'gravatar_default' == $default )
				$default = "$host/avatar/?s={$size}";
			else
				$default = "$host/avatar/?d=$default&amp;s={$size}";

			$avatar = "<img alt='{$safe_alt}' src='" . $default . "' class='avatar avatar-{$size} photo avatar-default' height='{$size}' width='{$size}' />";

		endif;

		return apply_filters('get_avatar', $avatar, $id_or_email, $size, $default, $alt);
	}

endif;

/**
 * on uninstallation, remove the custom field from the users and delete the local avatars
 */

register_uninstall_hook( __FILE__, 'afz_local_avatars_uninstall' );

function afz_local_avatars_uninstall() {
	$afz_local_avatars = new Afz_local_avatars;
	$users = get_users(array(
		'meta_key'	=> 'afz_local_avatar',
		'fields'	=> 'ids',
	));

	foreach ( $users as $user_id ):
		$afz_local_avatars->avatar_delete( $user_id );
	endforeach;
	
	delete_option('afz_local_avatars');
}