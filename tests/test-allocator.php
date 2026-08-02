<?php
// Pure allocation smoke test; run: php tests/test-allocator.php
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once dirname( __DIR__ ) . '/includes/class-etravel-mr-allocator.php';

$candidates = [
    0 => [
        [ 'type_id' => 10, 'available_units' => 2, 'price' => 100.0, 'capacity_waste' => 0 ],
        [ 'type_id' => 20, 'available_units' => 1, 'price' => 140.0, 'capacity_waste' => 1 ],
    ],
    1 => [
        [ 'type_id' => 10, 'available_units' => 2, 'price' => 100.0, 'capacity_waste' => 0 ],
        [ 'type_id' => 30, 'available_units' => 1, 'price' => 120.0, 'capacity_waste' => 0 ],
    ],
];

$solutions = ETravel_MR_Allocator::allocate( $candidates, 10 );
if ( count( $solutions ) < 2 ) {
    fwrite( STDERR, "Expected multiple solutions.\n" );
    exit( 1 );
}
if ( $solutions[0]['total_price'] !== 200.0 ) {
    fwrite( STDERR, "Expected cheapest solution to cost 200.\n" );
    exit( 1 );
}

$insufficient = [
    0 => [ [ 'type_id' => 10, 'available_units' => 1, 'price' => 100.0, 'capacity_waste' => 0 ] ],
    1 => [ [ 'type_id' => 10, 'available_units' => 1, 'price' => 100.0, 'capacity_waste' => 0 ] ],
];
$only = ETravel_MR_Allocator::allocate( $insufficient, 10 );
if ( ! empty( $only ) ) {
    fwrite( STDERR, "Expected no solution when only one unit is available for two rooms.\n" );
    exit( 1 );
}

echo "Allocator tests passed.\n";
