#### Implementační dokumentace k 2. úloze do IPP 20212022
#### Jméno a příjmení Tomáš Švondr
#### Login xsvond00

## Popis kódu v souboru interpret.py
#### Třídy
Program interpretu se skládá z 4 esenciálních tříd:
 Error - Zajišťuje obsluhu chybových stavů.
 Frame - Definuje rámec a zajišťuje obsluhu práce s proměnnými.
 TypeParser - Převádí a kontroluje správnost datových typů a hodnot proměnných a konstant.
 Interpret - Páteřní třída programu, zajišťuje obsluhu a interpretaci instrukcí kódu IPP2022.

Testovací rámec se skládá ze 3 tříd:
 TestSet - třída pro uchovávání výsledků testů z jednotlivých adresářů.
 Result - třída uchovává informace o výsledku testu.
 TestConfig - hlavní třída testovacího rámce

#### Podrobný popis kódu
Program se skládá ze 4 tříd a 2 funkcí (main a XMLParse), které jsou součástí přípravné fáze programu jejíž úkolem je zpracování argumentů programu (pomocí knihovny argparse), dále zpracování vstupního dokumentu formátu XML (pomocí xml.etree) a kontrolu všech základních náležitostí zdrojového souboru.

Zpracování zdrojového souboru zajišťuje funkce XMLParse. Funkce kontroluje jeho strukturu a extrahuje z něj instrukce a návěští s pozicí následující instrukce.

extrahované instrukce a návěští jsou dále předány ke zpracování objektu interpret třídy Interpret.

Interpret je páteřní třída celého programu. Ve vlastnictví třídy se nachází několik slovníků, např. instruction_Table, který obsahuje název instrukce a referenci k obslužné funkci danné instrukce. Tento slovník se dále využívá pouze ve funkci run, která jeho prostřednictvím postupně volá obslužné funkce instrukcí kódu IPP2022.
Ostatní slovníky jsou používány například pro uchovávání viditelných rámců (překryté rámce se uchovávají v localFrame).

Třída dále obsahuje obslužné metody pro všechny zadané instrukce jazyka IPP2022, správné volání zajištuje metoda run. Pomocné metody, tj. funkce jejichž smyslem je pouze kontrola správnosti vstupních hodnot jsou v programu označovány postfixem Criteria.

TypeParser je třída rozšiřující Interpret, jejíž smyslem existence je převod a kontrola hodnot na různé datové typy (převážně z typu string) a také získávání hodnot proměnných.

Frame je třída zastupující jeden rámec proměnných hodnot, majetkem této třídy je slovník proměnných s hodnotami jejich datových typů a jejich vlastních hodnot. Třída obsahuje metody obsluhy rámce, či jednotlivých proměnných, jako je například addVariable, changeValue, a getValueOf. Tyto funkce mají cíl přidávat do rámce nové proměnné, nebo změnit hodnotu již deklarované proměnné, nebo získat hodnoty proměnné pro volající entitu.

#### Popis testování
Testování probíhá v několika fázích:
1. zpracování argumentů
2. hledání všech zdrojových souborů pro testování
3. samotné testování.

Testování obstarává funkce runTests která provede testování parseru a interpretu (podle nastavení).
Výsledný html dokument vytváří funkce BuildDoc.

testovací rámec přijímá pro testování zdrojové soubory s příponou .src, pro vstup do interpretu (skrze --input) .in a konečně informace o očekávaném návratovém kódu soubory s příponou .rc.

#### Bonusová rozšíření
Z rozšíření bylo implementováno pouze rozšíření FLOAT.
