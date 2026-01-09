<?php
/**
 * Admin Page Class
 * 
 * Professional WordPress-native dashboard for performance monitoring.
 * Uses core WP styles and follows WordPress UI patterns.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Performance_Checkup_Admin_Page {
	
	/**
	 * Singleton instance
	 */
	private static $instance = null;
	
	/**
	 * Current active tab
	 */
	private $active_tab = 'overview';
	
	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}
	
	/**
	 * Add admin menu item
	 */
	public function add_admin_menu() {
		add_menu_page(
			'Performance Pro',
			'Performance Pro',
			'manage_options',
			'performance-checkup',
			array( $this, 'render_page' ),
			'dashicons-performance',
			80
		);
	}
	
	/**
	 * Enqueue admin styles
	 */
	public function enqueue_styles( $hook ) {
		if ( 'toplevel_page_performance-checkup' !== $hook ) {
			return;
		}
		
		// We only use inline styles to keep the plugin portable
		// All styles are included in render_page()
	}
	
	/**
	 * Render the admin page
	 */
	public function render_page() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'performance-checkup' ) );
		}
		
		// Determine active tab
		$this->active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		
		// Collect all metrics
		$metrics = $this->collect_metrics();
		
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Performance Checkup', 'performance-checkup' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=performance-checkup&tab=' . $this->active_tab ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Re-scan', 'performance-checkup' ); ?>
				</a>
			</h1>
			
			<?php $this->render_tabs(); ?>
			
			<div class="pc-tab-content">
				<?php
				switch ( $this->active_tab ) {
					case 'slow-queries':
						$this->render_slow_queries_tab( $metrics );
						break;
					case 'database':
						$this->render_database_tab( $metrics );
						break;
					case 'overview':
					default:
						$this->render_overview_tab( $metrics );
						break;
				}
				?>
			</div>
		</div>
		
		<?php $this->render_styles(); ?>
		<?php
	}
	
	/**
	 * Render navigation tabs
	 */
	private function render_tabs() {
		$tabs = array(
			'overview'      => __( 'Health Overview', 'performance-checkup' ),
			'slow-queries'  => __( 'Slow Query Log', 'performance-checkup' ),
			'database'      => __( 'Database Health', 'performance-checkup' ),
		);
		
		echo '<nav class="nav-tab-wrapper wp-clearfix">';
		foreach ( $tabs as $tab_key => $tab_label ) {
			$active_class = ( $this->active_tab === $tab_key ) ? 'nav-tab-active' : '';
			$url = admin_url( 'admin.php?page=performance-checkup&tab=' . $tab_key );
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( $url ),
				esc_attr( $active_class ),
				esc_html( $tab_label )
			);
		}
		echo '</nav>';
	}
	
	/**
	 * Collect all performance metrics
	 */
	private function collect_metrics() {
		global $wpdb;
		
		$metrics = array();
		
		// Query count
		$metrics['query_count'] = $wpdb->num_queries;
		
		// Memory usage
		$metrics['memory_used'] = memory_get_peak_usage( true );
		$metrics['memory_limit'] = $this->get_memory_limit();
		$metrics['memory_percent'] = ( $metrics['memory_limit'] > 0 ) 
			? ( $metrics['memory_used'] / $metrics['memory_limit'] ) * 100 
			: 0;
		
		// Slow queries
		$metrics['slow_queries'] = $this->get_slow_queries();
		$metrics['slow_query_count'] = count( $metrics['slow_queries'] );
		
		// Autoloaded data
		$metrics['autoload_size'] = $this->get_autoload_size();
		
		// Server environment
		$metrics['php_version'] = phpversion();
		$metrics['mysql_version'] = $wpdb->db_version();
		$metrics['wp_version'] = get_bloginfo( 'version' );
		$metrics['object_cache'] = wp_using_ext_object_cache();
		
		return $metrics;
	}
	
	/**
	 * Get PHP memory limit in bytes
	 */
	private function get_memory_limit() {
		$memory_limit = ini_get( 'memory_limit' );
		
		if ( preg_match( '/^(\d+)(.)$/', $memory_limit, $matches ) ) {
			$value = (int) $matches[1];
			$unit = $matches[2];
			
			switch ( strtoupper( $unit ) ) {
				case 'G':
					$value *= 1024 * 1024 * 1024;
					break;
				case 'M':
					$value *= 1024 * 1024;
					break;
				case 'K':
					$value *= 1024;
					break;
			}
			
			return $value;
		}
		
		return 0;
	}
	
	/**
	 * Get slow queries (if SAVEQUERIES is enabled)
	 */
	private function get_slow_queries() {
		global $wpdb;
		
		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES || empty( $wpdb->queries ) ) {
			return array();
		}
		
		$slow_queries = array();
		$threshold = 0.1; // 100ms
		
		foreach ( $wpdb->queries as $query ) {
			$query_time = isset( $query[1] ) ? floatval( $query[1] ) : 0;
			
			if ( $query_time > $threshold ) {
				$slow_queries[] = array(
					'sql'    => isset( $query[0] ) ? $query[0] : '',
					'time'   => $query_time,
					'caller' => isset( $query[2] ) ? $query[2] : '',
				);
			}
		}
		
		// Sort by time descending
		usort( $slow_queries, function( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );
		
		return $slow_queries;
	}
	
	/**
	 * Get autoloaded data size from wp_options
	 */
	private function get_autoload_size() {
		global $wpdb;
		
		$autoload_size = $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) 
			FROM {$wpdb->options} 
			WHERE autoload = 'yes'"
		);
		
		return $autoload_size ? (int) $autoload_size : 0;
	}
	
	/**
	 * Render Overview Tab
	 */
	private function render_overview_tab( $metrics ) {
		?>
		<div class="pc-status-cards">
			<?php $this->render_status_card( 'queries', $metrics ); ?>
			<?php $this->render_status_card( 'memory', $metrics ); ?>
			<?php $this->render_status_card( 'slow_queries', $metrics ); ?>
		</div>
		
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Server Environment', 'performance-checkup' ); ?></h2>
			</div>
			<div class="inside">
				<table class="widefat striped">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'WordPress Version', 'performance-checkup' ); ?></strong></td>
							<td><?php echo esc_html( $metrics['wp_version'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'PHP Version', 'performance-checkup' ); ?></strong></td>
							<td><?php echo esc_html( $metrics['php_version'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'MySQL Version', 'performance-checkup' ); ?></strong></td>
							<td><?php echo esc_html( $metrics['mysql_version'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Object Cache', 'performance-checkup' ); ?></strong></td>
							<td>
								<?php if ( $metrics['object_cache'] ) : ?>
									<span class="pc-badge pc-badge-success"><?php esc_html_e( 'Active', 'performance-checkup' ); ?></span>
								<?php else : ?>
									<span class="pc-badge pc-badge-warning"><?php esc_html_e( 'Not Active', 'performance-checkup' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'SAVEQUERIES', 'performance-checkup' ); ?></strong></td>
							<td>
								<?php if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) : ?>
									<span class="pc-badge pc-badge-success"><?php esc_html_e( 'Enabled', 'performance-checkup' ); ?></span>
								<?php else : ?>
									<span class="pc-badge pc-badge-neutral"><?php esc_html_e( 'Disabled', 'performance-checkup' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render a status card
	 */
	private function render_status_card( $type, $metrics ) {
		$card_data = $this->get_card_data( $type, $metrics );
		
		?>
		<div class="postbox pc-status-card pc-status-<?php echo esc_attr( $card_data['status'] ); ?>">
			<div class="inside">
				<div class="pc-card-icon">
					<span class="dashicons dashicons-<?php echo esc_attr( $card_data['icon'] ); ?>"></span>
				</div>
				<div class="pc-card-content">
					<h3><?php echo esc_html( $card_data['title'] ); ?></h3>
					<div class="pc-card-value"><?php echo wp_kses_post( $card_data['value'] ); ?></div>
					<div class="pc-card-description"><?php echo esc_html( $card_data['description'] ); ?></div>
					<?php if ( ! empty( $card_data['progress'] ) ) : ?>
						<div class="pc-progress-bar">
							<div class="pc-progress-fill" style="width: <?php echo esc_attr( $card_data['progress'] ); ?>%;"></div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Get card data based on type
	 */
	private function get_card_data( $type, $metrics ) {
		switch ( $type ) {
			case 'queries':
				$count = $metrics['query_count'];
				$status = 'good';
				$description = 'Normal range';
				
				if ( $count > 200 ) {
					$status = 'critical';
					$description = 'Very high - investigate';
				} elseif ( $count > 100 ) {
					$status = 'warning';
					$description = 'Higher than normal';
				}
				
				return array(
					'title'       => __( 'Database Queries', 'performance-checkup' ),
					'value'       => number_format( $count ),
					'description' => $description,
					'status'      => $status,
					'icon'        => 'database',
				);
				
			case 'memory':
				$used_mb = $metrics['memory_used'] / 1024 / 1024;
				$limit_mb = $metrics['memory_limit'] / 1024 / 1024;
				$percent = $metrics['memory_percent'];
				
				$status = 'good';
				$description = 'Healthy usage';
				
				if ( $percent > 80 ) {
					$status = 'critical';
					$description = 'Near limit';
				} elseif ( $percent > 60 ) {
					$status = 'warning';
					$description = 'Moderate usage';
				}
				
				return array(
					'title'       => __( 'Memory Usage', 'performance-checkup' ),
					'value'       => sprintf( '%.1f MB / %.0f MB', $used_mb, $limit_mb ),
					'description' => $description,
					'status'      => $status,
					'icon'        => 'performance',
					'progress'    => min( $percent, 100 ),
				);
				
			case 'slow_queries':
				$count = $metrics['slow_query_count'];
				$status = 'good';
				$description = 'No slow queries detected';
				
				if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
					$status = 'neutral';
					$description = 'SAVEQUERIES not enabled';
				} elseif ( $count > 5 ) {
					$status = 'critical';
					$description = 'Multiple slow queries found';
				} elseif ( $count > 0 ) {
					$status = 'warning';
					$description = 'Some slow queries detected';
				}
				
				return array(
					'title'       => __( 'Slow Queries', 'performance-checkup' ),
					'value'       => number_format( $count ),
					'description' => $description,
					'status'      => $status,
					'icon'        => 'clock',
				);
		}
		
		return array();
	}
	
	/**
	 * Render Slow Queries Tab
	 */
	private function render_slow_queries_tab( $metrics ) {
		if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
			$this->render_savequeries_guide();
			return;
		}
		
		if ( empty( $metrics['slow_queries'] ) ) {
			?>
			<div class="notice notice-success inline">
				<p><strong><?php esc_html_e( 'No slow queries detected!', 'performance-checkup' ); ?></strong></p>
				<p><?php esc_html_e( 'All queries on this page load completed in under 100ms.', 'performance-checkup' ); ?></p>
			</div>
			<?php
			return;
		}
		
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Slow Queries Detected', 'performance-checkup' ); ?></h2>
			</div>
			<div class="inside">
				<p><?php esc_html_e( 'The following queries took longer than 100ms to execute:', 'performance-checkup' ); ?></p>
				
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 80px;"><?php esc_html_e( 'Time (s)', 'performance-checkup' ); ?></th>
							<th><?php esc_html_e( 'SQL Query', 'performance-checkup' ); ?></th>
							<th style="width: 200px;"><?php esc_html_e( 'Called By', 'performance-checkup' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $metrics['slow_queries'] as $query ) : ?>
							<tr>
								<td><strong><?php echo esc_html( number_format( $query['time'], 4 ) ); ?></strong></td>
								<td><code style="font-size: 11px; display: block; overflow-x: auto;"><?php echo esc_html( $query['sql'] ); ?></code></td>
								<td><small><?php echo esc_html( $query['caller'] ); ?></small></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render SAVEQUERIES guide
	 */
	private function render_savequeries_guide() {
		?>
		<div class="notice notice-info inline">
			<h3><?php esc_html_e( 'Enable SAVEQUERIES to Track Slow Queries', 'performance-checkup' ); ?></h3>
			<p><?php esc_html_e( 'To see detailed information about slow database queries, you need to enable the SAVEQUERIES constant in your wp-config.php file.', 'performance-checkup' ); ?></p>
		</div>
		
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'How to Enable SAVEQUERIES', 'performance-checkup' ); ?></h2>
			</div>
			<div class="inside">
				<ol>
					<li><?php esc_html_e( 'Open your wp-config.php file (located in your WordPress root directory)', 'performance-checkup' ); ?></li>
					<li><?php esc_html_e( 'Find the line that says "That\'s all, stop editing! Happy publishing."', 'performance-checkup' ); ?></li>
					<li><?php esc_html_e( 'Add this line BEFORE that comment:', 'performance-checkup' ); ?></li>
				</ol>
				
				<pre style="background: #f5f5f5; padding: 15px; border-left: 4px solid #2271b1; margin: 15px 0;">define('SAVEQUERIES', true);</pre>
				
				<div class="notice notice-warning inline" style="margin-top: 20px;">
					<p><strong><?php esc_html_e( 'Important:', 'performance-checkup' ); ?></strong> <?php esc_html_e( 'Only enable SAVEQUERIES on development or staging sites. It adds overhead to every page load and should not be used in production.', 'performance-checkup' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render Database Health Tab
	 */
	private function render_database_tab( $metrics ) {
		$autoload_size = $metrics['autoload_size'];
		$autoload_kb = $autoload_size / 1024;
		
		$status = 'good';
		$status_text = __( 'Good', 'performance-checkup' );
		
		if ( $autoload_kb > 800 ) {
			$status = 'critical';
			$status_text = __( 'Critical - Needs Attention', 'performance-checkup' );
		} elseif ( $autoload_kb > 500 ) {
			$status = 'warning';
			$status_text = __( 'Warning - Monitor Closely', 'performance-checkup' );
		}
		
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2><?php esc_html_e( 'Autoloaded Options', 'performance-checkup' ); ?></h2>
			</div>
			<div class="inside">
				<table class="widefat">
					<tbody>
						<tr>
							<td style="width: 200px;"><strong><?php esc_html_e( 'Total Size', 'performance-checkup' ); ?></strong></td>
							<td><?php echo esc_html( number_format( $autoload_kb, 2 ) ); ?> KB</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Status', 'performance-checkup' ); ?></strong></td>
							<td><span class="pc-badge pc-badge-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_text ); ?></span></td>
						</tr>
					</tbody>
				</table>
				
				<div style="margin-top: 20px;">
					<h3><?php esc_html_e( 'What is Autoloaded Data?', 'performance-checkup' ); ?></h3>
					<p><?php esc_html_e( 'Autoloaded options are loaded on every page request. WordPress and plugins store settings in the wp_options table, and some are marked to "autoload" for quick access.', 'performance-checkup' ); ?></p>
					
					<h3><?php esc_html_e( 'Why Does It Matter?', 'performance-checkup' ); ?></h3>
					<p><?php esc_html_e( 'Too much autoloaded data slows down every page on your site because WordPress has to load all of it into memory on every request.', 'performance-checkup' ); ?></p>
					
					<h3><?php esc_html_e( 'Recommended Limits', 'performance-checkup' ); ?></h3>
					<ul>
						<li><strong><?php esc_html_e( 'Under 300 KB:', 'performance-checkup' ); ?></strong> <?php esc_html_e( 'Excellent', 'performance-checkup' ); ?></li>
						<li><strong><?php esc_html_e( '300-500 KB:', 'performance-checkup' ); ?></strong> <?php esc_html_e( 'Good', 'performance-checkup' ); ?></li>
						<li><strong><?php esc_html_e( '500-800 KB:', 'performance-checkup' ); ?></strong> <?php esc_html_e( 'Warning - consider cleanup', 'performance-checkup' ); ?></li>
						<li><strong><?php esc_html_e( 'Over 800 KB:', 'performance-checkup' ); ?></strong> <?php esc_html_e( 'Critical - cleanup recommended', 'performance-checkup' ); ?></li>
					</ul>
					
					<?php if ( $status !== 'good' ) : ?>
						<div class="notice notice-warning inline">
							<p><strong><?php esc_html_e( 'How to Fix:', 'performance-checkup' ); ?></strong></p>
							<ul>
								<li><?php esc_html_e( 'Remove unused plugins (they often leave autoloaded data behind)', 'performance-checkup' ); ?></li>
								<li><?php esc_html_e( 'Use a plugin like "WP-Optimize" to clean up the options table', 'performance-checkup' ); ?></li>
								<li><?php esc_html_e( 'Contact plugin developers if their plugins are storing excessive autoloaded data', 'performance-checkup' ); ?></li>
							</ul>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Render inline styles
	 */
	private function render_styles() {
		?>
		<style>
		/* Status Cards */
		.pc-status-cards {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
			gap: 20px;
			margin: 20px 0;
		}
		
		.pc-status-card {
			border-left-width: 4px;
			border-left-style: solid;
		}
		
		.pc-status-card.pc-status-good {
			border-left-color: #00a32a;
		}
		
		.pc-status-card.pc-status-warning {
			border-left-color: #dba617;
		}
		
		.pc-status-card.pc-status-critical {
			border-left-color: #d63638;
		}
		
		.pc-status-card.pc-status-neutral {
			border-left-color: #646970;
		}
		
		.pc-status-card .inside {
			display: flex;
			align-items: flex-start;
			gap: 15px;
			padding: 20px;
		}
		
		.pc-card-icon {
			flex-shrink: 0;
		}
		
		.pc-card-icon .dashicons {
			width: 48px;
			height: 48px;
			font-size: 48px;
			color: #2271b1;
		}
		
		.pc-card-content {
			flex-grow: 1;
		}
		
		.pc-card-content h3 {
			margin: 0 0 5px 0;
			font-size: 14px;
			color: #646970;
			font-weight: 400;
		}
		
		.pc-card-value {
			font-size: 32px;
			font-weight: 600;
			line-height: 1.2;
			margin-bottom: 5px;
			color: #1d2327;
		}
		
		.pc-card-description {
			font-size: 13px;
			color: #646970;
			margin-bottom: 10px;
		}
		
		/* Progress Bar */
		.pc-progress-bar {
			height: 8px;
			background: #f0f0f1;
			border-radius: 4px;
			overflow: hidden;
			margin-top: 10px;
		}
		
		.pc-progress-fill {
			height: 100%;
			background: #2271b1;
			transition: width 0.3s ease;
		}
		
		.pc-status-warning .pc-progress-fill {
			background: #dba617;
		}
		
		.pc-status-critical .pc-progress-fill {
			background: #d63638;
		}
		
		.pc-status-good .pc-progress-fill {
			background: #00a32a;
		}
		
		/* Badges */
		.pc-badge {
			display: inline-block;
			padding: 3px 8px;
			border-radius: 3px;
			font-size: 12px;
			font-weight: 500;
		}
		
		.pc-badge-success {
			background: #d7f0db;
			color: #00a32a;
		}
		
		.pc-badge-warning {
			background: #fcf3e0;
			color: #dba617;
		}
		
		.pc-badge-neutral {
			background: #f0f0f1;
			color: #646970;
		}
		
		.pc-badge-good {
			background: #d7f0db;
			color: #00a32a;
		}
		
		.pc-badge-critical {
			background: #fce8e9;
			color: #d63638;
		}
		
		/* Tab Content */
		.pc-tab-content {
			margin-top: 20px;
		}
		
		/* Table Improvements */
		.wp-list-table code {
			background: #f6f7f7;
			padding: 2px 6px;
			border-radius: 3px;
		}
		
		/* Responsive */
		@media (max-width: 782px) {
			.pc-status-cards {
				grid-template-columns: 1fr;
			}
			
			.pc-card-value {
				font-size: 24px;
			}
		}
		</style>
		<?php
	}
}
