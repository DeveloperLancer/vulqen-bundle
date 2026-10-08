# AGENTS.md — vulqen-bundle

Umieszczenie docelowe: `AGENTS.md` w repozytorium kodu. Linki `../vulqen-docs/` są liczone z tamtego katalogu.

Paczka `vulqen/symfony-bundle`. Kanon jest w repozytorium `vulqen-docs`, nie tutaj.

Lokalnie: [../vulqen-docs/README.md](../vulqen-docs/README.md).  
Na GitHubie: [vulqen-docs](https://github.com/DeveloperLancer/vulqen-docs).

Ten plik jest kopią [../vulqen-docs/repos/vulqen-bundle.AGENTS.md](../vulqen-docs/repos/vulqen-bundle.AGENTS.md). Przy zmianie zasad najpierw szablon.

## Zakres

Integracja Symfony: kernel, Doctrine DBAL, od Fazy 7 Messenger, konsola i HttpClient, od Fazy 10 komenda `vulqen:agent`. Opis: [../vulqen-docs/plan/31-symfony-bundle.md](../vulqen-docs/plan/31-symfony-bundle.md). Rdzeń: paczka `vulqen/php-sdk`, opis [../vulqen-docs/plan/30-sdk-php.md](../vulqen-docs/plan/30-sdk-php.md).

PHP 8.2+, Symfony 6.4 i 7.x. Bundle nie dociąga Messengera ani Doctrine jako twardych zależności, jeśli aplikacja ich nie ma.

## Zasady

- Pusty DSN albo `enabled: false` zostawia aplikację w spokoju.
- Wysyłka wisi na `kernel.terminate`. Timeouty i obwód są w SDK i nie wolno ich obchodzić dłuższym wywołaniem w bundlu.
- Proces długo żyjący czyści stan po każdej transakcji (`ResetInterface`).
- Wywołanie na host ingestu nie dostaje spana ani `traceparent`.
- Nazwa transakcji HTTP to nazwa trasy, nie URI.
- Lokalne repozytorium Composer typu `path` dopisuje się komendą i nie commituje. CI pada, gdy `"type": "path"` wejdzie do `composer.json`.
- Katalog `.idea/` nie wchodzi do gita.
- Macierz CI obejmuje co najmniej PHP 8.2 z Symfony 6.4 oraz PHP 8.3 z Symfony 7.4, gdy istnieje kod instrumentacji (od Fazy 4).

## Faza bieżąca

Patrz tabela w [../vulqen-docs/README.md](../vulqen-docs/README.md).
