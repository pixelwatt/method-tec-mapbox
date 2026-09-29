<?php
/**
 * Plugin Name: Method – Mapbox for The Events Calendar
 * Description: Opt-in replacement for The Events Calendar's Google Maps integration. Geocodes venues through Mapbox and keeps coordinates in sync between TEC/ECP meta and a cmb2-mapbox field.
 * Version: 1.0.2
 * Author: Rob Clark
 * Author URI: https://robclark.io
 * License: GPLv2 or later
 * Text Domain: method-tec-mapbox
 * GitHub Plugin URI: https://github.com/pixelwatt/method-tec-mapbox
 * Primary Branch: main
 *
 * Nothing runs until a child theme (or mu-plugin) opts in by hooking the token filter:
 *
 *   add_filter( 'method_mapbox_token', fn() => 'pk.xxxxxxxx' );
 *   // or reuse the token already stored by cmb2-mapbox:
 *   add_filter( 'method_mapbox_token', 'method_cmb2_mapbox_token' );
 *
 *   // The cmb2-mapbox field id registered on the tribe_venue post type:
 *   add_filter( 'method_mapbox_venue_meta_key', fn() => '_venue_location' );
 *
 * Once opted in, TEC's Google Maps scripts and embedded maps are disabled, even
 * while the filter has no token to return yet. Nothing else runs until it does.
 *
 * With a token:
 *   - ECP's Google geocoder is disabled.
 *   - Wherever TEC would embed a Google map (tribe_get_embedded_map(): single
 *     event meta, venue block, single venue) a Mapbox GL map is rendered from
 *     the venue's stored coordinates instead. TEC's "Enable Maps" setting and
 *     the per-event / per-venue "Show Map" checkboxes keep working. Venues
 *     without coordinates render no map (see `wp method geocode-venues`).
 *     Customise with:
 *
 *       add_filter( 'method_mapbox_embedded_map', function ( $config, $venue_id ) {
 *           $config['options']['style'] = 'mapbox://styles/you/xxxx'; // any mapboxgl.Map option
 *           $config['marker']['color']  = '#e86100';                  // any mapboxgl.Marker option
 *           return $config;
 *       }, 10, 2 );
 *
 *   - Any venue create/update (venue editor, inline venue form in the event
 *     editor, venue block via REST, importer) is geocoded through Mapbox and
 *     written to ECP's keys (_VenueLat, _VenueLng, _VenueGeoAddress) AND to
 *     the cmb2-mapbox key in cmb2-mapbox's array format.
 *   - An address that can't be geocoded (or is removed) unsets the coordinates
 *     found for the previous one, so a map is never drawn in the wrong place.
 *     A pin placed by hand is kept.
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

	// Set while the venue's coordinates come from a pin dropped in the cmb2-mapbox field.
	const MANUAL = '_VenueManualPin';

	const ENDPOINT = 'https://api.mapbox.com/search/geocode/v6/forward';

	// Same handle and version cmb2-mapbox enqueues, so the library never loads twice.
	const GL_HANDLE = 'mapbox-gl';
	const GL_BASE   = 'https://api.mapbox.com/mapbox-gl-js/v3.23.1/mapbox-gl';

	/** @var string */
	private static $token = '';

	/** @var string */
	private static $meta_key = '';

	/** @var array{venue_id: int, index: int, style: string} Set while TEC builds one embedded map. */
	private static $pending_map = [ 'venue_id' => 0, 'index' => 0, 'style' => '' ];

	/** @var array<int, array{lat: float, lng: float}|null> Pins as this request found them, for venues whose pin it has since rewritten. */
	private static $pin_before = [];

	/** @var array<int, bool> Venues whose pin was moved by hand during this request. */
	private static $pin_moved = [];

	public static function boot(): void {
		// Late enough that TEC, ECP and the child theme have all registered.
		add_action( 'init', [ __CLASS__, 'maybe_enable' ], 100 );
	}

	public static function maybe_enable(): void {
		// Hooking the token filter is the opt-in, whether or not it has a token to return yet.
		if ( ! has_filter( 'method_mapbox_token' ) || ! class_exists( 'Tribe__Events__Main' ) ) {
			return;
		}

		self::$token    = trim( (string) apply_filters( 'method_mapbox_token', '' ) );
		self::$meta_key = (string) apply_filters( 'method_mapbox_venue_meta_key', '_venue_location' );

		// 1. Take Google out of the picture. With no key TEC never enqueues its Google scripts,
		//    but it still runs the embed routine, which we use to swap in a Mapbox map.
		add_filter( 'tribe_get_option_google_maps_js_api_key', '__return_empty_string' );
		add_filter( 'tribe_is_using_basic_gmaps_api', '__return_false' );

		if ( '' === self::$token ) {
			// Nothing to draw or geocode with yet: no map at all, rather than falling back to Google's.
			add_filter( 'tribe_get_embedded_map', '__return_empty_string', 99 );
			return;
		}

		add_filter( 'tribe_events_embedded_map_style', [ __CLASS__, 'capture_map_style' ], 99, 2 );
		add_action( 'tribe_events_map_embedded', [ __CLASS__, 'capture_map_venue' ], 10, 2 );
		add_filter( 'tribe_get_embedded_map', [ __CLASS__, 'render_map' ], 99 );

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
		$moved    = ! empty( self::$pin_moved[ $venue_id ] );

		unset( self::$pin_before[ $venue_id ], self::$pin_moved[ $venue_id ] );

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

		if ( $moved ) {
			// TEC updates the post before its meta when it is handed a title, which lets CMB2 save
			// first. A pin dropped in this very save outranks the geocoder, but Direction B could
			// only cache it against the address as it was.
			update_post_meta( $venue_id, self::ADDRESS, self::build_address( $venue_id, $data ) );
			return;
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
			self::clear_stale_coords( $venue_id, $address );
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
			self::clear_stale_coords( $venue_id, $address );
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

		$pin    = self::get_pin( $venue_id );
		$coords = self::get_tec_coords( $venue_id );

		if ( array_key_exists( $venue_id, self::$pin_before ) ) {
			// CMB2 saves after TEC, so the form has just written its own pin over what Direction A
			// geocoded or unset moments ago. That only stands if the pin was actually moved.
			$moved = $pin && ! self::same_point( $pin, self::$pin_before[ $venue_id ] );
			unset( self::$pin_before[ $venue_id ] );

			if ( ! $moved ) {
				if ( $coords ) {
					self::write_pin( $venue_id, $coords['lat'], $coords['lng'] );
				} else {
					delete_post_meta( $venue_id, self::$meta_key );
				}
				return;
			}
		} elseif ( self::same_point( $pin, $coords ) ) {
			return; // Same pin, stored differently.
		}

		if ( ! $pin ) {
			// Pin deleted: clear TEC coords so the next address save re-geocodes.
			delete_post_meta( $venue_id, self::LAT );
			delete_post_meta( $venue_id, self::LNG );
			delete_post_meta( $venue_id, self::ADDRESS );
			delete_post_meta( $venue_id, self::MANUAL );
			return;
		}

		update_post_meta( $venue_id, self::LAT, (string) $pin['lat'] );
		update_post_meta( $venue_id, self::LNG, (string) $pin['lng'] );
		update_post_meta( $venue_id, self::MANUAL, 1 );
		self::$pin_moved[ $venue_id ] = true;

		// Pin the geocode cache to the current address so Direction A treats this
		// pin as authoritative until the address itself changes.
		update_post_meta( $venue_id, self::ADDRESS, self::build_address( $venue_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Front end: TEC's embedded Google map → Mapbox GL
	 *
	 * 'tribe_get_embedded_map' only receives the finished HTML, so the two
	 * hooks TEC fires just before it are used to learn which venue (and what
	 * dimensions) the map is for.
	 * ------------------------------------------------------------------ */

	/**
	 * Fires from TEC's modules/map template with the "height: x; width: y" it was asked for.
	 *
	 * @param string $style
	 * @param int    $index
	 */
	public static function capture_map_style( $style, $index = 0 ) {
		self::$pending_map['style'] = (string) $style;
		return $style;
	}

	/**
	 * Fires from Tribe__Events__Embedded_Maps::get_map() immediately before 'tribe_get_embedded_map'.
	 *
	 * @param int $index
	 * @param int $venue_id
	 */
	public static function capture_map_venue( $index, $venue_id ): void {
		self::$pending_map['index']    = (int) $index;
		self::$pending_map['venue_id'] = (int) $venue_id;
	}

	/**
	 * Replace TEC's Google map container with a Mapbox one.
	 *
	 * @param string $html
	 * @return string Empty when TEC bailed (no venue / no address) or the venue has no coordinates.
	 */
	public static function render_map( $html ): string {
		$pending           = self::$pending_map;
		self::$pending_map = [ 'venue_id' => 0, 'index' => 0, 'style' => '' ];

		$venue_id = $pending['venue_id'];
		$coords   = $venue_id ? self::get_coords( $venue_id ) : null;
		if ( ! $coords ) {
			return '';
		}

		/**
		 * 'options' is passed to mapboxgl.Map, 'marker' to mapboxgl.Marker; 'title' is the
		 * marker popup text (empty for no popup). Return an empty array to render no map.
		 */
		$config = apply_filters( 'method_mapbox_embedded_map', [
			'options' => [
				'style'               => 'mapbox://styles/mapbox/streets-v12',
				'center'              => [ $coords['lng'], $coords['lat'] ],
				'zoom'                => (int) apply_filters( 'tribe_events_single_map_zoom_level', (int) tribe_get_option( 'embedGoogleMapsZoom', 15 ) ),
				'cooperativeGestures' => true,
			],
			'marker'  => [ 'color' => '#3FB1CE' ],
			'title'   => html_entity_decode( get_the_title( $venue_id ), ENT_QUOTES, 'UTF-8' ),
		], $venue_id );

		if ( empty( $config['options']['center'] ) ) {
			return '';
		}

		self::enqueue_map_assets();

		return sprintf(
			'<div id="method-tec-mapbox-map-%1$d" class="method-tec-mapbox-map" style="%2$s" role="region" aria-label="%3$s" data-map="%4$s"></div>',
			$pending['index'],
			esc_attr( $pending['style'] ?: 'height: 350px; width: 100%' ),
			/* translators: %s: venue name */
			esc_attr( sprintf( __( 'Map of %s', 'method-tec-mapbox' ), wp_strip_all_tags( get_the_title( $venue_id ) ) ) ),
			esc_attr( wp_json_encode( $config ) )
		);
	}

	/**
	 * Coordinates for a venue: TEC's keys first, then the cmb2-mapbox pin.
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	private static function get_coords( int $venue_id ): ?array {
		return self::get_tec_coords( $venue_id ) ?? self::get_pin( $venue_id );
	}

	/**
	 * @return array{lat: float, lng: float}|null
	 */
	private static function get_tec_coords( int $venue_id ): ?array {
		return self::as_point( get_post_meta( $venue_id, self::LAT, true ), get_post_meta( $venue_id, self::LNG, true ) );
	}

	/**
	 * @return array{lat: float, lng: float}|null
	 */
	private static function get_pin( int $venue_id ): ?array {
		$pin = get_post_meta( $venue_id, self::$meta_key, true );

		return is_array( $pin ) ? self::as_point( $pin['lat'] ?? null, $pin['lng'] ?? null ) : null;
	}

	/**
	 * @return array{lat: float, lng: float}|null
	 */
	private static function as_point( $lat, $lng ): ?array {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}

		return [ 'lat' => (float) $lat, 'lng' => (float) $lng ];
	}

	private static function same_point( ?array $a, ?array $b ): bool {
		if ( ! $a || ! $b ) {
			return ! $a && ! $b;
		}

		// Stored as strings of varying precision; anything within about a centimetre is the same point.
		return abs( $a['lat'] - $b['lat'] ) < 1e-7 && abs( $a['lng'] - $b['lng'] ) < 1e-7;
	}

	private static function enqueue_map_assets(): void {
		if ( ! wp_script_is( self::GL_HANDLE, 'registered' ) ) {
			wp_register_script( self::GL_HANDLE, self::GL_BASE . '.js', [], null, true );
		}
		if ( ! wp_style_is( self::GL_HANDLE, 'registered' ) ) {
			wp_register_style( self::GL_HANDLE, self::GL_BASE . '.css', [], null );
		}
		wp_enqueue_script( self::GL_HANDLE );
		wp_enqueue_style( self::GL_HANDLE );

		// After wp_print_footer_scripts (20), so mapboxgl exists whether it loaded in the head or the footer.
		if ( ! has_action( 'wp_footer', [ __CLASS__, 'print_map_script' ] ) ) {
			add_action( 'wp_footer', [ __CLASS__, 'print_map_script' ], 100 );
		}
	}

	public static function print_map_script(): void {
		$token = wp_json_encode( self::$token );

		$js = <<<JS
		( function () {
			if ( 'undefined' === typeof mapboxgl ) {
				return;
			}
			mapboxgl.accessToken = {$token};

			document.querySelectorAll( '.method-tec-mapbox-map' ).forEach( function ( el ) {
				var config;
				try {
					config = JSON.parse( el.getAttribute( 'data-map' ) );
				} catch ( e ) {
					return;
				}

				var map    = new mapboxgl.Map( Object.assign( {}, config.options, { container: el } ) );
				var marker = new mapboxgl.Marker( config.marker || {} ).setLngLat( config.options.center );

				if ( config.title ) {
					marker.setPopup( new mapboxgl.Popup( { offset: 25 } ).setText( config.title ) );
				}

				marker.addTo( map );
				map.addControl( new mapboxgl.NavigationControl(), 'top-left' );
			} );
		} )();
		JS;

		wp_print_inline_script_tag( $js, [ 'id' => 'method-tec-mapbox-js' ] );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	private static function write_coords( int $venue_id, float $lat, float $lng, string $address ): void {
		self::remember_pin( $venue_id );

		update_post_meta( $venue_id, self::LAT, (string) $lat );
		update_post_meta( $venue_id, self::LNG, (string) $lng );
		update_post_meta( $venue_id, self::ADDRESS, $address );
		delete_post_meta( $venue_id, self::MANUAL );

		self::write_pin( $venue_id, $lat, $lng );
	}

	private static function write_pin( int $venue_id, float $lat, float $lng ): void {
		// cmb2-mapbox's storage format.
		update_post_meta( $venue_id, self::$meta_key, [
			'lat'    => (string) $lat,
			'lng'    => (string) $lng,
			'lnglat' => $lng . ',' . $lat,
		] );
	}

	/**
	 * Unset coordinates found for an address the venue no longer has, so a map is never
	 * drawn in the wrong place. Coordinates someone placed by hand are left alone.
	 */
	private static function clear_stale_coords( int $venue_id, string $address ): void {
		if ( self::is_pinned( $venue_id ) ) {
			return;
		}

		// A failed re-geocode of the same address (--force) says nothing against coordinates that were right for it.
		if ( self::get_tec_coords( $venue_id ) && $address === (string) get_post_meta( $venue_id, self::ADDRESS, true ) ) {
			return;
		}

		self::remember_pin( $venue_id );

		delete_post_meta( $venue_id, self::LAT );
		delete_post_meta( $venue_id, self::LNG );
		delete_post_meta( $venue_id, self::ADDRESS );
		delete_post_meta( $venue_id, self::$meta_key );
	}

	/**
	 * Whether the venue's coordinates were placed by a person: a pin dropped in the cmb2-mapbox
	 * field (including one from before this plugin, which TEC's keys never mirrored) or ECP's
	 * manual coordinates.
	 */
	private static function is_pinned( int $venue_id ): bool {
		if ( get_post_meta( $venue_id, self::MANUAL, true ) || (int) get_post_meta( $venue_id, self::OVERWRITE, true ) ) {
			return true;
		}

		$pin = self::get_pin( $venue_id );

		return $pin && ! self::same_point( $pin, self::get_tec_coords( $venue_id ) );
	}

	/**
	 * Note the pin as this request found it, before the request first rewrites it, so that
	 * Direction B can tell a pin the form merely carried along from one that was moved.
	 */
	private static function remember_pin( int $venue_id ): void {
		if ( ! array_key_exists( $venue_id, self::$pin_before ) ) {
			self::$pin_before[ $venue_id ] = self::get_pin( $venue_id );
		}
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