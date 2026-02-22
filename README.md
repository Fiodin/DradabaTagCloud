# DradabaTagCloud

Kategorie-Wortwolke für MediaWiki 1.42+.

Zeigt Wiki-Kategorien als Wortwolke an, wobei die Schriftgröße proportional zur Anzahl der Seiten in der jeweiligen Kategorie ist. Die Reihenfolge ist zufällig.

## Voraussetzungen

- MediaWiki ≥ 1.42
- PHP ≥ 8.1

## Installation

1. Ordner `DradabaTagCloud` nach `extensions/` kopieren:

```bash
cp -r DradabaTagCloud /var/www/dradaba/extensions/
```

2. In `LocalSettings.php` aktivieren:

```php
wfLoadExtension( 'DradabaTagCloud' );
```

3. Fertig – kein `composer install` nötig, keine externen Abhängigkeiten.

## Nutzung

Auf jeder Wiki-Seite den Parser-Tag einfügen:

### Einfachste Variante – alle Kategorien

```wiki
<tagcloud />
```

### Mit Filtern

```wiki
<tagcloud min="3" max="40" exclude="Wartung,Versteckte_Kategorie" />
```

### Nur bestimmte Kategorien (Whitelist)

```wiki
<tagcloud only="Drachen,Lenkdrachen,Einleiner,Vierleiner" />
```

### Schriftgröße anpassen

```wiki
<tagcloud minsize="70" maxsize="250" />
```

## Parameter

| Parameter | Standard | Beschreibung                                                    |
| --------- | -------- | --------------------------------------------------------------- |
| `min`     | `1`      | Mindestanzahl Seiten in einer Kategorie                         |
| `max`     | `0`      | Maximale Anzahl angezeigter Kategorien (0 = alle)               |
| `exclude` | –        | Liste auszuschließender Kategorien. Trennung mit Pipe \|        |
| `only`    | –        | Whitelist – nur diese Kategorien anzeigen. Trennung mit Pipe \| |
| `minsize` | `80`     | Kleinste Schriftgröße in Prozent                                |
| `maxsize` | `200`    | Größte Schriftgröße in Prozent                                  |
| `refresh` | `3600`   | Cache-Dauer in Sekunden (Standard: 1 Stunde)                    |

## Hinweise

- Die Wortwolke wird bei jeder Cache-Aktualisierung neu gemischt.
- Die Schriftgröße wird linear zwischen `minsize` und `maxsize` interpoliert.
- Das CSS funktioniert mit allen Skins (Citizen, Vector, MonoBook, Timeless).
- Dark-Mode wird automatisch unterstützt (über `prefers-color-scheme` und Citizen's `data-citizen-theme`).

## Lizenz

GPL-3.0-or-later
