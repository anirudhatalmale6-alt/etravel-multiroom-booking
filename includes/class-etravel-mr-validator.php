<?php
/**
 * R3.1: server-side validation of a created MotoPress booking against the
 * requested room plan. Runs independently of any front-end JavaScript.
 */

defined( 'ABSPATH' ) || exit;

final class ETravel_MR_Validator {
	/**
	 * Validate the booking's reserved rooms against the requested party plan.
	 * Returns an array of human-readable error strings (empty array = valid).
	 */
	public static function validate( object $booking, array $party ): array {
		$errors   = array();
		$reserved = method_exists( $booking, 'getReservedRooms' ) ? (array) $booking->getReservedRooms() : array();

		// 1. exact requested room count
		if ( count( $reserved ) !== (int) ( $party['room_count'] ?? 0 ) ) {
			$errors[] = sprintf( 'Room count mismatch: %d accommodation(s) booked vs %d requested.', count( $reserved ), (int) ( $party['room_count'] ?? 0 ) );
		}

		// 2. no repeated physical unit + 3. capacity + collect booked occupancy
		$unit_ids = array();
		$booked   = array();
		foreach ( $reserved as $rr ) {
			$unit    = method_exists( $rr, 'getRoomId' ) ? (int) $rr->getRoomId() : 0;
			$type_id = method_exists( $rr, 'getRoomTypeId' ) ? (int) $rr->getRoomTypeId() : 0;
			$adults  = method_exists( $rr, 'getAdults' ) ? (int) $rr->getAdults() : 0;
			$children = method_exists( $rr, 'getChildren' ) ? (int) $rr->getChildren() : 0;

			if ( $unit > 0 ) {
				if ( isset( $unit_ids[ $unit ] ) ) {
					$errors[] = sprintf( 'The same physical unit (#%d) was booked more than once.', $unit );
				}
				$unit_ids[ $unit ] = true;
			}

			if ( $type_id > 0 && function_exists( 'mphb_get_room_type' ) ) {
				$rt = mphb_get_room_type( $type_id );
				if ( $rt ) {
					if ( $adults > (int) $rt->getAdultsCapacity() ) {
						$errors[] = sprintf( 'Adults (%d) exceed capacity for accommodation #%d.', $adults, $type_id );
					}
					if ( $children > (int) $rt->getChildrenCapacity() ) {
						$errors[] = sprintf( 'Children (%d) exceed capacity for accommodation #%d.', $children, $type_id );
					}
				}
			}
			$booked[] = $adults . 'a' . $children . 'c';
		}

		// 4. per-room occupancy multiset must equal the requested plan (order-independent,
		//    so asymmetric occupancy stays attached to the correct accommodation regardless
		//    of checkout display order).
		$requested = array();
		foreach ( (array) ( $party['rooms'] ?? array() ) as $room ) {
			$requested[] = (int) $room['adults'] . 'a' . (int) $room['children'] . 'c';
		}
		$booked_sorted = $booked;
		sort( $booked_sorted );
		sort( $requested );
		if ( $booked_sorted !== $requested ) {
			$errors[] = 'Per-room occupancy does not match the requested plan (requested ' . implode( ', ', $requested ) . '; booked ' . implode( ', ', $booked ) . ').';
		}

		return $errors;
	}
}
