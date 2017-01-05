<?php

include_once(__DIR__.'/Scripts/Dependencies.php');

class Packager_Php_Completer extends Packager_Php_Scripts_Dependencies
{
    private static $_universalStoringType = 'base64';
	private static $_extensionsAndStoringTypes = array();
	private static $_fileTypesForWhiteSpaceTrim = array(
		'css','htc','js','txt','svg','ini','htm','html','phtml','xml',
	);
	protected function autoloadJob ($params = array()) {
		$this->completePhpFilesDependenciesByAutoloadDeclaration($params['file']);
	}
	protected function mainJob ($params = array()) {
		// complete $this->files as ussual
		$this->completeAllFiles();
		// complete dependencies array by include_once(), require_once() and autoloading
		$this->completePhpFilesDependencies();
		// complete order for php files declaration by completed data
		$this->completePhpFilesOrder();
		// complete $this->files->php array and $this->files->static array, unset $this->files->all
		$this->_completePhpAndStaticFiles();
		// process php code filesystem functions replacements
		$this->processPhpCode();
		// complete files records and php code together
		$this->_completeResult();
		// save result php file and display notification
		$this->_saveResult();
		$this->_notify();
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
			$this->files->static = array();
		} else {
			$fullPaths = array_keys($this->files->all);
			for ($i = 0, $l = count($fullPaths); $i < $l; $i += 1) {
				$fullPath = $fullPaths[$i];
				$this->files->static[$fullPath] = $this->files->all[$fullPath];
				unset($this->files->all[$fullPath]);
			}
		}
		// frees memory
		$this->files->all = array();
		$this->filesPhpOrder = array();
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
		for ($i = 0, $l = count($fullPaths); $i < $l; $i += 1) {

			$fullPath = $fullPaths[$i];
			$fileInfo = $this->files->php[$fullPath];

			$relPath = $fileInfo->relPath;
			$filemtime = $fileInfo->filemtime;
			$filesize = $fileInfo->filesize;
			$linesCount = substr_count($fileInfo->content, "\n") + 1;

			$glue = ($this->result == '') ? '' : "\n";
			$this->result .= $glue . $fileInfo->content;
			
			// Why to store any info about php scripts?
			// BECAUSE MvcCore NEEDS TO KNOW IF CONTROLLER CLASS EXISTS TO DISPATCH THE CONTROLLER
			$this->resultFilesInfo .= "\n" . "'$relPath'=>array('index'=>-1,'mtime'=>$filemtime,'size'=>$filesize,'lines'=>array($linesCounter,$linesCount)),";

			$this->files->php[$fullPath] = $relPath;

			$linesCounter += $linesCount;
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
			$this->resultFilesInfo .= $glue . "'$relPath'=>array('index'=>$i,'mtime'=>$filemtime,'size'=>$filesize,'store'=>'$storingType'),";
			
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
		if (in_array($fileInfo->extension, self::$_fileTypesForWhiteSpaceTrim)) {
			$fileInfo->content = trim($fileInfo->content);
		}
		if (in_array($fileInfo->extension, static::$templatesExtensions)) {
			// process pattern and string replacements by config
			$this->processPatternAndStringReplacements($fileInfo);
			// process php code and wrap configured functions
			$fileInfo->content = Packager_Php_Scripts_Replacer::Process($fileInfo);

			if ($this->cfg->minifyPhp) {
				$fileInfo->content = self::shrinkPhpCode($fileInfo->content);
			}
			if ($this->cfg->minifyTemplates) {
				$fileInfo->content = Minify_HTML::minify($fileInfo->content);
			}
		}
	}
	private function _completeWholeResult () {
		// insert files info into wrapper code
		$this->wrapperCode = str_replace(
			"'____" . self::$wrapperClassName . "::\$Info____'", 
			$this->resultFilesInfo . "\n", 
			$this->wrapperCode
		);

		$baseCode = '<'.'?php' 
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
		$releaseFile = $this->cfg->releaseFile;
		unlink($releaseFile);
		file_put_contents($releaseFile, $this->result);
	}
	private function _notify () {
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
				. "If there will occur any exceptions by running result, complete order for these files manualy.\n\n"
				. implode("\n", $this->unsafeOrderDetection);
			}
			$content .= "\n\n\nDONE";
			$this->sendResult(
				'Successfully packed', 
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
				. "<p>If there will occur any exceptions by running result, complete order for these files manualy.</p>"
				. '<div class="files">'
					. implode('<br />', $this->unsafeOrderDetection)
				. "</div>";
			}
			$content .= '</div><h2>DONE</h2>';
			$this->sendResult(
				'Successfully packed', 
				$content,
				'success'
			);
		}
	}
}