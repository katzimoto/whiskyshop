<?php
/**
 * Shipping zone and method management for the Omef dashboard, built directly
 * on WooCommerce's own shipping zone objects and each method's own declared
 * settings schema (instance_form_fields) rather than a hand-rolled one, so
 * any of WooCommerce's built-in methods (and their fields) just work.
 */

defined( 'ABSPATH' ) || exit;

function omef_admin_shape_shipping_method( WC_Shipping_Method $method ): array {
	return array(
		'instanceId'   => $method->instance_id,
		'methodId'     => $method->id,
		'methodTitle'  => $method->get_method_title(),
		'title'        => $method->get_title(),
		'enabled'      => $method->enabled === 'yes',
		'cost'         => $method->supports( 'settings' ) ? $method->get_option( 'cost', '' ) : '',
	);
}

function omef_shipping_zones_list(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$zones = array();
	foreach ( WC_Shipping_Zones::get_zones() as $raw ) {
		$zone = new WC_Shipping_Zone( $raw['id'] );
		$zones[] = array(
			'id'       => $zone->get_id(),
			'name'     => $zone->get_zone_name(),
			'location' => $zone->get_formatted_location() ?: 'כל העולם',
			'methods'  => array_values( array_map( 'omef_admin_shape_shipping_method', $zone->get_shipping_methods() ) ),
		);
	}

	$rest_of_world = new WC_Shipping_Zone( 0 );
	$zones[] = array(
		'id'       => 0,
		'name'     => 'כל העולם',
		'location' => 'כל אזור שלא הוגדר לו אזור ייעודי',
		'methods'  => array_values( array_map( 'omef_admin_shape_shipping_method', $rest_of_world->get_shipping_methods() ) ),
	);

	wp_send_json_success( array( 'zones' => $zones ) );
}
add_action( 'wp_ajax_omef_shipping_zones_list', 'omef_shipping_zones_list' );

function omef_shipping_countries_list(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$countries = array();
	foreach ( WC()->countries->get_countries() as $code => $name ) {
		$countries[] = array( 'code' => $code, 'name' => $name );
	}

	wp_send_json_success( array( 'countries' => $countries, 'methodTypes' => omef_shipping_available_method_types() ) );
}
add_action( 'wp_ajax_omef_shipping_countries_list', 'omef_shipping_countries_list' );

function omef_shipping_available_method_types(): array {
	$types = array();
	foreach ( WC_Shipping::instance()->get_shipping_methods() as $id => $method ) {
		$types[] = array( 'id' => $id, 'title' => $method->get_method_title() );
	}
	return $types;
}

function omef_shipping_zone_get(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id   = absint( $_GET['id'] ?? 0 );
	$zone = new WC_Shipping_Zone( $id );

	$countries = array();
	foreach ( $zone->get_zone_locations() as $location ) {
		if ( $location->type === 'country' ) {
			$countries[] = $location->code;
		}
	}

	wp_send_json_success(
		array(
			'id'        => $zone->get_id(),
			'name'      => $zone->get_zone_name(),
			'countries' => $countries,
		)
	);
}
add_action( 'wp_ajax_omef_shipping_zone_get', 'omef_shipping_zone_get' );

function omef_shipping_zone_save(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id   = absint( $_POST['id'] ?? 0 );
	$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	if ( $name === '' ) {
		wp_send_json_error( array( 'message' => 'יש להזין שם לאזור המשלוח.' ) );
	}

	$zone = new WC_Shipping_Zone( $id );
	$zone->set_zone_name( $name );
	$zone->save();

	$countries = array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['countries'] ?? array() ) ) );
	$locations = array_map(
		static fn( string $code ): array => array( 'code' => $code, 'type' => 'country' ),
		$countries
	);
	$zone->set_locations( $locations );
	$zone->save();

	wp_send_json_success( array( 'id' => $zone->get_id() ) );
}
add_action( 'wp_ajax_omef_shipping_zone_save', 'omef_shipping_zone_save' );

function omef_shipping_zone_delete(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$id = absint( $_POST['id'] ?? 0 );
	if ( ! $id ) {
		wp_send_json_error( array( 'message' => 'לא ניתן למחוק את האזור הכללי.' ) );
	}

	WC_Shipping_Zones::delete_zone( $id );
	wp_send_json_success();
}
add_action( 'wp_ajax_omef_shipping_zone_delete', 'omef_shipping_zone_delete' );

function omef_shipping_method_add(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$zone_id = absint( $_POST['zone_id'] ?? 0 );
	$type    = sanitize_key( $_POST['type'] ?? '' );

	$zone = new WC_Shipping_Zone( $zone_id );
	$instance_id = $zone->add_shipping_method( $type );
	if ( ! $instance_id ) {
		wp_send_json_error( array( 'message' => 'לא ניתן היה להוסיף את שיטת המשלוח.' ) );
	}

	wp_send_json_success( array( 'instanceId' => $instance_id ) );
}
add_action( 'wp_ajax_omef_shipping_method_add', 'omef_shipping_method_add' );

function omef_shipping_method_delete(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$zone_id     = absint( $_POST['zone_id'] ?? 0 );
	$instance_id = absint( $_POST['instance_id'] ?? 0 );

	$zone = new WC_Shipping_Zone( $zone_id );
	$zone->delete_shipping_method( $instance_id );

	wp_send_json_success();
}
add_action( 'wp_ajax_omef_shipping_method_delete', 'omef_shipping_method_delete' );

function omef_shipping_method_get(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$instance_id = absint( $_GET['instance_id'] ?? 0 );
	$method      = WC_Shipping_Zones::get_shipping_method( $instance_id );
	if ( ! $method ) {
		wp_send_json_error( array( 'message' => 'שיטת המשלוח לא נמצאה.' ), 404 );
	}

	$fields = array();
	foreach ( $method->get_instance_form_fields() as $key => $field ) {
		if ( in_array( $field['type'] ?? '', array( 'title', 'sectionend' ), true ) ) {
			continue;
		}
		$fields[] = array(
			'key'         => $key,
			'label'       => wp_strip_all_tags( $field['title'] ?? $key ),
			'type'        => $field['type'] ?? 'text',
			'description' => wp_strip_all_tags( $field['description'] ?? '' ),
			'options'     => $field['options'] ?? null,
			'value'       => $method->get_option( $key ),
		);
	}

	wp_send_json_success(
		array(
			'instanceId' => $method->instance_id,
			'methodId'   => $method->id,
			'title'      => $method->get_method_title(),
			'enabled'    => $method->enabled === 'yes',
			'fields'     => $fields,
		)
	);
}
add_action( 'wp_ajax_omef_shipping_method_get', 'omef_shipping_method_get' );

function omef_shipping_method_save(): void {
	omef_dashboard_guard( 'manage_woocommerce' );

	$instance_id = absint( $_POST['instance_id'] ?? 0 );
	$method      = WC_Shipping_Zones::get_shipping_method( $instance_id );
	if ( ! $method ) {
		wp_send_json_error( array( 'message' => 'שיטת המשלוח לא נמצאה.' ), 404 );
	}

	$settings = get_option( $method->get_instance_option_key(), array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	foreach ( $method->get_instance_form_fields() as $key => $field ) {
		$type = $field['type'] ?? 'text';
		if ( $type === 'checkbox' ) {
			$settings[ $key ] = isset( $_POST[ $key ] ) ? 'yes' : 'no';
			continue;
		}
		if ( in_array( $type, array( 'title', 'sectionend' ), true ) ) {
			continue;
		}
		if ( array_key_exists( $key, $_POST ) ) {
			$settings[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}
	}
	update_option( $method->get_instance_option_key(), $settings );

	global $wpdb;
	$wpdb->update(
		"{$wpdb->prefix}woocommerce_shipping_zone_methods",
		array( 'is_enabled' => ! empty( $_POST['enabled'] ) ? 1 : 0 ),
		array( 'instance_id' => $instance_id )
	);

	WC_Cache_Helper::get_transient_version( 'shipping', true );

	wp_send_json_success();
}
add_action( 'wp_ajax_omef_shipping_method_save', 'omef_shipping_method_save' );
