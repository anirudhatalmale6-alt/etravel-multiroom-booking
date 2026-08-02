<?php

defined( 'ABSPATH' ) || exit;

final class ETravel_MR_Frontend {
    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'shortcodes' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'admin_post_etravel_multiroom_search', [ __CLASS__, 'handle_search' ] );
        add_action( 'admin_post_nopriv_etravel_multiroom_search', [ __CLASS__, 'handle_search' ] );
        add_filter( 'post_class', [ __CLASS__, 'room_type_post_class' ], 10, 3 );
        add_action( 'mphb_checkout_form_after_start', [ __CLASS__, 'checkout_summary' ], 5 );
    }

    public static function shortcodes(): void {
        add_shortcode( 'etravel_multiroom_search', [ __CLASS__, 'search_shortcode' ] );
        add_shortcode( 'etravel_multiroom_plan', [ __CLASS__, 'plan_shortcode' ] );
        add_shortcode( 'etravel_multiroom_summary', [ __CLASS__, 'summary_shortcode' ] );
    }

    public static function assets(): void {
        if ( is_admin() ) {
            return;
        }

        wp_enqueue_style( 'etravel-multiroom', ETR_MR_URL . 'assets/css/multiroom.css', [], ETR_MR_VERSION );
        wp_enqueue_script( 'etravel-multiroom', ETR_MR_URL . 'assets/js/multiroom.js', [], ETR_MR_VERSION, true );

        $party = ETravel_MR_Party::from_query_or_cookie();
        $combo = self::selected_combo();
        wp_localize_script(
            'etravel-multiroom',
            'etravelMultiroom',
            [
                'party'        => is_wp_error( $party ) ? null : $party,
                'combo'        => $combo,
                'maxRooms'     => absint( ETravel_MR_Settings::get( 'max_rooms', 6 ) ),
                'collectAges'  => (bool) ETravel_MR_Settings::get( 'collect_child_ages', 1 ),
                'enforceCount' => (bool) ETravel_MR_Settings::get( 'enforce_checkout_room_count', 0 ),
                'labels'       => [
                    'childAge'       => __( 'Age of child', 'etravel-multiroom' ),
                    'selectAge'      => __( 'Select age', 'etravel-multiroom' ),
                    'under1'         => __( 'Under 1', 'etravel-multiroom' ),
                    'requiredUnits'  => __( 'Required units', 'etravel-multiroom' ),
                    'checkoutCount'  => __( 'The number of selected accommodations does not match your room request.', 'etravel-multiroom' ),
                ],
            ]
        );
    }

    public static function search_shortcode( array $atts = [] ): string {
        $atts = shortcode_atts(
            [
                'destination'      => '',
                'destination_term' => '',
                'results_url'      => (string) ETravel_MR_Settings::get( 'results_page_url', '' ),
                'rooms'            => 1,
            ],
            $atts,
            'etravel_multiroom_search'
        );

        $max_rooms      = absint( ETravel_MR_Settings::get( 'max_rooms', 6 ) );
        $max_adults     = absint( ETravel_MR_Settings::get( 'max_adults_per_room', 8 ) );
        $max_children   = absint( ETravel_MR_Settings::get( 'max_children_per_room', 6 ) );
        $default_rooms  = min( $max_rooms, max( 1, absint( $atts['rooms'] ) ) );
        $results_url    = $atts['results_url'] ? esc_url( $atts['results_url'] ) : esc_url( home_url( '/hotels-accommodations/' ) );
        $check_in       = wp_date( 'Y-m-d', strtotime( '+30 days', current_time( 'timestamp' ) ) );
        $check_out      = wp_date( 'Y-m-d', strtotime( '+32 days', current_time( 'timestamp' ) ) );
        $form_id        = wp_unique_id( 'etr-mr-' );

        ob_start();
        ?>
        <form id="<?php echo esc_attr( $form_id ); ?>" class="etr-mr-search" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-etr-mr-search>
            <input type="hidden" name="action" value="etravel_multiroom_search">
            <input type="hidden" name="results_url" value="<?php echo esc_attr( $results_url ); ?>">
            <input type="hidden" name="destination_term" value="<?php echo esc_attr( sanitize_title( $atts['destination_term'] ) ); ?>">
            <?php wp_nonce_field( 'etravel_multiroom_search', 'etr_mr_nonce' ); ?>

            <div class="etr-mr-primary-fields">
                <div class="etr-mr-field etr-mr-field--destination">
                    <label for="<?php echo esc_attr( $form_id ); ?>-destination"><?php esc_html_e( 'Destination', 'etravel-multiroom' ); ?></label>
                    <input id="<?php echo esc_attr( $form_id ); ?>-destination" name="destination" type="text" value="<?php echo esc_attr( (string) $atts['destination'] ); ?>" <?php wp_readonly( '' !== $atts['destination'] ); ?>>
                </div>
                <div class="etr-mr-field">
                    <label for="<?php echo esc_attr( $form_id ); ?>-in"><?php esc_html_e( 'Check-in', 'etravel-multiroom' ); ?></label>
                    <input id="<?php echo esc_attr( $form_id ); ?>-in" name="check_in" type="date" value="<?php echo esc_attr( $check_in ); ?>" min="<?php echo esc_attr( wp_date( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) ) ); ?>" required data-etr-mr-checkin>
                </div>
                <div class="etr-mr-field">
                    <label for="<?php echo esc_attr( $form_id ); ?>-out"><?php esc_html_e( 'Check-out', 'etravel-multiroom' ); ?></label>
                    <input id="<?php echo esc_attr( $form_id ); ?>-out" name="check_out" type="date" value="<?php echo esc_attr( $check_out ); ?>" min="<?php echo esc_attr( $check_out ); ?>" required data-etr-mr-checkout>
                </div>
                <div class="etr-mr-field">
                    <label for="<?php echo esc_attr( $form_id ); ?>-rooms"><?php esc_html_e( 'Rooms', 'etravel-multiroom' ); ?></label>
                    <select id="<?php echo esc_attr( $form_id ); ?>-rooms" name="etr_rooms" data-etr-mr-room-count>
                        <?php for ( $i = 1; $i <= $max_rooms; $i++ ) : ?>
                            <option value="<?php echo esc_attr( (string) $i ); ?>" <?php selected( $default_rooms, $i ); ?>><?php echo esc_html( (string) $i ); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="etr-mr-room-list" data-etr-mr-room-list>
                <?php for ( $room = 0; $room < $max_rooms; $room++ ) : ?>
                    <fieldset class="etr-mr-room" data-etr-mr-room="<?php echo esc_attr( (string) $room ); ?>" <?php echo $room >= $default_rooms ? 'hidden' : ''; ?>>
                        <legend><?php echo esc_html( sprintf( __( 'Room %d', 'etravel-multiroom' ), $room + 1 ) ); ?></legend>
                        <div class="etr-mr-room-fields">
                            <div class="etr-mr-field">
                                <label for="<?php echo esc_attr( $form_id . '-a-' . $room ); ?>"><?php esc_html_e( 'Adults', 'etravel-multiroom' ); ?></label>
                                <select id="<?php echo esc_attr( $form_id . '-a-' . $room ); ?>" name="etr_party[<?php echo esc_attr( (string) $room ); ?>][adults]">
                                    <?php for ( $a = 1; $a <= $max_adults; $a++ ) : ?><option value="<?php echo esc_attr( (string) $a ); ?>" <?php selected( 2, $a ); ?>><?php echo esc_html( (string) $a ); ?></option><?php endfor; ?>
                                </select>
                            </div>
                            <div class="etr-mr-field">
                                <label for="<?php echo esc_attr( $form_id . '-c-' . $room ); ?>"><?php esc_html_e( 'Children', 'etravel-multiroom' ); ?></label>
                                <select id="<?php echo esc_attr( $form_id . '-c-' . $room ); ?>" name="etr_party[<?php echo esc_attr( (string) $room ); ?>][children]" data-etr-mr-children>
                                    <?php for ( $c = 0; $c <= $max_children; $c++ ) : ?><option value="<?php echo esc_attr( (string) $c ); ?>"><?php echo esc_html( (string) $c ); ?></option><?php endfor; ?>
                                </select>
                            </div>
                            <div class="etr-mr-child-ages" data-etr-mr-ages aria-live="polite"></div>
                        </div>
                    </fieldset>
                <?php endfor; ?>
            </div>

            <div class="etr-mr-search-footer">
                <p class="etr-mr-total" data-etr-mr-total></p>
                <button type="submit" class="etr-mr-submit"><?php esc_html_e( 'Find matching stays', 'etravel-multiroom' ); ?></button>
            </div>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public static function handle_search(): void {
        if ( ! isset( $_POST['etr_mr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['etr_mr_nonce'] ) ), 'etravel_multiroom_search' ) ) {
            wp_die( esc_html__( 'The search request could not be verified.', 'etravel-multiroom' ), 403 );
        }

        $party = ETravel_MR_Party::from_request( wp_unslash( $_POST ) );
        if ( is_wp_error( $party ) ) {
            wp_die( esc_html( $party->get_error_message() ), 400 );
        }

        $check_in  = self::valid_date( sanitize_text_field( wp_unslash( $_POST['check_in'] ?? '' ) ) );
        $check_out = self::valid_date( sanitize_text_field( wp_unslash( $_POST['check_out'] ?? '' ) ) );
        if ( ! $check_in || ! $check_out || $check_out <= $check_in ) {
            wp_die( esc_html__( 'Please select valid check-in and check-out dates.', 'etravel-multiroom' ), 400 );
        }

        // R3.1: store the plan server-side and expose only an opaque token.
        $token = ETravel_MR_Party::store( $party );

        $default_url = (string) ETravel_MR_Settings::get( 'results_page_url', home_url( '/hotels-accommodations/' ) );
        $results_url = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['results_url'] ?? '' ) ), $default_url ?: home_url( '/hotels-accommodations/' ) );
        $destination = sanitize_text_field( wp_unslash( $_POST['destination'] ?? '' ) );
        $term        = sanitize_title( wp_unslash( $_POST['destination_term'] ?? '' ) );

        $query = [
            'mphb_check_in_date'  => $check_in,
            'mphb_check_out_date' => $check_out,
            'mphb_adults'         => (int) $party['total_adults'],
            'mphb_children'       => (int) $party['total_children'],
            'etr_rooms'           => (int) $party['room_count'],
            'etr_token'           => $token,
            'etr_destination'     => $destination,
            'etr_destination_term'=> $term,
        ];

        $query = (array) apply_filters( 'etravel_multiroom_native_query_args', $query, $destination, $term, $party );
        $target = add_query_arg( $query, $results_url );
        wp_safe_redirect( $target );
        exit;
    }

    public static function plan_shortcode(): string {
        $party = ETravel_MR_Party::from_query_or_cookie();
        if ( is_wp_error( $party ) ) {
            return '';
        }

        $check_in  = self::valid_date( sanitize_text_field( wp_unslash( $_GET['mphb_check_in_date'] ?? '' ) ) );
        $check_out = self::valid_date( sanitize_text_field( wp_unslash( $_GET['mphb_check_out_date'] ?? '' ) ) );
        if ( ! $check_in || ! $check_out || $check_out <= $check_in ) {
            return '<div class="etr-mr-notice etr-mr-notice--warning">' . esc_html__( 'Select valid dates to calculate exact multi-room options.', 'etravel-multiroom' ) . '</div>';
        }

        try {
            $from = new DateTime( $check_in );
            $to   = new DateTime( $check_out );
        } catch ( Exception ) {
            return '';
        }

        $destination_term = sanitize_title( wp_unslash( $_GET['etr_destination_term'] ?? '' ) );
        $candidate_result = ETravel_MR_MotoPress::build_candidates( $party, $from, $to, $destination_term );
        if ( is_wp_error( $candidate_result ) ) {
            return '<section class="etr-mr-plan etr-mr-plan--empty"><h2>' . esc_html__( 'Your room request', 'etravel-multiroom' ) . '</h2><p>' . esc_html( $candidate_result->get_error_message() ) . '</p>' . self::party_html( $party ) . '</section>';
        }

        $limit     = absint( ETravel_MR_Settings::get( 'max_combinations', 12 ) );
        $solutions = ETravel_MR_Allocator::allocate( $candidate_result, $limit );
        // R3.1: the chosen plan is kept server-side against the opaque token, not in the URL.
        $token     = ETravel_MR_Token::token_from_request();
        $use_index = isset( $_GET['etr_use_plan'] ) ? absint( $_GET['etr_use_plan'] ) : -1;
        $selected  = ETravel_MR_Token::combo( $token );

        ob_start();
        ?>
        <section class="etr-mr-plan" data-etr-mr-plan>
            <div class="etr-mr-plan__header">
                <div>
                    <p class="etr-mr-eyebrow"><?php esc_html_e( 'Exact room planning', 'etravel-multiroom' ); ?></p>
                    <h2><?php echo esc_html( sprintf( _n( '%d-room request', '%d-room request', (int) $party['room_count'], 'etravel-multiroom' ), (int) $party['room_count'] ) ); ?></h2>
                </div>
                <?php echo self::party_html( $party ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>

            <?php if ( empty( $solutions ) ) : ?>
                <div class="etr-mr-notice etr-mr-notice--warning">
                    <strong><?php esc_html_e( 'No exact combination is available for these dates and room-by-room occupancies.', 'etravel-multiroom' ); ?></strong>
                    <p><?php esc_html_e( 'Try different dates, add a room, reduce a room’s occupancy, or contact eTravel support for a custom arrangement.', 'etravel-multiroom' ); ?></p>
                </div>
            <?php else : ?>
                <p><?php esc_html_e( 'These plans use real available MotoPress units and each requested room’s adult/child capacity. Prices are estimates; the MotoPress checkout remains the final source for rates, taxes, fees and policies.', 'etravel-multiroom' ); ?></p>
                <div class="etr-mr-solutions">
                    <?php foreach ( array_slice( $solutions, 0, 5 ) as $solution_index => $solution ) :
                        $combo_data = [
                            'assignments' => array_map(
                                static fn( array $a ): array => [
                                    'room'     => (int) $a['request']['room'],
                                    'type_id'  => (int) $a['type_id'],
                                    'title'    => (string) $a['title'],
                                    'adults'   => (int) $a['request']['adults'],
                                    'children' => (int) $a['request']['children'],
                                ],
                                $solution['assignments']
                            ),
                        ];
                        if ( $solution_index === $use_index && '' !== $token ) {
                            ETravel_MR_Token::set_combo( $token, $combo_data );
                            $selected = $combo_data;
                        }
                        $use_url = add_query_arg( [ 'etr_use_plan' => $solution_index ], self::current_url_without_combo() );
                        $is_selected = $selected && wp_json_encode( $selected ) === wp_json_encode( $combo_data );
                        ?>
                        <article class="etr-mr-solution <?php echo $is_selected ? 'is-selected' : ''; ?>">
                            <div class="etr-mr-solution__title">
                                <h3><?php echo esc_html( sprintf( __( 'Option %d', 'etravel-multiroom' ), $solution_index + 1 ) ); ?></h3>
                                <?php if ( null !== $solution['total_price'] ) : ?>
                                    <strong><?php echo wp_kses_post( function_exists( 'mphb_format_price' ) ? mphb_format_price( (float) $solution['total_price'] ) : number_format_i18n( (float) $solution['total_price'], 2 ) ); ?> <small><?php esc_html_e( 'estimated accommodation subtotal', 'etravel-multiroom' ); ?></small></strong>
                                <?php endif; ?>
                            </div>
                            <ol>
                                <?php foreach ( $solution['assignments'] as $assignment ) : ?>
                                    <li>
                                        <span><?php echo esc_html( sprintf( __( 'Room %1$d: %2$d adults, %3$d children', 'etravel-multiroom' ), (int) $assignment['request']['room'], (int) $assignment['request']['adults'], (int) $assignment['request']['children'] ) ); ?></span>
                                        <a href="<?php echo esc_url( (string) $assignment['link'] ); ?>"><?php echo esc_html( (string) $assignment['title'] ); ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <a class="etr-mr-use-plan" href="<?php echo esc_url( $use_url ); ?>"><?php echo $is_selected ? esc_html__( 'Selected plan', 'etravel-multiroom' ) : esc_html__( 'Use this room plan', 'etravel-multiroom' ); ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="etr-mr-notice">
                    <strong><?php esc_html_e( 'Booking step:', 'etravel-multiroom' ); ?></strong>
                    <?php esc_html_e( 'Use MotoPress’s Recommended section when it matches the selected plan, or add the highlighted accommodation types below to one reservation before clicking Confirm Reservation.', 'etravel-multiroom' ); ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function summary_shortcode(): string {
        $party = ETravel_MR_Party::from_query_or_cookie();
        return is_wp_error( $party ) ? '' : self::party_html( $party );
    }

    public static function checkout_summary(): void {
        $party = ETravel_MR_Party::from_query_or_cookie();
        if ( is_wp_error( $party ) ) {
            return;
        }
        echo '<section class="etr-mr-checkout-request" data-etr-mr-checkout-request><h3>' . esc_html__( 'Your requested room arrangement', 'etravel-multiroom' ) . '</h3>';
        echo self::party_html( $party ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<p data-etr-mr-checkout-warning hidden></p></section>';
    }

    public static function room_type_post_class( array $classes, array $class, int $post_id ): array {
        if ( 'mphb_room_type' === get_post_type( $post_id ) ) {
            $classes[] = 'etr-mr-room-type-' . absint( $post_id );
            $classes[] = 'etr-mr-room-type-card';
        }
        return $classes;
    }

    private static function party_html( array $party ): string {
        ob_start();
        ?>
        <ul class="etr-mr-party-summary">
            <?php foreach ( $party['rooms'] as $room ) : ?>
                <li>
                    <strong><?php echo esc_html( sprintf( __( 'Room %d', 'etravel-multiroom' ), (int) $room['room'] ) ); ?></strong>
                    <span><?php echo esc_html( sprintf( _n( '%d adult', '%d adults', (int) $room['adults'], 'etravel-multiroom' ), (int) $room['adults'] ) ); ?> · <?php echo esc_html( sprintf( _n( '%d child', '%d children', (int) $room['children'], 'etravel-multiroom' ), (int) $room['children'] ) ); ?></span>
                    <?php if ( ! empty( $room['ages'] ) ) : ?><small><?php echo esc_html( sprintf( __( 'Ages: %s', 'etravel-multiroom' ), implode( ', ', array_map( 'absint', $room['ages'] ) ) ) ); ?></small><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return (string) ob_get_clean();
    }

    private static function selected_combo(): ?array {
        // R3.1: read the chosen plan from the opaque server-side token record.
        return ETravel_MR_Token::combo( ETravel_MR_Token::token_from_request() );
    }

    private static function current_url_without_combo(): string {
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $url    = $scheme . '://' . $host . $uri;
        return remove_query_arg( [ 'etr_use_plan', 'etr_combo', 'etr_combo_sig' ], $url );
    }

    private static function valid_date( string $date ): string {
        $dt = DateTime::createFromFormat( 'Y-m-d', $date );
        return $dt && $dt->format( 'Y-m-d' ) === $date ? $date : '';
    }
}
