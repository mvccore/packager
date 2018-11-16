<?php

if(!defined('T_TRAIT')) define('T_TRAIT', 362);

class Packager_Php_Scripts_Replacer
{
	protected static $dynamicClassFnDeterminators = [
		'continueTokens'=> [
			T_WHITESPACE=> 1,
			T_COMMENT	=> 1,
		],
		'endingTokens'	=> [
			T_PUBLIC	=> 1,
			T_PROTECTED	=> 1,
			T_PRIVATE	=> 1,
			T_ABSTRACT	=> 1,
			T_FINAL		=> 1,
		],
	];
	protected static $phpFunctionsToProcess = [];
	protected static $wrapperReplacements = [];
	protected static $phpReplacementsStatistics = [];
	protected static $wrapperClassName = '';
	protected static $phpFsMode = '';
	protected $cfg;
	protected $result = '';
	protected $scriptContent = '';
	protected $statementEndOperator = ';';
	protected $fileInfo = NULL;
	protected $tokens = [];
	protected $namespaceState = 0;
	protected $classState = 0;
	protected $classBracketsLevel = 0;
	protected $functionsStates = [0];
	protected $functionsOpenIndexes = [0];
	protected $functionsBracketsLevels = [0];
	public static function SetPhpFunctionsToProcess ($phpFunctionsToProcess = []) {
		self::$phpFunctionsToProcess = $phpFunctionsToProcess;
	}
	public static function SetWrapperReplacements ($wrapperReplacements = []) {
		self::$wrapperReplacements = $wrapperReplacements;
	}
	public static function SetWrapperClassName ($wrapperClassName = '') {
		self::$wrapperClassName = $wrapperClassName;
	}
	public static function SetPhpFsMode ($phpFsMode = '') {
		self::$phpFsMode = $phpFsMode;
	}
	public static function GetReplacementsStatistics () {
		return self::$phpReplacementsStatistics;
	}
	public static function ProcessReplacements (& $fileInfo, & $cfg) {
		$instance = new self($fileInfo, $cfg, token_get_all($fileInfo->content));
		return $instance->runReplacementsProcessing();
	}
	public static function ProcessNamespaces (& $fileInfo, & $cfg) {
		$instance = new self($fileInfo, $cfg, token_get_all("<"."?php\n".$fileInfo->content));
		return $instance->runNamespacesProcessing();
	}
	/* protected *************************************************************************************/
	public function __construct(& $fileInfo, $cfg, $tokens) {
		$this->fileInfo = & $fileInfo;
		$this->cfg = & $cfg;
		$this->tokens = & $tokens;
		$this->result = '';
		$this->classState = 0;
		$this->classBracketsLevel = 0;
		$this->functionsStates = [0];
		$this->functionsBracketsLevels = [0];

		$this->classFnDynamicEnvironment = FALSE;
		$this->classFnDynamicMonitorIndex = -1;

		$this->classFnStaticEnvironment = FALSE;
		$this->classFnStaticMonitorIndex = -1;
	}
	protected function runReplacementsProcessing () {
		$newPart = '';
		for ($i = 0, $l = count($this->tokens); $i < $l;) {
			$token = $this->tokens[$i];
			if (is_array($token)) {
				$tokenId = $token[0];
				$oldPart = $token[1];
				if (isset(self::$wrapperReplacements[$tokenId])) {
					// if there is any part of php code for possible processing:
					list ($i, $newPart) = $this->processPhpCodeReplacement(
						$oldPart, $tokenId, $i
					);
				} else {
					// if there is not any part of php code for possible processing,
					// just add php code part into result code string:
					$newPart = $oldPart;
				}
			} else if (is_string($token)) {
				if ($token == '(') {
					$this->statementEndOperator = ')';
				} else if ($token == ')') {
					$this->statementEndOperator = ';';
				}
				$newPart = $token;
			}
			$this->monitorNamespace($token);
			$this->monitorClass($token, $i);
			$this->monitorFunctions($token, $i);
			$this->result .= $newPart;
			$i += 1;
		}
		return $this->result;
	}
	protected function runNamespacesProcessing () {
		$newPart = '';
		for ($i = 0, $l = count($this->tokens); $i < $l; $i += 1) {
			$token = & $this->tokens[$i];
			if (is_array($token)) {
				$tokenId = $token[0];
				$newPart = $token[1];
				$token[3] = token_name($tokenId);
				if ($tokenId == T_NAMESPACE) {
					if ($this->namespaceState > 0) {
						$this->result .= (!$this->cfg->minifyPhp ? "\n}\n" : '}');
					}
					$this->namespaceState = 1;
				}
				if ($tokenId == T_OPEN_TAG) $newPart = '';
			} else if (is_string($token)) {
				$newPart = $token;
				if ($this->namespaceState == 1 && $token == ';') {
					$newPart = '{';
					$this->namespaceState = 2;
				}
			}
			$this->result .= $newPart;
		}
		if ($this->namespaceState == 2) {
			$this->result .= (!$this->cfg->minifyPhp ? "\n}" : '}');
		}
		return $this->result;
	}
	protected function monitorNamespace ($token) {
		if ($this->namespaceState > 2 || $this->fileInfo->extension !== 'php') return;
		if (is_array($token)) {
			$tokenId = $token[0];
			if ($this->namespaceState == 1 && $tokenId == T_STRING) {
				$this->namespaceState = 2;
			} else if ($tokenId == T_NAMESPACE) {
				$this->namespaceState = 1;
			}
		} else if (is_string($token)) {
			if ($this->namespaceState == 1) {
				if ($token == '{') {
					$this->fileInfo->containsNamespace = Packager_Php::NAMESPACE_GLOBAL_CURLY_BRACKETS;
					$this->namespaceState = 3;
				}
			} else if ($this->namespaceState == 2) {
				if ($token == ';') {
					$this->fileInfo->containsNamespace = Packager_Php::NAMESPACE_NAMED_SEMICOLONS;
					$this->namespaceState = 3;
				} else if ($token == '{') {
					$this->fileInfo->containsNamespace = Packager_Php::NAMESPACE_NAMED_CURLY_BRACKETS;
					$this->namespaceState = 3;
				}
			}
		}
	}
	protected function monitorClass ($token, $currentIndex) {
		if (is_array($token)) {
			$tokenId = $token[0];
			// manage curly brackets (to determinate class closed moment to switch back $this->classState to "0")
			if ($this->classState > 0) {
				if ($tokenId === T_CURLY_OPEN || $tokenId === T_DOLLAR_OPEN_CURLY_BRACES) {
					$this->classBracketsLevel += 1;
				}
			}
			// if token is "class" keyword - open stage
			if ($tokenId === T_CLASS || $tokenId === T_TRAIT) {
				$this->classState = 1;
				$this->classBracketsLevel = 0;
				// prepare bools for class methods
				$this->classFnDynamicEnvironment = FALSE;
				$this->classFnDynamicMonitorIndex = -1;
				$this->classFnStaticEnvironment = FALSE;
				$this->classFnStaticMonitorIndex = -1;
			}
		} else if (is_string($token)) {
			// manage curly brackets (to determinate class closed moment to switch back $this->classState to "0")
			if ($this->classState > 0) {
				if ($token === '{') {
					if ($this->classState === 1) $this->classState = 2;
					$this->classBracketsLevel += 1;
				} else if ($token === '}') {
					$this->classBracketsLevel -= 1;
				}
			}
		}
		// determinate class closed moment - if class state is 2 and curly bracket counters are both 0
		if ($this->classState === 2 && $this->classBracketsLevel === 0) {
			$this->classState = 0;
		}
	}
	protected function monitorFunctions ($token, $currentIndex) {
		$monitorLastIndex = count($this->functionsStates) - 1;
		$functionsStatesLastRec = $this->functionsStates[$monitorLastIndex];
		if (is_array($token)) {
			$tokenId = $token[0];
			// manage curly brackets (to determinate class closed moment to switch back $this->classState to "0")
			if ($functionsStatesLastRec > 0) {
				if ($tokenId === T_CURLY_OPEN || $tokenId === T_DOLLAR_OPEN_CURLY_BRACES) {
					$this->functionsBracketsLevels[$monitorLastIndex] += 1;
				}
			}
			// if token is "function" keyword - open stage
			if ($tokenId === T_FUNCTION) {
				$monitorLastIndex += 1;
				$this->functionsStates[$monitorLastIndex] = 1;
				$this->functionsBracketsLevels[$monitorLastIndex] = 0;
				// determinate if we are in class dynamic function
				$dynamicClassFn = FALSE;
				if ($this->classState > 0) {
					if (!$this->classFnStaticEnvironment && !$this->classFnDynamicEnvironment) {
						$dynamicClassFn = $this->getClassDynamicFunctionEnvironment($currentIndex);
						if ($dynamicClassFn) {
							$this->classFnDynamicEnvironment = TRUE;
							$this->classFnDynamicMonitorIndex = $monitorLastIndex;
						} else {
							$this->classFnStaticEnvironment = TRUE;
							$this->classFnStaticMonitorIndex = $monitorLastIndex;
						}
					}
				} else {
					$this->classFnDynamicEnvironment = FALSE;
					$this->classFnDynamicMonitorIndex = -1;
					$this->classFnStaticEnvironment = FALSE;
					$this->classFnStaticMonitorIndex = -1;
				}
			}
		} else if (is_string($token)) {
			// manage curly brackets (to determinate class closed moment to switch back $this->classState to "0")
			if ($functionsStatesLastRec > 0) {
				if ($token === '{') {
					if ($functionsStatesLastRec === 1) $this->functionsStates[$monitorLastIndex] = 2;
					$this->functionsBracketsLevels[$monitorLastIndex] += 1;
				} else if ($token === '}') {
					$this->functionsBracketsLevels[$monitorLastIndex] -= 1;
				}
			}
		}
		// determinate class closed moment - if class state is 2 and curly bracket counters are both 0
		if ($this->functionsStates[$monitorLastIndex] === 2 && $this->functionsBracketsLevels[$monitorLastIndex] === 0) {
			// manage static vs dynamic class function booleans
			if ($this->classFnDynamicEnvironment && $this->classFnDynamicMonitorIndex === $monitorLastIndex) {
				$this->classFnDynamicEnvironment = FALSE;
				$this->classFnDynamicMonitorIndex = -1;
			}
			if ($this->classFnStaticEnvironment && $this->classFnStaticMonitorIndex === $monitorLastIndex) {

				$this->classFnStaticEnvironment = FALSE;
				$this->classFnStaticMonitorIndex = -1;
			}

			// manage states and brackets records
			if ($monitorLastIndex > 0) {
				unset($this->functionsStates[$monitorLastIndex]);
				unset($this->functionsBracketsLevels[$monitorLastIndex]);
			} else {
				$this->functionsStates[0] = 0;
				$this->functionsBracketsLevels[0] = 0;
			}
		}
	}
	protected function processPhpCodeReplacement ($oldPart, $tokenId, $i) {
		$newPart = '';
		$replacement = self::$wrapperReplacements[$tokenId];
		if (is_callable($replacement)) {
			// if there is configured item in replacements array in type: callable closure function,
			// there will be probably replacements for __FILE__ and __DIR__ replacements,
			// call these functions to process replacements
			$newPart = $replacement($this->fileInfo);
		} else {
			if (self::$phpFsMode == Packager_Php::FS_MODE_STRICT_HDD) {
				$newPart = $oldPart;
			} else {
				if (is_object($replacement)) {
					// if we have current php function call between php replacements
					$newPart = $this->processPhpCodeReplacementObjectType(
						$replacement, $oldPart, $i
					);
				} else if (is_array($replacement)) {
					// if there is configured item in replacements array in type: string,
					// there will be probably replacements for requires and includes or any other php statement
					// determinate if current statement is necessary to process by config
					// and fill $newPart variable with proper content
					list($i, $newPart) = $this->processPhpCodeReplacementArrayType(
						$replacement, $oldPart, $tokenId, $i
					);
				}
			}
		}
		return [$i, $newPart];
	}
	// php function calls - any_php_build_in_function() or programmerCustomFunction()
	// php class names, keywords, stdClass keys, TRUE/FALSE, public constants like PHP_EOL and other php shit...
	protected function processPhpCodeReplacementObjectType ($replacement, $oldPart, $i) {
		$newPart = '';
		if (isset($replacement->$oldPart)) {
			// determinate if current function call is necessary to process by config
			// and fill $newPart variable with proper content
			if (isset(self::$phpFunctionsToProcess[$oldPart])) {
				// yes - by configuration is necessary to replace this php function call - do it
				$newPart = str_replace('%WrapperClass%', self::$wrapperClassName, $replacement->$oldPart);
				// check if before replaced function call is backslash allready and if backslash is also first char in new part
				if (mb_substr(self::$wrapperClassName, 0, 1) == '\\' && $i > 0) {
					$previousToken = $this->tokens[$i - 1];
					if (is_array($previousToken) && $previousToken[1] == '\\') {
						// previous token is already a backslash - remove backslash from new part
						$newPart = mb_substr($newPart, 1);
					}
				}
				self::addToReplacementStatistics($oldPart);
			} else {
				// no - keep original php function call
				$newPart = $oldPart;
			}
		} else {
			// it is other php statement - do not replace anything
			$newPart = $oldPart;
		}
		return $newPart;
	}
	// All occurrences of require_once(), include_once(), require() and include() statements replace by configuration.
	protected function processPhpCodeReplacementArrayType ($replacement, $oldPart, $tokenId, $i) {
		$newPart = '';
		if ($replacement[0] == $oldPart) {
			// determinate if current function call is necessary to process by config
			// and fill $newPart variable with proper content
			$newPart = str_replace('%WrapperClass%', self::$wrapperClassName, $replacement[1]) . '(';
			$subTokens = [];
			$j = $i + 1;
			while (TRUE) {
				$subToken = & $this->tokens[$j];
				if (is_array($subToken)) {
					$subTokenId = $subToken[0];
					$subToken[3] = token_name($subTokenId);
					$subTokens[] = & $subToken;
				} else if (is_string($subToken)) {
					if ($subToken == $this->statementEndOperator) {
						$subInstance = new self($this->fileInfo, $this->cfg, $subTokens);
						$newSubPart = $subInstance->runReplacementsProcessing();
						if ($this->classFnDynamicEnvironment) {
							$newPart .= $newSubPart . ', $this)' . $this->statementEndOperator;
						} else {
							$newPart .= $newSubPart . ')' . $this->statementEndOperator;
						}
						$i = $j;
						break;
					} else {
						$subTokens[] = $subToken;
					}
				}
				$j += 1;
			}
			/*
			echo '<pre>';
			print_r($subTokens);
			echo '</pre>';
			*/
			self::addToReplacementStatistics($oldPart);
		} else {
			// it is other php statement - do not replace anything
			$newPart = $oldPart;
		}
		return [$i, $newPart];
	}
	protected function getClassDynamicFunctionEnvironment ($functionIndex) {
		// go backwards and try to catch T_STATIC or not
		$staticCaught = FALSE;
		$continueTokens = self::$dynamicClassFnDeterminators['continueTokens'];
		$endingTokens = self::$dynamicClassFnDeterminators['endingTokens'];
		for ($i = $functionIndex - 1; $i > -1; $i -= 1) {
			$token = $this->tokens[$i];
			if (is_array($token)) {
				$tokenId = $token[0];
				if (isset($continueTokens[$tokenId])) {
					continue;
				} else if (isset($endingTokens[$tokenId])) {
					break;
				} else if ($tokenId == T_STATIC) {
					$staticCaught = TRUE;
					break;
				} else {
					break;
				}
			} else if (is_string($token)) {
				if ($token == '&') {
					continue;
				} else {
					break;
				}
			}
		}
		return $staticCaught ? FALSE : TRUE;
	}
	protected static function addToReplacementStatistics ($replacementKey) {
		if (!isset(self::$phpReplacementsStatistics[$replacementKey])) {
			self::$phpReplacementsStatistics[$replacementKey] = 0;
		}
		self::$phpReplacementsStatistics[$replacementKey] += 1;
	}
}
