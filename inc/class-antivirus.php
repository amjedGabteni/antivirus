<?php
/**
 * AntiVirus: Main class.
 *
 * @package    AntiVirus
 */

// Quit.
defined( 'ABSPATH' ) || exit;


/**
 * AntiVirus: Main plugin class.
 */

/**
 *
 */
class AntiVirus {
	/**
	 * The basename of a plugin.
	 *
	 * @var string
	 */
	private static $base;

	/**
	 * Pseudo constructor.
	 */
	public static function instance() {
		new self();
	}

	/**
	 * Constructor.
	 *
	 * Should not be called directly,
	 *
	 * @see AntiVirus::instance()
	 */
	public function __construct() {
		// Don't run during autosave or XML-RPC request.
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}

		// Save the plugin basename.
		self::$base = plugin_basename( ANTIVIRUS_FILE );

		// Run the daily cronjob.
		if ( defined( 'DOING_CRON' ) ) {
			add_action( 'antivirus_daily_cronjob', array( __CLASS__, 'do_daily_cronjob' ) );
		}

		if ( is_admin() ) {
			/* AJAX */
			if ( defined( 'DOING_AJAX' ) ) {
				add_action( 'wp_ajax_get_ajax_response', array( __CLASS__, 'get_ajax_response' ) );
			} else {
				/* Actions */
				add_action( 'admin_menu', array( __CLASS__, 'add_sidebar_menu' ) );
				add_action( 'admin_notices', array( __CLASS__, 'show_dashboard_notice' ) );
				add_action( 'deactivate_' . self::$base, array( __CLASS__, 'clear_scheduled_hook' ) );
				add_action( 'plugin_row_meta', array( __CLASS__, 'init_row_meta' ), 10, 2 );
				add_action( 'plugin_action_links_' . self::$base, array( __CLASS__, 'init_action_links' ) );
			}
		}
	}

	/**
	 * Adds a link to the plugin settings in the plugin list table.
	 *
	 * @param array $data Plugin action links.
	 *
	 * @return array The modified action links array.
	 */
	public static function init_action_links( $data ) {
		// Only add link if user has permissions to view them.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $data;
		}

		return array_merge(
			$data,
			array(
				sprintf(
					'<a href="%s">%s</a>',
					add_query_arg(
						array(
							'page' => 'antivirus',
						),
						admin_url( 'options-general.php' )
					),
					__( 'Settings', 'antivirus' )
				),
			)
		);
	}

	/**
	 * Adds a donation link to the second row in the plugin list table.
	 *
	 * @param array  $data Plugin links array.
	 * @param string $page The current row identifier.
	 *
	 * @return array The modified links array.
	 */
	public static function init_row_meta( $data, $page ) {
		if ( $page !== self::$base ) {
			return $data;
		}

		return array_merge(
			$data,
			array(
				'<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=TD4AMD2D8EMZW" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Donate', 'antivirus' ) . '</a>',
				'<a href="https://wordpress.org/support/plugin/antivirus" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support', 'antivirus' ) . '</a>',
			)
		);
	}

	/**
	 * Plugin activation hook.
	 */
	public static function activation() {
		// Add default option.
		add_option(
			'antivirus',
			array(),
			'',
			'no'
		);

		// Add cron schedule.
		if ( self::_get_option( 'cronjob_enable' ) ) {
			self::_add_scheduled_hook();
		}
	}

	/**
	 * Plugin deactivation hook.
	 */
	public static function deactivation() {
		self::clear_scheduled_hook();
	}

	/**
	 * Plugin uninstall hook.
	 */
	public static function uninstall() {
		delete_option( 'antivirus' );
	}

	/**
	 * Get a plugin option value.
	 *
	 * @param string $field Option name.
	 *
	 * @return string The option value.
	 */
	private static function _get_option( $field ) {
		$options = wp_parse_args(
			get_option( 'antivirus' ),
			array(
				'cronjob_enable'    => 0,
				'cronjob_alert'     => 0,
				'safe_browsing'     => 0,
				'safe_browsing_key' => '',
				'checksum_verifier' => 0,
				'notify_email'      => '',
				'white_list'        => '',
			)
		);

		return ( empty( $options[ $field ] ) ? '' : $options[ $field ] );
	}

	/**
	 * Update an option in the database.
	 *
	 * @param string     $field The option name.
	 * @param string|int $value The option value.
	 */
	protected static function _update_option( $field, $value ) {
		self::_update_options(
			array(
				$field => $value,
			)
		);
	}

	/**
	 * Update multiple options in the database.
	 *
	 * @param array $data An associative array of option fields and values.
	 */
	private static function _update_options( $data ) {
		update_option(
			'antivirus',
			array_merge(
				(array) get_option( 'antivirus' ),
				$data
			)
		);
	}

	/**
	 * Initialize the cronjob.
	 *
	 * Schedules the AntiVirus cronjob to run daily.
	 */
	private static function _add_scheduled_hook() {
		if ( ! wp_next_scheduled( 'antivirus_daily_cronjob' ) ) {
			wp_schedule_event(
				time(),
				'daily',
				'antivirus_daily_cronjob'
			);
		}
	}

	/**
	 * Cancel the daily cronjob.
	 */
	public static function clear_scheduled_hook() {
		if ( wp_next_scheduled( 'antivirus_daily_cronjob' ) ) {
			wp_clear_scheduled_hook( 'antivirus_daily_cronjob' );
		}
	}

	/**
	 * Cronjob callback.
	 */
	public static function do_daily_cronjob() {
		// Check if cronjob is enabled in the plugin.
		if ( ! self::_get_option( 'cronjob_enable' ) ) {
			return;
		}

		// Check the theme and permalinks.
		AntiVirus_CheckInternals::check_blog_internals();

		// Check the Safe Browsing API.
		if ( self::_get_option( 'safe_browsing' ) ) {
			AntiVirus_SafeBrowsing::check_safe_browsing();
		}

		// Check the theme and permalinks.
		if ( self::_get_option( 'checksum_verifier' ) ) {
			AntiVirus_ChecksumVerifier::verify_files();
		}
	}

	/**
	 * Send a warning via email that something was detected.
	 *
	 * @param string $subject Subject of the notification email.
	 * @param string $body    Email body.
	 */
	protected static function _send_warning_notification( $subject, $body ) {
		// Get recipient email address.
		$email = self::_get_option( 'notify_email' );

		// Get admin email address if nothing is stored.
		if ( ! is_email( $email ) ) {
			$email = get_bloginfo( 'admin_email' );
		}

		// Send email.
		wp_mail(
			$email,
			sprintf(
				'[%s] %s',
				get_bloginfo( 'name' ),
				$subject
			),
			sprintf(
				"%s\r\n\r\n\r\n%s\r\n%s\r\n",
				$body,
				esc_html__( 'Notify message by AntiVirus for WordPress', 'antivirus' ),
				esc_html__( 'http://wpantivirus.com', 'antivirus' )
			)
		);
	}

	/**
	 * Add sub menu page to the options main menu.
	 */
	public static function add_sidebar_menu() {
		$page = add_options_page(
			__( 'AntiVirus', 'antivirus' ),
			__( 'AntiVirus', 'antivirus' ),
			'manage_options',
			'antivirus',
			array(
				__CLASS__,
				'show_admin_menu',
			)
		);

		add_action( 'admin_print_styles-' . $page, array( __CLASS__, 'add_enqueue_style' ) );
		add_action( 'admin_print_scripts-' . $page, array( __CLASS__, 'add_enqueue_script' ) );
	}

	/**
	 * Enqueue our JavaScript.
	 */
	public static function add_enqueue_script() {
		// Get plugin data.
		$data = get_plugin_data( ANTIVIRUS_FILE );

		// Enqueue the JavaScript.
		wp_enqueue_script(
			'av_script',
			plugins_url( 'js/script.min.js', ANTIVIRUS_FILE ),
			array( 'jquery' ),
			$data['Version']
		);

		// Localize script data.
		wp_localize_script(
			'av_script',
			'av_settings',
			array(
				'nonce' => wp_create_nonce( 'av_ajax_nonce' ),
				'theme' => esc_js( urlencode( self::_get_theme_name() ) ),
				'msg_1' => esc_js( __( 'This is not a virus', 'antivirus' ) ),
				'msg_2' => esc_js( __( 'View line', 'antivirus' ) ),
				'msg_3' => esc_js( __( 'Scan finished', 'antivirus' ) ),
			)
		);
	}

	/**
	 * Enqueue our stylesheet.
	 */
	public static function add_enqueue_style() {
		// Get plugin data.
		$data = get_plugin_data( ANTIVIRUS_FILE );

		// Enqueue the stylesheet.
		wp_enqueue_style(
			'av_css',
			plugins_url( 'css/style.min.css', ANTIVIRUS_FILE ),
			array(),
			$data['Version']
		);
	}

	/**
	 * Get the currently activated theme.
	 *
	 * @return array|false An array holding the theme data or false on failure.
	 */
	private static function _get_current_theme() {
		$theme = wp_get_theme();
		$name  = $theme->get( 'Name' );
		$slug  = $theme->get_stylesheet();
		$files = $theme->get_files( 'php', 1 );

		// Check if empty.
		if ( empty( $name ) || empty( $files ) ) {
			return false;
		}

		return array(
			'Name'           => $name,
			'Slug'           => $slug,
			'Template Files' => $files,
		);
	}

	/**
	 * Get all the files belonging to the current theme.
	 *
	 * @return array|false Theme files or false on failure.
	 */
	protected static function _get_theme_files() {
		// Check if the theme exists.
		if ( ! $theme = self::_get_current_theme() ) {
			return false;
		}

		// Check its files.
		if ( empty( $theme['Template Files'] ) ) {
			return false;
		}

		// Returns the files, stripping out the content dir from the paths.
		return array_unique(
			array_map(
				array( 'AntiVirus', '_strip_content_dir' ),
				$theme['Template Files']
			)
		);
	}

	/**
	 * Strip out the content dir from a path.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	private static function _strip_content_dir( $string ) {
		return str_replace( array( WP_CONTENT_DIR, "wp-content" ), "", $string );
	}

	/**
	 * Get the name of the currently activated theme.
	 *
	 * @return string|false The theme name or false on failure.
	 */
	private static function _get_theme_name() {
		if ( $theme = self::_get_current_theme() ) {
			if ( ! empty( $theme['Slug'] ) ) {
				return $theme['Slug'];
			}
			if ( ! empty( $theme['Name'] ) ) {
				return $theme['Name'];
			}
		}

		return false;
	}

	/**
	 * Get the whitelist.
	 *
	 * @return array MD5 hashes of whitelisted files.
	 */
	protected static function _get_white_list() {
		return explode(
			':',
			self::_get_option( 'white_list' )
		);
	}

	/**
	 * Ajax response handler.
	 */
	public static function get_ajax_response() {
		// Check referer.
		check_ajax_referer( 'av_ajax_nonce' );

		// Check if there really is some data.
		if ( empty( $_POST['_action_request'] ) ) {
			exit();
		}

		// Check user permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$values = array();

		// Get value based on request.
		switch ( $_POST['_action_request'] ) {
			case 'get_theme_files':
				self::_update_option(
					'cronjob_alert',
					0
				);

				$values = self::_get_theme_files();
				break;

			case 'check_theme_file':
				if ( ! empty( $_POST['_theme_file'] ) && $lines = AntiVirus_CheckInternals::check_theme_file( $_POST['_theme_file'] ) ) {
					foreach ( $lines as $num => $line ) {
						foreach ( $line as $string ) {
							$values[] = $num;
							$values[] = htmlentities( $string, ENT_QUOTES );
							$values[] = md5( $num . $string );
						}
					}
				}
				break;

			case 'update_white_list':
				if ( ! empty( $_POST['_file_md5'] ) && preg_match( '/^[a-f0-9]{32}$/', $_POST['_file_md5'] ) ) {
					self::_update_option(
						'white_list',
						implode(
							':',
							array_unique(
								array_merge(
									self::_get_white_list(),
									array( $_POST['_file_md5'] )
								)
							)
						)
					);

					$values = array( $_POST['_file_md5'] );
				}
				break;

			default:
				break;
		}

		// Send response.
		if ( $values ) {
			wp_send_json(
				array(
					'data'  => array_values( $values ),
					'nonce' => $_POST['_ajax_nonce'],
				)
			);
		}

		exit();
	}

	/**
	 * Show notice on the dashboard.
	 */
	public static function show_dashboard_notice() {
		// Only show notice if there's an alert.
		if ( ! self::_get_option( 'cronjob_alert' ) ) {
			return;
		}

		// Display warning.
		echo sprintf(
			'<div class="error"><p><strong>%1$s:</strong> %2$s <a href="%3$s">%4$s &rarr;</a></p></div>',
			esc_html__( 'Virus suspected', 'antivirus' ),
			esc_html__( 'The daily antivirus scan of your blog suggests alarm.', 'antivirus' ),
			esc_url( add_query_arg(
				array(
					'page' => 'antivirus',
				),
				admin_url( 'options-general.php' )
			) ),
			esc_html__( 'Manual malware scan', 'antivirus' )
		);
	}

	/**
	 * Print the settings page.
	 */
	public static function show_admin_menu() {
		// Save updates.
		if ( ! empty( $_POST ) ) {
			// Check the referer.
			check_admin_referer( 'antivirus' );

			// Save values.
			$options = array(
				'cronjob_enable'    => (int) ( ! empty( $_POST['av_cronjob_enable'] ) ),
				'notify_email'      => sanitize_email( @$_POST['av_notify_email'] ),
				'safe_browsing'     => (int) ( ! empty( $_POST['av_safe_browsing'] ) ),
				'safe_browsing_key' => sanitize_text_field( @$_POST['av_safe_browsing_key'] ),
				'checksum_verifier' => (int) ( ! empty( $_POST['av_checksum_verifier'] ) ),
			);

			// No cronjob?
			if ( empty( $options['cronjob_enable'] ) ) {
				$options['notify_email']      = '';
                $options['safe_browsing']     = 0;
				$options['safe_browsing_key'] = '';
				$options['checksum_verifier'] = 0;
			}

			// Stop cron if it was disabled.
			if ( $options['cronjob_enable'] && ! self::_get_option( 'cronjob_enable' ) ) {
				self::_add_scheduled_hook();
			} else if ( ! $options['cronjob_enable'] && self::_get_option( 'cronjob_enable' ) ) {
				self::clear_scheduled_hook();
			}

			// Save options.
			self::_update_options( $options ); ?>

			<div id="message" class="notice notice-success">
				<p>
					<strong>
						<?php esc_html_e( 'Settings saved.', 'antivirus' ); ?>
					</strong>
				</p>
			</div>
		<?php } ?>

		<div class="wrap" id="av_main">
			<h1>
				<?php esc_html_e( 'AntiVirus', 'antivirus' ); ?>
			</h1>

			<table class="form-table">
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Manual malware scan', 'antivirus' ); ?>
					</th>
					<td>
						<div class="inside" id="av_manual_scan">
							<p>
								<a href="#" class="button button-primary">
									<?php esc_html_e( 'Scan the theme templates now', 'antivirus' ); ?>
								</a>
								<span class="alert"></span>
							</p>

							<div class="output"></div>
						</div>
					</td>
				</tr>
			</table>


			<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=antivirus' ) ); ?>">
				<?php wp_nonce_field( 'antivirus' ) ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Daily malware scan', 'antivirus' ); ?>
						</th>
						<td>
							<fieldset>
								<label for="av_cronjob_enable">
									<input type="checkbox" name="av_cronjob_enable" id="av_cronjob_enable"
										   value="1" <?php checked( self::_get_option( 'cronjob_enable' ), 1 ) ?> />
									<?php esc_html_e( 'Check the theme templates for malware', 'antivirus' ); ?>
								</label>

								<p class="description">
									<?php
									if ( $timestamp = wp_next_scheduled( 'antivirus_daily_cronjob' ) ) {
										echo sprintf(
											'%s: %s',
											esc_html__( 'Next Run', 'antivirus' ),
											date_i18n( 'd.m.Y H:i:s', $timestamp + get_option( 'gmt_offset' ) * 3600 )
										);
									}
									?>
								</p>

								<br/>

								<label for="av_safe_browsing">
									<input type="checkbox" name="av_safe_browsing" id="av_safe_browsing"
										   value="1" <?php checked( self::_get_option( 'safe_browsing' ), 1 ) ?> />
									<?php esc_html_e( 'Malware detection by Google Safe Browsing', 'antivirus' ); ?>
								</label>

								<p class="description">
                                    <?php
                                    /* translators: Link for transparency report in english */
                                    $start_tag = sprintf( '<a href="%s">', __( 'https://transparencyreport.google.com/safe-browsing/search?hl=en', 'antivirus' ) );
                                    $end_tag = '</a>';
                                    /* translators: First placeholder (%s) starting link tag to transparency report, second placeholder closing link tag */
                                    printf( __( 'Diagnosis and notification in suspicion case. For more details read %s the transparency report %s.', 'antivirus' ), $start_tag, $end_tag );
                                    ?>
								</p>

								<br/>

								<label for="av_safe_browsing_key">
									<?php esc_html_e( 'Safe Browsing API key', 'antivirus' ); ?>
								</label>
								<br/>
								<input type="text" name="av_safe_browsing_key" id="av_safe_browsing_key"
								       value="<?php esc_attr_e( self::_get_option( 'safe_browsing_key' ) ); ?>" />

								<p class="description">
									<?php
									esc_html_e( 'Provide a custom key for the Google Safe Browsing API (v4). If this value is left empty, a fallback will be used. However, to ensure valid results due to rate limitations, it is recommended to use your own key.', 'antivirus' );
									?>
								</p>

								<br/>

								<label for="av_checksum_verifier">
									<input type="checkbox" name="av_checksum_verifier" id="av_checksum_verifier"
										   value="1" <?php checked( self::_get_option( 'checksum_verifier' ), 1 ) ?> />
									<?php esc_html_e( 'Checksum verification of WP core files', 'antivirus' ); ?>
								</label>

								<p class="description">
									<?php
									esc_html_e( 'Matches checksums of all WordPress core files against the values provided by the official API.', 'antivirus' );
									?>
								</p>

								<br/>

								<label for="av_notify_email"><?php esc_html_e( 'Email address for notifications', 'antivirus' ); ?></label>
								<input type="text" name="av_notify_email" id="av_notify_email"
									   value="<?php esc_attr_e( self::_get_option( 'notify_email' ) ); ?>"
									   class="regular-text"
									   placeholder="<?php esc_attr_e( 'Email address for notifications', 'antivirus' ); ?>" />


								<p class="description">
									<?php esc_html_e( 'If the field is empty, the blog admin will be notified', 'antivirus' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'antivirus' ) ?>"/>
						</th>
						<td>
							<?php
							printf(
								'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
								'https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=TD4AMD2D8EMZW',
								esc_html__( 'Donate', 'antivirus' )
							);
							?>
							&bull;
							<?php
							printf(
								'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
								esc_attr__( 'https://wordpress.org/plugins/antivirus/faq/', 'antivirus' ),
								esc_html__( 'FAQ', 'antivirus' )
							);
							?>
							&bull;
							<?php
							printf(
								'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
								'https://github.com/pluginkollektiv/antivirus/wiki',
								esc_html__( 'Manual', 'antivirus' )
							);
							?>
							&bull;
							<?php
							printf(
								'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
								'https://wordpress.org/support/plugin/antivirus',
								esc_html__( 'Support', 'antivirus' )
							);
							?>
						</td>
					</tr>
				</table>
			</form>
		</div>
	<?php }
}
