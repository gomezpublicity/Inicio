<?php
/*
Plugin Name: WordPress Importer
Plugin URI: http://wordpress.org/extend/plugins/wordpress-importer/
Description: Import posts, pages, comments, custom fields, categories, tags and more from a WordPress export file.
Author: wordpressdotorg
Author URI: http://wordpress.org/
Version: 0.6.1 (modified by RoseMary - no backward compatibility!)
Text Domain: rosemary
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

// include WXR file parsers
require 'parsers.php';

/**
 * WordPress Importer class for managing the import process of a WXR file
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class WP_Import extends WP_Importer {
	var $overwrite = true;				// Overwrite existing data
	var $debug = false;					// Enable debug output
	var $uploads_folder = 'uploads';	// Folder with images in the import data
	var $start_time = 0;				// Import start time
	var $max_time = 0;					// max_execution_time for PHP scripts
	var $posts_at_once = 10;			// How many posts imported at one AJAX call

	var $max_wxr_version = 1.2; // max. supported WXR version

	var $id; // WXR attachment ID

	// information to import from WXR file
	var $version;
	var $authors = array();
	var $posts = array();
	var $terms = array();
	var $categories = array();
	var $tags = array();
	var $base_url = '';

	// mappings from old information to new
	var $processed_authors = array();
	var $author_mapping = array();
	var $processed_terms = array();
	var $processed_posts = array();
	var $post_orphans = array();
	var $processed_menu_items = array();
	var $menu_item_orphans = array();
	var $missing_menu_items = array();

	var $fetch_attachments = false;
	var $url_remap = array();
	var $featured_images = array();

	var $uploads_url = '';
	var $uploads_dir = '';
	
	var $demo_url = '';
	
	var $start_from_id = 0;
	var $import_log = '';

	function __construct() { 
		$uploads_info = wp_upload_dir();
		$this->uploads_dir = $uploads_info['basedir'];
		$this->uploads_url = $uploads_info['baseurl'];			
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	function import( $file ) {

		// Increase time and memory limits
		@set_time_limit(max(30, min(1800, (int) rosemary_get_theme_option('admin_dummy_timeout'))));
		$this->start_time = time();
		$tm = max(30, ini_get('max_execution_time'));
		$this->max_time = $tm - min(10, round($tm*0.33));

		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		$this->import_start( $file );

		if ($this->debug) dfl(esc_html__('Process authors', 'rosemary'));
		$this->get_author_mapping();

		wp_suspend_cache_invalidation( true );
		
		$error = false;
		$result = 0;
		try {
			if ($this->start_from_id == 0) {
				$this->process_categories();
				$this->process_tags();
				$this->process_terms();
			}
			$result = $this->process_posts();

		} catch (Exception $e) {

			$error = true;
			dfl( sprintf(esc_html__('Error while import: %s', 'rosemary'), $e->getMessage()) );

		}

		wp_suspend_cache_invalidation( false );

		// update incorrect/missing information in the DB
		if ($result >= 100) {
			$this->backfill_parents();
			$this->backfill_attachment_urls();
			$this->remap_featured_images();
		}

		$this->import_end();

		return $error ? 100 : $result;
	}

	/**
	 * Parses the WXR file and prepares us for the task of processing parsed data
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	function import_start( $file ) {

		if ($this->debug) dfl(esc_html__('Start import posts', 'rosemary'));

		if ( ! is_file($file) ) {
			dfl( sprintf(esc_html__('Sorry, there has been an error: %s', 'rosemary'), esc_html__( 'The file does not exist, please try again.', 'rosemary')) );
			$this->footer();
			die();
		}
		
		$import_data = $this->parse( $file );

		if ( is_wp_error( $import_data ) ) {
			dfl( sprintf(esc_html__('Sorry, there has been an error: %s', 'rosemary'), $import_data->get_error_message()) );
			$this->footer();
			die();
		}

		$this->version = $import_data['version'];
		$this->get_authors_from_import( $import_data );
		$this->posts = $import_data['posts'];
		$this->terms = $import_data['terms'];
		$this->categories = $import_data['categories'];
		$this->tags = $import_data['tags'];
		$this->base_url = esc_url( $import_data['base_url'] );

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		do_action( 'import_start' );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	function import_end() {

		if ($this->debug) dfl(esc_html__('Finish import posts', 'rosemary'));

		wp_import_cleanup( $this->id );

		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		if ($this->debug) dfl(esc_html__('All done', 'rosemary'));

		do_action( 'import_end' );
	}

	/**
	 * Retrieve authors from parsed WXR data
	 *
	 * Uses the provided author information from WXR 1.1 files
	 * or extracts info from each post for WXR 1.0 files
	 *
	 * @param array $import_data Data returned by a WXR parser
	 */
	function get_authors_from_import( $import_data ) {

		if ($this->debug) dfl( esc_html__('Get authors from import', 'rosemary') );

		if ( ! empty( $import_data['authors'] ) ) {
			$this->authors = $import_data['authors'];
		// no author information, grab it from the posts
		} else {
			foreach ( $import_data['posts'] as $post ) {
				$login = sanitize_user( $post['post_author'], true );
				if ( empty( $login ) ) {
					if ($this->debug) dfl( sprintf(esc_html__( 'Failed to import author "%s". Their posts will be attributed to the current user.', 'rosemary' ), $post['post_author']) );
					continue;
				}

				if ( ! isset($this->authors[$login]) )
					$this->authors[$login] = array(
						'author_login' => $login,
						'author_display_name' => $post['post_author']
					);
			}
		}
	}

	/**
	 * Map old author logins to local user IDs based on decisions made
	 * in import options form. Can map to an existing user, create a new user
	 * or falls back to the current user in case of error with either of the previous
	 */
	function get_author_mapping() {
		if ( ! isset( $_POST['imported_authors'] ) )
			return;

		if ($this->debug) dfl( esc_html__('Author mapping', 'rosemary') );

		$create_users = $this->allow_create_users();

		foreach ( (array) $_POST['imported_authors'] as $i => $old_login ) {
			// Multisite adds strtolower to sanitize_user. Need to sanitize here to stop breakage in process_posts.
			$santized_old_login = sanitize_user( $old_login, true );
			$old_id = isset( $this->authors[$old_login]['author_id'] ) ? intval($this->authors[$old_login]['author_id']) : false;

			if ( ! empty( $_POST['user_map'][$i] ) ) {
				$user = get_userdata( intval($_POST['user_map'][$i]) );
				if ( isset( $user->ID ) ) {
					if ( $old_id )
						$this->processed_authors[$old_id] = $user->ID;
					$this->author_mapping[$santized_old_login] = $user->ID;
				}
			} else if ( $create_users ) {
				if ( ! empty($_POST['user_new'][$i]) ) {
					$user_id = wp_create_user( $_POST['user_new'][$i], wp_generate_password() );
				} else if ( $this->version != '1.0' ) {
					$user_data = array(
						'user_login' => $old_login,
						'user_pass' => wp_generate_password(),
						'user_email' => isset( $this->authors[$old_login]['author_email'] ) ? $this->authors[$old_login]['author_email'] : '',
						'display_name' => $this->authors[$old_login]['author_display_name'],
						'first_name' => isset( $this->authors[$old_login]['author_first_name'] ) ? $this->authors[$old_login]['author_first_name'] : '',
						'last_name' => isset( $this->authors[$old_login]['author_last_name'] ) ? $this->authors[$old_login]['author_last_name'] : '',
					);
					$user_id = wp_insert_user( $user_data );
				}

				if ( ! is_wp_error( $user_id ) ) {
					if ( $old_id )
						$this->processed_authors[$old_id] = $user_id;
					$this->author_mapping[$santized_old_login] = $user_id;
					if ($this->debug) dfl( sprintf(esc_html__('Author "%s" imported.', 'rosemary'), $this->authors[$old_login]['author_display_name']) );
				} else {
					if ($this->debug) dfl( sprintf(esc_html__('Failed to create new user for %s. Their posts will be attributed to the current user. Message: %s', 'rosemary'), $this->authors[$old_login]['author_display_name'], $user_id->get_error_message()) );
				}
			}

			// failsafe: if the user_id was invalid, default to the current user
			if ( ! isset( $this->author_mapping[$santized_old_login] ) ) {
				if ( $old_id )
					$this->processed_authors[$old_id] = (int) get_current_user_id();
				$this->author_mapping[$santized_old_login] = (int) get_current_user_id();
			}
		}
	}

	/**
	 * Compare two terms for sorting
	 */
	function compare_terms($t1, $t2) {
		if (isset($t1['term_id']))
			return (int) $t1['term_id'] < (int) $t2['term_id'] ? -1 : ( (int) $t1['term_id'] > (int) $t2['term_id'] ? 1 : 0);
		else if (isset($t1['post_id']))
			return (int) $t1['post_id'] < (int) $t2['post_id'] ? -1 : ( (int) $t1['post_id'] > (int) $t2['post_id'] ? 1 : 0);
		else
			return (int) $t1 < (int) $t2 ? -1 : ( (int) $t1 > (int) $t2 ? 1 : 0);
	}
	
	
	/**
	 * Create new categories based on import information
	 *
	 * Doesn't create a new category if its slug already exists
	 */
	function process_categories() {

		if ($this->debug) dfl( esc_html__('Process categories', 'rosemary') );

		$this->categories = apply_filters( 'wp_import_categories', $this->categories );

		if ( empty( $this->categories ) )
			return;
		
		usort($this->categories, array($this, 'compare_terms'));
		
		foreach ( $this->categories as $cat ) {
			// if the category already exists leave it alone
			$term_id = term_exists( $cat['category_nicename'], 'category' );
			if ( $term_id ) {
				if ( is_array($term_id) ) $term_id = $term_id['term_id'];
				if ( isset($cat['term_id']) )
					$this->processed_terms[intval($cat['term_id'])] = (int) $term_id;
				continue;
			}
			$category_parent = empty( $cat['category_parent'] ) ? 0 : category_exists( $cat['category_parent'] );
			$category_description = isset( $cat['category_description'] ) ? $cat['category_description'] : '';
			$catarr = array(
				'category_nicename' => $cat['category_nicename'],
				'category_parent' => $category_parent,
				'cat_name' => $cat['cat_name'],
				'category_description' => $category_description
			);
			$id = wp_insert_category( $catarr );

			if ( ! is_wp_error( $id ) ) {
				if ( isset($cat['term_id']) ) {
					if ($this->overwrite) {
						if ($cat['term_id']!=$id) {
							global $wpdb;
							$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->term_taxonomy) . " SET term_id=%d, parent=%d WHERE term_id=%d", $cat['term_id'], $category_parent, $id));
							$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->terms) . " SET term_id=%d WHERE term_id=%d LIMIT 1", $cat['term_id'], $id));
							if ($id < $cat['term_id']) $wpdb->query($wpdb->prepare("ALTER TABLE " . esc_sql($wpdb->terms) . " AUTO_INCREMENT=%d", $cat['term_id']+1));
							$id = $cat['term_id'];
						}
					} //else
						$this->processed_terms[intval($cat['term_id'])] = $id;
					if ( $this->debug ) dfl( sprintf( esc_html__( 'Category "%s" imported.', 'rosemary' ).'<br>', esc_html($cat['category_nicename']) ) );
				}
			} else {
				dfl( sprintf( esc_html__( 'Failed to import category "%s"', 'rosemary' ), esc_html($cat['category_nicename']), $id->get_error_message() ) );
				continue;
			}
		}

		unset( $this->categories );
	}

	/**
	 * Create new post tags based on import information
	 *
	 * Doesn't create a tag if its slug already exists
	 */
	function process_tags() {

		if ($this->debug) dfl( esc_html__('Process tags', 'rosemary') );

		$this->tags = apply_filters( 'wp_import_tags', $this->tags );

		if ( empty( $this->tags ) )
			return;

		usort($this->tags, array($this, 'compare_terms'));

		foreach ( $this->tags as $tag ) {
			// if the tag already exists leave it alone
			$term_id = term_exists( $tag['tag_slug'], 'post_tag' );
			if ( $term_id ) {
				if ( is_array($term_id) ) $term_id = $term_id['term_id'];
				if ( isset($tag['term_id']) )
					$this->processed_terms[intval($tag['term_id'])] = (int) $term_id;
				continue;
			}

			$tag_desc = isset( $tag['tag_description'] ) ? $tag['tag_description'] : '';
			$tagarr = array( 'slug' => $tag['tag_slug'], 'description' => $tag_desc );

			$id = wp_insert_term( $tag['tag_name'], 'post_tag', $tagarr );

			if ( ! is_wp_error( $id ) ) {
				if ( isset($tag['term_id']) ) {
					if ($this->overwrite) {
						if ($tag['term_id']!=$id['term_id']) {
							global $wpdb;
							$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->term_taxonomy) . " SET term_id=%d WHERE term_id=%d", $tag['term_id'], $id['term_id']));
							$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->terms) . " SET term_id=%d WHERE term_id=%d LIMIT 1", $tag['term_id'], $id['term_id']));
							if ($id['term_id'] < $tag['term_id']) $wpdb->query($wpdb->prepare("ALTER TABLE " . esc_sql($wpdb->terms) . " AUTO_INCREMENT=%d", $tag['term_id']+1));
							$id['term_id'] = $tag['term_id'];
						}
					} //else
						$this->processed_terms[intval($tag['term_id'])] = $id['term_id'];
					if ( $this->debug ) dfl( sprintf( esc_html__( 'Tag "%s" imported.', 'rosemary' ), $tag['tag_name']) );
				}
			} else {
				dfl( sprintf( esc_html__( 'Failed to import post tag "%s". Message: %s', 'rosemary' ), $tag['tag_name'], $id->get_error_message()) );
				continue;
			}
		}

		unset( $this->tags );
	}

	/**
	 * Create new terms based on import information
	 *
	 * Doesn't create a term its slug already exists
	 */
	function process_terms() {

		if ($this->debug) dfl( esc_html__('Process terms', 'rosemary') );

		$this->terms = apply_filters( 'wp_import_terms', $this->terms );

		if ( empty( $this->terms ) )
			return;

		usort($this->terms, array($this, 'compare_terms'));

		foreach ( $this->terms as $term ) {
			// if the term already exists in the correct taxonomy leave it alone
			$term_id = term_exists( $term['slug'], $term['term_taxonomy'] );
			if ( $term_id ) {
				if ( is_array($term_id) ) $term_id = $term_id['term_id'];
				if ( isset($term['term_id']) )
					$this->processed_terms[intval($term['term_id'])] = (int) $term_id;
				continue;
			}

			if ( empty( $term['term_parent'] ) ) {
				$parent = 0;
			} else {
				$parent = $this->overwrite ? $term['term_parent'] : term_exists( $term['term_parent'], $term['term_taxonomy'] );
				if ( is_array( $parent ) ) $parent = $parent['term_id'];
			}
			$description = isset( $term['term_description'] ) ? $term['term_description'] : '';
			$termarr = array( 'slug' => $term['slug'], 'description' => $description, 'parent' => intval($parent) );

			$id = wp_insert_term( $term['term_name'], $term['term_taxonomy'], $termarr );
			if ( ! is_wp_error( $id ) ) {
				if ( isset($term['term_id']) ) {
					if ($this->overwrite) {
						if ($term['term_id']!=$id['term_id']) {
							global $wpdb;
							$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->term_taxonomy) . " SET term_id=%d, parent=%d WHERE term_id=%d", $term['term_id'], $parent, $id['term_id']));
							$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->terms) . " SET term_id=%d WHERE term_id=%d LIMIT 1", $term['term_id'], $id['term_id']));
							if ($id['term_id'] < $term['term_id']) $wpdb->query($wpdb->prepare("ALTER TABLE " . esc_sql($wpdb->terms) . " AUTO_INCREMENT=%d", $term['term_id']+1));
							$id['term_id'] = $term['term_id'];
						}
					} //else
						$this->processed_terms[intval($term['term_id'])] = $id['term_id'];
					if ( $this->debug ) dfl( sprintf(esc_html__( 'Taxonomy "%s": term "%s" imported.', 'rosemary' ), $term['term_taxonomy'], $term['term_name']) );
				}
			} else {
				dfl( sprintf(esc_html__( 'Failed to import term %s: "%s". Message: %s', 'rosemary' ), $term['term_taxonomy'], $term['term_name'], $id->get_error_message()) );
				continue;
			}
		}

		unset( $this->terms );
	}

	/**
	 * Create new posts based on import information
	 *
	 * Posts marked as having a parent which doesn't exist will become top level items.
	 * Doesn't create a new post if: the post type doesn't exist, the given post ID
	 * is already noted as imported or a post with the same title and date already exists.
	 * Note that new/updated terms, comments and meta are imported for the last of the above.
	 */
	function process_posts() {
	
		if ($this->debug) dfl( sprintf(esc_html__('Process posts %s', 'rosemary'), $this->start_from_id > 0
																						? sprintf(esc_html__('(continue from ID = %s)', 'rosemary'), $this->start_from_id)
																						: ''
											)
								);

		$this->posts = apply_filters( 'wp_import_posts', $this->posts );

		usort($this->posts, array($this, 'compare_terms'));
		
		$posts_all = count($this->posts);
		$posts_counter = 0;
		$posts_imported = 0;
		$result = 0;
		
		foreach ( $this->posts as $post ) {

			$posts_counter++;

			$original_post_ID = !empty($post['post_id']) ? $post['post_id'] : 0;
			
			if (!empty($post['post_id']) && $post['post_id'] <= $this->start_from_id) continue;
			
			$post = apply_filters( 'wp_import_post_data_raw', $post );

			if ( ! post_type_exists( $post['post_type'] ) ) {

				dfl( sprintf( esc_html__( 'Failed to import post "%s": Invalid post type "%s"', 'rosemary' ), $post['post_title'], $post['post_type']) );
				do_action( 'wp_import_post_exists', $post );

			} else if ( !empty( $post['post_id'] ) && isset( $this->processed_posts[$post['post_id']] )  ) {

				// Do nothing

			} else if ( $post['status'] == 'auto-draft' ) {

				// Do nothing

			} else if ( 'nav_menu_item' == $post['post_type'] ) {

				$this->process_menu_item( $post );

			} else {

				do {	
					$post_type_object = get_post_type_object( $post['post_type'] );
		
					$post_exists = post_exists( $post['post_title'], '', $post['post_date'] );
		
					$original_post_ID = $post['post_id'];
		
					if ( $post_exists && get_post_type( $post_exists ) == $post['post_type'] ) {
						dfl( sprintf(esc_html__('%s "%s" already exists.', 'rosemary'), $post_type_object->labels->singular_name, $post['post_title']) );
						$comment_post_ID = $post_id = $post_exists;
					} else {
						$post_parent = (int) $post['post_parent'];
						if ( !$this->overwrite && $post_parent ) {
							// if we already know the parent, map it to the new local ID
							if ( isset( $this->processed_posts[$post_parent] ) ) {
								$post_parent = $this->processed_posts[$post_parent];
							// otherwise record the parent for later
							} else {
								$this->post_orphans[intval($post['post_id'])] = $post_parent;
								$post_parent = 0;
							}
						}
		
						// map the post author
						$author = sanitize_user( $post['post_author'], true );
						if ( isset( $this->author_mapping[$author] ) )
							$author = $this->author_mapping[$author];
						else
							$author = (int) get_current_user_id();
		
						$postdata = array(
							'import_id' => $post['post_id'],
							'post_author' => $author,
							'post_date' => $post['post_date'],
							'post_date_gmt' => $post['post_date_gmt'],
							'post_content' => $this->replace_uploads($post['post_content']),
							'post_excerpt' => $post['post_excerpt'],
							'post_title' => $post['post_title'],
							'post_status' => $post['status'],
							'post_name' => $post['post_name'],
							'comment_status' => $post['comment_status'],
							'ping_status' => $post['ping_status'],
							'guid' => $post['guid'],
							'post_parent' => $post_parent,
							'menu_order' => $post['menu_order'],
							'post_type' => $post['post_type'],
							'post_password' => $post['post_password']
						);
		
						$postdata = apply_filters( 'wp_import_post_data_processed', $postdata, $post );
		
						if ( 'attachment' == $postdata['post_type'] ) {
							$remote_url = ! empty($post['attachment_url']) ? $post['attachment_url'] : $post['guid'];
		
							// try to use _wp_attached file for upload folder placement to ensure the same location as the export site
							// e.g. location is 2003/05/image.jpg but the attachment post_date is 2010/09, see media_handle_upload()
							$postdata['upload_date'] = $post['post_date'];
							if ( isset( $post['postmeta'] ) ) {
								foreach( $post['postmeta'] as $meta ) {
									if ( $meta['key'] == '_wp_attached_file' ) {
										if ( preg_match( '%^[0-9]{4}/[0-9]{2}%', $meta['value'], $matches ) )
											$postdata['upload_date'] = $matches[0];
										break;
									}
								}
							}
							$comment_post_ID = $post_id = $this->process_attachment( $postdata, $remote_url );
						} else {
							$comment_post_ID = $post_id = wp_insert_post( $postdata, true );
							do_action( 'wp_import_insert_post', $post_id, $original_post_ID, $postdata, $post );
						}
		
						if ( is_wp_error( $post_id ) ) {
							dfl( sprintf(esc_html__( 'Failed to import %s: "%s". Message: %s', 'rosemary' ), $post_type_object->labels->singular_name, $post['post_title'], $post_id->get_error_message()) );
							break;
						}
		
						if ($this->overwrite) {
							if ($post_id != $original_post_ID) {
								global $wpdb;
								$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->term_relationships) . " SET object_id=%d WHERE object_id=%d", $original_post_ID, $post_id));
								$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->postmeta) . " SET post_id=%d WHERE post_id=%d", $original_post_ID, $post_id));
								$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->posts) . " SET ID=%d, post_parent=%d WHERE ID=%d LIMIT 1", $original_post_ID, $post_parent, $post_id));
								if ($post_id < $original_post_ID) $wpdb->query($wpdb->prepare("ALTER TABLE " . esc_sql($wpdb->posts) . " AUTO_INCREMENT=%d", $original_post_ID+1));
								$comment_post_ID = $post_id = $original_post_ID;
							}
						}
		
						if ( $post['is_sticky'] == 1 )
							stick_post( $post_id );
				
						if ( $this->debug ) dfl( sprintf( esc_html__( '%s "%s" (ID=%s) imported.', 'rosemary' ), $post_type_object->labels->singular_name, $post['post_title'], $post_id) );
					}
		
					// map pre-import ID to local ID
					$this->processed_posts[intval($post['post_id'])] = (int) $post_id;
		
					if ( ! isset( $post['terms'] ) )
						$post['terms'] = array();
		
					$post['terms'] = apply_filters( 'wp_import_post_terms', $post['terms'], $post_id, $post );
		
					// add categories, tags and other terms
					if ( ! empty( $post['terms'] ) ) {
						$terms_to_set = array();
						foreach ( $post['terms'] as $term ) {
							// back compat with WXR 1.0 map 'tag' to 'post_tag'
							$taxonomy = ( 'tag' == $term['domain'] ) ? 'post_tag' : $term['domain'];
							$term_exists = term_exists( $term['slug'], $taxonomy );
							$term_id = is_array( $term_exists ) ? $term_exists['term_id'] : $term_exists;
							if ( ! $term_id ) {
								$t = wp_insert_term( $term['name'], $taxonomy, array( 'slug' => $term['slug'] ) );
								if ( ! is_wp_error( $t ) ) {
									$term_id = $t['term_id'];
									do_action( 'wp_import_insert_term', $t, $term, $post_id, $post );
									//if ( $this->debug ) { dfl( sprintf( esc_html__( 'Post term %s: "%s" imported.', 'rosemary' ).'<br>', esc_html($taxonomy), esc_html($term['name']) ) );
								} else {
									dfl( sprintf( esc_html__( 'Failed to import post term %s: "%s". Message: %s', 'rosemary' ), $taxonomy, $term['name'], $t->get_error_message()) );
									do_action( 'wp_import_insert_term_failed', $t, $term, $post_id, $post );
									break;
								}
							}
							$terms_to_set[$taxonomy][] = intval( $term_id );
						}
		
						foreach ( $terms_to_set as $tax => $ids ) {
							$tt_ids = wp_set_post_terms( $post_id, $ids, $tax );
							do_action( 'wp_import_set_post_terms', $tt_ids, $ids, $tax, $post_id, $post );
						}
						unset( $post['terms'], $terms_to_set );
					}
		
					if ( ! isset( $post['comments'] ) )
						$post['comments'] = array();
		
					$post['comments'] = apply_filters( 'wp_import_post_comments', $post['comments'], $post_id, $post );
		
					// add/update comments
					if ( ! empty( $post['comments'] ) ) {
						$num_comments = 0;
						$inserted_comments = array();
						foreach ( $post['comments'] as $comment ) {
							$comment_id	= $comment['comment_id'];
							$newcomments[$comment_id]['comment_post_ID']      = $comment_post_ID;
							$newcomments[$comment_id]['comment_author']       = $comment['comment_author'];
							$newcomments[$comment_id]['comment_author_email'] = $comment['comment_author_email'];
							$newcomments[$comment_id]['comment_author_IP']    = $comment['comment_author_IP'];
							$newcomments[$comment_id]['comment_author_url']   = $comment['comment_author_url'];
							$newcomments[$comment_id]['comment_date']         = $comment['comment_date'];
							$newcomments[$comment_id]['comment_date_gmt']     = $comment['comment_date_gmt'];
							$newcomments[$comment_id]['comment_content']      = $comment['comment_content'];
							$newcomments[$comment_id]['comment_approved']     = $comment['comment_approved'];
							$newcomments[$comment_id]['comment_type']         = $comment['comment_type'];
							$newcomments[$comment_id]['comment_parent'] 	  = $comment['comment_parent'];
							$newcomments[$comment_id]['commentmeta']          = isset( $comment['commentmeta'] ) ? $comment['commentmeta'] : array();
							if ( isset( $this->processed_authors[$comment['comment_user_id']] ) )
								$newcomments[$comment_id]['user_id'] = $this->processed_authors[$comment['comment_user_id']];
						}
						ksort( $newcomments );
		
						foreach ( $newcomments as $key => $comment ) {
							// if this is a new post we can skip the comment_exists() check
							if ( ! $post_exists || ! comment_exists( $comment['comment_author'], $comment['comment_date'] ) ) {
								if ( isset( $inserted_comments[$comment['comment_parent']] ) )
									$comment['comment_parent'] = $inserted_comments[$comment['comment_parent']];
								$comment = wp_filter_comment( $comment );
								$inserted_comments[$key] = wp_insert_comment( $comment );
								do_action( 'wp_import_insert_comment', $inserted_comments[$key], $comment, $comment_post_ID, $post );
		
								foreach( $comment['commentmeta'] as $meta ) {
									$value = rosemary_unserialize( $meta['value'] );
									add_comment_meta( $inserted_comments[$key], $meta['key'], $value );
								}
		
								$num_comments++;
							}
						}
						unset( $newcomments, $inserted_comments, $post['comments'] );
					}
		
					if ( ! isset( $post['postmeta'] ) )
						$post['postmeta'] = array();
		
					$post['postmeta'] = apply_filters( 'wp_import_post_meta', $post['postmeta'], $post_id, $post );
					// add/update post meta
					if ( ! empty( $post['postmeta'] ) ) {
						foreach ( $post['postmeta'] as $meta ) {
							$key = apply_filters( 'import_post_meta_key', $meta['key'], $post_id, $post );
							$value = false;
		
							if ( '_edit_last' == $key ) {
								if ( isset( $this->processed_authors[intval($meta['value'])] ) )
									$value = $this->processed_authors[intval($meta['value'])];
								else
									$key = false;
							}
		
							if ( $key ) {
								// export gets meta straight from the DB so could have a serialized string
								$replace = true;
								if ( ! $value ) {
									$value = $meta['value'];
									if (is_serialized($value)) {
										$value = rosemary_unserialize( $value );
										if ( $value===false ) {
											if ($this->debug) dfl( sprintf(esc_html__('Post (ID=%s) - error unserialize postmeta: %s', 'rosemary'), $post['post_id'], $key) );
											$value = $meta['value'];
											$replace = false;
										}
									}
								}
								if ($replace) {
									$value = $this->replace_uploads($value);
								}
								update_post_meta( $post_id, $key, $value );
								do_action( 'import_post_meta', $post_id, $key, $value );
		
								// if the post has a featured image, take note of this in case of remap
								if ( '_thumbnail_id' == $key )
									$this->featured_images[$post_id] = (int) $value;
							}
						}
					}
				} while (false);
			}

			// Get max_execution_time
			$admin_tm = max(0, min(1800, (int) rosemary_get_theme_option('admin_dummy_timeout')));
			$tm = max(30, (int) ini_get('max_execution_time'));
			if ($tm < $admin_tm) {
				@set_time_limit($admin_tm);
				$tm = max(30, ini_get('max_execution_time'));
				$this->max_time = $tm - min(10, round($tm*0.33));
			}
			// Save into log last
			$result = $posts_counter < $posts_all ? round($posts_counter / $posts_all * 100) : 100;
			rosemary_fpc($this->import_log, trim(max($original_post_ID, $this->start_from_id)) . '|' . trim($result));
			if ($this->debug) dfl( sprintf( esc_html__('Post (ID=%s) imported. Current import progress: %s. Time limit: %s sec. Elapsed time: %s sec.', 'rosemary'), $post['post_id'], $result.'%', $this->max_time, time() - $this->start_time) );

			// Break import after timeout or if leave one post and execution time > half of max_time
			$posts_imported++;
			if (time() - $this->start_time >= $this->max_time || $posts_imported >= $this->posts_at_once) break;

		}

		unset( $this->posts );
		
		return $result;
	}

	/**
	 * Attempt to create a new menu item from import data
	 *
	 * Fails for draft, orphaned menu items and those without an associated nav_menu
	 * or an invalid nav_menu term. If the post type or term object which the menu item
	 * represents doesn't exist then the menu item will not be imported (waits until the
	 * end of the import to retry again before discarding).
	 *
	 * @param array $item Menu item details from WXR file
	 */
	function process_menu_item( $item ) {

		if ($this->debug) dfl( esc_html__('Process menu item', 'rosemary') );

		// skip draft, orphaned menu items
		if ( 'draft' == $item['status'] )
			return;

		$menu_slug = false;
		if ( isset($item['terms']) ) {
			// loop through terms, assume first nav_menu term is correct menu
			foreach ( $item['terms'] as $term ) {
				if ( 'nav_menu' == $term['domain'] ) {
					$menu_slug = $term['slug'];
					break;
				}
			}
		}

		// no nav_menu term associated with this menu item
		if ( ! $menu_slug ) {
			if ($this->debug) dfl( sprintf(esc_html__( 'Menu item "%s" (id=%s) skipped due to missing menu slug', 'rosemary' ), $item['post_title'], $item['post_id']) );
			return;
		}

		$menu_id = term_exists( $menu_slug, 'nav_menu' );
		if ( ! $menu_id ) {
			if ($this->debug) dfl( sprintf( esc_html__( 'Menu item "%s" (id=%s) skipped due to invalid menu slug: %s', 'rosemary' ), $item['post_title'], $item['post_id'], $menu_slug) );
			return;
		} else {
			$menu_id = is_array( $menu_id ) ? $menu_id['term_id'] : $menu_id;
		}

		foreach ( $item['postmeta'] as $meta )
			${$meta['key']} = $meta['value'];

		if (!$this->overwrite) {
			if ( 'taxonomy' == $_menu_item_type && isset( $this->processed_terms[intval($_menu_item_object_id)] ) ) {
				$_menu_item_object_id = $this->processed_terms[intval($_menu_item_object_id)];
			} else if ( 'post_type' == $_menu_item_type && isset( $this->processed_posts[intval($_menu_item_object_id)] ) ) {
				$_menu_item_object_id = $this->processed_posts[intval($_menu_item_object_id)];
			} else if ( 'custom' != $_menu_item_type ) {
				// associated object is missing or not imported yet, we'll retry later
				$this->missing_menu_items[] = $item;
				return;
			}
	
			if ( isset( $this->processed_menu_items[intval($_menu_item_menu_item_parent)] ) ) {
				$_menu_item_menu_item_parent = $this->processed_menu_items[intval($_menu_item_menu_item_parent)];
			} else if ( $_menu_item_menu_item_parent ) {
				$this->menu_item_orphans[intval($item['post_id'])] = (int) $_menu_item_menu_item_parent;
				$_menu_item_menu_item_parent = 0;
			}
		}

		// wp_update_nav_menu_item expects CSS classes as a space separated string
		$_menu_item_classes = rosemary_unserialize( $_menu_item_classes );
		if ( is_array( $_menu_item_classes ) )
			$_menu_item_classes = implode( ' ', $_menu_item_classes );
		
		// Replace URL from our demo site to real site
		if ($_menu_item_type == 'custom' && strpos($_menu_item_url, $this->demo_url) !== false) {
			$_menu_item_url = str_replace($this->demo_url, get_site_url() . (rosemary_substr($this->demo_url, -1)=='/' ? '/' : ''), $_menu_item_url);
		}

		$args = array(
			'menu-item-object-id' => $_menu_item_object_id,
			'menu-item-object' => $_menu_item_object,
			'menu-item-parent-id' => $_menu_item_menu_item_parent,
			'menu-item-position' => intval( $item['menu_order'] ),
			'menu-item-type' => $_menu_item_type,
			'menu-item-title' => $item['post_title'],
			'menu-item-url' => $_menu_item_url,
			'menu-item-description' => $item['post_content'],
			'menu-item-attr-title' => $item['post_excerpt'],
			'menu-item-target' => $_menu_item_target,
			'menu-item-classes' => $_menu_item_classes,
			'menu-item-xfn' => $_menu_item_xfn,
			'menu-item-status' => $item['status']
		);
		if (isset($_item_custom_data)) {
			$args['_item_custom_data'] = $_item_custom_data;
		}
		
		$id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( $id && ! is_wp_error( $id ) ) {
			if ($this->overwrite) {
				global $wpdb;
				if ($item['post_id'] != $id) {
					// Replace generated ID with original menu item ID
					$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->term_relationships) . " SET object_id=%d WHERE object_id=%d", $item['post_id'], $id));
					$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->postmeta) . " SET post_id=%d WHERE post_id=%d", $item['post_id'], $id));
					$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->postmeta) . " SET meta_value=%d WHERE post_id=%d AND meta_key='_menu_item_menu_item_parent' LIMIT 1", $_menu_item_menu_item_parent, $item['post_id']));
					if ($_menu_item_type == 'custom') {
						$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->postmeta) . " SET meta_value=%d WHERE post_id=%d AND meta_key='_menu_item_object_id' LIMIT 1", $_menu_item_object_id, $item['post_id']));
					}
					$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->posts) . " SET ID=%d WHERE ID=%d LIMIT 1", $item['post_id'], $id));
					if ($id < $item['post_id']) $wpdb->query($wpdb->prepare("ALTER TABLE " . esc_sql($wpdb->posts) . " AUTO_INCREMENT=%d", $item['post_id']+1));
					$id = $item['post_id'];
				}
				if (isset($_item_custom_data)) {
					$custom_data = get_post_meta($item['post_id'], '_item_custom_data', true);
					if (is_array($custom_data)) $custom_data = serialize($custom_data);
					if ($custom_data=='')
						$wpdb->query($wpdb->prepare("INSERT INTO " . esc_sql($wpdb->postmeta) . " (post_id, meta_key, meta_value) VALUES (%d, '_item_custom_data', %s)", $item['post_id'], $_item_custom_data));
					else if ($custom_data!=$_item_custom_data)
						$wpdb->query($wpdb->prepare("UPDATE " . esc_sql($wpdb->postmeta) . " SET meta_value=%s WHERE post_id=%d AND meta_key='_item_custom_data' LIMIT 1", $_item_custom_data, $item['post_id']));
				}

				$item['postmeta'] = apply_filters( 'wp_import_post_meta', $item['postmeta'], $item['post_id'], $item );
				// add/update post meta
				if ( ! empty( $item['postmeta'] ) ) {
					foreach ( $item['postmeta'] as $meta ) {
						$key = apply_filters( 'import_post_meta_key', $meta['key'], $item['post_id'], $item );
						if (rosemary_substr($key, 0, 11)=='_menu_item_') continue;
						
						$value = false;
						if ( $key ) {
							// export gets meta straight from the DB so could have a serialized string
							$replace = true;
							if ( ! $value ) {
								$value = $meta['value'];
								if (is_serialized($value)) {
									$value = rosemary_unserialize( $value );
									if ( $value === false ) {
										if ($this->debug) {
											dfl(sprintf( esc_html__('Menu item (ID=%s) - error unserialize postmeta: %s=', 'rosemary'), $item['post_id'], $key) );
										}
										$value = $meta['value'];
										$replace = false;
									}
								}
							}
							if ($replace) {
								$value = $this->replace_uploads($value);
							}
							update_post_meta( $item['post_id'], $key, $value );
							do_action( 'import_post_meta', $item['post_id'], $key, $value );
						}
					}
				}

			} //else
				$this->processed_menu_items[intval($item['post_id'])] = (int) $id;
			if ( $this->debug ) dfl( sprintf( esc_html__( 'Menu item "%s" (ID=%s) imported.', 'rosemary'), $item['post_title'], $item['post_id']) );
		}
	}

	/**
	 * If fetching attachments is enabled then attempt to create a new attachment
	 *
	 * @param array $post Attachment post details from WXR
	 * @param string $url URL to fetch attachment from
	 * @return int|WP_Error Post ID on success, WP_Error otherwise
	 */
	function process_attachment( $post, $url ) {

		if ($this->debug) dfl( esc_html__('Process attachment', 'rosemary') );

		// if the URL is absolute, but does not contain address, then upload it assuming base_site_url
		if ( preg_match( '|^/[\w\W]+$|', $url ) )
			$url = rtrim( $this->base_url, '/' ) . ($url);

		$upload = $this->fetch_remote_file( $url, $post );
		if ( is_wp_error( $upload ) )
			return $upload;

		if ( $info = wp_check_filetype( $upload['file'] ) )
			$post['post_mime_type'] = $info['type'];
		else {
			$msg = esc_html__('Invalid file type', 'rosemary');
			dfl( $msg );
			return new WP_Error( 'attachment_processing_error', $msg );
		}

		$post['guid'] = $upload['url'];

		// as per wp-admin/includes/upload.php
		$post_id = wp_insert_attachment( $post, $upload['file'] );
		wp_update_attachment_metadata( $post_id, wp_generate_attachment_metadata( $post_id, $upload['file'] ) );

		// remap resized image URLs, works by stripping the extension and remapping the URL stub.
		if ( preg_match( '!^image/!', $info['type'] ) ) {
			$parts = pathinfo( $url );
			$name = basename( $parts['basename'], ".{$parts['extension']}" ); // PATHINFO_FILENAME in PHP 5.2

			$parts_new = pathinfo( $upload['url'] );
			$name_new = basename( $parts_new['basename'], ".{$parts_new['extension']}" );

			$this->url_remap[($parts['dirname']) . '/' . ($name)] = ($parts_new['dirname']) . '/' . ($name_new);
		}

		return $post_id;
	}

	/**
	 * Attempt to download a remote file attachment
	 *
	 * @param string $url URL of item to fetch
	 * @param array $post Attachment details
	 * @return array|WP_Error Local file location details on success, WP_Error otherwise
	 */
	function fetch_remote_file( $url, $post ) {

		if ($this->debug) dfl( sprintf(esc_html__('Fetch remote file "%s"', 'rosemary'), $url) );

		// Check for already uploaded
		$local_url = $this->replace_uploads($url);
		$upload = array(
			'file' => str_replace($this->uploads_url, $this->uploads_dir, $local_url),
			'url'  => $local_url
		);

		if ( !file_exists($upload['file']) && $this->fetch_attachments ) {

			// extract the file name and extension from the url
			$file_name = basename( $url );
	
			// get placeholder file in the upload dir with a unique, sanitized filename
			$upload = wp_upload_bits( $file_name, 0, '', $post['upload_date'] );
			if ( $upload['error'] )
				return new WP_Error( 'upload_dir_error', $upload['error'] );
	
			// fetch the remote url and write it to the placeholder file
			$wp_http = new WP_Http();
			$headers = $wp_http->request( $url, [ 'stream' => true, 'filename' => $upload['file'] ] );
	
			// request failed
			if ( ! $headers ) {
				@unlink( $upload['file'] );
				$msg = esc_html__('Remote server did not respond', 'rosemary');
				dfl($msg);
				return new WP_Error( 'import_file_error', $msg );
			}
	
			// make sure the fetch was successful
			if ( $headers['response'] != '200' ) {
				@unlink( $upload['file'] );
				$msg = sprintf( esc_html__('Remote server returned error response %1$d %2$s', 'rosemary'), $headers['response'], get_status_header_desc($headers['response']) );
				dfl($msg);
				return new WP_Error( 'import_file_error', $msg );
			}
	
			$filesize = filesize( $upload['file'] );
	
			if ( isset( $headers['content-length'] ) && $filesize != $headers['content-length'] ) {
				@unlink( $upload['file'] );
				$msg = esc_html__('Remote file is incorrect size', 'rosemary');
				dfl($msg);
				return new WP_Error( 'import_file_error', $msg );
			}
	
			if ( 0 == $filesize ) {
				@unlink( $upload['file'] );
				$msg = esc_html__('Zero size file downloaded', 'rosemary');
				dfl($msg);
				return new WP_Error( 'import_file_error', $msg );
			}
	
			$max_size = (int) $this->max_attachment_size();
			if ( ! empty( $max_size ) && $filesize > $max_size ) {
				@unlink( $upload['file'] );
				$msg = sprintf(esc_html__('Remote file is too large, limit is %s', 'rosemary'), size_format($max_size) );
				dfl($msg);
				return new WP_Error( 'import_file_error', $msg );
			}
	
			// keep track of the old and new urls so we can substitute them later
			$this->url_remap[$url] = $upload['url'];
			$this->url_remap[$post['guid']] = $upload['url']; // r13735, really needed?
			// keep track of the destination if the remote url is redirected somewhere else
			if ( isset($headers['x-final-location']) && $headers['x-final-location'] != $url )
				$this->url_remap[$headers['x-final-location']] = $upload['url'];

			if ($this->debug) dfl(esc_html__('Attachment fetched', 'rosemary'));
		} else
			if ($this->debug) dfl(esc_html__('Attachment already uploaded', 'rosemary'));

		return $upload;
	}
		
	// Replace uploads dir to new url
	function replace_uploads($str) {
		return rosemary_replace_uploads_url($str, $this->uploads_folder);
	}

	/**
	 * Attempt to associate posts and menu items with previously missing parents
	 *
	 * An imported post's parent may not have been imported when it was first created
	 * so try again. Similarly for child menu items and menu items which were missing
	 * the object (e.g. post) they represent in the menu
	 */
	function backfill_parents() {

		if ($this->debug) dfl(esc_html__('Backfill parents', 'rosemary'));

		global $wpdb;

		// find parents for post orphans
		foreach ( $this->post_orphans as $child_id => $parent_id ) {
			$local_child_id = $local_parent_id = false;
			if ( isset( $this->processed_posts[$child_id] ) )
				$local_child_id = $this->processed_posts[$child_id];
			if ( isset( $this->processed_posts[$parent_id] ) )
				$local_parent_id = $this->processed_posts[$parent_id];

			if ( $local_child_id && $local_parent_id )
				$wpdb->update( $wpdb->posts, array( 'post_parent' => $local_parent_id ), array( 'ID' => $local_child_id ), '%d', '%d' );
		}

		// all other posts/terms are imported, retry menu items with missing associated object
		$missing_menu_items = $this->missing_menu_items;
		foreach ( $missing_menu_items as $item )
			$this->process_menu_item( $item );

		// find parents for menu item orphans
		foreach ( $this->menu_item_orphans as $child_id => $parent_id ) {
			$local_child_id = $local_parent_id = 0;
			if ( isset( $this->processed_menu_items[$child_id] ) )
				$local_child_id = $this->processed_menu_items[$child_id];
			if ( isset( $this->processed_menu_items[$parent_id] ) )
				$local_parent_id = $this->processed_menu_items[$parent_id];

			if ( $local_child_id && $local_parent_id )
				update_post_meta( $local_child_id, '_menu_item_menu_item_parent', (int) $local_parent_id );
		}
	}

	/**
	 * Use stored mapping information to update old attachment URLs
	 */
	function backfill_attachment_urls() {

		if ($this->debug) dfl(esc_html__('Backfill attachments', 'rosemary'));

		global $wpdb;
		// make sure we do the longest urls first, in case one is a substring of another
		uksort( $this->url_remap, array(&$this, 'cmpr_strlen') );

		foreach ( $this->url_remap as $from_url => $to_url ) {
			// remap urls in post_content
			$wpdb->query( $wpdb->prepare("UPDATE " . esc_sql($wpdb->posts) . " SET post_content = REPLACE(post_content, %s, %s)", $from_url, $to_url) );
			// remap enclosure urls
			$result = $wpdb->query( $wpdb->prepare("UPDATE " . esc_sql($wpdb->postmeta) . " SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key='enclosure'", $from_url, $to_url) );
		}
	}

	/**
	 * Update _thumbnail_id meta to new, imported attachment IDs
	 */
	function remap_featured_images() {

		if ($this->debug) dfl(esc_html__('Remap featured images', 'rosemary'));

		// cycle through posts that have a featured image
		foreach ( $this->featured_images as $post_id => $value ) {
			if ( isset( $this->processed_posts[$value] ) ) {
				$new_id = $this->processed_posts[$value];
				// only update if there's a difference
				if ( $new_id != $value )
					update_post_meta( $post_id, '_thumbnail_id', $new_id );
			}
		}
	}

	/**
	 * Parse a WXR file
	 *
	 * @param string $file Path to WXR file for parsing
	 * @return array Information gathered from the WXR file
	 */
	function parse( $file ) {
		if ($this->debug) dfl( sprintf(esc_html__('Parse file %s', 'rosemary'), $file) );
		$parser = new WXR_Parser();
		$parser->debug = $this->debug;
		return $parser->parse( $file );
	}

	/**
	 * Decide if the given meta key maps to information we will want to import
	 *
	 * @param string $key The meta key to check
	 * @return string|bool The key if we do want to import, false if not
	 */
	function is_valid_meta_key( $key ) {
		// skip attachment metadata since we'll regenerate it from scratch
		// skip _edit_lock as not relevant for import
		if ( in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock' ) ) )
			return false;
		return $key;
	}

	/**
	 * Decide whether or not the importer is allowed to create users.
	 * Default is true, can be filtered via import_allow_create_users
	 *
	 * @return bool True if creating users is allowed
	 */
	function allow_create_users() {
		return apply_filters( 'import_allow_create_users', true );
	}

	/**
	 * Decide whether or not the importer should attempt to download attachment files.
	 * Default is true, can be filtered via import_allow_fetch_attachments. The choice
	 * made at the import options screen must also be true, false here hides that checkbox.
	 *
	 * @return bool True if downloading attachments is allowed
	 */
	function allow_fetch_attachments() {
		return apply_filters( 'import_allow_fetch_attachments', true );
	}

	/**
	 * Decide what the maximum file size for downloaded attachments is.
	 * Default is 0 (unlimited), can be filtered via import_attachment_size_limit
	 *
	 * @return int Maximum attachment file size to import
	 */
	function max_attachment_size() {
		return apply_filters( 'import_attachment_size_limit', 0 );
	}

	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import
	 * @return int 60
	 */
	function bump_request_timeout($time=60) {
		return $time;
	}

	// return the difference in length between two strings
	function cmpr_strlen( $a, $b ) {
		return strlen($b) - strlen($a);
	}
}

} // class_exists( 'WP_Importer' )
?>