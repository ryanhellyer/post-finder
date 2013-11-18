<?php

/**
 * Plugin Name: Post Finder
 * Author: Micah Ernst
 * Description: Adds a UI for currating and ordering posts
 * Version: 0.2
 */

if( !class_exists( 'NS_Post_Finder' ) ) :

define( 'POST_FINDER_VERSION', '0.2' );
 
/**
 * Namespacing the class with "NS" to ensure uniqueness
 */
class NS_Post_Finder {
	
	/**
	 * Setup hooks
	 *
	 * @return void
	 */
	function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'scripts' ) );
		add_action( 'admin_footer', array( $this, 'admin_footer' ) );
		add_action( 'wp_ajax_pf_search_posts', array( $this, 'search_posts' ) );
	}

	/**
	 * Enable our scripts and stylesheets
	 *
	 * @return void
	 */
	function scripts() {

		wp_enqueue_script(
			'post-finder',
			plugins_url( 'js/main.js', __FILE__ ),
			array(
				'jquery',
				'jquery-ui-draggable',
				'jquery-ui-sortable',
				'underscore'
			),
			POST_FINDER_VERSION,
			true
		);

		wp_localize_script(
			'post-finder',
			'POST_FINDER_CONFIG',
			array(
				'adminurl' => admin_url(),
			)
		);

		wp_localize_script(
			'post-finder',
			'POST_FINDER_SEARCH_RESULT', 
			$this->search_result_template()
		);

		wp_localize_script(
			'post-finder',
			'POST_FINDER_TEMPLATE', 
			$this->search_template()
		);

		wp_enqueue_style( 'post-finder', plugins_url( 'css/screen.css', __FILE__ ) );
	}

	/**
	 * Make sure our nonce is on all admin pages
	 *
	 * @return void
	 */
	function admin_footer() {
		wp_nonce_field( 'post_finder', 'post_finder_nonce' );
	}

	/**
	 * Builds an input that lets the user find and order posts
	 *
	 * @param string $name Name of input
	 * @param string $value Expecting comma seperated post ids
	 * @param array $options Field options
	 */
	public static function render( $name, $value, $options = array() ) {

		$options = wp_parse_args( $options, array(
			'show_numbers' => true, // display # next to post
			'limit' => 10
		));

		// check to see if we have query args
		$args = isset( $options['args'] ) ? $options['args'] : array();

		// setup some defaults
		$args = wp_parse_args( $args, array(
			'post_type' => 'post',
			'posts_per_page' => 10,
			'post_status' => 'publish'
		));

		// now that we have a post type, figure out the proper label
		if( is_array( $args['post_type'] ) ) {
			$singular = 'Item';
			$plural = 'Items';
		} elseif( $post_type = get_post_type_object( $args['post_type'] ) ) {
			$singular = $post_type->labels->singular_name;
			$plural = $post_type->labels->name;
		} else {
			$singular = 'Post';
			$plural = 'Posts';
		}
		
		// get current selected posts if we have a value
		if( !empty( $value ) && is_string( $value ) ) {

			$post_ids = array_map( 'intval', explode( ',', $value ) );

			$posts = get_posts( array(
				'post_type' => $args['post_type'],
				'post_status' => $args['post_status'],
				'post__in' => $post_ids,
				'orderby' => 'post__in',
				'posts_per_page' => count( $post_ids )
			));
		}

		// if we have some ids already, make sure they arent included in the recent posts
		if( !empty( $post_ids ) ) {
			$args['post__not_in'] = $post_ids;
		} 

		// get recent posts
		$recent_posts = get_posts( apply_filters( 'post_finder_' . $name . '_recent_post_args', $args ) );

		$class = 'post-finder';

		if( !$options['show_numbers'] )
			$class .= ' no-numbers';
		
		?>
		<div class="<?php echo esc_attr( $class ); ?>" data-limit="<?php echo intval( $options['limit'] ); ?>" data-args='<?php echo json_encode( $args ); ?>'>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<ul class="list">
				<?php

				if( !empty( $posts ) ) {
					$i = 1;
					foreach( $posts as $post ) {
						printf(
							'<li data-id="%s">' .
								'<input type="text" size="3" maxlength="3" max="3" value="%s">' .
								'<span>%s</span>' .
								'<nav>' .
									'<a href="%s" class="icon-pencil" target="_blank" title="Edit"></a>' .
									'<a href="%s" class="icon-eye" target="_blank" title="View"></a>' .
									'<a href="#" class="icon-remove" title="Remove"></a>' .
								'</nav>' .
							'</li>',
							intval( $post->ID ),
							intval( $i ),
							esc_html( $post->post_title ),
							get_edit_post_link( $post->ID ),
							get_permalink( $post->ID )
						);
						$i++;
					}
				} else {
					echo '<p class="notice">No ' . esc_html( $plural ) . ' added.</p>';
				}

				?>
			</ul>

			<?php if( $recent_posts ) : ?>
			<h4>Select a Recent <?php echo esc_html( $singular ); ?></h4>
			<select>
				<option value="0">Choose a <?php echo esc_html( $singular ); ?></option>
				<?php foreach( $recent_posts as $post ) : ?>
				<option value="<?php echo intval( $post->ID ); ?>" data-permalink="<?php echo esc_attr( get_permalink( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php endif; ?>
		
			<div class="search">
				<h4>Search for a <?php echo esc_html( $singular ); ?></h4>
				<input type="text" placeholder="Enter a term or phrase">
				<buttton class="button">Search</buttton>
				<ul class="results"></ul>
			</div>
		</div>
		<script>jQuery(document).ready(function($){$('.post-finder').postFinder()});</script>
		<?php
	}

	/**
	 * Ajax callback to get posts that we may want to ad
	 *
	 * @return void
	 */
	function search_posts() {
		
		check_ajax_referer( 'post_finder' );

		if( !current_user_can( 'edit_posts' ) )
			return;

		// possible vars we'll except
		$vars = array(
			's',
			'post_parent',
			'post_status',
		);

		$args = array();

		// clean the basic vars
		foreach( $vars as $var ) {
			if( isset( $_REQUEST[$var] ) ) {
				if( is_array( $_REQUEST[$var] ) ) {
					$args[$var] = array_map( 'sanitize_text_field', $_REQUEST[$var] );
				} else {
					$args[$var] = sanitize_text_field( $_REQUEST[$var] );
				}
			}
		}	

		// this needs to be within a range
		if( isset( $_REQUEST['posts_per_page'] ) ) {

			$num = intval( $_REQUEST['posts_per_page'] );

			if( $num <= 0 ) {
				$num = 10;
			} elseif( $num > 100 ) {
				$num = 100;
			}

			$args['posts_per_page'] = $num;
		}

		// handle post type validation differently
		if( isset( $_REQUEST['post_type'] ) ) {

			$post_types = get_post_types( array( 'public' => true ) );

			if( is_array( $_REQUEST['post_type'] ) ) {

				foreach( $_REQUEST['post_type'] as $type ) {

					if( in_array( $type, $post_types ) ) {
						$args['post_type'][] = $type;
					}
				}

			} else {

				if( in_array( $_REQUEST['post_type'], $post_types ) )
					$args['post_type'] = $_REQUEST['post_type'];
			
			}
		}

		// allow search args to be filtered
		$posts = get_posts( apply_filters( 'post_finder_search_args', $args ) );

		// Get the permalink so that View/Edit links work
		foreach( $posts as $key => $post )
			$posts[$key]->permalink = get_permalink( $post->ID );

		if( $posts )
			header("Content-type: text/json");
			die( json_encode( array( 'posts' => $posts ) ) );
		
	}

	/*
	 * Sets the search result template
	 *
	 * @return string The search result template
	 */
	public function search_result_template() {
		$search_result_template = array(
			'<li data-id="<%= ID %>">',
				'<a href="#" class="add">' . __( 'Add', 'post-finder' ) . '</a>',
				'<span><%= post_title %></span>',
			'</li>'
		);
		return implode( '', $search_result_template );
	}

	/*
	 * Sets the search form template
	 *
	 * @return the search form template
	 */
	public function search_template() {
		$search_template = array(
		'<li data-id="<%= id %>">',
			'<input type="text" size="3" maxlength="3" max="3" value="<%= pos %>">',
			'<span><%= title %></span>',
			'<nav>',
				'<a href="<%= edit_url %>" class="icon-pencil" target="_blank" title="' . __( 'Edit', 'post-finder' ) . '"></a>',
				'<a href="<%= permalink %>" class="icon-eye" target="_blank" title="' . __( 'View', 'post-finder' ) . '"></a>',
				'<a href="#" class="icon-remove" title="' . __( 'Remove', 'post-finder' ) . '"></a>',
			'</nav>',
		'</li>' );
		return implode( '', $search_template );
	}

}
new NS_Post_Finder();

/**
 * Help function to render a post finder input
 */
function pf_render( $name, $value, $options = array() ) {
	NS_Post_Finder::render( $name, $value, $options );
}

endif;