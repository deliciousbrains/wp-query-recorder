<?php
/*
Plugin Name: Query Recorder
Plugin URI: http://deliciousbrains.com
Description: Record SQL queries to a text file to run later
Author: Delicious Brains
Version: 1.0.1
Author URI: http://deliciousbrains.com
*/

// Copyright (c) 2013 Delicious Brains. All rights reserved.
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************

$GLOBALS['query_recorder_version'] = '1.0.1';

function query_recorder_load_textdomain() {
	load_plugin_textdomain( 'query-recorder', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'wp_loaded', 'query_recorder_load_textdomain' );

function query_recorder_init() {
    if( ! class_exists( 'Query_Recorder' ) )
        require_once 'class/query-recorder.php';

	global $query_recorder;
	$query_recorder = new Query_Recorder( __FILE__ );
}
add_action( 'init', 'query_recorder_init', 5 );

function query_recorder_deactivate(){
    if( ! class_exists( 'Query_Recorder' ) )
        require_once 'class/query-recorder.php';

    global $query_recorder;
    $query_recorder = new Query_Recorder( __FILE__ );
    $query_recorder->deactivate();
}
register_deactivation_hook( __FILE__, 'query_recorder_deactivate' );