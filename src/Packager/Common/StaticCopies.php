<?php

include_once(__DIR__.'/Base.php');

class Packager_Common_StaticCopies extends Packager_Common_Base
{
	protected $staticallyCopiedFiles = [];

	private static $_isWindows = NULL;
	private static $_winDirsLinkItems = [];

	protected function cleanReleaseDir () {
		$releaseDir = $this->cfg->releaseDir;
		if (!is_dir($releaseDir))
			mkdir($releaseDir, 0777);
		$this->cfg->sourcesDir = str_replace('\\', '/', realpath($this->cfg->sourcesDir));
		$releaseDir = str_replace('\\', '/', realpath($releaseDir));
		$this->cfg->releaseDir = $releaseDir;
		clearstatcache();
		$rdi = new \RecursiveDirectoryIterator($releaseDir);
		$rii = new \RecursiveIteratorIterator($rdi);
		$dirsFullPaths = [];
		foreach ($rii as $item) {
			/** @var $item \SplFileInfo */
			$path = str_replace('\\', '/', $item->getPath());
			$fileName = $item->getFilename();
			$parentDir = $fileName == '..';
			if ($parentDir) continue;
			$isDir = $fileName == '.';
			$baseDir = $isDir && $releaseDir === $path;
			if ($baseDir) continue;
			if ($isDir) {
				$dirsFullPaths[$path] = TRUE;
				continue;
			}
			$fullPath = $path . '/' . $fileName;
			unlink($fullPath);
			clearstatcache(TRUE, $fullPath);
			if (file_exists($fullPath)) {
				$this->sendResult(
					"Unable to remove a file to clean whole release directory first:", 
					"Full path to the file:<br /><br />'$fullPath'", 
					'error'
				);
			}
		}
		$dirsFullPaths = array_keys($dirsFullPaths);
		rsort($dirsFullPaths);
		foreach ($dirsFullPaths as $dirFullPath) {
			rmdir($dirFullPath);
			clearstatcache(TRUE, $dirFullPath);
			if (file_exists($dirFullPath)) {
				$this->sendResult(
					"Unable to remove a directory to clean whole release directory first:", 
					"Full path to the directory:<br /><br />'$dirFullPath'", 
					'error'
				);
			}
		}
		sort($this->staticallyCopiedFiles);
	}
	protected function copyStaticFilesAndFolders () {
		if (!$this->cfg->staticCopies) return;
		try {
			$sourcesDir = $this->cfg->sourcesDir;
			$releaseDir = $this->cfg->releaseDir;
			$sourcesDirLength = mb_strlen($sourcesDir);
			foreach ($this->cfg->staticCopies as $key => $value) {
				$source = $sourcesDir . (is_numeric($key) ? $value : $key);
				$destination = $releaseDir . $value;
				$i = 0;
				while ($i < 5) {
					$sourceSpl = new \SplFileInfo($source);
					if (self::_isLink($sourceSpl)) {
						$source = self::_readLink($sourceSpl);
					} else {
						break;
					}
					$i++;
				}
				if (is_dir($source)) {
					//var_dump(['dir', $source, $destination]);
					$this->_copyDirectoryRecursively($source, $destination);
				} else if (is_file($source)) {
					//var_dump(['file', $source, $destination]);
					$this->_copyFile($source, $destination, mb_substr($source, $sourcesDirLength));
				}
			}
		} catch (\Exception $e) {
			$this->sendResult(
				"Unable to copy a file/directory into release directory:", 
				$e->getMessage(), 'error'
			);
		}
	}
	private function _copyDirectoryRecursively ($sourceDirFullPath, $destinationBaseDirFullPath) {
		$rdi = new \RecursiveDirectoryIterator(
			$sourceDirFullPath,
			\FilesystemIterator::FOLLOW_SYMLINKS
		);
		$rii = new \RecursiveIteratorIterator($rdi);
		$dirsFullPaths = [];
		$filesFullPaths = [];
		foreach ($rii as $item) {
			/** @var $item \SplFileInfo */
			$path = str_replace('\\', '/', $item->getPath());
			$isDir = $item->isDir();
			$baseDir = $isDir && $sourceDirFullPath === $path;
			if ($baseDir) continue;
			if ($isDir) {
				$dirsFullPaths[$path] = TRUE;
			} else {
				$filesFullPaths[$path . '/' . $item->getFilename()] = TRUE;
			}
		}
		$dirsFullPaths = array_keys($dirsFullPaths);
		$filesFullPaths = array_keys($filesFullPaths);
		sort($dirsFullPaths);
		rsort($filesFullPaths);
		$sourceDirFullPathLength = mb_strlen($sourceDirFullPath);
		$sourceBaseDirFullPathLength = mb_strlen($this->cfg->sourcesDir);
		foreach ($dirsFullPaths as $dirsFullPath) {
			$sourceRelativePath = mb_substr($dirsFullPath, $sourceDirFullPathLength);
			$destinationDirFullPath = $destinationBaseDirFullPath . $sourceRelativePath;
			//var_dump(['dir-dir', $dirsFullPath, $destinationDirFullPath]);
			mkdir($destinationDirFullPath, 640, TRUE);
		}
		foreach ($filesFullPaths as $fileFullPath) {
			$sourceRelativePath = mb_substr($fileFullPath, $sourceDirFullPathLength);
			$destinationFileFullPath = $destinationBaseDirFullPath . $sourceRelativePath;
			//var_dump(['dir-file', $filesFullPath, $destinationFileFullPath]);
			$this->_copyFile($fileFullPath, $destinationFileFullPath, mb_substr($fileFullPath, $sourceBaseDirFullPathLength));
		}
	}
	private function _copyFile ($sourceFileFullPath, $destinationFileFullPath, $sourceFileRelPath) {
		$destinationDirFullPath = dirname($destinationFileFullPath);
		if (!is_dir($destinationDirFullPath))
			mkdir($destinationDirFullPath, 640, TRUE);
		$success = copy($sourceFileFullPath, $destinationFileFullPath);
		if ($success) {
			$this->staticallyCopiedFiles[] = $sourceFileRelPath;
		} else {
			$this->sendResult(
				"Unable to copy a file into release directory:", 
				"Source file full path:<br /><br />'$sourceFileFullPath'<br /><br /><br />"
				."Destination file full path:<br /><br />'$destinationFileFullPath'", 
				'error'
			);
		}
	}
	private static function _isLink (\SplFileInfo $spl) {
		$splFileName = $spl->getFilename();
		if (self::_isWindows()) {
			$dirPath = str_replace('\\', '/', $spl->getPath());
			$linkItems = self::_getItemDirWinSymLinks($dirPath);
			return isset($linkItems[$splFileName]);
		} else {
			return is_link($spl->getPath() . '/' . $splFileName);
		}
	}
	private static function _readLink (\SplFileInfo $spl) { 
		$splFileName = $spl->getFilename();
		if (self::_isWindows()) {
			$dirPath = str_replace('\\', '/', $spl->getPath());
			$linkItems = self::_getItemDirWinSymLinks($dirPath);
			return $linkItems[$splFileName];
		} else {
			return readlink($spl->getPath() . '/' . $splFileName);
		}
	}
	private static function _isWindows () {
		if (self::$_isWindows === NULL) {
			if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
				self::$_isWindows = TRUE;
			} else {
				self::$_isWindows = FALSE;
			}
		}
		return self::$_isWindows;
	}
	private static function _getItemDirWinSymLinks ($dirPath) {
		if (isset(self::$_winDirsLinkItems[$dirPath])) {
			$linkItems = self::$_winDirsLinkItems[$dirPath];
		} else {
			$linkItems = [];
			$sysOut = self::_system('dir /A:L', $dirPath);
			if ($sysOut !== FALSE) {
				$sysOutLines = explode(PHP_EOL, $sysOut);
				foreach ($sysOutLines as $sysOutLine) {
					if (mb_strlen($sysOutLine) === 0) continue;
					$firstChar = mb_substr($sysOutLine, 0, 1);
					if ($firstChar === ' ') continue;
					$fileNameWithTarget = mb_substr($sysOutLine, 36);
					$fileNameWithTargetLength = mb_strlen($fileNameWithTarget);
					$fileNameEndPos = mb_strrpos($fileNameWithTarget, ' [');
					if ($fileNameEndPos === FALSE) {
						$fileNameEndPos = mb_strlen($fileNameWithTarget);
					} else {
						$fileNameEndPos += 2;
					}
					$fileName = mb_substr($fileNameWithTarget, 0, $fileNameEndPos - 2);
					$target = str_replace('\\', '/', 
						mb_substr(
							$fileNameWithTarget, 
							$fileNameEndPos, 
							$fileNameWithTargetLength - $fileNameEndPos - 1
						)
					);
					$linkItems[$fileName] = $target;
				}
			}
			self::$_winDirsLinkItems[$dirPath] = $linkItems;
		}
		return $linkItems;
	}
	private static function _system ($cmd, $dirPath = NULL) {
		if (!function_exists('system')) return FALSE;
		$dirPathPresented = $dirPath !== NULL && mb_strlen($dirPath) > 0;
		$cwd = '';
		if ($dirPathPresented) {
			$cwd = getcwd();
			chdir($dirPath);
		}
		ob_start();
		system($cmd);
		$sysOut = ob_get_clean();
		if ($dirPathPresented) chdir($cwd);
		return $sysOut;
	}
}
