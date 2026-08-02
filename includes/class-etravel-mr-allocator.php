<?php

defined( 'ABSPATH' ) || exit;

final class ETravel_MR_Allocator {
    /**
     * @param array<int,array<int,array<string,mixed>>> $candidates_by_room
     * @return array<int,array<string,mixed>>
     */
    public static function allocate( array $candidates_by_room, int $limit = 12 ): array {
        if ( empty( $candidates_by_room ) ) {
            return [];
        }

        foreach ( $candidates_by_room as $candidates ) {
            if ( empty( $candidates ) ) {
                return [];
            }
        }

        $order = array_keys( $candidates_by_room );
        usort(
            $order,
            static fn( int $a, int $b ): int => count( $candidates_by_room[ $a ] ) <=> count( $candidates_by_room[ $b ] )
        );

        $solutions = [];
        self::walk( $order, 0, $candidates_by_room, [], [], $solutions, max( $limit * 20, 100 ) );

        foreach ( $solutions as &$solution ) {
            ksort( $solution['assignments'] );
            $solution['assignments'] = array_values( $solution['assignments'] );
            $solution['signature']   = implode( '-', array_map( static fn( array $a ): string => (string) $a['type_id'], $solution['assignments'] ) );
        }
        unset( $solution );

        $unique = [];
        foreach ( $solutions as $solution ) {
            $key = $solution['signature'];
            if ( ! isset( $unique[ $key ] ) || self::compare( $solution, $unique[ $key ] ) < 0 ) {
                $unique[ $key ] = $solution;
            }
        }

        $solutions = array_values( $unique );
        usort( $solutions, [ __CLASS__, 'compare' ] );
        return array_slice( $solutions, 0, max( 1, $limit ) );
    }

    private static function walk(
        array $order,
        int $position,
        array $candidates_by_room,
        array $usage,
        array $assignments,
        array &$solutions,
        int $hard_limit
    ): void {
        if ( count( $solutions ) >= $hard_limit ) {
            return;
        }

        if ( $position >= count( $order ) ) {
            $total_price = 0.0;
            $known_price = true;
            $waste       = 0;
            foreach ( $assignments as $assignment ) {
                if ( null === $assignment['price'] ) {
                    $known_price = false;
                } else {
                    $total_price += (float) $assignment['price'];
                }
                $waste += (int) $assignment['capacity_waste'];
            }
            $solutions[] = [
                'assignments' => $assignments,
                'total_price' => $known_price ? $total_price : null,
                'waste'       => $waste,
            ];
            return;
        }

        $room_index = $order[ $position ];
        foreach ( $candidates_by_room[ $room_index ] as $candidate ) {
            $type_id = (int) $candidate['type_id'];
            $used    = (int) ( $usage[ $type_id ] ?? 0 );
            $limit   = max( 0, (int) $candidate['available_units'] );
            if ( $used >= $limit ) {
                continue;
            }

            $next_usage             = $usage;
            $next_usage[ $type_id ] = $used + 1;
            $next_assignments       = $assignments;
            $next_assignments[ $room_index ] = $candidate;
            self::walk( $order, $position + 1, $candidates_by_room, $next_usage, $next_assignments, $solutions, $hard_limit );
        }
    }

    private static function compare( array $a, array $b ): int {
        $a_known = null !== $a['total_price'];
        $b_known = null !== $b['total_price'];
        if ( $a_known && $b_known && (float) $a['total_price'] !== (float) $b['total_price'] ) {
            return (float) $a['total_price'] <=> (float) $b['total_price'];
        }
        if ( $a_known !== $b_known ) {
            return $a_known ? -1 : 1;
        }
        return (int) $a['waste'] <=> (int) $b['waste'];
    }
}
