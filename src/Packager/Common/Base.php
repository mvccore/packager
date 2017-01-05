<?php

include_once(__DIR__.'/../Libs/Minify/HTML.php');

class Packager_Common_Base {
	protected $cfg;
	protected $files = array();
	protected $cliScriptName;
	protected $compilationType = ''; // PHP || PHAR
	protected $exceptionsMessages = array();
	protected $exceptionsTraces = array();
	protected $errorHandlerData = array();
	protected static $templatesExtensions = array('phtml');
	protected static $fileTypesStoringTypes = array(
		'gzip'	=> array(
			'css', 'htc', 'js', 'txt', 'svg'
		),
		'binary'	=> array(
			'ico', 'gif', 'png', 'jpg', 'jpeg', 
			'zip', 'ttf', 'eot', 'otf', 'woff', 'woff2',
		),
		'base64'	=> array(
			// 'ini',
		),
		'template'	=> array(
			'phtml',
		),
		'text'	=> array(
			'ini', 'htm', 'html', 'xml', 'xsd', 'csv',
		),
	);
	private static $_instance;
	private static $_cfgDefault = array(
		'sourcesDir'			=> '',
		'releaseFile'			=> '',
		'excludePatterns'		=> array(),
		'stringReplacements'	=> array(),
		'patternReplacements'	=> array(),
		'minifyTemplates'		=> 0,
		'minifyPhp'				=> 0,
		// PHP compiling only:
		'includeFirst'			=> array(),	
		'includeLast'			=> array(),
		'phpFsMode'				=> 'PHP_PRESERVE_HDD',
		'phpFunctionsToReplace'	=> array(),
		'phpFunctionsToKeep'	=> array(),
		'phpFunctionsToProcess'	=> array(),
		//'errorReportingLevel'	=> 5,		// E_ALL
	);
	private static $_htmlStyles = array(
		'success'	=> 'html,body{background:#005700;}',
		'error'		=> 'html,body{background:#cd1818;}.xdebug-error,.xdebug-error th,.xdebug-error td{color:#000;text-shadow:none !important;font-size:125%;}',
	);
	private static $_responseTemplates = array(
		'text'	=> "\n======================= %title =======================\n\n\n%h1\n\n\n%content\n\n",
		'html'	=> '<!DOCTYPE HTML><html lang="en-US"><head><meta charset="UTF-8"><title>%title</title><style type="text/css">html,body{margin:30px;font-size:14px;color:#fff;text-align:left;line-height:1.5em;font-weight:bold;font-family:"consolas",courier new,monotype;text-shadow:1px 1px 0 rgba(0,0,0,.4);}h1{font-size:200%;line-height:1.5em;}h2{font-size:150%;line-height:1.5em;}%style</style></head><body><h1>%h1</h1>%content</body></html>',
	);
	public function __construct ($cfg = array()) {
		$cfg = is_array($cfg) ? $cfg : array();
		foreach (self::$_cfgDefault as $key => $value) {
			if (!isset($cfg[$key])) {
				$cfg[$key] = self::$_cfgDefault[$key];
			}
		}
		$this->cfg = $cfg;
	}
	public static function Create ($cfg = array()) {
		if (!self::$_instance) {
			// set custom error handlers to catch eval warnings and errors
			set_error_handler(array(__CLASS__, 'errorHandler'));
			set_exception_handler(array(__CLASS__, 'errorHandler'));
			self::$_instance = new static($cfg);
		}
		return self::$_instance;
	}
	public static function Get ($cfg = array()) {
		if (!self::$_instance) {
			self::Create($cfg);
		} else {
			self::$_instance->MergeConfiguration($cfg);
		}
		return self::$_instance;
	}
	public function SetSourceDir ($fullOrRelativePath = '') {
		$sourceDir = ltrim($fullOrRelativePath);
		if (is_dir($sourceDir)) {
			$this->cfg['sourcesDir'] = realpath($sourceDir);
		} else if (is_dir(__DIR__ . $sourceDir)) {
			$this->cfg['sourcesDir'] = realpath(__DIR__ . $sourceDir);
		}
		return $this;
	}
	public function SetReleaseFile ($releaseFileFullPath = '') {
		$this->cfg['releaseFile'] = $releaseFileFullPath;
		return $this;
	}
	public function SetExcludePatterns ($excludePatterns = array()) {
		if (gettype($excludePatterns) == 'array') {
			$this->cfg['excludePatterns'] = $excludePatterns;
		} else {
			$this->cfg['excludePatterns'] = array($excludePatterns);
		}
		return $this;
	}
	public function AddExcludePatterns ($excludePatterns = array()) {
		if (gettype($excludePatterns) == 'array') {
			$this->MergeConfiguration(array('excludePatterns' => $excludePatterns));
		} else {
			$this->cfg['excludePatterns'][] = $excludePatterns;
		}
		return $this;
	}
	public function SetPatternReplacements ($patternReplacements = array()) {
		if (gettype($patternReplacements) == 'array') {
			$this->cfg['patternReplacements'] = $patternReplacements;
		} else {
			$this->cfg['patternReplacements'] = array($patternReplacements);
		}
		return $this;
	}
	public function AddPatternReplacements ($patternReplacements = array()) {
		if (gettype($patternReplacements) == 'array') {
			$this->MergeConfiguration(array('patternReplacements' => $patternReplacements));
		} else {
			$this->cfg['patternReplacements'][] = $patternReplacements;
		}
		return $this;
	}
	public function SetStringReplacements ($stringReplacements = array()) {
		if (gettype($stringReplacements) == 'array') {
			$this->cfg['stringReplacements'] = $stringReplacements;
		} else {
			$this->cfg['stringReplacements'] = array($stringReplacements);
		}
		return $this;
	}
	public function AddStringReplacements ($stringReplacements = array()) {
		if (gettype($stringReplacements) == 'array') {
			$this->MergeConfiguration(array('stringReplacements' => $stringReplacements));
		} else {
			$this->cfg['stringReplacements'][] = $stringReplacements;
		}
		return $this;
	}
	public function SetMinifyTemplates ($minifyTemplates = TRUE) {
		$this->cfg['minifyTemplates'] = (bool)$minifyTemplates;
		return $this;
	}
	public function SetMinifyPhp ($minifyPhp = TRUE) {
		$this->cfg['minifyPhp'] = (bool)$minifyPhp;
		return $this;
	}
	public function SetIncludeFirst ($includeFirst = array()) {
		if (gettype($includeFirst) == 'array') {
			$this->cfg['includeFirst'] = $includeFirst;
		} else {
			$this->cfg['includeFirst'] = array($includeFirst);
		}
		return $this;
	}
	public function AddIncludeFirst ($includeFirst = array(), $mode = 'append') {
		if (gettype($includeFirst) == 'array') {
			if ($mode == 'prepend') {
				for ($i = count($includeFirst) - 1; $i >= 0; $i--) {
					array_unshift($this->cfg['includeFirst'], $includeFirst[$i]);
				}
			} else {
				foreach ($includeFirst as $includeFirstItem) {
					$this->cfg['includeFirst'][] = $includeFirstItem;
				}
			}
		} else {
			if ($mode == 'prepend') {
				array_unshift($this->cfg['includeFirst'], $includeFirst);
			} else {
				$this->cfg['includeFirst'][] = $includeFirst;
			}
		}
		return $this;
	}
	public function SetIncludeLast ($includeLast = array()) {
		if (gettype($includeLast) == 'array') {
			$this->cfg['includeLast'] = $includeLast;
		} else {
			$this->cfg['includeLast'] = array($includeLast);
		}
		return $this;
	}
	public function AddIncludeLast ($includeLast = array(), $mode = 'append') {
		if (gettype($includeLast) == 'array') {
			if ($mode == 'prepend') {
				for ($i = count($includeLast) - 1; $i >= 0; $i--) {
					array_unshift($this->cfg['includeLast'], $includeLast[$i]);
				}
			} else {
				foreach ($includeLast as $includeLastItem) {
					$this->cfg['includeLast'][] = $includeLastItem;
				}
			}
		} else {
			if ($mode == 'prepend') {
				array_unshift($this->cfg['includeLast'], $includeLast);
			} else {
				$this->cfg['includeLast'][] = $includeLast;
			}
		}
		return $this;
	}
	public function SetPhpFileSystemMode ($fsMode = 'PHP_PRESERVE_HDD') {
		$this->cfg['phpFsMode'] = $fsMode;
		return $this;
	}
	public function ReplacePhpFunctions () {
		$result = isset($this->cfg['phpFunctionsToReplace']) ? $this->cfg['phpFunctionsToReplace'] : array() ;
		$this->cfg['phpFunctionsToReplace'] = array_merge($result, func_get_arg(0));
		return $this;
	}
	public function KeepPhpFunctions () {
		$result = isset($this->cfg['phpFunctionsToKeep']) ? $this->cfg['phpFunctionsToKeep'] : array() ;
		$this->cfg['phpFunctionsToKeep'] = array_merge($result, func_get_arg(0));
		return $this;
	}
	public function MergeConfiguration ($cfg = array()) {
		foreach ($cfg as $key1 => & $value1) {
			if ($value1 instanceof stdClass) $value1 = (array)$value1;
			if (gettype($value1) == 'array') {
				if (isset($this->cfg[$key1])) {
					if ($this->cfg[$key1] instanceof stdClass) {
						$this->cfg[$key1] = (array) $this->cfg[$key1];
					} else if (gettype($this->cfg[$key1]) != 'array') {
						$this->cfg[$key1] = array($this->cfg[$key1]);
					}
				} else {
					$this->cfg[$key1] = array();
				}
				foreach ($value1 as $key2 => & $value2) {
					$this->cfg[$key1][$key2] = $value2;
				}
			} else {
				$this->cfg[$key1] = $value1;
			}
		}
		return $this;
	}
	public function Run ($cfg = array()) {
		$this->cfg = (object) $this->cfg;
		$this->compilationType = strtoupper(str_replace('Packager_', '', get_class($this)));
		$this->_checkCommonConfiguration($cfg);
		return $this;
	}

	/************************************* static ************************************/
	protected static function decodeJson (& $cUrlContentStr) {
		$result = (object) array(
			'success'	=> FALSE,
			'data'		=> NULL,
			'message'	=> '',
		);
		$jsonData = json_decode($cUrlContentStr);
		if (json_last_error() == JSON_ERROR_NONE) {
			$result = $jsonData;
			$result->type = 'json';
		} else {
			$result->message = "Json decode error.";
			$result->data = $cUrlContentStr;
			$result->type = 'html';
		}
		return $result;
	}
	protected static function shrinkPhpCode (& $code = '') {
		if (!defined('T_DOC_COMMENT')) define ('T_DOC_COMMENT', -1);
		if (!defined('T_ML_COMMENT')) define ('T_ML_COMMENT', -1);
		$chars = '!"#$&\'()*+,-./:;<=>?@[\]^`{|}';
		$chars = array_flip(preg_split('//',$chars));
		$result = '';
		$space = '';
		$tokens = token_get_all($code);
		$tokensToRemove = array(
			T_COMMENT		=> 1,
			T_ML_COMMENT	=> 1,
			T_DOC_COMMENT	=> 1,
			T_WHITESPACE	=> 1,
		);
		foreach ($tokens as $token) {
			if (is_array($token)) {
				$tokenId = $token[0];
				if (isset($tokensToRemove[$tokenId])) {
					if ($tokenId == T_WHITESPACE) $space = ' ';
				} else {
					$oldPart = $token[1];
					if ($tokenId == T_ECHO) $oldPart .= ' ';
					if (
						isset($chars[substr($result, -1)]) ||
						isset($chars[$oldPart{0}])
					) $space = '';
					$result .= $space . $oldPart;
					$space = '';
				}
			} else if (is_string($token)) {
				$result .= $token; // control char: !"#$&\'()*+,-./:;<=>?@[\]^`{|}
			}
		}
		return $result;
	}
	protected static function errorHandler ($severity, $message, $file, $line, $context) {
		$backTrace = debug_backtrace();
		foreach ($backTrace as & $backTraceItem) {
			unset($backTraceItem['args'], $backTraceItem['object']);
		}
		self::$_instance->errorHandlerData[] = func_get_args();
		self::$_instance->exceptionsTraces[] = $backTrace;
	}

	/************************************* dynamic ************************************/
	protected function completeJobAndParams () {
		$jobMethod = 'mainJob';
		$params = array();
		if (php_sapi_name() == 'cli') {
			$scriptArgsItems = array_merge($_SERVER['argv'], array());
			$this->cliScriptName = array_shift($scriptArgsItems); // unset php script name - script.php
			$params = array();
			foreach ($scriptArgsItems as $scriptArgsItem) {
				$firstEqualPos = strpos($scriptArgsItem, '=');
				if ($firstEqualPos === FALSE) {
					$params[] = $scriptArgsItem;
				} else {
					$paramName = substr($scriptArgsItem, 0, $firstEqualPos);
					$paramValue = substr($scriptArgsItem, $firstEqualPos + 1);
					$params[$paramName] = $paramValue;
				}
			}
		} else {
			$params = $_GET;
		}
		foreach ($params as $key => $value) {
			if ($key != 'job') {
				$params[$key] = base64_decode($value);
			}
		}
		if (isset($params['job'])) {
			$jobMethod = $params['job'];
			unset($params['job']);
		}
		return array($jobMethod, $params);
	}
	protected function executeJobAndGetResult ($job = '', $arguments = array(), $resultType = 'json') {
		$jobResult = '';
		if (php_sapi_name() == 'cli') {
			$command = 'php ' . $this->cliScriptName . ' job=' . $job;
			foreach ($arguments as $key => $value) {
				$command .= ' ' . $key . '=' . base64_encode($value);
			}
			$jobResult = @exec($command, $out);
		} else {
			$protocol = (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on') ? 'https://' : 'http://';
			$absoluteUrl = $protocol . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
			$subProcessUrl = $absoluteUrl . '?job=' . $job;
			foreach ($arguments as $key => $value) {
				$subProcessUrl .= '&' . $key . '=' . base64_encode($value);
			}
			// $jobJsonResult = file_get_contents($subProcessUrl); // do not use file_get_contents(), when http output is 500, file_get_contents() returns false only..
			// echo $subProcessUrl . '<br />';
			$cUrlResult = $this->_processGetRequest($subProcessUrl);
			//print_r($cUrlResult);
			//die();
			$jobResult = $cUrlResult->content;
		}
		if ($resultType == 'json') {
			return self::decodeJson($jobResult);
		} else {
			return (object) array(
				'success'	=> TRUE,
				'data'		=> $jobResult,
				'type'		=> 'html',
			);
		}
	}
	protected function completeAllFiles () {
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
				$extension = strtolower($extension);
				
				$fileName = '';
				$lastSlashPos = strrpos($fullPath, '/');
				if ($lastSlashPos !== FALSE) $fileName = substr($fullPath, $lastSlashPos + 1);
				
				$relPathDir = substr($relPath, 0, strlen($relPath) - strlen($fileName) - 1);
				
				$fileItem = (object) array(
					'relPath'	 	=> $relPath,
					'fullPath'	 	=> $fullPath,
					'relPathDir'	=> $relPathDir,
					'fileName'		=> $fileName,
					'extension'		=> $extension,
					'processed'		=> FALSE,
					'content'		=> file_get_contents($fullPath),
					'utf8bomRemoved'=> FALSE,
				);

				if (!in_array($extension, static::$fileTypesStoringTypes['binary'])) {
					self::_convertFilecontentToUtf8Automaticly($fileItem);
				}
				
				if ($this->compilationType == 'PHP') {
					$fileItem->filemtime	= filemtime($fullPath);
					$fileItem->filesize		= filesize($fullPath);
				}
				
				$allFiles[$fullPath] = $fileItem;
			}
		}
		
		$this->excludeFilesByCfg($allFiles);
		
		if ($this->compilationType == 'PHP') {
			$this->files->all = $allFiles;
		} else if ($this->compilationType == 'PHAR') {
			$this->files = $allFiles;
		}
	}
	protected function excludeFilesByCfg (& $files) {
		$excludePatterns = $this->cfg->excludePatterns;
		foreach ($excludePatterns as $excludePattern) {
			$excludePattern = "/" . str_replace('/', '\\/', $excludePattern) . "/";
			foreach ($files as $fullPath => $fileInfo) {
				@preg_match($excludePattern, $fileInfo->relPath, $matches);
				if ($matches) unset($files[$fullPath]);
			}
		}
	}
	protected function sendJsonResultAndExit ($jsonData) {
		$jsonOut = json_encode($jsonData);
		header('Content-Type: text/javascript; charset=utf-8');
		header('Content-Length: ' . mb_strlen($jsonOut));
		echo $jsonOut;
		exit;
	}
	protected function sendResult ($title, $content, $type = '') {
		if (php_sapi_name() == 'cli') {
			$outputType = 'text';
			if ($type == 'success') {
				$title .= "\n";
				for ($i = 0, $l = mb_strlen($title)-1; $i < $l; $i += 1) $title .= "=";
			}
		} else {
			$outputType = 'html';
		}
		if (gettype($content) == 'string') {
			$contentStr = $content;
		} else {
			$contentItems = array();
			foreach ($content as $item) {
				$contentItems[] = $this->_sendResultRenderErrorsContentItem($outputType, $item);
			}
			if ($outputType == 'text') {
				$contentStr = implode("\n\n", $contentItems);
			} else {
				$contentStr = '<table><tbody><tr>' 
					. implode('</tr><tr>', $contentItems) 
					. '</tr></tbody></table>';
			}
		}
		$responseTmpl = self::$_responseTemplates[$outputType];
		$response = str_replace(
			array('%title', '%h1', '%content', '%style'),
			array(get_class($this), $title, $contentStr, self::$_htmlStyles[$type]),
			$responseTmpl
		);
		echo $response;
		exit;
	}
	private function _checkCommonConfiguration () {
		if (!$this->cfg->sourcesDir) {
			$this->sendResult(
				"Source directory is an empty string.", 
				"Define application source directory:<br /><br />"
				 . "\$config['sourcesDir'] = '/path/to/development/directory';", 
				'error'
			);
		}
		$this->cfg->sourcesDir = str_replace('\\', '/', realpath($this->cfg->sourcesDir));
		if (!is_dir($this->cfg->sourcesDir)) {
			$this->sendResult(
				"Source directory not found.", 
				"Define application source directory:<br /><br />"
				 . "\$config['sourcesDir'] = '/path/to/development/directory';", 
				'error'
			);
		}
		if (!$this->cfg->releaseFile) {
			$this->sendResult(
				"Release file not defined or empty string.", 
				"Define release file:<br /><br />"
					. "\$config['releaseFile'] = '/path/to/release/directory/with/index.php';", 
				'error'
			);
		}
	}
	private function _convertFilecontentToUtf8Automaticly (& $fileInfo) {
		//$this->errorHandlerData = array();
		// remove UTF-8 BOM
		if (substr($fileInfo->content, 0, 3) == pack("CCC",0xef,0xbb,0xbf)) {
			$fileInfo->content = substr($fileInfo->content, 3);
			$fileInfo->utf8bomRemoved = TRUE;
		} else {
			// detect UTF-8
			if (preg_match('#[\x80-\x{1FF}\x{2000}-\x{3FFF}]#u', $fileInfo->content)) {
				return;
			} else {
				// detect WINDOWS-1250
				if (preg_match('#[\x7F-\x9F\xBC]#', $fileInfo->content)) {
					$fileInfo->content = iconv('WINDOWS-1250', 'UTF-8', $fileInfo->content);
				} else {
					// assume ISO-8859-2
					$fileInfo->content = iconv('ISO-8859-2', 'UTF-8', $fileInfo->content);
				}
			}
		}
		/*if ($this->errorHandlerData) {
			$this->errorHandlerData = array();
			xcv($fileInfo->fullPath);
		}*/
	}
	private function _processGetRequest ($url) {
		$ch = curl_init($url);
		$timeout = 60;
		$options = array(
			CURLOPT_HTTPHEADER		=> array(
				'Accept: text/javascript',
				'Accept-Encoding: sdch, br',
				'Accept-Charset: utf-8,windows-1250;q=0.7,*;q=0.3',
				'Accept-Language: cs-CZ,cs;q=0.8',
				'Cache-Control: no-cache',
				'Cache-Control: max-age=0',
				'Connection: keep-alive',
				'Pragma: no-cache',
				'Upgrade-Insecure-Requests: 1',
			),
			CURLOPT_CONNECTTIMEOUT	=> $timeout,
			CURLOPT_TIMEOUT			=> $timeout,
			CURLOPT_MAXREDIRS		=> 10,
			CURLOPT_RETURNTRANSFER	=> TRUE,
			CURLOPT_FOLLOWLOCATION	=> TRUE,
			CURLOPT_AUTOREFERER		=> TRUE,
			CURLOPT_SSL_VERIFYPEER	=> FALSE,
			CURLOPT_SSL_VERIFYHOST	=> 2,
		);
		curl_setopt_array($ch, $options);
		$content = curl_exec($ch);
		$info = (object) curl_getinfo($ch);
		$info->error = curl_error($ch);
		$code = intval($info->http_code);
		curl_close($ch);
		return (object) array(
			'code'		=> $code,
			'info'		=> $info,
			'content'	=> $content,
		);
	}
	private function _sendResultRenderErrorsContentItem ($outputType, $item) {
		if (
			!isset($item['class']) &&
			!isset($item['function']) &&
			!isset($item['file']) &&
			!isset($item['line'])
		) {
			$contentItems = array();
			foreach ($item as $value) {
				$contentItems[] = $this->_sendResultRenderErrorsContentItem($outputType, $value);
			}
			if ($outputType == 'text') {
				return implode("\n\n", $contentItems);
			} else {
				return '<table><tbody><tr>' . implode('</tr><tr>', $contentItems) . '</tr></tbody></table>';
			}
		} else {
			if ($outputType == 'text') {
				return $item['class'] . '::' . $item['function'] . "();\n" . $item['file'] . ':' . $item['line'];
			} else {
				return '<td>' . $item['class'] . '::' . $item['function'] . '();&nbsp;</td><td>' . $item['file'] . ':' . $item['line'] . '</td>';
			}
		}
	}
}