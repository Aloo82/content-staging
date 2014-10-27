<?php
namespace Me\Stenberg\Content\Staging\DB;

use Me\Stenberg\Content\Staging\Models\Model;
use Me\Stenberg\Content\Staging\Models\Post;

class Post_DAO extends DAO {

	private $table;

	public function __construct( $wpdb ) {
		parent::__constuct( $wpdb );
		$this->table = $wpdb->posts;
	}

	/**
	 * Get post by global unique identifier.
	 *
	 * @param $guid
	 * @return Post
	 */
	public function get_by_guid( $guid ) {
		$guid = $this->normalize_guid( $guid );

		// Select post with a specific GUID ending.
		$query = $this->wpdb->prepare(
			'SELECT * FROM ' . $this->wpdb->posts . ' WHERE guid LIKE %s',
			'%' . $guid
		);

		$result = $this->wpdb->get_row( $query, ARRAY_A );

		if ( isset( $result['ID'] ) ) {
			return $this->create_object( $result );
		}

		return null;
	}

	/**
	 * Find post with the same global unique identifier (GUID) as the one for
	 * the provided post. If a match is found, update provided post with the
	 * post ID we got from database.
	 *
	 * Useful for comparing a post sent from content staging to production.
	 *
	 * @param Post $post
	 */
	public function get_id_by_guid( Post $post ) {
		$query = $this->wpdb->prepare(
			'SELECT ID FROM ' . $this->wpdb->posts . ' WHERE guid = %s',
			$post->get_guid()
		);

		$post->set_id( $this->wpdb->get_var( $query ) );
	}

	/**
	 * Get published posts.
	 *
	 * @param string $order_by
	 * @param string $order
	 * @param int $per_page
	 * @param int $paged
	 * @param array $selected
	 * @return array
	 */
	public function get_published_posts( $order_by = null, $order = 'asc', $per_page = 5,
										 $paged = 1, $selected = array() ) {
		$posts           = array();
		$nbr_of_selected = count( $selected );
		$limit           = $per_page;

		if ( ( $offset = ( ( $paged - 1 ) * $per_page ) - $nbr_of_selected ) < 0 ) {
			$offset = 0;
		}

		if ( ( ( ( $paged - 1 ) * $per_page ) - $nbr_of_selected ) < 0 ) {
			$limit = $per_page - $nbr_of_selected;
		}

		if ( $limit < 0 ) {
			return $posts;
		}

		// Only allow sorting results ascending or descending.
		if ( $order !== 'asc' ) {
			$order = 'desc';
		}

		$where  = 'post_type != "sme_content_batch" AND post_status = "publish"';
		$where  = apply_filters( 'sme_query_posts_where', $where );
		$values = apply_filters( 'sme_values_posts_where', array() );
		$stmt   = 'SELECT * FROM ' . $this->wpdb->posts . ' WHERE ' . $where;

		if ( ( $nbr_of_selected = count( $selected ) ) > 0 ) {
			$placeholders = implode( ',', array_fill( 0, $nbr_of_selected, '%d' ) );
			$values       = array_merge( $values, $selected );
			$stmt        .= ' AND ID NOT IN (' . $placeholders . ')';
		}

		if ( ! is_null( $order_by ) ) {
			$stmt .= ' ORDER BY ' . $order_by . ' ' . $order;
		}

		// Adjust the query to take pagination into account.
		$stmt    .= ' LIMIT %d, %d';
		$values[] = $offset;
		$values[] = $limit;

		$query  = $this->wpdb->prepare( $stmt, $values );
		$result = ( $result = $this->wpdb->get_results( $query, ARRAY_A ) ) ? $result : array();

		foreach ( $result as $post ) {
			if ( isset( $post['ID'] ) ) {
				$posts[] = $this->create_object( $post );
			}
		}

		return $posts;
	}

	/**
	 * Get number of published posts that exists.
	 *
	 * @return int
	 */
	public function get_published_posts_count() {
		$where  = 'post_type != "sme_content_batch" AND post_status = "publish"';
		$where  = apply_filters( 'sme_query_posts_where', $where );
		$values = apply_filters( 'sme_values_posts_where', array() );
		$query  = 'SELECT COUNT(*) FROM ' . $this->wpdb->posts . ' WHERE ' . $where;
		if ( ! empty( $values ) ) {
			$query  = $this->wpdb->prepare( $query, $values );
		}
		return $this->wpdb->get_var( $query );
	}

	/**
	 * Get published posts that is newer then provided date.
	 *
	 * @param string $date
	 * @return array
	 */
	public function get_published_posts_newer_then_modification_date( $date ) {

		$posts = array();

		$query = $this->wpdb->prepare(
			'SELECT * FROM ' . $this->wpdb->posts . ' ' .
			'WHERE post_status = "publish" AND post_type != "sme_content_batch" AND post_modified > %s ' .
			'ORDER BY post_type ASC',
			$date
		);

		foreach ( $this->wpdb->get_results( $query, ARRAY_A ) as $post ) {
			if ( isset( $post['ID'] ) ) {
				$posts[] = $this->create_object( $post );
			}
		}

		return $posts;
	}

	/**
	 * @param Post $post
	 */
	public function update_post( Post $post ) {
		$data         = $this->create_array( $post );
		$where        = array( 'ID' => $post->get_id() );
		$format       = $this->format();
		$where_format = array( '%d' );
		$this->update( $data, $where, $format, $where_format );
	}

	/**
	 * @return string
	 */
	protected function get_table() {
		return $this->table;
	}

	/**
	 * @return string
	 */
	protected function target_class() {
		return '\Me\Stenberg\Content\Staging\Models\Post';
	}

	/**
	 * @param array $raw
	 * @return string
	 */
	protected function unique_key( array $raw ) {
		return $raw['ID'];
	}

	/**
	 * @return string
	 */
	protected function select_stmt() {
		return 'SELECT * FROM ' . $this->table . ' WHERE ID = %d';
	}

	/**
	 * @param array $ids
	 * @return string
	 */
	protected function select_by_ids_stmt( array $ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return 'SELECT * FROM ' . $this->table . ' WHERE ID in (' . $placeholders . ')';
	}

	/**
	 * @param Model $obj
	 */
	protected function do_insert( Model $obj ) {
		$data   = $this->create_array( $obj );
		$format = $this->format();
		$this->wpdb->insert( $this->table, $data, $format );
		$obj->set_id( $this->wpdb->insert_id );
	}

	/**
	 * @param array $raw
	 * @return Post
	 */
	protected function do_create_object( array $raw ) {
		$obj = new Post( $raw['ID'] );

		if ( ( $parent = $this->find( $raw['post_parent'] ) ) !== null ) {
			$obj->set_parent( $parent );
		}

		$obj->set_author( $raw['post_author'] );
		$obj->set_date( $raw['post_date'] );
		$obj->set_date_gmt( $raw['post_date_gmt'] );
		$obj->set_modified( $raw['post_modified'] );
		$obj->set_modified_gmt( $raw['post_modified_gmt'] );
		$obj->set_content( $raw['post_content'] );
		$obj->set_title( $raw['post_title'] );
		$obj->set_excerpt( $raw['post_excerpt'] );
		$obj->set_post_status( $raw['post_status'] );
		$obj->set_comment_status( $raw['comment_status'] );
		$obj->set_ping_status( $raw['ping_status'] );
		$obj->set_password( $raw['post_password'] );
		$obj->set_name( $raw['post_name'] );
		$obj->set_to_ping( $raw['to_ping'] );
		$obj->set_pinged( $raw['pinged'] );
		$obj->set_content_filtered( $raw['post_content_filtered'] );
		$obj->set_guid( $raw['guid'] );
		$obj->set_menu_order( $raw['menu_order'] );
		$obj->set_type( $raw['post_type'] );
		$obj->set_mime_type( $raw['post_mime_type'] );
		$obj->set_comment_count( $raw['comment_count'] );
		return $obj;
	}

	/**
	 * @param Model $obj
	 * @return array
	 */
	protected function do_create_array( Model $obj ) {
		$parent = 0;

		if ( $obj->get_parent() !== null ) {
			$parent = $obj->get_parent()->get_id();
		}

		return array(
			'post_author'           => $obj->get_author(),
			'post_date'             => $obj->get_date(),
			'post_date_gmt'         => $obj->get_date_gmt(),
			'post_content'          => $obj->get_content(),
			'post_title'            => $obj->get_title(),
			'post_excerpt'          => $obj->get_excerpt(),
			'post_status'           => $obj->get_post_status(),
			'comment_status'        => $obj->get_comment_status(),
			'ping_status'           => $obj->get_ping_status(),
			'post_password'         => $obj->get_password(),
			'post_name'             => $obj->get_name(),
			'to_ping'               => $obj->get_to_ping(),
			'pinged'                => $obj->get_pinged(),
			'post_modified'         => $obj->get_modified(),
			'post_modified_gmt'     => $obj->get_modified_gmt(),
			'post_content_filtered' => $obj->get_content_filtered(),
			'post_parent'           => $parent,
			'guid'                  => $obj->get_guid(),
			'menu_order'            => $obj->get_menu_order(),
			'post_type'             => $obj->get_type(),
			'post_mime_type'        => $obj->get_mime_type(),
			'comment_count'         => $obj->get_comment_count(),
		);
	}

	/**
	 * Format of each of the values in the result set.
	 *
	 * Important! Must mimic the array returned by the
	 * 'do_create_array' method.
	 *
	 * @return array
	 */
	protected function format() {
		return array(
			'%d', // post_author
			'%s', // post_date
			'%s', // post_date_gmt
			'%s', // post_content
			'%s', // post_title
			'%s', // post_excerpt
			'%s', // post_status
			'%s', // comment_status
			'%s', // ping_status
			'%s', // post_password
			'%s', // post_name
			'%s', // to_ping
			'%s', // pinged
			'%s', // post_modified
			'%s', // post_modified_gmt
			'%s', // post_content_filtered
			'%d', // post_parent
			'%s', // guid
			'%d', // menu_order
			'%s', // post_type
			'%s', // post_mime_type
			'%d', // comment_count
		);
	}

}
