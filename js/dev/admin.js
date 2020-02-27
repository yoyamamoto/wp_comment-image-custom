(function ( $ ) {
	"use strict";
	$(function () {
		if ( 0 === $( '#disable_comment_images' ).length ) {
			return;
		}
		// Setup an event handler so we can notify the user whether or not the file type is valid
		$( '#comment_image_toggle' ).on( 'click', function () {
			if ( confirm( cm_imgs.toggleConfirm ) ) {
				$( this).attr( 'disabled', 'disabled' );
				$( '#comment_image_source' ).val( 'button' );
				$( '#publish' ).trigger( 'click' );
			}
		});
	});
})( jQuery );
