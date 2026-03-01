#!/bin/bash
# Injects Meta Pixel + Google Ads scaffold into each comparison page
# Competitor value is set per-file for per-segment retargeting audiences
# Boss/Matt: swap PIXEL_ID_HERE and GTAG_ID_HERE with real IDs at deploy time

declare -A COMPETITORS
COMPETITORS["lw-vs-linkbot.html"]="linkbot"
COMPETITORS["lw-vs-linkboss.html"]="linkboss"
COMPETITORS["lw-vs-linkilo.html"]="linkilo"
COMPETITORS["lw-vs-linksy.html"]="linksy"
COMPETITORS["lw-vs-ilj.html"]="ilj"
COMPETITORS["lw-vs-yoast.html"]="yoast"
COMPETITORS["compare-hub.html"]="hub"

DIR="/home/sprite/agents/glitch/builds/comparison-pages"

for FILE in "${!COMPETITORS[@]}"; do
  COMPETITOR="${COMPETITORS[$FILE]}"
  FILEPATH="$DIR/$FILE"

  # Check if pixel is already injected
  if grep -q "PIXEL_ID_HERE\|fbq('init'" "$FILEPATH"; then
    echo "SKIP (already has pixel): $FILE"
    continue
  fi

  PIXEL_SNIPPET="  <!-- ============================================================
  META PIXEL + GOOGLE ADS RETARGETING SCAFFOLD
  Boss/Matt: Replace PIXEL_ID_HERE with your Meta Pixel ID (15-digit number from
  Meta Business Manager → Events Manager).
  Replace GTAG_ID_HERE with your Google Ads tag ID (format: AW-XXXXXXXXXX).
  Both IDs are in the ad account — 2-min lookup.
  ============================================================ -->
  <script>
    /* --- META PIXEL BASE + PER-PAGE COMPETITOR EVENT --- */
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
    n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
    document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', 'PIXEL_ID_HERE');
    fbq('track', 'PageView');

    /* Fires once when user scrolls past 50% — seeds per-competitor audience segment */
    (function() {
      var fired = false;
      window.addEventListener('scroll', function() {
        var scrollPct = window.scrollY / (document.body.scrollHeight - window.innerHeight);
        if (!fired && scrollPct > 0.5) {
          fired = true;
          fbq('trackCustom', 'ComparisonPageEngaged', {
            competitor: '$COMPETITOR',
            scroll_depth: '50pct'
          });
        }
      });
    })();

    /* --- GOOGLE ADS REMARKETING TAG --- */
    (function() {
      var s = document.createElement('script');
      s.async = true;
      s.src = 'https://www.googletagmanager.com/gtag/js?id=GTAG_ID_HERE';
      document.head.appendChild(s);
    })();
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'GTAG_ID_HERE');
    gtag('event', 'comparison_page_engaged', { competitor: '$COMPETITOR' });
  </script>
  <noscript><img height=\"1\" width=\"1\" style=\"display:none\"
    src=\"https://www.facebook.com/tr?id=PIXEL_ID_HERE&ev=PageView&noscript=1\" /></noscript>"

  # Inject before </head>
  sed -i "s|</head>|${PIXEL_SNIPPET}\n</head>|" "$FILEPATH"
  echo "INJECTED ($COMPETITOR): $FILE"
done

echo "Done."
