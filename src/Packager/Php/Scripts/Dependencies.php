<?php

include_once(__DIR__.'/Order.php');

class Packager_Php_Scripts_Dependencies extends Packager_Php_Scripts_Order
{
	protected $includedFiles = [];
	protected $composerClassLoader = NULL;
	private static $_includePaths = [
		'',
		'/App',
		'/Libs',
	];
	public static function AutoloadCall ($className) {
		$fileName = str_replace(['_', '\\'], '/', $className) . '.php';
		$includePath = '';
		foreach (self::$_includePaths as $path) {
			$fullPath = self::$instance->cfg->sourcesDir . $path . '/' . $fileName;
			if (file_exists($fullPath)) {
				$includePath = $fullPath;
				break;
			}
		}
		if ($includePath) {
			self::$instance->includedFiles[] = self::_virtualRealPath($includePath);
			return include_once($includePath);
		} else {
			$includePath = self::$instance->composerClassLoader->findFile($className);
			if ($includePath) {
				self::$instance->includedFiles[] = self::_virtualRealPath($includePath);
				return include_once($includePath);
			}
		}
		return FALSE;
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
		if ($this->cfg->autoloadingOrderDetection) {
			// try to load file and try to capture what was necessary to autoload
			$autoloadedByDeclaration = $this->_completePhpFileDependenciesByAutoloadDeclaration($fileInfo);
			// if there is no record about autoloaded file and it is not foreing file - add autoloaded file at the end
			foreach ($autoloadedByDeclaration as $autoLoadItem) {
				if (isset($this->files->all[$autoLoadItem]) && !in_array($autoLoadItem, $byRequiresAndIncludes)) {
					$byRequiresAndIncludes[] = $autoLoadItem;
				}
			}
		}
		return (object) [
			'requiredBy'	=> [],
			'requires'		=> $byRequiresAndIncludes,
		];
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
		$capturedItems = [];
		$regExps = [
			// do not read anything from require() and include(),
			// these functions are always used for dynamicly included files
			//"#([^a-zA-Z0-9_\\/\*])(require)([^_a-zA-Z0-9])([^;]*);#m" => array('$1', array(3, 4)),
			//"#([^a-zA-Z0-9_\\/\*])(include)([^_a-zA-Z0-9])([^;]*);#m" => array('$1', array(3, 4)),

			// read everything from require_once() and include_once(),
			// these functions are always used for fixed including to declare content classes
			"#([^a-zA-Z0-9_\\/\*])(require_once)([^;]*);#m"	=> ['$1', [3]],
			"#([^a-zA-Z0-9_\\/\*])(include_once)([^;]*);#m"	=> ['$1', [3]],
		];
		foreach ($regExps as $regExp => $backReferences) {
			$matches = [];
			$catched = preg_match_all($regExp, $fileInfo->content, $matches, PREG_OFFSET_CAPTURE);
			if ($catched > 0) {
				// xcv(array($fileInfo->fullPath, $matches));
				foreach ($matches[0] as $matchKey => $matchItem) {
					$backReferenceStr = '';
					foreach ($backReferences[1] as $backReferenceIndex) {
						$backReferenceStr .= $matches[$backReferenceIndex][$matchKey][0];
					}
					$catchedTextIndex = $matchItem[1];
					// this is very very very crazy result fix from `preg_match_all()` with PREG_OFFSET_CAPTURE
					$catchedTextIndexFixMatchItem = $matches[2][0][0];
					$catchedTextIndexFixOffset = $catchedTextIndex - mb_strlen($catchedTextIndexFixMatchItem) - 4;
					if ($catchedTextIndexFixOffset > 0 && mb_strlen($fileInfo->content) > $catchedTextIndexFixOffset + mb_strlen($catchedTextIndexFixMatchItem)) {
						$catchedTextIndexFix = mb_strpos(
							$fileInfo->content, 
							$catchedTextIndexFixMatchItem,
							$catchedTextIndexFixOffset
						);
						if ($catchedTextIndexFix !== $catchedTextIndex && $catchedTextIndexFix !== FALSE) {
							$catchedTextIndex = $catchedTextIndexFix;
						}
					}
					// end of fix
					$catchedTextLength = mb_strlen($matchItem[0]);
					$capturedItems[] = [$backReferenceStr, $catchedTextIndex, $catchedTextLength];
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
		$capturedItemKeysToUnset = [];
		ob_start();
		$this->errorHandlerData = [];
		foreach ($capturedItems as $key => & $capturedItem) {
			$capturedText = trim($capturedItem[0], "\t \r\n()");
			$capturedText = str_replace(
				['__FILE__', '__DIR__',],
				["'".$fileInfo->fullPath."'", "'".$fullPathDir."'",],
				$capturedText
			);
			$addDependency = TRUE;
			ob_clean();
			try {
				@eval('echo '.$capturedText . ';');
			} catch (Exception $e) {
				$addDependency = FALSE;
			}
			if ($this->errorHandlerData) {
				// if there was any unknown variables in captured include_once() or require_once() content,
				// do not add any evaluated dependency, because there is not relevant eval result
				$addDependency = FALSE;
				$this->errorHandlerData = [];
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
			$previousItem = [];
			$currentIndex = 0;
			$currentLength = 0;
			//var_dump([$fileInfo->fullPath, $capturedItems]);
			foreach ($capturedItems as $key => & $capturedItem) {
				$previousItem = ($key > 0)
					? $capturedItems[$key - 1]
					: [0, 0, 0] ;
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
		$byRequiresAndIncludes = [];
		$result = [];
		foreach ($capturedItems as $key => $capturedItem) {
			$requiredOrIncluded = $capturedItem[3];
			$realPath = realpath($requiredOrIncluded);
			$fullPath = '';
			if ($realPath !== FALSE) {
				$fullPath = self::_virtualRealPath($requiredOrIncluded);
			} else {
				$fullPathLastSlash = strrpos($fileInfo->fullPath, '/');
				$fullPathDir = $fullPathLastSlash !== FALSE
					? substr($fileInfo->fullPath, 0, $fullPathLastSlash)
					: $fileInfo->fullPath ;
				$possibleFullPath = $fullPathDir . '/' . ltrim($requiredOrIncluded, '/');
				$realPath = realpath($possibleFullPath);
				if ($realPath !== FALSE) {
					$fullPath = self::_virtualRealPath($possibleFullPath);
				}
			}
			if (!$fullPath) {
				foreach (self::$_includePaths as $possibleAutoloadingDirectory) {
					$possibleFullPath = $this->cfg->sourcesDir . $possibleAutoloadingDirectory . '/' . ltrim($requiredOrIncluded, '/');
					$realPath = realpath($possibleFullPath);
					if ($realPath !== FALSE) {
						$fullPath = self::_virtualRealPath($possibleFullPath);
						break;
					}
				}
			}
			if (!$fullPath) {
				$fullPath = $requiredOrIncluded;
			}
			$byRequiresAndIncludes[$key] = str_replace('\\', '/', $fullPath);
		}
		// remove duplicates and foreing files
		foreach ($byRequiresAndIncludes as $key => $byRequiresAndIncludesItem) {
			if (isset($this->files->all[$byRequiresAndIncludesItem]) && !isset($result[$byRequiresAndIncludesItem])) {
				$result[$byRequiresAndIncludesItem] = 1;
			}
		}
		return array_keys($result);
	}
	private function _completePhpFileDependenciesByAutoloadDeclaration (& $fileInfo) {
		$result = [];
		$autoloadJobResult = $this->executeJobAndGetResult(
			'autoloadJob', ['file' => $fileInfo->fullPath], 'json'
		);
		//var_dump([$fileInfo->fullPath, $autoloadJobResult]);
		if ($autoloadJobResult instanceof stdClass && $autoloadJobResult->success) {
			$result = $autoloadJobResult->includedFiles;
		} else if ($fileInfo->relPath !== '/index.php') {
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
					$fileInfo->fullPath . "\r\n" . $autoloadJobResult->data,
					'error'
				);
			}
		}
		return $result;
	}
	protected function completePhpFilesDependenciesByAutoloadDeclaration ($file = '') {
		$success = TRUE;
		$content = '';
		if (!$file) {
			$success = FALSE;
			$this->exceptionsMessages[] = 'File is an empty string.';
		} else {
			// store included files count included till now to remove them later at the end
			$this->includedFiles = get_included_files();
			$this->includedFilesCountTillNow = count($this->includedFiles);
			if ($this->_prepareIncludePathsOrComposerAutoloadAndErrorHandlers($file)) {
				// proces target file include command
				try {
					include($file);
				} catch (Exception $e) {
					$success = FALSE;
					$this->exceptionsMessages[] = $e->getMessage();
					$this->exceptionsTraces[] = $e->getTrace();
				}
			} else {
				if ($this->exceptionsMessages) $success = FALSE;
			}
			$content = ob_get_clean();
			// complete included files by target file
		}
		$this->sendJsonResultAndExit((object) [
			'success'			=> $success,
			'includedFiles'		=> self::CompleteIncludedFilesByTargetFile(),
			'exceptionsMessages'=> $this->exceptionsMessages,
			'exceptionsTraces'	=> $this->exceptionsTraces,
			'content'			=> $content,
		]);
	}
	private function _prepareIncludePathsOrComposerAutoloadAndErrorHandlers ($file) {
		// try to find composer loader usualy placed in $documentRoot/vendor/autoload.php
		$scriptFileName = $_SERVER['SCRIPT_FILENAME'];
		$scriptFileName = strtoupper(mb_substr($scriptFileName, 0, 1)) . mb_substr($scriptFileName, 1);
		$lastSlash = mb_strrpos($scriptFileName, DIRECTORY_SEPARATOR);
		$documentRoot = ($lastSlash !== FALSE) ? mb_substr($scriptFileName, 0, $lastSlash) : $scriptFileName ;
		$wrongComposerAutoloadFullPath = $documentRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
		// count allready included files
		$alreadyIncludedFiles = get_included_files();
		// check if packager use include_once("vendor/autoload.php") or not
		if (in_array($wrongComposerAutoloadFullPath, $alreadyIncludedFiles)) {
			$this->exceptionsMessages = [
				"Do not use 'include_once(\"vendor/autoload.php\");' for result packing.",
				"Use direct path instead: 'include_once(\"vendor/mvccore/packager/src/Packager/Php.php\");'"
			];
			return FALSE;
		}
		$sourcesDir = trim($this->cfg->sourcesDir, '/');
		$composerAutoloadFullPath = $sourcesDir . '/vendor/autoload.php';
		$errorMsgs = [];
		$errorTraces = [];
		if (file_exists($composerAutoloadFullPath)) {
			// if project is using composer autoloader
			try {
				$this->composerClassLoader = include_once($composerAutoloadFullPath);
			} catch (Exception $e1) {
				//var_dump($e1);
				$errorMsgs = [$e1->getMessage()];
				$errorTraces = $e1->getTrace();
			} catch (Error $e2) {
				//var_dump($e2);
				$errorMsgs = [$e2->getMessage()];
				$errorTraces = $e2->getTrace();
			} finally {
				if ($errorMsgs) {
					var_dump(get_included_files());
					var_dump($errorMsgs);
				}
			}
			if ($this->_isFileIncluded($file)) {
				// file has no dependency, because it's part of composer
				// autoload or in composer autoload static includes array
				return FALSE;
			}
			spl_autoload_register([__CLASS__, 'AutoloadCall'], false, true);
		} else {
			// if composer autoload doesn't exists, MvcCore project is probably
			// developed with manualy placed files in docment root, '/App' dir or in '/Libs' dir,
			spl_autoload_register([__CLASS__, 'AutoloadCall']);
		}
		// set custom error handlers to catch eval warnings and errors
		register_shutdown_function([__CLASS__, 'ShutdownHandler']);
		set_exception_handler([__CLASS__, 'ExceptionHandler']);
		set_error_handler([__CLASS__, 'ErrorHandler']);
		$this->errorResponse = [
			'autoloadJob',
			(object) [
				'success'			=> FALSE,
				'includedFiles'		=> [],
				'exceptionsMessages'=> $errorMsgs,
				'exceptionsTraces'	=> $errorTraces,
				'content'			=> '',
			]
		];
		return TRUE;
	}
	public static function CompleteIncludedFilesByTargetFile () {
		$includedFilesCountTillNow = self::$instance->includedFilesCountTillNow;
		//$allIncludedFiles = array_slice(get_included_files(), $includedFilesCountTillNow);
		$allIncludedFiles = array_slice(self::$instance->includedFiles, $includedFilesCountTillNow);
		$autoLoadedFiles = [];
		foreach ($allIncludedFiles as $includedFileFullPath) {
			$autoLoadedFiles[] = str_replace('\\', '/', $includedFileFullPath);
		}
		return $autoLoadedFiles;
	}
	private function _isFileIncluded ($file) {
		$result = FALSE;
		$inclFiles = get_included_files();
		foreach ($inclFiles as $inclFile) {
			$inclFile = str_replace('\\', '/', $inclFile);
			if ($inclFile == $file) {
				$result = TRUE;
				break;
			}
		}
		return $result;
	}
	private static function _virtualRealPath ($path) {
		$path = str_replace('\\', '/', $path);
		$path = rtrim($path, '/');
		while (strpos($path, '//') !== FALSE)
			$path = str_replace('//', '/', $path);
		$parts = explode('/', $path);
		$absolutes = [];
		foreach ($parts as $part) {
			if (strlen($part) === 0) {
				$absolutes[] = $part;
				continue;
			}
			if ($part == '.') continue;
			if ($part == '..') {
				array_pop($absolutes);
			} else {
				$absolutes[] = $part;
			}
		}
		return implode('/', $absolutes);
	}
}
