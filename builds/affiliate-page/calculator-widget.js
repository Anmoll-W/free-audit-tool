/**
 * Link Whisper Affiliate Commission Calculator Widget
 * Standalone vanilla JS — no dependencies, no build step
 * Slots into /become-an-affiliate/ after the commission structure section
 *
 * Math:
 *   - CTR: 0.5% (readers → affiliate link clicks) — conservative floor per Rex benchmark
 *   - Conversion: 1% conservative default / 2% optimistic toggle
 *   - Commission: $97 × 30% = $29.10/sale
 *   - Monthly estimate: readers × 0.005 × CR × $29.10 / 12
 *
 * Usage: <script src="calculator-widget.js"></script>
 * Mounts into: <div id="lw-affiliate-calc"></div>
 * Cookie duration: swap [COOKIE_DURATION] with confirmed value before deploy
 */

(function () {
  const PRICE = 97;           // Annual license price ($)
  const COMMISSION_RATE = 0.30;
  const CTR = 0.005;          // 0.5% — readers → clicks
  const COMMISSION_PER_SALE = PRICE * COMMISSION_RATE; // $29.10

  function calcEarnings(readers, cr) {
    const monthlyClicks = readers * CTR;          // readers who click per month
    const monthlySales = monthlyClicks * cr;       // those who purchase
    const monthly = monthlySales * COMMISSION_PER_SALE;
    const year1 = monthly * 12;
    // Year 2+: assume 70% customer renewal rate (conservative)
    const year2plus = year1 * 0.70;
    return { monthly, year1, year2plus };
  }

  function fmt(n) {
    return '$' + Math.round(n).toLocaleString('en-US');
  }

  function mount() {
    const el = document.getElementById('lw-affiliate-calc');
    if (!el) return;

    el.innerHTML = `
      <div class="lw-calc-wrap">
        <div class="lw-calc-header">
          <h3 class="lw-calc-heading">What could you earn?</h3>
          <p class="lw-calc-subhead">Estimate your monthly affiliate revenue based on your audience size.</p>
        </div>

        <div class="lw-calc-inputs">
          <div class="lw-calc-field">
            <label for="lw-readers-slider" class="lw-calc-label">
              Monthly readers: <strong id="lw-readers-val">10,000</strong>
            </label>
            <input
              type="range"
              id="lw-readers-slider"
              min="1000"
              max="100000"
              step="1000"
              value="10000"
              class="lw-calc-slider"
              aria-label="Monthly readers"
            />
            <div class="lw-calc-slider-labels">
              <span>1,000</span>
              <span>100,000</span>
            </div>
          </div>

          <div class="lw-calc-toggle-wrap">
            <span class="lw-calc-toggle-label">Scenario:</span>
            <div class="lw-calc-toggle" role="group" aria-label="Conversion scenario">
              <button
                id="lw-toggle-conservative"
                class="lw-calc-toggle-btn lw-calc-toggle-active"
                data-cr="0.01"
                aria-pressed="true"
              >Conservative</button>
              <button
                id="lw-toggle-optimistic"
                class="lw-calc-toggle-btn"
                data-cr="0.02"
                aria-pressed="false"
              >Optimistic</button>
            </div>
            <span class="lw-calc-toggle-hint" id="lw-cr-hint">1% conversion rate</span>
          </div>
        </div>

        <div class="lw-calc-output" aria-live="polite">
          <div class="lw-calc-row lw-calc-row-primary">
            <span class="lw-calc-row-label">Estimated monthly earnings</span>
            <span class="lw-calc-row-value" id="lw-out-monthly">$24</span>
          </div>
          <div class="lw-calc-row">
            <span class="lw-calc-row-label">Year 1 total</span>
            <span class="lw-calc-row-value" id="lw-out-year1">$291</span>
          </div>
          <div class="lw-calc-row lw-calc-row-renewals">
            <span class="lw-calc-row-label">Year 2+ (customer renewals @ 30%)</span>
            <span class="lw-calc-row-value" id="lw-out-year2">$204/yr</span>
          </div>
        </div>

        <p class="lw-calc-disclaimer">
          Estimates based on average affiliate CTR (0.5%) and conversion rates.
          Actual results vary based on audience, placement, and content quality.
        </p>
      </div>
    `;

    // State
    let currentReaders = 10000;
    let currentCR = 0.01;

    function update() {
      const e = calcEarnings(currentReaders, currentCR);
      document.getElementById('lw-out-monthly').textContent = fmt(e.monthly);
      document.getElementById('lw-out-year1').textContent = fmt(e.year1);
      document.getElementById('lw-out-year2').textContent = fmt(e.year2plus) + '/yr';
    }

    // Slider
    const slider = document.getElementById('lw-readers-slider');
    const readersVal = document.getElementById('lw-readers-val');
    slider.addEventListener('input', function () {
      currentReaders = parseInt(this.value, 10);
      readersVal.textContent = currentReaders.toLocaleString('en-US');
      update();
    });

    // Toggle buttons
    const toggleBtns = el.querySelectorAll('.lw-calc-toggle-btn');
    const crHint = document.getElementById('lw-cr-hint');

    toggleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        toggleBtns.forEach(function (b) {
          b.classList.remove('lw-calc-toggle-active');
          b.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('lw-calc-toggle-active');
        btn.setAttribute('aria-pressed', 'true');
        currentCR = parseFloat(btn.dataset.cr);
        crHint.textContent = (currentCR * 100) + '% conversion rate';
        update();
      });
    });

    // Init
    update();
  }

  // Inject styles
  const style = document.createElement('style');
  style.textContent = `
    .lw-calc-wrap {
      background: #f8f9fa;
      border: 1px solid #e2e6ea;
      border-radius: 12px;
      padding: 32px 36px;
      max-width: 560px;
      margin: 32px auto;
      font-family: inherit;
    }
    .lw-calc-heading {
      font-size: 1.25rem;
      font-weight: 700;
      margin: 0 0 6px;
      color: #1a1a2e;
    }
    .lw-calc-subhead {
      font-size: 0.9rem;
      color: #555;
      margin: 0 0 24px;
    }
    .lw-calc-inputs {
      display: flex;
      flex-direction: column;
      gap: 20px;
      margin-bottom: 28px;
    }
    .lw-calc-field {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .lw-calc-label {
      font-size: 0.9rem;
      color: #333;
      font-weight: 500;
    }
    .lw-calc-slider {
      width: 100%;
      accent-color: #2563eb;
      cursor: pointer;
      height: 4px;
    }
    .lw-calc-slider-labels {
      display: flex;
      justify-content: space-between;
      font-size: 0.75rem;
      color: #888;
    }
    .lw-calc-toggle-wrap {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .lw-calc-toggle-label {
      font-size: 0.9rem;
      color: #333;
      font-weight: 500;
    }
    .lw-calc-toggle {
      display: flex;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      overflow: hidden;
    }
    .lw-calc-toggle-btn {
      padding: 6px 16px;
      font-size: 0.85rem;
      background: #fff;
      border: none;
      cursor: pointer;
      color: #555;
      transition: background 0.15s, color 0.15s;
    }
    .lw-calc-toggle-btn:hover {
      background: #f0f4ff;
      color: #2563eb;
    }
    .lw-calc-toggle-active {
      background: #2563eb !important;
      color: #fff !important;
    }
    .lw-calc-toggle-hint {
      font-size: 0.78rem;
      color: #888;
    }
    .lw-calc-output {
      background: #fff;
      border: 1px solid #e2e6ea;
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 14px;
    }
    .lw-calc-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 14px 20px;
      border-bottom: 1px solid #f0f0f0;
      font-size: 0.9rem;
    }
    .lw-calc-row:last-child {
      border-bottom: none;
    }
    .lw-calc-row-primary {
      background: #f0f4ff;
    }
    .lw-calc-row-label {
      color: #444;
    }
    .lw-calc-row-primary .lw-calc-row-label {
      font-weight: 600;
      color: #222;
    }
    .lw-calc-row-value {
      font-weight: 700;
      color: #2563eb;
      font-size: 1rem;
    }
    .lw-calc-row-primary .lw-calc-row-value {
      font-size: 1.3rem;
    }
    .lw-calc-row-renewals .lw-calc-row-label {
      font-size: 0.82rem;
      color: #666;
    }
    .lw-calc-row-renewals .lw-calc-row-value {
      color: #16a34a;
      font-size: 0.95rem;
    }
    .lw-calc-disclaimer {
      font-size: 0.75rem;
      color: #999;
      margin: 0;
      line-height: 1.5;
    }
    @media (max-width: 480px) {
      .lw-calc-wrap {
        padding: 20px 16px;
      }
      .lw-calc-toggle-wrap {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
      }
    }
  `;
  document.head.appendChild(style);

  // Mount when DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
