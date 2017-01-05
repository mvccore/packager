<?php

include_once(__DIR__.'/Order.php');

class Packager_Php_Scripts_Dependencies extends Packager_Php_Scripts_Order
{
	private static $_includePaths = array(
		'',
		'/App', 
		'/Libs',
	);
	private $_autoLoadedFiles = array();
	public function AutoloadCall ($className) {
		$fileName = str_replace(array('_', '\\'), '/', $className) . '.php';
		$includePath = '';
		foreach (self::$_includePaths as $path) {
			$fullPath = $this->cfg->sourcesDir . $path . '/' . $fileName;
			if (file_exists($fullPath)) {
				$includePath = $fullPath;
				break;
			}
		}
		if ($includePath) $this->_autoLoadedFiles[] = $includePath;
		// $this->_autoLoadedFiles[] = array($fileName, $className, $includePath, $includePaths, '/');
		if ($includePath) {
			include_once($includePath);
		} else {
			$status = 0;
			$backTraceLog = debug_backtrace();
			foreach ($backTraceLog as $backTraceInfo) {
				if ($status === 0 && $backTraceInfo['function'] == 'spl_autoload_call') {
					$status = 1;
				} else if ($status == 1 && $backTraceInfo['function'] == 'class_exists') {
					$status = 2;
					break;
				} else if ($status > 0) {
					break;
				}
			}
			if ($status < 2) {
				$this->exceptionsMessages[] = 'Class "' . $className . '" not found by class_exists() method.';
			}
		}
	}
    protected function completePhpFilesDependencies () {
		// complete dependencies - requires
		foreach ($this->files->all as $fullPath => & $fileInfo) {
			if ($fileInfo->extension == 'php') {
				$this->filesPhpDependencies[$fullPath] = $this->_completeRequireRecords($fullPath, $fileInfo);
			}
		}
		// complete dependencies - requiredBy
		$this->_completeRequiredByRecords();
	}
	private function _completeRequireRecords ($fullPath, & $fileInfo) {
		// capture all dependent files defined by require, require_once, include, include_once as relative paths
		$byRequiresAndIncludes = $this->_completeDependenciesByRequiresAndIncludes($fullPath, $fileInfo);
		// try to load file and try to capture what was necessary to autoload
		$autoloadedByDeclaration = $this->_completePhpFileDependenciesByAutoloadDeclaration($fileInfo);
		// if there is no record about autoloaded file and it is not foreing file - add autoloaded file at the end
		foreach ($autoloadedByDeclaration as $autoLoadItem) {
			if (isset($this->files->all[$autoLoadItem]) && !in_array($autoLoadItem, $byRequiresAndIncludes)) {
				$byRequiresAndIncludes[] = $autoLoadItem;
			}
		}
		return (object) array(
			'requiredBy'	=> array(),
			'requires'		=> $byRequiresAndIncludes,
		);
	}
	private function _completeRequiredByRecords () {
		foreach ($this->filesPhpDependencies as $fullPath => & $requirements) {
			$requirements->requiredBy = $this->_completeRequiredByRecordsRecursive(
				$fullPath, $requirements->requiredBy
			);
		}
		foreach ($this->filesPhpDependencies as $fullPath => & $requirements) {
			$requirements->requiredByCount = count($requirements->requiredBy);
			$requirements->requiresCount = count($requirements->requires);
		}
	}
	private function _completeRequiredByRecordsRecursive ($searchedFullPath, & $searchedRequirementsRequiredBy) {
		foreach ($this->filesPhpDependencies as $fullPath => $requirements) {
			if (in_array($searchedFullPath, $requirements->requires)) {
				if (!in_array($fullPath, $searchedRequirementsRequiredBy)) {
					$searchedRequirementsRequiredBy[] = $fullPath;
					$requiredByLocal = $this->_completeRequiredByRecordsRecursive(
						$searchedFullPath, $searchedRequirementsRequiredBy
					);
					foreach ($requiredByLocal as $requiredByLocalItem) {
						if (!in_array($requiredByLocalItem, $searchedRequirementsRequiredBy)) {
							$searchedRequirementsRequiredBy[] = $requiredByLocalItem;
						}
					}
				}
			}
		}
		return $searchedRequirementsRequiredBy;
	}
	private function _completeDependenciesByRequiresAndIncludes ($fullPath, & $fileInfo) {
		$capturedItems = $this->_completeDependenciesByFileContentCapture(
			$fileInfo
		);
		$capturedItems = $this->_completeDependenciesByReqsAndInclsReplaceConstsAndEval(
			$fileInfo, $capturedItems
		);
		$this->_removeProperlyCapturedReqsAndInclsFromPhpFilesContents(
			$fileInfo, $capturedItems
		);
		$dependentFilesByRequiresAndIncludes = $this->_completeDependenciesByReqsAndInclsAbsolutizeCapturedPaths(
			$fileInfo, $capturedItems
		);

		return $dependentFilesByRequiresAndIncludes;
	}
	private function _completeDependenciesByFileContentCapture (& $fileInfo) {
		$capturedItems = array();
		$regExps = array(
			// do not read anything from require() and include(),
			// these functions are always used for dynamicly included files
			//"#([^a-zA-Z0-9_])(require)([^_a-zA-Z0-9])([^;]*);#m" => array('$1', array(3, 4)),
			//"#([^a-zA-Z0-9_])(include)([^_a-zA-Z0-9])([^;]*);#m" => array('$1', array(3, 4)),

			// read everything from require_once() and include_once(),
			// these functions are always used for fixed including to declare content classes
			"#([^a-zA-Z0-9_])(require_once)([^;]*);#m"	=> array('$1', array(3)),
			"#([^a-zA-Z0-9_])(include_once)([^;]*);#m"	=> array('$1', array(3)),
		);
		foreach ($regExps as $regExp => $backReferences) {
			$matches = array();
			$catched = preg_match_all($regExp, $fileInfo->content, $matches, PREG_OFFSET_CAPTURE);
			if ($catched > 0) {
				// xcv(array($fileInfo->fullPath, $matches));
				foreach ($matches[0] as $matchKey => $matchItem) {
					$backReferenceStr = '';
					foreach ($backReferences[1] as $backReferenceIndex) {
						$backReferenceStr .= $matches[$backReferenceIndex][$matchKey][0];
					}
					$catchedTextIndex = $matchItem[1];
					$catchedTextLength = mb_strlen($matchItem[0]);
					$capturedItems[] = array($backReferenceStr, $catchedTextIndex, $catchedTextLength);
				}
			}
		}
		usort($capturedItems, function ($a, $b) {
			if ($a[1] == $b[1]) return 0;
			return ($a[1] < $b[1]) ? -1 : 1;
		});
		return $capturedItems;
	}
	private function _completeDependenciesByReqsAndInclsReplaceConstsAndEval (& $fileInfo, & $capturedItems) {
		$fullPathLastSlash = strrpos($fileInfo->fullPath, '/');
		$fullPathDir = $fullPathLastSlash !== FALSE ? substr($fileInfo->fullPath, 0, $fullPathLastSlash) : $fileInfo->fullPath ;
		$capturedItemKeysToUnset = array();
		ob_start();
		$this->errorHandlerData = array();
		foreach ($capturedItems as $key => & $capturedItem) {
			$capturedText = trim($capturedItem[0], "\t \r\n()");
			$capturedText = str_replace(
				array('__FILE__', '__DIR__',),
				array("'".$fileInfo->fullPath."'", "'".$fullPathDir."'",),
				$capturedText
			);
			$addDependency = TRUE;
			ob_clean();
			try {
				@eval('echo '.$capturedText . ';');
			}
			catch (Exception $e) {
				$addDependency = FALSE;
			}
			if ($this->errorHandlerData) {
				// if there was any unknown variables in captured inclide_once() or require_once() content,
				// do not add any evaluated dependency, because there is not relevant eval result
				$addDependency = FALSE;
				$this->errorHandlerData = array();
			} else {
				$capturedText = ob_get_contents();
			}
			ob_clean();
			if ($addDependency && $capturedText) {
				$capturedItem[3] = $capturedText;
			} else {
				$capturedItemKeysToUnset[] = $key;
			}
		}
		foreach ($capturedItemKeysToUnset as $key) {
			unset($capturedItems[$key]);
		}
		return $capturedItems;
	}
	private function _removeProperlyCapturedReqsAndInclsFromPhpFilesContents (& $fileInfo, & $capturedItems) {
		if (count($capturedItems) > 0) {
			$newFileContent = '';
			$previousItem = array();
			foreach ($capturedItems as $key => & $capturedItem) {
				$previousItem = ($key > 0) ? $capturedItems[$key - 1] : array(0, 0, 0) ;
				$previousIndex = $previousItem[1];
				$previousLength = $previousItem[2];
				$currentIndex = $capturedItem[1];
				$currentLength = $capturedItem[2];
				$start = $previousIndex + $previousLength;
				$length = $currentIndex - $start;
				$newFileContent .= mb_substr($fileInfo->content, $start, $length);
			}
			$newFileContent .= mb_substr($fileInfo->content, $currentIndex + $currentLength);
			$fileInfo->content = $newFileContent;
		}
	}
	private function _completeDependenciesByReqsAndInclsAbsolutizeCapturedPaths (& $fileInfo, & $capturedItems) {
		$byRequiresAndIncludes = array();
		$result = array();
		foreach ($capturedItems as $key => $capturedItem) {
			$requiredOrIncluded = $capturedItem[3];
			$realPath = realpath($requiredOrIncluded);
			$fullPath = '';
			if ($realPath !== FALSE) {
				$fullPath = $realPath;
			} else {
				foreach (self::$_includePaths as $possibleAutoloadingDirectory) {
					$possibleFullPath = $this->cfg->sourcesDir . $possibleAutoloadingDirectory . '/' . ltrim($requiredOrIncluded, '/');
					$realPath = realpath($possibleFullPath);
					if ($realPath !== FALSE) {
						$fullPath = $realPath;
						break;
					}
				}
			}
			if (!$fullPath) {
				$fullPathLastSlash = strrpos($fileInfo->fullPath, '/');
				$fullPathDir = $fullPathLastSlash !== FALSE ? substr($fileInfo->fullPath, 0, $fullPathLastSlash) : $fileInfo->fullPath ;
				$possibleFullPath = $fullPathDir . '/' . ltrim($requiredOrIncluded, '/');
				$realPath = realpath($possibleFullPath);
				if ($realPath !== FALSE) {
					$fullPath = $realPath;
				}
			}
			if (!$fullPath) {
				$fullPath = $requiredOrIncluded;
			}
			$byRequiresAndIncludes[$key] = str_replace('\\', '/', $fullPath);
		}
		// remove duplicates and foreing files
		foreach ($byRequiresAndIncludes as $byRequiresAndIncludesItem) {
			if (isset($this->files->all[$byRequiresAndIncludesItem]) && !isset($result[$byRequiresAndIncludesItem])) {
				$result[$byRequiresAndIncludesItem] = 1;
			}
		}
		return array_keys($result);
	}
	private function _completePhpFileDependenciesByAutoloadDeclaration (& $fileInfo) {
		$result = array();
		$autoloadJobResult = $this->executeJobAndGetResult(
			'autoloadJob', array('file' => $fileInfo->fullPath), 'json'
		);
		if ($autoloadJobResult instanceof stdClass && $autoloadJobResult->success) {
			$result = $autoloadJobResult->includedFiles;
		} else {
			if ($autoloadJobResult->type == 'json') {
				$this->sendResult(
					implode('<br />', $autoloadJobResult->exceptionsMessages), 
					$autoloadJobResult->exceptionsTraces, 
					'error'
				);
			} else {
				$relPath = $fileInfo->relPath;
				$newLine = php_sapi_name() == 'cli' ? "\n" : "<br />";
				$this->sendResult(
					"Autoload error by including file: $newLine"
					 . "'$relPath' $newLine"
					 . "Is this file used also in your development versions? $newLine"
					 . "Or does this file generate any output by simple include() $newLine "
					 . "which breaks compiling process?", 
					$autoloadJobResult->data, 
					'error'
				);
			}
		}
		return $result;
	}
	protected function completePhpFilesDependenciesByAutoloadDeclaration ($file = '') {
		$success = TRUE;
		$content = '';
		$phpInclPath = get_include_path();
		foreach (self::$_includePaths as $path) {
			$phpInclPath .= PATH_SEPARATOR . $this->cfg->sourcesDir . '/' . $path;
		}
		set_include_path($phpInclPath);
		spl_autoload_register(array(__CLASS__, 'AutoloadCall'));
		if (!$file) {
			$success = FALSE;
			$this->exceptionsMessages[] = 'File is an empty string.';
		} else {
			// set custom error handlers to catch eval warnings and errors
			set_error_handler(array(__CLASS__, 'ErrorHandler'));
			set_exception_handler(array(__CLASS__, 'ErrorHandler'));
			ob_start();
			try {
				include($file);
			} catch (Exception $e) {
				$success = FALSE;
				$this->exceptionsMessages[] = $e->getMessage();
				$this->exceptionsTraces[] = $e->getTrace();
			}
			$content = ob_get_clean();
		}
		$this->sendJsonResultAndExit((object) array(
			'success'			=> $success,
			'includedFiles'		=> $this->_autoLoadedFiles,
			'exceptionsMessages'=> $this->exceptionsMessages,
			'exceptionsTraces'	=> $this->exceptionsTraces,
			'content'			=> $content,
		));
	}
}