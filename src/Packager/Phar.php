<?php

include_once(__DIR__.'/Phar/ResultCompleter.php');

class Packager_Phar extends Packager_Phar_ResultCompleter
{
	/**
	 * Create singleton instance with configuration
	 * 
	 * @param array $cfg 
	 * 
	 * @return Packager_Phar
	 */
	public static function Create ($cfg = []) {
		return parent::Create($cfg);
	}
	/**
	 * Get instance and merge configuration or create singleton instance with configuration
	 * 
	 * @param array $cfg 
	 * 
	 * @return Packager_Phar
	 */
	public static function Get ($cfg = []) {
		return parent::Get($cfg);
	}
	/**
	 * Set application sources directory
	 * 
	 * @param string $fullOrRelativePath
	 * 
	 * @return Packager_Phar
	 */
	public function SetSourceDir ($fullOrRelativePath = '') {
		return parent::SetSourceDir($fullOrRelativePath);
	}
	/**
	 * Set compilation result file, if exist, it will be overwritten
	 * 
	 * @param string $releaseFileFullPath 
	 * 
	 * @return Packager_Phar
	 */
	public function SetReleaseFile ($releaseFileFullPath = '') {
		return parent::SetReleaseFile($releaseFileFullPath);
	}
	/**
	 * Set preg_replace() patterns array or single string about
	 * which files or folders will be excluded from result file.
	 * Function replace all previous configuration records.
	 * 
	 * @param array|string $excludePatterns 
	 * 
	 * @return Packager_Phar
	 */
	public function SetExcludePatterns ($excludePatterns = []) {
		return parent::SetExcludePatterns($excludePatterns);
	}
	/**
	 * Add preg_replace() patterns array or single string about
	 * which files or folders will be excluded from result file.
	 * 
	 * @param array|string $excludePatterns 
	 * 
	 * @return Packager_Phar
	 */
	public function AddExcludePatterns ($excludePatterns = []) {
		return parent::AddExcludePatterns($excludePatterns);
	}
	/**
	 * Set patterns/replacements array about what will be replaced
	 * in *.php and *.phtml files by preg_replace(pattern, replacement, source)
	 * before possible minification process.
	 * Function replace all previous configuration records.
	 * 
	 * @param array $patternReplacements
	 * 
	 * @return Packager_Php
	 */
	public function SetPatternReplacements ($patternReplacements = []) {
		return parent::SetPatternReplacements($patternReplacements);
	}
	/**
	 * Add patterns/replacements array about what will be replaced
	 * in *.php and *.phtml files by preg_replace(pattern, replacement, source)
	 * before possible minification process.
	 * 
	 * @param array $patternReplacements
	 * 
	 * @return Packager_Php
	 */
	public function AddPatternReplacements ($patternReplacements = []) {
		return parent::AddPatternReplacements($patternReplacements);
	}
	/**
	 * Set str_replace() key/value array about
	 * what will be simply replaced in result file.
	 * Function replace all previous configuration records.
	 * 
	 * @param array $stringReplacements 
	 * 
	 * @return Packager_Phar
	 */
	public function SetStringReplacements ($stringReplacements = []) {
		return parent::SetStringReplacements($stringReplacements);
	}
	/**
	 * Add str_replace() key/value array about
	 * what will be simply replaced in result file.
	 * 
	 * @param array $stringReplacements 
	 * 
	 * @return Packager_Phar
	 */
	public function AddStringReplacements ($stringReplacements = []) {
		return parent::AddStringReplacements($stringReplacements);
	}
	/**
	 * Set boolean if *.phtml templates will be minimized before saving into result file
	 * 
	 * @param string $minifyTemplates
	 * 
	 * @return Packager_Phar
	 */
	public function SetMinifyTemplates ($minifyTemplates = TRUE) {
		return parent::SetMinifyTemplates($minifyTemplates);
	}
	/**
	 * Set boolean if *.php scripts will be minimized before saving into result file
	 * 
	 * @param string $minifyPhp
	 * 
	 * @return Packager_Phar
	 */
	public function SetMinifyPhp ($minifyPhp = TRUE) {
		return parent::SetMinifyPhp($minifyPhp);
	}
	/**
	 * Merge multilevel configuration array with previously initialized values.
	 * New values sent into this function will be used preferred.
	 * 
	 * @param array $cfg
	 * 
	 * @return Packager_Phar
	 */
	public function MergeConfiguration ($cfg = []) {
		return parent::MergeConfiguration($cfg);
	}
	/**
	 * Run PHAR compilation process, print output to CLI or browser
	 * 
	 * @param array $cfg 
	 * 
	 * @return Packager_Phar
	 */
	public function Run ($cfg = []) {
		parent::Run($cfg);
		list($jobMethod, $params) = $this->completeJobAndParams();
		$this->$jobMethod($params);
	}
}
