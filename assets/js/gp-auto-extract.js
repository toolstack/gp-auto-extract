jQuery( document ).ready( function() {
	var toggle_group = function( value ) {
		var group = jQuery( value ).data( 'group' );
		if ( jQuery( value ).is( ':checked' ) ) {
			jQuery( '.group-' + group ).removeClass( 'hidden' );
		} else {
			jQuery( '.group-' + group ).addClass( 'hidden' );
		}
	};

	jQuery( '.editinline' ).click( function() {
		var project_id = jQuery( this ).data( 'project-id' );
		jQuery( '#project-' + project_id ).addClass( 'hidden' );
		jQuery( '#edit-project-' + project_id ).removeClass( 'hidden' );
		jQuery( '.group-toggle' ).each( function( index, value ) {
			toggle_group( value );
			jQuery( '.source_type' ).change();
		} );
	} );

	jQuery( '.inline-edit-save .cancel' ).click( function() {
		var project_id = jQuery( this ).data( 'project-id' );
		jQuery( '#project-' + project_id ).removeClass( 'hidden' );
		jQuery( '#edit-project-' + project_id ).addClass( 'hidden' );
	} );

	jQuery( '.group-toggle' ).click( function() {
		toggle_group( this );
	} );

	jQuery( '.source_type' ).change( function() {
		var $tr = jQuery( this ).closest( 'tr' );
		$tr.removeClass( 'source-type-none' );
		$tr.removeClass( 'source-type-github' );
		$tr.removeClass( 'source-type-wordpress' );
		$tr.removeClass( 'source-type-custom' );

		var source_type = jQuery( this ).val();
		$tr.addClass( 'source-type-' + source_type );

		var $setting = jQuery( '.gpae-setting', $tr );
		var $password = jQuery( '.gpae-password', $tr );
		$setting.attr( 'placeholder', gpae.settings[source_type] );
		$password.attr( 'placeholder', gpae.passwords[source_type] );
	} );

	jQuery( '.extract-project, .reset-project' ).click( function() {
		var $form = jQuery( 'form#gp-auto-extract' );

		var $field = jQuery( '<input></input>' );
		$field.attr( 'type', 'hidden' );
		$field.attr( 'name', this.id );
		$field.attr( 'value', 1 );

		$form.append( $field );

		$form.submit();
	} );
} );
