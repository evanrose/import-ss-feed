<?php

/*
Plugin Name: Import Squarespace Feed
Plugin URI: http://github.com/evanrose/import-ss-feed
Description: Import a Squarespace Feed, insert posts into a WordPress database, download and save images, and set them as featured.
Author: Evan Rose
Version: 1.0
Author URI: http://evanrose.com/
*/


//defined( 'ABSPATH' ) or die();

register_activation_hook(__FILE__, 'er_ss_activation' );

function er_ss_activation() {
	
	wp_schedule_event( time(), 'hourly', 'er_ss_hourly_event' );
}
add_action('er_ss_hourly_event', 'er_ss_fetch_and_insert');

require_once( ABSPATH . 'wp-admin' . '/includes/file.php' );
require_once( ABSPATH . 'wp-admin' . '/includes/media.php' );
require_once( ABSPATH . 'wp-admin' . '/includes/image.php');

//add_action( 'wp', 'er_ss_fetch_and_insert' );

function er_ss_fetch_and_insert() {

	$ss_url = 'http://www.example.com/?format=json';

	$upload_path = array();
	$upload_path = wp_upload_dir();
	$img_path = $upload_path['path'];

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $ss_url );
	curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0' );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	$curl_response = curl_exec( $ch );
	curl_close( $ch ); 
	
	$remote_feed = json_decode( $curl_response, true );

	foreach( $remote_feed['items'] as $feed_item ) {

		$item['id']					= $feed_item['id'];
		$item['author_name']		= $feed_item['author']['displayName'];
		$item['author_thumbnail']	= $feed_item['author']['avatarAssetUrl'];
		$item['canonical_url']		= 'http://www.truthwellbrewed.com/' . $feed_item['urlId'];
		$item['categories'] 		= $feed_item['categories'];
		$item['datetime']			= date( 'Y-m-d H:i:s', $feed_item['publishOn']/1000 );
		$feed['img_name']			= $feed_item['filename'];
		$item['img_path_name']		= $img_path . '/' . $feed_item['filename'];
		$item['remote_img_url']		= $feed_item['assetUrl'];
		$item['tags'] 				= $feed_item['tags'];
		$item['post_title']			= $feed_item['title'];
		$item['url_path']			= $feed_item['fullUrl'];

		er_ss_create_post( $item );
	}
}

function er_ss_create_post( $item ) {

	$meta_id 		= $item['id'];
	$meta_key		= 'ss_id';
	$post_author 	= 1;
	$post_type 		= 'ss';
	$post_title 		 	= $item['post_title'];

	$args = array();
	$args = array(
			
		'meta_query' => array(
	
			array(
				'key'   => $meta_key,
				'value' => $meta_id,
			)
		),
		'post_type'		=> $post_type,
	);

	$post_meta = get_posts( $args );

	if ( empty( $post_meta ) ) {

		$post = array();
		$post = array(

			'post_author'	=> 1,
			'post_date_gmt'	=> $item['datetime'],
			'post_name'		=> sanitize_title( $post_title ), 
			'post_status'   => 'publish',
			'post_title'    => $post_title, 
			'post_type'		=> $post_type,
		);

		$post_id = wp_insert_post( $post );
	
		add_post_meta( $post_id, 'ss_author', $item['author_name'] );
		add_post_meta( $post_id, 'ss_author_thumbnail', $item['author_thumbnail'] );
		add_post_meta( $post_id, $meta_key, $meta_id );
		add_post_meta( $post_id, 'ss_canonical', $item['canonical_url'] );
		add_post_meta( $post_id, 'ss_thumbnail', $item['img_path_name'] );
		add_post_meta( $post_id, 'ss_url_path', $item['url_path'] );

		foreach( $item['categories'] as $category ) {

			add_post_meta( $post_id, 'ss_categories', $category );
		}
		foreach( $item['tags'] as $tag ) {

			add_post_meta( $post_id, 'ss_tags', $tag );
		}

		er_ss_get_remote_img( $item['remote_img_url'], $item['img_path_name'] );
		er_ss_set_featured( $post_id, $item['img_path_name'] );
	}
}

function er_ss_get_remote_img( $remote_img_url, $img_path_name ) {

    $fp = fopen ($img_path_name, 'w+');              
    $ch = curl_init($remote_img_url);
    curl_setopt($ch, CURLOPT_FILE, $fp);          
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1000);      
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_exec($ch);
    curl_close($ch);                              
    fclose($fp);                                
}

function er_ss_set_featured( $post_id, $img_path_name ) {

	$filetype = wp_check_filetype( basename( $img_path_name ), null );

	$wp_upload_dir = wp_upload_dir();

	$attachment = array(
		'guid'           => $wp_upload_dir['url'] . '/' . basename( $img_path_name ), 
		'post_mime_type' => $filetype['type'],
		'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $img_path_name ) ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);

	$attachment_id = wp_insert_attachment( $attachment, $img_path_name, $post_id );
	$attach_data = wp_generate_attachment_metadata( $attachment_id, $img_path_name );
	
	wp_update_attachment_metadata( $attachment_id, $attach_data );
	set_post_thumbnail( $post_id, $attachment_id );
}

/*
/* Deactivate chron on plugin deactivation
*/
register_deactivation_hook(__FILE__, 'er_ss_deactivation' );

function er_ss_deactivation() {
	
	wp_clear_scheduled_hook( 'er_ss_hourly_event' );
}