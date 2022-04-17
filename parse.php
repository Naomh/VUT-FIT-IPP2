<?php
/*
*   Autor: Tomáš Švondr
*   xlogin: xsvond00
*
************************************************************/

/*
* třída Printerror zajišťuje obsluhu chybových stavů. Každá funkce vypisuje na stderr chybovou hlášku a ukončí program s příslušným chobovým kódem 
*/
class Printerror { 
  
    protected function err_header(){
        error_log("ERR 21 - chybná nebo chybějící hlavička ve zdrojovém kódu zapsaném v IPPcode22");
        exit(21);
    }
    
    protected function err_opcode(){
        error_log("ERR 22 - neznámý nebo chybný operační kód ve zdrojovém kódu zapsaném v IPPcode22");
        exit(22);
    }
    
    protected function err_code(){
        error_log("ERR 23 - jiná lexikální nebo syntaktická chyba zdrojového kódu zapsaného v IPPcode22");
        exit(23);
    }
    
    protected function err_param(){
        error_log("ERR 10  - chybějící parametr skriptu (je-li třeba) nebo použití zakázané kombinace parametrů");
        exit(10);
    }
    
    protected function err_input(){
        error_log("ERR 11  - chyba při otevírání vstupních souborů (např. neexistence, nedostatečné oprávnění)");
        exit(11);
    }
    
    protected function err_output(){
        error_log("ERR 12  - chyba při otevření výstupních souborů pro zápis (např. nedostatečné oprávnění, chybapři zápisu)");
        exit(12);
    }
    
    protected function err_internal(){
        error_log("ERR 99  - interní chyba (neovlivněná vstupními soubory či parametry příkazové řádky; např. chybaalokace paměti)");
        exit(99);
    }
}

/*
* třída XmlBuilder obstarává generování dokumentu xml přes DOM
*/
class XmlBuilder{
    private $xml;
    private $order=1;
    private $argcount;

    // constructor<void> - inicializuje počítadla a vytváří DOM dokument
    public  function __construct(){
        $order = 1;
        $argcount = 1;
        $this->xml = new DOMDocument('1.0', 'UTF-8'); 
    }
    //isVar<bool> - porovnává argument funkce s regulárním výrazem pro proměnné
    private function isVar($string){
        return preg_match("/(LF|TF|GF)@([a-z|A-Z|_|-|$|&|%|*|!|?]|[a-z|A-Z|_|-|$|&|%|*|!|?][a-z|A-Z|0-9|_|-|$|&|%|*|!|?])+$/",$string);
    }
    //escapeSymbols<string> - nahrazuje některé znaky z původního znakového řetězce za jejich escape kódy a kontroluje validitu escape kódu ze stringu
    private function escapeSymbols($string){
        $symbols = array('&' => '&amp;', '"' => '&quot;', '\'' => '&apos;', '<' => '&lt;', '>' => '&gt;');
        $offset = 0;
        return strtr($string,$symbols);
       /* while(($pos = strpos($string, "\\", $offset))!==false && pos < sizeof($string)){
           $number = substr( $string , $pos+1 , 3 );
           if(!preg_match("/[0-9][0-9][0-9]/",$number)){
            exit(23);
           }
           $offset = $pos+3;
        }*/
        
    }
    //newInstructionElement<DOMElement> generuje XML element pro Instrukce
    public function newInstructionElement($opcode){
        $this->argcount = 1;
        $element = $this->xml->createElement('instruction');
        
        $orderAttr = $this->xml->createAttribute('order');
        $orderAttr->value = $this->order;
        $element->appendChild($orderAttr);
        $this->order = $this->order + 1;

        $opcodeAttr = $this->xml->createAttribute('opcode');
        $opcodeAttr->value = $opcode;
        $element->appendChild($opcodeAttr);
        return $element;
    }
    //newVariableElemnt<DOMElement> generuje XML element pro proměnné
    public function newVariableElement($name){
        $name=$this->escapeSymbols($name);
        $element = $this->xml->createElement('arg'.$this->argcount, $name);
        $type = $this->xml->createAttribute('type');
        $type->value = count(explode('@',$name)) == 2? 'var' : 'label';
        $element->appendChild($type);
        $this->argcount++;
        return $element;
    }
    // newSymbolElement<DOMElement> generuje XML element pro symboly
    public function newSymbolElement($string){
        if($this->isVar($string)){
            return $this->newVariableElement($string);
        }
        $name = explode('@',$string);
        $value = array_shift($name);
        if($value === 'string'){
            $name = $this->escapeSymbols($name[0]);
        }else{
            $name = implode($name);
        }
        $element = $this->xml->createElement('arg'.$this->argcount, $name);
        $type = $this->xml->createAttribute('type');
        $type->value = $value;
        $element->appendChild($type);
        $this->argcount++;
        return $element;
    }
    //new TypeElement<DOMElement> generuje xml element pro datové typy
    public function newTypeElement($string){
        $element = $this->xml->createElement('arg'.$this->argcount, $string);
        $type = $this->xml->createAttribute('type');
        $type->value = 'type';
        $element->appendChild($type);
        $this->argcount++;
        return $element;
    }
    // newLabelElement<DOMElement> generuje xml element pro label
    public function newLabelElement($string){
        $element = $this->xml->createElement('arg'.$this->argcount, $string);
        $type = $this->xml->createAttribute('type');
        $type->value = 'label';
        $element->appendChild($type);
        $this->argcount++;
        return $element;
    }
}
// Cílem třídy InstructionAnayzer je analyzovat vstupní kód a sestavit odpovídající XML dokument
class InstructionAnalyzer{
    private $builder; 
    //constructor<void> - inicializuje třídu XmlBuilder
    public function __construct(){
        $this->builder = new XmlBuilder();
    }
    //isVar<bool> - porovnává argument funkce s regulárním výrazem pro proměnnou
    private function isVar($string){
        return preg_match("/^(LF|TF|GF)@([a-z|A-Z|_|-|$|&|%|*|!|?])+$/",$string);
    }
    //isLabel<bool> - porovnává argument funkce s regulárním výrazem pro label
    private function isLabel($string){
        return preg_match("/^([^@]|[|a-z|A-Z|_|-|$|&|%|*|!|?])*$/",$string);
    }
    //isSymbol<bool> - rozlišuje, zdali se jedná o Symbol nebo proměnnou a validuje jejich hodnoty
    private function isSymbol($string){
        if($this->isVar($string)){
            return true;
        } else {
          $value = explode('@',$string);
          $type = array_shift($value);
          $value = implode($value);
          switch($type){
              case "int":
                return (intval($value, $base = 0) !== 0 || $value=="0");
              case "string":
                return preg_match("/^([^\\\]*((\\\([0-9][0-9][0-9]))*))*$/",$value);
              case "float":
                return is_float($value);
              case "bool":
                return  ($value === "true" || $value === "false");
              case "nil":
                return $value === "nil";
              default:
              return false;
          }
        }
    }
// twoOperandInstruction<DOMElement|string> - funkce buduje xml pro instrukce pracující s 1 proměnnou a jedním Symbolem/proměnnou
    private function twoOperandInstruction($instruction, $parameters){
        if (count($parameters) === 2){
            if ($this->isVar($parameters[0]) && $this->isSymbol($parameters[1])){
                $element = $this->builder->newInstructionElement($instruction);
                //argument 1
                $arg = $this->builder->newVariableElement($parameters[0]);
                $element->appendChild($arg);
                //argument 2
                $arg = $this->builder->newSymbolElement($parameters[1]);
                $element->appendChild($arg);
                return $element;
            } 
            else return 'bad_type'; 
        }
        else return 'bnp'; // bad numbers of parameters
    }
    // noParameterInstruction<DOMElement | string> - buduje xml pro instrukce bez parametrů
    private function noParameterInstruction($instruction, $parameters){
        if($parameters){
            return 'bnp';
        }
        return $this->builder->newInstructionElement($instruction);
    }
    // oneParameterInstruction<DOMElement | string> - buduje xml pro instrukce pracující s jednou proměnnou
    private function oneParameterInstruction($instruction, $parameters){
        if(count($parameters)!==1){
            return 'bnp';
        }
        if(!$this->isVar($parameters[0])){
            return 'bad_type';
        }
        $element = $this->builder->newInstructionElement($instruction);
        //argument 1
        $arg = $this->builder->newVariableElement($parameters[0]);
        $element->appendChild($arg);
        return $element;
    }
    // labelInstruction<DOMElement | string> - buduje xml pro instrukci LABEL
    private function labelInstruction($instruction,$parameters){
        if(count($parameters) !== 1){
            return 'bnp';
        }
        if(!$this->isLabel($parameters[0]) || $this->isSymbol($parameters[0])){
            return 'bad_type';
        }
        $element = $this->builder->newInstructionElement($instruction);
        //argument 1
        $arg = $this->builder->newLabelElement($parameters[0]);
        $element->appendChild($arg);
        return $element;
    }
    // oneSymbolInstruction<DOMElement | string> - buduje xml pro instrukce pracující s jednou proměnnou/symbolem
    private function oneSymbolInstruction($instruction, $parameters){
        if(count($parameters) !== 1){
            return "bnp";
        }elseif(!$this->isSymbol($parameters[0])){
            return "bad_type";
        }
        $element = $this->builder->newInstructionElement($instruction);
        //argument 1
        $arg = $this->builder->newSymbolElement($parameters[0]);
        $element->appendChild($arg);
        return $element;
    }
    //variable2SymbolInstruction<DOMElement | string> - buduje xml pro instrukce pracující s jednou proměnnou a 2 symboly
    private function variable2SymbolInstruction($instruction, $parameters){
        if(count($parameters)!== 3 ){
            return "bnp";
        }elseif(!$this->isVar($parameters[0]) || !$this->isSymbol($parameters[1]) || !$this->isSymbol($parameters[2])){
            return "bad_type";
        }
            $element = $this->builder->newInstructionElement($instruction);
            //argument 1
            $arg = $this->builder->newVariableElement($parameters[0]);
            $element->appendChild($arg);
            //argument 2
            $arg = $this->builder->newSymbolElement($parameters[1]);
            $element->appendChild($arg);
            //argument 3
            $arg = $this->builder->newSymbolElement($parameters[2]);
            $element->appendChild($arg);
            return $element;
    }
    // jumpIF<DOMElement | string> - buduje xml pro instrukce podmíněných skoků
    private function jumpIF($instruction, $parameters){
        if (count($parameters) !== 3){ 
                return "bnp";
        }
        if ($this->isSymbol($parameters[0]) || !$this->isLabel($parameters[0]) || !$this->isSymbol($parameters[1]) || !$this->isSymbol($parameters[2])){
                return "bad_type";
        }
        $element = $this->builder->newInstructionElement($instruction);
        $arg = $this->builder->newLabelElement($parameters[0]);
        $element->appendChild($arg);

        $arg = $this->builder->newSymbolElement($parameters[1]);
        $element->appendChild($arg);
        
        $arg = $this->builder->newSymbolElement($parameters[2]);
        $element->appendChild($arg);

        return $element;
    }
    // read<DOMElement | string> - buduje xml pro instrukci read
    private function read($instruction, $parameters){
      
        if(count($parameters) !== 2){
            return "bnp";
        }
       
        if(!$this->isVar($parameters[0])){
            return 'bad_type';
        }
            switch($parameters[1]){
                case "int":
                case "bool":
                case "string":
                case "float":
                break;
                default:
                return "bad_type";
                }
       $element = $this->builder->newInstructionElement($instruction);
       $arg = $this->builder->newVariableElement($parameters[0]);
       $element->appendChild($arg);

       $arg = $this->builder->newTypeElement($parameters[1]);
       $element->appendChild($arg);
       return $element;
    }
    // classify<DOMElement | string> - klasifikuje instrukce podle jejich názvu 
    public function classify($instruction, $parameters){
        switch ($instruction){       
            case 'MOVE':
            case 'INT2CHAR':
            case 'STRLEN':
            case 'NOT':
            case 'TYPE':            
                return $this->twoOperandInstruction($instruction, $parameters);            
            break;
    
            case 'CREATEFRAME':
            case 'PUSHFRAME':
            case 'POPFRAME':
            case 'RETURN':
            case 'BREAK':           
                return $this->noParameterInstruction($instruction, $parameters);
            break; 
    
            case 'DEFVAR':
            case 'POPS':
                return $this->oneParameterInstruction($instruction, $parameters);
            break;
    
            case 'CALL':
            case 'LABEL':
            case 'JUMP':            
                return $this->labelInstruction($instruction, $parameters);
            break;
    
            case 'PUSHS':
            case 'WRITE':
            case 'EXIT':
            case 'DPRINT':
                return $this->oneSymbolInstruction($instruction, $parameters);
            break;
    
            case 'ADD':
            case 'SUB':
            case 'MUL':
            case 'IDIV':
            case 'STRI2INT':
            case 'CONCAT':
            case 'GETCHAR':
            case 'SETCHAR':        
            case 'LT':
            case 'GT':
            case 'EQ':
            case 'AND':
            case 'OR':
                return $this->variable2SymbolInstruction($instruction, $parameters);
            break;
    
            case 'JUMPIFEQ':
            case 'JUMPIFNEQ':
                return $this->JumpIf($instruction, $parameters);
            break;
    
            case 'READ':
                return $this->read($instruction, $parameters);
            break;
    
            case "\0":
            case "\n":
            case '':
            case "": 
            return 'skip';
            break;  
    
            default:
                return false;           
            break;
        }
        return;
    }
}

// třída Program je základní třídou programu 
class Program extends Printerror{

    // constructor<void> - náhrada funkce main - zde probíhá kontrola headeru, analýza instrukcí a vzniká tu i finální podoba xml 
public function __construct($argc, $argv){
    $this->checkArgs($argc, $argv);
    $this->checkHeader();
    $analyzer = new InstructionAnalyzer();
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->formatOutput = true;
    $root = $document->createElement('program');
    $rootLang = $document->createAttribute('language');
    $rootLang->value='IPPcode22';
    $root->appendChild($rootLang);
    $document->appendChild($root);
    while($line = fgets(STDIN)){
    $parameters = $this->decompose($line);
    $token = strtoupper(array_shift($parameters));
    $result = $analyzer->classify($token, $parameters);
    switch ($result){
        case 'skip':
            break;
        case false:
            $this->err_opcode();
            break;
        case "bnp":
            $this->err_code();
            break;
        case "bad_type":
            $this->err_code();
            break;
        default:
            if($result)
            if ($importedNode = $document->importNode($result, true)) {
                $root->appendChild($importedNode);
            }
        break;
    }
        
    }
    $document->appendChild($root);
    $document->save("php://stdout");
    exit(0);
}

//decompose<string[]> - odstranění komentářů a přebytečných mezer, rozdělení na předpokládané instrukce a jejich operandy
private function decompose($line){
    $line = trim(explode('#', $line)[0]);
    $line = preg_replace('!\s+!', ' ', $line);
    return explode(' ', $line);

}
//checkArgs<void> - kontrola argumentů programu
Private function checkArgs($argc, $argv){
    if($argc > 1){
        if($argv[1] === "--help" && $argc < 3){
        $this->printHelp();
        }else{
            $this->err_param();
        }
    }
return;
}
//check header<true> - kontroluje správnost hlavičky u programu 
private function checkHeader(){
while($line = fgets(STDIN)){
    $line = trim(explode('#',$line)[0]);
    if($line === '.IPPcode22'){
        return true;
    }
    if($line !== ''){
        $this->err_header();
    }
}
$this->err_header();
}
//printHelp<void> - vytiskne nápovědu k obsluze programu
private function printHelp(){
    echo "spusteni programu provedte prikazem:\nphp parse.php <input.ippcode22\n";
    exit(0);
}
}

ini_set('display_errors','STDERR');
$PROGRAM = new Program($argc, $argv);
?>