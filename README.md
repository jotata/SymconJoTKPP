# SymconJoTModBus
ModBus-Erweiterung für IP-Symcon

## Dokumentation
**Inhaltsverzeichnis**
1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Modul-Installation](#3-modul-installation) 
4. [Einrichten der Instanz in IP-Symcon](#4-einrichten-der-instanz-in-ip-symcon)
    1. [Erstellen einer neuen Instanz](#1-erstellen-einer-neuen-instanz)
    2. [Konfiguration der Instanz](#2-konfiguration-der-instanz)
    3. [Modul-Funktionen](#3-modul-funktionen)
    4. [Fehlersuche](#4-fehlersuche)
5. [Anhang](#5-anhang)  
    1. [Modul-Informationen](#1-modul-informationen)
    2. [Changlog](#2-changlog)
    3. [Spenden](#3-spenden)
6. [Lizenz](#6-lizenz)

## 1. Funktionsumfang
Das Modul "JoT ModBus" ist eine erweiterte Variante der ursprünglichen ModBus-Device Instanz in IP-Symcon mit folgendem Funktionsumfang:
- Mehrere ModBus-Variabeln vom selben Gateway in einer einzelnen Instanz möglich.
- Nebst Zahlen & boolschen Werten können auch Strings ausgelesen werden.
- Es lassen sich folgende Übertragungsarten je Variable separat einstellen:
  - BigEndian
  - BigEndian WordSwap
  - LittleEndian
  - LittleEndian WordSwap
- Das Polling einzelner Variabeln kann individuell (de)aktiviert werden.
- Über IPS Ereignisse können individuelle Abfrage-Muster realisiert werden (nur bei Bedarf, einmal am Tag, alle x Sekunden, usw.).
- Konfigurationen können Ex-/Importiert werden (ToDo).
- Werte können auf ModBus zurück geschrieben werden (ToDo).

## 2. Voraussetzungen
 - IPS 5.2 oder höher  
 - Gerät mit ModBus-TCP/IP-Unterstützung oder
 - ModBus-TCP/IP zu RS485 Gateway oder
 - physikalisches RS485 Interface  

## 3. Modul-Installation
Die Installation erfolgt über den IPS Module-Store. In der Suche einfach "JoT ModBus" eingeben und die Installation starten.

**Das Modul wird für den privaten Gebrauch kostenlos zur Verfügung gestellt.**

**Bei kommerzieller Nutzung (d.h. wenn Sie für die Einrichtung/Installation und/oder den Betrieb von IPS Geld erhalten) wenden Sie sich bitte an den Autor.**

**ACHTUNG:**
  
  **Der Autor übernimmt keine Haftung für irgendwelche Folgen welche durch die Nutzung dieses Modules entstehen!**

## 4. Einrichten der Instanz in IP-Symcon
  ### 1. Erstellen einer neuen Instanz
   1. Neue Instanz hinzufügen
   2. im Schnellfilter "ModBus" eingeben
   3. Das Gerät "ModBus Device Advanced" auswählen
   4. Name & Ort anpassen (optional)
   5. Falls noch keine ModBus Gateway Instanz vorhanden ist, wid eine solche erstellt. Diese entsprechend konfigurieren.
 
  ### 2. Konfiguration der Instanz
   - Abfrage-Intervall: Definiert die Zeit, in welcher die Werte via ModBus angefragt werden sollen. Es werden nur die Werte abgefragt, bei welchen "Poll" aktiviert ist.
   - ModBus Variablen: Hier werden alle Eigenschaften einer ModBus-Variable aufgelistet. Mit dem Zahnrad kann die jeweilige Konfiguration angepasst werden.
     - Ident: Wird vom Modul vergeben und kann nicht geändert werden.
     - Name: Ist der Name der Variable im Objektbaum und kann auch dort geändert werden.
     - DatenTyp: Entspricht dem DatenTyp der ModBus-Variable. Der Typ der Variable in IPS wird daraus abgeleitet.
     - Profil: Das Variablen-Profil kann über die Eigenschaften der Variable im Objektbaum geändert werden.
     - Faktor: Der Wert vom ModBus-Gerät wird mit diesem Faktor multipliziert (bei 0, 1 oder einem String wird nichts multipliziert).
     - Lese Funktion: Definiert die ModBus Read Function.
     - Lese Adresse: Definiert die ModBus-Adresse von welcher gelesen werden soll.
     - Anzahl: Definiert wie viele Register ausgehend ab der Lese Adresse zu diesem Wert gehören.
     - ModBus Typ: Definiert wie die Daten vom ModBus-Gerät übertragen werden.
     - Poll: Nur Werte mit aktivem Poll werden zyklisch abgefragt. Es ist allerdings auch möglich die Werte mit Funktionen auszulesen (siehe [Modul-Funktionen](#3-modul-funktionen)).
   - Weitere Variablen können im Actions-Bereich hinzugefügt werden. Neue Variabeln werden grün markiert und mindestens eine muss angepasst werden, damit die Änderungen übernommen werden können.

  ### 3. Modul-Funktionen
  Die folgenden Funktionen stehen in IPS-Scripts zur Verfügung:
  - RequestRead(int $InstanceID): Liest alle Werte, bei welchen Poll aktiviert ist.
  - RequestReadAll(int $InstanceID): Liest alle Werte (auch wenn Poll nicht aktiviert ist).
  - RequestReadIdent(int $InstanceID, string $Ident): Liest alle Werte, deren Ident angegeben wird (mehrere Idents werden durch ein Leerzeichen getrennt).
  
  ### 4. Fehlersuche
  Die Debug-Funktion der Instanz liefert recht detaillierte Informationen über die Konvertierung der Werte und vom ModBus-Gerät zurückgegebenen Fehler. Oft ist es einfach eine falsche Addresse oder Function welche in der Konfiguration angegeben wird.

## 5. Anhang
###  1. Modul-Informationen
| Modul                      | Typ    | Hersteller | Gerät           | Prefix | GUID                                   |
| :------------------------- | :----- | :--------- | :---------------| :----- | :------------------------------------- |
| JoT Kostal Plenticore Plus | Device | Kostal     | Plenticore Plus | JoTKPP | {E64278F5-1942-5343-E226-8673886E2D05} |

### 2. Changelog
Version 0.4:
- Dynamische Formulare ab IPS 5.2 -> Verbesserter Dialog zum Hinzufügen neuer Variablen
- Diverse Code-Optimierungen
Version 0.3:  
- Erste öffentliche Beta-Version - Feedbacks zu Fehlern aber auch funktionierende Geräte & Konfigurationen sind willkommen.

### 3. Spenden    
Das Modul ist für die nicht kommzerielle Nutzung kostenlos. Spenden als Unterstützung für den Autor sind aber willkommen:  
<p align="center"><a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=9M6W4KM34HWMA&source=url" target="_blank"><img src="https://www.paypalobjects.com/de_DE/CH/i/btn/btn_donateCC_LG.gif" border="0" /></a></p>

## 6. Lizenz
IPS-Modul: <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/" target="_blank">CC BY-NC-SA 4.0</a>
