<?php

include_once(__DIR__.'/../Common/Base.php');

class Packager_Phar_ResultCompleter extends Packager_Common_Base
{
    private static $_pharNotAllowedMsg = array(
		'It is not allowed to create PHAR archive on your computer.',
		'Go to "php.ini" and allow PHAR archive creation by set up "phar.readonly = 0".'
	);
	private $_jsonResult;
	protected function mainJob ($params = array()) {
		$firstJobResult = $this->executeJobAndGetResult(
			'completingJob', array()
		);
		if ($firstJobResult instanceof stdClass && $firstJobResult->success) {
			$pharAbsPath = $firstJobResult->data->phar;
			$phpAbsPath = $firstJobResult->data->php;
			$secondJobResult = $this->executeJobAndGetResult(
				'renameJob', 
				array(
					'phar'	=> $pharAbsPath,
					'php'	=> $phpAbsPath,
				),
				'json'
			);
			if ($secondJobResult instanceof stdClass && $secondJobResult->success) {
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
	protected function completingJob ($params = array()) {
		$this->_jsonResult = (object) array(
			'success'	=> TRUE,
			'data'		=> array(),
		);
		$this->completeAllFiles();
		$this->_processPhpCode();
		list($releaseDir, $releaseFileNameWithoutExt) = $this->_completeBuildPaths();
		$this->_buildPharArchive($releaseDir, $releaseFileNameWithoutExt);
		$this->sendJsonResultAndExit($this->_jsonResult);
	}
	protected function renameJob ($params = array()) {
		$result = (object) array(
			'success'	=> TRUE,
			'data'		=> array(),
		);
		try {
			rename($params['phar'],  $params['php']);
		} catch (Exception $e) {
			$result->success = FALSE;
			$result->data = array(
				$e->getMessage(),
				$e->getTrace(),
			);
		}
		$this->sendJsonResultAndExit($result);
	}
	private function _processPhpCode () {
		foreach ($this->files as $fullPath => $fileInfo) {
			if ($fileInfo->extension != 'php' && !in_array($fileInfo->extension, static::$templatesExtensions)) continue;
			if ($this->cfg->patternReplacements) {
				foreach ($this->cfg->patternReplacements as $pattern => $replacement) {
					if (is_numeric($pattern)) {
						// if there is numeric key - values is always patern to replace with empty string
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
			if ($this->cfg->minifyTemplates && in_array($fileInfo->extension, static::$templatesExtensions)) {
				//include_once(__DIR__.'/../Libs/Minify/HTML.php');
				@include_once('vendor/autoload.php');
				$fileInfo->content = Minify_HTML::minify($fileInfo->content);
			}
			$this->files[$fullPath] = $fileInfo;
		}
	}
	private function _completeBuildPaths () {
		$releaseFileAbsPath = str_replace('\\', '/', $this->cfg->releaseFile);
		$lastSlashPos = strrpos($releaseFileAbsPath, '/');
		if ($lastSlashPos === FALSE) die('Something is wrong with your release file path. Fix it. No slash character found.');
		
		$releaseFileName = substr($releaseFileAbsPath, $lastSlashPos + 1);
		$releaseDir = substr($releaseFileAbsPath, 0, $lastSlashPos);
		
		$releaseFileNameExpl = explode('.', $releaseFileName);
		unset($releaseFileNameExpl[count($releaseFileNameExpl) - 1]);
		$releaseFileNameWithoutExt = implode('.', $releaseFileNameExpl);
		
		@unlink($releaseDir . '/' . $releaseFileNameWithoutExt . '.phar');
		@unlink($releaseDir . '/' . $releaseFileNameWithoutExt . '.php');

		return array(
			$releaseDir, $releaseFileNameWithoutExt
		);
	}
	private function _buildPharArchive ($releaseDir, $releaseFileNameWithoutExt) {
		$archive = NULL;

		try {
			$archive = new Phar(
				$releaseDir . '/' . $releaseFileNameWithoutExt . '.phar', 
				0, 
				$releaseFileNameWithoutExt . '.phar'
			);
		} catch (UnexpectedValueException $e1) {
			$m = $e1->getMessage();
			if (mb_strpos($m, 'disabled by the php.ini setting phar.readonly') !== FALSE) {
				$this->_jsonResult->success = FALSE;
				$this->_jsonResult->data = array(
					self::$_pharNotAllowedMsg[0],
					str_replace('php.ini', php_ini_loaded_file(), self::$_pharNotAllowedMsg[1]),
				);
			} else {
				$this->_jsonResult->success = FALSE;
				$this->_jsonResult->data = array(
					$e1->getMessage(),
					$e1->getTrace(),
				);
			}
		} catch (Exception $e2) {
			$this->_jsonResult->success = FALSE;
			$this->_jsonResult->data = array(
				$e2->getMessage(),
				$e2->getTrace(),
			);
		} finally {
			if ($this->_jsonResult->data) return;
		}
		
		$archive->setStub('<'.'?php '
			.PHP_EOL.'Phar::mapPhar();'
			.'include_once("phar://' . $releaseFileNameWithoutExt . '.phar/index.php");'
			.'__HALT_COMPILER();');
		
		$incScripts = array();
		$incStatics = array();
		//$archive->startBuffering();
		foreach ($this->files as $fileInfo) {
			$archive[$fileInfo->relPath] = $fileInfo->content;
			if ($fileInfo->extension == 'php') {
				$incScripts[] = $fileInfo->relPath;
			} else {
				$incStatics[] = $fileInfo->relPath;
			}
		}
		//$archive->stopBuffering();
		//$archive->compressFiles(Phar::GZ);
		@$archive->buildFromIterator(); // writes archive on hard drive
		unset($archive); // frees memory, run rename operation without any conflict
		
		$this->_jsonResult->data = array(
			'phar'		=> $releaseDir . '/' . $releaseFileNameWithoutExt . '.phar', 
			'php'		=> $releaseDir . '/' . $releaseFileNameWithoutExt . '.php',
			'incl'		=> array(
				'scripts'	=> $incScripts,
				'statics'	=> $incStatics,
			),
		);
	}
	protected function notify ($incFiles) {
		$scriptsCount = count($incFiles->scripts);
		$staticsCount = count($incFiles->statics);
		$totalCount = $scriptsCount + $staticsCount;
		if (php_sapi_name() == 'cli') {
			$content = "Total included files: $totalCount\n\n"
				. "\nIncluded PHP files ($scriptsCount):\n\n"
				. implode("\n", $incFiles->scripts)
				. "\n\n\nIncluded static files ($staticsCount):\n\n"
				. implode("\n", $incFiles->statics)
				. "\n\n\nDONE";
			$this->sendResult('Successfully packed', $content);
		} else {
			$content = "<div>Total included files: $totalCount</div>"
				. "<h2>Included PHP files ($scriptsCount):</h2>"
				. '<div class="files">'
					. implode("<br />", $incFiles->scripts)
				. "</div>"
				. "<h2>Included static files ($staticsCount):</h2>"
				. '<div class="files">'
					. implode("<br />", $incFiles->statics)
				. "</div>"
				. "<h2>DONE</h2>";
			$this->sendResult('Successfully packed', $content, 'success');
		}
	}
}
