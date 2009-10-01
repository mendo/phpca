<?php
/**
 * Copyright (c) 2009 Stefan Priebsch <stefan@priebsch.de>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *   * Redistributions of source code must retain the above copyright notice,
 *     this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright notice,
 *     this list of conditions and the following disclaimer in the documentation
 *     and/or other materials provided with the distribution.
 *
 *   * Neither the name of Stefan Priebsch nor the names of contributors
 *     may be used to endorse or promote products derived from this software
 *     without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
 * THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
 * PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER ORCONTRIBUTORS
 * BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package    PHPca
 * @author     Stefan Priebsch <stefan@priebsch.de>
 * @copyright  Stefan Priebsch <stefan@priebsch.de>. All rights reserved.
 * @license    BSD License
 */

namespace spriebsch\PHPca;

/**
 * The PHPCA application.
 *
 * @author     Stefan Priebsch <stefan@priebsch.de>
 * @copyright  Stefan Priebsch <stefan@priebsch.de>. All rights reserved.
 */
class Application
{
    /**
     * Version number.
     *
     * @var string
     */
    static public $version = '0.4.5';

    /**
     * Additional paths to load rules from.
     *
     * @var array of string
     */
    protected $rulePaths = array();

    /**
     * All Rule class instances.
     *
     * @var array of Rule
     */
    protected $rules = array();

    /**
     * List of requested rules
     *
     * @var array
     */
    protected $requestedRules = array();

    /**
     * Result object
     *
     * @var Result
     */
    protected $result;

    /**
     * Observer that is notified whenever one file was analyzed
     *
     * @var ProgressPrinterInterface
     */
    protected $progressPrinter;

    /**
     * Number of files that will be analyzed.
     *
     * @var int
     */
    protected $numberOfFiles = 0;

    /**
     * Constructs the object.
     *
     * @return null
     */
    public function __construct()
    {
        $this->configuration = new Configuration();
    }

    /**
     * Converts a rule filename to a rule classname
     * by removing the .php extension and prepending the namespace.
     *
     * @param string $fileName The file name
     * @return string The rule name
     */
    protected function toClassName($fileName)
    {
        return '\\spriebsch\\PHPca\\Rule\\' . substr(basename($fileName), 0, -4);
    }

    /**
     * Check whether given rule was explicitly requested.
     * When no requested rules are given, all rules are considered expected,
     * since no restriction has been specified.
     *
     * @param string $className
     * @return bool
     */
    protected function isRuleRequested($className)
    {
        if (sizeof($this->requestedRules) == 0) {
            return true;
        }

        return in_array($className, $this->requestedRules);
    }

    /**
     * Loads all rules from given directories
     * Note: built-in rules are listed in the $_ClassMap.
     * Returns an array of Rule object instances.
     *
     * @param array of Rule names
     * @return array of Rule
     * @todo handle potential class name conflicts between built-in and custom rules
     */
    protected function loadRules(array $requestedRules = array())
    {
        $builtInRules = $this->listFiles(__DIR__ . '/Rule');
        $rules = $builtInRules;

        foreach ($requestedRules as $rule) {
            $this->requestedRules[] = '\\spriebsch\\PHPca\\Rule\\' . $rule . 'Rule';
        }

        foreach ($this->rulePaths as $path) {
            $rules = array_merge($rules, $this->listFiles($path));
        }

        $result = array();

        foreach ($rules as $rule) {

            $className = $this->toClassName($rule);

            if ($this->isRuleRequested($className)) {
                if (!in_array($rule, $builtInRules)) {
                    require_once $rule;
                    if (!class_exists($className)) {
                        throw new Exception('Additional rule ' . $rule . ' not found');
                    }
                }

                $result[] = new $className;
            }
        }

        return $result;
    }

    /**
     * Enforce all rules on a given file.
     *
     * @param string $fileName Name of the file to analyze
     * @param File   $file     Tokenized representation of the file to analyze
     * @return void
     */
    protected function enforceRules($fileName, File $file)
    {
        if (sizeof($this->rules) == 0) {
            throw new Exception('No rules to enforce');
        }

        foreach ($this->rules as $rule) {
            try {
                // Note: each rule will rewind() $file.
                $rule->check($file, $this->result);
            }

            catch (\Exception $e) {
                // Convert any exceptions inside a rule to a RuleError
                $this->result->addMessage(new RuleError($fileName, 'Rule ' . get_class($rule) . ': ' . $e->getMessage()));
            }
        }
    }

    /**
     * Returns array of all .php filenames in given directory.
     * If $path points to a single file, we do not iterate.
     *
     * @param string $path
     * @return array
     */
    public function listFiles($path, array $extensions = array('php'))
    {
        if (!file_exists($path)) {
            throw new Exception($path . ' not found');
        }

        if (sizeof($extensions) == 0) {
            throw new Exception('No file extensions specified');
        }

        // If $path is a regular file, we are done.
        if (is_file($path)) {

            foreach ($extensions as $extension) {
                if (substr($path, -(strlen($extension) + 1)) == '.' . $extension) {
                    return array($path);
                }
            }

            // File does not have one of the given extensions.
            return array();
        }

        $result = array();

        // Recursively collect all files with given extensions from given directory.
        $it = new PhpFileFilterIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)));

        $it->setExtensions($extensions);

        foreach ($it as $file) {
            $result[] = $file->getPathname();
        }

        return $result;
    }

    /**
     * Add a path to load rules from.
     * No $_ClassMap is required in that directory since additional rules
     * are not autoloaded.
     * Note: All additional rules must be in spriebsch\PHPca\Rule namespace.
     *
     * @param string $path
     * @return void
     */
    public function addRulePath($path)
    {
        if (!file_exists($path)) {
            throw new Exception('The path ' . $path . ' does not exist');
        }

        // Make sure each item of the rule path is unique
        if (!in_array($path, $this->rulePaths)) {
            $this->rulePaths[] = $path;
        }
    }

    /**
     * Return the array of paths where additional rules are loaded from.
     *
     * @return array
     */
    public function getRulePaths()
    {
        return $this->rulePaths;
    }

    /**
     * Register a callback that is notified whenever a file has been processed.
     * Can be used to display a dot, E of F for each processed file in CLI mode.
     *
     * @param ProgressPrinterInterface
     * @return void
     */
    public function registerProgressPrinter(ProgressPrinterInterface $progressPrinter)
    {
        $this->progressPrinter = $progressPrinter;
    }

    /**
     * Returns the number of files to analyze.
     * The value will not be available before run() has been called
     * and should only be used by the progress printer.
     *
     * @return int
     */
    public function getNumberOfFiles()
    {
        return $this->numberOfFiles;
    }

    /**
     * PHPca's main method. Returns a result object holding
     * error and warning messages for all the files that have been analyzed.
     *
     * @param string $pathToPhpExecutable path to PHP executable for lint check
     * @param string $fileOrDirectory     path to file or directory to check
     * @return object
     */
    public function run($pathToPhpExecutable, $fileOrDirectory, Configuration $configuration = null, array $extensions = array('php'), $rules = array())
    {
        if ($pathToPhpExecutable == '') {
            throw new Exception('No path to PHP executable specified');
        }

        if ($fileOrDirectory == '') {
            throw new Exception('No file or directory to analyze');
        }

        if (sizeof($extensions) == 0) {
            throw new Exception('No file extension(s) specified');
        }

        if (!is_null($configuration)) {
            $this->configuration = $configuration;
        }

        // Define our own additionl T_* token constants
        Constants::init();

        // Set up the lint checker and make sure that given path points to a PHP binary
        $linter = new Linter($pathToPhpExecutable);

        // Create result object that collects the error and warning messages
        $this->result = new Result();

        // Create a list of all rules to enforce
        $this->rules = $this->loadRules($this->configuration->getRules());

        // List all PHP files in given path
        $phpFiles = $this->listFiles($fileOrDirectory, $this->configuration->getExtensions());

        if (sizeof($phpFiles) == 0) {
            throw new Exception('No PHP files to analyze');
        }

        $this->numberOfFiles = sizeof($phpFiles);

        foreach ($phpFiles as $phpFile) {

            // Remember that we have processed this file,
            // even if it generates no message at all.
            $this->result->addFile($phpFile);

            if ($linter->runLintCheck($phpFile)) {
                $file = Tokenizer::tokenize($phpFile, file_get_contents($phpFile));

                $this->result->addNamespaces($phpFile, $file->getNamespaces());
                $this->result->addClasses($phpFile, $file->getClasses());
                $this->result->addFunctions($phpFile, $file->getFunctions());

                $this->enforceRules($phpFile, $file);
            } else {
                $this->result->addMessage(new LintError($phpFile, $linter->getErrorMessages()));
            }

            // Notify the progress printer that we have analyzed a file
            if (is_object($this->progressPrinter)) {
                $this->progressPrinter->showProgress($phpFile, $this->result, $this);
            }

            unset($phpFile);
        }

        // Return the result object containing all error and warning messages
        return $this->result;
    }
}
?>