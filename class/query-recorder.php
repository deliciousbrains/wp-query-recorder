<?php
class Query_Recorder {

	function __construct( $plugin_file_path ) {
		$this->set_default_options();
		$this->load_options();

		$this->plugin_version = $GLOBALS['query_recorder_version'];
		$this->plugin_file_path = $plugin_file_path;
		$this->plugin_dir_path = plugin_dir_path( $plugin_file_path );
		$this->plugin_folder_name = basename( $this->plugin_dir_path );
		$this->plugin_basename = plugin_basename( $plugin_file_path );
		$this->plugin_base ='options-general.php?page=query-recorder';

		// allow developers to modify the capability required to use this plugin
		$this->required_cap = apply_filters( 'query_recorder_required_cap', 'manage_options' );

		// process options update
		if ( 'POST' == $_SERVER['REQUEST_METHOD'] && isset( $_REQUEST['page'] ) && 'query-recorder' === $_REQUEST['page'] ) {
			check_admin_referer( 'query_recorder_update_options' );
			$this->update_options();
		}

		if ( is_admin() ) {
			$this->admin_init();
		}

		if ( is_admin_bar_showing() && current_user_can( $this->required_cap ) ) {
			add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ) );
			$this->admin_bar_assets();
		}

		if ( true === $this->options['active'] ) {
			add_filter( 'query', array( $this, 'record_query' ), 9999 ); // Set priority high to make sure it's the last filter to run
		}

		add_action( 'wp_ajax_query_recorder_toggle_active', array( $this, 'ajax_toggle_active' ) );
	}

	function ajax_toggle_active() {
		if( !current_user_can( $this->required_cap ) ) {
			echo '-1';
			exit;
		}

		if ( '1' == trim( $_POST['active_status'] ) ) {
			$this->options['active'] = false;
			$date_stamp_message = sprintf( __( 'Stopped recording %s UTC', 'query-recorder' ), current_time( 'mysql', 1 ) );
		} else {
			$this->options['active'] = true;
			$date_stamp_message = sprintf( __( 'Started recording %s UTC', 'query-recorder' ), current_time( 'mysql', 1 ) );
		}
		update_option( 'query_recorder', $this->options );

        global $wp_filesystem;

        if( !function_exists( 'WP_Filesystem' ) )
            require_once(ABSPATH . 'wp-admin/includes/file.php');

        // protect if the the global filesystem isn't setup yet
        if( is_null( $wp_filesystem ) )
            WP_Filesystem();

        $current_sql = $wp_filesystem->get_contents( $this->options['saved_queries_file_path'] );
        $current_sql .= '# ' . $date_stamp_message . "\n";

        $wp_filesystem->put_contents(
            $this->options['saved_queries_file_path'],
            $current_sql,
            FS_CHMOD_FILE);

		echo '1';
		exit;
	}

	function admin_init() {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'plugin_action_links' ) );
	}

	function add_pages() {
		$options_page = add_options_page( __( "Query Recorder Settings", 'query-recorder' ), __( "Query Recorder", 'query-recorder' ), $this->required_cap, 'query-recorder', array( $this, 'page_options' ) );
		// Enqueue styles and scripts
		add_action( 'admin_print_scripts-' . $options_page, array( $this, 'page_assets' ) );
	}

	function page_assets() {
		$version = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? time() : $this->plugin_version;
		$plugins_url = trailingslashit( plugins_url() ) . trailingslashit( $this->plugin_folder_name );

		// css
		$src = $plugins_url . 'asset/css/styles.css';
		wp_enqueue_style( 'query-recorder-styles', $src, array(), $version );
	}

	function plugin_action_links( $links ) {
		if ( !current_user_can( $this->required_cap ) ) return $links; // don't show the Settings link unless the user can access the Settings page
		$link = sprintf( '<a href="%s">%s</a>', admin_url( $this->plugin_base ), __( 'Settings', 'query-recorder' ) );
		array_unshift( $links, $link );
		return $links;
	}

	function admin_bar_menu() {
		global $wp_admin_bar;

		// Add the main siteadmin menu item
		$wp_admin_bar->add_menu( array(
			'id'		=> 'query-recorder',
			'parent'	=> 'top-secondary',
			'meta'		=> array( 'class' => 'query-recorder' ),
		) );
	}

	function record_query( $sql ) {
		if ( !empty( $this->options['record_queries_beggining_with'] ) ) {
			$record_queries_beggining_with = implode( '|', $this->options['record_queries_beggining_with'] );
			if ( !preg_match( '@^(' . $record_queries_beggining_with . ')@i', $sql ) ) {
				return $sql;
			}
		}

		$exclude_queries = $this->options['exclude_queries'];
		/* Ensures that Query Recorder specific option updates are not recorded */
		$exclude_queries[] = '`option_name` = \'query_recorder\'';
		foreach ( $exclude_queries as $string ) {
			if ( false !== strpos( $sql, $string ) ) {
				return $sql;
			}
		}

		$upload_dir = wp_upload_dir();

		// check if SQL has an ending semicolon and add if it doesn't
		$save_sql = substr( rtrim( $sql ), -1 ) == ';' ? $sql : $sql . ' ;';

        global $wp_filesystem;

        if( !function_exists( 'WP_Filesystem' ) )
            require_once(ABSPATH . 'wp-admin/includes/file.php');

        // protect if the the global filesystem isn't setup yet
        if( is_null( $wp_filesystem ) )
            WP_Filesystem();

        $current_sql = $wp_filesystem->get_contents( $this->options['saved_queries_file_path'] );
        $current_sql .= $save_sql . "\n";

        $wp_filesystem->put_contents(
            $this->options['saved_queries_file_path'],
            $current_sql,
            FS_CHMOD_FILE);

		return $sql;
	}

	function set_default_options() {
		// whether or not the recording is active
		$this->default_options['active'] = false;

		// default option for "Save queries to file"
		$upload_dir = wp_upload_dir();
		$salt = strtolower( wp_generate_password( 5, false, false ) );
		$saved_queries_file_path = sprintf( '%srecorded-queries-%s.sql', trailingslashit( $upload_dir['basedir'] ), $salt );
		$this->default_options['saved_queries_file_path'] = $this->slash_one_direction( $saved_queries_file_path );
	
		// default option for "Exclude queries containing"
		$this->default_options['exclude_queries'] = array( '_transient', '`option_name` = \'cron\'' );

		// default option for "Record queries that begin with"
		$this->default_options['record_queries_beggining_with'] = array( 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE' );
	}

	function load_options() {
		$update_options = false;

		$this->options = get_option( 'query_recorder' );

		// if no options exist then this is a fresh install, set up some default options
		if ( empty( $this->options ) ) {
			$this->options = $this->default_options;
			$update_options = true;
		} else {
			$this->options = wp_parse_args( $this->options, $this->default_options );
		}

		if ( $update_options ) {
			update_option( 'query_recorder', $this->options );
		}

		// allow developers to change the options regardless of the stored values
		$this->options = apply_filters( 'query_recorder_options', $this->options );
	}

	function update_options() {
		$_POST = stripslashes_deep( $_POST );

		$this->options['saved_queries_file_path'] = $this->slash_one_direction( trim( $_POST['saved_queries_file_path'] ) );
		$this->options['exclude_queries'] = str_replace( "\r", '', $_POST['exclude_queries'] );
		$this->options['exclude_queries'] = explode( "\n", $this->options['exclude_queries'] );
		$this->options['record_queries_beggining_with'] = isset( $_POST['record_queries_beggining_with'] ) ? $_POST['record_queries_beggining_with'] : array();

		update_option( 'query_recorder', $this->options );

		wp_redirect( admin_url( $this->plugin_base ) . '&settings-updated=1' );
		exit;
	}

	function admin_bar_assets() {
		$version = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? time() : $this->plugin_version;
		$plugins_url = trailingslashit( plugins_url() ) . trailingslashit( $this->plugin_folder_name );

		// css
		$src = $plugins_url . 'asset/css/admin-bar.css';
		wp_enqueue_style( 'query-recorder-admin-bar-styles', $src, array(), $version );

		// js
		$src = $plugins_url . 'asset/js/admin-bar.js';
		wp_enqueue_script( 'query-recorder-admin-bar-script', $src, array( 'jquery' ), $version, true );

		wp_localize_script( 'query-recorder-admin-bar-script', 'query_recorder', array(
			'active'			=> ( true === $this->options['active'] ) ? 1 : 0,
			'ajax_url'			=> admin_url( 'admin-ajax.php' ),
			'start_recording'	=> __( 'Start recording queries', 'query-recorder' ),
			'stop_recording'	=> __( 'Stop recording queries', 'query-recorder' ),
			'ajax_problem_on'	=> __( 'An error occured attempting to turn on query recording', 'query-recorder' ),
			'ajax_problem_off'	=> __( 'An error occured attempting to turn off query recording', 'query-recorder' ),
		) );

		$src = $plugins_url . 'asset/js/spin.min.js';
		wp_enqueue_script( 'query-recorder-admin-bar-spin', $src, array(), '2.0.1', true );

		$src = $plugins_url . 'asset/js/spin.js';
		wp_enqueue_script( 'query-recorder-admin-bar-spin-jquery', $src, array( 'jquery', 'query-recorder-admin-bar-spin' ), '2.0.1', true );
	}

	function page_options() {
		extract( $this->options, EXTR_SKIP );

		// these types of queries can be recorded, others cannot
		$recordable_queries = apply_filters( 'query_recorder_recordable_queries', array( 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE' ) );

		// process the content for the "Exclude queries containing" textarea
		$exclude_queries = ( empty( $exclude_queries ) ) ? '' : implode( "\n", $exclude_queries );

		require_once $this->plugin_dir_path . 'template/options.php';
	}

	// converts file paths that include mixed slashes to use the correct type of slash for the current operating system
	function slash_one_direction( $path ) {
		return str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $path );
	}

    function remove_saved_queries_file(){
        global $wp_filesystem;

        if( !function_exists( 'WP_Filesystem' ) )
            require_once(ABSPATH . 'wp-admin/includes/file.php');

        // protect if the the global filesystem isn't setup yet
        if( is_null( $wp_filesystem ) )
            WP_Filesystem();

        $wp_filesystem->delete( $this->options['saved_queries_file_path'], false, 'f' );
    }

    // clean up on deactivation of the plugin
    function deactivate(){
        // TODO recommended flush saved DB settings
        $this->remove_saved_queries_file();
    }

}
