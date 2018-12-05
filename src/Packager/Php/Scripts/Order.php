<?php

include_once(__DIR__.'/Completer.php');

class Packager_Php_Scripts_Order extends Packager_Php_Scripts_Completer
{
	protected function completePhpFilesOrder () {
		$this->filesPhpOrder = [];
		// order dependencies to process next steps faster
		$this->_orderDependenciesByCounts();
		// order by completed dependencies
		$this->_orderPhpFilesByDependencies();
		// reorder by preferred configuration
		$this->_orderPhpFilesByPreferredConfiguration('includeFirst');
		$this->_orderPhpFilesByPreferredConfiguration('includeLast');
		// free memory
		$this->filesPhpDependencies = [];
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
		/*print_r($this->filesPhpDependencies);
		die();*/
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
			$this->_arangeOrderByDependenciesRequiresDataWithRecursiveDetection();
			$this->_arangeOrderByDependenciesRequiresAndRequiredByData();
		}
		if (!$success && count($this->filesPhpDependencies) > 0) {
			/* print_r($this->filesPhpDependencies);
			die(); */
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
					// if file doesn't require any other file - put it into order as it is directly
					$this->filesPhpOrder[$fullPath] = 1;
					unset($this->filesPhpDependencies[$fullPath]);
				} else {
					// if file requires anything - check if his files to require have been already orderer or not
					$allRequiresFilesAlreadyOrdered = TRUE;
					foreach ($filesDependenciesItem->requires as $requiresItem) {
						if (!isset($this->filesPhpOrder[$requiresItem])) {
							$allRequiresFilesAlreadyOrdered = FALSE;
							break;
						}
					}
					// all it's files what is needs has been already ordered - so put this file also into order as it is
					if ($allRequiresFilesAlreadyOrdered) {
						$this->filesPhpOrder[$fullPath] = 1;
						unset($this->filesPhpDependencies[$fullPath]);
					}
				}
			}
		}
	}
	private function _arangeOrderByDependenciesRequiresDataWithRecursiveDetection () {
		$keysLengthLast = 0;
		while (true) {
			// complete keys to iterate in current loop
			$keysToIterate = array_keys($this->filesPhpDependencies);
			$keysLengthCurrent = count($keysToIterate);
			if (!$keysLengthCurrent || $keysLengthCurrent == $keysLengthLast) break;
			$keysLengthLast = $keysLengthCurrent;
			for ($i = 0; $i < $keysLengthCurrent; $i += 1) {
				$fullPath = $keysToIterate[$i];
				if (!isset($this->filesPhpDependencies[$fullPath])) continue; // file has been ordered by another file
				$filesDependenciesItem = $this->filesPhpDependencies[$fullPath];
				if (!$filesDependenciesItem->requiresCount) {
					// if file doesn't require any other file - put it into order as it is directly
					$this->filesPhpOrder[$fullPath] = 1;
					unset($this->filesPhpDependencies[$fullPath]);
				} else {
					// if file requires anything - walk on requires levels to complete recursive order
					$filesToOrder = [$fullPath => 1];
					$this->_getAllRequiresFilesToOrderedRecursive(
						$filesDependenciesItem->requires,
						$filesToOrder
					);
					/*print_r($filesToOrder);
					die();*/
					if ($filesToOrder) {
						foreach ($filesToOrder as $subFullPath => $yes) {
							$this->filesPhpOrder[$subFullPath] = 1;
							unset($this->filesPhpDependencies[$subFullPath]);
						}
					}
				}
			}
		}
	}
	private function _getAllRequiresFilesToOrderedRecursive ($requires, & $filesToOrder, $level = 0) {
		foreach ($requires as $require) {
			
			if (!isset($this->filesPhpDependencies[$require])) continue; // file has been ordered by another file
			if (isset($filesToOrder[$require])) continue; // recursive requiring
			$fileItem = $this->filesPhpDependencies[$require];
			if (!$fileItem->requires) continue; // file doesn't require anything
			
			$filesToOrder = array_merge([$require => 1], $filesToOrder);

			$this->_getAllRequiresFilesToOrderedRecursive(
				$fileItem->requires, $filesToOrder, $level + 1
			);
		}
		return $filesToOrder;
	}
	private function _arangeOrderByDependenciesRequiresAndRequiredByData () {
		// complete keys to iterate in current loop
		$keysToIterate = array_keys($this->filesPhpDependencies);
		$keysLength = count($keysToIterate);
		for ($i = 0; $i < $keysLength; $i += 1) {
			$unsafeOrderDetectionRequires = [];
			$fullPath = $keysToIterate[$i];
			// $filesDependenciesItem - current file to process it's order
			$filesDependenciesItem = $this->filesPhpDependencies[$fullPath];
			if (!$filesDependenciesItem->requiresCount) {
				// if file doesn't require any other file - put it into order as it is directly
				$this->filesPhpOrder[$fullPath] = 1;
				unset($this->filesPhpDependencies[$fullPath]);
			} else {
				// if file requires anything - 
				$allRequiresFilesAlreadyOrdered = TRUE;
				// $requiresItem - other file required by current file
				foreach ($filesDependenciesItem->requires as $requiresItem) {
					if (!isset($this->filesPhpOrder[$requiresItem])) {
						// if file what is required by currently processed file has not ordered yet
						if (in_array($requiresItem, $filesDependenciesItem->requiredBy, TRUE)) {
							// if current field requires another file but another file also requires current file - it's unsafe cycle!
							$unsafeOrderDetectionRequires[] = $this->files->all[$requiresItem]->relPath;
						} else {
							// not all required files for current files has been ordered
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
			// add unsafe order detected files into global array to notify developer at the end
			if (count($unsafeOrderDetectionRequires) > 0) {
				$relPath = $this->files->all[$fullPath]->relPath;
				if (!in_array($relPath, $this->unsafeOrderDetection, TRUE)) {
					$this->unsafeOrderDetection[] = $relPath;
				}
				foreach ($unsafeOrderDetectionRequires as $unsafeOrderDetectionRequire) {
					if (!in_array($unsafeOrderDetectionRequire, $this->unsafeOrderDetection, TRUE)) {
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
			ob_start();
			echo '<pre>';
			var_dump($filesFullPaths);
			$filesFullPathsPrinted = ob_get_clean() . '</pre>';
			$this->sendResult(
				"There was not possible to determinate declaration order for files bellow. <br />"
				."Please set order for these files manually by config arrays in keys: <br />"
				."\$config['includeFirst'] = array(...);<br />"
				."\$config['includeLast'] = array(...);",
				$filesFullPathsPrinted, 
				'error'
			);
		}
	}
	private function _orderPhpFilesByPreferredConfiguration ($cfgKey) {
		if (count($this->cfg->$cfgKey) > 0) {
			$filesPhpOrder = $this->filesPhpOrder;
			$fullPathsToReorder = [];
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
