<?php

defined( 'ABSPATH' ) || exit;

final class ETravel_MR_MotoPress {
    public static function is_available(): bool {
        return function_exists( 'mphb_get_room_type' )
            && function_exists( 'mphb_get_available_rooms' )
            && function_exists( 'mphb_get_room_type_period_price' );
    }

    public static function build_candidates( array $party, DateTime $from, DateTime $to, string $destination_term = '' ): array|WP_Error {
        if ( ! self::is_available() ) {
            return new WP_Error( 'etr_motopress_missing', __( 'MotoPress Hotel Booking is unavailable.', 'etravel-multiroom' ) );
        }

        $room_type_ids = self::room_type_ids( $destination_term );
        if ( empty( $room_type_ids ) ) {
            return new WP_Error( 'etr_no_room_types', __( 'No accommodation types were found for this destination.', 'etravel-multiroom' ) );
        }

        $inventory = [];
        foreach ( $room_type_ids as $type_id ) {
            try {
                $room_type = mphb_get_room_type( (int) $type_id );
                if ( ! $room_type || 'publish' !== get_post_status( (int) $type_id ) ) {
                    continue;
                }

                $available_room_ids = mphb_get_available_rooms(
                    clone $from,
                    clone $to,
                    [ 'room_type_id' => (int) $type_id ]
                );
                // mphb_get_available_rooms() may return a flat list of room IDs OR a
                // map [ room_type_id => [ room_ids ] ]. Flatten and count DISTINCT
                // physical units so same-type multi-unit availability is correct.
                $flat = [];
                if ( is_array( $available_room_ids ) ) {
                    array_walk_recursive(
                        $available_room_ids,
                        static function ( $value ) use ( &$flat ): void {
                            $id = absint( $value );
                            if ( $id > 0 ) {
                                $flat[] = $id;
                            }
                        }
                    );
                }
                $available_units = count( array_unique( $flat ) );
                if ( $available_units < 1 ) {
                    continue;
                }

                $inventory[] = [
                    'type_id'          => (int) $type_id,
                    'title'            => (string) $room_type->getTitle(),
                    'link'             => (string) $room_type->getLink(),
                    'adult_capacity'   => max( 1, (int) $room_type->getAdultsCapacity() ),
                    'child_capacity'   => max( 0, (int) $room_type->getChildrenCapacity() ),
                    'total_capacity'   => max( 0, (int) $room_type->getTotalCapacity() ),
                    'limited_total'    => (bool) $room_type->hasLimitedTotalCapacity(),
                    'available_units'  => $available_units,
                    'room_type_object' => $room_type,
                ];
            } catch ( Throwable $error ) {
                do_action( 'etravel_multiroom_adapter_error', $error, $type_id );
            }
        }

        $candidates_by_room = [];
        foreach ( $party['rooms'] as $index => $room_request ) {
            $candidates_by_room[ $index ] = [];
            foreach ( $inventory as $item ) {
                if ( ! self::fits( $room_request, $item ) ) {
                    continue;
                }

                $price = self::price_for_room( $from, $to, $item['room_type_object'], $room_request );
                $capacity = $item['limited_total'] && $item['total_capacity'] > 0
                    ? $item['total_capacity']
                    : $item['adult_capacity'] + $item['child_capacity'];

                $candidates_by_room[ $index ][] = [
                    'room_index'      => $index,
                    'request'         => $room_request,
                    'type_id'         => $item['type_id'],
                    'title'           => $item['title'],
                    'link'            => $item['link'],
                    'available_units' => $item['available_units'],
                    'price'           => $price,
                    'capacity_waste'  => max( 0, $capacity - ( (int) $room_request['adults'] + (int) $room_request['children'] ) ),
                ];
            }
        }

        return $candidates_by_room;
    }

    private static function fits( array $request, array $type ): bool {
        $adults   = (int) $request['adults'];
        $children = (int) $request['children'];
        $total    = $adults + $children;

        if ( $adults < 1 || $adults > (int) $type['adult_capacity'] ) {
            return false;
        }
        if ( $children > (int) $type['child_capacity'] ) {
            return false;
        }
        if ( $type['limited_total'] && (int) $type['total_capacity'] > 0 && $total > (int) $type['total_capacity'] ) {
            return false;
        }
        return true;
    }

    private static function price_for_room( DateTime $from, DateTime $to, object $room_type, array $request ): ?float {
        try {
            $price = mphb_get_room_type_period_price(
                clone $from,
                clone $to,
                $room_type,
                [
                    'adults'   => (int) $request['adults'],
                    'children' => (int) $request['children'],
                ]
            );
            return is_numeric( $price ) && (float) $price > 0 ? (float) $price : null;
        } catch ( Throwable $error ) {
            do_action( 'etravel_multiroom_price_error', $error, $room_type, $request );
            return null;
        }
    }

    private static function room_type_ids( string $destination_term ): array {
        $args = [
            'post_type'              => 'mphb_room_type',
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        $taxonomy = sanitize_key( (string) ETravel_MR_Settings::get( 'destination_taxonomy', '' ) );
        if ( $destination_term && $taxonomy && taxonomy_exists( $taxonomy ) ) {
            $args['tax_query'] = [
                [
                    'taxonomy' => $taxonomy,
                    'field'    => 'slug',
                    'terms'    => sanitize_title( $destination_term ),
                ],
            ];
        }

        $args = (array) apply_filters( 'etravel_multiroom_room_type_query_args', $args, $destination_term );
        $ids  = get_posts( $args );
        return array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : [] ) ) );
    }
}
