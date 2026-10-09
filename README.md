# vulqen/symfony-bundle

Instrumentacja Symfony na paczce `vulqen/php-sdk`: transakcja na każde żądanie HTTP, wyjątki i zapytania Doctrine DBAL. Paczka Composer: `vulqen/symfony-bundle`.

Kanon: [vulqen-docs](https://github.com/DeveloperLancer/vulqen-docs), lokalnie `../vulqen-docs`. Opis: `plan/31-symfony-bundle.md`. Zasady: [AGENTS.md](AGENTS.md).

PHP 8.2+, Symfony 6.4 albo 7.4, Doctrine DBAL 3.8+ albo 4 (opcjonalnie).

## Instalacja

Paczki są prywatne. Composer potrzebuje obu repozytoriów VCS i tokenu GitHub z odczytem (`composer config --global github-oauth.github.com <token>` albo `COMPOSER_AUTH`):

```bash
composer config repositories.vulqen-php-sdk vcs https://github.com/DeveloperLancer/vulqen-sdk-php.git
composer config repositories.vulqen-symfony-bundle vcs https://github.com/DeveloperLancer/vulqen-bundle.git
composer require vulqen/symfony-bundle:^0.1
```

Recipe Flex jeszcze nie ma (D-066), więc trzy kroki są ręczne.

1. `config/bundles.php`:

   ```php
   Vulqen\SymfonyBundle\VulqenBundle::class => ['all' => true],
   ```

2. `config/packages/vulqen.yaml`:

   ```yaml
   vulqen:
       dsn: '%env(default::VULQEN_DSN)%'
       environment: '%env(default:vulqen.default_environment:VULQEN_ENVIRONMENT)%'
       release: '%env(default::VULQEN_RELEASE)%'
       excluded_routes: []

   when@test:
       vulqen:
           enabled: false
   ```

3. `.env`:

   ```dotenv
   VULQEN_DSN=
   ```

   Prawdziwy DSN z dashboardu trafia do `.env.local` albo do sekretów, nie do gita.

## Co zbiera

| Zdarzenie | Skąd |
|---|---|
| transakcja `http.server` | nazwa trasy z `_route`, bez dopasowania `http.unmatched`; status, pamięć, CPU |
| span `db.query` | każde zapytanie DBAL, tekst po normalizacji SDK, bez parametrów |
| błąd | wyjątek z `kernel.exception`, bez `HttpException` poniżej 500 |

Trasy z `excluded_routes` i trasy wewnętrzne Symfony (`_wdt`, `_profiler`) nie wysyłają nic. Wysyłka idzie w `kernel.terminate`, czyli po `fastcgi_finish_request()` na PHP-FPM. Nagłówki, ciało żądania i parametry SQL nie trafiają do zdarzeń.

## Wyłączenie

`enabled: false` nie rejestruje żadnego słuchacza ani middleware. Pusty `VULQEN_DSN` rejestruje słuchacze, które wracają od razu.

## Testy

```bash
composer config repositories.vulqen-php-sdk '{"type":"path","url":"../vulqen-sdk-php","options":{"versions":{"vulqen/php-sdk":"0.1.0"}}}'
composer update
vendor/bin/phpunit
composer phpstan
```

Repozytorium `path` zostaje tylko w drzewie roboczym. `bin/guard-no-path-repo` w CI pada, gdy wejdzie do `composer.json`.
