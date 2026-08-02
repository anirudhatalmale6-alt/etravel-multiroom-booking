<?php

defined( 'ABSPATH' ) || exit;

final class ETravel_MR_Party {
    public static function from_request( array $request ): array|WP_Error {
        $max_rooms    = absint( ETravel_MR_Settings::get( 'max_rooms', 6 ) );
        $max_adults   = absint( ETravel_MR_Settings::get( 'max_adults_per_room', 8 ) );
        $max_children = absint( ETravel_MR_Settings::get( 'max_children_per_room', 6 ) );
        $room_count   = min( $max_rooms, max( 1, absint( $request['etr_rooms'] ?? 1 ) ) );
        $raw_rooms    = isset( $request['etr_party'] ) && is_array( $request['etr_party'] ) ? $request['etr_party'] : [];
        $rooms        = [];

        for ( $index = 0; $index < $room_count; $index++ ) {
            $raw      = isset( $raw_rooms[ $index ] ) && is_array( $raw_rooms[ $index ] ) ? $raw_rooms[ $index ] : [];
            $adults   = min( $max_adults, max( 1, absint( $raw['adults'] ?? 1 ) ) );
            $children = min( $max_children, max( 0, absint( $raw['children'] ?? 0 ) ) );
            $ages     = [];

            if ( ! empty( ETravel_MR_Settings::get( 'collect_child_ages', 1 ) ) && $children > 0 ) {
                $raw_ages = isset( $raw['ages'] ) && is_array( $raw['ages'] ) ? $raw['ages'] : [];
                for ( $child = 0; $child < $children; $child++ ) {
                    // R3.1: age must be explicitly chosen. Empty = the "Select age" placeholder
                    // (rejected); "0" is the explicit "Under 1" choice.
                    $raw_age = isset( $raw_ages[ $child ] ) ? trim( (string) $raw_ages[ $child ] ) : '';
                    if ( '' === $raw_age || ! is_numeric( $raw_age ) ) {
                        return new WP_Error( 'etr_age_required', __( 'Please select an age for every child (choose “Under 1” for infants).', 'etravel-multiroom' ) );
                    }
                    $ages[] = min( 17, max( 0, absint( $raw_age ) ) ); // 0 = "Under 1"
                }
            }

            $rooms[] = [
                'room'     => $index + 1,
                'adults'   => $adults,
                'children' => $children,
                'ages'     => $ages,
            ];
        }

        if ( empty( $rooms ) ) {
            return new WP_Error( 'etr_no_rooms', __( 'At least one room is required.', 'etravel-multiroom' ) );
        }

        return [
            'rooms'          => $rooms,
            'room_count'     => count( $rooms ),
            'total_adults'   => array_sum( array_column( $rooms, 'adults' ) ),
            'total_children' => array_sum( array_column( $rooms, 'children' ) ),
            'total_guests'   => array_sum( array_column( $rooms, 'adults' ) ) + array_sum( array_column( $rooms, 'children' ) ),
        ];
    }

    public static function encode( array $party ): array {
        $json      = wp_json_encode( $party, JSON_UNESCAPED_SLASHES );
        $payload   = self::base64url_encode( (string) $json );
        $signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        return [ $payload, $signature ];
    }

    public static function decode( string $payload, string $signature ): array|WP_Error {
        if ( '' === $payload || '' === $signature ) {
            return new WP_Error( 'etr_missing_party', __( 'The room request is missing.', 'etravel-multiroom' ) );
        }

        $expected = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'etr_invalid_party', __( 'The room request could not be verified.', 'etravel-multiroom' ) );
        }

        $decoded = self::base64url_decode( $payload );
        $data    = json_decode( $decoded, true );
        if ( ! is_array( $data ) || empty( $data['rooms'] ) ) {
            return new WP_Error( 'etr_invalid_party_data', __( 'The room request is invalid.', 'etravel-multiroom' ) );
        }

        return $data;
    }

    /**
     * R3.1: the plan is resolved from the opaque server-side token only.
     * Nothing sensitive (occupancy, child ages) is read from the URL or an
     * encoded cookie.
     */
    public static function from_query_or_cookie(): array|WP_Error {
        $token = ETravel_MR_Token::token_from_request();
        if ( '' !== $token ) {
            return ETravel_MR_Token::party( $token );
        }
        return new WP_Error( 'etr_missing_party', __( 'The room request is missing or has expired.', 'etravel-multiroom' ) );
    }

    /** Persist a party server-side and return the opaque token. */
    public static function store( array $party ): string {
        $token = ETravel_MR_Token::create( $party );
        ETravel_MR_Token::set_cookie( $token );
        return $token;
    }

    private static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private static function base64url_decode( string $data ): string {
        $remainder = strlen( $data ) % 4;
        if ( $remainder ) {
            $data .= str_repeat( '=', 4 - $remainder );
        }
        $decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
        return false === $decoded ? '' : $decoded;
    }
}
