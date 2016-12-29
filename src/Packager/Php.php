<?php

/**
 * Packager
 *
 * This source file is subject to the BSD 3 License
 * For the full copyright and license information, please view 
 * the LICENSE.md file that are distributed with this source code.
 *
 * @copyright	Copyright (c) 2016 Tom Flídr (https://github.com/mvccore/packager)
 * @license		https://mvccore.github.io/docs/packager/1.0.0/LICENCE.md
 */

class Packager_Php
{
	private static $instance;
	private static $mimeTypes = array(
		// app files
		'php'	=> 'application/php',
		'htm'	=> 'text/html',
		'html'	=> 'text/html',
		'phtml'	=> 'text/html',
		'xml'	=> 'text/xml',
		'config'=> 'text/xml',
		'css'	=> 'text/css',
		'htc'	=> 'text/x-component',
		'js'	=> 'application/javascript',
		'txt'	=> 'text/plain',
		'zip'	=> 'application/zip',
		// fonts
		'ttf'	=> 'application/x-font-ttf',
		'eot'	=> 'application/vnd.ms-fontobject',
		'otf'	=> 'application/x-font-otf',
		'woff'	=> 'application/x-font-woff',
		'woff2'	=> 'application/x-font-woff',
		// images
		'ico'	=> 'image/x-icon',
		'gif'	=> 'image/gif',
		'png'	=> 'image/png',
		'jpg'	=> 'image/jpg',
		'jpeg'	=> 'image/jpeg',
		'bmp'	=> 'image/bmp',
		'svg'	=> 'image/svg+xml',
	);
	private static $_responseTemplates = array(
		'text'	=> "=================== %title ===================\n\n%h1\n\n%content\n\n",
		'html'	=> '<!DOCTYPE HTML><html lang="en-US"><head><meta charset="UTF-8"><title>%title</title><style type="text/css">html,body{margin:30px;font-size:14px;color:#fff;text-align:left;line-height:1.5em;font-weight:bold;font-family:"consolas",courier new,monotype;text-shadow:1px 1px 0 rgba(0,0,0,.4);}h1{font-size:200%;line-height:1.5em;}h2{font-size:150%;line-height:1.5em;}%style</style></head><body><h1>%h1</h1>%content</body></html>',
	);
	private static $_htmlStyles = array(
		'success'	=> 'html,body{background:#005700;}',
		'error'		=> 'html,body{background:#cd1818;}',
	);
	private $cfg;
	private $files = array();
	private $result;
	public static function run ($cfg = array())
	{
		self::$instance = new self($cfg);
		self::$instance->process();
	}
	public function __construct ($cfg = array())
	{
		$cfg = (object) $cfg;
		$cfg->sourcesDir = realpath($cfg->sourcesDir);
		$cfg->sourcesDir = str_replace('\\', '/', realpath($cfg->sourcesDir));
		if (!is_dir($cfg->sourcesDir)) die('Source directory not found.');
		$this->cfg = $cfg;
		$this->files = (object) array(
			'all'		=> array(),
			'php'		=> array(),
			'static'	=> array(),
		);
	}
	public function process ($cfg = array())
	{
		$this->_completeFilesPathsAndTypes();
		$this->_completePhpFiles();
		$this->_processPhpCode();
		$this->_completeStaticFiles();
		$this->_completeResult();
		$this->_saveResult();
		$this->_notify();
	}
	private function _completeFilesPathsAndTypes ()
	{
		// get project source code recursive iterator
		$rdi = new \RecursiveDirectoryIterator($this->cfg->sourcesDir);
		$rii = new \RecursiveIteratorIterator($rdi);
		
		$allFiles = array();
		foreach($rii as $item){
			if (!$item->isDir()) {
				
				$fullPath = str_replace('\\', '/', $item->__toString());
				$relPath = substr($fullPath, strlen($this->cfg->sourcesDir));
				
				$extension = '';
				$lastDotPos = strrpos($fullPath, '.');
				if ($lastDotPos !== FALSE) $extension = substr($fullPath, $lastDotPos + 1);
				
				$fileName = '';
				$lastSlashPos = strrpos($fullPath, '/');
				if ($lastSlashPos !== FALSE) $fileName = substr($fullPath, $lastSlashPos + 1);
				
				$relPathDir = substr($relPath, 0, strlen($relPath) - strlen($fileName) - 1);
				
				$mimeType = '';
				if (isset(self::$mimeTypes[$extension])) $mimeType = self::$mimeTypes[$extension];
				
				$allFiles[$relPath] = (object) array(
					'fullPath'	 	=> $fullPath,
					'filemtime'		=> filemtime($fullPath),
					'relPathDir'	=> $relPathDir,
					'fileName'		=> $fileName,
					'extension'		=> $extension,
					'mimeType'		=> $mimeType,
					'processed'		=> FALSE,
				);
			}
		}
		
		ksort($allFiles);
		$this->files->all = $allFiles;
		
		$excludePatterns = $this->cfg->excludePatterns;
		foreach ($excludePatterns as $excludePattern) {
			$excludePattern = "/" . str_replace('/', '\\/', $excludePattern) . "/";
			foreach ($this->files->all as $relPath => $fileInfo) {
				@preg_match($excludePattern, $relPath, $matches);
				if ($matches) unset($this->files->all[$relPath]);
			}
		}
	}
	private function _completePhpFiles ()
	{
		$includeFirst = $this->cfg->includeFirst;
		foreach ($includeFirst as $includeFirstPathBegin) {
			$this->_completePhpFiles_completeCollection($includeFirstPathBegin);
		}
		$this->_completePhpFiles_completeCollection();
	}
	private function _completePhpFiles_completeCollection ($pathBegin = '')
	{
		$fileInfoRecordsToInclude = array();
		foreach ($this->files->all as $relPath => $fileInfo) {
			if ($fileInfo->extension == 'php') {
				if (strlen($pathBegin) > 0 && strpos($relPath, $pathBegin) === 0) {
					$fileInfoRecordsToInclude[$relPath] = $fileInfo;
					$this->files->all[$relPath]->processed = TRUE;
				} else if (strlen($pathBegin) === 0) {
					$fileInfoRecordsToInclude[$relPath] = $fileInfo;
					$this->files->all[$relPath]->processed = TRUE;
				}
			}
		}
		ksort($fileInfoRecordsToInclude);
		foreach ($fileInfoRecordsToInclude as $relPath => $fileInfo) {
			$fileInfo->content = file_get_contents($fileInfo->fullPath);
			$this->files->php[$relPath] = $fileInfo;
		}
	}
	private function _processPhpCode ()
	{
		foreach ($this->files->php as $relPath => $fileInfo) {
		
			if ($this->cfg->compressPhp) $fileInfo->content = $this->_processPhpCode_shrinkPhpCode($fileInfo->content);
			
			if (mb_strpos($fileInfo->content, '<' . '?php') === 0) {
				$fileInfo->content = mb_substr($fileInfo->content, 5);
			} else if (mb_strpos($fileInfo->content, '<' . '?') === 0) {
				$fileInfo->content = mb_substr($fileInfo->content, 2);
			}
			$fileInfo->content = trim($fileInfo->content);

			$fileInfo->content = str_replace('__DIR__', "'" . $fileInfo->relPathDir . "'", $fileInfo->content);
			$fileInfo->content = str_replace('__FILE__', "'" . $fileInfo->relPathDir . $fileInfo->fileName . "'", $fileInfo->content);
			
			preg_match("/[^a-zA-Z0-9_](readfile)\(/", $fileInfo->content, $matches);
			if ($matches) {
				$fileInfo->content = preg_replace_callback(
					"/[^a-zA-Z0-9_](readfile)\(/",
					"Packager_Php::ProcessPhpCodeReadfileCalls",
					$fileInfo->content
				);
			}
				
			preg_match("/[^a-zA-Z0-9_](file_get_contents)\(/", $fileInfo->content, $matches);
			if ($matches) {
				$fileInfo->content = preg_replace_callback(
					"/[^a-zA-Z0-9_](file_get_contents)\(/",
					"Packager_Php::ProcessPhpCodeFilegetcontentsCalls",
					$fileInfo->content
				);
			}
			
			preg_match("/[^a-zA-Z0-9_\@]require\(/", $fileInfo->content, $matches);
			if ($matches) {
				$method = "Packager_Php::ProcessPhpCodeRequireCalls";
				if (mb_strpos($fileInfo->content, '$this') !== FALSE) {
					preg_match('/\$this/', $fileInfo->content, $thisMatches);
					if ($thisMatches) {
						$method = "Packager_Php::ProcessPhpCodeRequireCallsWithContext";
					}
				}
				$fileInfo->content = preg_replace_callback(
					"/([^a-zA-Z0-9_\@])require\(([^\)]*)\)/",
					$method,
					$fileInfo->content
				);
			}
				
			preg_match("/[^a-zA-Z0-9_\@]include\(/", $fileInfo->content, $matches);
			if ($matches) {
				$method = "Packager_Php::ProcessPhpCodeIncludeCalls";
				if (mb_strpos($fileInfo->content, '$this') !== FALSE) {
					preg_match('/\$this/', $fileInfo->content, $thisMatches);
					if ($thisMatches) {
						$method = "Packager_Php::ProcessPhpCodeIncludeCallsWithContext";
					}
				}
				$fileInfo->content = preg_replace_callback(
					"/([^a-zA-Z0-9_\@])include\(([^\)]*)\)/",
					$method,
					$fileInfo->content
				);
			}
			
				
			preg_match("/[^a-zA-Z0-9_\@]file_exists\(/", $fileInfo->content, $matches);
			if ($matches) {
				$fileInfo->content = preg_replace_callback(
					"/([^a-zA-Z0-9_\@])file_exists\(([^\)]*)\)/",
					"Packager_Php::ProcessPhpCodeFileExistsCalls",
					$fileInfo->content
				);
			}
				
			preg_match("/[^a-zA-Z0-9_\@]filemtime\(/", $fileInfo->content, $matches);
			if ($matches) {
				$fileInfo->content = preg_replace_callback(
					"/([^a-zA-Z0-9_\@])filemtime\(([^\)]*)\)/",
					"Packager_Php::ProcessPhpCodeFilemtimeCalls",
					$fileInfo->content
				);
			}
			
			preg_match("/[^a-zA-Z0-9_\@]include_once\(/", $fileInfo->content, $matches);
			if ($matches) {
				$fileInfo->content = preg_replace_callback(
					"/([^a-zA-Z0-9_\@])include_once\(([^\)]*)\)/",
					"Packager_Php::ProcessPhpCodeIncludeOnceCalls",
					$fileInfo->content
				);
			}
			
			preg_match("/[^a-zA-Z0-9_\@]parse_ini_file\(/", $fileInfo->content, $matches);
			if ($matches) {
				$fileInfo->content = preg_replace_callback(
					"/([^a-zA-Z0-9_\@])parse_ini_file\(([^\)]*)\)/",
					"Packager_Php::ProcessPhpCodeParseIniFile",
					$fileInfo->content
				);
			}
			
			preg_match("/[^a-zA-Z0-9_\@]simplexml_load_file\(/", $fileInfo->content, $matches);
			if ($matches) {
				$fileInfo->content = preg_replace_callback(
					"/([^a-zA-Z0-9_\@])simplexml_load_file\(([^\)]*)\)/",
					"Packager_Php::ProcessPhpCodeSimplexmlLoadFile",
					$fileInfo->content
				);
			}
			
			preg_match("/[^a-zA-Z0-9_]new DirectoryIterator\(/", $fileInfo->content, $matches);
			if ($matches) {
				$fileInfo->content = preg_replace_callback(
					"/([^a-zA-Z0-9_])new DirectoryIterator\(([^\)]*)\)/",
					"Packager_Php::ProcessPhpCodeDirectoryIteratorCalls",
					$fileInfo->content
				);
			}
			
			$this->files->php[$relPath] = $fileInfo;
		}
	}
	private function _processPhpCode_shrinkPhpCode ($code = '')
	{
		// PHP 4 & 5 compatibility
		if (!defined('T_DOC_COMMENT')) define ('T_DOC_COMMENT', -1);
		if (!defined('T_ML_COMMENT')) define ('T_ML_COMMENT', -1);
		$space = $result = '';
		$set = '!"#$&\'()*+,-./:;<=>?@[\]^`{|}';
		$set = array_flip(preg_split('//',$set));
		foreach (token_get_all($code) as $token)	{
			if (!is_array($token))
			$token = array(0, $token);
			switch ($token[0]) {
			case T_COMMENT:
			case T_ML_COMMENT:
			case T_DOC_COMMENT:
			case T_WHITESPACE:
				$space = ' ';
				break;
			default:
				if (isset($set[substr($result, -1)]) ||
					isset($set[$token[1]{0}])) $space = '';
				$result .= $space . $token[1];
				$space = '';
			}
		}
		return $result;
	}
	public static function ProcessPhpCodeReadfileCalls ($matches)
	{
		return str_replace('readfile(', 'PHP_PACKAGER::READFILE(', $matches[0]);
	}
	public static function ProcessPhpCodeFilegetcontentsCalls ($matches)
	{
		return str_replace('file_get_contents(', 'PHP_PACKAGER::FILE_GET_CONTENTS(', $matches[0]);
	}
	public static function ProcessPhpCodeRequireCalls ($matches)
	{
		return $matches[1] . 'PHP_PACKAGER::STANDARD_REQUIRE(' . $matches[2] . ')';
	}
	public static function ProcessPhpCodeRequireCallsWithContext ($matches)
	{
		return $matches[1] . 'PHP_PACKAGER::CONTEXT_REQUIRE(' . $matches[2] . ', $this)';
	}
	public static function ProcessPhpCodeIncludeCalls ($matches)
	{
		return $matches[1] . 'PHP_PACKAGER::STANDARD_INCLUDE(' . $matches[2] . ')';
	}
	public static function ProcessPhpCodeIncludeCallsWithContext ($matches)
	{
		return $matches[1] . 'PHP_PACKAGER::CONTEXT_INCLUDE(' . $matches[2] . ', $this)';
	}
	public static function ProcessPhpCodeFileExistsCalls ($matches)
	{
		return $matches[1] . 'PHP_PACKAGER::FILE_EXISTS(' . $matches[2] . ')';
	}
	public static function ProcessPhpCodeFilemtimeCalls ($matches)
	{
		return $matches[1] . 'PHP_PACKAGER::FILEMTIME(' . $matches[2] . ')';
	}
	public static function ProcessPhpCodeIncludeOnceCalls ($matches)
	{
		return $matches[1];
	}
	public static function ProcessPhpCodeParseIniFile ($matches)
	{
		return $matches[1] . 'PHP_PACKAGER::PARSE_INI_FILE(' . $matches[2] . ')';
	}
	public static function ProcessPhpCodeSimplexmlLoadFile ($matches)
	{
		return $matches[1] . 'PHP_PACKAGER::SIMPLEXML_LOAD_FILE(' . $matches[2] . ')';
	}
	public static function ProcessPhpCodeDirectoryIteratorCalls ($matches)
	{
		return $matches[1] . 'PHP_PACKAGER::DIRECTORY_ITERATOR(' . $matches[2] . ')';
	}
	private function _completeStaticFiles ()
	{
		$includeFirst = $this->cfg->includeFirst;
		foreach ($includeFirst as $includeFirstPathBegin) {
			$this->_completeStaticFiles_completeCollection($includeFirstPathBegin);
		}
		$this->_completeStaticFiles_completeCollection();
	}
	private function _completeStaticFiles_completeCollection ($pathBegin = '')
	{
		$fileInfoRecordsToInclude = array();
		foreach ($this->files->all as $relPath => $fileInfo) {
			if ($fileInfo->extension !== 'php') {
				if (strlen($pathBegin) > 0 && strpos($relPath, $pathBegin) === 0) {
					$fileInfoRecordsToInclude[$relPath] = $fileInfo;
					$this->files->all[$relPath]->processed = TRUE;
				} else if (strlen($pathBegin) === 0) {
					$fileInfoRecordsToInclude[$relPath] = $fileInfo;
					$this->files->all[$relPath]->processed = TRUE;
				}
			}
		}
		ksort($fileInfoRecordsToInclude);
		foreach ($fileInfoRecordsToInclude as $relPath => $fileInfo) {
			$fileInfo->content = base64_encode(file_get_contents($fileInfo->fullPath));
			$this->files->static[$relPath] = $fileInfo;
		}
	}
	private function _completeResult ()
	{
		$phpAppShrinkerCode = 'class PHP_PACKAGER {
	public static $CONTEXT;
	private static $STATICS_ENCODED = array(__PHP_STATIC_SHRINKED_FILES__);
	private static $STATICS_DECODED = array();
	private static $SCRIPTS = array(__PHP_SCRIPTS_SHRINKED_FILES__);
	private static function NORMALIZE_PATH ($path, $absolutely = TRUE) {
		$path = str_replace("\\\", "/", $path);
		if (mb_strpos($path, "/./") !== FALSE) {
			$path = str_replace("/./", "/", $path);
		}
		if (mb_strpos($path, "/..") !== FALSE) {
			while (true) {
				$doubleDotPos = mb_strpos($path, "/..");
				if ($doubleDotPos === FALSE) {
					break;
				} else {
					$path1 = mb_substr($path, 0, $doubleDotPos);
					$path2 = mb_substr($path, $doubleDotPos + 3);
					$lastSlashPos = mb_strrpos($path1, "/");
					$path1 = mb_substr($path1, 0, $lastSlashPos);
					$path = $path1 . $path2;
				}
			}
		}
		if ($absolutely) {
			$basePath = str_replace("\\\", "/", __DIR__);
			if (strpos($path, $basePath) === 0) {
				$path = substr($path, strlen($basePath));
			}
		}
		return $path;
	}
	private static function EVAL_FILE ($path, $context = NULL)
	{
		$path = self::NORMALIZE_PATH($path, FALSE);
		$content = self::GET_STATIC($path);
		if (!is_null($context)) self::$CONTEXT = $context;
		try {
			if (!is_null($context)) {
				$content = preg_replace_callback(
					\'/\$this([^a-zA-Z0-9_])/\',
					"PHP_PACKAGER::REPLACE_CONTEXT",
					$content
				);
			}
			eval(" ?".">" . $content . "<" . "?php ");
		} catch (Exception $e) {
			throw $e;
		}
		self::$CONTEXT = null;
	}
	private static function GET_STATIC ($key) {
		if (!isset(self::$STATICS_DECODED[$key]) && isset(self::$STATICS_ENCODED[$key])) {
			self::$STATICS_DECODED[$key] = base64_decode(self::$STATICS_ENCODED[$key][1]);
		}
		return isset(self::$STATICS_DECODED[$key]) ? self::$STATICS_DECODED[$key] : "";
	}
	public static function REPLACE_CONTEXT ($matches) {
		return \'PHP_PACKAGER::$CONTEXT\' . $matches[1];
	}
	public static function STANDARD_REQUIRE ($path) {
		self::EVAL_FILE($path);
	}
	public static function STANDARD_INCLUDE ($path) {
		self::EVAL_FILE($path);
	}
	public static function CONTEXT_REQUIRE ($path, $context) {
		self::EVAL_FILE($path, $context);
	}
	public static function CONTEXT_INCLUDE ($path, $context) {
		self::EVAL_FILE($path, $context);
	}
	public static function FILE_GET_CONTENTS ($path) {
		$path = self::NORMALIZE_PATH($path, TRUE);
		return self::GET_STATIC($path);
	}
	public static function READFILE ($path) {
		$path = self::NORMALIZE_PATH($path, TRUE);
		echo self::GET_STATIC($path);
	}
	public static function FILE_EXISTS ($path) {
		$path = self::NORMALIZE_PATH($path, TRUE);
		if (isset(self::$SCRIPTS[$path])) return self::$SCRIPTS[$path][1];
		if (isset(self::$STATICS_ENCODED[$path])) return self::$STATICS_ENCODED[$path][1];
	}
	public static function FILEMTIME ($path) {
		$path = self::NORMALIZE_PATH($path, TRUE);
		if (isset(self::$SCRIPTS[$path])) return self::$SCRIPTS[$path][0];
		if (isset(self::$STATICS_ENCODED[$path])) return self::$STATICS_ENCODED[$path][0];
	}
	public static function PARSE_INI_FILE ($path, $sections = FALSE, $scanner = INI_SCANNER_NORMAL) {
		$path = self::NORMALIZE_PATH($path, TRUE);
		$content = self::GET_STATIC($path);
		return parse_ini_string($content, $sections, $scanner);
	}
	public static function SIMPLEXML_LOAD_FILE ($path, $class_name = "SimpleXMLElement", $options = 0, $ns = "", $is_prefix = false)
	{
		$path = self::NORMALIZE_PATH($path, TRUE);
		$content = self::GET_STATIC($path);
		return simplexml_load_string($content, $class_name, $options, $ns, $is_prefix);
	}
	public static function DIRECTORY_ITERATOR ($absolutePath)
	{
		$relativePath = rtrim(self::NORMALIZE_PATH($absolutePath, TRUE), "/");
		$relativePathLength = mb_strlen($relativePath);
		$resultPaths = array();
		$result = array();
		$pathRest = "";
		foreach (self::$STATICS_ENCODED as $path => $value) {
			if (mb_strpos($path, $relativePath) === 0) {
				$pathRest = ltrim(mb_substr($path, $relativePathLength), "/");
				if (mb_strpos($pathRest, "/") === FALSE) {
					$resultPaths[] = $pathRest;
				}
			}
		}
		asort($resultPaths);
		foreach ($resultPaths as $pathRest) {
			$result[] = new SplFileInfo($pathRest);
		}
		return $result;
	}
}';
		
		if ($this->cfg->compressPhp) {
			$phpAppShrinkerCode = "error_reporting(E_ALL ^ E_NOTICE);\r\n" . preg_replace("#[\r\n\t]#", "", $phpAppShrinkerCode);
		}
		
		$phpAppStaticFiles = "\n";
		foreach ($this->files->static as $relPath => $fileInfo) {
			$content = $fileInfo->content;
			$filemtime = $fileInfo->filemtime;
			$phpAppStaticFiles .= "'$relPath'=>array($filemtime,'$content'),\n";
		}
		$phpAppShrinkerCode = str_replace('__PHP_STATIC_SHRINKED_FILES__', $phpAppStaticFiles, $phpAppShrinkerCode);
		
		$phpAppScriptFiles = "\n";
		foreach ($this->files->php as $relPath => $fileInfo) {
			$filemtime = $fileInfo->filemtime;
			$phpAppScriptFiles .= "'$relPath'=>array($filemtime,1),\n";
		}
		$phpAppShrinkerCode = str_replace('__PHP_SCRIPTS_SHRINKED_FILES__', $phpAppScriptFiles, $phpAppShrinkerCode);
		
		foreach ($this->files->php as $relPath => $fileInfo) {
			$phpAppShrinkerCode .= "\r\n" . $fileInfo->content;
		}
		
		foreach ($this->cfg->patternReplacements as $pattern => $replacement) {
			$phpAppShrinkerCode = preg_replace($pattern, $replacement, $phpAppShrinkerCode);
		}
		
		foreach ($this->cfg->stringReplacements as $from => $to) {
			$phpAppShrinkerCode = str_replace($from, $to, $phpAppShrinkerCode);
		}
		
		$this->result = $phpAppShrinkerCode;
	}
	private function _saveResult ()
	{
		$releaseFile = $this->cfg->releaseFile;
		unlink($releaseFile);
		file_put_contents($releaseFile, "<?php\r\n" . $this->result);
	}
	private function _notify ()
	{
		$phpFiles = array_keys($this->files->php);
		$staticFiles = array_keys($this->files->static);
		if (php_sapi_name() == 'cli') {
			$content = "Included PHP files:\n\n"
				. implode("\n", $phpFiles)
				. "\n\nIncluded static files:\n\n"
				. implode("\n", $staticFiles)
				. "\n\nDONE";
			$this->_sendResult(
				'Successfly packed.', 
				$content
			);
		} else {
			$content = '<h2>Included PHP files:</h2><div class="files">'
				. implode('<br />', $phpFiles)
				. '<h2>Included static files:</h2><div class="files">'
				. implode('<br />', $staticFiles)
				. '</div><h2>DONE</h2>';
			$this->_sendResult(
				'Successfly packed.', 
				$content,
				'success'
			);
		}
	}
	private function _sendResult ($title, $content, $type = '')
	{
		$outputType = php_sapi_name() == 'cli' ? 'text' : 'html' ;
		if (gettype($content) == 'string') {
			$contentStr = $content;
		} else {
			$contentItems = array();
			foreach ($content as $item) {
				if ($outputType == 'text') {
					$contentItems[] = $item['class'] . '::' . $item['function'] . "();\n" . $item['file'] . ':' . $item['line'];
				} else {
					$contentItems[] = '<td>' . $item['class'] . '::' . $item['function'] . '();&nbsp;</td><td>' . $item['file'] . ':' . $item['line'] . '</td>';
				}
			}
			if ($outputType == 'text') {
				$contentStr = implode("\n\n", $contentItems);
			} else {
				$contentStr = '<table><tbody><tr>' . implode('</tr><tr>', $contentItems) . '</tr></tbody></table>';
			}
		}
		$responseTmpl = self::$_responseTemplates[$outputType];
		$response = str_replace(
			array('%title', '%h1', '%content', '%style'),
			array('MvcCore PHP Packager', $title, $contentStr, self::$_htmlStyles[$type]),
			$responseTmpl
		);
		echo $response;
		die();
	}
}