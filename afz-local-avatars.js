var afz_local_avatar_frame, avatar_spinner, avatar_ratings, avatar_container, avatar_form_button;
var avatar_working = false;

jQuery(document).ready(function($){
	$( document.getElementById('afz-local-avatar-media') ).on( 'click', function(event) {
		event.preventDefault();

		if ( avatar_working )
			return;

		if ( afz_local_avatar_frame ) {
			afz_local_avatar_frame.open();
			return;
		}

		afz_local_avatar_frame = wp.media.frames.afz_local_avatar_frame = wp.media({
			title: i10n_AfzLocalAvatars.insertMediaTitle,
			button: { text: i10n_AfzLocalAvatars.insertIntoPost },
			library : { type : 'image'},
			multiple: false
		});

		afz_local_avatar_frame.on( 'select', function() {
			// We set multiple to false so only get one image from the uploader
			avatar_lock('lock');
			var avatar_url = afz_local_avatar_frame.state().get('selection').first().toJSON().id;
			jQuery.post( ajaxurl, { action: 'assign_afz_local_avatar_media', media_id: avatar_url, user_id: i10n_AfzLocalAvatars.user_id, _wpnonce: i10n_AfzLocalAvatars.mediaNonce }, function(data) {
				if ( data != '' ) {
					avatar_container.innerHTML = data;
					$( document.getElementById('afz-local-avatar-remove') ).show();
					avatar_ratings.disabled = false;
					avatar_lock('unlock');
				}
			});
		});

		afz_local_avatar_frame.open();
	});

	$( document.getElementById('afz-local-avatar-remove') ).on('click',function(event){
		event.preventDefault();

		if ( avatar_working )
			return;

		avatar_lock('lock');
		$.get( ajaxurl, { action: 'remove_afz_local_avatar', user_id: i10n_AfzLocalAvatars.user_id, _wpnonce: i10n_AfzLocalAvatars.deleteNonce })
		.done(function(data) {
			if ( data != '' ) {
				avatar_container.innerHTML = data;
				$( document.getElementById('afz-local-avatar-remove') ).hide();
				avatar_ratings.disabled = true;
				avatar_lock('unlock');
			}
		});
	});
});

function avatar_lock( lock_or_unlock ) {
	if ( undefined == avatar_spinner ) {
		avatar_ratings = document.getElementById('afz-local-avatar-ratings');
		avatar_spinner = jQuery( document.getElementById('afz-local-avatar-spinner') );
		avatar_container = document.getElementById('afz-local-avatar-photo');
		avatar_form_button = jQuery(avatar_ratings).closest('form').find('input[type=submit]');
	}

	if ( lock_or_unlock == 'unlock' ) {
		avatar_working = false;
		avatar_form_button.removeAttr('disabled');
		avatar_spinner.hide();
	} else {
		avatar_working = true;
		avatar_form_button.attr('disabled','disabled');
		avatar_spinner.show();
	}
}