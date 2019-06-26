<?php

class Packager_Common_Base {
	protected $cfg;
	protected $files = [];
	protected $cliScriptName;
	protected $compilationType = ''; // PHP || PHAR
	protected $exceptionsMessages = [];
	protected $exceptionsTraces = [];
	protected $includedFilesCountTillNow = 0;
	protected $errorHandlerData = [];
	protected $errorResponse = [];
	protected $autoLoadedFiles = [];
	protected static $templatesExtensions = ['phtml'];
	protected static $fileTypesStoringTypes = [
		'gzip'	=> [
			'css', 'htc', 'js', 'txt', 'svg'
		],
		'binary'	=> [
			'ico', 'gif', 'png', 'jpg', 'jpeg', 
			'zip', 'ttf', 'eot', 'otf', 'woff', 'woff2',
		],
		'base64'	=> [
			// 'ini',
		],
		'template'	=> [
			'phtml',
		],
		'text'	=> [
			'ini', 'htm', 'html', 'xml', 'xsd', 'csv',
		],
	];
	protected static $instance;
	private static $_cfgDefault = [
		'sourcesDir'				=> '',
		'releaseDir'				=> '',
		'releaseFileName'			=> '/index.php',
		'staticCopies'				=> [],
		'excludePatterns'			=> [],
		'includePatterns'			=> [],
		'stringReplacements'		=> [],
		'patternReplacements'		=> [],
		'minifyTemplates'			=> 0,
		'minifyPhp'					=> 0,
		'keepPhpDocComments'		=> [],
		// PHP compiling only:
		'autoloadingOrderDetection'	=> TRUE,
		'includeFirst'				=> [],	
		'includeLast'				=> [],
		'phpFsMode'					=> 'PHP_PRESERVE_HDD',
		'phpFunctionsToReplace'		=> [],
		'phpFunctionsToKeep'		=> [],
		'phpFunctionsToProcess'		=> [],
		//'errorReportingLevel'		=> 5,		// E_ALL
	];
	private static $_htmlStyles = [
		'success'	=> 'html,body{background:#005700;}',
		'error'		=> 'html,body{background:#cd1818;}.xdebug-error,.xdebug-error th,.xdebug-error td{color:#000;text-shadow:none !important;font-size:125%;}.xdebug-var-dump font[color*=cc0000]{background:#fff;text-shadow:none;}',
	];
	private static $_responseTemplates = [
		'text'	=> "\n======================= %title =======================\n\n\n%h1\n\n\n%content\n\n",
		'html'	=> '<!DOCTYPE HTML><html lang="en-US"><head><meta charset="UTF-8"><title>%title</title><style type="text/css">html,body{margin:30px;font-size:14px;color:#fff;text-align:left;line-height:1.5em;font-weight:bold;font-family:"consolas",courier new,monotype;text-shadow:1px 1px 0 rgba(0,0,0,.4);}h1{font-size:200%;line-height:1.5em;}h2{font-size:150%;line-height:1.5em;}%style</style></head><body><h1>%h1</h1>%content</body></html>',
	];
	public function __construct ($cfg = []) {
		$cfg = is_array($cfg) ? $cfg : [];
		foreach (self::$_cfgDefault as $key => $value) {
			if (!isset($cfg[$key])) {
				$cfg[$key] = self::$_cfgDefault[$key];
			}
		}
		$this->cfg = $cfg;
	}
	public static function Create ($cfg = []) {
		if (!self::$instance) {
			// set custom error handlers to catch eval warnings and errors
			$selfClass = version_compare(PHP_VERSION, '5.5', '>') ? self::class : __CLASS__;
			set_error_handler([$selfClass, 'ErrorHandler']);
			set_exception_handler([$selfClass, 'ErrorHandler']);
			self::$instance = new static($cfg);
		}
		return self::$instance;
	}
	public static function Get ($cfg = []) {
		if (!self::$instance) {
			self::Create($cfg);
		} else {
			self::$instance->MergeConfiguration($cfg);
		}
		return self::$instance;
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
	public function SetReleaseDir ($fullOrRelativePath = '') {
		$this->cfg['releaseDir'] = $fullOrRelativePath;
		return $this;
	}
	public function SetReleaseFileName ($releaseFileName = '/index.php') {
		$this->cfg['releaseFileName'] = $releaseFileName;
		return $this;
	}
	public function SetExcludePatterns ($excludePatterns = []) {
		if (is_array($excludePatterns)) {
			$this->cfg['excludePatterns'] = $excludePatterns;
		} else {
			$this->cfg['excludePatterns'] = [$excludePatterns];
		}
		return $this;
	}
	public function AddExcludePatterns ($excludePatterns = []) {
		if (is_array($excludePatterns)) {
			$this->MergeConfiguration(['excludePatterns' => $excludePatterns]);
		} else {
			$this->cfg['excludePatterns'][] = $excludePatterns;
		}
		return $this;
	}
	public function SetIncludePatterns ($includePatterns = []) {
		if (is_array($includePatterns)) {
			$this->cfg['includePatterns'] = $includePatterns;
		} else {
			$this->cfg['includePatterns'] = [$includePatterns];
		}
		return $this;
	}
	public function AddIncludePatterns ($includePatterns = []) {
		if (is_array($includePatterns)) {
			$this->MergeConfiguration(['includePatterns' => $includePatterns]);
		} else {
			$this->cfg['includePatterns'][] = $includePatterns;
		}
		return $this;
	}
	public function SetPatternReplacements ($patternReplacements = []) {
		if (is_array($patternReplacements)) {
			$this->cfg['patternReplacements'] = $patternReplacements;
		} else {
			$this->cfg['patternReplacements'] = [$patternReplacements];
		}
		return $this;
	}
	public function AddPatternReplacements ($patternReplacements = []) {
		if (is_array($patternReplacements)) {
			$this->MergeConfiguration(['patternReplacements' => $patternReplacements]);
		} else {
			$this->cfg['patternReplacements'][] = $patternReplacements;
		}
		return $this;
	}
	public function SetStringReplacements ($stringReplacements = []) {
		if (is_array($stringReplacements)) {
			$this->cfg['stringReplacements'] = $stringReplacements;
		} else {
			$this->cfg['stringReplacements'] = [$stringReplacements];
		}
		return $this;
	}
	public function AddStringReplacements ($stringReplacements = []) {
		if (is_array($stringReplacements)) {
			$this->MergeConfiguration(['stringReplacements' => $stringReplacements]);
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
	public function SetKeepPhpDocComments ($keepPhpDocComments = []) {
		$this->cfg['keepPhpDocComments'] = $keepPhpDocComments;
		return $this;
	}
	public function SetIncludeFirst ($includeFirst = []) {
		if (is_array($includeFirst)) {
			$this->cfg['includeFirst'] = $includeFirst;
		} else {
			$this->cfg['includeFirst'] = [$includeFirst];
		}
		return $this;
	}
	public function AddIncludeFirst ($includeFirst = [], $mode = 'append') {
		if (is_array($includeFirst)) {
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
	public function SetIncludeLast ($includeLast = []) {
		if (is_array($includeLast)) {
			$this->cfg['includeLast'] = $includeLast;
		} else {
			$this->cfg['includeLast'] = [$includeLast];
		}
		return $this;
	}
	public function AddIncludeLast ($includeLast = [], $mode = 'append') {
		if (is_array($includeLast)) {
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
	public function SetAutoloadingOrderDetection ($enabled = TRUE) {
		$this->cfg['autoloadingOrderDetection'] = $enabled;
		return $this;
	}
	public function ReplacePhpFunctions () {
		$result = isset($this->cfg['phpFunctionsToReplace']) ? $this->cfg['phpFunctionsToReplace'] : [] ;
		$this->cfg['phpFunctionsToReplace'] = array_merge($result, func_get_arg(0));
		return $this;
	}
	public function KeepPhpFunctions () {
		$result = isset($this->cfg['phpFunctionsToKeep']) ? $this->cfg['phpFunctionsToKeep'] : [] ;
		$this->cfg['phpFunctionsToKeep'] = array_merge($result, func_get_arg(0));
		return $this;
	}
	public function SetStaticCopies ($staticCopies = [/* '/from-dir' => '/to-dir', '/filename', '/dirname' */]) {
		$this->cfg['staticCopies'] = $staticCopies;
		return $this;
	}
	public function MergeConfiguration ($cfg = []) {
		foreach ($cfg as $key1 => & $value1) {
			if ($value1 instanceof stdClass) $value1 = (array)$value1;
			if (is_array($value1)) {
				if (isset($this->cfg[$key1])) {
					if ($this->cfg[$key1] instanceof stdClass) {
						$this->cfg[$key1] = (array) $this->cfg[$key1];
					} else if (!is_array($this->cfg[$key1])) {
						$this->cfg[$key1] = [$this->cfg[$key1]];
					}
				} else {
					$this->cfg[$key1] = [];
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
	public function PreRun () {
		$this->cfg = (object) $this->cfg;
		$this->compilationType = strtoupper(str_replace('Packager_', '', get_class($this)));
		$this->_checkCommonConfiguration();
		$this->_changeCurrentWorkingDirectoryToProjectRoot();
		$this->_setUpStrictExceptionsMode();
		return $this;
	}
	/**allLevelsToExceptions
	 * Print all files included by exclude/include pattern rules directly to output.
	 * 
	 * @return void
	 */
	public function PrintFilesToPack () {
		$this->PreRun();
		// complete $this->files as usual
		$this->completeAllFiles();
		$phpFiles = [];
		$staticFiles = [];
		foreach($this->files->all as $path => $fileItem){
			if ($fileItem->extension == 'php') {
				$phpFiles[] = $path;
			} else {
				$staticFiles[] = $path;
			}
		}
		$this->files->php = $phpFiles;
		$this->files->static = $staticFiles;
		unset($this->files->all);
		$this->notify("Files to pack notification");
	}

	/************************************* static ************************************/
	protected static function decodeJson (& $cUrlContentStr) {
		$result = (object) [
			'success'	=> FALSE,
			'data'		=> NULL,
			'message'	=> '',
		];
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
	public static function ErrorHandler ($severity = NULL, $message = NULL, $file = NULL, $line = NULL, $context = NULL) {
		$backTrace = debug_backtrace();
		foreach ($backTrace as & $backTraceItem) {
			unset($backTraceItem['args'], $backTraceItem['object']);
		}

		var_dump($backTrace);
		//var_dump(error_get_last());
		var_dump(func_get_args());
		
		self::$instance->errorHandlerData = func_get_args();
		self::$instance->exceptionsTraces = $backTrace;

		if (isset($backTrace[count($backTrace) - 2])) {
			$semiFinalBacktraceRec = (object) $backTrace[count($backTrace) - 2];
			if ($semiFinalBacktraceRec->class == 'Packager_Php_Completer' && $semiFinalBacktraceRec->function == 'autoloadJob') {
				if (!headers_sent()) header("HTTP/1.1 200 OK");
				$response = (object) [
					'success'			=> 2,
					'includedFiles'		=> Packager_Php_Scripts_Dependencies::CompleteIncludedFilesByTargetFile(),
					'exceptionsMessages'=> self::$instance->exceptionsMessages,
					'exceptionsTraces'	=> self::$instance->exceptionsTraces,
					'content'			=> '',
				];
				self::$instance->sendJsonResultAndExit($response);
			}
		}
	}
	public static function ExceptionHandler (/*\Exception */$exception = NULL, $exit = TRUE) {
		if (!is_null($exception)) var_dump($exception);
	}
	public static function ShutdownHandler () {
		$exception = error_get_last();
		if (!is_null($exception)) var_dump($exception);
		var_dump(get_included_files());
	}
	/************************************* dynamic ************************************/
	protected function shrinkPhpCode (& $code = '') {
		if (!defined('T_DOC_COMMENT')) define ('T_DOC_COMMENT', -1);
		if (!defined('T_ML_COMMENT')) define ('T_ML_COMMENT', -1);
		$chars = '!"#$&\'()*+,-./:;<=>?@[\]^`{|}';
		$chars = array_flip(preg_split('//',$chars));
		$result = '';
		$space = '';
		$tokens = token_get_all($code);
		$tokensToRemove = [
			T_COMMENT		=> 1,
			T_ML_COMMENT	=> 1,
			T_WHITESPACE	=> 1,
		];
		if (count($this->cfg->keepPhpDocComments) === 0) 
			$tokensToRemove[T_DOC_COMMENT] = 1;
		foreach ($tokens as & $token) {
			if (is_array($token)) {
				$tokenId = $token[0];
				$token[3] = token_name($tokenId);
				if (isset($tokensToRemove[$tokenId])) {
					if ($tokenId == T_WHITESPACE) $space = ' ';
				} else {
					$oldPart = $token[1];
					if ($tokenId == T_ECHO) $oldPart .= ' ';
					if (
						isset($chars[substr($result, -1)]) ||
						isset($chars[$oldPart{0}])
					) $space = '';
					if ($tokenId == T_DOC_COMMENT) 
						$oldPart = $this->shrinkPhpCodeReducePhpDocComment($oldPart);
					$result .= $space . $oldPart;
					$space = '';
				}
			} else if (is_string($token)) {
				$result .= $token; // control char: !"#$&\'()*+,-./:;<=>?@[\]^`{|}
			}
		}
		return $result;
	}
	protected function shrinkPhpCodeReducePhpDocComment ($code) {
		$keepPhpDocComments = $this->cfg->keepPhpDocComments;
		$result = [];
		foreach ($keepPhpDocComments as $keepPhpDocComment) {
			if ($keepPhpDocComment == '@var') {
				preg_match("#(@var)\s+([^\s]+)#", $code, $matchesVar);
				if ($matchesVar) {
					if (substr($matchesVar[2], 0, 1) == '$') {
						preg_match("#(@var)\s+([\$])([^\s]+)\s+([^\s]+)#", $code, $matchesVarInline);
						$result[] = $matchesVarInline
							? $matchesVarInline[0]
							: $matchesVar[0] ;
					} else {
						$result[] = $matchesVar[0];
					}
				}
			} else if ($keepPhpDocComment == '@param') {
				$index = 0;
				$paramLength = mb_strlen($keepPhpDocComment);
				while (TRUE) {
					$paramPos = mb_strpos($code, $keepPhpDocComment, $index);
					if ($paramPos === FALSE) break;
					$dolarPos = mb_strpos($code, '$', $paramPos + $paramLength + 1);
					if ($dolarPos === FALSE) break;
					$dolarPosPlusOne = $dolarPos + 1;
					$nextPos = [
						mb_strpos($code, '\r', $dolarPosPlusOne),
						mb_strpos($code, '\n', $dolarPosPlusOne),
						mb_strpos($code, ' ', $dolarPosPlusOne),
						mb_strpos($code, '\t', $dolarPosPlusOne)
					];
					foreach ($nextPos as $key => & $nextPosItem) if ($nextPosItem === FALSE) unset($nextPos[$key]);
					if (!$nextPos) break;
					$nextPosInt = min($nextPos);
					$result[] = mb_substr($code, $paramPos, $nextPosInt - $paramPos);
					$index = $nextPosInt + 1;
				}
			} else if ($keepPhpDocComment == '@return') {
				preg_match("#(@return)\s+([^\s]+)#", $code, $matchesReturn);
				if ($matchesReturn) $result[] = $matchesReturn[0];
			} else {
				preg_match("#" . $keepPhpDocComment . "\s#", $code, $matchesOther);
				if ($matchesOther) $result[] = trim($matchesOther[0]);
			}
		}
		if (!$result) return '';
		return '/** '.implode(' ', $result).' */';
	}
	protected function completeJobAndParams () {
		$jobMethod = 'mainJob';
		$params = [];
		if (php_sapi_name() == 'cli') {
			$scriptArgsItems = array_merge($_SERVER['argv'], []);
			$this->cliScriptName = array_shift($scriptArgsItems); // unset php script name - script.php
			$params = [];
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
		return [$jobMethod, $params];
	}
	protected function executeJobAndGetResult ($job = '', $arguments = [], $resultType = 'json') {
		$jobResult = '';
		if (php_sapi_name() == 'cli') {
			$phpBinary = str_replace('\\', '/', PHP_BINARY);
			$phpBinaryDir = dirname($phpBinary);
			$phpBinaryFile = mb_substr($phpBinary, mb_strlen($phpBinaryDir) + 1);
			$cwd = getcwd();
			chdir($phpBinaryDir);
			$command = $phpBinaryFile . ' ' . $this->cliScriptName . ' job=' . $job;
			foreach ($arguments as $key => $value) {
				$command .= ' ' . $key . '=' . base64_encode($value);
			}
			$jobResult = @exec($command, $out);
			chdir($cwd);
			//var_dump(array($jobResult, $command, $out));
		} else {
			$protocol = (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on') ? 'https://' : 'http://';
			$serverPort = isset($_SERVER['SERVER_PORT']) && strval($_SERVER['SERVER_PORT']) !== '80' ? ':' . $_SERVER['SERVER_PORT'] : '';
			$absoluteUrl = $protocol . $_SERVER['SERVER_NAME'] . $serverPort . $_SERVER['REQUEST_URI'];
			$subProcessUrl = $absoluteUrl . '?job=' . $job;
			foreach ($arguments as $key => $value) {
				$subProcessUrl .= '&' . $key . '=' . base64_encode($value);
			}
			// echo $subProcessUrl . '<br />';

			//$jobResult = file_get_contents($subProcessUrl); // do not use file_get_contents(), when http output is 500, file_get_contents() returns false only..
			
			$cUrlResult = $this->_processGetRequest($subProcessUrl);
			/*if ($cUrlResult->code == 500) {
				echo '<pre>';
				print_r($cUrlResult->info->url);
				echo '</pre>';
			}*/
			$jobResult = $cUrlResult->content;
		}
		if ($resultType == 'json') {
			return self::decodeJson($jobResult);
		} else {
			return (object) [
				'success'	=> TRUE,
				'data'		=> $jobResult,
				'type'		=> 'html',
			];
		}
	}
	protected function sendJsonResultAndExit ($jsonData) {
		$jsonOut = json_encode($jsonData);
		if (!headers_sent()) {
			header('Content-Type: text/javascript; charset=utf-8');
			header('Content-Length: ' . mb_strlen($jsonOut));
		}
		echo $jsonOut;
		exit;
	}
	protected function completeAllFiles () {
		// get project source code recursive iterator
		$rdi = new \RecursiveDirectoryIterator($this->cfg->sourcesDir);
		$rii = new \RecursiveIteratorIterator($rdi);
		$allFiles = [];
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
				
				$fileItem = (object) [
					'relPath'	 		=> $relPath,
					'fullPath'	 		=> $fullPath,
					'relPathDir'		=> $relPathDir,
					'fileName'			=> $fileName,
					'extension'			=> $extension,
					'processed'			=> FALSE,
					'content'			=> file_get_contents($fullPath),
				];

				if ($this->compilationType == 'PHP') {
					$fileItem->instance				= $item;
					$fileItem->filemtime			= filemtime($fullPath);
					$fileItem->filesize				= filesize($fullPath);
					$fileItem->utf8bomRemoved		= FALSE;
					$fileItem->containsNamespace	= Packager_Php::NAMESPACE_NONE;
				}
				
				$allFiles[$fullPath] = $fileItem;
			}
		}
		
		$this->excludeFilesByCfg($allFiles);
		
		$this->encodeFilesToUtf8($allFiles);
		
		if ($this->compilationType == 'PHP') {
			$this->files->all = $allFiles;
		} else if ($this->compilationType == 'PHAR') {
			$this->files = $allFiles;
		}
	}
	protected function excludeFilesByCfg (& $allFiles) {
		$excludePatterns = $this->cfg->excludePatterns;
		$includePatterns = $this->cfg->includePatterns;
		foreach ($includePatterns as & $includePattern) {
			$includePattern = $includePattern;
		}
		foreach ($excludePatterns as $excludePattern) {
			//$excludePattern = $excludePattern;
			foreach ($allFiles as $fullPath => & $fileInfo) {
				@preg_match($excludePattern, $fileInfo->relPath, $excludeMatches);
				if ($excludeMatches) {
					$unset = TRUE;
					foreach ($includePatterns as & $includePattern) {
						@preg_match($includePattern, $fileInfo->relPath, $includeMatches);
						if ($includeMatches) {
							$unset = FALSE;
							break;
						}
					}
					if ($unset) unset($allFiles[$fullPath]);
				}
			}
		}
	}
	protected function encodeFilesToUtf8 (& $allFiles) {
		foreach ($allFiles as $fullPath => $fileInfo) {
			if (!in_array($fileInfo->extension, static::$fileTypesStoringTypes['binary'], TRUE)) {
				self::_convertFilecontentToUtf8Automatically($fileInfo);
			}
		}
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
			$contentItems = [];
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
			['%title', '%h1', '%content', '%style'],
			[get_class($this), $title, $contentStr, self::$_htmlStyles[$type]],
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
		if (!$this->cfg->releaseDir) {
			$this->sendResult(
				"Release directory not defined or empty string.", 
				"Define release directory:<br /><br />"
					. "\$config['releaseDir'] = '/path/to/release/directory';", 
				'error'
			);
		}
	}
	private function _convertFilecontentToUtf8Automatically (& $fileInfo) {
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
		$options = [
			CURLOPT_HTTPHEADER		=> [
				'Accept: text/javascript',
				'Accept-Encoding: sdch, br',
				'Accept-Charset: utf-8,windows-1250;q=0.7,*;q=0.3',
				'Accept-Language: cs-CZ,cs;q=0.8',
				'Cache-Control: no-cache',
				'Cache-Control: max-age=0',
				'Connection: keep-alive',
				'Pragma: no-cache',
				'Upgrade-Insecure-Requests: 1',
			],
			CURLOPT_CONNECTTIMEOUT	=> $timeout,
			CURLOPT_TIMEOUT			=> $timeout,
			CURLOPT_MAXREDIRS		=> 10,
			CURLOPT_RETURNTRANSFER	=> TRUE,
			CURLOPT_FOLLOWLOCATION	=> TRUE,
			CURLOPT_AUTOREFERER		=> TRUE,
			CURLOPT_SSL_VERIFYPEER	=> FALSE,
			CURLOPT_SSL_VERIFYHOST	=> 2,
		];
		curl_setopt_array($ch, $options);
		$content = curl_exec($ch);
		$info = (object) curl_getinfo($ch);
		$info->error = curl_error($ch);
		$code = intval($info->http_code);
		curl_close($ch);
		//var_dump($content);
		return (object) [
			'code'		=> $code,
			'info'		=> $info,
			'content'	=> $content,
		];
	}
	private function _sendResultRenderErrorsContentItem ($outputType, $item) {
		if (
			!isset($item['class']) &&
			!isset($item['function']) &&
			!isset($item['file']) &&
			!isset($item['line'])
		) {
			$contentItems = [];
			foreach ($item as $value) {
				$contentItems[] = $this->_sendResultRenderErrorsContentItem($outputType, (array) $value);
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
	private function _changeCurrentWorkingDirectoryToProjectRoot () {
		$startDir = rtrim(str_replace('\\', '/', getcwd()), '/');
		$projectRootDir = '';
		// try to detect current or any parent folder with composer.json
		$currentDir = $startDir;
		while (TRUE) {
			if (file_exists($currentDir . '/vendor/autoload.php')) {
				$projectRootDir = $currentDir;
				break;
			} else {
				$lastSlashPos = mb_strrpos($currentDir, '/');
				if ($lastSlashPos === FALSE) break;
				$currentDir = mb_substr($currentDir, 0, $lastSlashPos);
			}
		}
		if ($projectRootDir && $startDir !== $projectRootDir) chdir($projectRootDir);
	}
	private function _setUpStrictExceptionsMode () {
		$prevErrorHandler = NULL;
		$errorLevels = array_fill_keys([E_ERROR,E_RECOVERABLE_ERROR,E_CORE_ERROR,E_USER_ERROR,E_WARNING,E_CORE_WARNING,E_USER_WARNING], TRUE);
		$prevErrorHandler = set_error_handler(
			function(
				$errLevel, $errMessage, $errFile, $errLine, $errContext
			) use (
				& $prevErrorHandler, $errorLevels
			) {
				if ($errFile === '' && defined('HHVM_VERSION'))  // https://github.com/facebook/hhvm/issues/4625
					$errFile = func_get_arg(5)[1]['file'];
				if (isset($errorLevels[$errLevel]))
					throw new \ErrorException($errMessage, $errLevel, $errLevel, $errFile, $errLine);
				return $prevErrorHandler 
					? call_user_func_array($prevErrorHandler, func_get_args()) 
					: FALSE;
			}
		);
	}
}
