<?php

class Packager_Common_RecursiveDirIterator {

	const PLAFORM_WINDOWS = 'win';
	const PLAFORM_UNIX = 'unix';
	const PLAFORM_MAC = 'max';
	
	protected static $platform = NULL;
	protected static $winDirsLinkItems = [];

	protected $directoryFullPath;

	protected static function getPlatform () {
		if (self::$platform === NULL) {
			if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
				self::$platform = self::PLAFORM_WINDOWS;
			} else if (strtoupper(PHP_OS) == "DARWIN") {
				self::$platform = self::PLAFORM_MAC;
			} else {
				self::$platform = self::PLAFORM_UNIX;
			}
		}
		return self::$platform;
	}

	protected static function isLink (\SplFileInfo $spl) {
		$platform = self::getPlatform();
		$splFileName = $spl->getFilename();
		if ($platform === self::PLAFORM_UNIX) {
			return is_link($spl->getPath() . '/' . $splFileName);
		} else {
			$dirPath = str_replace('\\', '/', $spl->getPath());
			$linkItems = self::getItemDirWinSymLinks($dirPath);
			return isset($linkItems[$splFileName]);
		}
	}

	protected static function readLink (\SplFileInfo $spl) { 
		$platform = self::getPlatform();
		$splFileName = $spl->getFilename();
		if ($platform === self::PLAFORM_UNIX) {
			return readlink($spl->getPath() . '/' . $splFileName);
		} else {
			$dirPath = str_replace('\\', '/', $spl->getPath());
			$linkItems = self::getItemDirWinSymLinks($dirPath);
			return $linkItems[$splFileName];
		}
	}

	protected static function getItemDirWinSymLinks ($dirPath) {
		if (isset(self::$winDirsLinkItems[$dirPath])) {
			$linkItems = self::$winDirsLinkItems[$dirPath];
		} else {
			$linkItems = [];
			$sysOut = self::System('dir /A:L /A:D', $dirPath);
			if ($sysOut !== FALSE) {
				$sysOutLines = explode(PHP_EOL, $sysOut);
				$dirPos = NULL;
				$juncNamePos = NULL;
				foreach ($sysOutLines as $sysOutLine) {
					if (mb_strlen($sysOutLine) === 0) continue;
					$firstChar = mb_substr($sysOutLine, 0, 1);
					if ($firstChar === ' ') continue;
					if ($juncNamePos === NULL) {
						$dirPos = mb_strrpos($sysOutLine, '<DIR>');
						$juncNamePos = mb_strrpos($sysOutLine, '.', $dirPos + 5);
						continue;
					}
					$fileNameWithTarget = mb_substr($sysOutLine, $juncNamePos);
					$fileNameWithTargetLength = mb_strlen($fileNameWithTarget);
					$fileNameEndPos = mb_strrpos($fileNameWithTarget, ' [');
					if ($fileNameEndPos === FALSE) {
						$fileNameEndPos = mb_strlen($fileNameWithTarget);
					} else {
						$fileNameEndPos += 2;
					}
					$fileName = mb_substr($fileNameWithTarget, 0, $fileNameEndPos - 2);
					$target = str_replace('\\', '/', mb_substr($fileNameWithTarget, $fileNameEndPos, $fileNameWithTargetLength - $fileNameEndPos - 1));
					$linkItems[$fileName] = $target;
				}
			}
			self::$winDirsLinkItems[$dirPath] = $linkItems;
		}
		return $linkItems;
	}

	/**
	 * @param string $cmd 
	 * @param string|NULL $dirPath 
	 * @return bool|string
	 */
	public static function system ($cmd, $dirPath = NULL) {
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

	public function __construct ($directoryFullPath) {
		$this->directoryFullPath = $directoryFullPath;
	}

	public function GetAllFiles () {
		return $this->completeSplItems($this->directoryFullPath, $this->directoryFullPath);
	}

	protected function completeSplItems ($dirFullPath, $realFullPath) {
		$rii = $this->createPhpIterator($realFullPath);
		$realFullPathLen = mb_strlen($realFullPath);
		$allItems = [];
		foreach ($rii as $item) {
			$fileName = $item->getFilename();
			if ($fileName === '.' || $fileName === '..') continue;
			$fullPath = $item->__toString();
			$relPath = mb_substr($fullPath, $realFullPathLen);
			if ($item->isFile()) {
				$allItems[$fullPath] = [$relPath, $item];
			} else if (self::isLink($item)) {
				$linkSourceFullPath = self::readLink($item);
				$allItemsLocal = $this->completeSplItems($dirFullPath, $linkSourceFullPath);
				foreach ($allItemsLocal as $fullPath => $relPathAndSplFileInfo) {
					list ($relPathLocal, $splFileInfo) = $relPathAndSplFileInfo;
					$allItems[$dirFullPath . $relPath . $relPathLocal] = [$relPath . $relPathLocal, $splFileInfo];
				}
			}
		}
		return $allItems;
	}

	protected function createPhpIterator ($dirFullPath) {
		$rdi = new \RecursiveDirectoryIterator(
			$dirFullPath,
			\FilesystemIterator::KEY_AS_PATHNAME |
			\FilesystemIterator::CURRENT_AS_FILEINFO | 
			\FilesystemIterator::UNIX_PATHS
		);
		$rii = new \RecursiveIteratorIterator($rdi);
		return $rii;
	}

}