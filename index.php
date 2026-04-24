<?php $yesterday = date('Y-m-d', strtotime('yesterday')); ?>
<!doctype html>
<html lang="pl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Tauron Energetyka Dnia</title>
    <link rel="stylesheet" href="public/styles.css" />
  </head>
  <body>
    <div class="loading-bar"></div>
    <main class="shell">
      <div class="modal-backdrop" id="error-modal" hidden>
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="error-modal-title">
          <div class="modal-head">
            <p class="modal-eyebrow">Błąd</p>
            <button id="error-modal-close" class="modal-close" type="button" aria-label="Zamknij popup">×</button>
          </div>
          <h2 id="error-modal-title">Nie udało się pobrać danych</h2>
          <p id="error-modal-message" class="modal-message">Wystąpił nieoczekiwany błąd.</p>
          <div class="modal-actions">
            <button id="error-modal-ok" class="secondary-button" type="button">Zamknij</button>
          </div>
        </div>
      </div>

      <section class="panel status-panel" id="status-panel">
        <div class="status-header">
          <div class="status-icon-box">
            <div class="pulse-ring"></div>
            <div class="status-icon" id="status-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
          </div>
          <div class="status-details">
            <p class="status-eyebrow">Połączenie z Tauron</p>
            <h1 class="status-title">Logowanie automatyczne</h1>
            <p id="status" class="status-text">Inicjalizacja systemu...</p>
          </div>
        </div>
        <div class="status-footer">
          <p class="status-note">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>
            Źródło: <code>config.local.php</code>
          </p>
        </div>
      </section>

      <section class="panel date-panel">
        <div class="date-controls">
          <div class="date-row">
            <button id="previous-day" class="secondary-button nav-button" type="button" aria-label="Poprzedni dzień">
              <span class="nav-arrow">&larr;</span>
              <span>Poprzedni dzień</span>
            </button>
            <button id="next-day" class="secondary-button nav-button" type="button" aria-label="Następny dzień">
              <span>Następny dzień</span>
              <span class="nav-arrow">&rarr;</span>
            </button>
          </div>
          <div class="date-row">
            <div class="field date-field">
              <label for="date-input">Wybrany dzień</label>
              <input id="date-input" name="date" type="date" max="<?php echo $yesterday; ?>" />
            </div>
            <button id="load-day" class="secondary-button load-button" type="button">Pokaż wybrany dzień</button>
          </div>
        </div>
      </section>

      <section class="grid" id="summary-grid" hidden>
        <div class="summary-date" id="summary-date">Dane za --</div>
        <article class="card accent-ink prosumer-card" id="prosumer-card">
          <p class="label">Magazyn prosumencki dzienny</p>
          <p class="value" id="prosumer-balance-total">0.00 kWh</p>
        </article>
        <article class="card accent-ink prosumer-card" id="prosumer-year-card">
          <p class="label" id="prosumer-year-label">Magazyn prosumencki roczny</p>
          <p class="value" id="prosumer-year-total">0.00 kWh</p>
        </article>
        <article class="card accent-blue">
          <p class="label">Pobrana</p>
          <p class="value" id="imported-total">0.00 kWh</p>
        </article>
        <article class="card accent-green">
          <p class="label">Wpuszczona / oddana</p>
          <p class="value" id="exported-total">0.00 kWh</p>
        </article>
        <article class="card accent-sand">
          <p class="label">Pobrana po zbilansowaniu</p>
          <p class="value" id="net-imported-total">0.00 kWh</p>
        </article>
        <article class="card accent-rose">
          <p class="label">Oddana po zbilansowaniu</p>
          <p class="value" id="net-exported-total">0.00 kWh</p>
        </article>
      </section>

      <section class="panel chart-panel" id="chart-panel" hidden>
        <div class="chart-header">
          <div>
            <p class="eyebrow">Porównanie</p>
            <h2>Godzinowy pobór i oddawanie</h2>
          </div>
          <div class="chart-legend">
            <span class="legend-item"><span class="legend-swatch legend-import"></span>Pobór</span>
            <span class="legend-item"><span class="legend-swatch legend-export"></span>Oddawanie</span>
          </div>
        </div>
        <div id="chart-empty" class="chart-empty" hidden>Brak danych do narysowania wykresu.</div>
        <div id="chart-tooltip" class="chart-tooltip" hidden></div>
        <svg id="energy-chart" class="energy-chart" viewBox="0 0 920 320" role="img" aria-label="Wykres poboru i oddawania energii"></svg>
      </section>

      <section class="panel chart-panel" id="monthly-chart-panel" hidden>
        <div class="chart-header">
          <div>
            <p class="eyebrow">Miesiąc</p>
            <h2 id="monthly-chart-title">Dzienny pobór i oddawanie w miesiącu</h2>
          </div>
          <div class="chart-legend">
            <span class="legend-item"><span class="legend-swatch legend-import"></span>Pobór</span>
            <span class="legend-item"><span class="legend-swatch legend-export"></span>Oddawanie</span>
          </div>
        </div>
        <div id="monthly-chart-empty" class="chart-empty" hidden>Brak danych do narysowania wykresu miesięcznego.</div>
        <div id="monthly-chart-tooltip" class="chart-tooltip" hidden></div>
        <svg id="monthly-energy-chart" class="energy-chart" viewBox="0 0 920 320" role="img" aria-label="Wykres dziennego poboru i oddawania energii w miesiącu"></svg>
      </section>
    </main>

    <script src="public/app.js"></script>
  </body>
</html>
