# Changelog

Format z [05-repozytoria-i-wydania.md](https://github.com/DeveloperLancer/vulqen-docs/blob/main/plan/05-repozytoria-i-wydania.md): po co zmiana i czy upgrade wymaga kroku.

## 0.2.0

Pełny ślad nie musi iść w 100%, a konsola, Messenger i HttpClient widać w tym samym śladzie (Faza 7).

- Komenda konsoli jest transakcją `console.command`. `messenger:consume` i `messenger:consume-messages` nie otwierają transakcji konsoli.
- Middleware Messengera: wysyłka dokłada `queue.publish`, odbiór otwiera `queue.process` z tym samym `trace_id`.
- Dekorator `HttpClient` dokleja `traceparent` i span `http.client`. Host ingestu nie dostaje nagłówka ani spana.
- Zależność `vulqen/php-sdk` `^0.2`.

Kroki przy upgrade: serwer z Fazy 7. SDK poniżej 0.2 nie spełnia karety.

## 0.1.0

Pierwsze wydanie z instrumentacją, żeby aplikacja Symfony wysyłała żądania HTTP, wyjątki i SQL do Vulqena (Faza 4).

- Transakcja `http.server` na żądanie główne, nazwa z trasy albo `http.unmatched`.
- Wyjątki z `kernel.exception` z ramkami i `in_app`. `HttpException` poniżej 500 nie jest błędem.
- Middleware Doctrine DBAL 3 i 4 (`doctrine.middleware`): span `db.query` z tekstem po normalizacji.
- `excluded_routes`, `enabled: false` i pusty DSN.
- Reset stanu klienta przez `kernel.reset` dla procesów długo żyjących.
- Zależność `vulqen/php-sdk` `^0.1` z prywatnego repozytorium VCS.

Kroki przy upgrade: z wersji `0.0.x` dopisać repozytorium VCS SDK i plik `config/packages/vulqen.yaml` z README.
