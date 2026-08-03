const previousDayButton = document.querySelector("#previous-day");
const nextDayButton = document.querySelector("#next-day");
const loadDayButton = document.querySelector("#load-day");
const statusBox = document.querySelector("#status");
const statusPanel = document.querySelector("#status-panel");
const statusIcon = document.querySelector("#status-icon");
const summaryGrid = document.querySelector("#summary-grid");
const summaryDate = document.querySelector("#summary-date");
const prosumerCard = document.querySelector("#prosumer-card");
const prosumerYearCard = document.querySelector("#prosumer-year-card");
const prosumerNetCard = document.querySelector("#prosumer-net-card");
const prosumerNetYearCard = document.querySelector("#prosumer-net-year-card");
const errorModal = document.querySelector("#error-modal");
const errorModalMessage = document.querySelector("#error-modal-message");
const errorModalClose = document.querySelector("#error-modal-close");
const errorModalOk = document.querySelector("#error-modal-ok");
const chartPanel = document.querySelector("#chart-panel");
const chartEmpty = document.querySelector("#chart-empty");
const chartTooltip = document.querySelector("#chart-tooltip");
const energyChart = document.querySelector("#energy-chart");
const monthlyChartPanel = document.querySelector("#monthly-chart-panel");
const monthlyChartEmpty = document.querySelector("#monthly-chart-empty");
const monthlyChartTooltip = document.querySelector("#monthly-chart-tooltip");
const monthlyEnergyChart = document.querySelector("#monthly-energy-chart");
const monthlyChartTitle = document.querySelector("#monthly-chart-title");
const dateInput = document.querySelector("#date-input");
const boilerPanel = document.querySelector("#boiler-panel");
const boilerName = document.querySelector("#boiler-name");
const boilerStatus = document.querySelector("#boiler-status");
const boilerStatusText = document.querySelector("#boiler-status-text");
const boilerYearLabel = document.querySelector("#boiler-year-label");
const boilerYearTotal = document.querySelector("#boiler-year-total");
const boilerMonthTitle = document.querySelector("#boiler-month-title");
const boilerMonthInput = document.querySelector("#boiler-month-input");
const boilerPrevMonthButton = document.querySelector("#boiler-prev-month");
const boilerNextMonthButton = document.querySelector("#boiler-next-month");
const boilerChartEmpty = document.querySelector("#boiler-chart-empty");
const boilerChartTooltip = document.querySelector("#boiler-chart-tooltip");
const boilerEnergyChart = document.querySelector("#boiler-energy-chart");
const greePanel = document.querySelector("#gree-panel");
const greeName = document.querySelector("#gree-name");
const greeStatus = document.querySelector("#gree-status");
const greeStatusText = document.querySelector("#gree-status-text");
const greeStatsGrid = document.querySelector("#gree-stats-grid");
const greeTemp = document.querySelector("#gree-temp");
const greeModeIcon = document.querySelector("#gree-mode-icon");
const greeMode = document.querySelector("#gree-mode");
const greeFan = document.querySelector("#gree-fan");
const greeSwing = document.querySelector("#gree-swing");

const GREE_MODE_ICONS = {
  "Auto": "🔄",
  "Chłodzenie": "❄️",
  "Osuszanie": "💧",
  "Wentylator": "🌀",
  "Grzanie": "🔥",
};

let lastPayload = null;
let latestAvailableDate = "";
let storageFactor = 0.8;
let latestBoilerMonth = "";

function shiftIsoDate(isoDate, amount) {
  const date = new Date(`${isoDate}T12:00:00`);
  date.setDate(date.getDate() + amount);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function shiftIsoMonth(isoMonth, amount) {
  const [year, month] = isoMonth.split("-").map(Number);
  const date = new Date(year, month - 1 + amount, 1);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

function currentIsoMonth() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

function formatKwh(value) {
  return `${Number(value || 0).toFixed(2)} kWh`;
}

function formatDisplayDate(isoDate) {
  const [year, month, day] = String(isoDate || "").split("-").map(Number);
  if (!year || !month || !day) {
    return isoDate;
  }

  const date = new Date(year, month - 1, day, 12, 0, 0);
  const weekday = new Intl.DateTimeFormat("pl-PL", {
    weekday: "long",
  }).format(date);
  const formattedDate = new Intl.DateTimeFormat("pl-PL", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(date);

  const weekdayCapitalized = weekday.charAt(0).toUpperCase() + weekday.slice(1);
  return `${weekdayCapitalized}, ${formattedDate}`;
}

function formatMonthYear(isoDate) {
  const [year, month] = String(isoDate || "").split("-").map(Number);
  if (!year || !month) {
    return isoDate;
  }

  const date = new Date(year, month - 1, 1, 12, 0, 0);
  return new Intl.DateTimeFormat("pl-PL", {
    month: "long",
    year: "numeric",
  }).format(date);
}

function setStatus(message, state = "syncing") {
  statusBox.textContent = message;
  statusPanel.classList.remove("is-syncing", "is-success", "is-error");

  if (state === "syncing") {
    statusPanel.classList.add("is-syncing");
    statusIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>`;
  } else if (state === "success") {
    statusPanel.classList.add("is-success");
    statusIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
  } else if (state === "error") {
    statusPanel.classList.add("is-error");
    statusIcon.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`;
  }
}

function showErrorModal(message) {
  errorModalMessage.textContent = message || "Wystąpił nieoczekiwany błąd.";
  errorModal.hidden = false;
}

function hideErrorModal() {
  errorModal.hidden = true;
}

function renderTotals(totals) {
  document.querySelector("#imported-total").textContent = formatKwh(totals.imported);
  document.querySelector("#exported-total").textContent = formatKwh(totals.exported);
  document.querySelector("#net-imported-total").textContent = formatKwh(totals.netImported);
  document.querySelector("#net-exported-total").textContent = formatKwh(totals.netExported);

  const balanceValue = document.querySelector("#prosumer-balance-total");
  if (totals.prosumerBalance >= 0) {
    balanceValue.textContent = formatKwh(totals.availableFromStorage);
    prosumerCard.classList.add("prosumer-positive");
    prosumerCard.classList.remove("prosumer-negative");
  } else {
    balanceValue.textContent = `-${Number(totals.storageDeficit || 0).toFixed(2)} kWh`;
    prosumerCard.classList.add("prosumer-negative");
    prosumerCard.classList.remove("prosumer-positive");
  }

  const netBalanceValue = document.querySelector("#prosumer-net-balance-total");
  if (Number(totals.netImported || 0) === 0 || Number(totals.netExported || 0) === 0) {
    netBalanceValue.textContent = "Czekam na dane od Tauron - pojawiają się po południu";
    prosumerNetCard.classList.add("prosumer-pending");
    prosumerNetCard.classList.remove("prosumer-positive", "prosumer-negative");
  } else if (totals.prosumerBalanceNet >= 0) {
    netBalanceValue.textContent = formatKwh(totals.availableFromStorageNet);
    prosumerNetCard.classList.add("prosumer-positive");
    prosumerNetCard.classList.remove("prosumer-negative", "prosumer-pending");
  } else {
    netBalanceValue.textContent = `-${Number(totals.storageDeficitNet || 0).toFixed(2)} kWh`;
    prosumerNetCard.classList.add("prosumer-negative");
    prosumerNetCard.classList.remove("prosumer-positive", "prosumer-pending");
  }
}

function renderYearlyStorage(yearlyStorage) {
  const balanceValue = document.querySelector("#prosumer-year-total");
  const balanceLabel = document.querySelector("#prosumer-year-label");
  const year = yearlyStorage.year || "";
  balanceLabel.textContent = `Magazyn prosumencki roczny ${year}`;

  if (yearlyStorage.prosumerBalance >= 0) {
    balanceValue.textContent = formatKwh(yearlyStorage.availableFromStorage);
    prosumerYearCard.classList.add("prosumer-positive");
    prosumerYearCard.classList.remove("prosumer-negative");
  } else {
    balanceValue.textContent = `-${Number(yearlyStorage.storageDeficit || 0).toFixed(2)} kWh`;
    prosumerYearCard.classList.add("prosumer-negative");
    prosumerYearCard.classList.remove("prosumer-positive");
  }

  const netBalanceValue = document.querySelector("#prosumer-net-year-total");
  const netBalanceLabel = document.querySelector("#prosumer-net-year-label");
  netBalanceLabel.textContent = `Magazyn prosumencki roczny po zbilansowaniu ${year}`;

  if (yearlyStorage.prosumerBalanceNet >= 0) {
    netBalanceValue.textContent = formatKwh(yearlyStorage.availableFromStorageNet);
    prosumerNetYearCard.classList.add("prosumer-positive");
    prosumerNetYearCard.classList.remove("prosumer-negative");
  } else {
    netBalanceValue.textContent = `-${Number(yearlyStorage.storageDeficitNet || 0).toFixed(2)} kWh`;
    prosumerNetYearCard.classList.add("prosumer-negative");
    prosumerNetYearCard.classList.remove("prosumer-positive");
  }
}

function buildXAxisLabels(rows, chartWidth, chartHeight, padding, labelKey) {
  const points = [];
  const step = chartWidth / Math.max(rows.length, 1);
  for (let index = 0; index < rows.length; index += 1) {
    if (rows.length > 12 && index % 2 === 1) {
      continue;
    }
    const x = padding.left + step * index + step / 2;
    const labelY = padding.top + chartHeight + 22;
    const isSelected = rows[index].isSelected ? " chart-label-selected" : "";
    points.push(`<text x="${x.toFixed(2)}" y="${labelY}" class="chart-label${isSelected}" text-anchor="middle">${rows[index][labelKey]}</text>`);
  }
  return points.join("");
}

function renderChart(rows, options) {
  const {
    svg,
    emptyState,
    tooltip,
    labelKey,
    titleKey,
    tooltipImportedLabel,
    tooltipExportedLabel,
    compactBars = false,
    selectedValue = "",
  } = options;

  if (!rows.length) {
    svg.innerHTML = "";
    emptyState.hidden = false;
    return;
  }

  emptyState.hidden = true;
  const width = 920;
  const height = 320;
  const padding = { top: 18, right: 24, bottom: 44, left: 52 };
  const chartWidth = width - padding.left - padding.right;
  const chartHeight = height - padding.top - padding.bottom;
  const maxValue = Math.max(...rows.flatMap((row) => [row.imported, row.exported]), 0.1);
  const groupWidth = chartWidth / Math.max(rows.length, 1);
  const barWidth = compactBars ? Math.min(20, Math.max(6, groupWidth * 0.5)) : Math.min(24, Math.max(10, groupWidth * 0.58));

  const gridLines = Array.from({ length: 5 }, (_, index) => {
    const ratio = index / 4;
    const y = padding.top + chartHeight - chartHeight * ratio;
    const value = (maxValue * ratio).toFixed(2);
    return `
      <line x1="${padding.left}" y1="${y}" x2="${width - padding.right}" y2="${y}" class="chart-grid" />
      <text x="${padding.left - 10}" y="${y + 4}" class="chart-axis" text-anchor="end">${value}</text>
    `;
  }).join("");

  const bars = rows.map((row, index) => {
    const groupX = padding.left + groupWidth * index + groupWidth / 2;
    const importedHeight = (row.imported / maxValue) * chartHeight;
    const exportedHeight = (row.exported / maxValue) * chartHeight;
    const importedY = padding.top + chartHeight - importedHeight;
    const exportedY = padding.top + chartHeight - exportedHeight;
    const isSelected = selectedValue !== "" && row[titleKey] === selectedValue;
    const isPositive = Number(row.exported || 0) >= Number(row.imported || 0);
    row.isSelected = isSelected;
    const balanceClass = isPositive ? "chart-bar-balance-positive" : "chart-bar-balance-negative";
    const importClass = isSelected
      ? `chart-bar chart-bar-import ${balanceClass} chart-bar-selected chart-bar-selected-import`
      : `chart-bar chart-bar-import ${balanceClass}`;
    const exportClass = isSelected
      ? `chart-bar chart-bar-export ${balanceClass} chart-bar-selected chart-bar-selected-export`
      : `chart-bar chart-bar-export ${balanceClass}`;

    return `
      <rect x="${(groupX - barWidth / 2).toFixed(2)}" y="${importedY.toFixed(2)}" width="${barWidth.toFixed(2)}" height="${Math.max(importedHeight, 1).toFixed(2)}" rx="8" class="${importClass}" />
      <rect x="${(groupX - barWidth / 2).toFixed(2)}" y="${exportedY.toFixed(2)}" width="${barWidth.toFixed(2)}" height="${Math.max(exportedHeight, 1).toFixed(2)}" rx="8" class="${exportClass}" />
      <rect x="${(padding.left + groupWidth * index).toFixed(2)}" y="${padding.top}" width="${groupWidth.toFixed(2)}" height="${chartHeight.toFixed(2)}" class="chart-hitbox" data-title="${row[titleKey]}" data-imported="${row.imported}" data-exported="${row.exported}" />
    `;
  }).join("");

  svg.innerHTML = `
    <rect x="0" y="0" width="${width}" height="${height}" rx="24" class="chart-bg"></rect>
    ${gridLines}
    <line x1="${padding.left}" y1="${padding.top + chartHeight}" x2="${width - padding.right}" y2="${padding.top + chartHeight}" class="chart-base" />
    ${buildXAxisLabels(rows, chartWidth, chartHeight, padding, labelKey)}
    ${bars}
  `;

  svg.querySelectorAll(".chart-hitbox").forEach((node) => {
    node.addEventListener("mouseenter", () => {
      const imported = formatKwh(Number(node.dataset.imported || 0));
      const exported = formatKwh(Number(node.dataset.exported || 0));
      tooltip.innerHTML = `
        <strong>${node.dataset.title}</strong><br />
        ${tooltipImportedLabel}: ${imported}<br />
        ${tooltipExportedLabel}: ${exported}
      `;
      tooltip.hidden = false;
    });

    node.addEventListener("mousemove", (event) => {
      const bounds = (tooltip.offsetParent || svg).getBoundingClientRect();
      tooltip.style.left = `${event.clientX - bounds.left + 14}px`;
      tooltip.style.top = `${event.clientY - bounds.top + 14}px`;
    });

    node.addEventListener("mouseleave", () => {
      tooltip.hidden = true;
    });
  });
}

function renderHourlyChart(rows) {
  renderChart(rows, {
    svg: energyChart,
    emptyState: chartEmpty,
    tooltip: chartTooltip,
    labelKey: "hourStart",
    titleKey: "hour",
    tooltipImportedLabel: "Pobrane",
    tooltipExportedLabel: "Wpuszczone",
  });
}

function renderMonthlyChart(rows, isoDate) {
  monthlyChartTitle.textContent = `Dzienny pobór i oddawanie w miesiącu ${formatMonthYear(isoDate)}`;
  renderChart(rows, {
    svg: monthlyEnergyChart,
    emptyState: monthlyChartEmpty,
    tooltip: monthlyChartTooltip,
    labelKey: "dayLabel",
    titleKey: "date",
    tooltipImportedLabel: "Pobrane",
    tooltipExportedLabel: "Oddane",
    compactBars: true,
    selectedValue: isoDate,
  });
}

function renderBoilerChart(rows) {
  const svg = boilerEnergyChart;
  const emptyState = boilerChartEmpty;
  const tooltip = boilerChartTooltip;

  if (!rows.length) {
    svg.innerHTML = "";
    emptyState.hidden = false;
    return;
  }

  emptyState.hidden = true;
  const width = 920;
  const height = 320;
  const padding = { top: 18, right: 24, bottom: 44, left: 52 };
  const chartWidth = width - padding.left - padding.right;
  const chartHeight = height - padding.top - padding.bottom;
  const maxValue = Math.max(...rows.map((row) => row.energy), 0.1);
  const groupWidth = chartWidth / Math.max(rows.length, 1);
  const barWidth = Math.min(24, Math.max(10, groupWidth * 0.5));

  const gridLines = Array.from({ length: 5 }, (_, index) => {
    const ratio = index / 4;
    const y = padding.top + chartHeight - chartHeight * ratio;
    const value = (maxValue * ratio).toFixed(2);
    return `
      <line x1="${padding.left}" y1="${y}" x2="${width - padding.right}" y2="${y}" class="chart-grid" />
      <text x="${padding.left - 10}" y="${y + 4}" class="chart-axis" text-anchor="end">${value}</text>
    `;
  }).join("");

  const bars = rows.map((row, index) => {
    const groupX = padding.left + groupWidth * index + groupWidth / 2;
    const barHeight = (row.energy / maxValue) * chartHeight;
    const barY = padding.top + chartHeight - barHeight;

    return `
      <rect x="${(groupX - barWidth / 2).toFixed(2)}" y="${barY.toFixed(2)}" width="${barWidth.toFixed(2)}" height="${Math.max(barHeight, 1).toFixed(2)}" rx="8" class="chart-bar chart-bar-boiler" />
      <rect x="${(padding.left + groupWidth * index).toFixed(2)}" y="${padding.top}" width="${groupWidth.toFixed(2)}" height="${chartHeight.toFixed(2)}" class="chart-hitbox" data-title="${row.date}" data-energy="${row.energy}" />
    `;
  }).join("");

  svg.innerHTML = `
    <rect x="0" y="0" width="${width}" height="${height}" rx="24" class="chart-bg"></rect>
    ${gridLines}
    <line x1="${padding.left}" y1="${padding.top + chartHeight}" x2="${width - padding.right}" y2="${padding.top + chartHeight}" class="chart-base" />
    ${buildXAxisLabels(rows, chartWidth, chartHeight, padding, "dayLabel")}
    ${bars}
  `;

  svg.querySelectorAll(".chart-hitbox").forEach((node) => {
    node.addEventListener("mouseenter", () => {
      const energy = formatKwh(Number(node.dataset.energy || 0));
      tooltip.innerHTML = `<strong>${node.dataset.title}</strong><br />Zużycie: ${energy}`;
      tooltip.hidden = false;
    });

    node.addEventListener("mousemove", (event) => {
      const bounds = (tooltip.offsetParent || svg).getBoundingClientRect();
      tooltip.style.left = `${event.clientX - bounds.left + 14}px`;
      tooltip.style.top = `${event.clientY - bounds.top + 14}px`;
    });

    node.addEventListener("mouseleave", () => {
      tooltip.hidden = true;
    });
  });
}

async function loadBoilerData(month) {
  try {
    const response = await fetch("api/boiler.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ month }),
    });

    const result = await response.json();

    if (!response.ok) {
      throw new Error(result.error || "Nie udało się pobrać danych z Tapo.");
    }

    latestBoilerMonth = result.month;
    boilerMonthInput.value = result.month;
    boilerName.textContent = result.deviceName || "Bojler";
    boilerYearLabel.textContent = `${result.deviceName || "Bojler"} w tym roku pobrał (${result.year})`;
    boilerYearTotal.textContent = formatKwh(result.yearTotalKwh);
    boilerMonthTitle.textContent = `Dzienne zużycie w miesiącu ${formatMonthYear(`${result.month}-01`)}`;

    boilerStatus.classList.toggle("boiler-on", Boolean(result.deviceOn));
    boilerStatus.classList.toggle("boiler-off", !result.deviceOn);
    boilerStatusText.textContent = result.deviceOn ? "Włączony" : "Wyłączony";

    renderBoilerChart(result.monthlyDaily || []);
    boilerPanel.hidden = false;
  } catch (error) {
    boilerStatusText.textContent = error.message || "Błąd połączenia z Tapo";
    boilerStatus.classList.remove("boiler-on");
    boilerStatus.classList.add("boiler-off");
    boilerPanel.hidden = false;
    console.error(error);
  }
}

if (boilerPanel && boilerPrevMonthButton && boilerNextMonthButton && boilerMonthInput) {
  boilerPrevMonthButton.addEventListener("click", async () => {
    const base = boilerMonthInput.value || latestBoilerMonth || currentIsoMonth();
    await loadBoilerData(shiftIsoMonth(base, -1));
  });

  boilerNextMonthButton.addEventListener("click", async () => {
    const base = boilerMonthInput.value || latestBoilerMonth || currentIsoMonth();
    const next = shiftIsoMonth(base, 1);
    const maxMonth = currentIsoMonth();
    await loadBoilerData(next > maxMonth ? maxMonth : next);
  });

  boilerMonthInput.addEventListener("change", async () => {
    if (boilerMonthInput.value) {
      await loadBoilerData(boilerMonthInput.value);
    }
  });
}

async function loadGreeData() {
  try {
    const response = await fetch("api/gree.php");
    const result = await response.json();

    if (!response.ok) {
      throw new Error(result.error || "Nie udało się połączyć z klimatyzacją Gree.");
    }

    greeName.textContent = result.deviceName || "Klimatyzacja";
    greeStatus.classList.toggle("boiler-on", Boolean(result.on));
    greeStatus.classList.toggle("boiler-off", !result.on);

    if (result.on) {
      greeStatusText.textContent = "Włączona";
      greeTemp.textContent = `${result.setTemp}°${result.tempUnit}`;
      greeModeIcon.textContent = GREE_MODE_ICONS[result.mode] || "⚙️";
      greeMode.textContent = result.mode;
      greeFan.textContent = result.fanSpeed;
      greeSwing.textContent = result.swing;
      greeStatsGrid.hidden = false;
    } else {
      greeStatusText.textContent = "Wyłączona";
      greeStatsGrid.hidden = true;
    }

    greePanel.hidden = false;
  } catch (error) {
    greeStatusText.textContent = error.message || "Błąd połączenia z klimatyzacją Gree";
    greeStatus.classList.remove("boiler-on");
    greeStatus.classList.add("boiler-off");
    greeStatsGrid.hidden = true;
    greePanel.hidden = false;
    console.error(error);
  }
}

async function loadConfig() {
  try {
    const response = await fetch("api/today.php?config=1");
    const config = await response.json();
    latestAvailableDate = config.latestAvailableDate || "";
    storageFactor = Number(config.storageFactor || 0.8);
    dateInput.value = latestAvailableDate;
    dateInput.max = latestAvailableDate;

    if (config.hasPresetCredentials) {
      setStatus("Znaleziono dane w `config.local.php`. Pobieram dane z Taurona.", "syncing");
    } else {
      setStatus("Brakuje TAURON_USERNAME albo TAURON_PASSWORD w `config.local.php`.", "error");
    }

    if (config.hasPresetSiteId) {
      setStatus("Znaleziono dane w `config.local.php`. Pobieram dane z Taurona.", "syncing");
    }

    if (config.hasPresetCredentials) {
      await loadSelectedDay();
    }
  } catch {
    setStatus("Nie udało się odczytać konfiguracji startowej.", "error");
    showErrorModal("Nie udało się odczytać konfiguracji startowej.");
  }

  try {
    const boilerConfigResponse = await fetch("api/boiler.php?config=1");
    const boilerConfig = await boilerConfigResponse.json();
    if (boilerConfig.hasConfig) {
      await loadBoilerData("");
    }
  } catch {
    // Sekcja bojlera jest opcjonalna - brak Tapo w sieci nie blokuje reszty aplikacji.
  }

  try {
    const greeConfigResponse = await fetch("api/gree.php?config=1");
    const greeConfig = await greeConfigResponse.json();
    if (greeConfig.hasConfig) {
      await loadGreeData();
      setInterval(loadGreeData, 60000);
    }
  } catch {
    // Sekcja klimatyzacji jest opcjonalna - brak Gree w sieci nie blokuje reszty aplikacji.
  }
}

async function fetchToday(payload) {
  loadDayButton.disabled = true;
  previousDayButton.disabled = true;
  nextDayButton.disabled = true;
  dateInput.disabled = true;
  document.body.classList.add("is-loading");
  setStatus("Łączę się z eLicznikiem i pobieram dane...", "syncing");

  try {
    const response = await fetch("api/today.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(payload),
    });

    const result = await response.json();

    if (!response.ok) {
      throw new Error(result.error || "Nie udało się pobrać danych.");
    }

    lastPayload = payload;
    renderTotals(result.totals);
    renderYearlyStorage(result.yearlyStorage || {});
    renderHourlyChart(result.hourly || []);
    renderMonthlyChart(result.monthlyDaily || [], result.date);
    summaryDate.textContent = formatDisplayDate(result.date);
    summaryGrid.hidden = false;
    chartPanel.hidden = false;
    monthlyChartPanel.hidden = false;
    setStatus("Dane zostały pobrane poprawnie.", "success");
  } catch (error) {
    const message = error.message || "Wystąpił nieoczekiwany błąd.";
    setStatus(message, "error");
    showErrorModal(message);
  } finally {
    loadDayButton.disabled = false;
    previousDayButton.disabled = false;
    nextDayButton.disabled = false;
    dateInput.disabled = false;
    document.body.classList.remove("is-loading");
    chartTooltip.hidden = true;
    monthlyChartTooltip.hidden = true;
  }
}

function currentPayload() {
  return {
    date: dateInput.value || latestAvailableDate,
  };
}

async function loadSelectedDay() {
  await fetchToday(currentPayload());
}

loadDayButton.addEventListener("click", async () => {
  await loadSelectedDay();
});

previousDayButton.addEventListener("click", async () => {
  const baseDate = dateInput.value || latestAvailableDate;
  dateInput.value = shiftIsoDate(baseDate, -1);
  await loadSelectedDay();
});

nextDayButton.addEventListener("click", async () => {
  const baseDate = dateInput.value || latestAvailableDate;
  const nextDate = shiftIsoDate(baseDate, 1);
  dateInput.value = nextDate > latestAvailableDate ? latestAvailableDate : nextDate;
  await loadSelectedDay();
});

dateInput.addEventListener("change", () => {
  if (dateInput.value > latestAvailableDate) {
    dateInput.value = latestAvailableDate;
  }
});

errorModalClose.addEventListener("click", hideErrorModal);
errorModalOk.addEventListener("click", hideErrorModal);
errorModal.addEventListener("click", (event) => {
  if (event.target === errorModal) {
    hideErrorModal();
  }
});

loadConfig();
