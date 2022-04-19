###
 # IPP project 2
 # 
 # author:	Tomáš Švondr
 # xlogin:  xsvond00
 #
 #


from sys import stdin, stderr
import argparse
import re
import xml.etree.ElementTree as ET

from numpy import interp

#Třída na zpracování argumentů
class Error_class():
    def __init__(self):
        pass
    exit_codes = {
        10: 'Error 10: Chybějící parametr skriptu nebo použití zakázané kombinace parametrů',
        11: 'Error 11: Chyba při otevírání vstupních souborů (např. neexistence, nedostatečné oprávnění)',
        12: 'Error 12: Chyba při otevření výstupních souborů pro zápis (např. nedostatečné oprávnění, chyba při zápisu)',
        99: 'Error 99: Interní chyba (např. chyba alokace paměti)',
        31: 'Error 31: Chybný XML formát ve vstupním souboru (soubor není tzv. dobře formátovaný)',
        32: 'Error 32: Neočekávaná struktura XML (např. element pro argument mimo element pro instrukci, instrukce s duplicitním pořadím nebo záporným pořadím)',
        52: 'Error 52: Duplicitní deklarace proměnné',
        55: 'Error 55: Chybný název rámce',
        53: 'Error 53: Chybný typ argumentu',
        57: 'Error 57: Chybná hodnota argumentu',
        58: 'Error 58: Zadaný hodnota argumentu není v povoleném rozsahu',
        56: 'Error 56: Zásobník je prázdný',
    }
    def exit_code(self, error_number):
        print(self.exit_codes.get(error_number, 'Neznámá chyba'), file=stderr)
        exit(error_number)
    
    def invalidFrame(self):
        self.exit_code(55)

    def invalidType(self):
        self.exit_code(53)

    def invalidValue(self):
        self.exit_code(57)

    def excludedValue(self):
        self.exit_code(58)

    def invalidXML(self):
        self.exit_code(31)

    def duplicateVariable(self):
        self.exit_code(52)

    def variableNotFound(self):
        self.exit_code(54)

    def unset(self):
        self.exit_code(56)
def label_check(name, type):
    error = Error_class()
    if type != 'label' or name[:1].isdigit():
        error.exit_code(31)
    for char in name:
        if char.isspace() or not(char.isalnum() or char == '_' or char == '&' or char == '-' or char == '%' or char == '$' or char == '*'):
            error.exit_code(31)

#Funkce na kontrolu XML formátu
class Frame():
    def __init__(self):
        self.error = Error_class()
        self.variables = {}
    
    def addVariable(self, name):
        if self.variables.get(name, 'Not Found') != 'Not Found':
            self.error.duplicateVariable() # duplicitni promenna
        else:
            self.variables[name] = {"type": None, "value": None}
    
    def changeValue(self, name, datatype, value):
        if self.variables.get(name, 'Not Found') == 'Not Found':
            self.error.variableNotFound() # promenna neexistuje
        else:
            self.variables[name] = {"type": datatype, "value": value}

    def getValueOf(self, name):
        if self.variables.get(name, 'Not Found') == 'Not Found':
            self.error.variableNotFound() # promenna neexistuje
        else:
            return self.variables[name]
    
    def getListOfVariables(self):
        return self.variables

class TypeParser():
    def __init__(self):
        self.error = Error_class()
        self.types = {
            'int': self.parseInt,
            'bool': self.parseBool,
            'float': self.parseFloat,
            'string': self.parseString,
            'nil': self.parseNil,
        }
    def parseType(self, valType, value):
        exe = self.types.get(valType, None)
        if exe is None:
            self.error.invalidType()
        return exe(value)

    def varSplit(self, value):
        value = value.split('@')
        return value

    def parseInt(self, value):
        if value.isdigit() or value[0] == '-' and value[1:].isdigit():
            return 'int', int(value, 0)
        else:
            self.error.exit_code(31) #chybný formát čísla
    def parseBool(self,value):
        value = value.lower()
        if value == 'true':
            return 'bool', True
        if value == 'false':
            return 'bool', False
        self.error.invalidValue() #chybný formát boolu        
    
    def parseFloat(self, value):
        checkVal = value[1:].replace('.','',1) if value[0] == '-' else value.replace('.', '')
        if checkVal[:2] == '0x' and checkVal[2:].isdigit():
            return 'float', float.fromhex(value)
        if checkVal[0] == '0' and checkVal[1].isdigit() and checkVal[2:].isdigit() :
            value = value.split('.')
            value = str(int(value[0], 8)) + '.' + str(int(value[1]))
            return 'float', float(value)
             #osmičkový float
        if value.isdigit():
            return 'float', float(value) #dekadický float
        else:
            self.error.exit_code(31)

    def parseString(self, value):
        if value != None:
            matches = re.findall(r'\\[0-9]{3}', value)
            for match in matches:
                value = value.replace(match, chr(int(match[1:])))
            return 'string', value
        else:
            return 'string', ''

    def parseNil(self, value):
        if value == 'nil':
            return 'nil', None
        else:
            self.error.invalidValue()  #chybný formát nilu

class Interpret (TypeParser):
    def __init__(self, instructions, labels):
        super().__init__()
        self.types['var'] = self.getVariableValue
        self.instructions = instructions
        self.labels = labels
        self.inputFile = None
        #zásobník na hodnoty pozice call instrukcí --- pro případný return
        self.called_from_label = []
        #zásobník na pushs
        self.stack = []
        #Rámce
        self.localFrame = []
        self.frames = {
            'GF': Frame(),
            'LF': None,
            'TF': None
        }
        #jump
        self.jumpTo = None
        #Tabulka instrukcí
        self.instruction_Table = { 
            "move": self.move,
            "createframe": self.createframe,
            "pushframe": self.pushframe,
            "popframe": self.popframe,
            "defvar": self.defvar,
            "call": self.call,
            "return": self.returnInst,
            "pushs": self.pushs,
            "pops": self.pops,
            "add": self.add,
            "sub": self.sub,
            "mul": self.mul,
            "idiv": self.idiv,
            "lt": self.lt,
            "gt": self.gt,
            "eq": self.eq,
            "and": self.andInst,
            "or": self.orInst,
            "not": self.notInst,
            "int2char": self.int2char,
            "stri2int": self.stri2int,
            "read": self.stdinRead,
            "write": self.write,
            "concat": self.concat,
            "strlen": self.strlen,
            "getchar": self.getchar,
            "setchar": self.setchar,
            "type": self.typeInst,
            "jump": self.jump, 
            "jumpifeq": self.jumpifeq,
            "jumpifneq": self.jumpifneq,
            "exit": self.exitInst,
            "dprint": self.dprint,
            "Int2Float": self.Int2Float,
            "Float2Int": self.Float2Int
            }
    def loadInputFile(self, file):
        self.inputFile = open(file, 'r')
        self.instruction_Table['read'] = self.readFromFile
    def run(self):
        i = 0
        while i < len(self.instructions):
            instruction = self.instructions[i]
            execute = self.instruction_Table.get(instruction['opcode'].lower(), None)
            if execute is None:
                self.error.exit_code(32) #instrukce nenalezena
            execute(instruction.get('args'), i) # zavolá funkci z tabulky instrukcí
            if self.jumpTo == None:
                i += 1
            else:
                i = self.jumpTo #label nenalezen
                self.jumpTo = None
        if self.inputFile != None:
            self.inputFile.close()         
   
    def getFrame(self, arg):
        Variable = self.varSplit(arg);
        frame = self.frames.get(Variable[0], None) # najde rámec nebo vrátí chybu
        if frame is None:
            self.error.invalidFrame()
        return Variable[1], frame

    def getVariable(self, arg):
        name,frame = self.getFrame(arg)
        value = frame.getValueOf(name)
        return value

    def getVariableValue(self, arg):
        value = self.getVariable(arg)
        return value['type'], value['value']

    def write(self,args, i):
        if len(args) != 1:
            print("num of args write")
            self.error.exit_code(32)
        valueType,value = self.parseType(args[0].get('type'), args[0].get('value'))
        if valueType == None and value == None:
            self.error.unset()
        print(value)

    def arithmeticOperationCriteria(self, typeArg1, typeArg2, arg1, arg2):
        if typeArg1 == None and arg1 == None or typeArg2 == None and arg2 == None:
            self.error.unset()
        if typeArg1 != 'int' and typeArg1 != 'float' or typeArg2 != 'int' and typeArg2 != 'float':
            self.error.invalidType() #chybný formát čísla

    def relationOperatorCriteria(self, args):
        if len(args) != 2:
            self.error.invalidXML()
        typeArg1, arg1 = self.parseType(args[0].get('type'), args[0].get('value'))
        typeArg2, arg2 = self.parseType(args[1].get('type'), args[1].get('value'))
        if typeArg1 == None and arg1 == None or typeArg2 == None and arg2 == None:
            self.error.unset()
        if typeArg1 != typeArg2 and typeArg1 != 'nil' and typeArg2 != 'nil':
            self.error.invalidType()
        return arg1, arg2
   
    def logicalOperatorCriteria(self, args):
        if(len(args) != 2):
            self.error.invalidXML()
        typeArg1, arg1 = self.parseType(args[0].get('type'), args[0].get('value'))
        typeArg2, arg2 = self.parseType(args[1].get('type'), args[1].get('value'))
        if typeArg1 == None and arg1 == None or typeArg2 == None and arg2 == None:
            self.error.unset()
        if typeArg1 != 'bool' or typeArg2 != 'bool':
            self.error.invalidType()
        return arg1, arg2

    def ConditionedJumpCriteria(self, args):
        if len(args) != 2:
            self.error.invalidXML()
        arg1Type, arg1 = self.parseType(args[0].get('type'), args[0].get('value'))
        arg2Type, arg2 = self.parseType(args[1].get('type'), args[1].get('value'))
        if arg1Type == None and arg1 == None or arg2Type == None and arg2 == None:
            self.error.unset()
        if arg1Type != arg2Type and arg1Type != 'nil' and arg2Type != 'nil':
            self.error.invalidType()
        return arg1, arg2

    def dprint(self, args, i):
        if len(args) != 1:
            self.error.invalidXML()
        typeArg, arg = self.parseType(args[0].get('type'), args[0].get('value'))
        stderr.write(arg)

    def exitInst(self, args, i):
        if len(args) != 1:
            self.error.invalidXML()
        typeArg, arg = self.parseType(args[0].get('type'), args[0].get('value'))
        if typeArg == None and arg == None:
            self.error.unset()
        if typeArg != 'int':
            self.error.invalidType()
        if arg <0 or arg >49:
            self.error.invalidValue()
        exit(arg)

    def jumpCriteria(self,arg):
        index = self.labels.get(arg.get('value'), None)
        if index == None:
            self.error.duplicateVariable()
        return index

    def readFromFile(self, args, i):
        if len(args) != 2:
            self.error.invalidXML()
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, args[1].get('value'), self.inputFile.readline())

    def stdinRead(self, args, i):
        if len(args) != 2:
            self.error.invalidXML()
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, args[1].get('value'), input())

    def Int2Float(self, args, i):
        if len(args) != 2:
            self.error.invalidXML()
        typeArg, arg = self.parseType(args[1].get('type'), args[1].get('value'))
        if typeArg == None and arg == None:
            self.error.unset()
        if typeArg != 'int':
            self.error.invalidType()
        name, frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'float', float(arg))
    
    def Float2Int(self, args, i):
        if len(args) != 2:
            self.error.invalidXML()
        typeArg, arg = self.parseType(args[1].get('type'), args[1].get('value'))
        if typeArg == None and arg == None:
            self.error.unset()
        if typeArg != 'float':
            self.error.invalidType()
        name, frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'int', int(arg))

    def jumpifeq(self, args, i):
        arg1, arg2 = self.ConditionedJumpCriteria(args[1:])
        index = self.jumpCriteria(args[0])
        if arg1 == arg2:
            self.jumpTo = index

    def jumpifneq(self, args, i):
        arg1, arg2 = self.ConditionedJumpCriteria(args[1:])
        index = self.jumpCriteria(args[0])
        if arg1 != arg2:
            self.jumpTo = index

    def setchar(self, args, i):
        if len(args) != 3:
            self.error.invalidXML()
        typeArg1, arg1 = self.parseType(args[0].get('type'), args[0].get('value'))
        typeArg2, arg2 = self.parseType(args[1].get('type'), args[1].get('value'))
        typeArg3, arg3 = self.parseType(args[2].get('type'), args[2].get('value'))
        if typeArg1 == None and arg1 == None or typeArg2 == None and arg2 == None or typeArg3 == None and arg3 == None:
            self.error.unset()
        if typeArg1 != 'string' or typeArg2 != 'int' or typeArg3 != 'string':
            self.error.invalidType()
        if arg2 < 0 or arg2 >= len(arg1) or arg3 == '':
            self.error.excludedValue()
        name, frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'string', arg1[:arg2] + arg3 + arg1[arg2+1:])

    def getchar(self, args, i):
        if len(args) != 3:
            self.error.invalidXML()
        typeArg1, arg1 = self.parseType(args[1].get('type'), args[1].get('value'))
        typeArg2, arg2 = self.parseType(args[2].get('type'), args[2].get('value'))
        if typeArg1 == None and arg1 == None or typeArg2 == None and arg2 == None:
            self.error.unset()
        if typeArg1 != 'string' or typeArg2 != 'int':
            self.error.invalidType()
        if arg2 < 0 or arg2 >= len(arg1):
            self.error.excludedValue()
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'string', arg1[arg2])

    def concat(self, args, i):
        if len(args) != 3:
            self.error.invalidXML()
        typeArg1, arg1 = self.parseType(args[1].get('type'), args[1].get('value'))
        typeArg2, arg2 = self.parseType(args[2].get('type'), args[2].get('value'))
        if typeArg1 == None and arg1 == None or typeArg2 == None and arg2 == None:
            self.error.unset()
        if typeArg1 != 'string' or typeArg2 != 'string':
            self.error.invalidType()
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'string', arg1 + arg2)

    def strlen(self, args, i):
        if len(args) != 2:
            self.error.invalidXML()
        typeArg, arg = self.parseType(args[1].get('type'), args[1].get('value'))
        if typeArg == None and arg == None:
            self.error.unset()
        if typeArg != 'string':
            self.error.invalidType()
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'int', len(arg))

    def stri2int(self, args, i):
        if len(args) != 3:
            self.error.invalidXML()
        arg1Type, arg1 = self.parseType(args[1].get('type'), args[1].get('value'))
        arg2Type, arg2 = self.parseType(args[2].get('type'), args[2].get('value'))
        if arg1Type == None and arg1 == None or arg2Type == None and arg2 == None:
            self.error.unset()
        if arg1Type != 'string' or arg2Type != 'int':
            self.error.invalidType()
        if arg2 < 0 or arg2 >= len(arg1):
            self.error.excludedValue()
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'int', ord(arg1[arg2]))

    def int2char(self, args, i):
        if len(args) != 2:
            self.error.invalidXML()
        argType, arg = self.parseType(args[1].get('type'), args[1].get('value'))
        if argType == None and arg == None:
            self.error.unset()
        if argType != 'int':
            self.error.invalidType()
        if arg < 0 or arg > 1114111:
            self.error.excludedValue()
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'char', chr(arg)) 
    def orInst(self, args, i):
        arg1, arg2 = self.logicalOperatorCriteria(args[1:])
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'bool', arg1 or arg2)

    def andInst(self, args, i):
        arg1, arg2 = self.logicalOperatorCriteria(args[1:])
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name,'bool', arg1 and arg2)

    def notInst(self, args, i):
        if len(args) != 2:
            self.error.invalidXML()
        typeArg, arg = self.parseType(args[1].get('type'), args[1].get('value'))
        if typeArg == None and arg == None:
            self.error.unset()
        if typeArg != 'bool':
            self.error.invalidType()
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'bool', not arg)

    def eq(self, args, i):
        arg1, arg2 = self.relationOperatorCriteria(args[1:])
        name, frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'bool', arg1 == arg2) 

    def gt(self, args, i):
        arg1, arg2 = self.relationOperatorCriteria(args[1:])
        if arg1 == None or arg2 == None:
            self.error.invalidType()
        name, frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'bool', arg1 > arg2) 

    def lt(self, args, i):
        arg1, arg2 = self.relationOperatorCriteria(args[1:])
        if arg1 == None or arg2 == None:
            self.error.invalidType()
        name, frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'bool', arg1 < arg2) 

    def add(self, args, i):
        if len(args) != 3 :
            self.error.invalidXML()
        typeArg1,arg1 = self.parseType(args[1].get('type'), args[1].get('value'))
        typeArg2,arg2 = self.parseType(args[2].get('type'), args[2].get('value'))
        self.arithmeticOperationCriteria(typeArg1, typeArg2, arg1, arg2)
        result = arg1 + arg2
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, type(result).__name__, result)

    def sub(self, args, i):
        if len(args) != 3 :
            self.error.invalidXML()
        typeArg1,arg1 = self.parseType(args[1].get('type'), args[1].get('value'))
        typeArg2,arg2 = self.parseType(args[2].get('type'), args[2].get('value'))
        self.arithmeticOperationCriteria(typeArg1, typeArg2, arg1, arg2)
        result = arg1 - arg2
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, type(result).__name__, result)
    
    def mul(self, args, i):
        if len(args) != 3 :
            self.error.invalidXML()
        typeArg1,arg1 = self.parseType(args[1].get('type'), args[1].get('value'))
        typeArg2,arg2 = self.parseType(args[2].get('type'), args[2].get('value'))
        self.arithmeticOperationCriteria(typeArg1, typeArg2, arg1, arg2)
        result = arg1 * arg2
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, type(result).__name__, result)
    
    def idiv(self, args, i):
        if len(args) != 3 :
            self.error.invalidXML()
        typeArg1,arg1 = self.parseType(args[1].get('type'), args[1].get('value'))
        typeArg2,arg2 = self.parseType(args[2].get('type'), args[2].get('value'))
        self.arithmeticOperationCriteria(typeArg1, typeArg2, arg1, arg2)
        if arg2 == 0:
            self.error.invalidValue()
        result = arg1 // arg2
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, type(result).__name__, result)

    def pops(self, args, i):
        if len(args) != 1:
            self.error.invalidXML()
        if len(self.stack) == 0:
            self.error.unset()
        val = self.stack.pop()
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name,val['type'], val['value'])
    
    def pushs(self, args, i):
        if len(args) != 1:
            self.error.invalidXML() #chybný počet argumentů
        argType, arg = self.parseType(args[0].get('type'), args[0].get('value'))
        if argType == None and arg == None:
            self.error.unset()
        self.stack.append({'type':argType, 'value': arg})

    def typeInst(self, args, i):
        if len(args) != 2:
            self.error.invalidXML()
        typeArg, arg = self.parseType(args[1].get('type'), args[1].get('value'))
        if typeArg == None and arg == None:
            typeArg = 'nil'
            arg = None
        name,frame = self.getFrame(args[0].get('value'))
        frame.changeValue(name, 'type', typeArg)

    def move(self, args, i):
        if len(args) != 2:
            self.error.invalidXML()# chybný počet argumentů
        typeArg,arg = self.parseType(args[1].get('type'), args[1].get('value'))
        name,frame = self.getFrame(args[0].get('value'))
        if typeArg == None and arg == None:
            self.error.unset()
        frame.changeValue(name, typeArg, arg)

    def defvar(self, args, i):
        if len(args) != 1:
            print("num of args defvar")
            self.error.exit_code(31)
        variable = self.varSplit(args[0].get('value'))
        frame = self.frames.get(variable[0], None)
        if frame == None:
            self.error.invalidFrame()
        frame.addVariable(variable[1])

    def popframe(self, args, i):
        if len(args) != 0:
            self.error.invalidXML() #chybny pocet argumentu
        if self.frames['LF'] is None:
            self.error.invalidFrame() # prázdný lokální rámec

        self.frames['TF'] = self.frames['LF']

        if len(self.localFrame) > 0:
            self.frames['LF'] = self.localFrame.pop()
        else:
            self.frames['LF'] = None

    def pushframe(self, args, i):
        if len(args) != 0:
            self.error.invalidXML()#chybny pocet argumentu
        if self.frames['TF'] is None:
            self.error.invalidFrame() #frame je prázdný
        if self.frames['LF'] is not None:
            self.localFrame.append(self.frames['LF'])
        self.frames['LF'] = self.frames['TF']
        self.frames['TF'] = None

    def createframe(self, args, i):
        if len(args) != 0:
            self.error.invalidXML() # chybny pocet argumentu
        self.frames['TF'] = Frame()
    

    def call(self, args, index):
        if len(args) != 1:
            print("num of args call")
            self.error.exit_code(32) # chybny pocet argumentu
        self.called_from_label.append(index+1) # ulozi pozici instrukce následující po call
        index = self.jumpCriteria(args[0])
        self.jumpTo = index # ulozi label do promenne

    def returnInst(self, args, index):
        if len(args) != 0:
            self.error.invalidXML()
        if len(self.called_from_label) == 0:
            self.error.unset()
        self.jumpTo = self.called_from_label.pop()
    def jump(self, args, index):
        if len(args) != 1:
           self.error.invalidXML()
        self.jumpTo = self.jumpCriteria(args[0])
    

def XMLparse(tree):
    error = Error_class()

    root = tree.getroot()

    # Zpracování <program> tag
    if root.tag != 'program':
        print("program")
        error.exit_code(31)
    if root.get('language') != 'IPPcode22':
        print("lang")
        error.exit_code(32)

    # Zpracování <instruction> tag
    labels = {}
    instructions = []
    root = sorted(root, key=lambda x: int(x.get('order', "-1")))
    i = 0
    while i < len(root):
        child = root[i]
        if child.tag != 'instruction':
            print("instruction")
            error.exit_code(31)

        if child.get('opcode') == 'LABEL':
            if labels.get(child[0].text, None) != None:
                error.duplicateVariable()
            labels[child[0].text] = i - len(labels)
            i += 1
            continue

        inst_args = []
        for child_child in sorted(child, key=lambda x: x.tag):
            if not re.match("^arg[123]$", child_child.tag):
                print("child_tag")
                error.exit_code(32)
            inst_args.append({"type": child_child.get("type"), "value": child_child.text})
        instructions.append({"opcode": child.get("opcode"), "args": inst_args})
        i+=1
    return instructions, labels




def main():

    error = Error_class()

    #Parsování argumentů
    arg_parser = argparse.ArgumentParser()
    arg_parser.add_argument("--source", dest='source_file', default=stdin, help="Vstupní soubor s XML reprezentací zdrojového kódu.")
    arg_parser.add_argument("--input", dest='input_file', default=None, help="Soubor se vstupy pro samotnou interpretaci zdrojového kódu.")

    args = arg_parser.parse_args()

    source_file = args.source_file
    input_file = args.input_file



    if args.source_file == stdin and args.input_file == stdin:
        stderr.write('Missing argument\n')
        error.exit_code(10)


    #XML načtení a zpracování
    tree = ET.parse(source_file)
    Parameters = XMLparse(tree) # vrátí seznam instrukcí a seznam labelů
    interpret = Interpret(Parameters[0], Parameters[1]) # vytvoří interpret
    if input_file != None:
        interpret.loadInputFile(input_file)
    interpret.run() # spustí interpret nad instrukcemi


main()