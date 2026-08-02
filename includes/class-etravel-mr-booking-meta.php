<?php

defined( 'ABSPATH' ) || exit;

final class ETravel_MR_Booking_Meta {
	public static function init(): void {
		// Bind the plan early; validate once reserved rooms are persisted (late save,
		// on confirmation, or when the booking is opened in admin — whichever first).
		add_action( 'save_post_mphb_booking', [ __CLASS__, 'bind_request' ], 20, 3 );
		add_action( 'save_post_mphb_booking', [ __CLASS__, 'validate_request' ], 99, 3 );
		add_action( 'mphb_booking_confirmed', [ __CLASS__, 'validate_on_confirm' ] );
		add_action( 'add_meta_boxes_mphb_booking', [ __CLASS__, 'meta_box' ] );
	}

	/**
	 * R3.1: bind the opaque token's plan to THIS booking id and consume the token
	 * (single use, server-side plan cleared). A stale cookie cannot attach to a
	 * booking that already carries a bound plan.
	 */
	public static function bind_request( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || 'mphb_booking' !== $post->post_type ) {
			return;
		}
		if ( get_post_meta( $post_id, '_etravel_multiroom_token', true ) ) {
			return; // already bound to this booking
		}

		$token = ETravel_MR_Token::token_from_request();
		if ( '' === $token ) {
			return; // not a multi-room booking
		}

		$record = ETravel_MR_Token::record( $token );
		if ( is_wp_error( $record ) ) {
			// Expired / consumed / tampered token on a multi-room attempt.
			update_post_meta( $post_id, '_etravel_multiroom_rejected', array( 'Invalid room request token: ' . $record->get_error_message() ) );
			self::reject( $post_id );
			return;
		}

		$party = (array) $record['party'];
		update_post_meta( $post_id, '_etravel_multiroom_request', $party );
		update_post_meta( $post_id, '_etravel_multiroom_requested_units', (int) $party['room_count'] );
		update_post_meta( $post_id, '_etravel_multiroom_token', $token );

		// One-time use: bind to this booking id, then clear the server-side plan + cookie.
		ETravel_MR_Token::consume( $token, $post_id );
	}

	/**
	 * R3.1: server-side validation of the actual reserved rooms against the bound
	 * plan. Runs at a late priority so reserved rooms are already persisted.
	 */
	public static function validate_request( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || 'mphb_booking' !== $post->post_type ) {
			return;
		}
		self::maybe_validate( $post_id );
	}

	public static function validate_on_confirm( $booking ): void {
		$id = is_object( $booking ) && method_exists( $booking, 'getId' ) ? (int) $booking->getId() : 0;
		if ( $id ) {
			self::maybe_validate( $id );
		}
	}

	/**
	 * Validate the reserved rooms against the bound plan, once the reserved rooms
	 * exist. Safe to call repeatedly; only acts once.
	 */
	public static function maybe_validate( int $post_id ): void {
		$party = get_post_meta( $post_id, '_etravel_multiroom_request', true );
		if ( ! is_array( $party ) || empty( $party['rooms'] ) ) {
			return; // not a bound multi-room booking
		}
		if ( get_post_meta( $post_id, '_etravel_multiroom_validated', true ) ) {
			return;
		}
		$booking = function_exists( 'MPHB' ) ? MPHB()->getBookingRepository()->findById( $post_id ) : null;
		if ( ! $booking ) {
			return;
		}
		// Reserved rooms are attached slightly after the initial booking insert.
		// Skip (do not mark validated) until they exist, so validation runs against
		// the real reservation, not an empty early save.
		$reserved = method_exists( $booking, 'getReservedRooms' ) ? (array) $booking->getReservedRooms() : array();
		if ( empty( $reserved ) ) {
			return;
		}
		$errors = ETravel_MR_Validator::validate( $booking, $party );
		update_post_meta( $post_id, '_etravel_multiroom_validated', 1 );

		if ( ! empty( $errors ) ) {
			update_post_meta( $post_id, '_etravel_multiroom_rejected', $errors );
			self::reject( $post_id );
		} else {
			delete_post_meta( $post_id, '_etravel_multiroom_rejected' );
		}
	}

	/** Cancel an invalid multi-room booking server-side (never silently confirm it). */
	private static function reject( int $post_id ): void {
		remove_action( 'save_post_mphb_booking', array( __CLASS__, 'bind_request' ), 20 );
		remove_action( 'save_post_mphb_booking', array( __CLASS__, 'validate_request' ), 99 );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'cancelled' ) );
	}

	public static function meta_box(): void {
		add_meta_box(
			'etravel-multiroom-request',
			__( 'eTravel room request', 'etravel-multiroom' ),
			[ __CLASS__, 'render_meta_box' ],
			'mphb_booking',
			'side',
			'default'
		);
	}

	public static function render_meta_box( WP_Post $post ): void {
		// On-demand validation: reserved rooms are guaranteed to exist by the time
		// the booking is opened in admin.
		self::maybe_validate( $post->ID );
		$rejected = get_post_meta( $post->ID, '_etravel_multiroom_rejected', true );
		if ( is_array( $rejected ) && $rejected ) {
			echo '<p style="color:#9c2b24;font-weight:700">' . esc_html__( 'This multi-room request was rejected by server-side validation:', 'etravel-multiroom' ) . '</p><ul style="list-style:disc;margin-left:18px">';
			foreach ( $rejected as $reason ) {
				echo '<li>' . esc_html( (string) $reason ) . '</li>';
			}
			echo '</ul>';
		}

		$party = get_post_meta( $post->ID, '_etravel_multiroom_request', true );
		if ( ! is_array( $party ) || empty( $party['rooms'] ) ) {
			echo '<p>' . esc_html__( 'No eTravel room-by-room request was stored for this booking.', 'etravel-multiroom' ) . '</p>';
			return;
		}
		$token = get_post_meta( $post->ID, '_etravel_multiroom_token', true );
		echo '<ol>';
		foreach ( $party['rooms'] as $room ) {
			echo '<li><strong>' . esc_html( sprintf( __( 'Room %d', 'etravel-multiroom' ), (int) $room['room'] ) ) . '</strong><br>';
			echo esc_html( sprintf( __( '%1$d adults, %2$d children', 'etravel-multiroom' ), (int) $room['adults'], (int) $room['children'] ) );
			if ( ! empty( $room['ages'] ) ) {
				$ages = array_map( static fn( $a ): string => 0 === (int) $a ? __( 'Under 1', 'etravel-multiroom' ) : (string) (int) $a, $room['ages'] );
				echo '<br><small>' . esc_html( sprintf( __( 'Ages: %s', 'etravel-multiroom' ), implode( ', ', $ages ) ) ) . '</small>';
			}
			echo '</li>';
		}
		echo '</ol>';
		if ( $token ) {
			echo '<p><small>' . esc_html( sprintf( __( 'Request token: %s (bound, consumed)', 'etravel-multiroom' ), substr( (string) $token, 0, 12 ) . '…' ) ) . '</small></p>';
		}
	}
}
