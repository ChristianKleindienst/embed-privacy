<?php
namespace epiphyt\Embed_Privacy;
use function add_action;
use function delete_post_meta;
use function download_url;
use function explode;
use function file_exists;
use function get_option;
use function get_post;
use function get_post_meta;
use function is_array;
use function is_wp_error;
use function rename;
use function reset;
use function sprintf;
use function str_replace;
use function strpos;
use function unlink;
use function update_post_meta;
use const ARRAY_A;

/**
 * Thumbnails for Embed Privacy.
 * 
 * @since	1.5.0
 *
 * @author	Epiphyt
 * @license	GPL2
 * @package	epiphyt\Embed_Privacy
 */
class Thumbnails {
	const DIRECTORY = WP_CONTENT_DIR . '/uploads/embed-privacy/thumbnails';
	
	/**
	 * @var		array Fields to output
	 */
	public $fields = [];
	
	/**
	 * @var		\epiphyt\Embed_Privacy\Thumbnails
	 */
	private static $instance;
	
	/**
	 * Post Type constructor.
	 */
	public function __construct() {
		self::$instance = $this;
	}
	
	/**
	 * Initialize functions.
	 */
	public function init() {
		if ( ! get_option( 'embed_privacy_download_thumbnails' ) ) {
			return;
		}
		
		add_action( 'before_delete_post', [ $this, 'delete_thumbnails' ] );
		add_action( 'post_updated', [ $this, 'check_orphaned' ], 10, 2 );
		
		add_filter( 'oembed_dataparse', [ $this, 'get_from_provider' ], 10, 3 );
	}
	
	/**
	 * Check and delete orphaned thumbnails.
	 * 
	 * @param	int			$post_id The post ID
	 * @param	\WP_Post	$post The post object
	 */
	public function check_orphaned( $post_id, $post ) {
		$global_metadata = $this->get_metadata();
		$metadata = get_post_meta( $post_id );
		
		foreach ( $metadata as $meta_key => $meta_value ) {
			if ( strpos( $meta_key, 'embed_privacy_thumbnail_' ) === false ) {
				continue;
			}
			
			if ( is_array( $meta_value ) ) {
				$meta_value = reset( $meta_value );
			}
			
			if ( strpos( $meta_key, '_youtube_' ) !== false && strpos( $meta_key, '_url' ) === false ) {
				$id = str_replace( 'embed_privacy_thumbnail_youtube_', '', $meta_key );
				
				if ( strpos( $post->post_content, $id ) === false ) {
					if ( ! $this->is_in_use( $meta_value, $post_id, $global_metadata ) ) {
						$this->delete( $meta_value );
						delete_post_meta( $post_id, $meta_key );
						delete_post_meta( $post_id, $meta_key . '_url' );
					}
				}
			}
		}
	}
	
	/**
	 * Delete a thumbnail.
	 * 
	 * @since	1.5.0
	 * 
	 * @param	string	$filename The thumbnail filename
	 */
	private function delete( $filename ) {
		if ( ! file_exists( self::DIRECTORY . '/' . $filename ) ) {
			return;
		}
		
		unlink( self::DIRECTORY . '/' . $filename );
	}
	
	/**
	 * Delete thumbnails for a given post ID.
	 * 
	 * @since	1.5.0
	 * 
	 * @param	int		$post_id Post ID
	 */
	public function delete_thumbnails( $post_id ) {
		$global_metadata = $this->get_metadata();
		$metadata = get_post_meta( $post_id );
		
		foreach ( $metadata as $meta_key => $meta_value ) {
			if ( strpos( $meta_key, 'embed_privacy_thumbnail_' ) === false ) {
				continue;
			}
			
			if ( is_array( $meta_value ) ) {
				$meta_value = reset( $meta_value );
			}
			
			if ( ! $this->is_in_use( $meta_value, $post_id, $global_metadata ) ) {
				$this->delete( $meta_value );
			}
		}
	}
	
	/**
	 * Get path and URL to an embed thumbnail.
	 * 
	 * @since	1.5.0
	 * 
	 * @param	\WP_Post	$post Post object
	 * @param	string		$url Embedded URL
	 * @return	array Thumbnail path and URL
	 */
	public function get_data( $post, $url ) {
		$thumbnail_path = '';
		$thumbnail_url = '';
		
		if ( strpos( $url, 'youtube.com' ) !== false || strpos( $url, 'youtu.be' ) !== false ) {
			$id = str_replace( [ 'https://www.youtube.com/watch?v=', 'https://youtu.be/' ], '', $url );
			$thumbnail = get_post_meta( $post->ID, 'embed_privacy_thumbnail_youtube_' . $id, true );
			$thumbnail_path = self::DIRECTORY . '/' . $thumbnail;
			
			if ( $thumbnail && file_exists( $thumbnail_path ) ) {
				$relative_path = str_replace( ABSPATH, '', $thumbnail_path );
				$thumbnail_url = home_url( $relative_path );
			}
		}
		
		return [
			'thumbnail_path' => $thumbnail_path,
			'thumbnail_url' => $thumbnail_url,
		];
	}
	
	/**
	 * Get embed thumbnails from the embed provider.
	 * 
	 * @since	1.5.0
	 * 
	 * @param	string	$return The returned oEmbed HTML
	 * @param	object	$data A data object result from an oEmbed provider
	 * @param	string	$url The URL of the content to be embedded
	 * @return	string The returned oEmbed HTML
	 */
	public function get_from_provider( $return, $data, $url ) {
		if ( strpos( $url, 'youtube.com' ) !== false || strpos( $url, 'youtu.be' ) !== false ) {
			$thumbnail_url = $data->thumbnail_url;
			// format: <id>/<thumbnail-name>.jpg
			$extracted = str_replace( 'https://i.ytimg.com/vi/', '', $thumbnail_url );
			// first part is the ID
			$parts = explode( '/', $extracted );
			$id = isset( $parts[0] ) ? $parts[0] : false;
			
			if ( $id ) {
				$this->set_youtube_thumbnail( $id, $url );
			}
		}
		
		return $return;
	}
	
	/**
	 * Get a unique instance of the class.
	 * 
	 * @return	\epiphyt\Embed_Privacy\Thumbnails The single instance of this class
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	/**
	 * Get all thumbnail metadata of all posts.
	 * 
	 * @since	1.5.0
	 * 
	 * @return	array All thumbnail metadata
	 */
	private function get_metadata() {
		global $wpdb;
		
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT	post_id,
									meta_value
				FROM				$wpdb->postmeta
				WHERE				meta_key LIKE %s",
				'embed_privacy_thumbnail_%'
			),
			ARRAY_A
		);
	}
	
	/**
	 * Check whether a thumbnail is in use in another post.
	 * 
	 * @since	1.5.0
	 * 
	 * @param	string	$meta_value The thumbnail filename
	 * @param	int		$post_id The post ID of the current post
	 * @param	array	$global_metadata Global metadata to check in
	 * @return	bool Whether a thumbnail is in use in another post
	 */
	private function is_in_use( $meta_value, $post_id, $global_metadata ) {
		$is_in_use = false;
		
		foreach ( $global_metadata as $global_meta_value ) {
			if ( (int) $global_meta_value['post_id'] === $post_id ) {
				continue;
			}
			
			if ( $global_meta_value['meta_value'] === $meta_value ) {
				$is_in_use = true;
				break;
			}
		}
		
		return $is_in_use;
	}
	
	/**
	 * Download and save a YouTube thumbnail.
	 * 
	 * @since	1.5.0
	 * 
	 * @param	string	$id YouTube video ID
	 * @param	string	$url YouTube video URL
	 */
	public function set_youtube_thumbnail( $id, $url ) {
		$post = get_post();
		
		if ( ! $post ) {
			return;
		}
		
		require_once ABSPATH . 'wp-admin/includes/file.php';
		
		// list of images we try to retrieve
		// see: https://stackoverflow.com/a/2068371
		$images = [
			'maxresdefault',
			'hqdefault',
			'0',
		];
		$thumbnail_url = 'https://img.youtube.com/vi/%1$s/%2$s.jpg';
		
		foreach ( $images as $image ) {
			$file = download_url( sprintf( $thumbnail_url, $id, $image ) );
			
			if ( is_wp_error( $file ) ) {
				continue;
			}
			
			rename( $file, self::DIRECTORY . '/youtube-' . $id . '-' . $image . '.jpg' );
			update_post_meta( $post->ID, 'embed_privacy_thumbnail_youtube_' . $id, 'youtube-' . $id . '-' . $image . '.jpg' );
			update_post_meta( $post->ID, 'embed_privacy_thumbnail_youtube_' . $id . '_url', $url );
			break;
		}
	}
}
