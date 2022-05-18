<?php

if (PHP_VERSION_ID < 70000 && !interface_exists('\\Throwable') && !class_exists('\\Throwable')) {
	class Packager_Php_Scripts_Throwable extends Exception { }
} else {
	class Packager_Php_Scripts_Throwable extends Throwable { }	
}
