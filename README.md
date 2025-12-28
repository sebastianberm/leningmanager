# Leningmanager

Een webapplicatie voor het beheren van leningen, betalingen en aflossingsschema's. Gebouwd met PHP, SQLite en Bootstrap.

## Beschrijving

Leningmanager helpt bij het organiseren van leningen. Gebruikers kunnen leningen aanmaken, betalingen registreren en aflossingsschema's bekijken. Er is ondersteuning voor verschillende soorten leningen (annuity en linear) en een REST API voor integratie.

### Functies

- Gebruikersbeheer met rollen (admin, manager, borrower)
- Lening aanmaken en beheren
- Betalingen registreren
- Aflossingsschema's berekenen en visualiseren
- PDF-export van jaarlijkse overzichten
- CSV-export van schema's
- REST API voor externe toegang
- Dark/light theme ondersteuning
- Webhook notificaties voor betalingen

## Vereisten

- PHP 8.2 of hoger
- SQLite (meegeleverd)
- Composer (voor dependencies)
- Docker (optioneel, voor containerized deployment)

## Installatie

### Met Docker (Aanbevolen)

1. Zorg ervoor dat Docker en Docker Compose geïnstalleerd zijn.
2. Clone de repository:
   ```bash
   git clone https://github.com/sebastianberm/leningmanager.git
   cd leningmanager
   ```
3. Start de applicatie:
   ```bash
   docker-compose up -d
   ```
4. Open in browser: `http://localhost:8080`

De database wordt automatisch aangemaakt bij eerste start.

### Handmatige Installatie

1. Clone de repository en ga naar de map.
2. Installeer dependencies:
   ```bash
   composer install --no-dev
   ```
3. Zorg voor een webserver (bijv. Apache) die naar `public/` wijst.
4. Stel omgevingsvariabelen in (zie Configuratie).
5. Open in browser en ga naar `/setup.php` voor eerste admin gebruiker.

## Configuratie

De applicatie gebruikt omgevingsvariabelen voor configuratie:

- `API_TOKEN`: Token voor API toegang (standaard: "changeme_dev_token")
- `WEBHOOK_URL`: URL voor webhook notificaties bij betalingen (optioneel)

In Docker, deze zijn ingesteld in `docker-compose.yml`.

## Gebruik

### Eerste Setup

1. Ga naar `/setup.php` en maak de eerste admin gebruiker aan.
2. Log in met de admin credentials.
3. Maak extra gebruikers aan via `/users.php` (admin/manager rol vereist).
4. Maak leningen aan via `/loans.php`.

### Leningen Beheren

- **Aanmaken**: Vul naam, hoofdsom, rente, startdatum, looptijd en type in.
- **Betalingen**: Voeg betalingen toe via de lening detail pagina.
- **Schema**: Bekijk het aflossingsschema met grafieken.
- **Export**: Download PDF of CSV van het schema.

### Rollen

- **Admin**: Volledige toegang, inclusief gebruikersbeheer.
- **Manager**: Kan leningen aanmaken en beheren.
- **Borrower**: Alleen-lezen toegang tot eigen leningen.

## API

De applicatie heeft een REST API voor externe integratie. Gebruik Bearer token authenticatie.

### Authenticatie

Stuur `Authorization: Bearer {API_TOKEN}` header.

### Endpoints

- `GET /api/?r=loans` - Lijst van alle leningen
- `GET /api/?r=loans/{id}` - Details van specifieke lening
- `GET /api/?r=loans/{id}/payments` - Betalingen voor lening
- `GET /api/?r=loans/{id}/schedule` - Volledig aflossingsschema
- `POST /api/?r=loans/{id}/payments` - Nieuwe betaling toevoegen

Voorbeeld POST betaling:
```json
{
  "date": "2023-12-01",
  "amount": 500.00,
  "note": "Maandelijkse betaling"
}
```

## Ontwikkeling

Voor ontwikkelaars:

1. Installeer dependencies met `composer install`.
2. Gebruik een lokale PHP server: `php -S localhost:8000 -t public/`.
3. Database bestand: `data/leningen.db`.
4. Voor tests: geen geautomatiseerde tests aanwezig, handmatig testen.

### Project Structuur

- `public/` - Web root
- `includes/` - PHP includes (DB, auth, functies)
- `vendor/` - Composer dependencies
- `data/` - Database bestand

## Licentie

Dit project is open source. Controleer de LICENSE bestanden voor details.

## Bijdragen

Issues en pull requests zijn welkom op GitHub.