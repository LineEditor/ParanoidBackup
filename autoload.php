<?php

function class_autoload($class_name) {
	$class_name = str_replace('\\', '/', $class_name);
	$file = __DIR__.'/classes/'.$class_name.'.php';
	
	
	if( file_exists($file) == false )
	{
		return false;
	}
	
	require_once ($file);
	
	return true;
}

spl_autoload_register('class_autoload');


?>