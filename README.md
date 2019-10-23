# SymconJoTKPP
Erweiterung zur Abfrage der Werte eines Kostal Wechselrichters via ModBus in IP-Symcon.

## Dokumentation
**Inhaltsverzeichnis**
1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Unterstützte Geräte](#3-unterst%C3%BCtze-ger%C3%A4te)
4. [Modul-Installation / Update](#4-modul-installation--update) 
5. [Einrichten der Instanz in IP-Symcon](#5-einrichten-der-instanz-in-ip-symcon)
    1. [Erstellen einer neuen Instanz](#1-erstellen-einer-neuen-instanz)
    2. [Konfiguration der Instanz](#2-konfiguration-der-instanz)
    3. [Modul-Funktionen](#3-modul-funktionen)
    4. [Fehlersuche](#4-fehlersuche)
6. [Anhang](#6-anhang)  
    1. [Modul-Informationen](#1-modul-informationen)
    2. [Changelog](#2-changelog)
    3. [Spenden](#3-spenden)
7. [Support](#7-support)
8. [Lizenz](#8-lizenz)

## 1. Funktionsumfang
Das Modul "JoTKPP" stellt eine Instanz zur Abfrage der Werte von Kostal-Wechselrichtern für IP-Symcon zur Verfügung.
Die Daten werden mittels ModBus abgefragt. Der Benutzer kann frei entscheiden, welche Werte abgefragt werden und ob dafür eine Instanz-Variable angelegt werden soll.
Über IPS Ereignisse oder einen Aufruf der verschiedenen [RequestRead-Funktionen](#3-modul-funktionen) können individuelle Abfrage-Muster realisiert werden (nur bei Bedarf, einmal am Tag, alle x Sekunden, usw.).
Das Modul prüft zudem, ob online eine neue FW-Version für den Wechselrichter verfügbar ist.

## 2. Voraussetzungen
 - IPS 5.2 oder höher  
 - Kostal Wechselrichter mit aktivierter ModBus-Schnittstelle

## 3. Unterstütze Geräte
Das Modul wird grundsätzlich für einen Kostal PLENTICORE Plus 7.0 programmiert / getestet.
Da Kostal aber für alle Geräte der Serien ["PLENTICORE plus"](https://www.kostal-solar-electric.com/de-de/products/hybrid-inverters/plenticore-plus) & ["PIKO IQ"](https://www.kostal-solar-electric.com/de-de/products/string-inverter/piko-iq) dieselben ModBus-Spezifikationen herausgibt, sollten auch andere Geräte dieser Serien funktionieren.

Hersteller: KOSTAL

Modelle:
- PLENTICORE plus 4.2
- PLENTICORE plus 5.5
- PLENTICORE plus 7.0 (getestet)
- PLENTICORE plus 8.5
- PLENTICORE plus 10 
- PIKO IQ 4.2
- PIKO IQ 5.5
- PIKO IQ 7.0
- PIKO IQ 8.5
- PIKO IQ 10

Da Kostal auch die SunSpec-Definitionen für ModBus implementiert, könnte das Modul ev. sogar mit Wechselrichtern von anderen Herstellern funktionieren.
Leider kann ich das nicht testen, da ich kein solches Gerät verfügbar habe. Für ein Feedback zur Funktion mit anderen Modellen / Herstellern bin ich euch daher sehr dankbar.

## 4. Modul-Installation / Update
Die Installation erfolgt über den IPS Module-Store. In der Suche einfach "JoTKPP" eingeben und die Installation starten.
Update erfolgt ebenfalls über den Module-Store. Einfach beim installierten Modul auf "Aktualisieren" klicken.

**WICHTIG:** Aktuell gibt es beim Update ein Problem, dass der Prozess "JoTKPP_RequestRead" hängen bleibt, wenn das Update bei aktivem Prozess gestartet wird.
Ursache dafür ist vermutlich ein Bug in IPS ([Details](https://www.symcon.de/forum/threads/41792-Prozess-PREFIX_RequestRead-bleibt-bei-Modul-Update-h%C3%A4ngen)).
Der Prozess wird nach ca. 20 Minuten durch IPS abgeschossen und danach funktioniert das Modul wieder normal.
Um diesen Hänger zu vermeiden, kann man vor dem Update den "Aktualisierungs-Intervall" auf "0" stellen und nach dem Update wieder aktivieren.

**Das Modul wird für den privaten Gebrauch kostenlos zur Verfügung gestellt.**

**Bei kommerzieller Nutzung (d.h. wenn Sie für die Einrichtung/Installation und/oder den Betrieb von IPS Geld erhalten) wenden Sie sich bitte an den Autor.**

**ACHTUNG: Der Autor übernimmt keine Haftung für irgendwelche Folgen welche durch die Nutzung dieses Modules entstehen!**

## 5. Einrichten der Instanz in IP-Symcon
  ### 1. Erstellen einer neuen Instanz
   1. Neue Instanz hinzufügen
   2. Im Schnellfilter "Kostal" eingeben
   3. Das Gerät "Kostal PLENTICORE plus" auswählen
   4. Name & Ort anpassen (optional)
   5. Falls noch keine ModBus Gateway Instanz vorhanden ist, wid eine solche erstellt. Diese entsprechend konfigurieren.
 
  ### 2. Konfiguration der Instanz
   - Abfrage-Intervall: Definiert die Zeit, in welcher die Werte via ModBus abgefragt werden sollen. Es werden nur die Werte abgefragt, bei welchen "Aktiv" angehakt ist.
   - Gruppe / Ident: Diese Bezeichnung kann zur Abfrage einer Gruppe von Werten oder einzelner Werte mit der entsprechenden [RequestRead-Methode](#3-modul-funktionen) verwendet werden.
   - Name: Die Bezeichnung der Instanz-Variable gemäss Kostal Spezifikation.
   - Eigener Name: Wenn euch die Bezeichnung der Instanz-Variabeln nicht gefällt, könnt ihr den Namen direkt in der Variable anpassen. Der neue Name wird dann in dieser Spalte angezeigt.
   - Profil: Standard-Profil des Modules.
   - Eigenes Profil: Ihr könnt der Instanz-Variable ein eigenes Profil zuweisen (z.B. für Batterie-Ladezustand). Dieses wird dann hier angezeigt.
   - Aktiv: Wenn der Haken gesetzt ist, wird einen entsprechende Instanz-Variable erstellt. VORSICHT: Wird der Haken entfernt und die Konfiguration gespeichert, so wird die entsprechende Instanz-Variable gelöscht.

  ### 3. Modul-Funktionen
  Die folgenden Funktionen stehen in IPS-Ereignissen/-Scripts zur Verfügung:
  - JoTKPP_RequestRead(): Liest alle Werte, bei welchen der Haken "Aktiv" gesetzt ist. Aktualisiert die entsprechenden Instanz-Variablen und gibt die Werte als Array zurück.
  - JoTKPP_RequestReadAll(): Liest alle Werte.*
  - JoTKPP_RequestReadIdent(string $Ident): Liest alle Werte, deren Ident angegeben wird (mehrere Idents werden durch ein Leerzeichen getrennt).*
  - JoTKPP_RequestReadGroup(string $Gruppe): Liest alle Werte, deren Gruppe angegeben wird (mehrere Gruppen werden durch ein Leerzeichen getrennt).*
  - JoTKPP_CheckFirmwareUpdate(): Holt den Namen der aktuellsten FW-Datei bei Kostal, speichert diese in einer Instanz-Variable und gibt sie als String zurück.

  *) Die Werte werden auch gelesen, wenn der Haken "Aktiv" nicht gesetzt ist. Sie werden dann jedoch nur als Array zurückgegeben und nicht in eine Instanz-Variable geschrieben.
  
  ### 4. Fehlersuche
  Die Debug-Funktion der Instanz liefert recht detaillierte Informationen über die Konvertierung der Werte und vom ModBus zurückgegebenen Fehler.

## 6. Anhang
###  1. Modul-Informationen
| Modul  | Typ    | Hersteller | Gerät           | Prefix | GUID                                   |
| :----- | :----- | :--------- | :---------------| :----- | :------------------------------------- |
| JoTKPP | Device | Kostal     | PLENTICORE plus | JoTKPP | {E64278F5-1942-5343-E226-8673886E2D05} |
| JoTKPP | Device | Kostal     | PIKO IQ         | JoTKPP | {E64278F5-1942-5343-E226-8673886E2D05} |

### 2. Changelog
Version 1.2
- Überprüfung der übergeordneten Instanzen optimiert

Version 1.1
- Timer für FW-Update-Check in Konfigurationsformular integriert
- Default-Positionen auf 10er-Zahlen angepasst, damit Sortierung auch innerhalb einer Gruppe möglich ist
- Zusätzliche Meldung im Debug-Log

Version 1.0 (RC1)
- Bezeichnungen für Powermeter-Werte angepasst
- Verbesserte Fehlerausgabe ModBus

Version 0.9:  
- Messwerte für Powermeter hinzugefügt
- Verbesserungen beim DeviceDiscovery
- Änderung des Gateways wird nun erkannt und verarbeitet

Version 0.8:  
- Erste öffentliche Beta-Version
- Feedbacks zu Fehlern aber auch funktionierende Geräte & Konfigurationen sind willkommen.

### 3. Spenden    
Das Modul ist für die nicht kommzerielle Nutzung kostenlos. Spenden als Unterstützung für den Autor sind aber willkommen:  
<p align="center"><a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=9M6W4KM34HWMA&source=url" target="_blank"><img src="https://www.paypalobjects.com/de_DE/CH/i/btn/btn_donateCC_LG.gif" border="0" /></a></p>

## 7. Support
Fragen, Anregungen, Kritik und Fehler zu diesem Modul können im entsprechenden [Thread des IPS-Forums](https://www.symcon.de/forum/threads/41720-Modul-JoTKPP-Solar-Wechselrichter-Kostal-PLENTICORE-plus-PIKO-IQ) deponiert werden.
Da das Modul in der Freizeit entwickelt wird, kann es jedoch eine Weile dauern, bis eine Antwort im Forum verfügbar oder ein entsprechendes Update vorhanden ist. Besten Dank für euer Verständnis :-)

## 8. Lizenz
IPS-Modul: <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/" target="_blank">CC BY-NC-SA 4.0</a>
