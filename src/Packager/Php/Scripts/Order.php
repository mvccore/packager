<?php

include_once(__DIR__.'/Completer.php');

class Packager_Php_Scripts_Order extends Packager_Php_Scripts_Completer
{
    protected function completePhpFilesOrder () {
		$this->filesPhpOrder = array();
		// order dependencies to process next steps faster
		$this->_orderDependenciesByCounts();
		// order by completed dependencies
		$this->_orderPhpFilesByDependencies();
		// reorder by prefered configuration
		$this->_orderPhpFilesByPreferedConfiguration('includeFirst');
		$this->_orderPhpFilesByPreferedConfiguration('includeLast');
		// free memory
		$this->filesPhpDependencies = array();
	}
	private function _orderDependenciesByCounts () {
		// order records from lowest count of requires to highest count of requires
		// if values are the same - order records from highest count of requiredBy 
		// to lowest count of requiredBy
		uasort($this->filesPhpDependencies, function ($a, $b) {
			if ($a->requiresCount == $b->requiresCount) {
				if ($a->requiredByCount == $b->requiredByCount) return 0;
				return ($a->requiredByCount > $b->requiredByCount) ? -1 : 1;
			}
			return ($a->requiresCount < $b->requiresCount) ? -1 : 1;
		});
	}
	private function _orderPhpFilesByDependencies () {
		$filesPhpDependenciesCountLast = 0;
		$success = FALSE;
		while (TRUE) {
			$filesPhpDependenciesCountCurrent = count($this->filesPhpDependencies);
			if ($filesPhpDependenciesCountCurrent == $filesPhpDependenciesCountLast) {
				$success = FALSE;
				break;
			}
			if (!$filesPhpDependenciesCountCurrent) {
				$success = TRUE;
				break;
			}
			$filesPhpDependenciesCountLast = $filesPhpDependenciesCountCurrent;
			$this->_arangeOrderByDependenciesRequiresData();
			$this->_arangeOrderByDependenciesRequiresAndRequiredByData();
		}
		if (!$success && count($this->filesPhpDependencies) > 0) {
			$this->_displayErrorIfThereWasIndefinableOrderSituation();
		}
		$this->filesPhpOrder = array_keys($this->filesPhpOrder);
	}
	private function _arangeOrderByDependenciesRequiresData () {
		$keysLengthLast = 0;
		while (true) {
			// complete keys to iterate in current loop
			$keysToIterate = array_keys($this->filesPhpDependencies);
			$keysLengthCurrent = count($keysToIterate);
			if (!$keysLengthCurrent || $keysLengthCurrent == $keysLengthLast) break;
			$keysLengthLast = $keysLengthCurrent;
			for ($i = 0; $i < $keysLengthCurrent; $i += 1) {
				$fullPath = $keysToIterate[$i];
				$filesDependenciesItem = $this->filesPhpDependencies[$fullPath];
				if (!$filesDependenciesItem->requiresCount) {
					$this->filesPhpOrder[$fullPath] = 1;
					unset($this->filesPhpDependencies[$fullPath]);
				} else {
					$allRequiresFilesAlreadyOrdered = TRUE;
					foreach ($filesDependenciesItem->requires as $requiresItem) {
						if (!isset($this->filesPhpOrder[$requiresItem])) {
							$allRequiresFilesAlreadyOrdered = FALSE;
							break;
						}
					}
					if ($allRequiresFilesAlreadyOrdered) {
						$this->filesPhpOrder[$fullPath] = 1;
						unset($this->filesPhpDependencies[$fullPath]);
					}
				}
			}
		}
	}
	private function _arangeOrderByDependenciesRequiresAndRequiredByData () {
		// complete keys to iterate in current loop
		$keysToIterate = array_keys($this->filesPhpDependencies);
		$keysLength = count($keysToIterate);
		for ($i = 0; $i < $keysLength; $i += 1) {
			$unsafeOrderDetectionRequires = array();
			$fullPath = $keysToIterate[$i];
			$filesDependenciesItem = $this->filesPhpDependencies[$fullPath];
			if (!$filesDependenciesItem->requiresCount) {
				$this->filesPhpOrder[$fullPath] = 1;
				unset($this->filesPhpDependencies[$fullPath]);
			} else {
				$allRequiresFilesAlreadyOrdered = TRUE;
				foreach ($filesDependenciesItem->requires as $requiresItem) {
					if (!isset($this->filesPhpOrder[$requiresItem])) {
						if (in_array($requiresItem, $filesDependenciesItem->requiredBy)) {
							$unsafeOrderDetectionRequires[] = $this->files->all[$requiresItem]->relPath;
						} else {
							$allRequiresFilesAlreadyOrdered = FALSE;
							break;
						}
					}
				}
				if ($allRequiresFilesAlreadyOrdered) {
					$this->filesPhpOrder[$fullPath] = 1;
					unset($this->filesPhpDependencies[$fullPath]);
				}
			}
			if (count($unsafeOrderDetectionRequires) > 0) {
				$relPath = $this->files->all[$fullPath]->relPath;
				if (!in_array($relPath, $this->unsafeOrderDetection)) {
					$this->unsafeOrderDetection[] = $relPath;
				}
				foreach ($unsafeOrderDetectionRequires as $unsafeOrderDetectionRequire) {
					if (!in_array($unsafeOrderDetectionRequire, $this->unsafeOrderDetection)) {
						$this->unsafeOrderDetection[] = $unsafeOrderDetectionRequire;
					}
				}
			}
		}
	}
	private function _displayErrorIfThereWasIndefinableOrderSituation () {
		$filesFullPaths = array_keys($this->filesPhpDependencies);
		foreach ($this->cfg->includeFirst as $fullPath) {
			$key = array_search($fullPath, $filesFullPaths);
			if ($key !== FALSE) unset($filesFullPaths[$key]);
		}
		foreach ($this->cfg->includeLast as $fullPath) {
			$key = array_search($fullPath, $filesFullPaths);
			if ($key !== FALSE) unset($filesFullPaths[$key]);
		}
		if (count($filesFullPaths) > 0) {
			$this->sendResult(
				"There was not possible to determinate declaration order for files bellow. <br />",
				"Please set order for these files manualy by config arrays in keys: <br />",
				"\$config['includeFirst'] = array(...);<br /><br />".
				"\$config['includeLast'] = array(...);", 
				$filesFullPaths, 
				'error'
			);
		}
	}
	private function _orderPhpFilesByPreferedConfiguration ($cfgKey) {
		if (count($this->cfg->$cfgKey) > 0) {
			$filesPhpOrder = $this->filesPhpOrder;
			$fullPathsToReorder = array();
			foreach ($this->cfg->$cfgKey as $fullPath) {
				if (is_file($fullPath)) {
					if (!isset($this->files->all[$fullPath])) continue;
					if ($this->files->all[$fullPath]->extension != 'php') continue;
					$fullPathsToReorder[] = $fullPath;
					$keyToUnset = array_search($fullPath, $filesPhpOrder);
					if ($keyToUnset !== FALSE && isset($filesPhpOrder[$keyToUnset])) {
						unset($filesPhpOrder[$keyToUnset]);
					}
				} else {
					for ($i = 0, $l = count($filesPhpOrder); $i < $l; $i += 1) {
						if (!isset($filesPhpOrder[$i])) continue;
						$orderedFullPath = $filesPhpOrder[$i];
						if (mb_strpos($orderedFullPath, $fullPath) === 0) {
							$fullPathsToReorder[] = $orderedFullPath;
							if (isset($filesPhpOrder[$i])) {
								unset($filesPhpOrder[$i]);
							}
						}
					}
				}
			}
			if ($cfgKey == 'includeFirst') {
				$this->filesPhpOrder = array_merge(
					array_values($fullPathsToReorder), 
					array_values($filesPhpOrder)
				);
			} else {
				$this->filesPhpOrder = array_merge(
					array_values($filesPhpOrder), 
					array_values($fullPathsToReorder)
				);
			}
		}
	}
}