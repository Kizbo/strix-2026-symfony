**Temat:** Przejęcie projektu: obsługa webhooków płatności, zakres na dziś i kilka pytań

Dzień dobry,

od dziś przejmuję projekt odbierania webhooków płatności i powiadamiania zespołu sprzedaży. Przeczytałem dotychczasowe ustalenia i zanim zacznę pisać kod, 
chcę potwierdzić z Państwem zakres oraz kilka rzeczy, których nie da się ustalić bez Państwa udziału.

## Co dostarczę w pierwszej wersji

System ma działać od dziś, więc PoC skupia się na tym, od czego zależą pieniądze i dane - jednocześnie dowożąc szybki efekt:

- Odbieranie informacji o płatnościach od bramki płatniczej. System przyjmie tylko wiadomości, które faktycznie pochodzą od bramki, a pozostałe odrzuci.
- Odporność na duplikaty. Jeśli bramka wyśle tę samą informację kilka razy, nawet w tym samym momencie, system obsłuży ją tylko raz. Zespół sprzedaży nie dostanie podwójnych powiadomień.
- Obsługę informacji, które przychodzą w złej kolejności. Jeśli potwierdzenie płatności dotrze przed informacją o samej płatności, system je zachowa i obsłuży, gdy dotrze brakująca informacja. Nic nie zginie.
- Powiadomienia dla zespołu sprzedaży. Jeśli usługa powiadomień chwilowo nie działa, system ponowi próbę kilka razy. Powiadomienia, których nie udało się dostarczyć, trafią na osobną listę, skąd można je wysłać ponownie. Nie zostaną utracone.
- Błędne lub niekompletne dane zostaną odrzucone, a każdy taki przypadek zostanie zapisany, żeby można było sprawdzić jego przyczynę.

## Co proponuję przesunąć na kolejne etapy

- **Message broker (np. RabbitMQ).** Przy obecnym ruchu kolejka w bazie danych daje te same gwarancje dostarczenia, a jest mniej infrastruktury do utrzymania i monitorowania. Broker warto dodać, gdy wolumen zdarzeń zacznie to uzasadniać. Kod przygotuję tak, żeby taka zmiana nie wymagała przepisywania logiki.
- **Powiadomienia na 5 kanałach.** Na start jeden kanał. Każdy kolejny to osobna integracja do przetestowania z osobnymi awariami do obsłużenia. Po tygodniu działania będzie widać, których kanałów zespół sprzedaży faktycznie używa. Baza kodowa na rozwój na inne kanały będzie przygotowana w PoC,
- **Raporty AI.** Mają sens, gdy w bazie zbierze się historia płatności. Proponuję wrócić do tematu za kilka tygodni z konkretnymi pytaniami, na które raporty mają odpowiadać.

Wolę dowieźć dziś mniejszy system, który nie gubi płatności przy stabilnych założeniach i podstawie.

## Czego potrzebuję od Państwa

1. **Lista dostawców płatności oraz dokumentacja podpisu webhooków dla każdego z nich** (nazwa nagłówka, algorytm, co jest podpisywane) oraz sekrety do środowiska testowego. Do czasu jej otrzymania używam standardowego podpisu HMAC-SHA256 i dostosuję go, gdy dostanę specyfikację.
2. **Kanał powiadomień na start**: Slack, e-mail, Teams czy inny? Jakie informacje zespół sprzedaży chce widzieć w powiadomieniu?
3. **Zdarzenia testowe** (`livemode: false`): w pierwszej wersji też wygenerują powiadomienia. Czy oznaczać je wyraźnie jako testowe, czy mają trafiać na osobny kanał?
4. **Przechowywanie danych**: webhooki zawierają dane osobowe klientów (e-mail, imię, nazwisko). Jak długo możemy je przechowywać i czy macie Państwo w tej sprawie wymagania RODO?

Brak odpowiedzi na punkty 1–3 nie blokuje mi startu, ale bez nich nie podłączymy systemu do produkcji.

Jeśli zakres jest dla Państwa w porządku, zaczynam od razu. Chętnie omówię szczegóły na krótkiej rozmowie, wystarczy 15 minut.

Pozdrawiam,
Igor Szostek
