<?php
# Author: Tomáš Švondr
# xlogin: xsvond00
#######################
class testSet{
    public $path = '';
    public $results_int = [];
    public $results_parse =[];
    public function __construct($path){
        $this->path = $path;
    }
}
class Result{
    public $type;
    public $status;
    public $basename;
    public $expected;
    public $returned;
    public function __construct($status, $basename, $expected, $code, $type){
        $this->status = $status;
        $this->basename = $basename;
        $this->expected = $expected;
        $this->returned = $code;
        $this->type = $type;
    }
}

class TestConfig{
    private $tested=0;
    private $passed=0;
    private $flags;
    private $directory = 'ipp-2022-tests';
    private $parse_script = 'parse.php';
    private $int_script = 'interpret.py';
    private $type = 'both';
    private $recursive = '-maxdepth 1';
    private $noclean = false;
    private $tests = [];
    private $results = [];
    public $lastResult;
    private function help(){
        print("Usage: php test.php [OPTIONS]\n");
        print("Options:\n 
        [--help] -- prints out usage information\n
        [--parse-only] -- runs tests for parser only\n
        [--int-only] -- runs tests for interpret only\n
        [--directory=DIRECTORY] -- directory with tests\n
        [--parse-script=SCRIPT] -- path to parse.php\n
        [--int-script=SCRIPT] -- path to interpret.php\n
        [--recursive] -- runs test through every subdirectory in the directory\n");
        exit(0);
    }
    public function parseArgs($argv){ #zpracování argumentů programu
        foreach($argv as $arg){
            if(preg_match('/^--directory=(.*)$/', $arg, $matches)){
                $this->directory = $matches[1];
            }
            elseif(preg_match('/^--parse-script=(.*)$/', $arg, $matches)){
                $this->parse_script = $matches[1];
            }
            elseif(preg_match('/^--int-script=(.*)$/', $arg, $matches)){
                $this->int_script = $matches[1];
            }
            elseif(preg_match('/^--int-only(.*)$/', $arg, $matches)){
                if($this->type == 'parse'){
                    print("--int-only and --parse-only are mutually exclusive\n");
                    $this->help();
                }
                $this->type = 'inter';
            }
            elseif(preg_match('/^--parse-only(.*)$/', $arg, $matches)){
                if($this->type != 'inter'){
                    print("--int-only and --parse-only are mutually exclusive\n");
                    $this->help();
                }
                $this->type = 'parse';
            }
            elseif(preg_match('/^--help$/', $arg, $matches)){
                $this->help();
            }
            elseif(preg_match('/^--recursive$/', $arg, $matches)){
                $this->recursive = '';
            }
            else{
                print("Unknown argument: $arg\n");
                $this->help();
            }
            if(!file_exists($this->directory)){
             print("Directory does not exist\n");
             exit(1);
            }
            if(!file_exists($this->parse_script)){
             print("Parser script does not exist\n");
             exit(1);
            }
            if(!file_exists($this->int_script)){
             print("Interpreter script does not exist\n");
             exit(1);
            }
            
        }

    }
    public function getTests(){ #prohledá zadaný adresář a najde všechny zdrojové soubory testů
        exec("find $this->directory $this->recursive -type f -name \"*.src\"",$this->tests, $return);
        if($return != 0){
            print("couldn't reach the directory\n");
            exit(41);
        }
        if(count($this->tests)==0){
            print("no tests found\n");
            exit(0);
        }
    }
    public function runTests(){ #spoustí testy
  
        foreach ($this->tests as $test){
            $expected = 0;
            $basename = preg_replace('/.src$/', '', $test);
            $dirname = preg_replace('/[^\/]*$/','',$basename);
            if (!$this->lastResult){                            #
                $this->lastResult = new testSet($dirname);      #
            }elseif($this->lastResult->path != $dirname){       # při vstupu do nového adresáře s novými testy se vytvoří objekt
                array_push($this->results, $this->lastResult);  # obsahující pro zapisování výsledků testů
                $this->lastResult = new testSet($dirname);      #
            }
            print("------------------------------------------------------------------------------------\n");
            print($basename."\n");
            print("------------------------------------------------------------------------------------\n");
            if(file_exists("$basename.rc")){ # kontrola existujícího souboru s předpokládanou návratovou hodnotou
                $content = file_get_contents("$basename.rc");
                if($content === false){
                    print("couldn't get file content");
                    exit(11);
                }
                $expected = intval($content, 10);
            }
            if($this->type == 'both' || type == 'parse'){
                $this->testParser($basename, $expected);
            }
            if($this->type == 'both' || type == 'inter'){
                $this->testInterpret($basename, $expected);
            }
       }
       array_push($this->results,$this->lastResult);
    }
    function testInterpret($basename, $expected){ #testování interpretu
        $extention = file_exists("$basename.parsed")? "parsed":"src";
        if((file_exists("$basename.$extention")) && file_exists("$basename.in")){
            exec("python3 $this->int_script --source=$basename.$extention --input=$basename.in > $basename.tout",$outmsg, $code);
            $passed = false;
            if($code == $expected){
                $passed = true;
            }
            if($code == 0 and $expected == 0){
                exec("diff $basename.out $basename.tout", $out,$ret);
                if($ret == 0){
                    $passed = true;
                }else{
                    $passed = false;
                }
            }
            $this->tested += 1;
            if($passed == 'passed'){
                $this->passed += 1;
            }
            $result = new Result($passed?'passed':'failed', $basename, $expected, $code, 'interpret');
            array_push($this->lastResult->results_int,$result);
            
        }
    }
    function testParser($basename, $expected){ #testy pro parser
        if((file_exists("$basename.src"))){
            exec("php $this->parse_script <$basename.src > $basename.parsed",$outmsg, $code);
            if($this->type == 'both'){ # předejití špatně vyhodnocenému testu -- za předpokladu, že návratový kód v souboru je určen pro interpret
                return;
            }
            $dirname = preg_replace('/[^\/]*$/','',$basename);
            $passed = false;
            if($code == $expected){
                $passed = true;
            }
            if($code == 0 and $expected == 0){
                exec("diff $basename.out $basename.parsed", $out,$ret);
                if($ret == 0){
                    $passed = true;
                }else{
                    $passed = false;
                }
            }
            $this->tested += 1;
            if($passed == 'passed'){
                $this->passed += 1;
            }
            $result = new Result($passed?'passed':'failed', $basename, $expected, $code, 'parser');
            array_push($this->lastResult->results_parse,$result);
        
        }
    }
    public function BuildDoc(){ #tvorba html dokumentu pro vypsání výsledků testu
        $file = fopen("test.html", 'w');
        $interpretHtml = "<div class=\"card\">";
        $parserHtml=$interpretHtml;
        $content = "<!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <meta http-equiv='X-UA-Compatible' content='IE=edge'>
            <title>Page Title</title>
            <meta name='viewport' content='width=device-width, initial-scale=1'>
            <style>
            body {
                background-color: #313244;
                padding: 20px;
            }
    
            .card {
                width: auto;
                height: 100%;
                color: rgb(203, 214, 225);
                background-color: #1f202ccc;
                border-radius: 5px;
                padding: 10px;
                margin: 5px;
                border: 2px solid rgb(203, 214, 225);
            }
    
            .card H1 {
                font-family: 'Courier New', Courier, monospace;
                font-size: 20px;
                font-weight: 400;
            }
            .test{
                width: auto;
                border: 2px dashed black;
                padding: 15px;
                font-family: 'Courier New', Courier, monospace;
            }
            .test:nth-child(odd){
                background-color: #1f202cfe;
            }
            .test span{
                text-transform: uppercase;
            }
            .test.passed span{
                color: rgb(54, 175, 3);
            }
            .test.failed span{
                color:crimson;
            }
            .overview{
                color: aliceblue;
                font-family: 'Courier New', Courier, monospace;
                font-size: 30px;
            }
            .overview p{
                font-size: 15px;
               
            }
        </style>
            </head>
        <body>
        <div class=\"overview\">Test overview:
        <p>total tests: <b>$this->tested</b></p>
        <p>total tests passed: <b>$this->passed</b></p>
        <p>percentage: <b>".(intval($this->passed/$this->tested*100))."%</b></div>";

        foreach($this->results as $test){
            $interpretHtml.="<div class=\"testDirectory\"><H1>$test->path</H1>" ;
            $parserHtml .="<div class=\"testDirectory\"><H1>$test->path</H1>" ;
            foreach($test->results_int as $t){
                $interpretHtml.="<div class=\"test $t->status\">$t->basename -- <span><b>$t->status!</b></span> returned <b>$t->returned</b> and <b>$t->expected</b> was expected</div>";
            }
            foreach($test->results_parse as $t){
                $parserHtml.="<div class=\"test $t->status\">$t->basename -- <span><b>$t->status!</span> returned <b>$t->returned</b> and <b>$t->expected</b> was expected</div>";
            }
            $interpretHtml.="</div>";
            $parserHtml.="</div>";
        }
        $interpretHtml.="</div>";
        $parserHtml.="</div>";
        if($this->type == 'both' || $this->type == 'inter')
       {$content.= $interpretHtml;}
       if($this->type == 'parse')
        {$content.=$parserHtml;}
        $content.="</body></html>";
        fwrite($file,$content);
        print($content);
        fclose($file);
    }
}
$Tests = new TestConfig();
$Tests->parseArgs(array_slice($argv,1));
$Tests->getTests();
$Tests->runTests();
$Tests->BuildDoc();
?>