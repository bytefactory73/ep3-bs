# Drinks-Testsuite

Live-Tests der Getränkeverwaltung gegen eine laufende Instanz (Standard: bookingtest), verglichen mit
den Referenzwerten in `reference.json`. Nur Python 3 Standardbibliothek.

## Aufruf

```sh
export EP3_SESSION=<Wert des Cookies ep3-bs-session eines eingeloggten Admins>

python3 tests/drinks/drinks_suite.py                              # Lese- und Rechtetests
python3 tests/drinks/drinks_suite.py --write --theke --paypal     # kompletter Standardlauf
python3 tests/drinks/drinks_suite.py -k team_stats                # nur Tests mit "team_stats" im Namen
python3 tests/drinks/drinks_suite.py --write --theke --paypal --record   # Referenz neu aufnehmen
```

Exit-Code 0 = kein Test fehlgeschlagen.

## Gruppen

| Schalter | Inhalt | Spuren in der Datenbank |
|---|---|---|
| (immer) `auth` | Seiten und alle JSON-Endpunkte ohne Login: kein HTTP 200 | keine |
| (immer) `read` | alle Admin-Seiten, Auswertungs-Varianten, Konto- und Kostenübersichts-Daten mit Konsistenzprüfungen, ungültige Parameter | keine |
| `--write` | Einzahlung, Admin-Buchung, Team-Buchung, Geld senden (inkl. Doppel-Submit), Einstellungen, Party-Mode, Getränke anlegen/ändern/löschen, Mitglieder, Zusatzkosten, Gastspenden, Relevanz, Spieltag umhängen, geschlossener Spieltag | Jede Änderung wird rückgängig gemacht (Storno). Es bleiben stornierte Einträge mit Kommentar `SMOKETEST`; Mails gehen an die Test-Umleitung. |
| `--theke` | Theke-Login, Bestellen/Stornieren, Kostenübersicht (gleich wie Admin-Sicht), Team-Schreibaktionen, Geld senden mit Passwort | wie `--write` |
| `--paypal` | PayPal-Historie-Import (3 Tage) und Namensabgleich aus dem Postfach | neue Zeilen in `drinks_paypal` |
| `--destructive` | Spieltag `SMOKETEST <Zeit>` anlegen und abschließen | ein abgeschlossener leerer Spieltag pro Lauf beim Team 962 |
| `--paypal-imap` | PayPal-IMAP-Abruf | markiert Mails im PayPal-Postfach als gelesen |

Vor und nach Schreibläufen räumt die Suite übrig gebliebene `SMOKETEST`-Daten eines abgebrochenen
Laufs auf (Storno, Löschen) und meldet sie. Reste nach einem Lauf zählen als Fehler.

## Testdaten (bookingtest)

In `drinks_suite.py` oben: Admin uid 1 (Session-User, Thekenadmin), Empfänger uid 4, Mannschaft 962
(„D50“) mit mindestens einem offenen, einem abgeschlossenen Spieltag. Die Theke-Tests lesen die
Theken-IDs dieser Konten zur Laufzeit über die Admin-API; sie werden nie ausgegeben oder gespeichert.

## Referenzwerte

`reference.json` enthält pro Test die gesammelten Fakten: HTTP-Status, Fehlermeldungen,
Feldstruktur der JSON-Antworten, Spieltag-IDs und -Namen, Salden-Änderungen der Round-Trips.
Fakten mit `~` (Salden, Anzahlen) können sich durch echte Nutzung ändern: Abweichungen sind
Warnungen, keine Fehler. Unabhängig von der Referenz prüft jeder Test feste Regeln, z. B.:

- Summe der Kostenzeilen = `total_sum`, Spieltage neueste zuerst, Mitglieder nicht unter den Kandidaten
- Teamlead-, Admin- und Theke-Sicht derselben Kostenübersicht sind identisch
- Buchung + Storno lässt jeden Saldo exakt unverändert; Geld senden storniert beide Seiten
- Schreibzugriffe auf einen abgeschlossenen Spieltag werden abgelehnt

Nach einer gewollten Verhaltensänderung: Lauf prüfen, dann mit `--record` die Referenz erneuern.
Die Verbindung zu bookingtest bricht gelegentlich ab; GET-Anfragen werden wiederholt, POSTs nicht
(sonst könnte doppelt gebucht werden). Ein einzelner `URLError` ist meist ein solcher Abbruch.
