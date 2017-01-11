<?php

include_once(__DIR__.'/../Base.php');
include_once(__DIR__.'/Replacer.php');

class Packager_Php_Scripts_Completer extends Packager_Php_Base
{
	protected function processPhpCode () {
		foreach ($this->files->php as & $fileInfo) {

			// process pattern and string replacements by config
			$this->processPatternAndStringReplacements($fileInfo);
			
			// process php code and wrap configured functions
			$fileInfo->content = Packager_Php_Scripts_Replacer::ProcessReplacements($fileInfo, $this->cfg);

			// minify if necessary
			if ($this->cfg->minifyPhp) {
				$fileInfo->content = $this->shrinkPhpCode($fileInfo->content);
			}
			$fileInfo->content = str_replace("\r\n", "\n", $fileInfo->content);

			// remove open tag - only at file begin (<\?php or <\?) 
			// and remove close tag - only at file end (?\>)
			self::_removeOpenAndClosePhpTags($fileInfo);

			if ($fileInfo->containsNamespace !== Packager_Php::NAMESPACE_NONE) {
				$this->anyPhpContainsNamespace = TRUE;
			}
		}
		if ($this->anyPhpContainsNamespace) {
			foreach ($this->files->php as & $fileInfo) {

				if ($fileInfo->containsNamespace === Packager_Php::NAMESPACE_NAMED_SEMICOLONS) {
					$fileInfo->content = Packager_Php_Scripts_Replacer::ProcessNamespaces($fileInfo, $this->cfg);
				}

			}
		}
	}
	protected function completeWrapperCode () {
		$wrapperFileName = $this->cfg->phpFsMode . '.php';
		$wrapperFullPath = __DIR__ . '/../Wrappers/' . $wrapperFileName;
		$this->wrapperCode = file_get_contents($wrapperFullPath);
		$this->wrapperCode = str_replace(
			"____" . self::$wrapperClassName . "::FS_MODE____", 
			$this->cfg->phpFsMode, 
			$this->wrapperCode
		);
		$this->wrapperCode = str_replace(
			"'____" . self::$wrapperClassName . "::\$_minifiedPhp____'", 
			$this->cfg->minifyPhp ? 'TRUE' : 'FALSE', 
			$this->wrapperCode
		);
		if ($this->cfg->phpFsMode != Packager_Php::FS_MODE_STRICT_HDD) {
			$this->_processWrapperCodeByReplacementsStatistics();
		}
		if ($this->cfg->minifyPhp) {
			$this->wrapperCode = $this->shrinkPhpCode($this->wrapperCode);
		}
		$this->wrapperCode = str_replace("\r\n", "\n", $this->wrapperCode);
		$this->wrapperCode = trim(mb_substr($this->wrapperCode, strlen('<'.'?php')));
	}
	private static function _removeOpenAndClosePhpTags (& $fileInfo) {
		$fileInfo->content = trim($fileInfo->content);
		if (mb_strpos($fileInfo->content, '<' . '?php') === 0) {
			$fileInfo->content = mb_substr($fileInfo->content, 5);
		} else if (mb_strpos($fileInfo->content, '<' . '?') === 0) {
			$fileInfo->content = mb_substr($fileInfo->content, 2);
		}
		$contentLength = mb_strlen($fileInfo->content);
		if (mb_strrpos($fileInfo->content, '?' . '>') === $contentLength - 2) {
			$fileInfo->content = mb_substr($fileInfo->content, 0, $contentLength - 2);
		}
		$fileInfo->content = trim($fileInfo->content);
	}
	protected function processPatternAndStringReplacements (& $fileInfo) {
		foreach ($this->cfg->patternReplacements as $pattern => $replacement) {
			if (is_numeric($pattern)) {
				// if there is numeric key - values is always pattern to replace with empty string
				$patternLocal = $replacement;
				while (preg_match($patternLocal, $fileInfo->content)) {
					$fileInfo->content = preg_replace($patternLocal, '', $fileInfo->content);
				}
			} else {
				while (preg_match($pattern, $fileInfo->content)) {
					$fileInfo->content = preg_replace($pattern, $replacement, $fileInfo->content);
				}
			}
		}
		foreach ($this->cfg->stringReplacements as $from => $to) {
			$fileInfo->content = str_replace($from, $to, $fileInfo->content);
		}
	}
	private function _processWrapperCodeByReplacementsStatistics () {
		self::$phpReplacementsStatistics = Packager_Php_Scripts_Replacer::GetReplacementsStatistics();
		$this->_processWrapperCodeRemovePublicElements();
		$this->_processWrapperCodeRemovePrivateElements();
	}
	private function _processWrapperCodeRemovePublicElements () {
		foreach (array('require_once', 'include_once', 'require', 'include') as $statement) {
			if (!(
				isset(self::$phpReplacementsStatistics[$statement]) && 
				self::$phpReplacementsStatistics[$statement] > 0
			)) {
				// remove php function equivalent from wrapper code
				$this->_removeWrapperPhpFunctionEquivalent($statement);
			}
		}
		// go thought all wrapper public elements and decide 
		// if there will be for each one public function or not by statistic record
		foreach (self::$wrapperReplacements[T_STRING] as $phpFunction => $wrapperEquivalent) {
			if (!(
				isset(self::$phpReplacementsStatistics[$phpFunction]) && 
				self::$phpReplacementsStatistics[$phpFunction] > 0
			)) {
				// remove php function equivalent from wrapper code
				$this->_removeWrapperPhpFunctionEquivalent($phpFunction);
			}
		}
	}
	private function _processWrapperCodeRemovePrivateElements () {
		// go thought all wrapper private elements and decide 
		// if there will necessary by dependencies to keep private element or not
		$privateElementsRemoved = array();
		foreach (self::$wrapperInternalElementsDependencies as $internalElement => $dependecies) {
			$keepPrivateElementInWrapper = FALSE;
			// go thought statistics and try to catch replaced function (with bigger statistic int than 0) in dependencies string
			foreach (self::$phpReplacementsStatistics as $phpFunction => $replacedCount) {
				if ($replacedCount > 0) {
					// if there is any php function replaced more times than 0 in target code
					if (mb_strpos($dependecies, ",$phpFunction,") !== FALSE) {
						// if there is any dependency from private wrapper code function 
						// representing original php function - keep this private code in wrapper
						$keepPrivateElementInWrapper = TRUE;
					}
				}
			}
			if (!$keepPrivateElementInWrapper) {
				$this->_removeWrapperPhpFunctionEquivalent($internalElement);
				$privateElementsRemoved[$internalElement] = 1;
			}
		}
		// check if there is still necessary to have sections: fields, Init
		// there is not necessary to call Init() function in wrapper, if there is no
		$initMethodRemoved = FALSE;
		if (isset($privateElementsRemoved['NormalizePath'])) {
			$this->_removeWrapperPhpFunctionEquivalent('Init'); // remove method declaration
			$this->_removeWrapperPhpFunctionEquivalent('Init'); // remove method call
			$initMethodRemoved = TRUE;
		}
		// there is not necessary to have there a field section, 
		// if there are no static files to include in result and 
		// if there is remoed Init() method
		if (count($this->files->static) === 0 && $initMethodRemoved) {
			$this->_removeWrapperPhpFunctionEquivalent('fields');
		}
	}
	private function _removeWrapperPhpFunctionEquivalent ($originalPhpFunctionName) {
		$commentTemplate = __CLASS__ . '::{startEnd}({functionName})';
		$startStr	= str_replace(array('{startEnd}', '{functionName}'), array('start', $originalPhpFunctionName), $commentTemplate);
		$endStr		= str_replace(array('{startEnd}', '{functionName}'), array('end', $originalPhpFunctionName), $commentTemplate);
		$startPos = mb_strpos($this->wrapperCode, $startStr);
		if ($startPos === FALSE) {
			echo "<pre>{$this->wrapperCode}</pre>";
			throw new Exception("Comment containing '$startStr' not founded in wrapper code.");
		}
		$endPos = mb_strpos($this->wrapperCode, $endStr, $startPos + mb_strlen($startStr));
		if ($endPos === FALSE) {
			echo "<pre>{$this->wrapperCode}</pre>";
			throw new Exception("Comment containing '$endStr' not founded in wrapper code.");
		}
		$this->wrapperCode = mb_substr(
			$this->wrapperCode,
			0,
			$startPos
		) . "REMOVED: " . $originalPhpFunctionName . mb_substr(
			$this->wrapperCode,
			$endPos + mb_strlen($endStr)
		);
	}
}