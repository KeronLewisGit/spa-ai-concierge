<?php
/**
 * Uninstall: remove options and transients. Post data (FAQs, meta, finder fields, Concern
 * terms) is kept unless "delete data on uninstall" was ticked in the settings.
 *
 * @package SpaAiConcierge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$spa_ai_settings = get_option( 'spa_ai_concierge_settings', array() );
$spa_ai_purge    = is_array( $spa_ai_settings ) && ! empty( $spa_ai_settings['delete_data_on_uninstall'] );

foreach ( array( 'spa_ai_concierge_settings', 'spa_ai_concierge_seeded', 'spa_ai_concierge_seed_lock', 'spa_ai_concierge_finder_page' ) as $spa_ai_option ) {
	delete_option( $spa_ai_option );
}
delete_transient( 'spa_ai_concierge_seed_wait' );

global $wpdb;

// Rate-limit transients use hashed keys, so they are removed by prefix.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_spa_ai_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_spa_ai_rl_' ) . '%'
	)
);

if ( $spa_ai_purge ) {
	foreach ( array( '_spa_ai_faqs', '_spa_ai_faqs_source', '_spa_ai_meta_title', '_spa_ai_meta_description', '_spa_ai_areas', '_spa_ai_downtime_days', '_spa_ai_price_from' ) as $spa_ai_meta_key ) {
		delete_post_meta_by_key( $spa_ai_meta_key );
	}

	// The taxonomy is not registered during uninstall, so remove its terms directly.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup.
	$spa_ai_terms = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'concern' ) );
	foreach ( $spa_ai_terms as $spa_ai_term ) {
		$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $spa_ai_term->term_taxonomy_id ) );
		$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => (int) $spa_ai_term->term_taxonomy_id ) );
		$wpdb->delete( $wpdb->termmeta, array( 'term_id' => (int) $spa_ai_term->term_id ) );
		$wpdb->delete( $wpdb->terms, array( 'term_id' => (int) $spa_ai_term->term_id ) );
	}
	// phpcs:enable
}
