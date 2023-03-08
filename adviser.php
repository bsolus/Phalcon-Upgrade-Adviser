<?php

class Adviser
{
    private $path;
    private $phalconClasses;
    private $logFile;

    public function __construct(array $phalconClasses, string $path, string $logFile) {
        $this->phalconClasses = $phalconClasses;
        $this->path = $path;
        $this->logFile = (empty($logFile)) ?  "upgradeLog.txt" : $logFile;
    }
    
    public function createLogAction()
    {
        if (empty($this->path)) {
            die("Please provide the path to the Application");
        }

        if (is_file($this->path)) {
            die($this->logPhalconClassesState($this->path));
        }

        $phpFiles = [];

        $this->getPhpFiles($this->path, $phpFiles);
        
        if (empty($phpFiles)) {
            die("No PHP files found in $this->path");
        }

        $log = $this->processPhpFiles($phpFiles);

        file_put_contents($this->logFile, $log);

        echo "Check '$this->logFile' to review the necessary changes to upgrade\n";

    }

    private function processPhpFiles(array $files): string
    {
        $log = "";

        foreach ($files as $file) {
            $log .= $this->logPhalconClassesState($file);
        }

        return $log;
    }

    private function getPhpFiles(string $dir, array &$phpFiles = [])
    {
        $files = scandir($dir);

        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);

            if (is_file($path)) {
                if (pathinfo($path, PATHINFO_EXTENSION) === "php") {
                    $phpFiles[] = $path;
                }
            } else if ($value != "." && $value != ".." && $value != "vendor" && $value != ".git") {
                $this->getPhpFiles($path, $phpFiles);
                if (pathinfo($path, PATHINFO_EXTENSION) === "php") {
                    $phpFiles[] = $path;
                }
            }
        }
    }

    private function logPhalconClassesState(string $file): string
    {       
        try {
            $fn = fopen($file, "r+");
        } catch (exception $e) {
            return "Error opening $file => " . $e->getMessage() . ";\n";
        }       

        $classes = [];

        $new_file = "";
        $replaced = false;

        while(! feof($fn)) {
            $content = fgets($fn);
            if (preg_match("/Phalcon\\\([^\s;(]+)/", $content, $match)) {
                $classes[] = $match[0];

                if(!isset($this->phalconClasses[$match[0]])){
                    $new_file .= $content;
                    continue;
                }

                $start_string = substr($this->phalconClasses[$match[0]], 0, 6);
                if ($start_string === "Rename" || $start_string === "Change") {
                    $new_file .= $this->applyChanges($this->phalconClasses[$match[0]], $content);
                    $replaced = true;
                    continue;
                }else{
                    $new_file .= str_replace("\n", " // TODO - " . $this->phalconClasses[$match[0]] . "\n", $content );
                    $replaced = true;
                    continue;
                }
            }

            $new_file .= $content;
        }

        if($replaced){
            file_put_contents($file, $new_file);
        }

        if (count($classes) == 0) {
            return "";
        }

        return $this->checkClassState($classes, $file);
    }

    private function applyChanges($changes, $content)
    {
        $changes = explode(" | ", $changes);
        $new_class =  str_replace("`", "", $changes[1]);
        $replaced_content = preg_replace("/Phalcon\\\([^\s;(]+)/", $new_class, $content);
        return isset($changes[2]) ? str_replace("\n", " // TODO - " . $changes[2] . "\n", $replaced_content) : $replaced_content;
    }

    private function checkClassState(array $classes, $file):string
    {
        $log = "";

        foreach ($classes as $class) {
            if (isset($this->phalconClasses[$class])) {
                $log .= $class . " => " . $this->phalconClasses[$class] . "\n";
            } elseif (strpos($class, "::") > 0) {
                $log .= $class . " => Check possible changes in constant\n";
            } else {
                //$log .= $class . " => No changes\n";
            }
        }

        return !$log ? "" : $file . ":\n" . $log . "\n\n";
    }
}