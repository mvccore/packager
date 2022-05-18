<?php

include_once(__DIR__.'/../Common/StaticCopies.php');

class Packager_Phar_ResultCompleter extends Packager_Common_StaticCopies
{
	private static $_pharNotAllowedMsg = [
		"It is not allowed to create PHAR archive on your computer.",
		"Go to 'php.ini' and allow PHAR archive creation by set up 'phar.readonly = 0'."
	];
	private $_jsonResult;
	protected function mainJob ($params = []) {
		// clean all files in release directory
		$this->cleanReleaseDir();
		// statically copy files and folders
		$this->copyStaticFilesAndFolders();
		// complete result file
		$firstJobResult = $this->executeJobAndGetResult(
			'completingJob', []
		);
		if ($firstJobResult instanceof stdClass && $firstJobResult->success) {
			$pharAbsPath = $firstJobResult->data->phar;
			$phpAbsPath = $firstJobResult->data->php;
			$secondJobResult = $this->executeJobAndGetResult(
				'renameJob', 
				[
					'phar'	=> $pharAbsPath,
					'php'	=> $phpAbsPath,
				],
				'json'
			);
			if ($secondJobResult instanceof stdClass && $secondJobResult->success) {
				// notify about success
				$this->notify($firstJobResult->data->incl);
			} else {
				if ($secondJobResult->type == 'json') {
					$this->sendResult(
						$secondJobResult->data[0], $secondJobResult->data[1], 'error'
					);
				} else {
					$this->sendResult(
						$secondJobResult->message, $secondJobResult->data, 'error'
					);
				}
			}
		} else {
			if ($firstJobResult->type == 'json') {
				$this->sendResult(
					$firstJobResult->data[0], $firstJobResult->data[1], 'error'
				);
			} else {
				$this->sendResult(
					$firstJobResult->message, $firstJobResult->data, 'error'
				);
			}
		}
	}
	protected function completingJob ($params = []) {
		$this->_jsonResult = (object) [
			'success'	=> TRUE,
			'data'		=> [],
		];
		$this->completeAllFiles();
		$this->_processPhpCode();
		list($releaseDir, $releaseFileNameWithoutExt) = $this->_completeBuildPaths();
		$this->_buildPharArchive($releaseDir, $releaseFileNameWithoutExt);
		$this->sendJsonResultAndExit($this->_jsonResult);
	}
	protected function renameJob ($params = []) {
		$result = (object) [
			'success'	=> TRUE,
			'data'		=> [],
		];
		try {
			clearstatcache(TRUE, $params['phar']);
			clearstatcache(TRUE, $params['php']);
			rename($params['phar'],  $params['php']);
		} catch (Exception $e) {
			$result->success = FALSE;
			$result->data = [
				$e->getMessage(),
				$e->getTrace(),
			];
		}
		$this->sendJsonResultAndExit($result);
	}
	private function _processPhpCode () {
		foreach ($this->files as $fullPath => $fileInfo) {
			if ($fileInfo->extension != 'php' && !in_array($fileInfo->extension, static::$templatesExtensions, TRUE)) continue;
			if ($this->cfg->patternReplacements) {
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
			}
			if ($this->cfg->stringReplacements) {
				foreach ($this->cfg->stringReplacements as $from => $to) {
					$fileInfo->content = str_replace($from, $to, $fileInfo->content);
				}
			}
			if ($this->cfg->minifyPhp) {
				$fileInfo->content = $this->shrinkPhpCode($fileInfo->content);
			}
			if ($this->cfg->minifyTemplates && in_array($fileInfo->extension, static::$templatesExtensions, TRUE)) {
				//include_once(__DIR__.'/../Libs/Minify/HTML.php');
				@include_once('vendor/autoload.php');
				$fileInfo->content = Minify_HTML::minify($fileInfo->content);
			}
			$this->files[$fullPath] = $fileInfo;
		}
	}
	private function _completeBuildPaths () {
		$releaseDir = $this->cfg->releaseDir;
		$releaseFileName = $this->cfg->releaseFileName;
		
		$releaseFileNameExpl = explode('.', $releaseFileName);
		unset($releaseFileNameExpl[count($releaseFileNameExpl) - 1]);
		$releaseFileNameWithoutExt = ltrim(implode('.', $releaseFileNameExpl), '/');
		
		$releaseFilePhp = $releaseDir . '/' . $releaseFileNameWithoutExt . '.php';
		$releaseFilePhar = $releaseDir . '/' . $releaseFileNameWithoutExt . '.phar';
		clearstatcache(TRUE, $releaseFilePhp);
		clearstatcache(TRUE, $releaseFilePhar);
		if (file_exists($releaseFilePhp)) unlink($releaseFilePhp);
		if (file_exists($releaseFilePhar))  unlink($releaseFilePhar);

		return [
			$releaseDir, $releaseFileNameWithoutExt
		];
	}
	private function _buildPharArchive ($releaseDir, $releaseFileNameWithoutExt) {
		$archive = NULL;
		$pharFullPath = $this->virtualRealPath($releaseDir . '/' . $releaseFileNameWithoutExt . '.phar');
		$phpFullPath = $this->virtualRealPath($releaseDir . '/' . $releaseFileNameWithoutExt . '.php');
		try {
			$archive = new Phar(
				$pharFullPath, 
				0, 
				$releaseFileNameWithoutExt . '.phar'
			);
			
			$incScripts = [];
			$incStatics = [];
			$archive->startBuffering();
			foreach ($this->files as $fileInfo) {
				$archive[$fileInfo->relPath] = $fileInfo->content;
				if ($fileInfo->extension == 'php') {
					$incScripts[] = $fileInfo->relPath;
				} else {
					$incStatics[] = $fileInfo->relPath;
				}
			}
			$archive->setStub('<'.'?php '
				.PHP_EOL."Phar::mapPhar();"
				.'include_once("phar://' . $releaseFileNameWithoutExt . '.phar/index.php");'
				.'__HALT_COMPILER();');

			$archive->stopBuffering();
		
			unset($archive); // frees memory, run rename operation without any conflict
			
			// wait for phar to be written on HDD:
			$i = 0;
			$fsc = 0;
			$lastFs = 0;
			while ($i < 50) {
				clearstatcache(true, $pharFullPath);
				$fs = @filesize($pharFullPath);
				if ($fs !== false && $fs > 0) {
					if ($fs === $lastFs) {
						if ($fsc > 10) {
							break;
						} else {
							$fsc++;
						}
					} else {
						$lastFs = $fs;
					}
				}
				usleep(50000);
				$i++;
			}

			$this->_jsonResult->data = [
				'phar'		=> $pharFullPath, 
				'php'		=> $phpFullPath,
				'incl'		=> [
					'scripts'	=> $incScripts,
					'statics'	=> $incStatics,
				],
			];

		} catch (UnexpectedValueException $e1) {
			$m = $e1->getMessage();
			if (mb_strpos($m, 'disabled by the php.ini setting phar.readonly') !== FALSE) {
				$this->_jsonResult->success = FALSE;
				$phpIniLoadedFile = str_replace('\\', '/', php_ini_loaded_file());
				$this->_jsonResult->data = [
					self::$_pharNotAllowedMsg[0],
					str_replace('php.ini', $phpIniLoadedFile, self::$_pharNotAllowedMsg[1]),
				];
			} else {
				$this->_jsonResult->success = FALSE;
				$this->_jsonResult->data = [
					$e1->getMessage(),
					$e1->getTrace(),
				];
			}
		} catch (Throwable $e2) {
			$this->_jsonResult->success = FALSE;
			$this->_jsonResult->data = [
				$e2->getMessage(),
				$e2->getTrace(),
			];
		}
	}
	protected function virtualRealPath ($path) {
		$path = explode('/', str_replace('\\', '/', $path));
		$stack = [];
		foreach ($path as $seg) {
			if ($seg == '..') {
				// Ignore this segment, remove last segment from stack
				array_pop($stack);
				continue;
			}
			if ($seg == '.') 
				// Ignore this segment
				continue;
			$stack[] = $seg;
		}
		return implode('/', $stack);
	}
	protected function notify ($incFiles) {
		$scriptsCount = count($incFiles->scripts);
		$staticsCount = count($incFiles->statics);
		$staticallyCopiedCount = count($this->staticallyCopiedFiles);
		$totalCount = $scriptsCount + $staticsCount;
		if (php_sapi_name() == 'cli') {
			$content = "Total included PHP and static files in package: $totalCount\n"
				. "Statically copied files into release directory: $staticallyCopiedCount\n\n"
				. "\nIncluded PHP files in package ($scriptsCount):\n\n"
				. implode("\n", $incFiles->scripts)
				. "\n\n\nIncluded static files in package ($staticsCount):\n\n"
				. implode("\n", $incFiles->statics)
				. "\n\n\nStatically copied files into release directory ($staticallyCopiedCount):\n\n"
				. implode("\n", $this->staticallyCopiedFiles)
				. "\n\n\nDONE";
			$this->sendResult('Successfully packed', $content);
		} else {
			$content = "<div>Total included PHP and static files in package: $totalCount</div>"
				. "<div>Statically copied files into release directory: $staticallyCopiedCount</div>"
				. "<h2>Included PHP files in package ($scriptsCount):</h2>"
				. '<div class="files">'
					. implode('<br />', $incFiles->scripts)
				. "</div>"
				. "<h2>Included static files in package ($staticsCount):</h2>"
				. '<div class="files">'
					. implode('<br />', $incFiles->statics)
				. "</div>"
				. "<h2>Statically copied files into release directory ($staticallyCopiedCount):</h2>"
				. '<div class="files">'
					. implode('<br />', $this->staticallyCopiedFiles)
				. "</div>"
				. "<h2>DONE</h2>";
			$this->sendResult('Successfully packed', $content, 'success');
		}
	}
}
