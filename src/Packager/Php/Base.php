<?php

include_once(__DIR__.'/../Common/Base.php');
include_once(__DIR__.'/Scripts/Replacer.php');

class Packager_Php_Base extends Packager_Common_Base
{
	protected static $wrapperClassName = 'Packager_Php_Wrapper';
	protected static $wrapperStringDeclarator = 'PACKAGER_';
	protected static $wrapperReplacements = array(
		T_DIR			=> NULL, // callable closure function, initialized later
		T_FILE			=> NULL, // callable closure function, initialized later
		T_REQUIRE_ONCE	=> array('require_once','%WrapperClass%::RequireOnce'),
		T_INCLUDE_ONCE	=> array('include_once','%WrapperClass%::IncludeOnce'),
		T_REQUIRE		=> array('require',		'%WrapperClass%::RequireStandard'), 
		T_INCLUDE		=> array('include',		'%WrapperClass%::IncludeStandard'), 
		T_STRING		=> array(
			'DirectoryIterator' 			=> '%WrapperClass%_DirectoryIterator',
			// 'RecursiveDirectoryIterator'	=> 'RecursiveDirectoryIterator', // not implemented
			'SplFileInfo' 					=> '%WrapperClass%_SplFileInfo',
			'readfile'						=> '%WrapperClass%::Readfile',
			'file_get_contents'				=> '%WrapperClass%::FileGetContents',
			'file_exists'					=> '%WrapperClass%::FileExists',
			'filemtime'						=> '%WrapperClass%::Filemtime',
			'filesize'						=> '%WrapperClass%::Filesize',
			'simplexml_load_file'			=> '%WrapperClass%::SimplexmlLoadFile',
			'parse_ini_file'				=> '%WrapperClass%::ParseIniFile',
			'md5_file'						=> '%WrapperClass%::Md5File',
		),
	);
	protected static $includeFirstDefault = array(
	);
	protected static $includeLastDefault = array(
		'/index.php',
	);
	protected static $excludePatternsDefault = array(
		'^/Libs/startup\.php$',
	);
	/**
	 *	0 - Turn off all error reporting 
	 *	1 -	Running errors (E_ERROR | E_WARNING | E_PARSE)
	 *	2 - Running errors + notices (E_ERROR | E_WARNING | E_PARSE | E_NOTICE)
	 *	3 - All errors except notices and warnings (E_ALL ^ (E_NOTICE | E_WARNING))
	 *	4 - All errors except notices (E_ALL ^ E_NOTICE)
	 *	5 - All errors (E_ALL)
	 */
	protected static $errorReportingLevelDefault = 5; // E_ALL
	protected static $phpReplacementsStatistics = array();
	protected static $wrapperInternalElementsDependencies = array(
		'NormalizePath'							=> ',require_once,include_once,require,include,readfile,file_get_contents,parse_ini_file,simplexml_load_file,filemtime,filesize,file_exists,DirectoryIterator,SplFileInfo,md5_file,',
		'Warning'								=> ',require_once,include_once,require,include,readfile,file_get_contents,parse_ini_file,simplexml_load_file,filemtime,filesize,',
		'_getFileContent'						=> ',require_once,include_once,require,include,readfile,file_get_contents,parse_ini_file,simplexml_load_file,md5_file,',
		'_includeFile'							=> ',require_once,include_once,require,include,',
		'_isProtocolPath'						=> ',readfile,file_get_contents,simplexml_load_file,',
	);
	protected $filesPhpDependencies = array();
	protected $filesPhpOrder = array();
	protected $wrapperCode = '';
	protected $result = '';
	protected $resultFilesInfo = '';
	protected $resultFilesContents = '';
	protected $unsafeOrderDetection = array();

	public function __construct ($cfg = array()) {
		parent::__construct($cfg);
	}
	public static function SetIncludeFirstDefault (array $includeFirstDefault = array()) {
		static::$includeFirstDefault = $includeFirstDefault;
	}
	public static function SetIncludeLastDefault (array $includeLastDefault = array()) {
		static::$includeLastDefault = $includeLastDefault;
	}
	public static function SetExcludePatternsDefault (array $excludePatternsDefault = array()) {
		static::$excludePatternsDefault = $excludePatternsDefault;
	}
	public function Run ($cfg = array()) {
		parent::Run($cfg);
		$this->_checkAndSetUpCompletePhpConfiguration();
		$this->_prepareScriptsReplacer();
		$this->files = (object) array(
			'all'		=> array(),
			'php'		=> array(),
			'static'	=> array(),
		);
		return $this;
	}
	private function _checkAndSetUpCompletePhpConfiguration () {
		foreach ($this->cfg->includeFirst as $key => $relPath) {
			$this->cfg->includeFirst[$key] = $this->cfg->sourcesDir . $relPath;
		}
		foreach (static::$includeFirstDefault as $relPath) {
			$absPath = $this->cfg->sourcesDir . $relPath;
			if (!in_array($absPath, $this->cfg->includeFirst)) {
				array_unshift($this->cfg->includeFirst, $absPath);
			}
		}
		foreach ($this->cfg->includeLast as $key => $relPath) {
			$this->cfg->includeLast[$key] = $this->cfg->sourcesDir . $relPath;
		}
		foreach (static::$includeLastDefault as $relPath) {
			$absPath = $this->cfg->sourcesDir . $relPath;
			if (!in_array($absPath, $this->cfg->includeLast)) {
				$this->cfg->includeLast[] = $absPath;
			}
		}
		foreach (static::$excludePatternsDefault as $pattern) {
			if (!in_array($pattern, $this->cfg->excludePatterns)) {
				$this->cfg->excludePatterns[] = $pattern;
			}
		}
		if (!isset($this->cfg->errorReportingLevel)) {
			$this->cfg->errorReportingLevel = static::$errorReportingLevelDefault;
		}
	}
	private function _prepareScriptsReplacer () {
		$phpFunctionsToProcess = array();
		if ($this->cfg->phpFsMode != Packager_Php::FS_MODE_STRICT_HDD) {
			$defaultCollection = array_merge(
				array('require_once', 'include_once', 'require', 'include'),
				array_keys(self::$wrapperReplacements[T_STRING])
			);
			foreach ($defaultCollection as $phpFunctionName) {
				$phpFunctionsToProcess[$phpFunctionName] = 1;
			}
		}

		$itemsToReplace = $this->cfg->phpFunctionsToReplace;
		$itemsToKeep = $this->cfg->phpFunctionsToKeep;
		foreach ($itemsToReplace as $item) {
			if (!isset($phpFunctionsToProcess[$item])) $phpFunctionsToProcess[$item] = 1;
		}
		foreach ($itemsToKeep as $item) {
			if ($item == 'include_once' || $item == 'require_once') continue;
			if (isset($phpFunctionsToProcess[$item])) unset($phpFunctionsToProcess[$item]);
		}
		$this->cfg->phpFunctionsToProcess = $phpFunctionsToProcess;
		unset($this->cfg->phpFunctionsToReplace, $this->cfg->phpFunctionsToKeep);
		
		self::$wrapperReplacements[T_STRING] = (object) self::$wrapperReplacements[T_STRING];

		static::$wrapperReplacements[T_DIR] = function (& $fileInfo) {
			$relPathDir = $fileInfo->relPathDir;
			return "str_replace('\\\','/',__DIR__).'$relPathDir'";
		};
		static::$wrapperReplacements[T_FILE] = function (& $fileInfo) {
			$relPath = $fileInfo->relPath;
			return "str_replace('\\\','/',__DIR__).'$relPath'";
		};
		
		Packager_Php_Scripts_Replacer::SetPhpFunctionsToProcess($this->cfg->phpFunctionsToProcess);
		Packager_Php_Scripts_Replacer::SetWrapperReplacements(static::$wrapperReplacements);
		Packager_Php_Scripts_Replacer::SetWrapperClassName(static::$wrapperClassName);
		Packager_Php_Scripts_Replacer::SetPhpFsMode($this->cfg->phpFsMode);
	}
}