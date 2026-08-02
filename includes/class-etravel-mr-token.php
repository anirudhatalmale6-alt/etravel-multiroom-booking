<?php
/**
 * R3.1: opaque server-side room-plan token.
 * The detailed room plan (including child ages) NEVER travels in a URL or an
 * encoded cookie. Only a random opaque token is exposed. The plan is stored
 * server-side, expires after the configured retention (default 4h), is single
 * use and is cleared once bound to a booking.
 */

defined( 'ABSPATH' ) || exit;

final class ETravel_MR_Token {
	private const PREFIX      = 'etr_mr_plan_';
	private const COOKIE      = 'etr_mr_token';
	private const QUERY_VAR   = 'etr_token';

	private static function ttl(): int {
		return HOUR_IN_SECONDS * max( 1, absint( ETravel_MR_Settings::get( 'cookie_hours', 4 ) ) );
	}

	/** Create a token and persist the plan server-side. Returns the opaque token. */
	public static function create( array $party ): string {
		$token  = bin2hex( random_bytes( 18 ) ); // 36 hex chars, unguessable
		$record = array(
			'party'      => $party,
			'combo'      => null,
			'created'    => time(),
			'consumed'   => false,
			'booking_id' => 0,
		);
		set_transient( self::PREFIX . $token, $record, self::ttl() );
		return $token;
	}

	/** Full server-side record for a token, or WP_Error. */
	public static function record( string $token ): array|WP_Error {
		$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'etr_token_missing', __( 'The room request token is missing.', 'etravel-multiroom' ) );
		}
		$record = get_transient( self::PREFIX . $token );
		if ( ! is_array( $record ) || empty( $record['party']['rooms'] ) ) {
			return new WP_Error( 'etr_token_expired', __( 'Your room request has expired or is invalid. Please search again.', 'etravel-multiroom' ) );
		}
		if ( ! empty( $record['consumed'] ) ) {
			return new WP_Error( 'etr_token_consumed', __( 'This room request has already been used for a booking. Please search again.', 'etravel-multiroom' ) );
		}
		return $record;
	}

	/** Validated party for a token, or WP_Error. */
	public static function party( string $token ): array|WP_Error {
		$record = self::record( $token );
		return is_wp_error( $record ) ? $record : (array) $record['party'];
	}

	/** Store the chosen room plan (combo) against the token, server-side. */
	public static function set_combo( string $token, array $combo ): void {
		$token  = preg_replace( '/[^a-f0-9]/', '', (string) $token );
		$record = get_transient( self::PREFIX . $token );
		if ( is_array( $record ) && empty( $record['consumed'] ) ) {
			$record['combo'] = $combo;
			$remaining       = max( MINUTE_IN_SECONDS, self::ttl() - ( time() - (int) ( $record['created'] ?? time() ) ) );
			set_transient( self::PREFIX . $token, $record, $remaining );
		}
	}

	public static function combo( string $token ): ?array {
		$record = self::record( $token );
		if ( is_wp_error( $record ) ) {
			return null;
		}
		return is_array( $record['combo'] ?? null ) ? $record['combo'] : null;
	}

	/** Bind the token to a real booking, mark it consumed, and clear the plan. */
	public static function consume( string $token, int $booking_id ): void {
		$token = preg_replace( '/[^a-f0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return;
		}
		// One-time use: remove the server-side plan entirely once a booking exists.
		delete_transient( self::PREFIX . $token );
		self::clear_cookie();
	}

	public static function token_from_request(): string {
		$token = '';
		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		}
		if ( '' === $token && isset( $_COOKIE[ self::COOKIE ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		return preg_replace( '/[^a-f0-9]/', '', $token );
	}

	public static function query_var(): string {
		return self::QUERY_VAR;
	}

	public static function set_cookie( string $token ): void {
		$expire = time() + self::ttl();
		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		setcookie( self::COOKIE, $token, $expire, $path, $domain, is_ssl(), true );
		$_COOKIE[ self::COOKIE ] = $token;
	}

	public static function clear_cookie(): void {
		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		setcookie( self::COOKIE, '', time() - 3600, $path, $domain, is_ssl(), true );
		unset( $_COOKIE[ self::COOKIE ] );
	}
}
