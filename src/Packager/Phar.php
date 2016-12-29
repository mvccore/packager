<?php

/**
 * Packager
 *
 * This source file is subject to the BSD 3 License
 * For the full copyright and license information, please view 
 * the LICENSE.md file that are distributed with this source code.
 *
 * @copyright	Copyright (c) 2016 Tom Flídr (https://github.com/mvccore/packager)
 * @license		https://mvccore.github.io/docs/packager/1.0.0/LICENCE.md
 */

class Packager_Phar
{
	private static $instance;
	private $cfg;
	private $files = array();
	private $result;
	private static $_pharNotAllowedMsg = array(
		'It is not allowed to create PHAR archive on your computer.',
		'Go to "php.ini" and allow PHAR archive creation by set up "phar.readonly = 0".'
	);
	private static $_responseTemplates = array(
		'text'	=> "=================== %title ===================\n\n%h1\n\n%content\n\n",
		'html'	=> '<!DOCTYPE HTML><html lang="en-US"><head><meta charset="UTF-8"><title>%title</title><style type="text/css">html,body{margin:30px;font-size:14px;color:#fff;text-align:left;line-height:1.5em;font-weight:bold;font-family:"consolas",courier new,monotype;text-shadow:1px 1px 0 rgba(0,0,0,.4);}h1{font-size:200%;line-height:1.5em;}h2{font-size:150%;line-height:1.5em;}%style</style></head><body><h1>%h1</h1>%content</body></html>',
	);
	private static $_htmlStyles = array(
		'success'	=> 'html,body{background:#005700;}',
		'error'		=> 'html,body{background:#cd1818;}',
	);
	public static function run ($cfg = array())
	{
		self::$instance = new self($cfg);
		self::$instance->process();
	}
	public function __construct ($cfg = array())
	{
		$cfg = (object) $cfg;
		$cfg->sourcesDir = realpath($cfg->sourcesDir);
		$cfg->sourcesDir = str_replace('\\', '/', realpath($cfg->sourcesDir));
		if (!is_dir($cfg->sourcesDir)) die('Source directory not found.');
		$this->cfg = $cfg;
		$this->files = array();
	}
	public function process ($cfg = array())
	{
		$this->_completeFiles();
		$this->_processPhpCode();
		$this->_completeResult();
		$this->_notify();
	}
	private function _completeFiles ()
	{
		// get project source code recursive iterator
		$rdi = new \RecursiveDirectoryIterator($this->cfg->sourcesDir);
		$rii = new \RecursiveIteratorIterator($rdi);
		
		$allFiles = array();
		foreach($rii as $item){
			if (!$item->isDir()) {
				
				$fullPath = str_replace('\\', '/', $item->__toString());
				$relPath = substr($fullPath, strlen($this->cfg->sourcesDir));
				
				$extension = '';
				$lastDotPos = strrpos($fullPath, '.');
				if ($lastDotPos !== FALSE) $extension = substr($fullPath, $lastDotPos + 1);
				
				$fileName = '';
				$lastSlashPos = strrpos($fullPath, '/');
				if ($lastSlashPos !== FALSE) $fileName = substr($fullPath, $lastSlashPos + 1);
				
				$relPathDir = substr($relPath, 0, strlen($relPath) - strlen($fileName) - 1);
				
				$allFiles[$relPath] = (object) array(
					'fullPath'	 	=> $fullPath,
					'relPathDir'	=> $relPathDir,
					'fileName'		=> $fileName,
					'extension'		=> $extension,
					'content'		=> file_get_contents($fullPath),
					'processed'		=> FALSE,
				);
			}
		}
		
		ksort($allFiles);
		$this->files = $allFiles;
		
		$excludePatterns = $this->cfg->excludePatterns;
		foreach ($excludePatterns as $excludePattern) {
			$excludePattern = "/" . str_replace('/', '\\/', $excludePattern) . "/";
			foreach ($this->files as $relPath => $fileInfo) {
				@preg_match($excludePattern, $relPath, $matches);
				if ($matches) unset($this->files[$relPath]);
			}
		}
	}
	private function _processPhpCode ()
	{
		foreach ($this->files as $relPath => $fileInfo) {
			if ($fileInfo->extension != 'php') continue;
			if ($this->cfg->compressPhp) {
				$fileInfo->content = $this->_shrinkPhpCode($fileInfo->content);
			}
			if ($this->cfg->patternReplacements) {
				foreach ($this->cfg->patternReplacements as $pattern => $replacement) {
					while (preg_match($pattern, $fileInfo->content)) {
						$fileInfo->content = preg_replace($pattern, $replacement, $fileInfo->content);
					}
				}
			}
			if ($this->cfg->stringReplacements) {
				foreach ($this->cfg->stringReplacements as $from => $to) {
					$fileInfo->content = str_replace($from, $to, $fileInfo->content);
				}
			}
			$this->files[$relPath] = $fileInfo;
		}
	}
	private function _shrinkPhpCode ($code = '')
	{
		// PHP 4 & 5 compatibility
		if (!defined('T_DOC_COMMENT')) define ('T_DOC_COMMENT', -1);
		if (!defined('T_ML_COMMENT')) define ('T_ML_COMMENT', -1);
		$space = $result = '';
		$set = '!"#$&\'()*+,-./:;<=>?@[\]^`{|}';
		$set = array_flip(preg_split('//',$set));
		foreach (token_get_all($code) as $token)	{
			if (!is_array($token))
			$token = array(0, $token);
			switch ($token[0]) {
			case T_COMMENT:
			case T_ML_COMMENT:
			case T_DOC_COMMENT:
			case T_WHITESPACE:
				$space = ' ';
				break;
			default:
				if (isset($set[substr($result, -1)]) ||
					isset($set[$token[1]{0}])) $space = '';
				$result .= $space . $token[1];
				$space = '';
			}
		}
		return $result;
	}
	private function _completeResult ()
	{
		$releaseFileAbsPath = str_replace('\\', '/', $this->cfg->releaseFile);
		$lastSlashPos = strrpos($releaseFileAbsPath, '/');
		if ($lastSlashPos === FALSE) die('Something is wrong with your release file path. Fix it. No slash character found.');
		
		$releaseFileName = substr($releaseFileAbsPath, $lastSlashPos + 1);
		$releaseDir = substr($releaseFileAbsPath, 0, $lastSlashPos);
		
		$releaseFileNameExpl = explode('.', $releaseFileName);
		unset($releaseFileNameExpl[count($releaseFileNameExpl) - 1]);
		$releaseFileNameWithoutExt = implode('.', $releaseFileNameExpl);
		
		
		$archive = null;
		@unlink($releaseDir . '/' . $releaseFileNameWithoutExt . '.phar');
		@unlink($releaseDir . '/' . $releaseFileNameWithoutExt . '.php');
		try {
			$archive = new Phar($releaseDir . '/' . $releaseFileNameWithoutExt . '.phar', 0, $releaseFileNameWithoutExt . '.phar');
		} catch (UnexpectedValueException $e1) {
			$m = $e1->getMessage();
			if (mb_strpos($m, 'disabled by the php.ini setting phar.readonly') !== FALSE) {
				$this->_sendResult(self::$_pharNotAllowedMsg[0], self::$_pharNotAllowedMsg[1], 'error');
			} else {
				$this->_sendResult($e1->getMessage(), $e1->getTrace(), 'error');
			}
		} catch (Exception $e2) {
			$this->_sendResult($e2->getMessage(), $e2->getTrace(), 'error');
		}
		
		$archive->setStub('<?php
			Phar::mapPhar();
			include_once("phar://' . $releaseFileNameWithoutExt . '.phar/index.php");
			__HALT_COMPILER();
		');
		foreach ($this->files as $relPath => $fileInfo) {
			$archive[$relPath] = $fileInfo->content;
		}
		@$archive->buildFromIterator();
		
		unset($archive); // to run rename operation bellow
		rename(
			$releaseDir . '/' . $releaseFileNameWithoutExt . '.phar', 
			$releaseDir . '/' . $releaseFileNameWithoutExt . '.php'
		);
	}
	private function _notify ()
	{
		$includedFiles = array_keys($this->files);
		if (php_sapi_name() == 'cli') {
			$this->_sendResult(
				'Successfly packed.', 
				"Included files:\n\n" . implode("\n", $includedFiles) . "\n\nDONE"
			);
		} else {
			$this->_sendResult(
				'Successfly packed.', 
				'<h2>Included files:</h2><div class="files">' . implode('<br />', $includedFiles) . '</div><h2>DONE</h2>',
				'success'
			);
		}
	}
	private function _sendResult ($title, $content, $type = '')
	{
		$outputType = php_sapi_name() == 'cli' ? 'text' : 'html' ;
		if (gettype($content) == 'string') {
			$contentStr = $content;
		} else {
			$contentItems = array();
			foreach ($content as $item) {
				if ($outputType == 'text') {
					$contentItems[] = $item['class'] . '::' . $item['function'] . "();\n" . $item['file'] . ':' . $item['line'];
				} else {
					$contentItems[] = '<td>' . $item['class'] . '::' . $item['function'] . '();&nbsp;</td><td>' . $item['file'] . ':' . $item['line'] . '</td>';
				}
			}
			if ($outputType == 'text') {
				$contentStr = implode("\n\n", $contentItems);
			} else {
				$contentStr = '<table><tbody><tr>' . implode('</tr><tr>', $contentItems) . '</tr></tbody></table>';
			}
		}
		$responseTmpl = self::$_responseTemplates[$outputType];
		$response = str_replace(
			array('%title', '%h1', '%content', '%style'),
			array('MvcCore PHAR Packager', $title, $contentStr, self::$_htmlStyles[$type]),
			$responseTmpl
		);
		echo $response;
		die();
	}
}