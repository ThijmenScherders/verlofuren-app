# Verlofuren-app (PHP + MySQL)

Een eenvoudige multi-user verlofplanner met:
- Accounts en inloggen (wachtwoorden gehasht, sessies, optioneel 'ingelogd blijven')
- Maand- en weekkalender (FullCalendar via CDN)
- Verlof toevoegen, aanpassen en verwijderen
- Uren handmatig invullen of automatisch berekenen op basis van werkuren per werkdag
- Verlofsaldo per gebruiker
- Nederlandse feestdagen tonen (Pasen, Koningsdag, Bevrijdingsdag, Hemelvaart, Pinksteren, Kerst en Nieuwjaar; enkele feestdagen hebben uitzonderingsregels)
- Export naar CSV die in Excel geopend kan worden

## Benodigd
- PHP 8.1+ met PDO MySQL
- MySQL 8+ of MariaDB
- Apache/Nginx of PHP's ingebouwde server
- Internetverbinding voor FullCalendar CDN (kalenderbibliotheek)

## Installatie (XAMPP)
1. Pak de map `verlofuren-app` uit naar `C:\xampp\htdocs\verlofuren-app`.
2. Start Apache en MySQL via het XAMPP Control Panel.
3. Open phpMyAdmin (`http://localhost/phpmyadmin`) en importeer `database.sql`.
4. Controleer de databasegegevens in `config.php`. Standaard: database `verlofuren`, gebruiker `root`, leeg wachtwoord.
5. Open `http://localhost/verlofuren-app/install.php`.
6. Maak de eerste beheerder aan. Verwijder daarna `install.php` of hernoem het bestand.
7. Log in via `http://localhost/verlofuren-app/login.php`.

## Eerste beheerder en medewerkers
De eerste account die via `install.php` wordt aangemaakt krijgt de rol `admin`.
Daarna kan een beheerder via `medewerkers.php` nieuwe accounts aanmaken. Medewerkers kunnen hun eigen verlof zien en beheren; beheerders kunnen alle boekingen bekijken en beheren.

## Werkuren en saldo
- Iedere gebruiker heeft een beginsaldo in uren en werkuren per weekdag.
- Automatische berekening telt de ingestelde werkuren op voor werkdagen binnen de gekozen periode, exclusief zaterdag/zondag. Feestdagen worden niet automatisch uitgesloten; pas het aantal uren handmatig aan als je op een feestdag niet werkt.
- Handmatige boekingen gebruiken het opgegeven aantal uren.
- Het resterende saldo = beginsaldo - totaal geboekte verlofuren.
- Pas het beginsaldo en het werkrooster aan via `medewerkers.php`.

## Excel-export
Klik in het dashboard op `Export CSV`. Excel kan dit CSV-bestand openen. Het gebruikt puntkomma's als scheidingsteken, passend bij veel Nederlandse Excel-installaties.

## Productiegebruik
Voor een echte openbare server: gebruik HTTPS, stel een sterk databasewachtwoord in, zet foutmeldingen uit, beperk toegang tot `install.php` (verwijder dit bestand na installatie), maak back-ups en configureer e-mail/wachtwoordherstel voordat je dit publiek gebruikt.







## Dingen om te doen 


 1* het  beschikbare verlof  vastzetten (193.75 & 25 dagen) (Done!!)
 2* tandarts / dokter toevoegen   (Mag niet van verlof uren af!!!) (Done !!!)
 3* specialist (Mag niet van verlof uren af!!!)
 4* Uitbetalen (Datum kunnen kiezen dat er uren van resterende saldo worden afgeboekt)
 5* Bij de resterende saldo het jaartal bij zetten.
 6* Rol van persoon + werknemersnummer toevoegen en  rechtsbovenin zetten. (Done!!)
 7* meldingen voor verlofuren (Max 5 dagen meenemen) de rest moet in jan / feb / maart gebruikt zijn. of uitlatenbetalen.

 8* Verlof aanvraag doen bij manager van de afdelingen  manager moet akkord geven


Must= have = 1 (Done) + 2 (Done) + 3 + 5
Could have = 4 + 6 (Done) + 7 + 8

Can have = 