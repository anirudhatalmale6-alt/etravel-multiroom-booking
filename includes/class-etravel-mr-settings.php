<?php

defined( 'ABSPATH' ) || exit;

final class ETravel_MR_Settings {
    private const OPTION = 'etravel_mr_settings';

    public static function defaults(): array {
        return [
            'max_rooms'                  => 6,
            'max_adults_per_room'        => 8,
            'max_children_per_room'      => 6,
            'max_combinations'           => 12,
            'destination_taxonomy'       => '',
            'results_page_url'           => '',
            'collect_child_ages'         => 1,
            'exact_room_count'           => 1,
            'enforce_checkout_room_count'=> 0,
            'cookie_hours'               => 4,
        ];
    }

    public static function activate(): void {
        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, self::defaults(), '', false );
        }
    }

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
    }

    public static function get( string $key, mixed $fallback = null ): mixed {
        $settings = wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() );
        return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
    }

    public static function all(): array {
        return wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() );
    }

    public static function admin_menu(): void {
        $parent = post_type_exists( 'mphb_room_type' ) ? 'edit.php?post_type=mphb_room_type' : 'options-general.php';
        add_submenu_page(
            $parent,
            __( 'eTravel Multi-Room', 'etravel-multiroom' ),
            __( 'eTravel Multi-Room', 'etravel-multiroom' ),
            'manage_options',
            'etravel-multiroom',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings(): void {
        register_setting(
            'etravel_mr_group',
            self::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [ __CLASS__, 'sanitize' ],
                'default'           => self::defaults(),
            ]
        );
    }

    public static function sanitize( mixed $input ): array {
        $input    = is_array( $input ) ? $input : [];
        $defaults = self::defaults();

        return [
            'max_rooms'                   => min( 12, max( 1, absint( $input['max_rooms'] ?? $defaults['max_rooms'] ) ) ),
            'max_adults_per_room'         => min( 20, max( 1, absint( $input['max_adults_per_room'] ?? $defaults['max_adults_per_room'] ) ) ),
            'max_children_per_room'       => min( 15, max( 0, absint( $input['max_children_per_room'] ?? $defaults['max_children_per_room'] ) ) ),
            'max_combinations'            => min( 50, max( 1, absint( $input['max_combinations'] ?? $defaults['max_combinations'] ) ) ),
            'destination_taxonomy'        => sanitize_key( $input['destination_taxonomy'] ?? '' ),
            'results_page_url'            => esc_url_raw( $input['results_page_url'] ?? '' ),
            'collect_child_ages'          => empty( $input['collect_child_ages'] ) ? 0 : 1,
            'exact_room_count'            => empty( $input['exact_room_count'] ) ? 0 : 1,
            'enforce_checkout_room_count' => empty( $input['enforce_checkout_room_count'] ) ? 0 : 1,
            'cookie_hours'                => min( 24, max( 1, absint( $input['cookie_hours'] ?? $defaults['cookie_hours'] ) ) ),
        ];
    }

    private static function number_field( string $key, string $label, int $min, int $max, string $help = '' ): void {
        $value = absint( self::get( $key ) );
        ?>
        <tr>
            <th scope="row"><label for="etr-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <input id="etr-<?php echo esc_attr( $key ); ?>" type="number" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>">
                <?php if ( $help ) : ?><p class="description"><?php echo esc_html( $help ); ?></p><?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private static function checkbox_field( string $key, string $label, string $help = '' ): void {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html( $label ); ?></th>
            <td>
                <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( 1, absint( self::get( $key ) ) ); ?>> <?php echo esc_html( $help ); ?></label>
            </td>
        </tr>
        <?php
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'eTravel Multi-Room Booking', 'etravel-multiroom' ); ?></h1>
            <p><?php esc_html_e( 'This add-on keeps MotoPress as the booking engine. It adds per-room parties, exact room-count preflight, compatible room plans, checkout prefilling and booking metadata without editing MotoPress core files.', 'etravel-multiroom' ); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields( 'etravel_mr_group' ); ?>
                <table class="form-table" role="presentation">
                    <?php
                    self::number_field( 'max_rooms', 'Maximum rooms per request', 1, 12 );
                    self::number_field( 'max_adults_per_room', 'Maximum adults per room', 1, 20 );
                    self::number_field( 'max_children_per_room', 'Maximum children per room', 0, 15 );
                    self::number_field( 'max_combinations', 'Maximum room plans shown', 1, 50 );
                    self::number_field( 'cookie_hours', 'Room request retention (hours)', 1, 24 );
                    self::checkbox_field( 'collect_child_ages', 'Collect child ages', 'Store ages as booking-request metadata. MotoPress pricing still follows its own adult/child rate configuration.' );
                    self::checkbox_field( 'exact_room_count', 'Require exact requested room count', 'The planner only shows combinations with exactly the requested number of units.' );
                    self::checkbox_field( 'enforce_checkout_room_count', 'Block checkout on a room-count mismatch', 'Enable only after staging UAT confirms your checkout template selectors. Default is warning-only.' );
                    ?>
                    <tr>
                        <th scope="row"><label for="etr-destination-taxonomy">Destination taxonomy</label></th>
                        <td>
                            <input id="etr-destination-taxonomy" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[destination_taxonomy]" value="<?php echo esc_attr( (string) self::get( 'destination_taxonomy' ) ); ?>" placeholder="Example: mphb_ra_location">
                            <p class="description">Optional. Enter the taxonomy used by MotoPress accommodation types for destinations. Leave blank to let the planner search all published accommodation types.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="etr-results-url">Default search results URL</label></th>
                        <td>
                            <input id="etr-results-url" class="regular-text" type="url" name="<?php echo esc_attr( self::OPTION ); ?>[results_page_url]" value="<?php echo esc_attr( (string) self::get( 'results_page_url' ) ); ?>" placeholder="https://www.etravel.gr/staging/hotels-accommodations/">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e( 'MotoPress release checklist', 'etravel-multiroom' ); ?></h2>
            <ol>
                <li>Accommodation → Settings → General → increase Max Adults and Max Children above your largest supported party.</li>
                <li>Enable “Recommend the best set of accommodations according to a number of guests”.</li>
                <li>Disable “Redirect to checkout immediately after successful addition to reservation”; that setting blocks multi-booking.</li>
                <li>Configure Search Results and Checkout system pages with the official MotoPress shortcodes.</li>
                <li>For every accommodation type: set adult/child/total capacity, generate physical units, add rates and seasons, and confirm booking rules.</li>
                <li>Place <code>[etravel_multiroom_plan]</code> immediately before <code>[mphb_search_results]</code> on the Search Results page.</li>
            </ol>

            <h2><?php esc_html_e( 'Compatibility status', 'etravel-multiroom' ); ?></h2>
            <table class="widefat striped" style="max-width:760px">
                <tbody>
                    <tr><td>MotoPress functions</td><td><?php echo ETravel_MR_MotoPress::is_available() ? '<strong style="color:#18743a">Available</strong>' : '<strong style="color:#b32d2e">Missing</strong>'; ?></td></tr>
                    <tr><td>PHP</td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
                    <tr><td>WordPress</td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
