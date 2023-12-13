<?php

if (!defined('PACKAGER_PHP_FILE_BASE')) {


	define('PACKAGER_PHP_FILE_BASE', str_replace('\\','/',__DIR__));

	class Packager_Php_Wrapper
	{
		/** @var string */
		const FS_MODE = '____\Packager_Php_Wrapper::FS_MODE____';
		
		/** @var int */
		const FN_INC 		= 0b0001;
		
		/** @var int */
		const FN_REQ 		= 0b0010;
		
		/** @var int */
		const FN_INC_ONCE 	= 0b0100;
		
		/** @var int */
		const FN_REQ_ONCE 	= 0b1000;
		
		/** @var string */
		public static $ScriptFilename;

		/** @var string */
		public static $BasePath;

		/** @var int */
		public static $BasePathLength;

		// Packager_Php_Scripts_Completer::start(fields)
		/** @var mixed */
		public static $Context = NULL;

		/** @var array */
		public static $NewContextContents = array();

		/** @var array */
		public static $Contents = array();

		/** @var array */
		public static $Info = array('____\Packager_Php_Wrapper::$Info____'); // replaced automatically in build process

		/** @var int */
		private static $_baseLinesCount = '____\Packager_Php_Wrapper::$_baseLinesCount____'; // replaced automatically in build process

		/** @var bool */
		private static $_minifiedPhp = '____\Packager_Php_Wrapper::$_minifiedPhp____'; // replaced automatically in build process
		
		/** @var array */
		private static $_contexts = array();
		
		/** @var bool */
		private static $_closureRendering = TRUE;
		
		/** @var array */
		private static $_currentFileSource = array();

		/**
		 * Prints backtrace in SplFileInfo wrapper exceptions and in DirectoryIterator wrapper exceptions
		 * @return void
		 */
		public static function PrintBacktrace () {
			echo '<pre>';
			var_dump(debug_backtrace());
			echo '</pre>';
		}
		// Packager_Php_Scripts_Completer::end(fields)
		
		// Packager_Php_Scripts_Completer::start(Init)
		/**
		 * Static initialization for often used variables
		 * 
		 * @return void
		 */
		public static function Init () {
			self::$ScriptFilename = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);
			self::$BasePath = str_replace('\\', '/', __DIR__);
			self::$BasePathLength = mb_strlen(self::$BasePath);
			if (PHP_VERSION_ID < 50400) 
				self::$_closureRendering = FALSE;
		}
		// Packager_Php_Scripts_Completer::end(Init)
		
		// Packager_Php_Scripts_Completer::start(_includeFile)
		/**
		 * Evaluate static file content (mostly a template)
		 * 
		 * @param string  $path 
		 * @param mixed   $context 
		 * @param boolean $onceOnly 
		 * @param int     $fn 
		 * 
		 * @return void|boolean|int|mixed
		 */
		private static function _includeFile ($path, $context, $onceOnly, $fn) {
			$path = self::NormalizePath($path);
			if ($onceOnly && self::_getIsFileIncluded($path)) return;
			if (!isset(self::$Info[$path])) {
				if (($fn & self::FN_INC) != 0) {
					return include(__DIR__.$path);
				} else if (($fn & self::FN_REQ) != 0) {
					return require(__DIR__.$path);
				} else if (($fn & self::FN_INC_ONCE) != 0) {
					return include_once(__DIR__.$path);
				} else /*if (($fn & self::FN_REQ_ONCE) != 0)*/ {
					return require_once(__DIR__.$path);
				}
				//return call_user_func($fn, $path);// <- this construction doesn't work properly.
			} else {
				return self::_includeFileWithRendering($path, $context, $onceOnly);
			}
		}
		
		/**
		 * Get info if file has been included or not
		 * 
		 * @param string  $path 
		 * 
		 * @return boolean
		 */
		private static function _getIsFileIncluded ($path) {
			return (
				isset(self::$Info[$path]) && 
				self::$Info[$path]['included']
			) ? TRUE : FALSE;
		}

		/**
		 * Evaluate static file content (mostly a template)
		 * 
		 * @param string  $path 
		 * @param mixed   $context 
		 * @param boolean $onceOnly 
		 * 
		 * @return boolean|int|mixed
		 */
		private static function _includeFileWithRendering ($path, & $context, $onceOnly) {
			$store = self::$Info[$path]['store'];
			$closureRendering = $store == 'template' && self::$_closureRendering;
			$result = self::_renderFile($path, $context, $onceOnly, $closureRendering, $store);
			if ($closureRendering) {
				return $result;
			} else {
				echo $result;
				return 1;
			}
		}

		/**
		 * Evaluate static file content (mostly a template)
		 * Render file by calling closure code in PHP > 5.3 or render file with eval() and $this context replacing in PHP < 5.4
		 * 
		 * @param string  $path 
		 * @param mixed   $context 
		 * @param boolean $onceOnly 
		 * @param boolean $closureRendering 
		 * @param string  $store 
		 * 
		 * @return int|mixed|string
		 */
		private static function _renderFile ($path, & $context, $onceOnly, $closureRendering, $store) {
			if ($closureRendering) {
				$result = self::_callTemplateClosure($path, $context);
			} else {
				$result = self::_evalFile($path, $context, $store);
			}
			if ($onceOnly) self::_setFileIsIncluded($path);
			return $result;
		}
		
		/**
		 * Evaluate static file content (mostly a template)
		 * Render file by calling closure code in PHP > 5.3
		 * 
		 * @param string  $path 
		 * @param mixed   $context 
		 * 
		 * @return mixed
		 */
		private static function _callTemplateClosure ($path, & $context) {
			$templateClosure = self::_getFileContent($path, FALSE);
			if (!is_null($context)) {
				$templateClosure = $templateClosure->bindTo($context, $context);
			}
			return $templateClosure();
		}
		
		/**
		 * Evaluate static file content (mostly a template)
		 * Render file with eval() and $this context replacing in PHP < 5.4
		 * 
		 * @param string  $path 
		 * @param mixed   $context 
		 * @param string  $store 
		 * 
		 * @return string
		 */
		private static function _evalFile ($path, & $context, $store) {
			if ($store == 'template') {
				$content = & self::_getStaticWithContext($path, $context, $store);
			} else {
				$content = self::_getFileContent($path, TRUE);
			}
			self::_addContext($context);
			ob_start();
			try {
				eval(' ?'.'>' . $content . '<'.'?php ');
			}
			catch (Exception $e) {
				throw $e;
			}
			self::_removeContext();
			return trim(ob_get_clean());
		}

		/**
		 * Get static file content with replaced $this context variables if necessary
		 * 
		 * @param string  $path 
		 * @param mixed   $context
		 * 
		 * @return string
		 */
		private static function _getStaticWithContext ($path, $context) {
			if (is_null($context)) {
				$templateClosure = self::_getFileContent($path, TRUE);
				$content = self::_getTemplateClosureBody($templateClosure);
			} else {
				$content = & self::_getStaticWithContextAlreadyProcessed($path);
				if (mb_strlen($content) === 0) {
					$templateClosure = self::_getFileContent($path, TRUE);
					$content = self::_getTemplateClosureBody($templateClosure);
					// http://php.net/manual/en/language.variables.basics.php
					$content = preg_replace(
						"#([^\\\])(\\\$this)([^a-zA-Z0-9_\x7f-\xff])#im",
						"$1".get_called_class()."::\$Context$3",
						$content
					);
					$index = self::$Info[$path]['index'];
					self::$NewContextContents[$index] = $content;
				}
			}
			return $content;
		}
		
		/**
		 * Get closure function body from static files array
		 * 
		 * @param Closure $templateClosure
		 * 
		 * @return string
		 */
		private static function _getTemplateClosureBody (Closure $templateClosure) {
			$reflection = new ReflectionFunction($templateClosure);
			$startLine = $reflection->getStartLine() - 1;
			$endLine = $reflection->getEndLine();
			$length = $endLine - $startLine;
			self::_setUpCurrentFileSource();
			$functionSource = implode('', array_slice(self::$_currentFileSource, $startLine, $length));
			$firstCloseTagPos = mb_strpos($functionSource, '?>') + 2;
			$lastOpenTagPos = mb_strrpos($functionSource, '<?php');
			$functionBodyLength = $lastOpenTagPos - $firstCloseTagPos;
			$functionSource = mb_substr($functionSource, $firstCloseTagPos, $functionBodyLength);
			return $functionSource;
		}
		
		/**
		 * Set up current file source array
		 * 
		 * @return void
		 */
		private static function _setUpCurrentFileSource () {
			if (count(self::$_currentFileSource) === 0) {
				self::$_currentFileSource = file(__FILE__);
			}
		}
		
		/**
		 * Get static file content with replaced $this context variables already processed
		 * 
		 * @param string  $path 
		 * 
		 * @return string
		 */
		private static function _getStaticWithContextAlreadyProcessed ($path) {
			$content = '';
			if (isset(self::$Info[$path])) {
				$index = self::$Info[$path]['index'];
				if (isset(self::$NewContextContents[$index])) {
					$content = & self::$NewContextContents[$index];
				}
			}
			return $content;
		}
		
		/**
		 * Set up current context into contexts array to remember it after subtemplate has been rendered
		 * 
		 * @param mixed $context
		 * 
		 * @return void
		 */
		private static function _addContext ($context) {
			self::$_contexts[] = $context;
			self::$Context = $context;
		}
		
		/**
		 * Remove current context from contexts array and reset previous (remember any context after subtemplate has been rendered)
		 * 
		 * @return void
		 */
		private static function _removeContext () {
			$contextsCount = count(self::$_contexts);
			$newContext = NULL;
			if ($contextsCount > 0) {
				$contextsCount -= 1;
				unset(self::$_contexts[$contextsCount]);
				self::$_contexts = array_values(self::$_contexts);
				if ($contextsCount > 0) {
					$newContext = self::$_contexts[$contextsCount - 1];
				}
			}
			self::$Context = $newContext;
		}

		/**
		 * Set info if file has been included or not
		 * 
		 * @param string  $path 
		 * 
		 * @return void
		 */
		private static function _setFileIsIncluded ($path) {
			if (isset(self::$Info[$path])) {
				self::$Info[$path]['included'] = 1;
			} else {
				self::$Info[$path] = array('included' => 1);
			}
		}
		// Packager_Php_Scripts_Completer::end(_includeFile)
		
		// Packager_Php_Scripts_Completer::start(_getFileContent)
		/**
		 * Get static file content string (decode gzip record by default)
		 * 
		 * @param string  $path 
		 * @param boolean $decodeGzip 
		 * 
		 * @return string
		 */
		private static function _getFileContent ($path, $decodeGzip = TRUE) {
			if (!isset(self::$Info[$path])) return FALSE;
			$record = self::$Info[$path];
			$index = $record['index'];
			if ($index == -1) {
				return self::_getScript($record['lines']);
			} else {
				return self::_getStatic($record['store'], $index, $decodeGzip);
			}
		}

		/**
		 * Get php script content as part of result file
		 * 
		 * @param array $lines 
		 * 
		 * @return string
		 */
		private static function _getScript ($lines) {
			self::_setUpCurrentFileSource();
			$begin = self::$_baseLinesCount + $lines[0] - 1;
			$end = $begin + $lines[1];
			$r = "<?php\n";
			$g = self::$_minifiedPhp ? "\n" : "";
			for ($i = $begin, $l = $end; $i < $l; $i += 1) {
				$r .= $g . self::$_currentFileSource[$i];
			}
			$r .= "\n?>";
			return $r;
		}

		/**
		 * Get static file content by store type and store index (decode gzip string if necessary)
		 * 
		 * @param string  $store 
		 * @param int	 $index 
		 * @param boolean $decodeGzip 
		 * 
		 * @return string
		 */
		private static function _getStatic ($store, $index, $decodeGzip = TRUE) {
			if ($store == 'template') {
				return self::$Contents[$index];
			} else if ($store == 'gzip') {
				return $decodeGzip ? gzdecode(self::$Contents[$index]) : self::$Contents[$index] ;
			} else if ($store == 'base64') {
				return base64_decode(self::$Contents[$index]);
			} else {
				return self::$Contents[$index];
			}
		}
		// Packager_Php_Scripts_Completer::end(_getFileContent)
		
		// Packager_Php_Scripts_Completer::start(NormalizePath)
		/**
		 * Normalize any path to stored relative path with normal slashes,
		 * used only for internal purposes, not as php build-in function replacement.
		 * 
		 * @param string  $path 
		 * @param boolean $absolutely 
		 * 
		 * @return string
		 */
		public static function NormalizePath ($path) {
			$path = str_replace('\\', '/', $path);
			if (mb_strpos($path, '/./') !== FALSE) {
				$path = str_replace('/./', '/', $path);
			}
			if (mb_strpos($path, '/..') !== FALSE) {
				while (true) {
					$doubleDotPos = mb_strpos($path, "/..");
					if ($doubleDotPos === FALSE) {
						break;
					} else {
						$path1 = mb_substr($path, 0, $doubleDotPos);
						$path2 = mb_substr($path, $doubleDotPos + 3);
						$lastSlashPos = mb_strrpos($path1, '/');
						$path1 = mb_substr($path1, 0, $lastSlashPos);
						$path = $path1 . $path2;
					}
				}
			}
			if (mb_strpos($path, self::$BasePath) === 0) {
				$path = mb_substr($path, self::$BasePathLength);
			}
			return str_replace('//', '/', $path);
		}
		// Packager_Php_Scripts_Completer::end(NormalizePath)
		
		// Packager_Php_Scripts_Completer::start(_isProtocolPath)
		/**
		 * Check if given path is protocol like:
		 * http://..., https://..., php://...
		 * 
		 * @param string  $path 
		 * 
		 * @return bool
		 */
		public static function _isProtocolPath ($path) {
			return preg_match("#^([a-z]*)\://(.*)#", $path) ? TRUE : FALSE;
		}
		// Packager_Php_Scripts_Completer::end(_isProtocolPath)

		// Packager_Php_Scripts_Completer::start(Warning)
		/**
		 * Generates php warning content in output.
		 * 
		 * @param string $msg 
		 * @param string $path 
		 * @param string $fn 
		 * 
		 * @return void
		 */
		public static function Warning ($msg = '', $path = '', $fn = '') {
			if (!$msg) $msg = "$fn($path): failed to open stream: No such file or directory";
			trigger_error($msg, E_USER_WARNING);
		}
		// Packager_Php_Scripts_Completer::end(Warning)

		// Packager_Php_Scripts_Completer::start(readfile)
		/**
		 * Send file content to output, add gzip http header 
		 * if client accepts it and also if data are stored as gzip.
		 * 
		 * @param string   $filename
		 * @param boolean  $use_include_path
		 * @param resource $context
		 * 
		 * @return int
		 */
		public static function Readfile ($filename, $use_include_path = FALSE, $context = NULL) {
			if (self::_isProtocolPath($filename)) return call_user_func_array('readfile', func_get_args());
			$path = self::NormalizePath($filename);
			$content = self::_getFileContent($path, FALSE);
			if ($content === FALSE) {
				return call_user_func_array('readfile', func_get_args());
			} else {
				return self::_readFile($content, $path);
			}
		}

		/**
		 * 
		 * Send file content to output, add gzip http header 
		 * if client accepts it and also if data are stored as gzip.
		 * 
		 * @param string $content
		 * @param string $path
		 * 
		 * @return int
		 */
		private static function _readfile (& $content, $path) {
			$store = self::$Info[$path]['store'];
			if ($store == 'gzip') {
				$zlibOutCompr = @ini_get('zlib.output_compression');
				if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== FALSE && !$zlibOutCompr) {
					header('Content-Encoding: gzip');
				} else {
					$content = gzdecode($content);
				}
			}
			echo $content;
			return self::$Info[$path]['size'];
		}
		// Packager_Php_Scripts_Completer::end(readfile)

		// Packager_Php_Scripts_Completer::start(is_file)
		/**
		 * Tells whether the filename is a regular file.
		 * 
		 * @param string   $filename
		 * 
		 * @return boolean
		 */
		public static function IsFile ($filename) {
			$path = self::NormalizePath($filename);
			if (isset(self::$Info[$path])) return TRUE;
			return is_file($filename);
		}
		// Packager_Php_Scripts_Completer::end(is_file)

		// Packager_Php_Scripts_Completer::start(is_dir)
		/**
		 * Tells whether the filename is a directory.
		 * 
		 * @param string   $filename
		 * 
		 * @return boolean
		 */
		public static function IsDir ($filename) {
			$path = self::NormalizePath($filename);
			if (isset(self::$Info[$path])) return TRUE;
			$caught = FALSE;
			foreach (self::$Info as $pathKey => & $item) {
				if (strpos($pathKey, $path) === 0) {
					$caught = TRUE;
					break;
				}
			}
			if ($caught) return TRUE;
			return is_dir($filename);
		}
		// Packager_Php_Scripts_Completer::end(is_dir)

		// Packager_Php_Scripts_Completer::start(mkdir)
		/**
		 * Makes real directory.
		 * 
		 * @param string   $filename
		 * @param int	  $mode
		 * @param bool	 $recursive
		 * @param resource $context
		 * 
		 * @return boolean
		 */
		public static function MkDir ($filename, $mode = 0777, $recursive = FALSE, $context = NULL) {
			$path = self::NormalizePath($filename);
			$r = FALSE;
			if (!isset(self::$Info[$path])) {
				if ($context !== NULL) {
					$r = mkdir($filename, $mode, $recursive, $context);
				} else {
					$r = mkdir($filename, $mode, $recursive);
				}
				if ($r) self::_changeItself(
					'/*____\\Packager_Php_Wrapper::$Info____*/',
					"'".$path."'=>['index'=>-1,'mtime'=>".time().",'size'=>0,'lines'=>array(0,0)],"
				);
			}
			return $r;
		}
		// Packager_Php_Scripts_Completer::end(mkdir)
		
		// Packager_Php_Scripts_Completer::start(_changeItself)
		/**
		 * Change php file content itself - add new content after unique `$replacement`.
		 * Do not put any new line chars into `$newContent`.
		 * 
		 * @param string $replacement
		 * @param string $newContent
		 * 
		 * @return boolean
		 */
		private static function _changeItself ($replacement, $newContent) {
			$oldSelf = self::$ScriptFilename;
			$newSelf = $oldSelf . '.' . uniqid();
			$oh = @fopen($oldSelf, 'r');
			$nh = @fopen($newSelf, 'w+');
			$r1 = FALSE;
			if ($oh && $nh) {
				$r1 = TRUE;
				while (($ol = fgets($oh)) !== FALSE) {
					$pos = mb_strpos($ol, $replacement);
					if ($pos !== FALSE) {
						$ol = mb_substr($ol, 0, $pos)
							. $replacement
							. $newContent
							. mb_substr($ol, $pos + mb_strlen($replacement));
					}
					fwrite($nh, $ol);
				}
			}
			@fclose($oh);
			@fclose($nh);
			if ($r1) {
				$newOldSelf = $oldSelf . '.' . uniqid();
				$r2 = rename($oldSelf, $newOldSelf);
				$r3 = rename($newSelf, $oldSelf);
				$r4 = unlink($newOldSelf);
			}
			return $r1 && $r2 && $r3 && $r4;
		}
		// Packager_Php_Scripts_Completer::end(_changeItself)
		
		// Packager_Php_Scripts_Completer::start(file_get_contents)
		/**
		 * Return file content string
		 * 
		 * @param string   $filename
		 * @param boolean  $use_include_path
		 * @param resource $context
		 * @param int	  $offset
		 * @param int	  $maxlen
		 * 
		 * @return string|bool
		 */
		public static function FileGetContents ($filename, $use_include_path = FALSE, $context = NULL, $offset = 0/*, $maxlen = NULL*/) {
			if (self::_isProtocolPath($filename)) return call_user_func_array('file_get_contents', func_get_args());
			$path = self::NormalizePath($filename);
			$content = self::_getFileContent($path, TRUE);
			if ($content === FALSE) {
				return call_user_func_array('file_get_contents', func_get_args());
			} else {
				return $content;
			}
		}
		// Packager_Php_Scripts_Completer::end(file_get_contents)
		
		// Packager_Php_Scripts_Completer::start(file)
		/**
		 * Return file content string
		 * 
		 * @param string   $filename
		 * @param int      $flags
		 * @param resource $context
		 * 
		 * @return \string[]|bool
		 */
		public static function File ($filename, $flags = 0, $context = NULL) {
			if (self::_isProtocolPath($filename)) return call_user_func_array('file', func_get_args());
			$path = self::NormalizePath($filename);
			$content = self::_getFileContent($path, TRUE);
			if ($content === FALSE) {
				return call_user_func_array('file', func_get_args());
			} else {
				$lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
				// if `TRUE`, there are no PHP_EOL at line ends
				$fileIgnoreNewLines = $flags > 1 && $flags !== 4 && $flags !== 5;
				// if `TRUE`, empty string lines are skipped - only if `$fileIgnoreNewLines` is `TRUE`
				$fileSkipEmptyLines = $flags > 3;
				if ($fileIgnoreNewLines && $fileSkipEmptyLines) {
					foreach ($lines as $key => & $line) {
						if (mb_strlen($line) === 0)
							unset($lines[$key]);
					}
				} else if (!$fileIgnoreNewLines) {
					foreach ($lines as $key => & $line) 
						$line .= PHP_EOL;
				}
				return $lines;
			}
		}
		// Packager_Php_Scripts_Completer::end(file)
		
		// Packager_Php_Scripts_Completer::start(parse_ini_file)
		/**
		 * Parse a configuration file
		 * 
		 * @param string  $filename 
		 * @param boolean $process_sections 
		 * @param int	 $scanner_mode 
		 * 
		 * @return array|bool
		 */
		public static function ParseIniFile ($filename, $process_sections = FALSE, $scanner_mode = INI_SCANNER_NORMAL) {
			$path = self::NormalizePath($filename);
			$str = self::_getFileContent($path, TRUE);
			if ($str === FALSE) {
				return call_user_func_array('parse_ini_file', func_get_args());
			} else {
				return parse_ini_string($str, $process_sections, $scanner_mode);
			}
			
		}
		// Packager_Php_Scripts_Completer::end(parse_ini_file)
		
		// Packager_Php_Scripts_Completer::start(md5_file)
		/**
		 * Calculates the md5 hash of a given file
		 * 
		 * @param string  $filename 
		 * @param boolean $raw_output
		 * 
		 * @return string|bool
		 */
		public static function Md5File ($filename, $raw_output = FALSE) {
			$path = self::NormalizePath($filename);
			$content = self::_getFileContent($path, TRUE);
			if ($content === FALSE) {
				return call_user_func_array('md5_file', func_get_args());
			} else {
				return md5($content, $raw_output);
			}
		}
		// Packager_Php_Scripts_Completer::end(md5_file)
		
		// Packager_Php_Scripts_Completer::start(simplexml_load_file)
		/**
		 * Interprets an XML file into an object
		 * 
		 * @param string  $path 
		 * @param string  $class_name 
		 * @param int	 $options 
		 * @param string  $ns 
		 * @param boolean $is_prefix 
		 * 
		 * @return SimpleXMLElement|bool
		 */
		public static function SimplexmlLoadFile (
			$filename, $class_name = 'SimpleXMLElement', $options = 0, $ns = '', $is_prefix = FALSE
		) {
			if (self::_isProtocolPath($filename)) return call_user_func_array('simplexml_load_file', func_get_args());
			$path = self::NormalizePath($filename);
			$str = self::_getFileContent($path, TRUE);
			if ($str === FALSE) {
				return simplexml_load_file($str, $class_name, $options, $ns, $is_prefix);
			} else {
				return simplexml_load_string($str, $class_name, $options, $ns, $is_prefix);
			}
		}
		// Packager_Php_Scripts_Completer::end(simplexml_load_file)
		
		// Packager_Php_Scripts_Completer::start(file_exists)
		/**
		 * Checks whether a file or directory exists)
		 * 
		 * @param string $filename
		 * 
		 * @return bool
		 */
		public static function FileExists ($filename) {
			$path = self::NormalizePath($filename);
			if (isset(self::$Info[$path])) {
				return TRUE;
			} else {
				return file_exists($filename);
			}
		}
		// Packager_Php_Scripts_Completer::end(file_exists)
		
		// Packager_Php_Scripts_Completer::start(filemtime)
		/**
		 * Gets file modification time
		 * 
		 * @param string $filename
		 * 
		 * @return boolean|int
		 */
		public static function Filemtime ($filename) {
			$path = self::NormalizePath($filename);
			if (isset(self::$Info[$path])) {
				return self::$Info[$path]['mtime'];
			} else {
				return filemtime($filename);
			}
		}
		// Packager_Php_Scripts_Completer::end(filemtime)
		
		// Packager_Php_Scripts_Completer::start(filesize)
		/**
		 * Gets file size
		 * 
		 * @param string $filename
		 * 
		 * @return boolean|int
		 */
		public static function Filesize ($filename) {
			$path = self::NormalizePath($filename);
			if (isset(self::$Info[$path])) {
				return self::$Info[$path]['size'];	
			} else {
				return filesize($filename);
			}
		}
		// Packager_Php_Scripts_Completer::end(filesize)
		
		// Packager_Php_Scripts_Completer::start(require)
		/**
		 * require()
		 * 
		 * @param string $path 
		 * 
		 * @return mixed
		 */
		public static function RequireStandard ($path, $context = NULL) {
			return self::_includeFile($path, $context, FALSE, self::FN_REQ);
		}
		// Packager_Php_Scripts_Completer::end(require)
		
		// Packager_Php_Scripts_Completer::start(include)
		/**
		 * include()
		 * 
		 * @param string $path 
		 * 
		 * @return mixed
		 */
		public static function IncludeStandard ($path, $context = NULL) {
			return self::_includeFile($path, $context, FALSE, self::FN_INC);
		}
		// Packager_Php_Scripts_Completer::end(include)
		
		// Packager_Php_Scripts_Completer::start(require_once)
		/**
		 * require_once()
		 * 
		 * @param string $path 
		 * 
		 * @return mixed
		 */
		public static function RequireOnce ($path, $context = NULL) {
			return self::_includeFile($path, $context, TRUE, self::FN_REQ_ONCE);
		}
		// Packager_Php_Scripts_Completer::end(require_once)
		
		// Packager_Php_Scripts_Completer::start(include_once)
		/**
		 * include_once()
		 * 
		 * @param string $path 
		 * 
		 * @return mixed
		 */
		public static function IncludeOnce ($path, $context = NULL) {
			return self::_includeFile($path, $context, TRUE, self::FN_INC_ONCE);
		}
		// Packager_Php_Scripts_Completer::end(include_once)
		
	}
	// Packager_Php_Scripts_Completer::start(Init)
	Packager_Php_Wrapper::Init();
	// Packager_Php_Scripts_Completer::end(Init)

	// Packager_Php_Scripts_Completer::start(DirectoryIterator)
	class Packager_Php_Wrapper_DirectoryIterator extends DirectoryIterator {
		/**
		 * Iterator PHP package files
		 * @var array
		 */
		private $_files = array();
		/**
		 * Current iterator position - only used when HDD mode is set to false
		 * @var int
		 */
		private $_position = 0;
		/**
		 * If there are any items in PHP package, this mode is set to false 
		 * and there are used current class implementations. If there are 
		 * no items in PHP package, this mode is set to true and there are
		 * used parent class implementations.
		 * @var bool
		 */
		private $_hdd = FALSE;
		/**
		 * The DirectoryIterator class provides a simple 
		 * interface for viewing the contents of filesystem directories.
		 * @param string $path
		 */
		public function __construct ($path) {
			$relPath = rtrim(Packager_Php_Wrapper::NormalizePath($path), '/');
			$relPathLength = mb_strlen($relPath);
			$filesPaths = array();
			$pathRest = "";
			foreach (Packager_Php_Wrapper::$Info as $relPathKey => $info) {
				if (mb_strpos($relPathKey, $relPath) === 0) {
					$pathRest = ltrim(mb_substr($relPathKey, $relPathLength), '/');
					if (mb_strpos($pathRest, '/') === FALSE) {
						$filesPaths[] = $pathRest;
					}
				}
			}
			if (count($filesPaths)) {
				asort($filesPaths);
				foreach ($filesPaths as $pathRest) {
					$this->_files[] = new SplFileInfo($pathRest);
				}
			} else {
				$this->_hdd = TRUE;
				parent::__construct($path);
			}
		}
		#[\ReturnTypeWillChange]
		public function rewind() {
			if (!$this->_hdd) {
				$this->_position = 0;
			} else {
				parent::rewind();
			}
		}
		#[\ReturnTypeWillChange]
		public function current() {
			if (!$this->_hdd) return $this->_files[$this->_position];
			return parent::current();
		}
		#[\ReturnTypeWillChange]
		public function key() {
			if (!$this->_hdd) return $this->_position;
			return parent::key();
		}
		#[\ReturnTypeWillChange]
		public function next() {
			if (!$this->_hdd) {
				$this->_position += 1;
			} else {
				parent::next();
			}
		}
		#[\ReturnTypeWillChange]
		public function valid() {
			if (!$this->_hdd) return isset($this->_files[$this->_position]);
			return parent::valid();
		}
	}

	class Packager_Php_Wrapper_SplFileInfo extends SplFileInfo {
		/**
		 * If there are any items in PHP package, this mode is set to false
		 * and there are used current class implementations. If there are
		 * no items in PHP package, this mode is set to true and there are
		 * used parent class implementations.
		 * @var bool
		 */
		private $_hdd = FALSE;
		/**
		 * File record from Packager_Php_Wrapper::$Info
		 * Possible keys are: index (int), mtime (int), size (int), lines (array(from int, to int)), store (string)
		 * @var array
		 */
		private $_info = array();
		/**
		 * Normalized absolute file path.
		 * @var string
		 */
		private $_absPath = '';
		/**
		 * Normalized relative file path.
		 * @var string
		 */
		private $_relPath = '';
		/**
		 * Base file path excluding base name
		 * @var string
		 */
		private $_basePath = '';
		/**
		 * Base file name including extension.
		 * @var string
		 */
		private $_baseName = '';
		/**
		 * File extension.
		 * @var string
		 */
		private $_ext = '';
		/**
		 * The SplFileInfo class offers a high-level object oriented interface to information for an individual file.
		 * @param string $full_path
		 * @throws UnexpectedValueException
		 */
		public function __construct ($absolute_path) {
			$absolute_path = str_replace('\\', '/', $absolute_path);
			if (mb_strpos($absolute_path, '/./') !== FALSE) {
				$absolute_path = str_replace('/./', '/', $absolute_path);
			}
			if (mb_strpos($absolute_path, '/..') !== FALSE) {
				while (true) {
					$doubleDotPos = mb_strpos($absolute_path, "/..");
					if ($doubleDotPos === FALSE) {
						break;
					} else {
						$path1 = mb_substr($absolute_path, 0, $doubleDotPos);
						$path2 = mb_substr($absolute_path, $doubleDotPos + 3);
						$lastSlashPos = mb_strrpos($path1, '/');
						$path1 = mb_substr($path1, 0, $lastSlashPos);
						$absolute_path = $path1 . $path2;
					}
				}
			}

			$this->_absPath = $absolute_path;
			$this->_relPath = $this->_absPath;
			if (mb_strpos($this->_absPath, Packager_Php_Wrapper::$BasePath) === 0) {
				$this->_relPath = mb_substr($this->_absPath, Packager_Php_Wrapper::$BasePathLength);
			}

			$this->_baseName = $this->_relPath;
			$lastSlashPos = mb_strrpos($this->_relPath, '/');
			if ($lastSlashPos !== FALSE) {
				$this->_baseName = mb_substr($this->_relPath, $lastSlashPos + 1);
			}

			$this->_basePath = mb_substr($this->_absPath, 0, mb_strlen($this->_absPath) - mb_strlen($this->_baseName));

			$this->_ext = $this->_baseName;
			$lastDotPos = mb_strrpos($this->_baseName, '.');
			if ($lastDotPos !== FALSE) {
				$this->_ext = mb_substr($this->_baseName, $lastDotPos + 1);
			}

			if (isset(Packager_Php_Wrapper::$Info[$this->_relPath])) {
				$this->_info = Packager_Php_Wrapper::$Info[$this->_relPath];
			} else if (file_exists($this->_absPath)) {
				$this->_hdd = TRUE;
				parent::__construct($this->_absPath);
			}
		}
		/**
		 * Gets last access time of the file
		 * @throws RuntimeException
		 * @return int
		 */
		#[\ReturnTypeWillChange]
		public function getATime () {
			if ($this->_hdd) {
				return parent::getATime();
			} else {
				$this->_notImplemented(__METHOD__);
			}
		}
		/**
		 * Gets the base name of the file
		 * @param string $suffix
		 * @return string
		 */
		#[\ReturnTypeWillChange]
		public function getBasename ($suffix = NULL) {
			if ($this->_hdd) {
				return parent::getBasename($suffix);
			} else {
				if (!is_null($suffix)) {
					$predictedPos = mb_strlen($this->_baseName) - mb_strlen($suffix);
					if (mb_strrpos($this->_baseName, $suffix) === $predictedPos) {
						return mb_substr($this->_baseName, 0, $predictedPos);
					}
				}
				return $this->_baseName;
			}
		}
		/**
		 * Gets the inode change time
		 * @throws RuntimeException
		 * @return int
		 */
		#[\ReturnTypeWillChange]
		public function getCTime () {
			if ($this->_hdd) {
				return parent::getCTime();
			} else {
				$this->_notImplemented(__METHOD__);
			}
		}
		/**
		 * Gets the file extension
		 * @return string
		 */
		#[\ReturnTypeWillChange]
		public function getExtension () {
			if ($this->_hdd) {
				return parent::getExtension();
			} else {
				return $this->_ext;
			}
		}
		/**
		 * Gets an SplFileInfo object for the file
		 * @param $class_name
		 * @return SplFileInfo
		 */
		#[\ReturnTypeWillChange]
		public function getFileInfo ($class_name = NULL) {
			if ($this->_hdd) {
				return parent::getFileInfo($class_name);
			} else {
				return $this;
			}
		}
		/**
		 * Gets the filename including extension
		 * @return string
		 */
		#[\ReturnTypeWillChange]
		public function getFilename () {
			if ($this->_hdd) {
				return parent::getFilename();
			} else {
				return $this->_baseName;
			}
		}
		/**
		 * Gets the file group
		 * @throws RuntimeException
		 * @return int
		 */
		#[\ReturnTypeWillChange]
		public function getGroup () {
			if ($this->_hdd) {
				return parent::getGroup();
			} else {
				$this->_notImplemented(__METHOD__);
			}
		}
		/**
		 * Gets the inode for the file
		 * @throws RuntimeException
		 * @return int
		 */
		#[\ReturnTypeWillChange]
		public function getInode () {
			if ($this->_hdd) {
				return parent::getInode();
			} else {
				$this->_notImplemented(__METHOD__);
			}
		}
		/**
		 * Gets the target of a link
		 * @throws RuntimeException
		 * @return string
		 */
		#[\ReturnTypeWillChange]
		public function getLinkTarget () {
			if ($this->_hdd) {
				return parent::getLinkTarget();
			} else {
				$this->_notImplemented(__METHOD__);
			}
		}
		/**
		 * Gets the last modified time
		 * @return int|bool
		 */
		#[\ReturnTypeWillChange]
		public function getMTime () {
			if ($this->_hdd) {
				return parent::getMTime();
			} else {
				return $this->_info ? $this->_info['mtime'] : FALSE;
			}
		}
		/**
		 * Gets the owner of the file
		 * @throws RuntimeException
		 * @return int
		 */
		#[\ReturnTypeWillChange]
		public function getOwner () {
			if ($this->_hdd) {
				return parent::getOwner();
			} else {
				$this->_notImplemented(__METHOD__);
			}
		}
		/**
		 * Gets the path without filename
		 * @return string
		 */
		#[\ReturnTypeWillChange]
		public function getPath () {
			if ($this->_hdd) {
				return parent::getPath();
			} else {
				return $this->_basePath;
			}
		}
		/**
		 * Gets an SplFileInfo object for the path
		 * @param mixed $class_name
		 * @return SplFileInfo
		 */
		#[\ReturnTypeWillChange]
		public function getPathInfo ($class_name = NULL) {
			if ($this->_hdd) {
				return parent::getPathInfo($class_name);
			} else {
				return $this;
			}
		}
		/**
		 * Gets the path to the file
		 * @return string
		 */
		#[\ReturnTypeWillChange]
		public function getPathname () {
			if ($this->_hdd) {
				return parent::getPathname();
			} else {
				return $this->_basePath;
			}
		}
		/**
		 * Gets file permissions
		 * @return int
		 */
		#[\ReturnTypeWillChange]
		public function getPerms () {
			if ($this->_hdd) {
				return parent::getPerms();
			} else {
				return 0444; // read for everyone
			}
		}
		/**
		 * Gets absolute path to file
		 * @return string
		 */
		#[\ReturnTypeWillChange]
		public function getRealPath () {
			if ($this->_hdd) {
				return parent::getRealPath();
			} else {
				return $this->_absPath;
			}
		}
		/**
		 * Gets file size
		 * @return int|bool
		 */
		#[\ReturnTypeWillChange]
		public function getSize () {
			if ($this->_hdd) {
				return parent::getSize();
			} else if ($this->_info) {
				return $this->_info['size'];
			} else {
				trigger_error("[".get_class($this)."::".__METHOD__."] File doesn't exists.", E_WARNING);
				return FALSE;
			}
		}
		/**
		 * Gets file type
		 * @return string
		 */
		#[\ReturnTypeWillChange]
		public function getType () {
			if ($this->_hdd) {
				return parent::getType();
			} else {
				return 'file'; // Packager implements only files packing functionality
			}
		}
		/**
		 * Tells if the file is a directory
		 * @return bool
		 */
		#[\ReturnTypeWillChange]
		public function isDir () {
			if ($this->_hdd) {
				return parent::isDir();
			} else {
				return FALSE; // Packager doesn't implement any directories packing functionality
			}
		}
		/**
		 * Tells if the file is executable
		 * @return bool
		 */
		#[\ReturnTypeWillChange]
		public function isExecutable () {
			if ($this->_hdd) {
				return parent::isExecutable();
			} else {
				return FALSE; // Packager doesn't implement any executable files packing functionality
			}
		}
		/**
		 * Tells if the object references a regular file
		 * @return bool
		 */
		#[\ReturnTypeWillChange]
		public function isFile () {
			if ($this->_hdd) {
				return parent::isFile();
			} else {
				return TRUE; // Packager implements only files packing functionality
			}
		}
		/**
		 * Tells if the file is a link
		 * @return bool
		 */
		#[\ReturnTypeWillChange]
		public function isLink () {
			if ($this->_hdd) {
				return parent::isLink();
			} else {
				return FALSE; // Packager doesn't implement any links packing functionality
			}
		}
		/**
		 * Tells if file is readable
		 * @return bool
		 */
		#[\ReturnTypeWillChange]
		public function isReadable () {
			if ($this->_hdd) {
				return parent::isReadable();
			} else {
				return TRUE;
			}
		}
		/**
		 * Tells if the entry is writable
		 * @return bool
		 */
		#[\ReturnTypeWillChange]
		public function isWritable () {
			if ($this->_hdd) {
				return parent::isWritable();
			} else {
				return FALSE; // Packager doesn't implement any self writing functionality
			}
		}
		/**
		 * Sets the class used with SplFileInfo::openFile()
		 * @param $class_name
		 * @throws RuntimeException
		 * @return void
		 */
		#[\ReturnTypeWillChange]
		public function setFileClass ($class_name = "SplFileObject") {
			if ($this->_hdd) {
				return parent::setFileClass($class_name);
			} else {
				$this->_notImplemented(__METHOD__);
			}
		}
		/**
		 * Sets the class used with SplFileInfo::getFileInfo() and SplFileInfo::getPathInfo()
		 * @param $class_name
		 * @throws RuntimeException
		 * @return void
		 */
		#[\ReturnTypeWillChange]
		public function setInfoClass ($class_name = "SplFileInfo") {
			if ($this->_hdd) {
				return parent::setInfoClass($class_name);
			} else {
				$this->_notImplemented(__METHOD__);
			}
		}
		/**
		 * Gets an SplFileObject object for the file
		 * @param string $open_mode
		 * @param bool $use_include_path
		 * @param resource $context
		 * @throws RuntimeException
		 * @return SplFileObject
		 */
		#[\ReturnTypeWillChange]
		public function openFile ($open_mode = "r", $use_include_path = false, $context = NULL) {
			if ($this->_hdd) {
				return parent::openFile($open_mode, $use_include_path, $context);
			} else {
				$this->_notImplemented(__METHOD__);
			}
		}
		/**
		 * Returns the path to the file as a string
		 * @return string
		 */
		#[\ReturnTypeWillChange]
		public function __toString(){
			if ($this->_hdd) {
				return parent::__toString();
			} else {
				return $this->_baseName;
			}
		}

		private function _notImplemented ($methodName) {
			Packager_Php_Wrapper::PrintBacktrace();
			throw new RuntimeException(get_class($this)."::" . $methodName . "(): Not implemented.");
		}
	}
	// Packager_Php_Scripts_Completer::end(DirectoryIterator)

}