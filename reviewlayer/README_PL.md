# ReviewLayer

ReviewLayer 1.3.5 to niezależna nakładka do komentowania prototypów stron. Klient może przypiąć uwagę do elementu strony, prowadzić rozmowę, oznaczyć ją jako rozwiązaną i wrócić do niej na innym urządzeniu. Instalacja działa bez Node.js, procesu build, MySQL, CDN i usług SaaS.

## Wymagania serwera

- PHP 8.0 lub nowszy.
- Zapisywalny przez PHP katalog `reviewlayer/data/` i `reviewlayer/data/backups/`.
- Zalecane PDO SQLite. Gdy `pdo_sqlite` nie istnieje, aplikacja automatycznie wybiera JSON z `flock` i atomową podmianą pliku.
- Ta sama domena dla strony i katalogu ReviewLayer. Instalacja cross-origin wymagałaby własnej konfiguracji CORS i cookies, której wersja domyślna celowo nie włącza.
- Apache odczytujący `.htaccess` albo równoważna blokada katalogu `data` w konfiguracji Nginx/IIS.

Nie ustawiaj `777`. Zwykle wystarczą katalogi `750`/`770` i pliki `640`/`660`, zależnie od użytkownika PHP na hostingu. Najmniejsze uprawnienia pozwalające PHP zapisywać dane są właściwym wyborem.

## Instalacja przez FTP

1. Wgraj cały katalog `reviewlayer/` do katalogu publicznego prototypu.
2. Skopiuj `config.example.php` jako `config.php` i wpisz wybrane kody dostępu.
3. Upewnij się, że `data/` oraz `data/backups/` są zapisywalne dla PHP.
4. Otwórz `/reviewlayer/`. Diagnostyka pokaże wersję PHP, PDO, SQLite, prawa zapisu, wybrany storage, konfigurację i adres health API. Nie pokazuje sekretów.
5. Dodaj poniższe dwa znaczniki w sekcji `<head>` wspólnego layoutu:

```html
<script src="/reviewlayer/embed.js" data-project="default" data-lang="en" defer></script>
<meta name="robots" content="noindex,nofollow">
```

Wywołanie `embed.js` powinno znajdować się w `<head>` i mieć atrybut `defer`. Skrypt sam ustala katalog instalacji na podstawie własnego `src`, ładuje CSS, moduły oraz API. Nie podawaj adresu bieżącej podstrony ani API.

Znacznik `<meta name="robots" content="noindex,nofollow">` powinien znaleźć się bezpośrednio pod wywołaniem skryptu na każdej podstronie prototypu, najlepiej przez wspólny layout. Informuje wyszukiwarki, aby nie indeksowały strony ani nie podążały za jej linkami. Nie jest jednak zabezpieczeniem dostępu — osoba znająca adres nadal może otworzyć prototyp.

Przykład osobnego projektu z wymuszonym polskim interfejsem:

```html
<script src="/reviewlayer/embed.js" data-project="shop-redesign" data-lang="pl" defer></script>
<meta name="robots" content="noindex,nofollow">
```

Plik `index.html` w katalogu nadrzędnym tego repozytorium jest wyłącznie stroną testową integracji. Produkcyjna aplikacja znajduje się w całości w `reviewlayer/`.

## Adresy, podstrony i SPA

Ten sam skrypt obsługuje wszystkie podstrony. Klucz strony zawiera protokół, host, port, pathname, posortowane query i hash. Różne porty, ścieżki, zwykłe parametry query oraz trasy hash są rozdzielone. Końcowy ukośnik poza stroną główną jest normalizowany.

Parametry techniczne `reviewlayer`, `reviewlayer_action`, `reviewlayer_lang`, `reviewlayer_debug` i `reviewlayer_token` nie wpływają na klucz. Dlatego `/oferta?variant=2&reviewlayer=clear` nadal wskazuje dane `/oferta?variant=2`.

Nakładka obserwuje `history.pushState`, `history.replaceState`, `popstate` i `hashchange`. Przy nawigacji anuluje stare żądanie, zamyka rozmowę poprzedniej strony i pobiera tylko pinezki nowej trasy bez przeładowania dokumentu.

## Język

Domyślnym językiem ReviewLayer jest angielski. `data-lang` akceptuje `auto`, `pl` lub `en`; pominięcie tego atrybutu również uruchamia interfejs po angielsku. Dla `auto` kolejność to: zapisane ustawienie, `html[lang]`, `navigator.languages`, `navigator.language`, angielski fallback. Jawne `pl` lub `en` ma pierwszeństwo przy starcie. Język można zmienić w ustawieniach bez przeładowania; wybór trafia do `localStorage`.

Wspólne pliki `assets/i18n/pl.json` i `assets/i18n/en.json` zawierają teksty nakładki oraz diagnostyki.

## Projekty i konfiguracja

`data-project` rozdziela dane wielu prototypów w jednej instalacji. Dozwolone są litery, cyfry, kropki, `_` i `-`, maksymalnie 64 znaki. Numeracja pinezek jest osobna dla projektu.

`config.php` zwraca tablicę PHP. Najważniejsze opcje:

- `PROJECT_ACCESS_CODE` — opcjonalny kod projektu; używany, gdy `ALLOW_GUESTS` wynosi `false`.
- `ADMIN_ACCESS_CODE` — opcjonalny kod do kopii, czyszczenia i administracyjnego usuwania. Zachowanie bez kodu określa `ALLOW_ADMIN_WITHOUT_CODE`; autor nadal może usuwać własne wpisy, jeśli odpowiednie opcje są włączone.
- `ALLOW_GUESTS` — dostęp bez kodu projektu.
- `ALLOW_AUTHOR_DELETE_OWN_MESSAGES` i `ALLOW_AUTHOR_DELETE_OWN_PINS` — soft delete własnych danych.
- `ALLOW_ADMIN_WITHOUT_CODE` — gdy `false`, kopie i czyszczenie administracyjne pozostają wyłączone do czasu ustawienia kodu administratora.
- `CREATE_BACKUP_BEFORE_PURGE` — automatyczny eksport przed trwałym czyszczeniem.
- `STORAGE_MODE` — `auto`, `sqlite` albo `json`.
- `MOBILE_BREAKPOINT` i `DESKTOP_BREAKPOINT` — domyślnie 600 i 1024 px.
- `PERSISTENT_RATE_LIMIT` — trwały limit żądań według skrótu adresu IP, odporny na otwieranie nowych sesji.
- `MAX_PINS_PER_AUTHOR`, `MAX_MESSAGES_PER_PIN`, `MAX_TOTAL_PINS` i `MAX_TOTAL_MESSAGES` — opcjonalne limity demo; `0` wyłącza dany limit.
- `MAX_BACKUPS` — maksymalna liczba zachowanych kopii; `0` oznacza brak automatycznego usuwania.
- `NOTIFICATIONS_ENABLED` — włącza ręczne powiadomienia e-mail. Wiadomości korzystają z natywnego transportu PHP `mail()` serwera; ReviewLayer nie przechowuje danych logowania SMTP.
- `NOTIFICATION_FROM_EMAIL` — opcjonalny stały nadawca. Gdy jest pusty, ReviewLayer wyprowadza `reviewlayer@bieżący-host`; ustaw istniejący adres w tej samej domenie, jeśli wymaga tego hosting.
- `NOTIFICATION_PUBLIC_BASE_URL` — opcjonalny pełny URL katalogu ReviewLayer dla linków potwierdzających. Pozostaw pusty dla kopii instalowanych w różnych domenach prototypów.
- limity czasowe, godzinowe i dzienne powiadomień oraz weryfikacji — trwała ochrona przed nadużyciami, niezależna od sesji przeglądarki.
- limity tekstu i okno rate limitingu.

Najprostsza konfiguracja dla prototypu wygląda tak:

```php
'PROJECT_ACCESS_CODE' => '',
'ADMIN_ACCESS_CODE' => 'wpisz-tutaj-wlasny-kod',
'ALLOW_GUESTS' => true,
```

Kod znajduje się wyłącznie w wykonywanym po stronie serwera `config.php`. Nie wpisuj go do `embed.js`, repozytorium ani dokumentacji. Kod projektu podany w ustawieniach jest przechowywany tylko w `sessionStorage`. Kod administratora nie jest zapisywany przez frontend. Starsze pola `PROJECT_ACCESS_CODE_HASH` i `ADMIN_ACCESS_CODE_HASH` nadal są obsługiwane opcjonalnie; jeśli ustawisz hash, ma on pierwszeństwo przed kodem jawnym.

## Używanie pinezek

Przy pierwszym komentarzu formularz prosi o imię. Imię i losowy UUID autora są zapisywane w `localStorage`; imię można zmienić w ustawieniach.

Każdy nowy komentujący w projekcie otrzymuje kolejny kolor z palety dziesięciu barw; jedenasty użytkownik ponownie dostaje kolor pierwszy. Pinezki i etykiety autora zachowują to przypisanie, rozwiązane pinezki używają mniej nasyconego odpowiednika, a Ustawienia pokazują wszystkich zarejestrowanych komentujących projektu. Zmiana wyświetlanego imienia nie zmienia koloru.

### Ręczne powiadomienia e-mail

Ikona wysyłania otwiera listę komentujących w projekcie. Kliknięcie „Powiadom…” wysyła jedną krótką wiadomość wprost przez natywny transport pocztowy PHP serwera. ReviewLayer nigdy nie wysyła e-maili po dodaniu komentarza, według harmonogramu ani z crona. Każda wiadomość wymaga świadomego kliknięcia. Polski lub angielski szablon jest wybierany na podstawie języka zapisanego przez odbiorcę.

Wiadomość wymienia wyłącznie pinezki utworzone przez nadawcę oraz zdarzenia wykonane przez niego na bieżącej stronie, zaczynając od najnowszych pinezek. Kolejne komentarze są grupowane (`komentarz ×3`), natomiast `rozwiązana` i `otwarta ponownie` zachowują kolejność chronologiczną. Niepowiązane pinezki ani treść komentarzy nigdy nie są umieszczane w e-mailu.

Historia statusów zaczyna się od instalacji tej wersji. Istniejące pinezki, komentarze, numeracja i bieżące statusy pozostają bez zmian; późniejsze zmiany statusu dopisują się automatycznie. SQLite tworzy tabelę `pin_status_events` przez `CREATE TABLE IF NOT EXISTS`, a JSON dodaje opcjonalną tablicę `status_events`. Przenośne kopie zawierają tę historię, a starsze backupy bez niej nadal można przywrócić.

Komentujący może dodać adres w Ustawieniach po utworzeniu pinezki lub komentarza. Zanim będzie można go powiadamiać, musi otworzyć jednorazowy link potwierdzający. Inne przeglądarki otrzymują wyłącznie imię oraz informację gotowy/brak adresu; sam e-mail nigdy nie jest zwracany przez API. Nadawca wybiera nieprzewidywalny identyfikator odbiorcy, a nie dowolny adres e-mail.

Adresy są szyfrowane uwierzytelnionym algorytmem w osobnym, chronionym magazynie `data/notifications.json`. Przeglądarka przechowuje losowy sekret tożsamości, a serwer tylko jego hash. Domyślnie klucz szyfrujący powstaje jako `data/.notification-key`; oba pliki chroni `data/.htaccess`. Pełna kopia hostingu musi zachować razem klucz i zaszyfrowany magazyn. Przenośne kopie pinezek celowo nie zawierają kontaktów.

Gdy ten sam adres e-mail zostanie potwierdzony w innej przeglądarce w tym samym projekcie, ReviewLayer łączy ją z najstarszym już potwierdzonym komentującym. Zachowane zostają dotychczasowe imię, kolor, pinezki, wiadomości, statusy, numeracja i daty. Poprzednie identyfikatory przeglądarek stają się aliasami po stronie serwera, dlatego stare karty nadal działają. Operacja zmienia wyłącznie dane i nie wprowadza migracji schematu SQLite. Stan przeczytane/nieprzeczytane celowo pozostaje lokalny dla każdej przeglądarki. Nie używaj jednego wspólnego adresu dla różnych osób w projekcie, ponieważ jego potwierdzenie połączy ich tożsamości komentujących.

Ręczna wysyłka ma limity dla nadawcy, odbiorcy, projektu i zahashowanego IP. Weryfikacja adresu ma osobny cooldown i limit dzienny. Wymagane są także CSRF, kontrola tego samego originu, zasady dostępu do projektu i sekret przeglądarki. Turnstile ani widoczna CAPTCHA nie są używane, dlatego publiczna instalacja powinna zachować ostrożne limity i najlepiej kod dostępu do projektu.

Funkcja PHP `mail()` musi już działać na hostingu tak jak dla zwykłego formularza kontaktowego. Skonfiguruj politykę domeny nadawcy, w miarę możliwości SPF, DKIM i DMARC. Wynik `true` oznacza przyjęcie wiadomości przez lokalny transport, a nie gwarancję końcowego doręczenia.

1. Kliknij „Dodaj pinezkę”.
2. Wskaż miejsce na stronie. Podświetlenie jest częścią nakładki i nie modyfikuje elementu prototypu.
3. Wpisz pierwszą uwagę i zapisz. Anulowanie lub `Escape` usuwa pinezkę tymczasową.
4. Kliknij zapisaną pinezkę, aby otworzyć rozmowę, odpowiedzieć, zmienić status, usunąć wiadomość lub całą pinezkę.
5. Przycisk odświeżenia pobiera aktualną rozmowę ręcznie.
6. „Pokaż pinezkę” w nagłówku rozmowy zamyka panel, w razie potrzeby odsłania pinezkę, przewija ją do widoku i krótko podświetla.

Komentarze są renderowane wyłącznie jako tekst. Data pochodzi z serwera UTC i jest wyświetlana lokalnie.

### Kotwiczenie

ReviewLayer zapisuje stabilny selektor, fingerprint elementu, pozycję względną 0–1, geometrię elementu i dokumentu, scroll, viewport oraz DPR. Przy odtwarzaniu ocenia selektor i fingerprint, a następnie liczy pozycję względem aktualnego `getBoundingClientRect()`. Po zmianie DOM, rozmiaru, orientacji lub scrolla pozycje aktualizuje `requestAnimationFrame`. Gdy element zniknął, używana jest pozycja dokumentu i pinezka dostaje znacznik niepewnego kotwiczenia.

## Widoczność i filtry

Pasek ReviewLayer zawiera „Pokaż/ukryj pinezki” oraz „Dodaj pinezkę”. „Ukryj pinezki” nie usuwa komentarzy ani nie modyfikuje bazy danych. Ukryta warstwa ma wyłączone `visibility` i `pointer-events`, więc nie przechwytuje kliknięć. Pasek i otwarta rozmowa pozostają dostępne.

Przełącznik pomiędzy ikonami strzałek przenosi pasek na dół lub na górę widoku. Domyślnie pasek znajduje się na dole, a wybór jest zapisywany dla projektu jako `reviewlayer:{project}:toolbar-position`.

Preferencja jest zapisywana jako `reviewlayer:{project}:pins-visible`, wspólna dla podstron projektu i osobna dla innych projektów. `Alt + P` przełącza widoczność poza polami tekstowymi. Pinezkę można dodać przy ukrytych istniejących pinezkach; pinezka tymczasowa jest widoczna, a po zapisie globalna preferencja pozostaje bez zmian.

Filtry `Wszystkie | Mobile | Tablet | Desktop` wybierają pinezki według viewportu utworzenia. Ukrywanie nie resetuje aktywnego filtra.

Ikona hamburgera bezpośrednio przed ustawieniami otwiera listę wszystkich pinezek bieżącego projektu, również z innych podstron. Każda pozycja pokazuje numer, status, adres i fragment pierwszej wiadomości. Kliknięcie przechodzi na właściwą podstronę i automatycznie otwiera rozmowę; tymczasowy parametr `reviewlayer_pin` jest następnie usuwany z adresu.

Lista projektu oznacza nową pinezkę niebieską kropką, a nowe odpowiedzi — pomarańczową. Niebieska kropka na ikonie menu informuje, że co najmniej jedna pinezka projektu nadal ma nieprzeczytaną aktywność; wskaźnik odświeża się przy uruchomieniu aplikacji i po powrocie do okna przeglądarki. Kliknięcie kropki wpisu lub otwarcie rozmowy oznacza tę pozycję jako przeczytaną. Rozmowa otwarta z tej listy zawiera akcję „Wróć do listy pinezek”, również po przejściu na inną podstronę projektu. Stan odczytu jest osobny dla projektu i zapisany wyłącznie w bieżącej przeglądarce pod kluczem `reviewlayer:{project}:read-state`; nie jest synchronizowany jak konto użytkownika. Pierwsze otwarcie listy zapisuje lokalny punkt odniesienia i nie oznacza starszych wpisów jako nowych.

## Usuwanie i bezpieczne czyszczenie

Zwykłe usuwanie pinezki lub wiadomości ustawia `deleted_at` (soft delete). Pojedyncza pinezka wymaga krótkiej frazy `DEL`; jeśli kod administratora nie jest skonfigurowany, aplikacja nie pyta o niego. Panel administracyjny otwórz adresem:

```text
https://prototype.example.com/?reviewlayer=clear
```

Samo żądanie GET niczego nie usuwa. Panel wymaga zakresu, trybu i frazy `DELETE`; kod administratora jest wymagany tylko wtedy, gdy został skonfigurowany. Dla całej instalacji obowiązuje dokładna fraza `DELETE ALL REVIEWLAYER DATA`. Po zamknięciu parametr jest usuwany z adresu.

Zakresy:

- bieżąca strona — `project_key + page_key`;
- bieżący projekt — wszystkie jego podstrony;
- rozwiązane w projekcie — tylko status `resolved`;
- wszystkie dane — wszystkie projekty, zawsze trwały purge.

Soft delete zachowuje historię. Permanent purge fizycznie usuwa rekordy. Trwałe czyszczenie projektu lub wszystkich danych usuwa też zaszyfrowane profile powiadomień, które nie należą już do zachowanego komentatora. Czyszczenie projektu nie resetuje liczników innych projektów. Backend ponownie kanonizuje `page_url`, porównuje klucz, waliduje dane, sprawdza kod administratora, zapisuje log i używa transakcji lub blokady pliku.

## Backup i przywracanie

W panelu „Ustawienia” znajduje się osobna sekcja „Kopia zapasowa”. Przycisk „Utwórz kopię teraz” zapisuje pełny eksport wszystkich projektów w `data/backups/` bez usuwania lub zmieniania danych. Jeśli kod administratora został skonfigurowany, aplikacja poprosi o niego przed wykonaniem kopii.

Przy `CREATE_BACKUP_BEFORE_PURGE = true` trwałe czyszczenie najpierw zapisuje przenośny JSON w `data/backups/`. Zawiera wersję formatu, projekty i liczniki, pinezki, wiadomości, statusy, daty, kotwiczenie, viewport i dane przeglądarki. Kontakty powiadomień są wyłączone, ponieważ wymagają klucza szyfrującego konkretnej instalacji. Jeśli backup zawiedzie, purge jest blokowany, chyba że administrator jawnie zaznaczy kontynuację bez kopii.

Przywracanie zastępuje aktualne dane całą zawartością backupu. Najpierw wykonaj dodatkową kopię katalogu `data`, włącz tryb konserwacyjny i z katalogu `reviewlayer` uruchom:

```bash
php tools/restore.php reviewlayer-backup-2026-07-19T143200Z-abc123.json
```

Narzędzie działa wyłącznie w PHP CLI, przyjmuje tylko nazwę pliku znajdującego się w `data/backups/`, waliduje format i odtwarza aktualnie wybrany storage. Na hostingu bez CLI poproś administratora o uruchomienie polecenia; celowo nie istnieje publiczny endpoint przywracania.

## SQLite i awaryjny JSON

W `auto` aplikacja wybiera `data/reviewlayer.sqlite`, jeśli istnieje `pdo_sqlite`. Schemat i baza powstają automatycznie. Operacje wielorekordowe używają transakcji i przygotowanych zapytań.

Bez SQLite używany jest `data/reviewlayer.json`, osobny plik blokady i `data/admin.log`. Zapis powstaje w pliku tymczasowym, a następnie jest atomowo podmieniany. JSON nadaje się do małych prototypów; SQLite lepiej obsługuje większy ruch i wielu równoczesnych komentujących.

## Ochrona danych

Dołączone `.htaccess` wyłącza listing i blokuje pobieranie `data/` oraz backupów. W Nginx/IIS trzeba odtworzyć tę regułę, np. zwrócić `403` dla `/reviewlayer/data/`. Sprawdź to z zewnątrz, próbując pobrać nazwę testowego pliku — odpowiedź nie może zawierać danych.

API stosuje walidację, prepared statements, UUID v4, limity, CSRF oparty o sesję, kontrolę Origin, rate limiting, soft delete i porównanie kodów przez `hash_equals` oraz spójne odpowiedzi JSON. To zabezpieczenia odpowiednie dla prototypu, nie zamiennik pełnego systemu kont i audytu dla danych wrażliwych. `noindex, nofollow` ogranicza indeksowanie, ale nie stanowi kontroli dostępu.

## Rozwiązywanie problemów

- Brak paska: sprawdź konsolę, ścieżkę `src`, MIME dla `.js` i dostęp do `assets/i18n/*.json`.
- API niedostępne: otwórz `/reviewlayer/api/index.php?action=health` i diagnostykę `/reviewlayer/`.
- Błąd zapisu: popraw właściciela/uprawnienia `data/`, nie używaj bez potrzeby `777`.
- SQLite niedostępne: pozostaw `STORAGE_MODE=auto` dla JSON albo włącz `pdo_sqlite`.
- Uszkodzony JSON: odsuń wadliwy plik, przywróć ostatni backup i sprawdź wolne miejsce.
- Konflikty zapisu: sprawdź obsługę `flock`; przy większym ruchu użyj SQLite.
- Błędny kod: sprawdź, czy `config.php` zawiera dokładnie ten sam `ADMIN_ACCESS_CODE`; po zmianie kodu używaj nowej wartości.
- Pinezka niepewna: element zniknął lub zmienił identyfikację; ReviewLayer pokazuje pozycję zapasową.
- SPA nie przełącza danych: router musi zmieniać `window.location` przez History API albo hash.
- Nginx/IIS: `.htaccess` jest ignorowany, więc blokadę `data` dodaj w konfiguracji serwera.

## Testy

Uruchom testy statyczne i klucza strony z katalogu projektu:

```bash
node --test reviewlayer/tests/*.test.mjs
```

Pełna lista ręcznych scenariuszy przeglądarkowych znajduje się w `tests/acceptance-checklist.md`.

## Pełne odinstalowanie

1. Wykonaj backup, jeśli dane mają zostać zachowane.
2. Usuń jedyny znacznik `embed.js` ze wspólnego layoutu.
3. Usuń katalog `reviewlayer/` przez FTP. To usuwa aplikację, bazę, JSON, logi i backupy.
4. Opcjonalnie usuń z przeglądarek klucze `reviewlayer:*` z `localStorage` i `sessionStorage`.

## Ograniczenia

Kotwiczenie jest odporne na typowe zmiany responsywne i przesunięcia treści, ale po całkowitej wymianie elementu bez stabilnych atrybutów może przejść na pozycję zapasową. Nakładka nie przechodzi do wnętrza obcych iframe ani zamkniętych Shadow DOM. Domyślna wersja zakłada instalację same-origin. Tryb JSON jest przeznaczony dla małych zespołów i nie zastępuje transakcyjnej bazy przy dużym ruchu.
