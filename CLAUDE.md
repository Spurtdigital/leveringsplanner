# Kolenbrander Leveringsplanner — Agent README

## Wat doet deze plugin?

Custom WooCommerce-plugin voor Kolenbrander Containers (containerverhuur). Klanten kiezen bij afrekenen een **leverdag + tijdvak**. De plugin bewaakt capaciteit, stuurt geautomatiseerde e-mails en synchroniseert leveringen naar Google Calendar. Na levering kan de klant de container via een unieke code aanmelden voor ophalen.

**Vereist**: WooCommerce (declared via `Requires Plugins` header + HPOS-compatibiliteit verklaard).

---

## Architectuur

```
kolenbrander-leveringsplanner.php   ← plugin entry: constanten, require, activation/deactivation hooks
includes/
  settings.php        KLP_Settings    ← admin UI + opties (één array in wp_options)
  holidays.php        KLP_Holidays    ← Nederlandse feestdagen (Pasen-algoritme + vaste data)
  checkout.php        KLP_Checkout    ← checkout-velden (datum + tijdvak)
  lockout.php         KLP_Lockout     ← capaciteitsbeheer (max leveringen per dag)
  order-meta.php      KLP_Order_Meta  ← post_meta CRUD + admin kolommen
  pickup.php          KLP_Pickup      ← ophaalaanmelding (rewrite URL + shortcode + AJAX)
  emails.php          KLP_Emails      ← e-mail motor (templates + cron-handlers)
  cron.php            KLP_Cron        ← WP-Cron planning
  google-calendar.php KLP_Google_Calendar ← OAuth2 + Calendar API (directe wp_remote_* calls)
templates/
  admin-settings.php  ← admin-pagina (4 tabs)
  pickup-form.php     ← ophaalformulier
assets/
  css/admin.css + checkout.css + pickup.css
  js/admin.js + checkout.js + pickup.js
```

### Naamgeving-conventies
- PHP-klassen: `KLP_` prefix, statische klassen (geen instanties — alle methoden zijn `static`).
- Option key: `klp_settings` (één associatief array, `wp_parse_args` met defaults).
- Post meta keys: `_klp_*` (underscore = verborgen voor standaard WooCommerce meta-box).
- AJAX actions: `klp_*`, nonces matchen de action name.
- Cron hooks: `klp_daily_*`, `klp_hourly_*`.
- CSS/JS handles: `klp-*`.

---

## Post meta keys

| Constante | Key | Waarde |
|---|---|---|
| `DATE_KEY` | `_klp_delivery_date` | `Y-m-d` |
| `TIME_SLOT_KEY` | `_klp_time_slot` | `morning` / `afternoon` |
| `PICKUP_CODE_KEY` | `_klp_pickup_code` | `KL-{order_id}-{8-char hash}` |
| `PICKUP_REQUESTED_KEY` | `_klp_pickup_requested` | `yes` / (leeg) |
| `PICKUP_REQUESTED_AT_KEY` | `_klp_pickup_requested_at` | MySQL datetime |
| `REMINDER_SENT_KEY` | `_klp_reminder_sent` | `yes` |
| `PICKUP_REMINDER_SENT_KEY` | `_klp_pickup_reminder_sent` | `yes` |
| `ESCALATED_REMINDER_SENT_KEY` | `_klp_escalated_reminder_sent` | `yes` |
| _(direct)_ | `_klp_gcal_event_id` | Google Calendar event ID |

---

## Modules

### KLP_Settings

Alle instellingen zitten in **één** `wp_options` entry (`klp_settings`). `get($key)` merged opgeslagen waarden met defaults via `wp_parse_args`. Dit voorkomt migratie-scripts bij nieuwe instellingen.

**Admin-pagina** heeft 4 tabs (JavaScript-gebaseerde tab-switching in `admin.js`):
1. **Instellingen** — tijdvak-labels, max per dag, e-mail adressen, reminder-termijnen
2. **Jaarkalender** — visuele kalender (jaar/maand view), klikken op een dag togglet sluitingsdag via AJAX (`klp_save_closed_dates`)
3. **E-mail teksten** — subject + body per e-mailtype, plaatshouders documentatie inline
4. **Google Calendar** — OAuth2-koppeling, agendakeuze, test-knop

**Sluitingsdagen** worden opgeslagen als newline-separated `dd-mm-yyyy` strings. In PHP worden ze naar `Y-m-d` geconverteerd voor vergelijking.

### KLP_Holidays

Berekent Nederlandse officiële feestdagen **algoritmisch** (geen externe API):
- Pasen via Meeus/Jones/Butcher algoritme (`easter_date()`)
- Goede Vrijdag, Paasmaandag, Hemelvaartsdag, Pinksteren en Pinkstermaandag worden van Pasen afgeleid
- Vaste feestdagen: Nieuwjaarsdag, Koningsdag (27 apr), Bevrijdingsdag (5 mei), Kerst I+II
- Resultaat wordt gecached in `static $cache` per jaar

> **Let op**: Bevrijdingsdag (5 mei) is elk jaar een feestdag in deze implementatie, niet alleen elk 5 jaar.

### KLP_Checkout

Voegt twee velden toe na het facturatieformulier:
1. jQuery UI Datepicker (format `dd-mm-yy`, NL lokalisatie, min = morgen, max = +60 dagen)
2. Tijdvak select (disabled totdat datum gekozen is)

Beschikbaarheidscontrole gebeurt via AJAX (`klp_check_availability`) na datumselectie.

**Datumformaat**: frontend gebruikt `dd-mm-yyyy`, opgeslagen als `Y-m-d`. `parse_date()` accepteert beide formaten.

**Validatie** (server-side, `validate_fields()`): datum aanwezig → geldig formaat → geen feestdag/sluitingsdag → geen zondag → niet volgeboekt → minimaal morgen. Elk foutpad heeft zijn eigen foutmelding.

### KLP_Lockout

Capaciteit wordt **live uit de database** gelezen (geen cache), zodat parallelle checkouts elkaar blokkeren. Telt orders met status `wc-processing`, `wc-completed`, `wc-on-hold`.

`get_full_dates()` haalt in één query alle volle datums op (via `HAVING cnt >= max`) voor de datepicker. `get_count()` kan optioneel ook per tijdvak filteren.

### KLP_Order_Meta

- Genereert ophaalcode bij order-opslag: `KL-{order_id}-{substr(wp_hash(...), 0, 8)}` (uppercase)
- Voegt twee extra admin-kolommen toe aan bestellingsoverzicht: **Ophaalstatus** en **GC** (Google Calendar sync status)
- Ondersteunt zowel klassieke post meta (`manage_edit-shop_order_columns`) als HPOS (`woocommerce_shop_order_list_table_columns`)
- `format_item_line()` filtert interne meta (underscore-prefix), WooCommerce product attributes (`pa_`-prefix) en lege "Geen …"-waarden eruit

### KLP_Pickup

Klanten melden de container aan via `/aanmelden-ophalen/?code=KL-1234-ABCD1234`.

**URL**: WordPress rewrite rule (`^aanmelden-ophalen/?$`), geregistreerd in `init` hook, `flush_rewrite_rules()` op activatie.

**Alternatief**: shortcode `[klp_pickup_form]` voor gebruik op een bestaande pagina.

**Flow**: code opgeven → AJAX `klp_request_pickup` → order opzoeken op code → mark als aangemeld → notificaties versturen (admin + klant).

### KLP_Emails

E-mails worden verstuurd via `wp_mail` met `text/html` content type. Ze worden **gewrapt in de WooCommerce e-mail template** (header/footer via `wc_get_template_html`).

CSS-inlining: als `Pelago\Emogrifier\CssInliner` beschikbaar is (via Composer of WooCommerce bundeled vendor), worden WooCommerce e-mail styles inline gezet. Fallback: `<style>` tag in `<head>`.

**Plaatshouders** in subject/body templates (beheerd via admin): `{customer_name}`, `{order_number}`, `{delivery_date}`, `{time_slot}`, `{days_before}`, `{pickup_url}`, `{site_name}`, `{date}`.

`{pickup_url}` wordt automatisch vervangen door een HTML-knop via `replace_pickup_url_with_button()`.

**E-mail typen**:
| Hook / methode | Aan | Wanneer |
|---|---|---|
| `klp_daily_admin_summary` | admin | dagelijks 07:00 — vandaag/morgen leveringen + niet aangemelde containers + ophaalverzoeken vandaag |
| `send_customer_reminder` | klant | X dagen vóór levering (instelling `reminder_days_before`, default 1) |
| `send_pickup_reminder` | klant | X dagen na levering zonder ophaalaanmelding (`pickup_reminder_days`, default 7) |
| `send_escalated_pickup_reminder` | klant | Y dagen na levering zonder ophaalaanmelding (`escalated_reminder_days`, default 21) |
| `send_pickup_notification` | admin + klant | direct bij ophaalaanmelding |

**Idem-potent**: elke herinnering slaat `yes` op in post meta na verzenden en controleert dat vóór verzenden.

### KLP_Cron

Drie WP-Cron jobs, gepland bij plugin-activatie:
- `klp_daily_admin_summary` — dagelijks 07:00
- `klp_hourly_reminder_check` — elk uur (controleert leveringsherinneringen)
- `klp_daily_pickup_reminder` — dagelijks 08:00 (controleert ophaalherinneringen)

Jobs worden bij deactivatie opgeruimd via `wp_unschedule_event`.

> **Aandachtspunt**: WP-Cron wordt alleen getriggerd door pageviews. Op laag-traffic sites kan tijdstip afwijken. Overweeg een echte cron via server-crontab (`wp-cron.php` of WP-CLI) voor productie.

### KLP_Google_Calendar

OAuth2-flow via Google Cloud Console:
1. Admin slaat Client ID + Secret op
2. Klik "Koppelen met Google" → redirect naar Google consent screen
3. Callback (`admin_init`, `?klp_gc_oauth=1`) wisselt code voor access + refresh token
4. Agendaoverzicht wordt opgehaald en opgeslagen; sync wordt ingeschakeld

**Tokens**: access token wordt 3500 seconden geldig gehouden (`get_valid_token()` doet auto-refresh via refresh token). Tokens worden in de settings-optie bewaard.

**Google API calls** gaan via `wp_remote_*` (geen Google PHP SDK). Directe HTTP calls naar `https://www.googleapis.com/calendar/v3`.

**Event beschrijving**: bevat bezorgadres, factuuradres, productenlijst en ophaalcode in plain text met Unicode bold headers (𝐁𝐄𝐒𝐓𝐄𝐋𝐍𝐔𝐌𝐌𝐄𝐑, etc.) voor leesbaarheid in de Google Calendar app.

**Status-triggers**:
- Order checkout → event aanmaken
- Status → processing/completed/on-hold → event aanmaken (als nog niet bestaat)
- Status → cancelled/refunded/failed → event verwijderen
- Handmatig via order-action "Verstuur naar Google Calendar"

---

## Beveiliging

- Alle AJAX-handlers: `check_ajax_referer()` of `current_user_can('manage_options')` check
- Alle output: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`
- Database queries: uitsluitend `$wpdb->prepare()`
- Settings sanitization: per veld type (`absint`, `sanitize_email`, `sanitize_text_field`, `sanitize_textarea_field`)
- OAuth callback: alleen toegankelijk voor `manage_options`-gebruikers

---

## Bekende ontwerpkeuzes en afwegingen

| Keuze | Reden |
|---|---|
| Alle klassen statisch (geen instanties) | Eenvoud voor een single-site plugin; geen dependency injection nodig |
| Één wp_options entry voor alle instellingen | Één DB-read volstaat; `wp_parse_args` met defaults voorkomt migratie-scripts |
| Geen Google PHP SDK, directe `wp_remote_*` | Geen Composer-dependency; lighter bundle; de benodigde API-surface is klein |
| Datum opgeslagen als `Y-m-d` (ISO) | Sorteert lexicografisch correct; compatible met MySQL date vergelijkingen |
| Frontend datum `dd-mm-yyyy` | Gebruiksvriendelijk voor NL-gebruikers; `parse_date()` handelt conversie af |
| Capaciteit live uit DB | Correctheid boven performance; bij containerverhuur zijn parallelle checkouts een reëel scenario |
| WP-Cron voor reminders | Geen server-cron vereist; werkt out-of-the-box; voldoende voor dit volume |
| Sluitingsdagen als newline-separated string | Simpelste opslag; kleine dataset; admin-kalender toggle via AJAX |
| HPOS-compatibility declared | WooCommerce 7+ vereist expliciete declaratie; anders werkt HPOS niet |
| Ophaalcode bevat order_id + hash | Ophalen op code is een simpele postmeta-query; order_id in de code maakt debuggen makkelijker |

---

## Installatie & setup

1. Plugin uploaden naar `wp-content/plugins/`
2. Activeren in WP Admin → Plugins
3. Na activatie: `flush_rewrite_rules()` wordt automatisch uitgevoerd
4. Ga naar **Leveringsplanner** in het menu
5. Stel tijdvak-labels, max per dag en e-mailadressen in
6. Koppel Google Calendar (optioneel, zie tab Google Calendar)
7. De ophaal-pagina (`/aanmelden-ophalen/`) werkt direct via de rewrite rule — geen aparte pagina nodig

---

## Toekomstige uitbreidingen (niet geïmplementeerd)

- HPOS-native queries (huidige queries gaan via `postmeta`, werkt ook met HPOS in compatibiliteits-modus)
- Herinnering voor "morgen levering" is voorbereid in settings (`email_subject_tomorrow`, `email_body_tomorrow`) maar er is geen aparte cron-trigger voor — die e-mail wordt niet automatisch verstuurd
- Meertaligheid: textdomain `kolenbrander-leveringsplanner` is geregistreerd, `.po`-bestanden ontbreken nog
