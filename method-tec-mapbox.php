<?php
/**
 * Plugin Name: Method – Mapbox for The Events Calendar
 * Description: Opt-in replacement for The Events Calendar's Google Maps integration. Geocodes venues through Mapbox and keeps coordinates in sync between TEC/ECP meta and a cmb2-mapbox field.
 * Version: 1.0.0
 * Author: Rob Clark
 * Author URI: https://robclark.io
 * License: GPLv2 or later
 * Text Domain: method-tec-mapbox
 * GitHub Plugin URI: https://github.com/pixelwatt/method-tec-mapbox
 *
 * Nothing runs until a child theme (or mu-plugin) supplies a token:
 *
 *   add_filter( 'method_mapbox_token', fn() => 'pk.xxxxxxxx' );
 *   // or reuse the token already stored by cmb2-mapbox:
 *   add_filter( 'method_mapbox_token', 'method_cmb2_mapbox_token' );
 *
 *   // The cmb2-mapbox field id registered on the tribe_venue post type:
 *   add_filter( 'method_mapbox_venue_meta_key', fn() => '_venue_location' );
 *
 * Once enabled:
 *   - TEC / ECP Google Maps output and ECP's Google geocoder are disabled.
 *   - Any venue create/update (venue editor, inline venue form in the event
 *     editor, venue block via REST, importer) is geocoded through Mapbox and
 *     written to ECP's keys (_VenueLat, _VenueLng, _VenueGeoAddress) AND to
 *     the cmb2-mapbox key in cmb2-mapbox's array format.
 *   - Saving the cmb2-mapbox field (pin drag / manual entry) writes back to
 *     _VenueLat / _VenueLng, and pins the geocode cache so the next TEC save
 *     doesn't overwrite the pin unless the address actually changed.
 *
 * Requires: The Events Calendar; cmb2-mapbox (for the field and optional token reuse).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read the token cmb2-mapbox already stores (honours its 'use method options' switch).
 */
function method_cmb2_mapbox_token(): string {
	if ( ! function_exists( 'cmb2_mapbox_method' ) ) {
		return '';
	}
	$options = get_option( cmb2_mapbox_method() ? 'method_options' : 'cmb2_mapbox' );
	return ( is_array( $options ) && ! empty( $options['mapbox_api_token'] ) )
		? trim( (string) $options['mapbox_api_token'] )
		: '';
}

final class Method_Mapbox_Venue_Sync {

	// Events Calendar Pro's keys (Tribe__Events__Pro__Geo_Loc constants). Harmless without Pro.
	const LAT       = '_VenueLat';
	const LNG       = '_VenueLng';
	const ADDRESS   = '_VenueGeoAddress';
	const OVERWRITE = '_VenueOverwriteCoords';

	const ENDPOINT = 'https://api.mapbox.com/search/geocode/v6/forward';

	/** @var string */
	private static $token = '';

	/** @var string */
	private static $meta_key = '';

	public static function boot(): void {
		// Late enough that TEC, ECP and the child theme have all registered.
		add_action( 'init', [ __CLASS__, 'maybe_enable' ], 100 );
	}

	public static function maybe_enable(): void {
		self::$token = trim( (string) apply_filters( 'method_mapbox_token', '' ) );

		if ( '' === self::$token || ! class_exists( 'Tribe__Events__Main' ) ) {
			return;
		}

		self::$meta_key = (string) apply_filters( 'method_mapbox_venue_meta_key', '_venue_location' );

		// 1. Take Google out of the picture.
		add_filter( 'tribe_get_option_embedGoogleMaps', '__return_false' );
		add_filter( 'tribe_get_option_google_maps_js_api_key', '__return_empty_string' );
		add_filter( 'tribe_get_embedded_map', '__return_empty_string' );

		if ( class_exists( 'Tribe__Events__Pro__Geo_Loc' ) ) {
			$geo = Tribe__Events__Pro__Geo_Loc::instance();
			remove_action( 'tribe_events_venue_created', [ $geo, 'save_venue_geodata' ], 10 );
			remove_action( 'tribe_events_venue_updated', [ $geo, 'save_venue_geodata' ], 10 );
		}

		// 2. TEC venue save → Mapbox → TEC keys + cmb2-mapbox key.
		add_action( 'tribe_events_venue_created', [ __CLASS__, 'on_venue_save' ], 20, 2 );
		add_action( 'tribe_events_venue_updated', [ __CLASS__, 'on_venue_save' ], 20, 2 );

		// 3. cmb2-mapbox field save → TEC keys.
		add_action( 'cmb2_save_field', [ __CLASS__, 'on_mapbox_field_save' ], 10, 4 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'method geocode-venues', [ __CLASS__, 'cli_backfill' ] );
		}
	}

	/* ---------------------------------------------------------------------
	 * Direction A: TEC venue save → coordinates
	 * ------------------------------------------------------------------ */

	/**
	 * @param int   $venue_id
	 * @param array $data  The venue data TEC just saved (keys without the _Venue prefix).
	 */
	public static function on_venue_save( $venue_id, $data ): void {
		$venue_id = (int) $venue_id;
		$data     = is_array( $data ) ? $data : [];

		// Replicate what ECP's handler did for its own "overwrite coordinates" fields,
		// since we just unhooked it. Only touch the flag when the form actually sent it.
		if ( array_key_exists( 'OverwriteCoords', $data ) ) {
			$overwrite = tribe_is_truthy( $data['OverwriteCoords'] ) ? 1 : 0;
			update_post_meta( $venue_id, self::OVERWRITE, $overwrite );

			if ( $overwrite ) {
				$lat = ( isset( $data['Lat'] ) && is_numeric( $data['Lat'] ) ) ? (float) $data['Lat'] : null;
				$lng = ( isset( $data['Lng'] ) && is_numeric( $data['Lng'] ) ) ? (float) $data['Lng'] : null;
				if ( null !== $lat && null !== $lng ) {
					self::write_coords( $venue_id, $lat, $lng, self::build_address( $venue_id, $data ) );
				}
				return;
			}
		}

		self::geocode_venue( $venue_id, $data, false );
	}

	/**
	 * Geocode one venue. Public so it can be used for backfills.
	 *
	 * @param int   $venue_id
	 * @param array $data   Optional freshly-saved data; falls back to post meta.
	 * @param bool  $force  Ignore the address cache and the overwrite flag.
	 * @return bool Whether coordinates were written.
	 */
	public static function geocode_venue( int $venue_id, array $data = [], bool $force = false ): bool {
		$address = self::build_address( $venue_id, $data );
		if ( '' === $address ) {
			return false;
		}

		if ( ! $force ) {
			if ( (int) get_post_meta( $venue_id, self::OVERWRITE, true ) ) {
				return false;
			}
			$has_coords = '' !== (string) get_post_meta( $venue_id, self::LAT, true );
			$cached     = (string) get_post_meta( $venue_id, self::ADDRESS, true );
			if ( $has_coords && $cached === $address ) {
				return false; // Address unchanged — leave any manually-placed pin alone.
			}
		}

		$result = self::forward_geocode( $address, $venue_id );
		if ( ! $result ) {
			do_action( 'method_venue_geocode_failed', $venue_id, $address );
			return false;
		}

		self::write_coords( $venue_id, $result['lat'], $result['lng'], $address );
		do_action( 'method_venue_geocoded', $venue_id, $result, $address );
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Direction B: cmb2-mapbox field save → TEC keys
	 * ------------------------------------------------------------------ */

	/**
	 * Fires from CMB2 after each field is persisted.
	 *
	 * @param string     $field_id
	 * @param bool       $updated
	 * @param string     $action   'updated' | 'removed' | 'repeatable'
	 * @param CMB2_Field $field
	 */
	public static function on_mapbox_field_save( $field_id, $updated, $action, $field ): void {
		if ( $field_id !== self::$meta_key || ! $updated ) {
			return;
		}
		if ( 'post' !== $field->object_type() ) {
			return;
		}

		$venue_id = (int) $field->object_id();
		if ( Tribe__Events__Main::VENUE_POST_TYPE !== get_post_type( $venue_id ) ) {
			return;
		}

		$value = get_post_meta( $venue_id, self::$meta_key, true );

		if ( 'removed' === $action || ! is_array( $value ) || ! is_numeric( $value['lat'] ?? null ) || ! is_numeric( $value['lng'] ?? null ) ) {
			// Pin deleted: clear TEC coords so the next address save re-geocodes.
			delete_post_meta( $venue_id, self::LAT );
			delete_post_meta( $venue_id, self::LNG );
			delete_post_meta( $venue_id, self::ADDRESS );
			return;
		}

		update_post_meta( $venue_id, self::LAT, (string) (float) $value['lat'] );
		update_post_meta( $venue_id, self::LNG, (string) (float) $value['lng'] );

		// Pin the geocode cache to the current address so Direction A treats this
		// pin as authoritative until the address itself changes.
		update_post_meta( $venue_id, self::ADDRESS, self::build_address( $venue_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	private static function write_coords( int $venue_id, float $lat, float $lng, string $address ): void {
		update_post_meta( $venue_id, self::LAT, (string) $lat );
		update_post_meta( $venue_id, self::LNG, (string) $lng );
		update_post_meta( $venue_id, self::ADDRESS, $address );

		// cmb2-mapbox's storage format.
		update_post_meta( $venue_id, self::$meta_key, [
			'lat'    => (string) $lat,
			'lng'    => (string) $lng,
			'lnglat' => $lng . ',' . $lat,
		] );
	}

	/**
	 * Assemble a single-line address from freshly-saved data, falling back to meta.
	 */
	private static function build_address( int $venue_id, array $data = [] ): string {
		$get = static function ( string $data_key, string $meta_key ) use ( $venue_id, $data ): string {
			if ( array_key_exists( $data_key, $data ) ) {
				return trim( (string) $data[ $data_key ] );
			}
			return trim( (string) get_post_meta( $venue_id, $meta_key, true ) );
		};

		$region = $get( 'StateProvince', '_VenueStateProvince' );
		if ( '' === $region ) {
			$region = $get( 'State', '_VenueState' ) ?: $get( 'Province', '_VenueProvince' );
		}

		$pieces = array_filter( [
			$get( 'Address', '_VenueAddress' ),
			$get( 'City', '_VenueCity' ),
			$region,
			$get( 'Zip', '_VenueZip' ),
			$get( 'Country', '_VenueCountry' ),
		] );

		return (string) apply_filters( 'method_venue_geocode_address', implode( ', ', $pieces ), $venue_id, $data );
	}

	/**
	 * Mapbox Geocoding v6 forward lookup.
	 *
	 * @return array{lat: float, lng: float, confidence: string, full_address: string}|null
	 */
	private static function forward_geocode( string $address, int $venue_id ): ?array {
		$args = apply_filters( 'method_mapbox_geocode_args', [
			'q'            => $address,
			'limit'        => 1,
			'types'        => 'address,street,place',
			'access_token' => self::$token,
		], $venue_id, $address );

		$response = wp_remote_get( add_query_arg( $args, self::ENDPOINT ), [ 'timeout' => 8 ] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$feature = $body['features'][0] ?? null;
		$coords  = $feature['geometry']['coordinates'] ?? null;

		if ( ! is_array( $coords ) || ! is_numeric( $coords[0] ?? null ) || ! is_numeric( $coords[1] ?? null ) ) {
			return null;
		}

		// v6 attaches match_code.confidence to address-type results: exact | high | medium | low.
		$confidence = (string) ( $feature['properties']['match_code']['confidence'] ?? 'exact' );
		$rank       = [ 'exact' => 3, 'high' => 2, 'medium' => 1, 'low' => 0 ];
		$minimum    = (string) apply_filters( 'method_mapbox_min_confidence', 'medium' );
		if ( ( $rank[ $confidence ] ?? 3 ) < ( $rank[ $minimum ] ?? 0 ) ) {
			return null;
		}

		return [
			'lng'          => (float) $coords[0],
			'lat'          => (float) $coords[1],
			'confidence'   => $confidence,
			'full_address' => (string) ( $feature['properties']['full_address'] ?? '' ),
		];
	}

	/* ---------------------------------------------------------------------
	 * WP-CLI: wp method geocode-venues [--force]
	 * ------------------------------------------------------------------ */

	public static function cli_backfill( array $args, array $assoc ): void {
		$force = ! empty( $assoc['force'] );
		$ids   = get_posts( [
			'post_type'      => Tribe__Events__Main::VENUE_POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$done = 0;
		foreach ( $ids as $id ) {
			if ( self::geocode_venue( (int) $id, [], $force ) ) {
				$done++;
				WP_CLI::log( "Geocoded venue {$id}" );
			}
		}
		WP_CLI::success( "{$done} of " . count( $ids ) . ' venues geocoded.' );
	}
}

Method_Mapbox_Venue_Sync::boot();