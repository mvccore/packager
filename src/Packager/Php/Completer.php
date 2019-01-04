<?php

include_once(__DIR__.'/Scripts/Dependencies.php');

class Packager_Php_Completer extends Packager_Php_Scripts_Dependencies
{
	private static $_universalStoringType = 'base64';
	private static $_extensionsAndStoringTypes = [];
	private static $_fileTypesForWhiteSpaceTrim = [
		'css','htc','js','txt','svg','ini','htm','html','phtml','xml',
	];
	protected function autoloadJob ($params = []) {
		$this->completePhpFilesDependenciesByAutoloadDeclaration($params['file']);
	}
	protected function mainJob ($params = []) {
		// complete $this->files as usual
		$this->completeAllFiles();
		// complete dependencies array by include_once(), require_once() and auto loading
		$this->completePhpFilesDependencies();
		// complete order for php files declaration by completed data
		$this->completePhpFilesOrder();
		// complete $this->files->php array and $this->files->static array, unset $this->files->all
		$this->_completePhpAndStaticFiles();
		// process php code file system functions replacements
		$this->processPhpCode();
		// complete files records and php code together
		$this->_completeResult();
		// save result php file and display notification
		$this->_saveResult();
		$this->notify();
	}
	private function _completePhpAndStaticFiles () {
		foreach ($this->filesPhpOrder as $fullPath) {
			if (isset($this->files->all[$fullPath])) {
				$fileInfo = $this->files->all[$fullPath];
				$this->files->php[$fullPath] = $fileInfo;
				unset($this->files->all[$fullPath]);
			}
		}
		if ($this->cfg->phpFsMode == Packager_Php::FS_MODE_STRICT_HDD) {
			$this->files->static = [];
		} else {
			$fullPaths = array_keys($this->files->all);
			for ($i = 0, $l = count($fullPaths); $i < $l; $i += 1) {
				$fullPath = $fullPaths[$i];
				$this->files->static[$fullPath] = $this->files->all[$fullPath];
				unset($this->files->all[$fullPath]);
			}
		}
		// frees memory
		$this->files->all = [];
		$this->filesPhpOrder = [];
	}
	private function _completeResult () {
		self::_setUpExtensionsAndStoringTypes();
		$this->_completeResultPhpCodeAndScriptFilesRecords();
		if ($this->cfg->phpFsMode != Packager_Php::FS_MODE_STRICT_HDD) {
			$this->_completeResultStaticFilesRecords();
		}
		$this->completeWrapperCode();
		$this->_completeWholeResult();
	}
	private static function _setUpExtensionsAndStoringTypes () {
		foreach (self::$fileTypesStoringTypes as $storingType => $fileTypes) {
			foreach ($fileTypes as $fileType) {
				self::$_extensionsAndStoringTypes[$fileType] = $storingType;
			}
		}
	}
	private static function _getStoringTypeByExtension ($extension) {
		$extension = strtolower($extension);
		$storingType = self::$_universalStoringType;
		if (isset(self::$_extensionsAndStoringTypes[$extension])) {
			$storingType = self::$_extensionsAndStoringTypes[$extension];
		}
		return $storingType;
	}
	private function _completeResultPhpCodeAndScriptFilesRecords () {
		$fullPaths = array_keys($this->files->php);
		$linesCounter = 0;
		$this->globalNamespaceOpened = $this->anyPhpContainsNamespace;
		for ($i = 0, $l = count($fullPaths); $i < $l; $i += 1) {

			$fullPath = $fullPaths[$i];
			$fileInfo = $this->files->php[$fullPath];

			$relPath = $fileInfo->relPath;
			$filemtime = $fileInfo->filemtime;
			$filesize = $fileInfo->filesize;
			$linesCount = substr_count($fileInfo->content, "\n") + 1;

			$openNamespaceGlue = '';
			$closeNamespaceGlue = '';
			if ($this->anyPhpContainsNamespace) {
				if (
					$fileInfo->containsNamespace === Packager_Php::NAMESPACE_NONE &&
					!$this->globalNamespaceOpened
				) {
					// if current file doesn't have any namespace and previous 
					// file closed global namespace - open global namespace again
					if ($this->cfg->minifyPhp) {					
						$openNamespaceGlue = 'namespace{';
					} else {
						$openNamespaceGlue = "namespace{\n";
						$linesCount += 1;
					}
					$this->globalNamespaceOpened = TRUE;
				} else if (
					$fileInfo->containsNamespace !== Packager_Php::NAMESPACE_NONE &&
					$this->globalNamespaceOpened
				) {
					// if current file have namespace - close previous global namespace
					if ($this->cfg->minifyPhp) {
						$closeNamespaceGlue = '}';
					} else {
						$closeNamespaceGlue = "}\n";
						$linesCount += 1;
					}
					$this->globalNamespaceOpened = FALSE;
				}
			}

			$newLineGlue = ($this->result == '') ? '' : "\n";
			
			$this->result .= $newLineGlue . $closeNamespaceGlue . $openNamespaceGlue . $fileInfo->content;
			
			/**
			 * DEVEL NOTE:
			 * Why to store any info about php scripts?
			 * - Because in previous versions MvcCore needs to know if controller 
			 * - class exists by file_exists() to dispatch the controller.
			 * - But not any more, maybe we can remove it.
			 * - but it is still good to have info about php scripts themselfs
			 */
			$this->resultFilesInfo .= "\n" . "'$relPath'=>array('index'=>-1,'mtime'=>$filemtime,'size'=>$filesize,'lines'=>array($linesCounter,$linesCount)),";

			$this->files->php[$fullPath] = $relPath;

			$linesCounter += $linesCount;
		}
		if ($this->anyPhpContainsNamespace && $this->globalNamespaceOpened) {
			$this->result .= (!$this->cfg->minifyPhp ? "\n}" : '}');;
		}
		// frees memory - store only relative paths for result notification
		$this->files->php = array_values($this->files->php);
	}
	private function _completeResultStaticFilesRecords () {
		$fullPaths = array_keys($this->files->static);
		for ($i = 0, $l = count($fullPaths); $i < $l; $i += 1) {
			
			$fullPath = $fullPaths[$i];
			$fileInfo = $this->files->static[$fullPath];

			$relPath = $fileInfo->relPath;
			$filemtime = $fileInfo->filemtime;
			$filesize = $fileInfo->filesize;
			$filesize = strlen($fileInfo->content);
			
			$storingType = self::_getStoringTypeByExtension($fileInfo->extension);
			$this->_processStaticFileContent($fileInfo, $storingType);
			
			$glue = ($this->resultFilesInfo == '') ? '' : "\n";
			$this->resultFilesInfo .= $glue . "'$relPath'=>['index'=>$i,'mtime'=>$filemtime,'size'=>$filesize,'store'=>'$storingType'],";
			
			$glue = ($this->resultFilesContents == '') ? '' : "\n";
			$this->resultFilesContents .= 
				$glue . /*'/*'.$relPath.'* /'."\n".*/ self::$wrapperClassName . "::\$Contents[$i]=" 
				. $this->_packStaticFileContent($fileInfo, $storingType);
			
			$this->files->static[$fullPath] = $relPath . ($fileInfo->utf8bomRemoved ? ' (UTF8 BOM REMOVED)' : '');
		}
		// frees memory - store only relative paths for result notification
		$this->files->static = array_values($this->files->static);
	}
	private function _processStaticFileContent (& $fileInfo, $storingType) {
		if (in_array($fileInfo->extension, self::$_fileTypesForWhiteSpaceTrim, TRUE)) {
			$fileInfo->content = trim($fileInfo->content);
		}
		if (in_array($fileInfo->extension, static::$templatesExtensions, TRUE)) {
			// process pattern and string replacements by config
			$this->processPatternAndStringReplacements($fileInfo);
			// process php code and wrap configured functions
			$fileInfo->content = Packager_Php_Scripts_Replacer::ProcessReplacements($fileInfo, $this->cfg);

			if ($this->cfg->minifyPhp) {
				$fileInfo->content = $this->shrinkPhpCode($fileInfo->content);
			}
			if ($this->cfg->minifyTemplates) {
				//include_once(__DIR__.'/../Libs/Minify/HTML.php');
				@include_once('vendor/autoload.php');
				$fileInfo->content = Minify_HTML::minify($fileInfo->content);
			}
		}
	}
	private function _completeWholeResult () {
		// insert files info into wrapper code
		$this->wrapperCode = str_replace(
			"'____" . self::$wrapperClassName . "::\$Info____'", 
			"/*____" . self::$wrapperClassName . "::\$Info____*/" . $this->resultFilesInfo . "\n", 
			$this->wrapperCode
		);

		$baseCode = '<'.'?php'
			. ($this->anyPhpContainsNamespace ? "\nnamespace{" : "")
			. "\n" . 'error_reporting('.$this->cfg->errorReportingLevel.');'
			. "\n" . $this->wrapperCode 
			. "\n" . $this->resultFilesContents 
			. "\n";
		$baseCodeLinesCount = substr_count($baseCode, "\n") + 1;

		$baseCode = str_replace(
			"'____" . self::$wrapperClassName . "::\$_baseLinesCount____'", 
			$baseCodeLinesCount, 
			$baseCode
		);

		$this->result = $baseCode . $this->result;

		// frees memory
		unset($baseCode);
		$this->resultFilesInfo = '';
		$this->resultFilesContents = '';
	}
	private function _packStaticFileContent (& $fileInfo, $storingType) {
		if ($storingType == 'gzip') {
			$gzipStr = gzencode($fileInfo->content, 6);
			return "<<<'" . self::$wrapperStringDeclarator . "GZIP'"
				. "\n" . $gzipStr
				. "\n" . self::$wrapperStringDeclarator . 'GZIP;';
		} else if ($storingType == 'binary') {
			return "<<<'" . self::$wrapperStringDeclarator . "BIN'"
				. "\n" . $fileInfo->content 
				. "\n" . self::$wrapperStringDeclarator . 'BIN;';
		} else if ($storingType == 'base64') {
			return "'".base64_encode($fileInfo->content)."';";
		} else if ($storingType == 'template') {
			return "function(){ ?>"
				. "\n" . $fileInfo->content
				. "\n" . '<?php return 1;};';
		} else if ($storingType == 'text') {
			$fileInfo->content = str_replace("\r\n", "\n", $fileInfo->content);
			return "<<<'" . self::$wrapperStringDeclarator . "TEXT'"
				. "\n" . $fileInfo->content
				. "\n" . self::$wrapperStringDeclarator . 'TEXT;';
		}
	}
	private function _saveResult () {
		$releaseFilePhp = $this->cfg->releaseFile;
		$releaseFilePhar = mb_substr($releaseFilePhp, 0, -4) . '.phar';
		clearstatcache(TRUE, $releaseFilePhp);
		clearstatcache(TRUE, $releaseFilePhar);
		if (file_exists($releaseFilePhp)) unlink($releaseFilePhp);
		if (file_exists($releaseFilePhar))  unlink($releaseFilePhar);
		file_put_contents($releaseFilePhp, $this->result);
	}
	protected function notify ($title = 'Successfully packed') {
		$scriptsCount = count($this->files->php);
		$staticsCount = count($this->files->static);
		$totalCount = $scriptsCount + $staticsCount;
		if (php_sapi_name() == 'cli') {
			$content = "Total included files: $totalCount\n\n"
				. "\nIncluded PHP files ($scriptsCount):\n\n"
				. implode("\n", $this->files->php)
				. "\n\n\nIncluded static files ($staticsCount):\n\n"
				. implode("\n", $this->files->static);
			if (count($this->unsafeOrderDetection)) {
				$content .= "\n\n\nDeclaration order for files below was not detected certainly\n"
				. "If there will occur any exceptions by running result, complete order for these files manually.\n\n"
				. implode("\n", $this->unsafeOrderDetection);
			}
			$content .= "\n\n\nDONE";
			$this->sendResult(
				$title, 
				$content
			);
		} else {
			$content = "<div>Total included files: $totalCount</div>"
				. "<h2>Included PHP files ($scriptsCount):</h2>"
				. '<div class="files">'
					. implode('<br />', $this->files->php)
				. "</div>"
				. "<h2>Included static files ($staticsCount):</h2>"
				. '<div class="files">'
					. implode('<br />', $this->files->static)
				. "</div>";
			if (count($this->unsafeOrderDetection)) {
				$content .= "<h2>Declaration order for files below was not detected certainly</h2>"
				. "<p>If there will occur any exceptions by running result, complete order for these files manually.</p>"
				. '<div class="files">'
					. implode('<br />', $this->unsafeOrderDetection)
				. "</div>";
			}
			$content .= '</div><h2>DONE</h2>';
			$this->sendResult(
				$title, 
				$content,
				'success'
			);
		}
	}
}
