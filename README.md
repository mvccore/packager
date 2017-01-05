# packager

## Main Features
- pack any application into PHAR archive
- pack any MvcCore application into single PHP file

## Configuration possibilities
- directory with whole app source to pack
- result index.php file where to store packed result
- no needs to define PHP scripts order anymore - automatic order detecting by reading PHP scripts
- exclude patterns by regular expressions to exclude any files or folders from app source
- string replacements applied on every packed PHP file before minimalization
- minimalizing PHTML templates
- minimalizing PHP scripts
- PHP packing has now 4 options, how implemented file system wrapping functions could behave:
  - strict package mode (Packager_Php::FS_MODE_STRICT_PACKAGE)
    (everything is only possible to get from index.php, very fast for specific application types in IIS/PHP/op_cache)
  - strict hard drive mode (Packager_Php::FS_MODE_STRICT_HDD)
    (no file system wrapping functions)
  - preserve php package mode (Packager_Php::FS_MODE_PRESERVE_PACKAGE)
    (first there is check if it is possible to get anything from index.php, then from hard drive)
  - preserve hard drive mode (Packager_Php::FS_MODE_PRESERVE_HDD)
    (first there is check if it is possible to get anything from hard drive, then from index.php)
- there are implemented those file system wrapping functions and constants:
  - \__DIR__ and \__FILE__
  - require_once(), _include_once(), require(), include()
  - new DirectoryIterator(), new SplFileInfo(), 
  - readfile(), file_get_contents()
  - file_exists(), filemtime(), filesize()
  - simplexml_load_file(), parse_ini_file(), md5_file()
- possibility to define which file system wrapping functions should be keeped and not wrapped
- for PHP packing - possibility to define files by extension how to store them inside index.php result
  - pure text
  - php code
  - binary
  - gzipped content
  - base64 encoded content

## Examples
- [**Example Hallo World (mvccore/example-helloworld)**](https://github.com/mvccore/example-helloworld)
- [**Example Pig Latin Translator (example-translator)**](https://github.com/mvccore/example-translator)
- [**Example CD Collection (mvccore/example-cdcol)**](https://github.com/mvccore/example-cdcol)
- [**Application Questionnaires (mvccore/app-questionnaire)**](https://github.com/mvccore/app-questionnaire)
