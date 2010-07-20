<?php

use lithium\core\Libraries;
use lithium\action\Dispatcher;
use lithium\net\http\Media;
use li3_amf\extensions\media\Amf;

/*
*	Load Zend Framework Libraries (for AMF encoding and decoding).
* 	http://rad-dev.org/lithium/wiki/guides/using/zend
* 
* 	'path' should be set to the location of the Zend folder.
* 	'includePath' should be set to the loation of folder containing the Zend folder.
* 
*/
if(!Libraries::get('Zend')) {
	Libraries::add("Zend", array(
	    "prefix" => "Zend_",
	    'path' => '/Library/Webserver/Documents/lithium/libraries/Zend',
	    "includePath" => '/Library/Webserver/Documents/lithium/libraries',
	    "bootstrap" => "Loader/Autoloader.php",
	    "loader" => array("Zend_Loader_Autoloader", "autoload"),
	    "transform" => function($class) { return str_replace("_", "/", $class) . ".php"; }
	));
}

/**
 * Declare AMF media type.
 */
Media::type('amf', 'application/x-amf', array(
	'decode' => function($data) {
		$amf = new Amf();
		$response = $amf->processResponseBodies();
		$response->finalize();
		die(print_r(debug_backtrace(), 1));
		return $response;
	},
	'encode' => function($data) {
		return $data;
	},
	'view' => false
));

?>